<?php
// =============================================================
// lib/geocoder_engine.php
// Motor principal de geocodificación IDECABA
// -------------------------------------------------------------
// Propósito : Procesa las filas pendientes de cualquier tabla
//             *_geo registrada en geocod.tablas_config.
//             Por cada fila llama a tres endpoints de la API
//             IDECABA en secuencia:
//               1) Geocoder      → normaliza dirección y obtiene GKBA
//               2) Datos útiles  → comuna, barrio, CP, etc.
//               3) Transformar   → convierte GKBA a WGS84
//             Actualiza la tabla con los resultados y el estado
//             de cada registro al finalizar cada paso.
// -------------------------------------------------------------
// Versión   : 1.1
// -------------------------------------------------------------
// Dependencias:
//   - lib/db.php          (db_query)
//   - lib/api_idecaba.php (api_geocode, api_datos_utiles, api_transformar)
// =============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_idecaba.php';


// =============================================================
// FUNCIÓN PRINCIPAL
// =============================================================

/**
 * geocode_pending_for_table()
 * -------------------------------------------------------------
 * Geocodifica todas las filas pendientes de una tabla *_geo
 * registrada en geocod.tablas_config.
 *
 * Una fila es "pendiente" cuando su estado_proceso es distinto
 * de 'OK' (incluye NULL, errores previos y estados intermedios).
 *
 * El proceso por fila es:
 *   1. Llamar al geocoder con calle + altura
 *   2. Llamar a datos útiles con la dirección normalizada
 *   3. Transformar coordenadas GKBA a WGS84
 *
 * Si el geocoder falla, se registra ERROR_API_GEOCODER y se
 * saltea la fila. Si datos útiles falla, se registra el error
 * pero se continúa con la transformación. Si la transformación
 * falla, se registra ERROR_API_WGS84.
 *
 * @param  array $tableConfig  Array con las claves:
 *                               'esquema' (string) — esquema PostgreSQL
 *                               'tabla'   (string) — nombre de la tabla
 *                             Puede contener otras claves que se ignoran.
 *
 * @return array Resumen con las claves:
 *                 'tabla'          (string) — esquema.tabla procesada
 *                 'total'          (int)    — filas procesadas
 *                 'ok'             (int)    — geocodificadas exitosamente
 *                 'sin_match'      (int)    — sin coordenadas GKBA
 *                 'error_geocoder' (int)    — errores en geocoder
 *                 'error_wgs84'   (int)    — errores en transformación WGS84
 *               O bien ['error' => string] si hubo un error fatal previo.
 */
