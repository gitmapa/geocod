<?php
// lib/geocoder_engine.php
// -------------------------------------------------------------
// Motor principal de geocodificación IDECABA v2.0.3
// Versión multi-tabla (usa config de geocod.tablas_config)
// -------------------------------------------------------------

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_idecaba.php';

/**
 * Geocodifica todas las filas pendientes de una tabla configurada.
 *
 * @param array $tableConfig ['nombre_config','esquema','tabla',...]
 * @return array Resumen del proceso
 */
function geocode_pending_for_table(array $tableConfig)
{
    $esquema = $tableConfig['esquema'];
    $tabla   = $tableConfig['tabla'];

    // Validación ultra básica para evitar injection por si alguien rompió la tabla de config
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $esquema) || !preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
        throw new RuntimeException("Esquema o tabla inválidos en configuración.");
    }

    $full_table = $esquema . '.' . $tabla;

    $resumen = [
        'tabla'         => $full_table,
        'procesadas'    => 0,
        'ok'            => 0,
        'error_geocoder'=> 0,
        'error_wgs84'   => 0,
        'error_general' => 0,
    ];

    // 1) Traer filas pendientes (estado_proceso distinto de 'OK' o NULL)
    $sql_select = "
        SELECT *
        FROM {$full_table}
        WHERE estado_proceso IS DISTINCT FROM 'OK'
        ORDER BY idgeo
    ";

    $result = db_query($sql_select);

    if (!$result) {
        throw new RuntimeException("Error al seleccionar filas pendientes de {$full_table}");
    }

    while ($row = pg_fetch_assoc($result)) {

        $resumen['procesadas']++;

        $idgeo          = $row['idgeo'];           // asumo PK idgeo, ajustá si tu PK es otra
        $calle_original = $row['calle_original'];
        $altura_original= $row['altura_original'];

        // Dirección “humana” a enviar al geocoder
        // Si vos ya tenías una forma exacta de armarla, reemplazá SOLO esta línea
        $direccion = trim($calle_original . ' ' . $altura_original);

        $estado_proceso = null;
        $mensaje_error  = null;

        $update_data = [];

        // =========================================================
        // 2) Llamar al geocoder
        // =========================================================
        $geocoder_resp = api_geocode($direccion);

        if ($geocoder_resp['status'] === 'OK') {

            $g = $geocoder_resp['data']; // asumimos data = array asociativo con todas las claves

            // Campos del geocoder (usamos ?? null para evitar warnings)
            $update_data['direccion_normalizada']   = $g['direccion_normalizada']   ?? null;
            $update_data['tipo_direccion']          = $g['tipo_direccion']          ?? null;
            $update_data['tipo_catastro']           = $g['tipo_catastro']           ?? null;
            $update_data['cod_calle_1']             = $g['cod_calle_1']             ?? null;
            $update_data['cod_calle_2']             = $g['cod_calle_2']             ?? null;
            $update_data['nombre_calle_1']          = $g['nombre_calle_1']          ?? null;
            $update_data['nombre_calle_2']          = $g['nombre_calle_2']          ?? null;
            $update_data['altura_normalizada']      = $g['altura_normalizada']      ?? null;
            $update_data['coordenada_x_gkba']       = $g['coordenada_x_gkba']       ?? null;
            $update_data['coordenada_y_gkba']       = $g['coordenada_y_gkba']       ?? null;
            $update_data['metodo_geocodificacion']  = $g['metodo_geocodificacion']  ?? null;
            $update_data['smp']                     = $g['smp']                     ?? null;
            $update_data['barrio_inf']              = $g['barrio_inf']              ?? null;
            $update_data['sector_inf']              = $g['sector_inf']              ?? null;
            $update_data['manzana_inf']             = $g['manzana_inf']             ?? null;
            $update_data['parcela_inf']             = $g['parcela_inf']             ?? null;

        } else {
            // Error de geocoder: guardamos JSON crudo en mensaje_error
            $estado_proceso = 'ERROR_API_GEOCODER';
            $mensaje_error  = json_encode($geocoder_resp, JSON_UNESCAPED_UNICODE);

            $update_data['mensaje_error']  = $mensaje_error;
            $update_data['estado_proceso'] = $estado_proceso;
            $update_data['fecha_procesado']= date('Y-m-d H:i:s');

            actualizar_fila_geocod($full_table, $idgeo, $update_data);

            $resumen['error_geocoder']++;
            // No seguimos con datos útiles ni transformación para este registro
            continue;
        }

        // =========================================================
        // 3) Llamar a datos útiles
        // =========================================================
        $datos_resp = api_datos_utiles($direccion);

        if ($datos_resp['status'] === 'OK') {
            $d = $datos_resp['data'] ?? [];

            $update_data['comuna']                = $d['comuna']                ?? null;
            $update_data['barrio']                = $d['barrio']                ?? null;
            $update_data['distrito_escolar']      = $d['distrito_escolar']      ?? null;
            $update_data['comisaria']             = $d['comisaria']             ?? null;
            $update_data['comisaria_vecinal']     = $d['comisaria_vecinal']     ?? null;
            $update_data['area_hospitalaria']     = $d['area_hospitalaria']     ?? null;
            $update_data['region_sanitaria']      = $d['region_sanitaria']      ?? null;
            $update_data['seccion']               = $d['seccion']               ?? null;
            $update_data['codigo_postal']         = $d['codigo_postal']         ?? null;
            $update_data['codigo_postal_argentino']= $d['codigo_postal_argentino'] ?? null;

        } else {
            // Error en datos útiles: lo registramos, pero seguimos si tenemos coordenadas
            // Podés diferenciar códigos si querés (ERROR_API_DATOS_ÚTILES)
            $update_data['mensaje_error'] = json_encode($datos_resp, JSON_UNESCAPED_UNICODE);
        }

        // =========================================================
        // 4) Transformar a WGS84
        // =========================================================
        $x = $update_data['coordenada_x_gkba'];
        $y = $update_data['coordenada_y_gkba'];

        if ($x !== null && $y !== null) {
            $transf_resp = api_transformar($x, $y);

            if ($transf_resp['status'] === 'OK') {
                $t = $transf_resp['data'] ?? [];
                $update_data['latitud_wgs84']  = $t['latitud_wgs84']  ?? null;
                $update_data['longitud_wgs84'] = $t['longitud_wgs84'] ?? null;
            } else {
                $estado_proceso = 'ERROR_API_WGS84';
                $mensaje_error  = json_encode($transf_resp, JSON_UNESCAPED_UNICODE);

                $update_data['mensaje_error'] = $mensaje_error;
                $update_data['estado_proceso']= $estado_proceso;
                $update_data['fecha_procesado']= date('Y-m-d H:i:s');

                actualizar_fila_geocod($full_table, $idgeo, $update_data);

                $resumen['error_wgs84']++;
                continue;
            }
        }

        // Si llegamos acá sin marcar estado_proceso, consideramos OK
        if (!$estado_proceso) {
            $estado_proceso = 'OK';
        }

        $update_data['estado_proceso']  = $estado_proceso;
        $update_data['fecha_procesado'] = date('Y-m-d H:i:s');

        actualizar_fila_geocod($full_table, $idgeo, $update_data);

        if ($estado_proceso === 'OK') {
            $resumen['ok']++;
        }
    }

    return $resumen;
}

/**
 * Actualiza una fila de la tabla de geocodificación.
 *
 * @param string $full_table  'esquema.tabla'
 * @param mixed  $idgeo       PK del registro
 * @param array  $data        Campos => valores
 */
function actualizar_fila_geocod($full_table, $idgeo, array $data)
{
    if (empty($data)) {
        return;
    }

    $set_parts = [];
    $values    = [];
    $i         = 1;

    foreach ($data as $field => $value) {
        $set_parts[] = "{$field} = $" . $i;
        $values[]    = $value;
        $i++;
    }

    $values[] = $idgeo;
    $sql = "
        UPDATE {$full_table}
        SET " . implode(', ', $set_parts) . "
        WHERE idgeo = $" . $i . ";
    ";

    db_query($sql, $values);
}
