<?php
/**
 * Interpretação do texto digitado.
 *
 * Estes testes valem duplo: conferem o parser e travam a decisão de projeto de
 * que a regra resolve antes da IA. Se um dia alguém trocar a ordem e mandar
 * "quanto deu a água?" para o Gemini, o teste quebra — e é para quebrar mesmo,
 * porque aquilo custa cota e um minuto de espera para o morador.
 */

require __DIR__ . '/_apoio/ambiente.php';

// ---- consultas: o dia a dia do morador ----
igual(Interpretacao::analisar('quanto deu a água?', 'casa1')['pergunta'], 'agua', 'pergunta sobre água vira consulta de água');
igual(Interpretacao::analisar('quanto deu a energia?', 'casa1')['pergunta'], 'energia', 'pergunta sobre energia vira consulta de energia');
igual(Interpretacao::analisar('Quanto foi a energia da avenida?', 'admin')['local'], 'av_brasil', 'pergunta sobre energia da avenida detecta local av_brasil');
igual(Interpretacao::analisar('quanto deu a energia da inhambu?', 'admin')['local'], 'rua_inhambu', 'pergunta sobre energia da inhambu detecta local rua_inhambu');
igual(Interpretacao::analisar('resumo', 'admin')['pergunta'], 'resumo', 'resumo');
igual(Interpretacao::analisar('RESUMO', 'admin')['pergunta'], 'resumo', 'resumo em caixa alta');
igual(Interpretacao::analisar('qual a situação?', 'casa2')['pergunta'], 'resumo', 'sinônimo de resumo');
igual(Interpretacao::analisar('minha conta', 'casa1'), null, '"minha conta" não é coberto por regra e vai para a IA');
igual(Interpretacao::analisar('última leitura', 'admin')['pergunta'], 'ultimo', 'última leitura');
igual(Interpretacao::analisar('manda o texto pro zap', 'admin')['pergunta'], 'compartilhar', 'pedido de texto para copiar');
igual(Interpretacao::analisar('mensagens', 'admin')['pergunta'], 'mensagens', 'pedido de mensagens separadas');
igual(Interpretacao::analisar('manda o logo', 'admin')['pergunta'], 'logo', 'manda o logo vira consulta de logo');
igual(Interpretacao::analisar('logotipo', 'casa1')['pergunta'], 'logo', 'logotipo vira consulta de logo');
igual(Interpretacao::analisar('água', 'casa1')['pergunta'], 'agua', 'só a palavra, com acento');
igual(Interpretacao::analisar('energia', 'casa3')['pergunta'], 'energia', 'só a palavra energia');

// ---- lançamento por texto: o administrador digitando no meio da rua ----
$l = Interpretacao::analisar('agua cesan 1806 h1 490 h2 189 h3 284 fatura 560.18', 'admin');
igual($l['tipo'], 'lancamento', 'frase com números vira lançamento, não consulta');
igual($l['tipo_dado'], 'agua', 'detecta água pelo sinônimo cesan');
igual($l['payload']['cesan'], 1806.0, 'lê a leitura da CESAN');
igual($l['payload']['h1'], 490.0, 'lê o H1');
igual($l['payload']['h2'], 189.0, 'lê o H2');
igual($l['payload']['h3'], 284.0, 'lê o H3');
igual($l['payload']['fatura'], 560.18, 'lê o valor da fatura');
igual($l['payload']['origem'], 'texto', 'marca a origem como texto digitado');

// ordem livre e separadores diferentes — ninguém digita tudo certinho no celular
$l = Interpretacao::analisar('fatura: 560,18 | cesan: 1.806 | h1: 0490 | h2: 189 | h3: 284', 'admin');
igual($l['payload']['cesan'], 1806.0, 'entende milhar com ponto no meio do texto');
igual($l['payload']['h1'], 490.0, 'entende decimal com vírgula e zero à esquerda');
igual($l['payload']['fatura'], 560.18, 'entende fatura com dois-pontos e vírgula');

$l = Interpretacao::analisar('cesan 1806', 'admin');
igual($l['tipo'], 'lancamento', 'mesmo só com a CESAN, é lançamento');
igual($l['payload']['h1'], null, 'hidrômetro ausente fica null, e não zero');

// ---- energia ----
$l = Interpretacao::analisar('energia padrao 2500 interno 3500 fatura 200.50', 'admin');
igual($l['tipo_dado'], 'energia', 'detecta energia');
igual($l['payload']['padrao'], 2500.0, 'lê a leitura padrão');
igual($l['payload']['interno'], 3500.0, 'lê a leitura interna');
igual($l['payload']['local'], 'rua_inhambu', 'sem indicação assume a Rua Inhambu');

$l = Interpretacao::analisar('energia av padrao 2000 interno 5589.71 fatura 2300', 'admin');
igual($l['payload']['local'], 'av_brasil', 'a palavra av manda para a Avenida Brasil');

// ---- o que NÃO é lançamento, mesmo tendo palavra de lançamento ----
igual(Interpretacao::analisar('quanto deu a água?', 'casa1')['tipo'], 'consulta', 'pergunta não vira lançamento só por ter a palavra água');
igual(Interpretacao::analisar('qual a situação da energia?', 'casa1')['tipo'], 'consulta', 'pergunta sobre energia é consulta');
igual(Interpretacao::analisar('oi', 'admin'), null, 'cumprimento não é intenção nenhuma — vai para a IA');
igual(Interpretacao::analisar('', 'admin'), null, 'texto vazio não é intenção');
igual(Interpretacao::analisar('   ', 'admin'), null, 'só espaço não é intenção');

// ---- data escrita na mensagem ----
igual(Interpretacao::dataDoTexto('cesan 1806 dia 20'), date('Y-m-') . sprintf('%02d', min(20, (int) date('j'))),
    'dia 20 vira o dia 20 do mês corrente (ou do anterior, se ainda não chegou)');
igual(Interpretacao::dataDoTexto('cesan 1806 05/11/2026'), '2026-11-05', 'data BR completa');
igual(Interpretacao::dataDoTexto('cesan 1806 2026-11-05'), '2026-11-05', 'data ISO completa');

// Propriedade: qualquer data que saia daqui tem que ser uma data válida,
// porque ela vira o valor da coluna DATE sem ninguém conferir depois.
for ($dia = 1; $dia <= 31; $dia++) {
    $texto = "cesan 1806 dia {$dia}";
    $data = Interpretacao::dataDoTexto($texto);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))) {
        ok(false, "propriedade: data extraída é sempre válida (dia {$dia} -> {$data})");
        break;
    }
}
ok(true, 'propriedade: data extraída é sempre válida (dias 1 a 31)');

// Dia impossível não pode virar data torta.
$hoje = Interpretacao::dataDoTexto('cesan 1806 dia 99');
igual($hoje, date('Y-m-d'), 'dia impossível cai no hoje em vez de gerar data inválida');

encerrarTeste();
