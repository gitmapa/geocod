<?php
// =============================================================
// config/api_config_example.php
// Plantilla de configuración para la API de IDECABA
// -------------------------------------------------------------
// Propósito : Define las credenciales necesarias para autenticar
//             las llamadas a la API pública de IDECABA
//             (Infraestructura de Datos Espaciales de la Ciudad
//             de Buenos Aires).
//
//             Este archivo es una PLANTILLA de ejemplo y puede
//             subirse al repositorio sin riesgo.
//             El archivo real (api_config.php) contiene las
//             credenciales verdaderas y NUNCA debe subirse.
// -------------------------------------------------------------
// Instrucciones de uso:
//   1. Copiá este archivo como config/api_config.php
//   2. Reemplazá los valores de ejemplo con tus credenciales reales
//   3. Verificá que config/api_config.php esté en .gitignore
// -------------------------------------------------------------
// Cómo obtener credenciales:
//   Registrate en https://datosabiertos.buenosaires.gob.ar
//   y solicitá acceso a la API de callejero/geocodificación.
// -------------------------------------------------------------
// Cómo se usa este archivo en el sistema:
//   lib/api_idecaba.php lo incluye en cada llamada HTTP:
//     $cfg = include __DIR__ . '/../config/api_config.php';
//   Devuelve este array directamente a la función api_call().
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

return [

    // URL base de la API de IDECABA.
    // No incluir barra final — los endpoints la agregan ellos.
    'url_base' => 'https://datosabiertos-callejeros-apis.buenosaires.gob.ar',

    // Identificador del cliente, proporcionado por IDECABA.
    // Se envía como header HTTP 'client_id' en cada request.
    'client_id' => 'TU_CLIENT_ID_AQUI',

    // Secreto del cliente, proporcionado por IDECABA.
    // Se envía como header HTTP 'client_secret' en cada request.
    // Tratar con el mismo cuidado que una contraseña.
    'client_secret' => 'TU_CLIENT_SECRET_AQUI',

];
