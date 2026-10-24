<?php
/**
 * Montagem das mensagens do bot.
 *
 * O texto do rateio já existia no botão "Compartilhar" do painel admin
 * (executeShareWhatsApp), e é ele que os moradores recebem por WhatsApp todo
 * mês. Reaproveitar o mesmo conteúdo evita duas versões da verdade: o que o
 * morador lê no Telegram é o que ele leria no WhatsApp.
 *
 * Regra de visibilidade: morador vê só a própria unidade; admin vê tudo e
 * lança leitura. Isso existe porque uma leitura errada mexe no rateio de todo
 * o condomínio — não é informação que cada um digita do seu jeito.
 */
function resp_boas_vindas(string $nome): string
{
    return "Olá, *{$nome}*! 👋\n\n"
        . "Sou o bot do *D-Meter*, o controle de consumo do condomínio.\n\n"
        . "Use /ajuda para ver o que posso fazer.";
}

function resp_ajuda(bool $ehAdmin): string
{
    $s = "*Comandos do D-Meter*\n\n"
        . "/resumo — água e energia do último mês\n"
        . "/agua — rateio detalhado da água (CESAN)\n"
        . "/energia — rateio detalhado da energia (use `/energia av` para a Av. Brasil)\n"
        . "/ultimo — últimas leituras brutas lançadas\n"
        . "/compartilhar — texto do rateio pronto para copiar\n"
        . "/pendentes — leituras aguardando sua confirmação\n"
        . "/logo — logo do D-Meter em PNG e JPG\n"
        . "/sair — desvincular este aparelho\n";

    if ($ehAdmin) {
        $s .= "\n*Só para administrador*\n"
            . "📷 Envie a foto da fatura ou do medidor que eu leio e proponho o lançamento.\n"
            . "⌨️ Ou digite, por exemplo: `agua cesan 1806 h1 490 h2 189 h3 284 fatura 560.18`\n"
            . "/tuya — sincroniza o whatímetro da Av. Brasil\n"
            . "/usuarios — lista os acessos dos moradores\n";
    } else {
        $s .= "\n_Você consulta seu consumo. Lançamento é feito pelo administrador._";
    }
    return $s;
}

/**
 * Rateio de água em texto. Com $perfil, mostra só a unidade daquele morador;
 * sem perfil (admin), mostra o quadro inteiro.
 */
