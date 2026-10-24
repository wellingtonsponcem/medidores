<?php
/**
 * Formatação pt-BR.
 *
 * Sem extensão `intl` no PHP desta máquina, formatamos na mão. Testamos para o
 * resultado não depender do ambiente — e para `number_format` não nos pegar
 * de surpresa com arredondamento de vírgula flutuante.
 */

require __DIR__ . '/_apoio/ambiente.php';

igual(fmt_moeda(560.18), 'R$ 560,18', 'moeda com centavos');
igual(fmt_moeda(1806.5), 'R$ 1.806,50', 'moeda com milhar e zero à direita');
igual(fmt_moeda(0), 'R$ 0,00', 'zero');
igual(fmt_moeda(1234567.891), 'R$ 1.234.567,89', 'vários milhares');
igual(fmt_moeda(null), 'R$ 0,00', 'nulo vira zero em vez de quebrar');
igual(fmt_moeda('abc'), 'R$ 0,00', 'texto vira zero');

// Os casos clássicos de arredondamento de ponto flutuante: 0.1+0.2 não é 0.3.
// O caso de 1.005 fica registrado como está, e não como "deveria ser": a
// representação binária do número é ligeiramente menor que 1.005, então o
// centavo não sobe. Conhecemos o comportamento — se um dia mudar, o teste avisa.
igual(fmt_moeda(0.1 + 0.2), 'R$ 0,30', 'soma com erro de vírgula flutuante');
igual(fmt_moeda(89.995), 'R$ 90,00', 'arredondamento para cima no centavo');
igual(fmt_moeda(1.005), 'R$ 1,00', 'um centavo e meio com representação binária menor não sobe');

igual(fmt_num(1806, 0), '1.806', 'inteiro com milhar');
igual(fmt_num(560.18, 2), '560,18', 'decimal brasileiro');
igual(fmt_num(968.85, 2), '968,85', 'decimal com centavo baixo');
igual(fmt_num(null), '0,00', 'nulo vira zero');

// --- datas: o banco guarda ISO com hora, o app digita BR ---
igual(fmt_data_iso('2026-10-04'), '2026-10-04', 'ISO simples');
igual(fmt_data_iso('2026-10-04T12:00:00Z'), '2026-10-04', 'ISO com hora, que é o formato que o painel grava');
igual(fmt_data_iso('04/10/2026'), '2026-10-04', 'formato brasileiro');
igual(fmt_data_iso('4/10/2026'), '2026-10-04', 'dia sem zero à esquerda');
igual(fmt_data_iso('nada'), null, 'texto sem data devolve null');
igual(fmt_data_iso(null), null, 'nulo devolve null');

igual(fmt_data_br('2026-10-04'), '04/10/2026', 'ISO vira BR');
igual(fmt_data_br('2026-10-04T12:00:00Z'), '04/10/2026', 'ISO com hora vira BR');
igual(fmt_data_br('04/10/2026'), '04/10/2026', 'BR passa reto');

igual(fmt_mes_ref('2026-10-04T12:00:00Z'), '10/2026', 'rótulo de mês de referência');
igual(fmt_mes_ref('2026-01-31'), '01/2026', 'primeiro mês do ano');

// Propriedade: o que sai de fmt_data_iso sempre reentra igual em fmt_data_br.
for ($mes = 1; $mes <= 12; $mes++) {
    $iso = sprintf('2026-%02d-15', $mes);
    $ida = fmt_data_iso($iso);
    $volta = fmt_data_br($ida);
    if ($volta !== sprintf('15/%02d/2026', $mes)) {
        ok(false, "propriedade: ISO -> BR preserva o dia (mês {$mes}: {$volta})");
        break;
    }
}
ok(true, 'propriedade: ISO -> BR preserva o dia nos 12 meses');

encerrarTeste();
