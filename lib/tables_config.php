<?php
// =============================================================
// lib/tables_config.php
// Abstracción de acceso a geocod.tablas_config
// -------------------------------------------------------------
// Propósito : Provee funciones para leer la tabla de metadatos
//             geocod.tablas_config, que registra qué tablas
//             están habilitadas para ser procesadas por el
//             motor de geocodificación.
//
//             Actúa como capa de abstracción entre index.php
//             y la tabla de configuración: cualquier cambio
//             en la estructura de tablas_config se resuelve
//             aquí sin tocar el resto del sistema.
// -------------------------------------------------------------
// Dependencias:
//   - lib/db.php  (db_query)
// -------------------------------------------------------------
// Tabla que gestiona:
//   geocod.tablas_config
//     id_tabla_config  — PK autoincremental
//     nombre_config    — nombre lógico único (= nombre de la tabla _geo)
//     esquema          — esquema PostgreSQL donde vive la tabla
//     tabla            — nombre físico de la tabla
//     descripcion      — descripción libre del pedido
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

require_once __DIR__ . '/db.php';


// =============================================================
// FUNCIONES
// =============================================================

/**
 * get_table_config()
 * -------------------------------------------------------------
 * Busca y devuelve la configuración de una tabla geocodificable
 * a partir de su nombre lógico en geocod.tablas_config.
 *
 * El nombre lógico (nombre_config) coincide con el nombre de
 * la tabla _geo. Por ejemplo, para la tabla geocod.pedido_01_geo,
 * el nombre_config es 'pedido_01_geo'.
 *
 * El array retornado es el que consume directamente
 * geocode_pending_for_table() en geocoder_engine.php.
 *
 * @param  string $nombre_config  Nombre lógico de la tabla.
 *                                Coincide con el nombre de la tabla _geo.
 *                                Ejemplo: 'pedido_01_geo'
 *
 * @return array|null  Array asociativo con las claves:
 *                       'nombre_config' (string) — nombre lógico
 *                       'esquema'       (string) — esquema PostgreSQL
 *                       'tabla'         (string) — nombre físico
 *                       'descripcion'   (string) — descripción del pedido
 *                     O null si no existe el registro o hubo error de DB.
 */
function get_table_config($nombre_config)
{
    // El nombre viene del formulario de usuario pero se pasa como
    // parámetro preparado — nunca interpolado en el SQL.
    $sql = "
        SELECT nombre_config, esquema, tabla, descripcion
        FROM geocod.tablas_config
        WHERE nombre_config = $1
        LIMIT 1;
    ";

    $result = db_query($sql, [$nombre_config]);

    // Si la query falló (error de conexión u otro problema de DB)
    if (!$result) {
        return null;
    }

    $row = pg_fetch_assoc($result);
    if (!$row) {
        return null;
    }

    return $row;
}

/**
 * get_default_table_config()
 * -------------------------------------------------------------
 * Devuelve la configuración de la tabla por defecto del sistema.
 * Pensada para uso en scripts o contextos donde no se selecciona
 * tabla explícitamente.
 *
 * Actualmente apunta a 'direcciones_base'. Si en el futuro se
 * agrega un flag 'es_default' en geocod.tablas_config, esta
 * función debería actualizarse para consultarlo.
 *
 * @return array|null  Igual que get_table_config(), o null si
 *                     'direcciones_base' no existe en la tabla.
 */
function get_default_table_config()
{
    return get_table_config('direcciones_base');
}
