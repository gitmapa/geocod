<?php
// =============================================================
// index.php
// Interfaz principal del sistema UEICEE - MAPA - GEOCOD
// -------------------------------------------------------------
// Propósito : Punto de entrada único de la aplicación.
//             Concentra en un solo archivo:
//               - Funciones auxiliares de consulta a la DB
//               - Procesamiento de todos los formularios POST
//                 usando el patrón PRG (Post-Redirect-Get)
//               - Preparación de variables para la UI
//               - Renderizado HTML de las 4 pestañas
//
//             Patrón PRG:
//               Cada acción POST termina con header(Location) + exit.
//               Esto evita que al recargar la página el navegador
//               reenvíe el formulario y ejecute la acción dos veces.
//               Los mensajes de resultado se pasan via $_SESSION
//               (flash messages) que se muestran una sola vez.
//
//             Pestañas de la UI:
//               1. ABM          — gestión de pedidos y tablas geo
//               2. Geocodificar — ejecución del motor
//               3. Descargas    — exportación en Excel (.xlsx)
//               4. Guía de Uso  — documentación inline
//
//             Acciones POST disponibles:
//               subir_csv      — carga un CSV al esquema geopedidos
//               crear_geo      — crea y puebla la tabla _geo
//               enviar_geocod  — copia la tabla _geo a geocod
//               borrar_meta    — elimina el metadato de una tabla BORRADA
//               (sin accion)   — ejecuta el motor de geocodificación
// -------------------------------------------------------------
// Dependencias:
//   - lib/tables_config.php    (get_table_config)
//   - lib/geocoder_engine.php  (geocode_pending_for_table)
//   - lib/db.php               (db_query, tabla_existe)
//   - lib/parser_direcciones.php (parse_direccion_raw)
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================

// Iniciar sesión para poder usar flash messages entre redirects
session_start();

require_once __DIR__ . '/lib/tables_config.php';
require_once __DIR__ . '/lib/geocoder_engine.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/parser_direcciones.php';


// ====================================================================
// FUNCIONES AUXILIARES DE CONSULTA
// Estas funciones se usan para preparar los datos de la UI.
// Se definen aquí (y no en lib/) porque son específicas de la
// lógica de presentación de este archivo.
// ====================================================================

/**
 * obtener_tablas_pedido_sin_geo()
 * -------------------------------------------------------------
 * Devuelve los nombres de las tablas del esquema geopedidos
 * que todavía NO tienen una tabla geo asociada.
 *
 * Usa NOT EXISTS contra geocod.tablas_geo_config para detectar
 * pedidos sin procesar. Solo muestra tablas que no terminan
 * en '_geo' (excluye las tablas geo ya creadas).
 *
 * Se usa para poblar el selector de "Crear tabla geo" en ABM,
 * garantizando que no se pueda crear una tabla geo duplicada.
 *
 * @return array  Lista de strings con los nombres de tabla.
 *                Array vacío si no hay pedidos disponibles.
 */
function obtener_tablas_pedido_sin_geo()
{
    // NOT EXISTS descarta pedidos que ya tienen registro en tablas_geo_config,
    // independientemente del estado actual (POBLADA, ENVIADA, GEOCODIFICADA, etc.)
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

    if (!$res) {
        return $out;
    }

    while ($r = pg_fetch_assoc($res)) {
        $out[] = $r['tablename'];
    }

    return $out;
}

