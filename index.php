<?php
// =============================================================
// index.php
// Interfaz principal del sistema UEICEE - MAPA - GEOCOD
// -------------------------------------------------------------
// Propósito : Punto de entrada único de la aplicación.
//             Implementa el patrón PRG (Post-Redirect-Get) para
//             evitar reenvíos de formulario al recargar.
//             Organiza la UI en 4 pestañas:
//               1. ABM        — gestión de pedidos y tablas geo
//               2. Geocodificar — ejecución del motor
//               3. Descargas  — exportación de datos en CSV
//               4. Guía de Uso — documentación inline
// -------------------------------------------------------------
// Versión   : 1.1
// =============================================================

session_start();

require_once __DIR__ . '/lib/tables_config.php';
require_once __DIR__ . '/lib/geocoder_engine.php';
require_once __DIR__ . '/lib/report_utils.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/parser_direcciones.php';


// ====================================================================
// FUNCIONES AUXILIARES DE CONSULTA
// ====================================================================

/**
 * obtener_tablas_pedido_sin_geo()
 * -------------------------------------------------------------
 * Devuelve las tablas del esquema geopedidos que aún NO tienen
 * un registro en geocod.tablas_geo_config.
 * Esto garantiza que en "Crear tabla geo" solo aparezcan pedidos
 * que nunca fueron procesados.
 *
 * @return array Lista de nombres de tabla
 */
function obtener_tablas_pedido_sin_geo()
{
    $sql = "
        SELECT t.tablename
        FROM pg_tables t
        WHERE t.schemaname = 'geopedidos'
          AND t.tablename  NOT LIKE '%_geo'
          AND NOT EXISTS (
              SELECT 1
              FROM geocod.tablas_geo_config c
              WHERE c.nombre_pedido = t.tablename
          )
        ORDER BY t.tablename
    ";
    $res = db_query($sql);
    $out = [];
    while ($r = pg_fetch_assoc($res)) {
        $out[] = $r['tablename'];
    }
    return $out;
}

/**
 * obtener_tablas_geo_config()
 * -------------------------------------------------------------
 * Devuelve todos los registros de geocod.tablas_geo_config
 * sincronizando el estado real con la existencia física de las
 * tablas en la base de datos.
 *
 * Reglas de sincronización automática:
 *  - Si no existe ni en geopedidos ni en geocod → BORRADA
 *  - Si existe en geocod y estaba POBLADA → ENVIADA
 *  - Si existe en geopedidos pero no en geocod y estaba ENVIADA → POBLADA
 *  - GEOCODIFICADA no se toca (es estado final)
 *
 * @return array Lista de registros con claves: id_tabla, nombre_pedido,
 *               tabla_geo, fecha_creado, estado
 */
function obtener_tablas_geo_config()
{
    $sql = "
        SELECT id_tabla, nombre_pedido, tabla_geo, fecha_creado, estado
        FROM geocod.tablas_geo_config
        ORDER BY fecha_creado DESC
    ";
    $res = db_query($sql);
    $out = [];

    while ($r = pg_fetch_assoc($res)) {

        $tg = $r['tabla_geo'];
        $id = $r['id_tabla'];

        $en_geopedidos = tabla_existe('geopedidos', $tg);
        $en_geocod     = tabla_existe('geocod',     $tg);

        // No existe en ningún lado y no está marcada como BORRADA
        if (!$en_geopedidos && !$en_geocod && $r['estado'] !== 'BORRADA') {
            db_query(
                "UPDATE geocod.tablas_geo_config SET estado = 'BORRADA' WHERE id_tabla = $1",
                [$id]
            );
            $r['estado'] = 'BORRADA';
        }

        // Existe en geocod pero el estado dice POBLADA → ya fue enviada
        if ($en_geocod && $r['estado'] === 'POBLADA') {
            db_query(
                "UPDATE geocod.tablas_geo_config SET estado = 'ENVIADA' WHERE id_tabla = $1",
                [$id]
            );
            $r['estado'] = 'ENVIADA';
        }

        // Existe en geopedidos pero no en geocod y decía ENVIADA → volvió atrás
        if ($en_geopedidos && !$en_geocod && $r['estado'] === 'ENVIADA') {
            db_query(
                "UPDATE geocod.tablas_geo_config SET estado = 'POBLADA' WHERE id_tabla = $1",
                [$id]
            );
            $r['estado'] = 'POBLADA';
        }

        $out[] = $r;
    }

    return $out;
}