function resp_agua(array $row, array $nomes, ?string $perfil = null): string
{
    $c1 = $nomes['casa1'] ?? 'Casa 1';
    $c2 = $nomes['casa2'] ?? 'Casa 2';
    $c3 = $nomes['casa3'] ?? 'Casa 3';
    $ref = fmt_mes_ref($row['data_leitura'] ?? null);
    $vpm3 = (float) ($row['valor_por_m3'] ?? 0) ?: ((float) ($row['valor_fatura_total'] ?? 0) / max(1, (float) ($row['consumo_cesan'] ?? 0)));

    if ($perfil !== null && $perfil !== 'admin') {
        $cab = "💧 *Água (CESAN)* — ref. {$ref}\n\n";
        $cab .= 'Consumo total: ' . fmt_num($row['consumo_cesan'] ?? 0, 2) . " m³\n";
        $cab .= 'Valor do m³: ' . fmt_moeda($vpm3) . "\n\n";
        if ($perfil === 'casa1') {
            $cab .= "*Sua unidade (Hidro 1) — {$c1}*\n"
                . fmt_num($row['consumo_h1'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_h1'] ?? 0) . "*\n";
        } elseif ($perfil === 'casa2') {
            $cab .= "*Sua unidade (Hidro 2) — {$c2}*\n"
                . fmt_num($row['consumo_h2'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_h2'] ?? 0) . "*\n";
        } elseif ($perfil === 'casa3') {
            $cab .= "*Sua unidade (Hidro 3) — {$c3}*\n"
                . fmt_num($row['consumo_h3'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_h3'] ?? 0) . "*\n";
            $cab .= "\n*Serviço / Comum*\n"
                . fmt_num($row['consumo_servico'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_servico'] ?? 0) . "*\n";
        } else {
            $cab .= "_Não consegui associar seu cadastro a um hidrômetro. Fale com o administrador._\n";
        }
        return $cab . "\n*Acesse seu painel:* " . SITE_URL;
    }

    $s = "💧 *Rateio de Água (CESAN)* — ref. {$ref}\n\n";
    $s .= 'Consumo total: ' . fmt_num($row['consumo_cesan'] ?? 0, 2) . " m³\n";
    $s .= 'Fatura: ' . fmt_moeda($row['valor_fatura_total'] ?? 0) . "\n";
    $s .= 'Valor do m³: ' . fmt_moeda($vpm3) . "\n\n";
    $s .= "• Hidro 1 — {$c1}: " . fmt_num($row['consumo_h1'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_h1'] ?? 0) . "*\n";
    $s .= "• Hidro 2 — {$c2}: " . fmt_num($row['consumo_h2'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_h2'] ?? 0) . "*\n";
    $s .= "• Hidro 3 — {$c3}: " . fmt_num($row['consumo_h3'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_h3'] ?? 0) . "*\n";
    $s .= '• Serviço/Comum: ' . fmt_num($row['consumo_servico'] ?? 0, 2) . ' m³ → *' . fmt_moeda($row['valor_servico'] ?? 0) . "*\n";
    return $s;
}

function resp_energia(array $row, array $nomes, ?string $perfil = null): string
{
    $cfg = energia_config($row['local_medidor'] ?? 'rua_inhambu');
    $c1 = $nomes['casa1'] ?? 'Casa 1';
    $c2 = $nomes['casa2'] ?? 'Casa 2';
    $c3 = $nomes['casa3'] ?? 'Casa 3';
    $l2 = $nomes['loja2'] ?? 'Loja 2';
    $ref = fmt_mes_ref($row['data_leitura'] ?? null);
    $vkwh = (float) ($row['valor_kwh'] ?? 0) ?: ((float) ($row['valor_fatura_total'] ?? 0) / max(1, (float) ($row['consumo_total_edp'] ?? 0)));

    if ($perfil !== null && $perfil !== 'admin') {
        $s = "⚡ *Energia — {$cfg['rotulo']}* — ref. {$ref}\n\n";
        $s .= 'Consumo total: ' . fmt_num($row['consumo_total_edp'] ?? 0, 2) . " kWh\n";
        $s .= 'Valor do kWh: ' . fmt_moeda($vkwh) . "\n\n";
        if ($perfil === 'casa1') {
            $s .= "*Sua unidade (Casa 1 — {$c1})*\n"
                . fmt_num($row['consumo_casa_1'] ?? 0, 2) . ' kWh → *' . fmt_moeda($row['valor_casa_1'] ?? 0) . "*\n";
        } elseif ($perfil === 'casa2' || $perfil === 'casa3') {
            // o nome tem que ser o da unidade, não o do vizinho: casa3 vê "Casa 3"
            // com o nome de casa3, como no painel
            $nome = $perfil === 'casa3' ? $c3 : $c2;
            $rotulo = $perfil === 'casa3' ? "Casa 3 ({$nome})" : "Casa 2 ({$nome})";
            $s .= "*Sua unidade ({$rotulo})*\n"
                . fmt_num($row['consumo_casa_2'] ?? 0, 2) . ' kWh → *' . fmt_moeda($row['valor_casa_2'] ?? 0) . "*\n";
        } elseif ($perfil === 'loja2' && ($row['local_medidor'] ?? 'rua_inhambu') === 'av_brasil') {
            // Só na Av. Brasil existe Loja 2, e lá ela é o relógio externo
            // (consumo_casa_1). Na Rua Inhambu o consumo_casa_1 é da Casa 1,
            // então um loja2 mostrando esse número estaria exibindo a conta de
            // outra pessoa — por isso a conferência do local.
            $s .= "*Sua unidade (Loja 2 — {$l2})*\n"
                . fmt_num($row['consumo_casa_1'] ?? 0, 2) . ' kWh → *' . fmt_moeda($row['valor_casa_1'] ?? 0) . "*\n";
        } else {
            $s .= "_Não consegui associar seu cadastro a um relógio. Fale com o administrador._\n";
        }
        return $s . "\n*Acesse seu painel:* " . SITE_URL;
    }

    $s = "⚡ *Rateio de Energia — {$cfg['rotulo']}* — ref. {$ref}\n\n";
    $s .= 'Consumo total: ' . fmt_num($row['consumo_total_edp'] ?? 0, 2) . " kWh\n";
    $s .= 'Fatura: ' . fmt_moeda($row['valor_fatura_total'] ?? 0) . "\n";
    $s .= 'Valor do kWh: ' . fmt_moeda($vkwh) . "\n\n";
    $s .= "• {$cfg['interno_rotulo']}: " . fmt_num($row['consumo_casa_2'] ?? 0, 2) . ' kWh → *' . fmt_moeda($row['valor_casa_2'] ?? 0) . "*\n";
    $s .= "• {$cfg['externo_rotulo']}: " . fmt_num($row['consumo_casa_1'] ?? 0, 2) . ' kWh → *' . fmt_moeda($row['valor_casa_1'] ?? 0) . "*\n";
    return $s;
}

/** Quadro compacto dos dois consumos, usado pelo /resumo. */
function resp_resumo(?array $agua, ?array $energia, array $nomes, ?string $perfil): string
{
    $s = "*Resumo do consumo*\n\n";
    $s .= $agua ? resp_agua($agua, $nomes, $perfil) : "_Ainda não há leitura de água lançada._";
    $s .= "\n\n";
    $s .= $energia ? resp_energia($energia, $nomes, $perfil) : "_Ainda não há leitura de energia lançada._";
    return $s;
}

/** Texto pronto para copiar, no formato que já sai pelo WhatsApp do painel. */
function resp_compartilhar(?array $agua, ?array $energia, array $nomes): string
{
    $c1 = $nomes['casa1'] ?? 'Casa 1';
    $c2 = $nomes['casa2'] ?? 'Casa 2';
    $c3 = $nomes['casa3'] ?? 'Casa 3';
    $l2 = $nomes['loja2'] ?? 'Loja 2';

    $texto = '';
    if ($agua) {
        $vpm3 = (float) ($agua['valor_por_m3'] ?? 0) ?: ((float) ($agua['valor_fatura_total'] ?? 0) / max(1, (float) ($agua['consumo_cesan'] ?? 0)));
        $texto .= "*AGUA (CESAN) — " . fmt_mes_ref($agua['data_leitura'] ?? null) . "*\n";
        $texto .= 'Consumo total: ' . fmt_num($agua['consumo_cesan'] ?? 0, 2) . " m3\n";
        $texto .= 'Valor do m3: ' . fmt_moeda($vpm3) . "\n\n";
        $texto .= "Hidro 1 - {$c1}\n" . fmt_num($agua['consumo_h1'] ?? 0, 2) . ' m3 -> *' . fmt_moeda($agua['valor_h1'] ?? 0) . "*\n\n";
        $texto .= "Hidro 2 - {$c2}\n" . fmt_num($agua['consumo_h2'] ?? 0, 2) . ' m3 -> *' . fmt_moeda($agua['valor_h2'] ?? 0) . "*\n\n";
        $texto .= "Hidro 3 - {$c3}\n" . fmt_num($agua['consumo_h3'] ?? 0, 2) . ' m3 -> *' . fmt_moeda($agua['valor_h3'] ?? 0) . "*\n\n";
        $texto .= "Servico\n" . fmt_num($agua['consumo_servico'] ?? 0, 2) . ' m3 -> *' . fmt_moeda($agua['valor_servico'] ?? 0) . "*\n\n";
    }
    if ($energia) {
        $cfg = energia_config($energia['local_medidor'] ?? 'rua_inhambu');
        $vkwh = (float) ($energia['valor_kwh'] ?? 0) ?: ((float) ($energia['valor_fatura_total'] ?? 0) / max(1, (float) ($energia['consumo_total_edp'] ?? 0)));
        $texto .= "*ENERGIA ({$cfg['rotulo']}) — " . fmt_mes_ref($energia['data_leitura'] ?? null) . "*\n";
        $texto .= 'Consumo total: ' . fmt_num($energia['consumo_total_edp'] ?? 0, 2) . " kWh\n";
        $texto .= 'Valor do kWh: ' . fmt_moeda($vkwh) . "\n\n";
        $texto .= "{$cfg['interno_rotulo']}\n" . fmt_num($energia['consumo_casa_2'] ?? 0, 2) . ' kWh -> *' . fmt_moeda($energia['valor_casa_2'] ?? 0) . "*\n\n";
        $texto .= "{$cfg['externo_rotulo']}\n" . fmt_num($energia['consumo_casa_1'] ?? 0, 2) . ' kWh -> *' . fmt_moeda($energia['valor_casa_1'] ?? 0) . "*\n\n";
    }
    $texto .= '*Acesse o painel:* ' . SITE_URL;
    return $texto;
}

function resp_nao_autorizado(): string
{
    return "Olá! Este bot é do *D-Meter*, mas seu aparelho ainda não está liberado.\n\n"
        . "Peça ao administrador do condomínio para liberar seu número.\n\n"
        . "Depois, use `/vincular seu_usuario sua_senha` para entrar com o mesmo acesso do painel.";
}

function resp_vinculo_errado(): string
{
    return "Usuário ou senha não conferem, ou o cadastro está inativo.\n\n"
        . "Use `/vincular seu_usuario sua_senha` — são os mesmos dados do painel em " . SITE_URL;
}

function resp_sem_permissao(): string
{
    return "Você pode consultar seu consumo, mas lançar leitura é função do administrador.\n\n"
        . "Qualquer coisa, fale com quem cuida do condomínio.";
}

function resp_pendentes(array $pendencias, array $nomes): string
{
    if (!$pendencias) {
        return "Nenhuma leitura aguardando confirmação. 👍";
    }
    $s = "📝 *Leituras aguardando confirmação*\n\n";
    foreach ($pendencias as $p) {
        $d = $p['payload'];
        $rotulo = $p['tipo'] === 'agua' ? '💧 Água' : ($p['tipo'] === 'energia' ? '⚡ Energia' : '📊 Tuya');
        $ref = fmt_mes_ref($d['data'] ?? ($p['criada_em'] ?? null));
        $s .= "*{$rotulo}* — ref. {$ref}\n";
        $s .= 'Criei em ' . fmt_data_br($p['criada_em'] ?? null) . "\n\n";
    }
    return $s . "Abra a mensagem original com os botões para confirmar ou descartar.";
}

function resp_usuarios(array $usuarios): string
{
    $s = "👥 *Acessos do D-Meter*\n\n";
    foreach ($usuarios as $u) {
        if (($u['perfil'] ?? '') === 'admin') {
            continue;
        }
        $ativo = (int) ($u['ativo'] ?? 1) === 1 ? 'ativo' : 'inativo';
        $troca = (int) ($u['senha_alterada'] ?? 0) === 1 ? 'senha ok' : 'senha provisória';
        $s .= '• ' . ucfirst((string) $u['nome']) . " ({$u['perfil']}) — {$ativo}, {$troca}\n";
    }
    return $s;
}

/**
 * Resumo específico para o Fernando (Loja 2 / Av. Brasil).
 * Mostra o consumo total, valor do kWh, consumo/valor da Graça (Tuya)
 * e o consumo/valor do Fernando (diferencial).
 */
function resp_rateio_fernando(array $row, array $nomes): string
{
    $ref = fmt_mes_ref($row['data_leitura'] ?? ($row['data'] ?? null));
    $totalConsumo = (float) ($row['consumo_total_edp'] ?? ($row['consumo_total'] ?? ($row['padrao'] ?? 0)));
    $fatura = (float) ($row['valor_fatura_total'] ?? ($row['fatura'] ?? 0));
    $vkwh = (float) ($row['valor_kwh'] ?? 0) ?: ($totalConsumo > 0 ? $fatura / $totalConsumo : 0);

    $c3Nome = $nomes['casa3'] ?? ($nomes['casa2'] ?? 'Graca');
    $l2Nome = $nomes['loja2'] ?? 'Fernando';

    $cGraca = (float) ($row['consumo_casa_2'] ?? ($row['consumo_interno'] ?? 0));
    $vGraca = (float) ($row['valor_casa_2'] ?? ($row['valor_interno'] ?? ($cGraca * $vkwh)));

    $cFernando = (float) ($row['consumo_casa_1'] ?? ($row['consumo_externo'] ?? 0));
    $vFernando = (float) ($row['valor_casa_1'] ?? ($row['valor_externo'] ?? ($cFernando * $vkwh)));

    $s = "Resumo de Rateio - {$ref}\n\n";
    $s .= "ENERGIA\n";
    $s .= "Consumo total: " . fmt_num($totalConsumo, 2) . " kWh\n";
    $s .= "Valor do kWh: " . fmt_moeda($vkwh) . "\n\n";
    $s .= "{$c3Nome}\n";
    $s .= fmt_num($cGraca, 2) . " kWh -> " . fmt_moeda($vGraca) . "\n\n";
    $s .= "{$l2Nome}\n";
    $s .= fmt_num($cFernando, 2) . " kWh -> " . fmt_moeda($vFernando) . "\n\n";
    $s .= "Acesse seu painel completo: " . SITE_URL;
    return $s;
}

/**
 * Mensagens individuais de água separadas por morador (prontas para copiar para o WhatsApp).
 */
function resp_mensagens_separadas_agua(array $row, array $nomes): array
{
    $ref = fmt_mes_ref($row['data_leitura'] ?? null);
    $vpm3 = (float) ($row['valor_por_m3'] ?? 0) ?: ((float) ($row['valor_fatura_total'] ?? 0) / max(1, (float) ($row['consumo_cesan'] ?? 0)));
    $cesan = (float) ($row['consumo_cesan'] ?? 0);

    $c1 = $nomes['casa1'] ?? 'Casa 1';
    $c2 = $nomes['casa2'] ?? 'Casa 2';
    $c3 = $nomes['casa3'] ?? 'Casa 3';

    $msgs = [];

    // Casa 1
    $msgs[] = "Olá, *{$c1}*! Aqui está o seu consumo de Água do mês de *{$ref}*:\n\n"
        . "💧 *ÁGUA (CESAN)*\n"
        . "• Consumo total da fatura: " . fmt_num($cesan, 2) . " m³\n"
        . "• Valor do m³: " . fmt_moeda($vpm3) . "\n\n"
        . "*Sua Unidade (Hidro 1 — {$c1})*\n"
        . "• Consumo: " . fmt_num($row['consumo_h1'] ?? 0, 2) . " m³\n"
        . "• Valor a pagar: *" . fmt_moeda($row['valor_h1'] ?? 0) . "*\n\n"
        . "Acesse seu painel completo: " . SITE_URL;

    // Casa 2
    $msgs[] = "Olá, *{$c2}*! Aqui está o seu consumo de Água do mês de *{$ref}*:\n\n"
        . "💧 *ÁGUA (CESAN)*\n"
        . "• Consumo total da fatura: " . fmt_num($cesan, 2) . " m³\n"
        . "• Valor do m³: " . fmt_moeda($vpm3) . "\n\n"
        . "*Sua Unidade (Hidro 2 — {$c2})*\n"
        . "• Consumo: " . fmt_num($row['consumo_h2'] ?? 0, 2) . " m³\n"
        . "• Valor a pagar: *" . fmt_moeda($row['valor_h2'] ?? 0) . "*\n\n"
        . "Acesse seu painel completo: " . SITE_URL;

    // Casa 3
    $msgs[] = "Olá, *{$c3}*! Aqui está o seu consumo de Água do mês de *{$ref}*:\n\n"
        . "💧 *ÁGUA (CESAN)*\n"
        . "• Consumo total da fatura: " . fmt_num($cesan, 2) . " m³\n"
        . "• Valor do m³: " . fmt_moeda($vpm3) . "\n\n"
        . "*Sua Unidade (Hidro 3 — {$c3})*\n"
        . "• Consumo: " . fmt_num($row['consumo_h3'] ?? 0, 2) . " m³\n"
        . "• Valor a pagar: *" . fmt_moeda($row['valor_h3'] ?? 0) . "*\n\n"
        . "• Área Comum / Serviço: " . fmt_num($row['consumo_servico'] ?? 0, 2) . " m³ → *" . fmt_moeda($row['valor_servico'] ?? 0) . "*\n\n"
        . "Acesse seu painel completo: " . SITE_URL;

    return $msgs;
}

/**
 * Mensagens individuais de energia separadas por morador (prontas para copiar para o WhatsApp).
 */
function resp_mensagens_separadas_energia(array $row, array $nomes): array
{
    $local = $row['local_medidor'] ?? 'rua_inhambu';
    $ref = fmt_mes_ref($row['data_leitura'] ?? null);
    $total = (float) ($row['consumo_total_edp'] ?? 0);
    $fatura = (float) ($row['valor_fatura_total'] ?? 0);
    $vkwh = (float) ($row['valor_kwh'] ?? 0) ?: ($total > 0 ? $fatura / $total : 0);

    $msgs = [];

    if ($local === 'av_brasil') {
        $c3 = $nomes['casa3'] ?? ($nomes['casa2'] ?? 'Graça');

        // Mensagem Graça (Casa 2/3 - Tuya)
        $msgs[] = "Olá, *{$c3}*! Segue o detalhamento da energia da Av. Brasil referente ao mês de *{$ref}*:\n\n"
            . "⚡ *Detalhamento da Energia — Av. Brasil*\n"
            . "• Consumo Total da Fatura (EDP): " . fmt_num($total, 2) . " kWh\n"
            . "• Valor Total da Fatura EDP: " . fmt_moeda($fatura) . "\n"
            . "• Custo do kWh: " . fmt_moeda($vkwh) . " / kWh\n\n"
            . "📊 *Seu Consumo (Whatímetro Digital Tuya):*\n"
            . "• Consumo do Mês: " . fmt_num($row['consumo_casa_2'] ?? 0, 2) . " kWh\n"
            . "• Valor a pagar: *" . fmt_moeda($row['valor_casa_2'] ?? 0) . "*\n\n"
            . "Acesse seu painel completo: " . SITE_URL;

        // Mensagem Fernando (Loja 2 / Diferencial)
        $msgs[] = resp_rateio_fernando($row, $nomes);
    } else {
        $c1 = $nomes['casa1'] ?? 'Casa 1';
        $c2 = $nomes['casa2'] ?? 'Mirian';

        // Mensagem Mirian (Casa 2 - Submedidor Nansen)
        $msgs[] = "Olá, *{$c2}*! Segue a sua conta de energia referente ao mês de *{$ref}*:\n\n"
            . "⚡ *Detalhamento da Energia — Novo Horizonte*\n"
            . "• Consumo Total da Fatura (EDP): " . fmt_num($total, 2) . " kWh\n"
            . "• Valor Total da Fatura EDP: " . fmt_moeda($fatura) . "\n"
            . "• Custo do kWh: " . fmt_moeda($vkwh) . " / kWh\n\n"
            . "📊 *Seu Consumo (Submedidor Casa 2):*\n"
            . "• Consumo do Mês: " . fmt_num($row['consumo_casa_2'] ?? 0, 2) . " kWh\n"
            . "• Valor a pagar: *" . fmt_moeda($row['valor_casa_2'] ?? 0) . "*\n\n"
            . "Acesse seu painel completo: " . SITE_URL;

        // Mensagem Casa 1 (Residual / Wellington)
        $msgs[] = "⚡ *Energia Novo Horizonte — {$c1} (Residual)* — ref. {$ref}\n\n"
            . "• Consumo: " . fmt_num($row['consumo_casa_1'] ?? 0, 2) . " kWh\n"
            . "• Valor a pagar: *" . fmt_moeda($row['valor_casa_1'] ?? 0) . "*\n\n"
            . "Acesse seu painel completo: " . SITE_URL;
    }

    return $msgs;
}
