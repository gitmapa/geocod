<?php
// =============================================================
// descargar.php
// Endpoint de descarga de archivos en formato Excel (.xlsx)
// -------------------------------------------------------------
// Propósito : Genera y envía un archivo .xlsx para descarga
//             directa desde el navegador usando PhpSpreadsheet.
//             El formato Excel garantiza que las coordenadas
//             numéricas se muestren correctamente sin notación
//             científica, independiente de la configuración
//             regional del usuario.
//
//             Soporta dos modos:
//               - fuente       : datos crudos de geopedidos
//               - geolocalizada: JOIN fuente + tabla geo geocod
// -------------------------------------------------------------
// Parámetros GET:
//   tipo   (string) — 'fuente' o 'geolocalizada'
//   tabla  (string) — nombre de la tabla en geopedidos
//   pedido (string) — nombre del pedido (solo para geolocalizada)
// -------------------------------------------------------------
// Dependencias:
//   - vendor/autoload.php  (PhpSpreadsheet via Composer)
//   - lib/db.php
// -------------------------------------------------------------
// Versión   : 1.1
// =============================================================

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// ------------------------------------------------------------
// Leer y validar parámetros GET
// ------------------------------------------------------------
$tipo   = $_GET['tipo']   ?? '';
$tabla  = $_GET['tabla']  ?? '';
$pedido = $_GET['pedido'] ?? '';

// Validar nombres de tabla: solo alfanuméricos y guión bajo
if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
    http_response_code(400);
    die("Nombre de tabla inválido.");
}

if ($pedido !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $pedido)) {
    http_response_code(400);
    die("Nombre de pedido inválido.");
}

if (!in_array($tipo, ['fuente', 'geolocalizada'])) {
    http_response_code(400);
    die("Tipo de descarga inválido.");
}

// ------------------------------------------------------------
// Columnas de coordenadas que reciben formato numérico explícito
// para evitar notación científica en Excel.
// ------------------------------------------------------------
$columnas_coord_gkba = ['coordenada_x_gkba', 'coordenada_y_gkba'];
$columnas_coord_wgs  = ['latitud_wgs84', 'longitud_wgs84'];
$columnas_numericas  = array_merge($columnas_coord_gkba, $columnas_coord_wgs);

// ------------------------------------------------------------
// Construir la query según el tipo de descarga
// ------------------------------------------------------------
if ($tipo === 'fuente') {

    if (!tabla_existe('geopedidos', $tabla)) {
        http_response_code(404);
        die("La tabla fuente no existe.");
    }

    $sql         = "SELECT * FROM geopedidos.{$tabla} ORDER BY id_reg";
    $nombre_xlsx = "fuente_{$tabla}.xlsx";

} else {

    // En modo geolocalizada, $tabla contiene el nombre de la tabla_geo
    $tabla_geo = $tabla;

    if (!tabla_existe('geocod', $tabla_geo)) {
        http_response_code(404);
        die("La tabla geocodificada no existe.");
    }

    if ($pedido !== '' && tabla_existe('geopedidos', $pedido)) {

        // JOIN completo: todos los campos de la fuente + campos geo
        $sql = "
            SELECT
                p.*,
                g.direccion_normalizada,
                g.tipo_direccion,
                g.tipo_catastro,
                g.cod_calle_1,
                g.cod_calle_2,
                g.nombre_calle_1,
                g.nombre_calle_2,
                g.altura_normalizada,
                g.coordenada_x_gkba,
                g.coordenada_y_gkba,
                g.metodo_geocodificacion,
                g.smp,
                g.barrio_inf,
                g.sector_inf,
                g.manzana_inf,
                g.parcela_inf,
                g.comuna,
                g.barrio,
                g.distrito_escolar,
                g.comisaria,
                g.comisaria_vecinal,
                g.area_hospitalaria,
                g.region_sanitaria,
                g.seccion,
                g.codigo_postal,
                g.codigo_postal_argentino,
                g.latitud_wgs84,
                g.longitud_wgs84,
                g.estado_proceso,
                g.mensaje_error,
                g.fecha_procesado
            FROM geopedidos.{$pedido} p
            LEFT JOIN geocod.{$tabla_geo} g
                ON p.id_reg::text = g.idgeo::text
            ORDER BY p.id_reg
        ";

    } else {
        // Fallback: solo la tabla geo sin JOIN
        $sql = "SELECT * FROM geocod.{$tabla_geo} ORDER BY idgeo";
    }

    $nombre_xlsx = "geo_{$tabla_geo}.xlsx";
}

// ------------------------------------------------------------
// Ejecutar la query
// ------------------------------------------------------------
$res = db_query($sql);

if (!$res) {
    http_response_code(500);
    die("Error al consultar los datos.");
}

// ------------------------------------------------------------
// Construir el archivo Excel con PhpSpreadsheet
// ------------------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();

// Obtener nombres de columnas desde el resultado de pg
$num_campos  = pg_num_fields($res);
$encabezados = [];
for ($i = 0; $i < $num_campos; $i++) {
    $encabezados[] = pg_field_name($res, $i);
}

// Escribir encabezados en fila 1
$sheet->fromArray([$encabezados], null, 'A1');
$sheet->getStyle('1')->getFont()->setBold(true);

// Escribir filas de datos fila por fila.
// Las columnas de coordenadas se escriben como TYPE_NUMERIC
// para que el formato numérico de Excel funcione correctamente.
$fila_excel = 2;

// Mapear nombre de columna → índice para saber qué columnas formatear
$indice_por_nombre = array_flip($encabezados);

while ($fila = pg_fetch_assoc($res)) {

    $valores = array_values($fila);

    foreach ($valores as $col_idx => $valor) {

        // Calcular letra de columna Excel (A, B, ..., Z, AA, ...)
        $letra = Coordinate::stringFromColumnIndex($col_idx + 1);
        $celda = $sheet->getCell($letra . $fila_excel);

        $nombre_col = $encabezados[$col_idx];

        if (in_array($nombre_col, $columnas_numericas) && $valor !== null && $valor !== '') {
            // Forzar tipo numérico para que el formato de celda surta efecto
            $celda->setValueExplicit((float) $valor, DataType::TYPE_NUMERIC);
        } else {
            $celda->setValue($valor);
        }
    }

    $fila_excel++;
}

// ------------------------------------------------------------
// Aplicar formato numérico a las columnas de coordenadas.
// Esto controla cómo Excel muestra el número — con cuántos
// decimales y sin notación científica.
// ------------------------------------------------------------
$total_filas = $fila_excel - 1;

foreach ($encabezados as $col_idx => $nombre_col) {

    if (!in_array($nombre_col, $columnas_numericas)) {
        continue;
    }

    $letra = Coordinate::stringFromColumnIndex($col_idx + 1);
    $rango = $letra . '2:' . $letra . $total_filas;

    // 8 decimales para GKBA, 6 para WGS84
    $formato = in_array($nombre_col, $columnas_coord_gkba)
        ? '0.00000000'
        : '0.000000';

    $sheet->getStyle($rango)->getNumberFormat()->setFormatCode($formato);
}

// ------------------------------------------------------------
// Ajustar ancho de columnas automáticamente
// ------------------------------------------------------------
$ultima_col = Coordinate::stringFromColumnIndex(count($encabezados));
foreach (range('A', $ultima_col) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Congelar la primera fila (encabezados siempre visibles)
$sheet->freezePane('A2');

// ------------------------------------------------------------
// Enviar headers y escribir el archivo directamente al navegador
// ------------------------------------------------------------
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre_xlsx . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
