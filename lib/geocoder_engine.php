<?php
// =============================================================
// lib/geocoder_engine.php
// Motor principal de geocodificación IDECABA
// -------------------------------------------------------------
// Propósito : Procesa todas las filas pendientes de una tabla
//             *_geo registrada en geocod.tablas_config.
//             Por cada fila ejecuta tres llamadas a la API de
//             IDECABA en secuencia obligatoria:
//               1) Geocoder     — normaliza dirección, obtiene GKBA
//               2) Datos útiles — comuna, barrio, CP, comisaría, etc.
//               3) Transformar  — convierte GKBA a WGS84
//
//             Antes de iniciar el bucle verifica que la API esté
//             respondiendo con una dirección de prueba conocida
//             (Brandsen 805). Si la API no responde, no se
//             modifica ningún registro de la tabla.
//
//             El resultado de cada fila se persiste en un único
//             UPDATE al final de procesar esa fila, minimizando
//             las escrituras a la base de datos.
// -------------------------------------------------------------
// Dependencias:
//   - lib/db.php          (db_query, tabla_existe)
//   - lib/api_idecaba.php (api_geocode, api_datos_utiles, api_transformar)
// -------------------------------------------------------------
// Estados posibles en la columna estado_proceso:
//   GEOCODER_OK          — geocoder OK, transformación pendiente
//   OK                   — proceso completo, coordenadas WGS84 obtenidas
//   SIN_COORDENADAS_GKBA — geocoder no devolvió X/Y para esta dirección
//   ERROR_API_GEOCODER   — fallo en la llamada al geocoder
//   ERROR_API_WGS84      — fallo en la transformación a WGS84
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_idecaba.php';


// =============================================================
// FUNCIÓN PRINCIPAL
// =============================================================

/**
 * geocode_pending_for_table()
 * -------------------------------------------------------------
 * Geocodifica todas las filas pendientes de una tabla *_geo.
 *
 * Una fila es "pendiente" cuando estado_proceso IS DISTINCT
 * FROM 'OK'. Esto incluye: NULL (nunca procesada), cualquier
 * estado de error previo, y GEOCODER_OK (geocoder OK pero
 * transformación WGS84 pendiente).
 * Esto permite reintentar automáticamente filas que fallaron.
 *
 * Flujo por fila:
 *   1. Construir dirección: calle_original + altura_original
 *   2. Geocoder  → si falla: registrar ERROR_API_GEOCODER y saltar
 *   3. Datos útiles → si falla: registrar en mensaje_error
 *                               pero CONTINUAR (no es bloqueante)
 *   4. Transformar GKBA→WGS84 → si falla: registrar ERROR_API_WGS84
 *   5. Un único UPDATE con todos los campos acumulados
 *
 * @param  array $tableConfig  Array de configuración con las claves:
 *                               'esquema' (string) — esquema PostgreSQL
 *                               'tabla'   (string) — nombre físico de la tabla
 *                             Claves adicionales son ignoradas.
 *
 * @return array  En caso de éxito, resumen con las claves:
 *                  'tabla'          (string) — esquema.tabla procesada
 *                  'total'          (int)    — total de filas procesadas
 *                  'ok'             (int)    — filas con coordenadas WGS84 OK
 *                  'sin_match'      (int)    — filas sin coordenadas GKBA
 *                  'error_geocoder' (int)    — filas con error en geocoder
 *                  'error_wgs84'    (int)    — filas con error en transformación
 *                En caso de error fatal previo al bucle:
 *                  ['error' => string]  — descripción del problema
 */