/**
 * generar_nombre_tabla()
 * -------------------------------------------------------------
 * Convierte un nombre de archivo CSV en un nombre de tabla
 * PostgreSQL válido y único dentro del esquema geopedidos.
 *
 * Reglas:
 *  - Se elimina la extensión .csv
 *  - Espacios y caracteres no alfanuméricos → guión bajo
 *  - Se trunca a 12 caracteres
 *  - Se agrega sufijo _01, _02, etc. si ya existe
 *
 * @param  string $nombre_archivo  Nombre original del archivo subido
 * @return string                  Nombre de tabla generado
 */
function generar_nombre_tabla(string $nombre_archivo): string
{
    // Quitar extensión
    $base = pathinfo($nombre_archivo, PATHINFO_FILENAME);

    // Reemplazar caracteres no alfanuméricos por guión bajo y pasar a minúsculas
    $base = preg_replace('/[^a-zA-Z0-9]+/', '_', $base);
    $base = strtolower($base);

    // Truncar a 12 caracteres
    $base = substr($base, 0, 12);

    // Eliminar guiones bajos al final que puedan haber quedado
    $base = rtrim($base, '_');

    // Buscar sufijo disponible (_01, _02, ...)
    $sufijo  = 1;
    $nombre  = $base . '_' . str_pad($sufijo, 2, '0', STR_PAD_LEFT);

    while (tabla_existe('geopedidos', $nombre)) {
        $sufijo++;
        $nombre = $base . '_' . str_pad($sufijo, 2, '0', STR_PAD_LEFT);
    }

    return $nombre;
}


// ====================================================================
// PROCESAMIENTO DE FORMULARIOS — PRG
// ====================================================================

