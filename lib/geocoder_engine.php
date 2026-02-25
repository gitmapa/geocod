<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_idecaba.php';

// =============================================================
// MOTOR DE GEOLOCALIZACIÓN UNIVERSAL
// -------------------------------------------------------------
// Procesa cualquier tabla *_geo enviada al esquema geocod
// con columnas:
//   idgeo, calle_original, altura_original, estado_proceso, mensaje_error,
//   y todos los campos del geocoder + datos útiles + WGS84.
//
// Este motor reemplaza el viejo procesar_tabla_direcciones(), pero
// mantiene compatibilidad si algún día seguís usando direcciones_base.
// =============================================================

/**
 * Procesa cualquier tabla configurada en geocod.tablas_config.
 * La tabla debe estar en el esquema indicado y tener estructura *_geo.
 *
 * @param array $tableConfig ['tabla','esquema','nombre_config','descripcion']
 * @return array resultado del proceso
 */
function geocode_pending_for_table(array $tableConfig)
{
    $tabla   = $tableConfig['tabla']   ?? null;
    $esquema = $tableConfig['esquema'] ?? null;

    if (!$tabla || !$esquema) {
        return ['error' => "Configuración incompleta para geocodificación."];
    }

    $tabla_full = $esquema . "." . $tabla;

    // ------------------------------------------------------------
    // 1. Verificar existencia real de la tabla
    // ------------------------------------------------------------
    $sql_exists = "
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = $1
          AND table_name   = $2
        LIMIT 1
    ";
    $res_exists = db_query($sql_exists, [$esquema, $tabla]);

    if (!pg_fetch_assoc($res_exists)) {
        return ['error' => "La tabla $tabla_full no existe en la base."];
    }

    // ------------------------------------------------------------
    // 2. Seleccionar filas pendientes o con error
    // ------------------------------------------------------------
    $sql_sel = "
        SELECT *
        FROM $tabla_full
        WHERE estado_proceso IS NULL
           OR estado_proceso NOT IN ('OK')
        ORDER BY idgeo
    ";

    $res = db_query($sql_sel);

    $total     = 0;
    $ok        = 0;
    $sin_match = 0;
    $error_api = 0;

    // ============================================================
    // 3. BUCLE PRINCIPAL DE PROCESAMIENTO
    // ============================================================
    while ($row = pg_fetch_assoc($res)) {

        $total++;
        $id = $row['idgeo'];

        // Dirección original
        $direccion = trim(($row['calle_original'] ?? '') . ' ' . ($row['altura_original'] ?? ''));


        // -----------------------------------------------------------
        // 1) GEOCODER
        // -----------------------------------------------------------
        $geo = api_geocode($direccion);
        $jg  = $geo['json'];

        if ($geo['http_code'] != 200 || !isset($jg['data'])) {

            $msg_clean = json_encode($geo['raw'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            db_query("
                UPDATE $tabla_full
                SET estado_proceso  = 'ERROR_API_GEOCODER',
                    mensaje_error   = $1,
                    fecha_procesado = NOW()
                WHERE idgeo = $2
            ", [$msg_clean, $id]);

            $error_api++;
            continue;
        }

        $d = $jg['data'];

        // Guardar resultados del GEOCODER
        db_query("
            UPDATE $tabla_full SET
                direccion_normalizada     = $1,
                tipo_direccion            = $2,
                tipo_catastro             = $3,
                cod_calle_1               = $4,
                cod_calle_2               = $5,
                nombre_calle_1            = $6,
                nombre_calle_2            = $7,
                altura_normalizada        = $8,
                coordenada_x_gkba         = $9,
                coordenada_y_gkba         = $10,
                metodo_geocodificacion    = $11,
                smp                       = $12,
                barrio_inf                = $13,
                sector_inf                = $14,
                manzana_inf               = $15,
                parcela_inf               = $16,
                estado_proceso            = 'GEOCODER_OK',
                fecha_procesado           = NOW()
            WHERE idgeo = $17
        ", [
            $d['direccion'] ?? null,
            $d['tipoDireccion'] ?? null,
            $d['tipoCatastro'] ?? null,
            $d['codCalle_1'] ?? null,
            $d['codCalle_2'] ?? null,
            $d['nombreCalle_1'] ?? null,
            $d['nombreCalle_2'] ?? null,
            $d['altura'] ?? null,
            $d['coordenada_x'] ?? null,
            $d['coordenada_y'] ?? null,
            $d['metodo_geocodificacion'] ?? null,
            $d['smp'] ?? null,
            $d['barrioInf'] ?? null,
            $d['sectorInf'] ?? null,
            $d['manzanaInf'] ?? null,
            $d['parcelaInf'] ?? null,
            $id
        ]);


        // -----------------------------------------------------------
        // 2) DATOS ÚTILES
        // -----------------------------------------------------------
        $du  = api_datos_utiles($d['direccion']);
        $jdu = $du['json'];

        if ($du['http_code'] == 200 && isset($jdu['data'])) {

            $u = $jdu['data'];

            db_query("
                UPDATE $tabla_full SET
                    comuna                 = $1,
                    barrio                 = $2,
                    distrito_escolar       = $3,
                    comisaria              = $4,
                    comisaria_vecinal      = $5,
                    area_hospitalaria      = $6,
                    region_sanitaria       = $7,
                    seccion                = $8,
                    codigo_postal          = $9,
                    codigo_postal_argentino= $10
                WHERE idgeo = $11
            ", [
                $u['comuna'] ?? null,
                $u['barrio'] ?? null,
                $u['distrito_escolar'] ?? null,
                $u['comisaria'] ?? null,
                $u['comisaria_vecinal'] ?? null,
                $u['area_hospitalaria'] ?? null,
                $u['region_sanitaria'] ?? null,
                $u['seccion'] ?? null,
                $u['codigo_postal'] ?? null,
                $u['codigo_postal_argentino'] ?? null,
                $id
            ]);
        }


        // -----------------------------------------------------------
        // 3) TRANSFORMAR WGS84
        // -----------------------------------------------------------
        $cx = $d['coordenada_x'] ?? null;
        $cy = $d['coordenada_y'] ?? null;

        if ($cx !== null && $cy !== null) {

            $tf = api_transformar($cx, $cy);
            $jt = $tf['json'];

            if ($tf['http_code'] == 200 && isset($jt['data'])) {

                db_query("
                    UPDATE $tabla_full SET
                        latitud_wgs84   = $1,
                        longitud_wgs84  = $2,
                        estado_proceso  = 'OK',
                        fecha_procesado = NOW()
                    WHERE idgeo = $3
                ", [
                    $jt['data']['y'] ?? null,
                    $jt['data']['x'] ?? null,
                    $id
                ]);

                $ok++;

            } else {

                $msg_clean = json_encode($tf['raw'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

                db_query("
                    UPDATE $tabla_full
                    SET estado_proceso  = 'ERROR_API_WGS84',
                        mensaje_error   = $1,
                        fecha_procesado = NOW()
                    WHERE idgeo = $2
                ", [$msg_clean, $id]);

                $error_api++;
            }

        } else {

            db_query("
                UPDATE $tabla_full
                SET estado_proceso  = 'SIN_COORDENADAS_GKBA',
                    mensaje_error   = 'No había X/Y devueltos por el geocoder',
                    fecha_procesado = NOW()
                WHERE idgeo = $1
            ", [$id]);

            $sin_match++;
        }
    }

    // ============================================================
    // RESULTADO
    // ============================================================
    return [
        'tabla'      => $tabla_full,
        'total'      => $total,
        'ok'         => $ok,
        'sin_match'  => $sin_match,
        'error_api'  => $error_api
    ];
}
