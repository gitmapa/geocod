<?php
// lib/report_utils.php
// -------------------------------------------------------------
// Render de reportes simples para la UI
// -------------------------------------------------------------

/**
 * Imprime un resumen del proceso de geocodificación.
 *
 * Estructura esperada en $r (devuelta por procesar_tabla_direcciones):
 * [
 *   'total'     => int,
 *   'ok'        => int,
 *   'sin_match' => int,
 *   'error_api' => int,
 *   'tabla'     => string (opcional, la completa el wrapper)
 * ]
 */
function print_geocode_report(array $r)
{
    // Si vino error simple
    if (isset($r['error'])) {
        echo "<div class='alert alert-danger'>" . htmlspecialchars($r['error']) . "</div>";
        return;
    }

    // Normalizar campos del motor real
    $tabla      = $r['tabla']      ?? '(no informada)';
    $total      = $r['total']      ?? 0;
    $ok         = $r['ok']         ?? 0;
    $sin_match  = $r['sin_match']  ?? 0;
    $error_api  = $r['error_api']  ?? 0;

    echo "<table class='table table-bordered table-striped'>";
    echo "<tbody>";

    // Fila: Tabla procesada
    echo "<tr>";
    echo "<th style='width:40%;'>Tabla procesada</th>";
    echo "<td>" . htmlspecialchars($tabla) . "</td>";
    echo "</tr>";

    // Fila: Registros procesados
    echo "<tr>";
    echo "<th>Registros procesados</th>";
    echo "<td>" . htmlspecialchars($total) . "</td>";
    echo "</tr>";

    // Fila: Geocodificados OK
    echo "<tr>";
    echo "<th>Geocodificados OK</th>";
    echo "<td>" . htmlspecialchars($ok) . "</td>";
    echo "</tr>";

    // Fila: Errores de geocodificación (API)
    echo "<tr>";
    echo "<th>Errores de geocodificación (API)</th>";
    echo "<td>" . htmlspecialchars($error_api) . "</td>";
    echo "</tr>";

    // Fila: Casos sin coordenadas GKBA
    echo "<tr>";
    echo "<th>Casos sin coordenadas GKBA</th>";
    echo "<td>" . htmlspecialchars($sin_match) . "</td>";
    echo "</tr>";

    echo "</tbody>";
    echo "</table>";
}
