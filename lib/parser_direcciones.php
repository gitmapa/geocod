<?php
// lib/parser_direcciones.php
// -------------------------------------------------------------
// Parser de direcciones "sucias" → calle_original + altura_original
// Pensado para CABA, direcciones con altura al final, cruces, S/N, etc.
// -------------------------------------------------------------

/**
 * Parsea una dirección cruda y devuelve calle y altura.
 *
 * Reglas principales:
 * - Si es solo números → se considera calle, sin altura.
 * - Si termina en número razonable → ese número es la altura.
 * - Se ignoran números asociados a MZA, CASA, DEPTO, etc. al final.
 * - 0, 0000, S/N → altura NULL.
 *
 * @param string|null $direccion_raw
 * @return array ['calle' => string|null, 'altura' => string|null]
 */
function parse_direccion_raw($direccion_raw)
{
    if ($direccion_raw === null) {
        return ['calle' => null, 'altura' => null];
    }

    $raw = trim((string)$direccion_raw);

    if ($raw === '') {
        return ['calle' => null, 'altura' => null];
    }

    // Normalizar espacios
    $clean = preg_replace('/\s+/u', ' ', $raw);
    $upper = mb_strtoupper($clean, 'UTF-8');

    // Caso: solo números → no tomar como altura (calle = todo, altura = NULL)
    if (preg_match('/^\d+$/u', $upper)) {
        return ['calle' => $clean, 'altura' => null];
    }

    // Caso: S/N, S-N, S.N → altura NULL
    if (preg_match('/S\/?N\b/u', $upper)) {
        return ['calle' => $clean, 'altura' => null];
    }

    // Buscar todas las secuencias numéricas
    if (!preg_match_all('/\d+/u', $upper, $matches, PREG_OFFSET_CAPTURE)) {
        // No hay números → sin altura
        return ['calle' => $clean, 'altura' => null];
    }

    $nums = $matches[0];
    if (empty($nums)) {
        return ['calle' => $clean, 'altura' => null];
    }

    // Palabras que indican que el número NO es altura (MZA, CASA, DEPTO, etc.)
    $banned_context_regex = '/(MZA|MANZANA|CASA|DEPTO|DEPARTAMENTO|DTO|PISO|TORRE|LOTE|LOCAL)\s*$/u';

    // Recorremos desde el último número hacia atrás buscando una altura válida
    for ($i = count($nums) - 1; $i >= 0; $i--) {

        $num_str = $nums[$i][0];
        $byte_pos = $nums[$i][1];

        // Altura inválida por ser 0 o 0000
        if (preg_match('/^0+$/', $num_str)) {
            continue;
        }

        // Tomar un contexto anterior al número (hasta 20 caracteres) para ver si es MZA, CASA, etc.
        $context_start = max(0, $byte_pos - 20);
        $context_len   = $byte_pos - $context_start;
        $before        = substr($upper, $context_start, $context_len);

        if (preg_match($banned_context_regex, $before)) {
            // Este número está asociado a MZA/CASA/DEPTO → no es altura
            continue;
        }

        // Si llegamos acá, aceptamos este número como altura
        $altura = $num_str;

        // Buscamos la posición de este número en el string "limpio"
        // Usamos strrpos para tomar la última ocurrencia de este número
        $pos_in_clean = strrpos($clean, $num_str);
        if ($pos_in_clean === false) {
            // Fallback: no se encontró; dejamos calle completa y solo altura
            return ['calle' => $clean, 'altura' => $altura];
        }

        $calle = rtrim(substr($clean, 0, $pos_in_clean));

        // Caso extremo: si la calle queda vacía, devolvemos todo como calle y sin altura
        if ($calle === '') {
            return ['calle' => $clean, 'altura' => null];
        }

        return ['calle' => $calle, 'altura' => $altura];
    }

    // Si ningún número fue aceptable como altura → sin altura
    return ['calle' => $clean, 'altura' => null];
}
