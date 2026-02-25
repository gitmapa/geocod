<?php
// =============================================================
// config/db_config.example.php
// Plantilla de configuración para la base de datos PostgreSQL.
// -------------------------------------------------------------
// INSTRUCCIONES:
//   1. Copiá este archivo como db_config.php en la misma carpeta
//   2. Completá los valores reales
//   3. NUNCA subas db_config.php al repositorio
// =============================================================

define('DB_CONN_STRING',
    'host=localhost '     .
    'port=5432 '          .
    'dbname=NOMBRE_DB '   .
    'user=USUARIO_DB '    .
    'password=PASSWORD_DB'
);