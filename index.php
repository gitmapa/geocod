<?php
/**********************************************************************
 * Sistema de Geocodificación IDECABA v2.0.3
 * ---------------------------------------------------------------
 * Este index implementa:
 *  - PRG (POST → REDIRECT → GET)
 *  - Pestañas: Geocodificar / ABM / Guía de Uso
 *  - Card dinámicas
 *  - Manejo de tablas de pedidos y tablas_geo
 *  - Actualización automática de estados
 **********************************************************************/

session_start();

require_once __DIR__ . '/lib/tables_config.php';
require_once __DIR__ . '/lib/geocoder_engine.php';
require_once __DIR__ . '/lib/report_utils.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/parser_direcciones.php';


// ====================================================================
// UTILIDADES
// ====================================================================

// Listar tablas sucias (sin _geo)
function obtener_tablas_pedido() {
    $sql = "
        SELECT tablename
        FROM pg_tables
        WHERE schemaname = 'geopedidos'
          AND tablename NOT LIKE '%_geo'
        ORDER BY tablename
    ";
    $res = db_query($sql);
    $out = [];
    while ($r = pg_fetch_assoc($res)) $out[] = $r['tablename'];
    return $out;
}


// Listar tablas_geo + sincronizar estado real
function obtener_tablas_geo_config() {

    $sql = "
        SELECT id_tabla, nombre_pedido, tabla_geo, fecha_creado, estado
        FROM geocod.tablas_geo_config
        ORDER BY fecha_creado DESC
    ";
    $res = db_query($sql);

    $out = [];

    while ($r = pg_fetch_assoc($res)) {

        $tg   = $r['tabla_geo'];
        $id   = $r['id_tabla'];

        $ex1 = tabla_existe('geopedidos', $tg);
        $ex2 = tabla_existe('geocod', $tg);

        // Caso: NO existe en ningún lado → BORRADA
        if (!$ex1 && !$ex2 && $r['estado'] !== 'BORRADA') {
            db_query("UPDATE geocod.tablas_geo_config SET estado = 'BORRADA' WHERE id_tabla = $1", [$id]);
            $r['estado'] = 'BORRADA';
        }

        // Caso: existe en geocod → ENVIADA
        if ($ex2 && $r['estado'] === 'POBLADA') {
            db_query("UPDATE geocod.tablas_geo_config SET estado = 'ENVIADA' WHERE id_tabla = $1", [$id]);
            $r['estado'] = 'ENVIADA';
        }

        // Caso: existe en geopedidos pero no en geocod → POBLADA
        if ($ex1 && !$ex2 && $r['estado'] === 'ENVIADA') {
            db_query("UPDATE geocod.tablas_geo_config SET estado = 'POBLADA' WHERE id_tabla = $1", [$id]);
            $r['estado'] = 'POBLADA';
        }

        $out[] = $r;
    }

    return $out;
}



// ====================================================================
// PROCESAMIENTO FORMULARIOS — PRG
// ====================================================================

