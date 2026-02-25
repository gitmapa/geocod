<?php
// =============================================================
// lib/parser_direcciones.php
// Parser de direcciones crudas para CABA
// -------------------------------------------------------------
// Propósito : Toma una dirección en texto libre ("sucia") y la
//             descompone en dos partes separadas:
//               - calle_original  : nombre de la calle
//               - altura_original : número de puerta
//
//             Esta separación es necesaria porque el sistema
//             guarda los componentes parseados en la tabla _geo
//             para trazabilidad y diagnóstico. La API de IDECABA
//             puede recibir la dirección como texto libre, pero
//             tener los campos separados facilita el análisis de
//             errores de geocodificación.
//
//             El parser maneja casos complejos frecuentes en
//             datos reales de CABA:
//               - Direcciones sin altura (S/N, s/n, s-n)
//               - Entradas que son solo números
//               - Números que NO son altura (MZA, DEPTO, PISO)
//               - Espacios múltiples y caracteres irregulares
//               - Alturas inválidas (0, 0000)
// -------------------------------------------------------------
// Dependencias: ninguna (función pura, sin DB ni API)
// -------------------------------------------------------------
// Versión : 1.1
// =============================================================


// =============================================================
// FUNCIÓN PRINCIPAL
// =============================================================

/**
 * parse_direccion_raw()
 * -------------------------------------------------------------
 * Descompone una dirección cruda en calle y altura.
 *
 * Algoritmo:
 *   1. Normalizar espacios múltiples y convertir a mayúsculas
 *      para comparaciones case-insensitive con acentos y ñ
 *   2. Manejar casos especiales: null, vacío, solo números, S/N
 *   3. Buscar todas las secuencias numéricas en la dirección
 *   4. Recorrer de derecha a izquierda descartando números
 *      precedidos por palabras prohibidas (MZA, DEPTO, etc.)
 *   5. El primer número válido encontrado es la altura;
 *      todo lo anterior es la calle
 *
 * Ejemplos de comportamiento esperado:
 *   "HERNANDEZ 2045"       → calle: "HERNANDEZ",      altura: "2045"
 *   "AV CORRIENTES 1000"   → calle: "AV CORRIENTES",  altura: "1000"
 *   "CALLE S/N"            → calle: "CALLE S/N",      altura: null
 *   "2045"                 → calle: "2045",            altura: null
 *   "MAZA 600 DEPTO 3"     → calle: "MAZA",           altura: "600"
 *   "CALLE 0000"           → calle: "CALLE 0000",     altura: null
 *
 * @param  string|null $direccion_raw  Dirección en texto libre. Puede ser null.
 *
 * @return array  Array asociativo con dos claves:
 *                  'calle'  (string|null) — nombre de la calle,
 *                                          null si la entrada era null o vacía
 *                  'altura' (string|null) — número de puerta,
 *                                          null si no se encontró altura válida
 */
