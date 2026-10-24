<?php
/**
 * Limpeza de números vindos de OCR ou de texto digitado.
 *
 * sanitize_hidro() é a tradução literal da função do admin-painel.js. Ela existe
 * porque o Gemini às vezes devolve o dígito vermelho (decimal) colado no final
 * ("4910" quando o preto é "0490") ou repete o primeiro dígito ("1189" quando o
 * preto é "0189"). A comparação com a leitura anterior é o que permite corrigir —
 * e é a MESMA correção que o painel web faz, para o app e o bot nunca divergirem.
 */

/**
 * Corrige leitura de hidrômetro usando a leitura anterior como referência.
 *
 * @param mixed $bruto  valor cru do OCR ou do texto
 * @param mixed $anterior leitura anterior da mesma coluna (null quando não há)
 * @return string|int|string|null vazio quando não deu para interpretar
 */
function sanitizar_hidro($bruto, $anterior = null)
{
    if ($bruto === null || $bruto === '') {
        return '';
    }

    $s = preg_replace('/\D+/', '', trim((string) $bruto));
    if ($s === null || $s === '') {
        return '';
    }
    if (strlen($s) > 1) {
        $s = ltrim($s, '0');
    }

    $valor = (int) $s;
    $prev = is_numeric($anterior) ? (float) $anterior : null;

    if ($prev !== null && $prev > 0 && strlen($s) >= 4) {
        // dígito vermelho colado no final: "4910" -> "490" (anterior 471)
        if ($valor - $prev > 200) {
            $semUltimo = (int) substr($s, 0, -1);
            if ($semUltimo >= $prev && ($semUltimo - $prev) < 150) {
                return (string) $semUltimo;
            }
            // dígito duplicado no início: "1189" -> "189" (anterior 183)
            $semPrimeiro = (int) substr($s, 1);
            if ($semPrimeiro >= $prev && ($semPrimeiro - $prev) < 150) {
                return (string) $semPrimeiro;
            }
        }
    }

    return (string) $valor;
}

/**
 * Converte um valor digitado ("1.806,5", "1806", "560.18") em número.
 * Aceita tanto o padrão BR quanto o ISO para não apanhar valores vindos do Gemini.
 */
function sanitizar_numero($bruto): ?float
{
    if ($bruto === null) {
        return null;
    }
    $s = trim((string) $bruto);
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/\s+/', '', $s);
    $s = preg_replace('/[^\d\.,\-]/', '', $s);
    if ($s === null || $s === '') {
        return null;
    }
    $temVirgula = strpos($s, ',') !== false;
    $temPonto = strpos($s, '.') !== false;
    if ($temVirgula && $temPonto) {
        // último separador é o decimal: "1.806,5" -> 1806.5
        if (strrpos($s, ',') > strrpos($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($temVirgula) {
        // vírgula sozinha: se é o último e há só um, é decimal
        $s = (preg_match('/^[\d]{1,3}(\.\d{3})*,\d+$/', $s) || substr_count($s, ',') === 1)
            ? str_replace(',', '.', $s)
            : str_replace(',', '', $s);
    } elseif ($temPonto && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        // ponto só com três dígitos depois é milhar brasileiro: "1.806" = 1806.
        // "560.18" não cai aqui (dois dígitos), então continua sendo decimal.
        $s = str_replace('.', '', $s);
    }
    if (!is_numeric($s)) {
        return null;
    }
    return (float) $s;
}