// --------------------------------------------------------------
// 1) CREAR + POBLAR TABLA GEO
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'crear_geo') {

    $pedido = trim($_POST['pedido']);

    if ($pedido === '') {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Debe seleccionar un pedido.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    if (!in_array($pedido, obtener_tablas_pedido())) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>La tabla no existe.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    $tabla_geo = $pedido . "_geo";

    // Crear tabla_geo desde plantilla
    if (!db_query("CREATE TABLE geopedidos.$tabla_geo (LIKE geocod.tabla_geo_plantilla INCLUDING ALL)")) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo crear la tabla GEO.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Poblar tabla_geo
    $res_sel = db_query("SELECT id_reg, direccion_raw FROM geopedidos.$pedido ORDER BY id_reg");

    $insertados = 0;
    $sin_altura = 0;

    while ($row = pg_fetch_assoc($res_sel)) {

        $id_reg  = $row['id_reg'];
        $raw     = $row['direccion_raw'];
        $parsed  = parse_direccion_raw($raw);

        if ($parsed['altura'] === null) $sin_altura++;

        db_query("
            INSERT INTO geopedidos.$tabla_geo (idgeo, pedido, calle_original, altura_original)
            VALUES ($1, $2, $3, $4)
        ", [
            $id_reg,
            $pedido,
            $parsed['calle'],
            $parsed['altura']
        ]);

        $insertados++;
    }

    // Registrar metadata
    db_query("
        INSERT INTO geocod.tablas_geo_config (nombre_pedido, tabla_geo, estado)
        VALUES ($1, $2, 'POBLADA')
    ", [$pedido, $tabla_geo]);

    $_SESSION['flash_abm'] = "
        <div class='alert alert-success'>
            Tabla <strong>$tabla_geo</strong> creada exitosamente.<br>
            Filas insertadas: $insertados<br>
            Sin altura detectada: $sin_altura
        </div>
    ";

    header("Location: index.php?abm=1");
    exit;
}



// --------------------------------------------------------------
// 2) ENVIAR TABLA GEO A GEOCOD
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'enviar_geocod') {

    $tabla_geo = $_POST['tabla_geo'];
    $pedido    = $_POST['pedido'];

    $src = "geopedidos.$tabla_geo";
    $dst = "geocod.$tabla_geo";

    if (!db_query("CREATE TABLE $dst AS SELECT * FROM $src")) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Error copiando a geocod.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    db_query("
        INSERT INTO geocod.tablas_config (nombre_config, esquema, tabla, descripcion)
        VALUES ($1, 'geocod', $2, $3)
    ", [$tabla_geo, $tabla_geo, "Pedido $pedido"]);

    db_query("
        UPDATE geocod.tablas_geo_config
        SET estado = 'ENVIADA'
        WHERE tabla_geo = $1
    ", [$tabla_geo]);

    $_SESSION['flash_abm'] = "<div class='alert alert-success'>Tabla enviada a geocod.</div>";

    header("Location: index.php?abm=1");
    exit;
}



// --------------------------------------------------------------
// 3) ELIMINAR METADATO
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'borrar_meta') {

    db_query("DELETE FROM geocod.tablas_geo_config WHERE id_tabla = $1", [$_POST['id_tabla']]);
    $_SESSION['flash_abm'] = "<div class='alert alert-info'>Metadato eliminado.</div>";

    header("Location: index.php?abm=1");
    exit;
}



// --------------------------------------------------------------
// 4) EJECUTAR GEOLOCALIZACIÓN
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['tabla_cfg'])
    && !isset($_POST['accion'])) {

    $config = get_table_config($_POST['tabla_cfg']);

    $resultado = $config
        ? geocode_pending_for_table($config)
        : ['error' => "Configuración inexistente para '{$_POST['tabla_cfg']}'"];
}