function parse_direccion_raw($direccion_raw)
{
    // ----------------------------------------------------------
    // Casos base: entrada null o vacía — nada que parsear
    // ----------------------------------------------------------
    if ($direccion_raw === null) {
        return ['calle' => null, 'altura' => null];
    }

    $raw = trim((string)$direccion_raw);

    if ($raw === '') {
        return ['calle' => null, 'altura' => null];
    }

    // ----------------------------------------------------------
    // Normalización:
    //   $clean — espacios múltiples colapsados, capitalización original
    //   $upper — versión en mayúsculas para comparaciones seguras
    //            (mb_strtoupper maneja correctamente ñ y acentos)
    // ----------------------------------------------------------
    $clean = preg_replace('/\s+/u', ' ', $raw);
    $upper = mb_strtoupper($clean, 'UTF-8');

    // ----------------------------------------------------------
    // Caso especial: la entrada es solo números.
    // Podría ser un código o referencia — no tomar el número
    // como altura. Devolver todo como calle sin altura.
    // ----------------------------------------------------------
    if (preg_match('/^\d+$/u', $upper)) {
        return ['calle' => $clean, 'altura' => null];
    }

    // ----------------------------------------------------------
    // Caso especial: S/N (sin número).
    // Acepta variantes: S/N, SN, S-N seguido de fin de palabra.
    // ----------------------------------------------------------
    if (preg_match('/S\/?N\b/u', $upper)) {
        return ['calle' => $clean, 'altura' => null];
    }

    // ----------------------------------------------------------
    // Buscar todas las secuencias numéricas en la dirección.
    // PREG_OFFSET_CAPTURE incluye la posición byte de cada match,
    // necesaria para analizar el contexto previo al número.
    // ----------------------------------------------------------
    if (!preg_match_all('/\d+/u', $upper, $matches, PREG_OFFSET_CAPTURE)) {
        // No hay números → no hay altura posible
        return ['calle' => $clean, 'altura' => null];
    }

    $nums = $matches[0];

    if (empty($nums)) {
        return ['calle' => $clean, 'altura' => null];
    }

    // ----------------------------------------------------------
    // Palabras que indican que el número que sigue NO es la
    // altura de la dirección sino una referencia interna
    // (piso, depto, manzana, etc.).
    // Se verifica el texto inmediatamente ANTES del número.
    // ----------------------------------------------------------
    $banned_context_regex = '/(MZA|MANZANA|CASA|DEPTO|DEPARTAMENTO|DTO|PISO|TORRE|LOTE|LOCAL)\s*$/u';

    // ----------------------------------------------------------
    // Recorrer los números de derecha a izquierda.
    // La altura suele estar al final de la dirección, pero puede
    // haber números posteriores (DEPTO, PISO) que hay que saltar.
    // ----------------------------------------------------------
    for ($i = count($nums) - 1; $i >= 0; $i--) {

        $num_str  = $nums[$i][0];   // El número como string, ej: "2045"
        $byte_pos = $nums[$i][1];   // Su posición en bytes dentro de $upper

        // Alturas inválidas: solo ceros (0, 00, 0000)
        if (preg_match('/^0+$/', $num_str)) {
            continue;
        }

        // Tomar hasta 20 caracteres antes del número para detectar
        // si está precedido por MZA, CASA, DEPTO, etc.
        // 20 caracteres alcanza para "DEPARTAMENTO " (13 chars).
        $context_start = max(0, $byte_pos - 20);
        $context_len   = $byte_pos - $context_start;
        $before        = substr($upper, $context_start, $context_len);

        // Si el contexto anterior termina con palabra prohibida,
        // este número es una referencia interna → descartarlo
        if (preg_match($banned_context_regex, $before)) {
            continue;
        }

        // ----------------------------------------------------------
        // Número válido encontrado → es la altura.
        // Separar la calle como todo lo que está antes de este número.
        // ----------------------------------------------------------
        $altura = $num_str;

        // Buscar la posición del número en $clean (no en $upper)
        // para preservar la capitalización original de la calle.
        // strrpos toma la ÚLTIMA ocurrencia por si el mismo número
        // aparece antes en el nombre de la calle.
        $pos_in_clean = strrpos($clean, $num_str);

        if ($pos_in_clean === false) {
            // Fallback improbable: retornar todo como calle con altura separada
            return ['calle' => $clean, 'altura' => $altura];
        }

        // La calle es todo lo anterior al número encontrado
        $calle = rtrim(substr($clean, 0, $pos_in_clean));

        // Caso extremo: si la calle quedó vacía, devolver
        // todo como calle sin altura para no perder información
        if ($calle === '') {
            return ['calle' => $clean, 'altura' => null];
        }

        return ['calle' => $calle, 'altura' => $altura];
    }

    // ----------------------------------------------------------
    // Ningún número superó los filtros → sin altura válida
    // ----------------------------------------------------------
    return ['calle' => $clean, 'altura' => null];
}
