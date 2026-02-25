<?php
// =============================================================
// config/db_config_example.php
// Plantilla de configuración para la base de datos PostgreSQL
// -------------------------------------------------------------
// Propósito : Define la cadena de conexión a PostgreSQL usada
//             por todo el sistema a través de lib/db.php.
//
//             Este archivo es una PLANTILLA de ejemplo y puede
//             subirse al repositorio sin riesgo.
//             El archivo real (db_config.php) contiene los datos
//             reales de conexión y NUNCA debe subirse.
// -------------------------------------------------------------
// Instrucciones de uso:
//   1. Copiá este archivo como config/db_config.php
//   2. Reemplazá los valores de ejemplo con tus datos reales
//   3. Verificá que config/db_config.php esté en .gitignore
// -------------------------------------------------------------
// Cómo se usa este archivo en el sistema:
//   lib/db.php lo carga con require_once al iniciarse:
//     require_once __DIR__ . '/../config/db_config.php';
//   Define la constante DB_CONN_STRING usada por pg_connect().
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

// Cadena de conexión en formato libpq para pg_connect().
// Los parámetros se separan por espacio en formato clave=valor.
// Referencia: https://www.postgresql.org/docs/current/libpq-connect.html
define('DB_CONN_STRING',
    'host=localhost '      .   // Servidor PostgreSQL — 'localhost' para XAMPP local
    'port=5432 '           .   // Puerto por defecto de PostgreSQL
    'dbname=NOMBRE_DB '    .   // Nombre de la base de datos del sistema
    'user=USUARIO_DB '     .   // Usuario de PostgreSQL con permisos sobre la base
    'password=PASSWORD_DB'     // Contraseña del usuario
);
