<?php
// =============================================================
// selftest.php
// Herramienta de diagnóstico completo del sistema
// -------------------------------------------------------------
// Propósito : Verifica que todos los componentes del sistema
//             estén correctamente instalados y configurados.
//             Pensado para uso del administrador/desarrollador,
//             no para el usuario final.
//
//             Ejecuta 6 chequeos en secuencia:
//               1. Extensión PHP pgsql
//               2. Extensión PHP curl
//               3. PhpSpreadsheet (vendor/autoload.php)
//               4. Conexión a PostgreSQL
//               5. Existencia de esquemas y tablas requeridas
//               6. API de IDECABA respondiendo (Brandsen 805)
//
//             Muestra los resultados en una tabla HTML con
//             indicadores visuales por chequeo y un resumen
//             general al inicio.
// -------------------------------------------------------------
// Acceso : http://localhost/geocod/selftest.php
// -------------------------------------------------------------
// Dependencias (cargadas condicionalmente si existen):
//   - lib/db.php          (db_connect, db_query, tabla_existe)
//   - lib/api_idecaba.php (api_geocode)
//   - vendor/autoload.php (PhpSpreadsheet)
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

// Mostrar todos los errores de PHP pero sin interrumpir el HTML.
// Así el reporte siempre se renderiza aunque algo falle.
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------------------------------------------
// Verificar existencia de dependencias antes de cargarlas.
// Se usa file_exists() para no generar un fatal error si
// algún archivo no está presente — el chequeo lo reportará.
// ------------------------------------------------------------
$db_disponible     = file_exists(__DIR__ . '/lib/db.php');
$api_disponible    = file_exists(__DIR__ . '/lib/api_idecaba.php');
$vendor_disponible = file_exists(__DIR__ . '/vendor/autoload.php');

if ($db_disponible)  require_once __DIR__ . '/lib/db.php';
if ($api_disponible) require_once __DIR__ . '/lib/api_idecaba.php';

// ------------------------------------------------------------
// Acumulador de resultados de chequeos.
// Cada elemento es un array con:
//   'nombre'  (string) — nombre legible del componente
//   'ok'      (bool)   — true si el chequeo pasó
//   'detalle' (string) — descripción del resultado o del error
// ------------------------------------------------------------
$checks = [];

// ------------------------------------------------------------
// CHECK 1 — Extensión PHP pgsql
// Requerida para todas las funciones pg_*() que usa lib/db.php.
// Sin esta extensión no hay conexión a PostgreSQL posible.
// ------------------------------------------------------------
$checks[] = [
    'nombre'  => 'Extensión PHP pgsql',
    'ok'      => extension_loaded('pgsql'),
    'detalle' => extension_loaded('pgsql')
                    ? 'Disponible'
                    : 'No disponible — habilitá extension=pgsql en php.ini',
];

// ------------------------------------------------------------
// CHECK 2 — Extensión PHP curl
// Requerida para las llamadas HTTP a la API de IDECABA
// que realiza lib/api_idecaba.php con curl_init() etc.
// ------------------------------------------------------------
$checks[] = [
    'nombre'  => 'Extensión PHP curl',
    'ok'      => extension_loaded('curl'),
    'detalle' => extension_loaded('curl')
                    ? 'Disponible'
                    : 'No disponible — habilitá extension=curl en php.ini',
];

// ------------------------------------------------------------
// CHECK 3 — PhpSpreadsheet via Composer
// Requerido para generar archivos .xlsx en descargar.php.
// Se verifica la existencia del autoloader de Composer.
// ------------------------------------------------------------
$checks[] = [
    'nombre'  => 'PhpSpreadsheet (vendor/autoload.php)',
    'ok'      => $vendor_disponible,
    'detalle' => $vendor_disponible
                    ? 'Disponible'
                    : 'No encontrado — ejecutá: composer install',
];

// ------------------------------------------------------------
// CHECK 4 — Conexión a la base de datos PostgreSQL
// Intenta abrir la conexión real usando db_connect().
// Solo se ejecuta si lib/db.php está disponible.
// ------------------------------------------------------------
$db_ok      = false;
$db_detalle = 'No se intentó (lib/db.php no encontrado)';

if ($db_disponible) {
    try {
        $conn = db_connect();
        if ($conn) {
            $db_ok      = true;
            $db_detalle = 'Conexión exitosa';
        } else {
            $db_detalle = 'Falló pg_connect() — verificá config/db_config.php';
        }
    } catch (Throwable $e) {
        $db_detalle = 'Error: ' . $e->getMessage();
    }
}

$checks[] = [
    'nombre'  => 'Conexión PostgreSQL',
    'ok'      => $db_ok,
    'detalle' => $db_detalle,
];

