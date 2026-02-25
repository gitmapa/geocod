<?php
// lib/db.php
// -------------------------------------------------------------
// Conexión PostgreSQL y helpers
// -------------------------------------------------------------

require_once __DIR__ . '/../config/db_config.php';

/**
 * Conecta a la base declarada en db_config.php
 */
function db_connect()
{
    static $conn = null;

    if ($conn === null) {
        $conn = pg_connect(DB_CONN_STRING);
    }

    if (!$conn) {
        die("Error de conexión con la base de datos.");
    }

    return $conn;
}

/**
 * db_query: wrapper simple para pg_query_params
 */
function db_query($sql, $params = [])
{
    $db = db_connect();

    if (empty($params)) {
        return pg_query($db, $sql);
    }

    return pg_query_params($db, $sql, $params);
}

/**
 * Verifica si existe una tabla en un schema
 */
function tabla_existe($schema, $table)
{
    $sql = "
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = $1
          AND table_name = $2
        LIMIT 1
    ";
    $res = db_query($sql, [$schema, $table]);
    return pg_fetch_assoc($res) ? true : false;
}
