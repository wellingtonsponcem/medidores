<?php
/**
 * Formatação pt-BR do bot.
 *
 * Por que manual e não `NumberFormatter`: o PHP da hospedagem e o da máquina de
 * desenvolvimento não têm extensão `intl` garantida. Formatamos na mão para o
 * resultado ser idêntico em qualquer lugar — e para os testes não dependerem de
 * extensão.
 */

function fmt_moeda($valor): string
{
    $valor = (float) ($valor ?? 0);
    $negativo = $valor < 0;
    $abs = abs($valor);
    $inteiro = (int) floor($abs);
    $centavos = (int) round(($abs - $inteiro) * 100);
    if ($centavos === 100) {
        $inteiro += 1;
        $centavos = 0;
    }
    $i = number_format($inteiro, 0, '', '.');
    return ($negativo ? '-' : '') . 'R$ ' . $i . ',' . str_pad($centavos, 2, '0', STR_PAD_LEFT);
}

/** Número com casas decimais, separador de milhar ponto e decimal vírgula. */
function fmt_num($valor, int $casas = 2): string
{
    return number_format((float) ($valor ?? 0), $casas, ',', '.');
}

/** Aceita ISO (AAAA-MM-DD[...]) ou BR (DD/MM/AAAA) e devolve AAAA-MM-DD. */
function fmt_data_iso($bruto): ?string
{
    $s = trim((string) ($bruto ?? ''));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $s, $m)) {
        // zero-padding obrigatório: "4/10/2026" tem que virar 2026-10-04, e não
        // 2026-10-4 — a coluna DATE e toda comparação por string dependem disso
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    return null;
}

/** Aceita ISO ou BR e devolve DD/MM/AAAA. Port do fmtDateSecure do admin-painel.js. */
function fmt_data_br($bruto): string
{
    $iso = fmt_data_iso($bruto);
    if ($iso === null) {
        return $bruto ? (string) $bruto : '-';
    }
    $p = explode('-', $iso);
    return $p[2] . '/' . $p[1] . '/' . $p[0];
}

/** "10/2026" — o rótulo usado nas mensagens de rateio, igual ao WhatsApp do admin. */
function fmt_mes_ref($bruto): string
{
    $iso = fmt_data_iso($bruto);
    if ($iso === null) {
        return '';
    }
    $p = explode('-', $iso);
    return $p[1] . '/' . $p[0];
}

/** Data de hoje no fuso do servidor, no formato ISO que a tabela aceita. */
function fmt_hoje(): string
{
    return date('Y-m-d');
}
