<?php
// =============================================================
// lib/api_idecaba.php
// Wrapper de llamadas a la API pública de IDECABA
// -------------------------------------------------------------
// Propósito : Encapsula todas las llamadas HTTP a la API de
//             IDECABA (Infraestructura de Datos Espaciales de
//             la Ciudad de Buenos Aires).
//             Expone tres funciones de alto nivel usadas por
//             el motor de geocodificación:
//               - api_geocode()      — normaliza y geolocaliza
//               - api_datos_utiles() — datos administrativos
//               - api_transformar()  — convierte GKBA a WGS84
//
//             Las tres funciones retornan el mismo formato de
//             respuesta con tres claves: http_code, raw, json.
// -------------------------------------------------------------
// Dependencias:
//   - config/api_config.php  (url_base, client_id, client_secret)
//   - Extensión PHP curl
// -------------------------------------------------------------
// Formato de respuesta (todas las funciones):
//   [
//     'http_code' => int,    // Código HTTP (200, 401, 500, etc.)
//     'raw'       => string, // Cuerpo de la respuesta como string
//     'json'      => array   // Respuesta decodificada (null si no es JSON válido)
//   ]
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================


// =============================================================
// FUNCIÓN BASE
// =============================================================

/**
 * api_call()
 * -------------------------------------------------------------
 * Función interna que ejecuta el request HTTP a la API de
 * IDECABA. Todas las funciones públicas de este archivo la
 * usan como base — no se llama directamente desde afuera.
 *
 * Las credenciales se envían como headers HTTP (client_id y
 * client_secret) según la especificación de la API de IDECABA.
 *
 * CURLOPT_SSL_VERIFYPEER está deshabilitado para compatibilidad
 * con entornos de desarrollo local (XAMPP). En producción
 * debería habilitarse.
 *
 * @param  string $endpoint  Ruta del endpoint sin la URL base.
 *                           Ejemplo: "/direcciones/geocoder?direccion=CORRIENTES+1000&v2=true"
 *
 * @return array  Array con tres claves:
 *                  'http_code' (int)        — código de respuesta HTTP
 *                  'raw'       (string)     — cuerpo de la respuesta como texto
 *                  'json'      (array|null) — respuesta decodificada,
 *                                            null si no es JSON válido
 */
function api_call($endpoint)
{
    // Cargar credenciales desde el archivo de configuración.
    // Se usa include (no require_once) porque la función puede
    // llamarse múltiples veces y necesita el array en cada llamada.
    $cfg = include __DIR__ . '/../config/api_config.php';

    // Construir la URL completa: base + endpoint con sus parámetros
    $url = $cfg['url_base'] . $endpoint;

    // Headers de autenticación requeridos por la API de IDECABA
    $headers = [
        "client_id: {$cfg['client_id']}",
        "client_secret: {$cfg['client_secret']}"
    ];

    // Inicializar y configurar el handle de cURL
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,    // Retornar la respuesta como string
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false    // Deshabilitado para desarrollo local
    ]);

    // Ejecutar el request y capturar respuesta y código HTTP.
    // curl_close() fue deprecado en PHP 8.0 — el handle se libera
    // automáticamente al salir del scope de la función.
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [
        'http_code' => $http,
        'raw'       => $res,
        'json'      => json_decode($res, true)  // null si no es JSON válido
    ];
}


// =============================================================
// FUNCIONES PÚBLICAS
// =============================================================

/**
 * api_geocode()
 * -------------------------------------------------------------
 * Llama al endpoint de geocodificación de IDECABA.
 * Normaliza la dirección y devuelve coordenadas en sistema
 * GKBA (Gauss-Krüger Buenos Aires), junto con datos
 * catastrales: SMP, barrio, sector, manzana, parcela.
 *
 * @param  string $direccion  Dirección en texto libre.
 *                            Ejemplo: "CORRIENTES 1000", "AV RIVADAVIA 500"
 *
 * @return array  Respuesta estándar de api_call().
 *                En 'json']['data'] se encuentran: direccion,
 *                tipoDireccion, coordenada_x, coordenada_y,
 *                smp, barrioInf, codCalle_1, altura, etc.
 */
function api_geocode($direccion)
{
    // Codificar para incluir la dirección como parámetro URL
    $direccion = urlencode($direccion);
    return api_call("/direcciones/geocoder?direccion={$direccion}&v2=true");
}

/**
 * api_datos_utiles()
 * -------------------------------------------------------------
 * Llama al endpoint de datos útiles de IDECABA.
 * Para una dirección normalizada devuelve información
 * administrativa: comuna, barrio, código postal, distrito
 * escolar, comisaría, área hospitalaria, región sanitaria,
 * sección electoral, CPA, etc.
 *
 * Se llama con la dirección ya NORMALIZADA por el geocoder
 * (no la dirección cruda original) para mayor precisión.
 *
 * @param  string $direccion  Dirección normalizada por el geocoder.
 *                            Ejemplo: "CORRIENTES 1000"
 *
 * @return array  Respuesta estándar de api_call().
 *                En 'json']['data'] se encuentran: comuna, barrio,
 *                codigo_postal, distrito_escolar, comisaria, etc.
 */
function api_datos_utiles($direccion)
{
    $direccion = urlencode($direccion);
    return api_call("/datos/datos-utiles?direccion={$direccion}&v2=true");
}

/**
 * api_transformar()
 * -------------------------------------------------------------
 * Llama al endpoint de transformación de coordenadas de IDECABA.
 * Convierte un par de coordenadas del sistema local GKBA
 * (Gauss-Krüger Buenos Aires) al sistema geodésico estándar
 * WGS84 (latitud/longitud).
 *
 * ATENCIÓN: la API devuelve x=longitud e y=latitud, que es el
 * orden inverso al convencional (lat, lon). El motor en
 * geocoder_engine.php ya contempla esta inversión al leer
 * la respuesta.
 *
 * @param  float|string $x  Coordenada X en sistema GKBA (este)
 * @param  float|string $y  Coordenada Y en sistema GKBA (norte)
 *
 * @return array  Respuesta estándar de api_call().
 *                En 'json']['data']:
 *                  'x' → longitud WGS84
 *                  'y' → latitud  WGS84
 */
function api_transformar($x, $y)
{
    return api_call("/coordenadas/transformar?x={$x}&y={$y}");
}