// --------------------------------------------------------------
// ACCIÓN: subir_csv
// Carga un archivo CSV al esquema geopedidos creando una tabla
// con el nombre generado a partir del nombre del archivo.
// Columnas requeridas: id_reg, direccion_raw
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'subir_csv') {

    // Verificar que se subió un archivo sin errores
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Error al subir el archivo. Intentá de nuevo.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    $archivo  = $_FILES['archivo_csv'];
    $tmp_path = $archivo['tmp_name'];
    $nombre   = $archivo['name'];

    // Validar extensión CSV
    if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'csv') {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Solo se permiten archivos CSV.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Abrir el archivo para leer encabezados
    $handle = fopen($tmp_path, 'r');
    if (!$handle) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo leer el archivo CSV.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Leer primera línea como encabezados (separador: coma o punto y coma)
    $primera_linea = fgets($handle);
    rewind($handle);

    // Detectar separador automáticamente
    $separador = (substr_count($primera_linea, ';') > substr_count($primera_linea, ',')) ? ';' : ',';

    $encabezados = fgetcsv($handle, 0, $separador);

    if (!$encabezados) {
        fclose($handle);
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudieron leer los encabezados del CSV.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Normalizar encabezados (trim + minúsculas)
    $encabezados = array_map(fn($h) => strtolower(trim($h)), $encabezados);

    // Verificar columnas mínimas requeridas
    if (!in_array('id_reg', $encabezados) || !in_array('direccion_raw', $encabezados)) {
        fclose($handle);
        $_SESSION['flash_abm'] = "
            <div class='alert alert-danger'>
                El CSV no contiene las columnas requeridas.<br>
                Se necesitan al menos: <strong>id_reg</strong> y <strong>direccion_raw</strong>.
            </div>
        ";
        header("Location: index.php?abm=1");
        exit;
    }

    // Generar nombre de tabla único
    $nombre_tabla = generar_nombre_tabla($nombre);

    // Crear la tabla en geopedidos con las columnas del CSV como texto
    // Todas las columnas se crean como TEXT para máxima compatibilidad
    $cols_sql = implode(', ', array_map(fn($h) => '"' . $h . '" TEXT', $encabezados));

    $create_ok = db_query("CREATE TABLE geopedidos.{$nombre_tabla} ({$cols_sql})");

    if (!$create_ok) {
        fclose($handle);
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo crear la tabla en la base de datos.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Insertar filas del CSV
    $insertados = 0;
    $errores    = 0;

    // Preparar placeholders dinámicos según cantidad de columnas
    $n_cols       = count($encabezados);
    $placeholders = implode(', ', array_map(fn($i) => '$' . ($i + 1), range(0, $n_cols - 1)));
    $cols_lista   = implode(', ', array_map(fn($h) => '"' . $h . '"', $encabezados));

    while (($fila = fgetcsv($handle, 0, $separador)) !== false) {

        // Ignorar filas vacías
        if (count(array_filter($fila, fn($v) => trim($v) !== '')) === 0) {
            continue;
        }

        // Ajustar cantidad de valores al número de columnas
        $valores = array_slice(array_pad($fila, $n_cols, null), 0, $n_cols);

        $ok = db_query(
            "INSERT INTO geopedidos.{$nombre_tabla} ({$cols_lista}) VALUES ({$placeholders})",
            $valores
        );

        $ok ? $insertados++ : $errores++;
    }

    fclose($handle);

    $_SESSION['flash_abm'] = "
        <div class='alert alert-success'>
            Archivo cargado exitosamente como tabla <strong>geopedidos.{$nombre_tabla}</strong>.<br>
            Filas insertadas: <strong>{$insertados}</strong>
            " . ($errores > 0 ? "<br><span class='text-warning'>Filas con error: {$errores}</span>" : "") . "
        </div>
    ";

    header("Location: index.php?abm=1");
    exit;
}


// --------------------------------------------------------------
// ACCIÓN: crear_geo
// Crea la tabla _geo en geopedidos a partir de una tabla pedido,
// parseando direccion_raw en calle_original + altura_original.
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'crear_geo') {

    $pedido = trim($_POST['pedido'] ?? '');

    if ($pedido === '') {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Debe seleccionar un pedido.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Verificar que la tabla pedido existe realmente
    if (!tabla_existe('geopedidos', $pedido)) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>La tabla seleccionada no existe.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    $tabla_geo = $pedido . '_geo';

    // Crear tabla geo desde la plantilla
    $ok = db_query(
        "CREATE TABLE geopedidos.{$tabla_geo} (LIKE geocod.tabla_geo_plantilla INCLUDING ALL)"
    );

    if (!$ok) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo crear la tabla GEO.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Leer y parsear todas las direcciones del pedido
    $res_sel    = db_query("SELECT id_reg, direccion_raw FROM geopedidos.{$pedido} ORDER BY id_reg");
    $insertados = 0;
    $sin_altura = 0;

    while ($row = pg_fetch_assoc($res_sel)) {

        $parsed = parse_direccion_raw($row['direccion_raw']);

        if ($parsed['altura'] === null) {
            $sin_altura++;
        }

        db_query(
            "INSERT INTO geopedidos.{$tabla_geo} (idgeo, pedido, calle_original, altura_original)
             VALUES ($1, $2, $3, $4)",
            [$row['id_reg'], $pedido, $parsed['calle'], $parsed['altura']]
        );

        $insertados++;
    }

    // Registrar en metadatos
    db_query(
        "INSERT INTO geocod.tablas_geo_config (nombre_pedido, tabla_geo, estado)
         VALUES ($1, $2, 'POBLADA')",
        [$pedido, $tabla_geo]
    );

    $_SESSION['flash_abm'] = "
        <div class='alert alert-success'>
            Tabla <strong>geopedidos.{$tabla_geo}</strong> creada exitosamente.<br>
            Filas insertadas: <strong>{$insertados}</strong><br>
            Sin altura detectada: <strong>{$sin_altura}</strong>
        </div>
    ";

    header("Location: index.php?abm=1");
    exit;
}


// --------------------------------------------------------------
// ACCIÓN: enviar_geocod
// Copia la tabla geo de geopedidos a geocod y la registra en
// tablas_config para que el motor pueda procesarla.
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'enviar_geocod') {

    $tabla_geo = $_POST['tabla_geo'] ?? '';
    $pedido    = $_POST['pedido']    ?? '';

    // Validar caracteres para evitar injection en nombre de tabla
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla_geo)) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Nombre de tabla inválido.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Copiar tabla de geopedidos a geocod
    $ok = db_query("CREATE TABLE geocod.{$tabla_geo} AS SELECT * FROM geopedidos.{$tabla_geo}");

    if (!$ok) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Error al copiar la tabla a geocod.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Registrar en tablas_config para que aparezca en el motor
    db_query(
        "INSERT INTO geocod.tablas_config (nombre_config, esquema, tabla, descripcion)
         VALUES ($1, 'geocod', $2, $3)",
        [$tabla_geo, $tabla_geo, "Pedido {$pedido}"]
    );

    // Actualizar estado en tablas_geo_config
    db_query(
        "UPDATE geocod.tablas_geo_config SET estado = 'ENVIADA' WHERE tabla_geo = $1",
        [$tabla_geo]
    );

    $_SESSION['flash_abm'] = "<div class='alert alert-success'>Tabla <strong>{$tabla_geo}</strong> enviada a geocod y lista para geocodificar.</div>";

    header("Location: index.php?abm=1");
    exit;
}