function geocode_pending_for_table(array $tableConfig)
{
    $esquema = $tableConfig['esquema'] ?? null;
    $tabla   = $tableConfig['tabla']   ?? null;

    // ----------------------------------------------------------
    // PASO 0 — Chequeo previo de disponibilidad de la API.
    // Se llama al geocoder con una dirección conocida y estable
    // (Brandsen 805, CABA) antes de tocar cualquier registro.
    //
    // Si la API no responde correctamente, retornamos error
    // inmediatamente sin modificar ninguna fila. Esto protege
    // contra dejar registros en estados inconsistentes por un
    // corte temporal de la API.
    // ----------------------------------------------------------
    $prueba = api_geocode('Brandsen 805');

    if ($prueba['http_code'] != 200 || empty($prueba['json']['data'])) {
        return [
            'error' => "La API de IDECABA no está respondiendo correctamente. " .
                       "Verificá la conexión o las credenciales antes de reintentar. " .
                       "(HTTP: " . ($prueba['http_code'] ?: 'sin respuesta') . ")"
        ];
    }

    // ----------------------------------------------------------
    // Validar presencia y seguridad de esquema y tabla.
    // Los nombres de tabla no pueden pasarse como parámetros
    // preparados en PostgreSQL — se interpolan en el SQL.
    // La validación regex garantiza solo caracteres seguros,
    // previniendo SQL injection por esta vía.
    // ----------------------------------------------------------
    if (!$esquema || !$tabla) {
        return ['error' => "Configuración incompleta: faltan 'esquema' o 'tabla'."];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $esquema) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
        return ['error' => "Esquema o tabla con caracteres inválidos en la configuración."];
    }

    // Nombre completamente calificado para usar en todas las queries
    $tabla_full = $esquema . '.' . $tabla;

    // ----------------------------------------------------------
    // Verificar existencia física de la tabla en la base.
    // Protege contra el caso en que la tabla haya sido eliminada
    // manualmente desde pgAdmin sin actualizar tablas_geo_config.
    // ----------------------------------------------------------
    $res_existe = db_query("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = $1
          AND table_name   = $2
        LIMIT 1
    ", [$esquema, $tabla]);

    // Separado en dos condiciones para que Intelephense infiera
    // que $res_existe es PgSql\Result antes del pg_fetch_assoc
    if (!$res_existe) {
        return ['error' => "La tabla {$tabla_full} no existe en la base de datos."];
    }
    if (!pg_fetch_assoc($res_existe)) {
        return ['error' => "La tabla {$tabla_full} no existe en la base de datos."];
    }

    // ----------------------------------------------------------
    // Inicializar contadores del resumen final.
    // Se incrementan durante el bucle y se retornan al terminar.
    // ----------------------------------------------------------
    $resumen = [
        'tabla'          => $tabla_full,
        'total'          => 0,
        'ok'             => 0,
        'sin_match'      => 0,
        'error_geocoder' => 0,
        'error_wgs84'    => 0,
    ];

    // ----------------------------------------------------------
    // Seleccionar todas las filas pendientes ordenadas por idgeo.
    // IS DISTINCT FROM 'OK' captura NULL y cualquier valor
    // distinto de 'OK', incluyendo todos los estados de error.
    // ----------------------------------------------------------
    $res_filas = db_query("
        SELECT *
        FROM {$tabla_full}
        WHERE estado_proceso IS DISTINCT FROM 'OK'
        ORDER BY idgeo
    ");

    if (!$res_filas) {
        return ['error' => "Error al consultar filas pendientes de {$tabla_full}."];
    }

    // ==========================================================
    // BUCLE PRINCIPAL
    // Una iteración completa por cada dirección pendiente.
    // Cada fila pasa por hasta 3 llamadas a la API y se persiste
    // con un único UPDATE al final.
    // assert() ayuda a Intelephense a inferir que $res_filas es
    // PgSql\Result en este punto (el false ya fue descartado arriba).
    // ==========================================================
    assert($res_filas instanceof \PgSql\Result);
    while ($fila = pg_fetch_assoc($res_filas)) {

        $resumen['total']++;

        $id     = $fila['idgeo'];
        $calle  = $fila['calle_original']  ?? '';
        $altura = $fila['altura_original'] ?? '';

        // Construir la dirección concatenando calle y altura.
        // trim() elimina el espacio sobrante si la altura es vacía.
        $direccion = trim($calle . ' ' . $altura);

        // Acumulador de campos para el UPDATE final.
        // Se construye a lo largo de los 3 pasos y se persiste
        // de una sola vez al terminar de procesar la fila.
        $campos = [];

        // ======================================================
        // PASO 1 — GEOCODER
        // Normaliza la dirección y obtiene coordenadas GKBA
        // (Gauss-Krüger Buenos Aires), código de calle, SMP, etc.
        //
        // Si este paso falla no tiene sentido continuar con la
        // fila: sin coordenadas GKBA no se puede transformar a
        // WGS84. Se registra el error y se salta la fila.
        // ======================================================
        $resp_geo = api_geocode($direccion);
        $json_geo = $resp_geo['json'];

        if ($resp_geo['http_code'] != 200 || empty($json_geo['data'])) {

            // Guardar la respuesta cruda como mensaje de error
            // para facilitar el diagnóstico posterior
            $campos['estado_proceso']  = 'ERROR_API_GEOCODER';
            $campos['mensaje_error']   = json_encode(
                $resp_geo['raw'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $campos['fecha_procesado'] = date('Y-m-d H:i:s');

            actualizar_fila_geocod($tabla_full, $id, $campos);
            $resumen['error_geocoder']++;
            continue;
        }

        // Extraer el nodo data de la respuesta
        $geo = $json_geo['data'];

        // Mapear campos del JSON de IDECABA (camelCase) a las
        // columnas de la tabla (snake_case).
        // Se usa ?? null para no romper si la API omite alguna clave.
        $campos['direccion_normalizada']  = $geo['direccion']              ?? null;
        $campos['tipo_direccion']         = $geo['tipoDireccion']          ?? null;
        $campos['tipo_catastro']          = $geo['tipoCatastro']           ?? null;
        $campos['cod_calle_1']            = $geo['codCalle_1']             ?? null;
        $campos['cod_calle_2']            = $geo['codCalle_2']             ?? null;
        $campos['nombre_calle_1']         = $geo['nombreCalle_1']          ?? null;
        $campos['nombre_calle_2']         = $geo['nombreCalle_2']          ?? null;
        $campos['altura_normalizada']     = $geo['altura']                 ?? null;
        $campos['coordenada_x_gkba']      = $geo['coordenada_x']           ?? null;
        $campos['coordenada_y_gkba']      = $geo['coordenada_y']           ?? null;
        $campos['metodo_geocodificacion'] = $geo['metodo_geocodificacion']  ?? null;
        $campos['smp']                    = $geo['smp']                    ?? null;
        $campos['barrio_inf']             = $geo['barrioInf']              ?? null;
        $campos['sector_inf']             = $geo['sectorInf']              ?? null;
        $campos['manzana_inf']            = $geo['manzanaInf']             ?? null;
        $campos['parcela_inf']            = $geo['parcelaInf']             ?? null;

        // Estado intermedio: geocoder OK, transformación WGS84 pendiente
        $campos['estado_proceso'] = 'GEOCODER_OK';

        // ======================================================
        // PASO 2 — DATOS ÚTILES
        // Obtiene información administrativa de la dirección:
        // comuna, barrio, código postal, distrito escolar,
        // comisaría, área hospitalaria, región sanitaria, etc.
        //
        // Se usa la dirección NORMALIZADA por el geocoder (no la
        // original) para maximizar la precisión del resultado.
        //
        // Este paso NO es bloqueante: si falla, se registra el
        // error en mensaje_error pero se continúa hacia WGS84.
        // Las coordenadas son más críticas que los datos útiles.
        // ======================================================
        $dir_normalizada = $geo['direccion'] ?? $direccion;
        $resp_du         = api_datos_utiles($dir_normalizada);
        $json_du         = $resp_du['json'];

        if ($resp_du['http_code'] == 200 && !empty($json_du['data'])) {

            $du = $json_du['data'];

            // Todas las claves de datos útiles son snake_case en la API
            $campos['comuna']                  = $du['comuna']                  ?? null;
            $campos['barrio']                  = $du['barrio']                  ?? null;
            $campos['distrito_escolar']        = $du['distrito_escolar']        ?? null;
            $campos['comisaria']               = $du['comisaria']               ?? null;
            $campos['comisaria_vecinal']       = $du['comisaria_vecinal']       ?? null;
            $campos['area_hospitalaria']       = $du['area_hospitalaria']       ?? null;
            $campos['region_sanitaria']        = $du['region_sanitaria']        ?? null;
            $campos['seccion']                 = $du['seccion']                 ?? null;
            $campos['codigo_postal']           = $du['codigo_postal']           ?? null;
            $campos['codigo_postal_argentino'] = $du['codigo_postal_argentino'] ?? null;

        } else {
            // Datos útiles falló: registrar para diagnóstico
            // pero NO interrumpir — continuar hacia transformación
            $campos['mensaje_error'] = 'DATOS_UTILES: ' . json_encode(
                $resp_du['raw'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        // ======================================================
        // PASO 3 — TRANSFORMAR GKBA → WGS84
        // Convierte coordenadas del sistema local GKBA al sistema
        // geodésico estándar WGS84 (latitud/longitud).
        //
        // Solo se ejecuta si el geocoder devolvió coordenadas X/Y.
        // Si son null, la dirección no pudo geolocalizarse
        // (dirección inexistente, fuera de CABA, etc.).
        //
        // ATENCIÓN: la API devuelve x=longitud e y=latitud
        // (orden inverso al convencional lat/lon).
        // ======================================================
        $cx = $campos['coordenada_x_gkba'];
        $cy = $campos['coordenada_y_gkba'];

        if ($cx !== null && $cy !== null) {

            $resp_wgs = api_transformar($cx, $cy);
            $json_wgs = $resp_wgs['json'];

            if ($resp_wgs['http_code'] == 200 && !empty($json_wgs['data'])) {

                // La API retorna x=longitud, y=latitud (orden invertido)
                $campos['latitud_wgs84']   = $json_wgs['data']['y'] ?? null;
                $campos['longitud_wgs84']  = $json_wgs['data']['x'] ?? null;
                $campos['estado_proceso']  = 'OK';
                $campos['fecha_procesado'] = date('Y-m-d H:i:s');

                $resumen['ok']++;

            } else {

                $campos['estado_proceso']  = 'ERROR_API_WGS84';
                $campos['mensaje_error']   = json_encode(
                    $resp_wgs['raw'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $campos['fecha_procesado'] = date('Y-m-d H:i:s');

                $resumen['error_wgs84']++;
            }

        } else {

            // Sin coordenadas GKBA no hay transformación posible.
            // Puede ocurrir con direcciones que existen pero no
            // tienen geometría cargada en la base de IDECABA.
            $campos['estado_proceso']  = 'SIN_COORDENADAS_GKBA';
            $campos['mensaje_error']   = 'El geocoder no devolvió coordenadas X/Y para esta dirección.';
            $campos['fecha_procesado'] = date('Y-m-d H:i:s');

            $resumen['sin_match']++;
        }

        // Persistir todos los campos en un único UPDATE.
        // Un solo UPDATE por fila minimiza la carga sobre la DB
        // en procesos de miles de registros.
        actualizar_fila_geocod($tabla_full, $id, $campos);
    }

    return $resumen;
}


// =============================================================
// FUNCIÓN AUXILIAR
// =============================================================

/**
 * actualizar_fila_geocod()
 * -------------------------------------------------------------
 * Construye y ejecuta dinámicamente un UPDATE parametrizado
 * sobre una fila específica de la tabla de geocodificación.
 *
 * El UPDATE se construye dinámicamente porque la cantidad y
 * nombres de columnas varían según qué pasos del proceso se
 * completaron exitosamente para esa fila.
 *
 * Seguridad:
 *   - Los VALORES se pasan como parámetros preparados ($1, $2...)
 *     para prevenir SQL injection.
 *   - Los NOMBRES de columna vienen exclusivamente del código
 *     interno (nunca del usuario), por lo que son confiables.
 *
 * Ejemplo de SQL generado para 3 campos:
 *   UPDATE geocod.pedido_01_geo
 *   SET estado_proceso = $1, coordenada_x_gkba = $2, latitud_wgs84 = $3
 *   WHERE idgeo = $4
 *
 * @param  string $tabla_full  Nombre completamente calificado: 'esquema.tabla'
 * @param  mixed  $id          Valor del idgeo de la fila a actualizar
 * @param  array  $campos      Array asociativo [nombre_columna => valor]
 *
 * @return void
 */
function actualizar_fila_geocod(string $tabla_full, $id, array $campos): void
{
    // Si no hay campos para actualizar, salir sin ejecutar nada
    if (empty($campos)) {
        return;
    }

    $partes  = [];   // Fragmentos "columna = $N" para el SET
    $valores = [];   // Valores en el mismo orden que los placeholders
    $i       = 1;

    // Construir cada fragmento del SET y acumular el valor
    foreach ($campos as $columna => $valor) {
        $partes[]  = "{$columna} = \${$i}";
        $valores[] = $valor;
        $i++;
    }

    // El ID de la fila es siempre el último parámetro (para el WHERE)
    $valores[] = $id;

    $sql = "
        UPDATE {$tabla_full}
        SET "    . implode(', ', $partes) . "
        WHERE idgeo = \${$i}
    ";

    db_query($sql, $valores);
}