// ------------------------------------------------------------
// CHECK 5 — Esquemas y tablas requeridas en la base
// Verifica que existan los esquemas geocod y geopedidos, y las
// tres tablas de configuración del sistema.
// Solo se ejecuta si la conexión a la base fue exitosa.
// ------------------------------------------------------------
if ($db_ok) {

    // Verificar esquema geocod
    $res = db_query("SELECT 1 FROM information_schema.schemata WHERE schema_name = 'geocod'");
    $checks[] = [
        'nombre'  => 'Esquema geocod',
        'ok'      => (bool) pg_fetch_assoc($res),
        'detalle' => pg_fetch_assoc(db_query("SELECT 1 FROM information_schema.schemata WHERE schema_name = 'geocod'"))
                        ? 'Existe'
                        : 'No existe — ejecutá sql/00_esquemas.sql',
    ];

    // Verificar esquema geopedidos
    $res2 = db_query("SELECT 1 FROM information_schema.schemata WHERE schema_name = 'geopedidos'");
    $checks[] = [
        'nombre'  => 'Esquema geopedidos',
        'ok'      => (bool) pg_fetch_assoc($res2),
        'detalle' => pg_fetch_assoc(db_query("SELECT 1 FROM information_schema.schemata WHERE schema_name = 'geopedidos'"))
                        ? 'Existe'
                        : 'No existe — ejecutá sql/00_esquemas.sql',
    ];

    // Verificar las tres tablas de configuración del sistema
    foreach (['tablas_config', 'tablas_geo_config', 'tabla_geo_plantilla'] as $t) {
        $existe = tabla_existe('geocod', $t);
        $checks[] = [
            'nombre'  => "Tabla geocod.{$t}",
            'ok'      => $existe,
            'detalle' => $existe
                            ? 'Existe'
                            : "No existe — ejecutá el script SQL correspondiente",
        ];
    }

} else {
    // Sin conexión no se pueden verificar las tablas
    $checks[] = [
        'nombre'  => 'Tablas de configuración',
        'ok'      => false,
        'detalle' => 'No verificado — sin conexión a la base',
    ];
}

// ------------------------------------------------------------
// CHECK 6 — API de IDECABA
// Llama al geocoder con la misma dirección de prueba que usa
// el motor antes de procesar (Brandsen 805).
// Si este chequeo pasa, el motor puede ejecutarse correctamente.
// Solo se ejecuta si lib/api_idecaba.php está disponible.
// ------------------------------------------------------------
$api_ok      = false;
$api_detalle = 'No se intentó (lib/api_idecaba.php no encontrado)';

if ($api_disponible) {
    try {
        $resp = api_geocode('Brandsen 805');

        if ($resp['http_code'] == 200 && !empty($resp['json']['data'])) {
            $api_ok      = true;
            $dir_norm    = $resp['json']['data']['direccion'] ?? '(sin dirección normalizada)';
            $api_detalle = "OK — dirección normalizada: \"{$dir_norm}\"";
        } else {
            $api_detalle = "HTTP {$resp['http_code']} — " .
                           (empty($resp['raw']) ? 'sin respuesta' : substr($resp['raw'], 0, 120));
        }
    } catch (Throwable $e) {
        $api_detalle = 'Excepción: ' . $e->getMessage();
    }
}

$checks[] = [
    'nombre'  => 'API IDECABA — geocoder (Brandsen 805)',
    'ok'      => $api_ok,
    'detalle' => $api_detalle,
];

// ------------------------------------------------------------
// Calcular totales para el resumen del encabezado
// ------------------------------------------------------------
$total_ok   = count(array_filter($checks, fn($c) => $c['ok']));
$total_fail = count($checks) - $total_ok;
$todo_ok    = $total_fail === 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>UEICEE · MAPA · GEOCOD — Self Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body        { background-color: #f5f6fa; }
        .header-bar { background-color: #273c75; color: #fff; padding: 18px; margin-bottom: 25px; }
    </style>
</head>
<body>

<div class="header-bar">
    <h3 class="m-0">UEICEE · MAPA · GEOCOD <small class="fs-6 ms-2 opacity-75">v1.1 — Self Test</small></h3>
</div>

<div class="container">

    <!-- Resumen general: verde si todo OK, rojo si hay fallos -->
    <div class="alert <?= $todo_ok ? 'alert-success' : 'alert-danger' ?> mb-4">
        <?php if ($todo_ok): ?>
            <strong>✅ Todo OK.</strong> El sistema está correctamente instalado y configurado.
        <?php else: ?>
            <strong>❌ <?= $total_fail ?> chequeo<?= $total_fail > 1 ? 's' : '' ?> fallido<?= $total_fail > 1 ? 's' : '' ?>.</strong>
            Revisá los detalles a continuación.
        <?php endif; ?>
    </div>

    <!-- Tabla de resultados por chequeo -->
    <div class="card shadow-sm p-4">
        <table class="table table-bordered align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th style="width:40px;">Estado</th>
                    <th>Componente</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $check): ?>
                <tr class="<?= $check['ok'] ? '' : 'table-danger' ?>">
                    <td class="text-center fs-5">
                        <?= $check['ok'] ? '✅' : '❌' ?>
                    </td>
                    <td><?= htmlspecialchars($check['nombre']) ?></td>
                    <td><code><?= htmlspecialchars($check['detalle']) ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Versión de PHP y timestamp del chequeo -->
    <p class="text-muted mt-3 small text-end">
        PHP <?= phpversion() ?> &nbsp;|&nbsp;
        <?= date('Y-m-d H:i:s') ?>
    </p>

    <a href="index.php" class="btn btn-secondary mt-2">← Volver al sistema</a>

</div>
</body>
</html>