// --------------------------------------------------------------
// ACCIÓN: borrar_meta
// Elimina el registro de metadatos de una tabla BORRADA.
// No toca tablas físicas.
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'borrar_meta') {

    db_query(
        "DELETE FROM geocod.tablas_geo_config WHERE id_tabla = $1",
        [$_POST['id_tabla']]
    );

    $_SESSION['flash_abm'] = "<div class='alert alert-info'>Metadato eliminado.</div>";

    header("Location: index.php?abm=1");
    exit;
}


// --------------------------------------------------------------
// ACCIÓN: ejecutar geocodificación
// Corre el motor sobre la tabla seleccionada y marca el estado
// como GEOCODIFICADA en tablas_geo_config independientemente
// del resultado individual de cada fila.
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['tabla_cfg'])
    && !isset($_POST['accion'])) {

    $tabla_cfg = $_POST['tabla_cfg'];
    $config    = get_table_config($tabla_cfg);

    if (!$config) {
        $resultado = ['error' => "Configuración inexistente para '{$tabla_cfg}'."];
    } else {
        // Ejecutar el motor de geocodificación
        $resultado = geocode_pending_for_table($config);

        // Marcar como GEOCODIFICADA sin importar resultados parciales.
        // Una tabla geocodificada no vuelve a aparecer en el selector.
        if (!isset($resultado['error'])) {
            db_query(
                "UPDATE geocod.tablas_geo_config
                 SET estado = 'GEOCODIFICADA'
                 WHERE tabla_geo = $1",
                [$tabla_cfg]
            );
        }
    }
}


// ====================================================================
// DATOS PARA LA UI
// Preparar variables que usan las pestañas
// ====================================================================

// Pedidos disponibles para crear tabla geo (sin geo previa)
$tablas_pedido_disponibles = obtener_tablas_pedido_sin_geo();

// Todas las tablas geo con su estado sincronizado
$tablas_geo = obtener_tablas_geo_config();

// Tablas listas para geocodificar (solo ENVIADA)
$tablas_para_geocodificar = array_filter(
    $tablas_geo,
    fn($t) => $t['estado'] === 'ENVIADA'
);

// Tablas ya geocodificadas (para descargas)
$tablas_geocodificadas = array_filter(
    $tablas_geo,
    fn($t) => $t['estado'] === 'GEOCODIFICADA'
);