// ====================================================================
// DATOS PARA UI
// ====================================================================
$tablas_pedido = obtener_tablas_pedido();
$tablas_geo    = obtener_tablas_geo_config();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>UEICEE : GEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background-color: #f5f6fa; }
        .card-shadow { box-shadow: 0 4px 12px rgba(0,0,0,0.10); }
        .header-bar { background-color: #273c75; color: #fff; padding: 18px; margin-bottom: 25px; }
    </style>
</head>

<body>

<div class="header-bar">
    <h3 class="m-0">Sistema de Geocodificación IDECABA v2.0.3</h3>
</div>

<div class="container">

<!-- ------------------------------------------------------------
     NAVEGACIÓN (3 pestañas)
------------------------------------------------------------ -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php if (!isset($_GET['abm']) && !isset($_GET['guia'])) echo 'active'; ?>"
           href="index.php">Geocodificar</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php if (isset($_GET['abm'])) echo 'active'; ?>"
           href="index.php?abm=1">ABM</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php if (isset($_GET['guia'])) echo 'active'; ?>"
           href="index.php?guia=1">Guía de Uso</a>
    </li>
</ul>


<?php
/******************************************************************
 * PESTAÑA 1 — GEOLOCALIZAR
 ******************************************************************/
if (!isset($_GET['abm']) && !isset($_GET['guia'])):
?>
    <div class="card card-shadow p-4 mb-4">
        <h4 class="mb-3">Ejecutar geocodificación</h4>

        <form method="POST">

            <div class="mb-3">
                <label class="form-label fw-bold">Seleccione una tabla</label>
                <select name="tabla_cfg" class="form-select" required>
                    <option value="">Seleccione...</option>

                    <?php
                    $res_cfg = db_query("SELECT nombre_config, descripcion FROM geocod.tablas_config ORDER BY nombre_config");
                    while ($t = pg_fetch_assoc($res_cfg)):
                    ?>
                        <option value="<?= htmlspecialchars($t['nombre_config']) ?>">
                            <?= htmlspecialchars($t['nombre_config']) ?>
                            <?= $t['descripcion'] ? ' — '.$t['descripcion'] : '' ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary w-100">Ejecutar geocodificación</button>
        </form>
    </div>

    <?php if (isset($resultado)): ?>
        <div class="card card-shadow p-4">
            <h4 class="mb-3">Resultado</h4>
            <a href="index.php" class="btn btn-secondary mb-3">Nueva ejecución</a>

            <?php
            if (isset($resultado['error'])) {
                echo "<div class='alert alert-danger'>{$resultado['error']}</div>";
            } else {
                print_geocode_report($resultado);
            }
            ?>
        </div>
    <?php endif; ?>

<?php
/******************************************************************
 * PESTAÑA 2 — ABM
 ******************************************************************/
elseif (isset($_GET['abm'])):
?>
    <div class="card card-shadow p-4 mb-4">
        <h4 class="mb-3">Crear y poblar tabla geo</h4>

        <?php if (isset($_SESSION['flash_abm'])): ?>
            <?= $_SESSION['flash_abm']; unset($_SESSION['flash_abm']); ?>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="accion" value="crear_geo">

            <div class="mb-3">
                <label class="form-label fw-bold">Seleccione tabla original (geopedidos)</label>
                <select name="pedido" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php foreach ($tablas_pedido as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn btn-success w-100">Crear tabla geo</button>
        </form>
    </div>


    <div class="card card-shadow p-4">
        <h4 class="mb-3">Tablas geo creadas</h4>

        <table class="table table-bordered table-striped">
            <thead>
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
                    <td><?= htmlspecialchars($tg['tabla_geo']) ?></td>
                    <td><?= htmlspecialchars($tg['fecha_creado']) ?></td>

                    <td>
                        <?php if ($tg['estado'] === 'BORRADA'): ?>
                            <span class="text-danger fw-bold">BORRADA</span>
                        <?php else: ?>
                            <?= htmlspecialchars($tg['estado']) ?>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ($tg['estado'] === 'POBLADA'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="accion" value="enviar_geocod">
                                <input type="hidden" name="tabla_geo" value="<?= htmlspecialchars($tg['tabla_geo']) ?>">
                                <input type="hidden" name="pedido" value="<?= htmlspecialchars($tg['nombre_pedido']) ?>">
                                <button class="btn btn-primary btn-sm">Enviar a geocod</button>
                            </form>

                        <?php elseif ($tg['estado'] === 'BORRADA'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="accion" value="borrar_meta">
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

    </div>

<?php
/******************************************************************
 * PESTAÑA 3 — GUÍA DE USO
 ******************************************************************/
elseif (isset($_GET['guia'])):
?>
    <div class="card card-shadow p-4">

        <h3 class="mb-4">Guía Básica de Uso del Sistema</h3>

        <h5 class="mt-3">1. Requisitos del pedido</h5>
        <p>
            La tabla del pedido debe estar en el esquema <strong>geopedidos</strong> y contener al menos:
        </p>

        <ul>
            <li><strong>id_reg</strong>: identificador único por fila.</li>
            <li><strong>direccion_raw</strong>: dirección original sin normalizar (texto libre).</li>
        </ul>

        <p>Ejemplo mínimo:</p>

        <pre class="bg-light p-3 border">
id_reg | direccion_raw              | ...otros campos...
--------------------------------------------------------
1      | HERNANDEZ 2045
2      | COLIBRI 686
3      | ALBERTI 162
        </pre>

        <hr>

        <h5 class="mt-4">2. Crear Tabla GEO</h5>
        <p>
            En la pestaña <strong>ABM</strong> seleccione la tabla sucia del pedido
            y presione <strong>“Crear tabla geo”</strong>.
        </p>

        <p>El sistema:</p>
        <ul>
            <li>Genera <code>&lt;pedido&gt;_geo</code>.</li>
            <li>Parsea la dirección en <strong>calle</strong> y <strong>altura</strong>.</li>
            <li>Usa <strong>id_reg</strong> como <strong>idgeo</strong>.</li>
            <li>Registra el pedido en metadatos internos.</li>
        </ul>

        <hr>

        <h5 class="mt-4">3. Enviar tabla GEO a geocod</h5>
        <p>
            Desde ABM, en la card de tablas creadas, presione
            <strong>“Enviar a geocod”</strong>.
        </p>

        <p>Esto:</p>
        <ul>
            <li>Copia la tabla a <code>geocod.&lt;pedido&gt;_geo</code>.</li>
            <li>La registra para poder ser geocodificada.</li>
        </ul>

        <hr>

        <h5 class="mt-4">4. Ejecutar geocodificación</h5>

        <ul>
            <li>Ir a <strong>Geocodificar</strong>.</li>
            <li>Seleccionar la tabla.</li>
            <li>Presionar <strong>“Ejecutar geocodificación”</strong>.</li>
        </ul>

        <p>El sistema ejecuta automáticamente la API IDECABA:</p>

        <ul>
            <li>Geocoder (normaliza dirección y da coordenadas GKBA).</li>
            <li>Datos útiles (comuna, barrio, CP, etc.).</li>
            <li>Transformación WGS84.</li>
            <li>Actualiza <strong>estado_proceso</strong> y <strong>fecha_procesado</strong>.</li>
        </ul>

        <hr>

        <h5 class="mt-4">5. Resultado final</h5>
        <p>
            Los resultados se encuentran en <strong>geocod.&lt;pedido&gt;_geo</strong>.
            Puede unirlos con la tabla original mediante <strong>id_reg = idgeo</strong>.
        </p>

        <pre class="bg-light p-3 border">
SELECT *
FROM geopedidos.&lt;pedido&gt; p
JOIN geocod.&lt;pedido&gt;_geo g
  ON p.id_reg = g.idgeo;
        </pre>

        <p>
            Luego puede exportar, integrarlo a sistemas externos o usarlo en mapas.
        </p>

        <hr>

        <!-- ============================================================
             RENDIMIENTO Y TIEMPOS ESTIMADOS
        ============================================================= -->
        <h5 class="mt-4">6. Rendimiento estimado (aproximado)</h5>

        <p>
            El rendimiento real depende de la latencia hacia la API IDECABA,
            de la carga del servidor y del volumen del pedido.
            Con mediciones reales del sistema, se observó:
        </p>

        <ul>
            <li><strong>Velocidad promedio:</strong> ~1.94 direcciones por segundo</li>
            <li><strong>Tiempo por dirección:</strong> ~0.51 segundos</li>
            <li><strong>Tiempo por 1.000 direcciones:</strong> ~8.5 minutos</li>
        </ul>

        <p>Estimación para pedidos de distintos tamaños:</p>

        <pre class="bg-light p-3 border">
  1.000 direcciones → ~8 minutos 30 segundos
  5.000 direcciones → ~42 minutos
 10.000 direcciones → ~1 hora 25 minutos
 25.000 direcciones → ~3 horas 30 minutos
 65.000 direcciones → ~9 horas 18 minutos     (caso real medido)
100.000 direcciones → ~14 horas 15 minutos
        </pre>

        <p class="text-muted">
            Los tiempos son aproximados y sirven como referencia para planificación.
            Pueden variar dependiendo del tráfico hacia la API y el hardware utilizado.
        </p>

    </div>

<?php endif; ?>



</div> <!-- container -->

</body>
</html>
