<?php

function api_call($endpoint) {

    $cfg = include __DIR__ . '/../config/api_config.php';

    $url = $cfg['url_base'] . $endpoint;

    $headers = [
        "client_id: {$cfg['client_id']}",
        "client_secret: {$cfg['client_secret']}"
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $http,
        'raw' => $res,
        'json' => json_decode($res, true)
    ];
}

function api_geocode($direccion) {
    $direccion = urlencode($direccion);
    return api_call("/direcciones/geocoder?direccion={$direccion}&v2=true");
}

function api_datos_utiles($direccion) {
    $direccion = urlencode($direccion);
    return api_call("/datos/datos-utiles?direccion={$direccion}&v2=true");
}

function api_transformar($x, $y) {
    return api_call("/coordenadas/transformar?x={$x}&y={$y}");
}