/**
 * obtener_tablas_geo_config()
 * -------------------------------------------------------------
 * Devuelve todos los registros de geocod.tablas_geo_config con
 * su estado sincronizado contra la existencia real de las tablas
 * en la base de datos.
 *
 * La sincronización automática detecta y corrige inconsistencias
 * que pueden ocurrir cuando alguien elimina tablas manualmente
 * desde pgAdmin sin actualizar los metadatos del sistema.
 *
 * Reglas de sincronización (se evalúan en orden):
 *   1. Si no existe ni en geopedidos ni en geocod
 *      → estado pasa a BORRADA
 *   2. Si existe en geocod pero el estado dice POBLADA
 *      → estado pasa a ENVIADA (la tabla ya fue copiada)
 *   3. Si existe en geopedidos pero NO en geocod y decía ENVIADA
 *      → estado vuelve a POBLADA (la tabla en geocod fue eliminada)
 *   4. GEOCODIFICADA → nunca se modifica (es el estado terminal)
 *
 * @return array  Lista de arrays asociativos con las claves:
 *                  'id_tabla'      (string) — PK del registro
 *                  'nombre_pedido' (string) — nombre de la tabla fuente
 *                  'tabla_geo'     (string) — nombre de la tabla _geo
 *                  'fecha_creado'  (string) — timestamp de creación
 *                  'estado'        (string) — estado sincronizado
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

    if (!$res) {
        return $out;
    }

    while ($r = pg_fetch_assoc($res)) {

        $tg = $r['tabla_geo'];
        $id = $r['id_tabla'];

        // Verificar existencia física de la tabla en ambos esquemas
        $en_geopedidos = tabla_existe('geopedidos', $tg);
        $en_geocod     = tabla_existe('geocod',     $tg);

        // Regla 1: no existe en ningún lado → BORRADA
        if (!$en_geopedidos && !$en_geocod && $r['estado'] !== 'BORRADA') {
            db_query(
                "UPDATE geocod.tablas_geo_config SET estado = 'BORRADA' WHERE id_tabla = $1",
                [$id]
            );
            $r['estado'] = 'BORRADA';
        }

        // Regla 2: existe en geocod pero aún dice POBLADA → ENVIADA
        // Puede pasar si el sistema se interrumpió justo después de
        // copiar la tabla pero antes de actualizar el estado.
        if ($en_geocod && $r['estado'] === 'POBLADA') {
            db_query(
                "UPDATE geocod.tablas_geo_config SET estado = 'ENVIADA' WHERE id_tabla = $1",
                [$id]
            );
            $r['estado'] = 'ENVIADA';
        }

        // Regla 3: existe en geopedidos pero no en geocod y decía ENVIADA
        // → la tabla en geocod fue eliminada manualmente → volver a POBLADA
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
 * Convierte el nombre de un archivo CSV subido en un nombre de
 * tabla PostgreSQL válido y único dentro del esquema geopedidos.
 *
 * Transformaciones aplicadas en orden:
 *   1. Eliminar la extensión (.csv)
 *   2. Reemplazar caracteres no alfanuméricos por guión bajo
 *   3. Convertir a minúsculas
 *   4. Truncar a 12 caracteres máximo
 *      (deja espacio para el sufijo _NN dentro del límite de 63
 *      caracteres que impone PostgreSQL para nombres de objetos)
 *   5. Eliminar guiones bajos finales que hayan quedado del truncado
 *   6. Agregar sufijo _01, _02, etc. hasta encontrar un nombre libre
 *
 * Ejemplos:
 *   "listado escuelas.csv" → "listado_escu_01"
 *   "datos-2024.csv"       → "datos_2024_01"
 *   "abc.csv" (ya existe)  → "abc_02"
 *
 * @param  string $nombre_archivo  Nombre original del archivo subido.
 *
 * @return string  Nombre de tabla válido, en minúsculas, único en geopedidos.
 */
function generar_nombre_tabla(string $nombre_archivo): string
{
    // Paso 1: quitar la extensión del archivo
    $base = pathinfo($nombre_archivo, PATHINFO_FILENAME);

    // Paso 2 y 3: reemplazar no-alfanuméricos por _ y pasar a minúsculas
    $base = preg_replace('/[^a-zA-Z0-9]+/', '_', $base);
    $base = strtolower($base);

    // Paso 4: truncar a 12 caracteres para dejar margen al sufijo _NN
    $base = substr($base, 0, 12);

    // Paso 5: limpiar guiones bajos finales que pueden quedar del truncado
    $base = rtrim($base, '_');

    // Paso 6: agregar sufijo numérico hasta encontrar nombre libre
    $sufijo = 1;
    $nombre = $base . '_' . str_pad($sufijo, 2, '0', STR_PAD_LEFT);

    while (tabla_existe('geopedidos', $nombre)) {
        $sufijo++;
        $nombre = $base . '_' . str_pad($sufijo, 2, '0', STR_PAD_LEFT);
    }

    return $nombre;
}


// ====================================================================
// PROCESAMIENTO DE FORMULARIOS — PATRÓN PRG
// ---------------------------------------------------------------------
// Cada bloque if detecta una acción POST específica, la procesa,
// guarda el resultado en $_SESSION['flash_abm'] y redirige.
// El HTML nunca se renderiza en el mismo request que el POST.
// ====================================================================

