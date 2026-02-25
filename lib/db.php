<?php
// =============================================================
// lib/db.php
// Conexión a PostgreSQL y funciones auxiliares de consulta
// -------------------------------------------------------------
// Propósito : Centraliza toda la lógica de acceso a la base de
//             datos. Expone tres funciones usadas en todo el
//             sistema:
//               - db_connect()   — conexión singleton a PostgreSQL
//               - db_query()     — ejecución segura de queries
//               - tabla_existe() — verificación de existencia
//
//             El patrón singleton en db_connect() garantiza que
//             solo se abre una conexión por request HTTP, sin
//             importar cuántas veces se llame a db_query().
// -------------------------------------------------------------
// Dependencias:
//   - config/db_config.php  (define la constante DB_CONN_STRING)
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

require_once __DIR__ . '/../config/db_config.php';


// =============================================================
// CONEXIÓN
// =============================================================

/**
 * db_connect()
 * -------------------------------------------------------------
 * Retorna la conexión activa a PostgreSQL, abriéndola la primera
 * vez que se llama y reutilizándola en las llamadas siguientes
 * (patrón singleton via variable estática).
 *
 * Si la conexión falla (credenciales incorrectas, servidor no
 * disponible, etc.) termina la ejecución con die(). En un
 * entorno de producción se podría loguear el error antes de
 * terminar.
 *
 * @return \PgSql\Connection  Objeto de conexión PostgreSQL activo.
 */
function db_connect()
{
    // Variable estática: se inicializa solo la primera vez que
    // se ejecuta la función y persiste durante todo el request.
    static $conn = null;

    // Solo conectar si aún no hay una conexión activa
    if ($conn === null) {
        $conn = pg_connect(DB_CONN_STRING);
    }

    // Si la conexión falló, no tiene sentido continuar
    if (!$conn) {
        die("Error de conexión con la base de datos.");
    }

    return $conn;
}


// =============================================================
// CONSULTAS
// =============================================================

/**
 * db_query()
 * -------------------------------------------------------------
 * Wrapper unificado para ejecutar queries en PostgreSQL.
 * Elige automáticamente entre pg_query() y pg_query_params()
 * según si se pasan parámetros o no.
 *
 * Siempre que el query incluya valores externos (del usuario,
 * de un formulario, de la base misma) se deben pasar como
 * elementos del array $params usando placeholders $1, $2, etc.
 * Nunca interpolados directamente en el SQL — eso previene
 * inyección SQL.
 *
 * Uso sin parámetros:
 *   db_query("SELECT * FROM geocod.tablas_config");
 *
 * Uso con parámetros (forma correcta para valores externos):
 *   db_query(
 *       "SELECT * FROM geocod.tablas_config WHERE nombre_config = $1",
 *       ['mi_tabla']
 *   );
 *
 * @param  string $sql     Query SQL. Placeholders: $1, $2, $3, etc.
 * @param  array  $params  Valores para los placeholders, en el mismo
 *                         orden. Array vacío si no hay parámetros.
 *
 * @return \PgSql\Result|false  Resultado de PostgreSQL, o false si falló.
 */
function db_query($sql, $params = [])
{
    $db = db_connect();

    // Sin parámetros: pg_query() directo
    if (empty($params)) {
        return pg_query($db, $sql);
    }

    // Con parámetros: pg_query_params() para evitar SQL injection
    return pg_query_params($db, $sql, $params);
}


// =============================================================
// HELPERS
// =============================================================

/**
 * tabla_existe()
 * -------------------------------------------------------------
 * Verifica si una tabla existe físicamente en un esquema de
 * PostgreSQL consultando information_schema.tables.
 *
 * Se usa en múltiples puntos del sistema para protegerse contra
 * el caso en que una tabla haya sido eliminada manualmente desde
 * pgAdmin sin actualizar los metadatos del sistema.
 *
 * @param  string $schema  Nombre del esquema (ej: 'geocod', 'geopedidos')
 * @param  string $table   Nombre de la tabla a verificar
 *
 * @return bool  true si la tabla existe, false si no existe o hubo error.
 */
function tabla_existe($schema, $table)
{
    $sql = "
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = $1
          AND table_name   = $2
        LIMIT 1
    ";

    $res = db_query($sql, [$schema, $table]);

    // Si la query falló o no hay resultado, la tabla no existe
    if (!$res) {
        return false;
    }

    // pg_fetch_assoc devuelve false si no hay filas → tabla no existe
    return pg_fetch_assoc($res) ? true : false;
}