function geocode_pending_for_table(array $tableConfig)
{
    $esquema = $tableConfig['esquema'] ?? null;
    $tabla   = $tableConfig['tabla']   ?? null;

    // ----------------------------------------------------------
    // PASO 0 — Chequeo previo de disponibilidad de la API.
    // Antes de tocar cualquier registro, verificamos que la API
    // de IDECABA esté respondiendo con una dirección de prueba
    // conocida. Si falla, retornamos error inmediatamente sin
    // modificar ningún registro de la tabla.
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
    // Validar que esquema y tabla estén presentes y sean seguros.
    // Solo se permiten letras, números y guiones bajos para evitar
    // inyección SQL, ya que estos valores se interpolan en el query
    // (los nombres de tabla no admiten parámetros en PostgreSQL).
    // ----------------------------------------------------------
    if (!$esquema || !$tabla) {
        return ['error' => "Configuración incompleta: faltan 'esquema' o 'tabla'."];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $esquema) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
        return ['error' => "Esquema o tabla con caracteres inválidos en la configuración."];
    }

    // Nombre completo para usar en queries
    $tabla_full = $esquema . '.' . $tabla;

    // ----------------------------------------------------------
    // Verificar que la tabla exista físicamente en la base.
    // Evita errores fatales si la tabla fue borrada manualmente.
    // ----------------------------------------------------------
    $res_existe = db_query("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = $1
          AND table_name   = $2
        LIMIT 1
    ", [$esquema, $tabla]);

    if (!$res_existe || !pg_fetch_assoc($res_existe)) {
        return ['error' => "La tabla {$tabla_full} no existe en la base de datos."];
    }

    // ----------------------------------------------------------
    // Contadores para el resumen final
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
    // Seleccionar filas pendientes.
    // IS DISTINCT FROM 'OK' captura NULL y cualquier otro estado.
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
    // BUCLE PRINCIPAL — una iteración por dirección pendiente
    // ==========================================================
    while ($fila = pg_fetch_assoc($res_filas)) {

        $resumen['total']++;

        $id      = $fila['idgeo'];
        $calle   = $fila['calle_original']  ?? '';
        $altura  = $fila['altura_original'] ?? '';

        // Armar la dirección que se enviará a la API
        $direccion = trim($calle . ' ' . $altura);

        // Array acumulador de campos a actualizar al final
        $campos = [];

        // ======================================================
        // PASO 1 — GEOCODER
        // Normaliza la dirección y devuelve coordenadas GKBA.
        // Si falla, no tiene sentido continuar con esta fila.
        // ======================================================
        $resp_geo = api_geocode($direccion);
        $json_geo = $resp_geo['json'];

        if ($resp_geo['http_code'] != 200 || empty($json_geo['data'])) {

            // Guardar el raw de la respuesta como mensaje de error
            $campos['estado_proceso']  = 'ERROR_API_GEOCODER';
            $campos['mensaje_error']   = json_encode(
                $resp_geo['raw'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $campos['fecha_procesado'] = date('Y-m-d H:i:s');

            actualizar_fila_geocod($tabla_full, $id, $campos);

            $resumen['error_geocoder']++;
            continue; // Saltar al siguiente registro
        }

        // Extraer el nodo data de la respuesta del geocoder
        $geo = $json_geo['data'];

        // Mapear campos del geocoder al acumulador.
        // Las claves del JSON de IDECABA son camelCase;
        // las columnas de la tabla son snake_case.
        $campos['direccion_normalizada']  = $geo['direccion']             ?? null;
        $campos['tipo_direccion']         = $geo['tipoDireccion']         ?? null;
        $campos['tipo_catastro']          = $geo['tipoCatastro']          ?? null;
        $campos['cod_calle_1']            = $geo['codCalle_1']            ?? null;
        $campos['cod_calle_2']            = $geo['codCalle_2']            ?? null;
        $campos['nombre_calle_1']         = $geo['nombreCalle_1']         ?? null;
        $campos['nombre_calle_2']         = $geo['nombreCalle_2']         ?? null;
        $campos['altura_normalizada']     = $geo['altura']                ?? null;
        $campos['coordenada_x_gkba']      = $geo['coordenada_x']          ?? null;
        $campos['coordenada_y_gkba']      = $geo['coordenada_y']          ?? null;
        $campos['metodo_geocodificacion'] = $geo['metodo_geocodificacion'] ?? null;
        $campos['smp']                    = $geo['smp']                   ?? null;
        $campos['barrio_inf']             = $geo['barrioInf']             ?? null;
        $campos['sector_inf']             = $geo['sectorInf']             ?? null;
        $campos['manzana_inf']            = $geo['manzanaInf']            ?? null;
        $campos['parcela_inf']            = $geo['parcelaInf']            ?? null;

        // Estado intermedio: el geocoder respondió bien
        $campos['estado_proceso'] = 'GEOCODER_OK';

        // ======================================================
        // PASO 2 — DATOS ÚTILES
        // Agrega datos administrativos: comuna, barrio, CP, etc.
        // Usamos la dirección NORMALIZADA devuelta por el geocoder
        // para mayor precisión.
        // Si falla, registramos el error pero continuamos:
        // los datos útiles no son bloqueantes para las coordenadas.
        // ======================================================
        $dir_normalizada = $geo['direccion'] ?? $direccion;
        $resp_du         = api_datos_utiles($dir_normalizada);
        $json_du         = $resp_du['json'];

        if ($resp_du['http_code'] == 200 && !empty($json_du['data'])) {

            $du = $json_du['data'];

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
            // Datos útiles falló: registramos para diagnóstico
            // pero NO frenamos el proceso
            $campos['mensaje_error'] = 'DATOS_UTILES: ' . json_encode(
                $resp_du['raw'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        // ======================================================
        // PASO 3 — TRANSFORMAR GKBA → WGS84
        // Convierte las coordenadas locales (GKBA) a latitud/
        // longitud estándar (WGS84).
        // Si no hay coordenadas GKBA, no hay nada que transformar.
        // ======================================================
        $cx = $campos['coordenada_x_gkba'];
        $cy = $campos['coordenada_y_gkba'];

        if ($cx !== null && $cy !== null) {

            $resp_wgs = api_transformar($cx, $cy);
            $json_wgs = $resp_wgs['json'];

            if ($resp_wgs['http_code'] == 200 && !empty($json_wgs['data'])) {

                // La API devuelve x=longitud, y=latitud
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

            // El geocoder no devolvió coordenadas GKBA para esta dirección
            $campos['estado_proceso']  = 'SIN_COORDENADAS_GKBA';
            $campos['mensaje_error']   = 'El geocoder no devolvió coordenadas X/Y para esta dirección.';
            $campos['fecha_procesado'] = date('Y-m-d H:i:s');

            $resumen['sin_match']++;
        }

        // Persistir todos los campos acumulados en un solo UPDATE
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
 * Construye y ejecuta dinámicamente un UPDATE sobre la tabla
 * de geocodificación para un registro específico.
 *
 * Usa parámetros preparados ($1, $2, ...) para evitar inyección
 * SQL en los valores. Los nombres de columna vienen de la lógica
 * interna y son considerados confiables.
 *
 * @param  string $tabla_full  Nombre completo 'esquema.tabla'
 * @param  mixed  $id          Valor de la PK (idgeo) a actualizar
 * @param  array  $campos      Array asociativo [columna => valor]
 *
 * @return void
 */
function actualizar_fila_geocod(string $tabla_full, $id, array $campos): void
{
    // Si no hay nada que actualizar, salir sin hacer nada
    if (empty($campos)) {
        return;
    }

    $partes  = [];   // Fragmentos "columna = $N" del SET
    $valores = [];   // Valores en el mismo orden que los parámetros
    $i       = 1;

    foreach ($campos as $columna => $valor) {
        $partes[]  = "{$columna} = \${$i}";
        $valores[] = $valor;
        $i++;
    }

    // El último parámetro es el id para el WHERE
    $valores[] = $id;

    $sql = "
        UPDATE {$tabla_full}
        SET " . implode(', ', $partes) . "
        WHERE idgeo = \${$i}
    ";

    db_query($sql, $valores);
}