// --------------------------------------------------------------
// ACCIÓN: subir_csv
// -------------------------------------------------------------
// Recibe un archivo CSV subido por el usuario y lo importa al
// esquema geopedidos creando una tabla nueva con todas las
// columnas del CSV como tipo TEXT.
//
// Validaciones:
//   - Que el upload no tuvo errores de PHP
//   - Que la extensión sea .csv
//   - Que el archivo tenga las columnas id_reg y direccion_raw
//
// El separador (coma o punto y coma) se detecta automáticamente
// comparando la cantidad de ocurrencias en la primera línea.
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'subir_csv') {

    // Verificar que PHP recibió el archivo sin errores de upload
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Error al subir el archivo. Intentá de nuevo.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    $archivo  = $_FILES['archivo_csv'];
    $tmp_path = $archivo['tmp_name'];  // Ruta temporal donde PHP guardó el archivo
    $nombre   = $archivo['name'];      // Nombre original del archivo en la PC del usuario

    // Validar que la extensión sea .csv (case-insensitive)
    if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'csv') {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Solo se permiten archivos CSV.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Abrir el archivo para leer encabezados y filas
    $handle = fopen($tmp_path, 'r');
    if (!$handle) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo leer el archivo CSV.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Leer la primera línea raw para detectar el separador,
    // luego rebobinar para que fgetcsv la procese correctamente
    $primera_linea = fgets($handle);
    rewind($handle);

    // Detectar separador: el que aparece más veces en la primera línea
    $separador = (substr_count($primera_linea, ';') > substr_count($primera_linea, ',')) ? ';' : ',';

    // Leer la fila de encabezados con el separador detectado
    $encabezados = fgetcsv($handle, 0, $separador);

    if (!$encabezados) {
        fclose($handle);
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudieron leer los encabezados del CSV.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Normalizar encabezados: quitar espacios y pasar a minúsculas
    // para comparaciones consistentes y nombres de columna válidos
    $encabezados = array_map(fn($h) => strtolower(trim($h)), $encabezados);

    // Verificar que estén las columnas mínimas requeridas por el sistema
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

    // Generar un nombre de tabla único a partir del nombre del archivo
    $nombre_tabla = generar_nombre_tabla($nombre);

    // Crear la tabla en geopedidos con todas las columnas del CSV como TEXT.
    // Todas TEXT garantiza compatibilidad con cualquier contenido del CSV
    // sin riesgo de errores de conversión de tipo.
    $cols_sql  = implode(', ', array_map(fn($h) => '"' . $h . '" TEXT', $encabezados));
    $create_ok = db_query("CREATE TABLE geopedidos.{$nombre_tabla} ({$cols_sql})");

    if (!$create_ok) {
        fclose($handle);
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo crear la tabla en la base de datos.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Preparar el INSERT parametrizado una sola vez fuera del bucle.
    // Los placeholders $1, $2, etc. se generan dinámicamente según
    // la cantidad de columnas del CSV.
    $insertados   = 0;
    $errores      = 0;
    $n_cols       = count($encabezados);
    $placeholders = implode(', ', array_map(fn($i) => '$' . ($i + 1), range(0, $n_cols - 1)));
    $cols_lista   = implode(', ', array_map(fn($h) => '"' . $h . '"', $encabezados));

    // Insertar cada fila del CSV en la tabla recién creada
    while (($fila = fgetcsv($handle, 0, $separador)) !== false) {

        // Ignorar filas completamente vacías (líneas en blanco al final)
        if (count(array_filter($fila, fn($v) => trim($v) !== '')) === 0) {
            continue;
        }

        // Normalizar la cantidad de valores para que coincida con las columnas.
        // array_pad agrega nulls si faltan columnas; array_slice recorta si sobran.
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
// -------------------------------------------------------------
// Crea la tabla _geo en geopedidos a partir de una tabla pedido
// existente. La tabla _geo se crea como copia de la plantilla
// geocod.tabla_geo_plantilla usando LIKE INCLUDING ALL.
//
// Luego parsea cada dirección_raw del pedido en calle_original
// y altura_original, e inserta una fila en la tabla _geo por
// cada fila del pedido.
//
// Al finalizar registra el metadato en geocod.tablas_geo_config
// con estado POBLADA.
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

    // Verificar que la tabla pedido existe en la base
    // (podría haber sido eliminada entre que se cargó la UI y el submit)
    if (!tabla_existe('geopedidos', $pedido)) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>La tabla seleccionada no existe.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    $tabla_geo = $pedido . '_geo';

    // Crear la tabla _geo copiando estructura completa de la plantilla.
    // LIKE INCLUDING ALL copia columnas, tipos, defaults, constraints e índices.
    $ok = db_query(
        "CREATE TABLE geopedidos.{$tabla_geo} (LIKE geocod.tabla_geo_plantilla INCLUDING ALL)"
    );

    if (!$ok) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>No se pudo crear la tabla GEO.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Leer todas las direcciones del pedido y parsearlas
    $res_sel    = db_query("SELECT id_reg, direccion_raw FROM geopedidos.{$pedido} ORDER BY id_reg");
    $insertados = 0;
    $sin_altura = 0;

    if ($res_sel) {
        while ($row = pg_fetch_assoc($res_sel)) {

            // Separar la dirección cruda en calle y altura
            $parsed = parse_direccion_raw($row['direccion_raw']);

            // Contabilizar filas sin altura para el resumen informativo
            if ($parsed['altura'] === null) {
                $sin_altura++;
            }

            // idgeo toma el valor de id_reg para mantener la trazabilidad
            // con el pedido original — el JOIN en descargar.php se basa en esto
            db_query(
                "INSERT INTO geopedidos.{$tabla_geo} (idgeo, pedido, calle_original, altura_original)
                 VALUES ($1, $2, $3, $4)",
                [$row['id_reg'], $pedido, $parsed['calle'], $parsed['altura']]
            );

            $insertados++;
        }
    }

    // Registrar el metadato en tablas_geo_config con estado inicial POBLADA
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
// -------------------------------------------------------------
// Copia la tabla _geo del esquema geopedidos al esquema geocod
// para que el motor de geocodificación pueda procesarla.
//
// También registra la tabla en geocod.tablas_config, que es la
// tabla que lee el motor para saber qué tablas están disponibles.
//
// El motivo de tener la tabla en dos esquemas es mantener una
// copia de trabajo en geopedidos (con los datos originales) y
// la copia activa en geocod (donde escribe el motor).
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && $_POST['accion'] === 'enviar_geocod') {

    $tabla_geo = $_POST['tabla_geo'] ?? '';
    $pedido    = $_POST['pedido']    ?? '';

    // Validar que el nombre solo tenga caracteres seguros antes
    // de interpolarlo en el SQL (los nombres de tabla no se pueden parametrizar)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla_geo)) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Nombre de tabla inválido.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Copiar la tabla completa de geopedidos a geocod
    $ok = db_query("CREATE TABLE geocod.{$tabla_geo} AS SELECT * FROM geopedidos.{$tabla_geo}");

    if (!$ok) {
        $_SESSION['flash_abm'] = "<div class='alert alert-danger'>Error al copiar la tabla a geocod.</div>";
        header("Location: index.php?abm=1");
        exit;
    }

    // Registrar en geocod.tablas_config para que sea visible en el motor.
    // El nombre_config coincide con el nombre de la tabla para simplificar
    // la relación entre las dos tablas de configuración.
    db_query(
        "INSERT INTO geocod.tablas_config (nombre_config, esquema, tabla, descripcion)
         VALUES ($1, 'geocod', $2, $3)",
        [$tabla_geo, $tabla_geo, "Pedido {$pedido}"]
    );

    // Actualizar el estado en el tracking de ciclo de vida
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
// -------------------------------------------------------------
// Elimina el registro de metadatos en geocod.tablas_geo_config
// para una tabla cuyo estado es BORRADA (la tabla física ya no
// existe en ningún esquema).
//
// Solo limpia el metadato — no intenta tocar ninguna tabla física
// porque ya no existen en este punto.
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
// -------------------------------------------------------------
// Esta acción NO usa el campo 'accion' oculto — se distingue
// por la presencia de 'tabla_cfg' en el POST sin 'accion'.
// Esto permite diferenciarla de las acciones de ABM.
//
// Llama al motor de geocodificación y, si termina sin error
// fatal, marca la tabla como GEOCODIFICADA en tablas_geo_config
// independientemente de cuántas filas individuales hayan fallado.
// Una tabla GEOCODIFICADA no vuelve a aparecer en el selector.
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['tabla_cfg'])
    && !isset($_POST['accion'])) {

    $tabla_cfg = $_POST['tabla_cfg'];
    $config    = get_table_config($tabla_cfg);

    if (!$config) {
        // La tabla fue enviada pero no tiene configuración en tablas_config
        $resultado = ['error' => "Configuración inexistente para '{$tabla_cfg}'."];
    } else {

        // Ejecutar el motor — puede tardar minutos u horas según el volumen
        $resultado = geocode_pending_for_table($config);

        // Si no hubo error fatal, marcar como GEOCODIFICADA.
        // Los errores individuales de filas no impiden este cambio de estado:
        // la tabla puede tener filas con ERROR_API_GEOCODER y aun así pasar a
        // GEOCODIFICADA, ya que esas filas quedan disponibles para reprocesar
        // en una futura ejecución manual si se reactivan.
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
// ---------------------------------------------------------------------
// Se preparan aquí, después de todos los POST, para que los estados
// reflejen cualquier cambio que hayan hecho las acciones de arriba.
// ====================================================================

// Pedidos sin tabla geo (para el selector de "Crear tabla geo")
$tablas_pedido_disponibles = obtener_tablas_pedido_sin_geo();

// Todas las tablas geo con su estado sincronizado (para la tabla ABM)
$tablas_geo = obtener_tablas_geo_config();

// Filtro: solo las ENVIADAS aparecen en el selector de Geocodificar
$tablas_para_geocodificar = array_filter(
    $tablas_geo,
    fn($t) => $t['estado'] === 'ENVIADA'
);

// Filtro: solo las GEOCODIFICADAS aparecen en la sección Descargas
$tablas_geocodificadas = array_filter(
    $tablas_geo,
    fn($t) => $t['estado'] === 'GEOCODIFICADA'
);

// Tablas fuente para descarga: todas las de geopedidos que no son _geo
$tablas_fuente = [];
$res_fuente    = db_query("
    SELECT tablename
    FROM pg_tables
    WHERE schemaname = 'geopedidos'
      AND tablename NOT LIKE '%_geo'
    ORDER BY tablename
");
if ($res_fuente) {
    while ($r = pg_fetch_assoc($res_fuente)) {
        $tablas_fuente[] = $r['tablename'];
    }
}

// Determinar pestaña activa según parámetro GET.
// El default es 'abm'. La pestaña Geocodificar puede llegar aquí
// con $resultado ya cargado si el POST de geocodificación acaba
// de ejecutarse (no hace redirect al terminar — muestra el resumen).
$tab_activa = 'abm';
if (isset($_GET['geo']))           $tab_activa = 'geo';
elseif (isset($_GET['descargas'])) $tab_activa = 'descargas';
elseif (isset($_GET['guia']))      $tab_activa = 'guia';

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

        /* Colores de badge para cada estado del ciclo de vida */
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
     La pestaña activa se determina por el parámetro GET.
     ABM es la pestaña default (sin parámetro).
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
// Gestión completa del ciclo de vida de pedidos y tablas geo.
// Contiene tres cards:
//   1. Subir listado     — upload de CSV
//   2. Crear tabla geo   — parseo y creación de tabla _geo
//   3. Tablas geo creadas — listado con estados y acciones
// ================================================================
if ($tab_activa === 'abm'):
?>

    <?php
    // Mostrar y consumir el flash message del PRG.
    // El mensaje se guarda en POST y se muestra en el GET siguiente.
    // unset() lo elimina para que no se muestre al recargar.
    ?>
    <?php if (isset($_SESSION['flash_abm'])): ?>
        <?= $_SESSION['flash_abm']; unset($_SESSION['flash_abm']); ?>
    <?php endif; ?>


    <!-- --------------------------------------------------------
         CARD 1: SUBIR LISTADOS
         Upload de archivos CSV al esquema geopedidos.
         Columnas mínimas requeridas: id_reg, direccion_raw.
         El nombre de la tabla se genera automáticamente desde
         el nombre del archivo.
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
         CARD 2: CREAR Y POBLAR TABLA GEO
         Solo muestra pedidos que aún no tienen tabla geo creada.
         El selector se filtra con obtener_tablas_pedido_sin_geo().
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
         CARD 3: TABLAS GEO CREADAS
         Lista completa de tablas geo con estado sincronizado.
         Los estados posibles son:
           POBLADA       → lista para enviar a geocod
           ENVIADA       → en geocod, lista para geocodificar
           GEOCODIFICADA → ya fue procesada
           BORRADA       → tabla física eliminada
         Las acciones disponibles varían según el estado.
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
                        <!-- Mostrar solo fecha y hora, sin segundos -->
                        <td><?= htmlspecialchars(substr($tg['fecha_creado'], 0, 16)) ?></td>

                        <td>
                            <?php
                            // Mapear estado a clase CSS de badge
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
                                <!-- Solo POBLADA puede enviarse a geocod -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="accion"    value="enviar_geocod">
                                    <input type="hidden" name="tabla_geo" value="<?= htmlspecialchars($tg['tabla_geo']) ?>">
                                    <input type="hidden" name="pedido"    value="<?= htmlspecialchars($tg['nombre_pedido']) ?>">
                                    <button class="btn btn-primary btn-sm">Enviar a geocod</button>
                                </form>

                            <?php elseif ($tg['estado'] === 'BORRADA'): ?>
                                <!-- Solo BORRADA puede limpiar su metadato -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="accion"   value="borrar_meta">
                                    <input type="hidden" name="id_tabla" value="<?= htmlspecialchars($tg['id_tabla']) ?>">
                                    <button class="btn btn-danger btn-sm">Eliminar metadato</button>
                                </form>

                            <?php else: ?>
                                <!-- ENVIADA y GEOCODIFICADA no tienen acción disponible aquí -->
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
// Permite ejecutar el motor de geocodificación sobre una tabla
// con estado ENVIADA. Muestra el resumen de resultados al terminar.
//
// Esta pestaña NO hace redirect después del POST (a diferencia
// de las acciones de ABM). Esto permite mostrar el resumen de
// resultados en la misma página. La variable $resultado viene
// cargada del bloque de procesamiento de arriba.
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

            <!-- Alerta de proceso en curso — se muestra via JS al enviar el form.
                 Está oculta por defecto (d-none) y se revela con classList.remove(). -->
            <div id="alerta-proceso" class="alert alert-warning d-none">
                <strong>⏳ En proceso.</strong>
                Aguardá mientras se geocodifican los registros. Esto puede tardar varios minutos.
                <br>No cerrés ni recargues esta pestaña.
            </div>

            <!-- Resultado del POST — se muestra si $resultado está definido -->
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
            // Al enviar el formulario:
            //   1. Mostrar la alerta "en proceso"
            //   2. Deshabilitar el botón para evitar doble envío accidental
            // El select ya tiene 'required' en HTML5 — si no hay valor
            // seleccionado, el navegador lo bloquea antes de llegar acá.
            document.getElementById('form-geocod').addEventListener('submit', function(e) {
                var select = document.querySelector('select[name="tabla_cfg"]');
                if (!select.value) return;

                document.getElementById('alerta-proceso').classList.remove('d-none');

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
// Permite descargar los datos en formato Excel (.xlsx).
// Dos secciones:
//   - Fuentes       : tablas originales del esquema geopedidos
//   - Geolocalizadas: JOIN fuente + tabla geo con todos los campos
// Los links apuntan a descargar.php que genera el archivo.
// ================================================================
elseif ($tab_activa === 'descargas'):
?>

    <!-- --------------------------------------------------------
         CARD: FUENTES
         Descarga de los CSV originales cargados en ABM.
         Apunta a descargar.php?tipo=fuente
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
         Descarga con JOIN fuente + tabla geo.
         Apunta a descargar.php?tipo=geolocalizada con tabla y pedido.
    -------------------------------------------------------- -->
    <div class="card card-shadow p-4">
        <h4 class="mb-1">Geolocalizadas</h4>
        <p class="text-muted mb-3">
            Tablas geocodificadas listas para descargar. El archivo incluye todos los campos
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
// Documentación inline del flujo completo del sistema.
// No tiene lógica PHP — es contenido estático.
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

        <hr>

        <h5 class="mt-4">Diagnóstico del sistema</h5>
        <p>
            Verificá que todos los componentes estén correctamente instalados y configurados.
        </p>
        <a href="selftest.php" target="_blank" class="btn btn-outline-secondary">
            🔧 Ejecutar Self Test
        </a>
    </div>

<?php endif; ?>

</div><!-- /container -->
</body>
</html>
