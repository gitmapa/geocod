<?php
// lib/tables_config.php
// -------------------------------------------------------------
// Abstracción mínima para leer geocod.tablas_config
// -------------------------------------------------------------

require_once __DIR__ . '/db.php';

/**
 * Obtiene la configuración de una tabla geocodificable por nombre lógico.
 *
 * @param string $nombre_config Nombre lógico configurado (ej: 'direcciones_base')
 * @return array|null           Array asociativo con ['nombre_config','esquema','tabla','descripcion'] o null si no existe
 */
function get_table_config($nombre_config)
{
    // Seguridad básica: el nombre viene del usuario pero se parametriza.
    $sql = "
        SELECT nombre_config, esquema, tabla, descripcion
        FROM geocod.tablas_config
        WHERE nombre_config = $1
        LIMIT 1;
    ";

    $result = db_query($sql, [$nombre_config]);

    if (!$result) {
        // Error de ejecución; podés loguear si querés
        return null;
    }

    $row = pg_fetch_assoc($result);
    if (!$row) {
        return null;
    }

    return $row;
}

/**
 * Devuelve una config por defecto si el usuario no pasa nada.
 *
 * @return array|null
 */
function get_default_table_config()
{
    // Si quisieras tener un flag "es_default" en la tabla, acá lo usarías.
    // Por ahora, devolvemos 'direcciones_base' a mano.
    return get_table_config('direcciones_base');
}