// Pedidos fuente disponibles para descarga (todos los de geopedidos sin _geo)
$tablas_fuente = [];
$res_fuente = db_query("
    SELECT tablename
    FROM pg_tables
    WHERE schemaname = 'geopedidos'
      AND tablename NOT LIKE '%_geo'
    ORDER BY tablename
");
while ($r = pg_fetch_assoc($res_fuente)) {
    $tablas_fuente[] = $r['tablename'];
}

// Determinar pestaña activa
$tab_activa = 'abm'; // default
if (isset($_GET['geo']))       $tab_activa = 'geo';
elseif (isset($_GET['descargas'])) $tab_activa = 'descargas';
elseif (isset($_GET['guia']))  $tab_activa = 'guia';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>UEICEE · MAPA · GEOCOD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body        { background-color: #f5f6fa; }
        .card-shadow{ box-shadow: 0 4px 12px rgba(0,0,0,0.10); }
        .header-bar { background-color: #273c75; color: #fff; padding: 18px; margin-bottom: 25px; }

        /* Badge de estado en tabla ABM */
        .badge-poblada      { background-color: #f0ad4e; color: #000; }
        .badge-enviada      { background-color: #5bc0de; color: #000; }
        .badge-geocodificada{ background-color: #5cb85c; color: #fff; }
        .badge-borrada      { background-color: #d9534f; color: #fff; }
    </style>
</head>
<body>

<div class="header-bar">
    <h3 class="m-0">UEICEE · MAPA · GEOCOD <small class="fs-6 ms-2 opacity-75">v1.1</small></h3>
</div>

<div class="container">

<!-- ============================================================
     NAVEGACIÓN — 4 pestañas
     Orden: ABM / Geocodificar / Descargas / Guía de Uso
============================================================ -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab_activa === 'abm'       ? 'active' : '' ?>"
           href="index.php">ABM</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab_activa === 'geo'       ? 'active' : '' ?>"
           href="index.php?geo=1">Geocodificar</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab_activa === 'descargas' ? 'active' : '' ?>"
           href="index.php?descargas=1">Descargas</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab_activa === 'guia'      ? 'active' : '' ?>"
           href="index.php?guia=1">Guía de Uso</a>
    </li>
</ul>


<?php
// ================================================================
// PESTAÑA 1 — ABM
// ================================================================
if ($tab_activa === 'abm'):
?>

    <?php // Flash messages del PRG ?>
    <?php if (isset($_SESSION['flash_abm'])): ?>
        <?= $_SESSION['flash_abm']; unset($_SESSION['flash_abm']); ?>
    <?php endif; ?>


    <!-- --------------------------------------------------------
         CARD: SUBIR LISTADOS
         Permite cargar un CSV crudo al esquema geopedidos.
         Columnas mínimas requeridas: id_reg, direccion_raw.
    -------------------------------------------------------- -->
    <div class="card card-shadow p-4 mb-4">
        <h4 class="mb-1">Subir listado</h4>
        <p class="text-muted mb-3">
            Cargá un archivo CSV con las direcciones a geocodificar.
            El archivo debe contener al menos las columnas
            <strong>id_reg</strong> y <strong>direccion_raw</strong>.
            Separador admitido: coma (<code>,</code>) o punto y coma (<code>;</code>).
        </p>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="subir_csv">

            <div class="mb-3">
                <label class="form-label fw-bold">Archivo CSV</label>
                <input type="file"
                       name="archivo_csv"
                       accept=".csv"
                       class="form-control"
                       required>
                <div class="form-text">Solo se aceptan archivos .csv</div>
            </div>

            <button type="submit" class="btn btn-secondary w-100">Subir listado</button>
        </form>
    </div>


    <!-- --------------------------------------------------------
         CARD: CREAR Y POBLAR TABLA GEO
         Solo muestra pedidos que aún no tienen tabla geo creada.
    -------------------------------------------------------- -->
    <div class="card card-shadow p-4 mb-4">
        <h4 class="mb-1">Crear y poblar tabla geo</h4>
        <p class="text-muted mb-3">
            Seleccioná un listado cargado para generar su tabla geo.
            Solo aparecen listados que aún no fueron procesados.
        </p>

        <?php if (empty($tablas_pedido_disponibles)): ?>
            <div class="alert alert-info mb-0">
                No hay listados disponibles. Subí un CSV primero, o todos los listados ya tienen tabla geo creada.
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="accion" value="crear_geo">

                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccionar listado</label>
                    <select name="pedido" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($tablas_pedido_disponibles as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>">
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-success w-100">Crear tabla geo</button>
            </form>
        <?php endif; ?>
    </div>


    <!-- --------------------------------------------------------
         CARD: TABLAS GEO CREADAS
         Lista todas las tablas geo con su estado actual.
         Los estados posibles son:
           POBLADA      → lista para enviar a geocod
           ENVIADA      → en geocod, lista para geocodificar
           GEOCODIFICADA→ ya fue procesada
           BORRADA      → tabla física eliminada
    -------------------------------------------------------- -->
    <div class="card card-shadow p-4">
        <h4 class="mb-3">Tablas geo creadas</h4>

        <?php if (empty($tablas_geo)): ?>
            <div class="alert alert-info mb-0">Aún no hay tablas geo registradas.</div>
        <?php else: ?>
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Pedido</th>
                        <th>Tabla geo</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tablas_geo as $tg): ?>
                    <tr>
                        <td><?= htmlspecialchars($tg['nombre_pedido']) ?></td>
                        <td><code><?= htmlspecialchars($tg['tabla_geo']) ?></code></td>
                        <td><?= htmlspecialchars(substr($tg['fecha_creado'], 0, 16)) ?></td>

                        <td>
                            <?php
                            $estado      = $tg['estado'];
                            $badge_class = match($estado) {
                                'POBLADA'       => 'badge-poblada',
                                'ENVIADA'       => 'badge-enviada',
                                'GEOCODIFICADA' => 'badge-geocodificada',
                                'BORRADA'       => 'badge-borrada',
                                default         => 'bg-secondary text-white'
                            };
                            ?>
                            <span class="badge <?= $badge_class ?> px-2 py-1">
                                <?= htmlspecialchars($estado) ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($tg['estado'] === 'POBLADA'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="accion"    value="enviar_geocod">
                                    <input type="hidden" name="tabla_geo" value="<?= htmlspecialchars($tg['tabla_geo']) ?>">
                                    <input type="hidden" name="pedido"    value="<?= htmlspecialchars($tg['nombre_pedido']) ?>">
                                    <button class="btn btn-primary btn-sm">Enviar a geocod</button>
                                </form>

                            <?php elseif ($tg['estado'] === 'BORRADA'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="accion"   value="borrar_meta">
                                    <input type="hidden" name="id_tabla" value="<?= htmlspecialchars($tg['id_tabla']) ?>">
                                    <button class="btn btn-danger btn-sm">Eliminar metadato</button>
                                </form>

                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>


<?php
// ================================================================
// PESTAÑA 2 — GEOCODIFICAR
// ================================================================
elseif ($tab_activa === 'geo'):
?>

    <div class="card card-shadow p-4 mb-4">
        <h4 class="mb-1">Ejecutar geocodificación</h4>
        <p class="text-muted mb-3">
            Solo se muestran las tablas listas para geocodificar (estado <strong>ENVIADA</strong>).
            Una vez ejecutada, la tabla pasa a estado <strong>GEOCODIFICADA</strong> y no vuelve a aparecer aquí.
        </p>

        <?php if (empty($tablas_para_geocodificar)): ?>
            <div class="alert alert-info mb-0">
                No hay tablas listas para geocodificar.
                Enviá una tabla geo desde la pestaña <strong>ABM</strong>.
            </div>
        <?php else: ?>

            <!-- Alert de proceso — visible solo mientras se ejecuta -->
            <div id="alerta-proceso" class="alert alert-warning d-none">
                <strong>⏳ En proceso.</strong>
                Aguardá mientras se geocodifican los registros. Esto puede tardar varios minutos.
                <br>No cerrés ni recargues esta pestaña.
            </div>

            <!-- Resultado tras el POST -->
            <?php if (isset($resultado)): ?>
                <?php if (isset($resultado['error'])): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($resultado['error']) ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        <strong>✅ Proceso finalizado.</strong><br>
                        Registros procesados: <strong><?= $resultado['total'] ?></strong><br>
                        Con coordenadas WGS84 (OK): <strong><?= $resultado['ok'] ?></strong><br>
                        Sin coordenadas GKBA: <strong><?= $resultado['sin_match'] ?></strong><br>
                        Errores geocoder: <strong><?= $resultado['error_geocoder'] ?></strong><br>
                        Errores WGS84: <strong><?= $resultado['error_wgs84'] ?></strong>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" id="form-geocod">

                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccionar tabla</label>
                    <select name="tabla_cfg" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($tablas_para_geocodificar as $tg): ?>
                            <option value="<?= htmlspecialchars($tg['tabla_geo']) ?>">
                                <?= htmlspecialchars($tg['tabla_geo']) ?>
                                — <?= htmlspecialchars($tg['nombre_pedido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" id="btn-geocod" class="btn btn-primary w-100">
                    Ejecutar geocodificación
                </button>
            </form>

            <script>
            // Al enviar el formulario, mostrar alert de "en proceso"
            // y deshabilitar el botón para evitar doble envío
            document.getElementById('form-geocod').addEventListener('submit', function(e) {
                var select = document.querySelector('select[name="tabla_cfg"]');
                if (!select.value) return; // HTML5 required lo captura antes

                // Mostrar alerta de proceso
                document.getElementById('alerta-proceso').classList.remove('d-none');

                // Deshabilitar botón
                var btn = document.getElementById('btn-geocod');
                btn.disabled    = true;
                btn.textContent = '⏳ Procesando...';
            });
            </script>

        <?php endif; ?>
    </div>


<?php
// ================================================================
// PESTAÑA 3 — DESCARGAS
// ================================================================
elseif ($tab_activa === 'descargas'):
?>

    <!-- --------------------------------------------------------
         CARD: FUENTES
         Descarga los CSV crudos subidos en ABM
    -------------------------------------------------------- -->
    <div class="card card-shadow p-4 mb-4">
        <h4 class="mb-1">Fuentes</h4>
        <p class="text-muted mb-3">
            Listados originales cargados en el sistema. Descargá el CSV crudo tal como fue subido.
        </p>

        <?php if (empty($tablas_fuente)): ?>
            <div class="alert alert-info mb-0">No hay listados fuente cargados.</div>
        <?php else: ?>
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Tabla</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tablas_fuente as $tf): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($tf) ?></code></td>
                        <td>
                            <a href="descargar.php?tipo=fuente&tabla=<?= urlencode($tf) ?>"
                               class="btn btn-outline-secondary btn-sm">
                                ⬇ Descargar Excel
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>


    <!-- --------------------------------------------------------
         CARD: GEOLOCALIZADAS
         Descarga el resultado joineado: fuente + datos geo
    -------------------------------------------------------- -->
    <div class="card card-shadow p-4">
        <h4 class="mb-1">Geolocalizadas</h4>
        <p class="text-muted mb-3">
            Tablas geocodificadas listas para descargar. El CSV incluye todos los campos
            del listado original más los datos de geocodificación (coordenadas, barrio, comuna, etc.).
        </p>

        <?php if (empty($tablas_geocodificadas)): ?>
            <div class="alert alert-info mb-0">
                Aún no hay tablas geocodificadas. Ejecutá el proceso desde la pestaña <strong>Geocodificar</strong>.
            </div>
        <?php else: ?>
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Pedido original</th>
                        <th>Tabla geo</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tablas_geocodificadas as $tg): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($tg['nombre_pedido']) ?></code></td>
                        <td><code><?= htmlspecialchars($tg['tabla_geo']) ?></code></td>
                        <td>
                            <a href="descargar.php?tipo=geolocalizada&tabla=<?= urlencode($tg['tabla_geo']) ?>&pedido=<?= urlencode($tg['nombre_pedido']) ?>"
                               class="btn btn-outline-success btn-sm">
                                ⬇ Descargar Excel
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>


<?php
// ================================================================
// PESTAÑA 4 — GUÍA DE USO
// ================================================================
elseif ($tab_activa === 'guia'):
?>

    <div class="card card-shadow p-4">
        <h3 class="mb-4">Guía de Uso</h3>

        <h5>1. Subir un listado</h5>
        <p>
            En <strong>ABM → Subir listado</strong> cargá un archivo CSV con las direcciones.
            El archivo debe tener al menos las columnas <code>id_reg</code> y <code>direccion_raw</code>.
            El sistema acepta separador por coma o punto y coma.
        </p>
        <pre class="bg-light p-3 border">id_reg,direccion_raw
1,HERNANDEZ 2045
2,COLIBRI 686
3,ALBERTI 162</pre>

        <hr>

        <h5 class="mt-4">2. Crear tabla geo</h5>
        <p>
            En <strong>ABM → Crear tabla geo</strong> seleccioná el listado cargado.
            El sistema parsea cada dirección en <strong>calle</strong> y <strong>altura</strong>,
            y genera la tabla <code>geopedidos.&lt;nombre&gt;_geo</code>.
        </p>

        <hr>

        <h5 class="mt-4">3. Enviar a geocod</h5>
        <p>
            Desde la tabla <strong>Tablas geo creadas</strong> presioná <strong>Enviar a geocod</strong>.
            Esto copia la tabla al esquema <code>geocod</code> y la deja lista para procesar.
        </p>

        <hr>

        <h5 class="mt-4">4. Geocodificar</h5>
        <p>
            En la pestaña <strong>Geocodificar</strong> seleccioná la tabla y presioná
            <strong>Ejecutar geocodificación</strong>. El sistema llama a la API IDECABA en tres pasos:
            geocoder → datos útiles → transformación WGS84.
            Al finalizar muestra un resumen con la cantidad de registros procesados y con coordenadas OK.
        </p>

        <hr>

        <h5 class="mt-4">5. Descargar resultados</h5>
        <p>
            En <strong>Descargas</strong> encontrás dos secciones:
            <strong>Fuentes</strong> (los listados originales) y
            <strong>Geolocalizadas</strong> (el resultado joineado con todos los campos geo).
            Ambas descargan en formato <strong>Excel (.xlsx)</strong> con las coordenadas
            correctamente formateadas.
        </p>

        <hr>

        <h5 class="mt-4">6. Estados del proceso</h5>
        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr><th>Estado</th><th>Significado</th></tr>
            </thead>
            <tbody>
                <tr><td><code>POBLADA</code></td>      <td>Tabla geo creada, lista para enviar a geocod</td></tr>
                <tr><td><code>ENVIADA</code></td>      <td>Copiada a geocod, lista para geocodificar</td></tr>
                <tr><td><code>GEOCODIFICADA</code></td><td>Procesada por el motor — disponible en Descargas</td></tr>
                <tr><td><code>BORRADA</code></td>      <td>La tabla física fue eliminada</td></tr>
            </tbody>
        </table>

        <hr>

        <h5 class="mt-4">7. Tiempos estimados</h5>
        <pre class="bg-light p-3 border">  1.000 direcciones →  ~8 min 30 seg
  5.000 direcciones → ~42 minutos
 10.000 direcciones →  ~1 hora 25 min
 25.000 direcciones →  ~3 horas 30 min
 65.000 direcciones →  ~9 horas 18 min  (caso real medido)
100.000 direcciones → ~14 horas 15 min</pre>
        <p class="text-muted small">
            Tiempos aproximados. Varían según latencia de la API y carga del servidor.
        </p>

        <hr>

        <h5 class="mt-4">Para desarrolladores</h5>
        <p>
            Repositorio: <a href="https://github.com/gitmapa/geocod" target="_blank">github.com/gitmapa/geocod</a>
        </p>
        <p>
            Stack tecnológico: PHP 8.x nativo · PostgreSQL · API IDECABA (datos abiertos GCBA) · Bootstrap 5
        </p>
        <p>
            Para instrucciones de instalación, estructura del proyecto y scripts SQL
            consultá el <code>README.md</code> en el repositorio.
        </p>

    </div>

<?php endif; ?>

</div><!-- /container -->
</body>
</html>
