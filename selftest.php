<?php

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api_idecaba.php';

echo "<h2>Self Test</h2>";

echo "<h3>DB</h3>";
try {
    $db = db_connect();
    echo "Conexión OK<br>";
} catch(Exception $e) {
    echo "ERROR<br>";
}

echo "<h3>API Geocoder</h3>";
$g = api_geocode("corrientes 1853");
echo "HTTP: " . $g['http_code'] . "<br>";
echo "<pre>" . substr($g['raw'], 0, 200) . "</pre>";
