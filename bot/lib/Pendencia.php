<?php
/**
 * Pendências: nenhuma leitura entra no banco sem o usuário confirmar.
 *
 * É a regra de ouro que os dois projetos irmãos compartilham, e aqui ela é
 * obrigatória por um motivo extra: uma leitura errada de hidrômetro não é só
 * um número torto, ela altera o rateio de água de TODO o condomínio no mês.
 * O bot propõe com a prévia do rateio já calculada; o usuário confere os
 * valores e só então a linha é inserida.
 *
 * O toque duplo é neutralizado pelo claim atômico em Banco::reivindicarPendencia():
 * o segundo clique não encontra a pendência pendente e não grava nada.
 */
class Pendencia
{
    public const TIPOS = ['agua', 'energia', 'tuya'];

    /** Gera um id curto, legível na URL do callback (limite do Telegram: 64 bytes). */
    public static function novoId(string $tipo): string
    {
        return substr($tipo, 0, 4) . '-' . bin2hex(random_bytes(5));
    }

    public static function criar(int $chatId, string $tipo, array $payload): string
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException('Tipo de pendência desconhecido: ' . $tipo);
        }
        $id = self::novoId($tipo);
        Banco::criarPendencia($id, $chatId, $tipo, $payload);
        return $id;
    }

    public static function teclado(string $id): array
    {
        return [[
            ['text' => '✅ Confirmar lançamento', 'callback_data' => 'p:' . $id . ':ok'],
            ['text' => '❌ Descartar', 'callback_data' => 'p:' . $id . ':nao'],
        ]];
    }

    /**
     * Mensagem da pendência: o que foi lido, o rateio resultante e os avisos.
     *
     * @param array $p pendência já com payload decodificado
     * @param array $nomes perfil -> nome do morador
     */
    public static function texto(array $p, array $nomes = []): string
    {
        if ($p['tipo'] === 'tuya') {
            $data = fmt_data_br($p['payload']['data'] ?? null);
            return "📊 *Sincronização do whatímetro*\n\n"
                . "Vou buscar a leitura automática do medidor da Av. Brasil referente a *{$data}*.\n\n"
                . "Isso atualiza a leitura usada no rateio da Casa 03 e da Loja 02.";
        }

        $r = self::rateioDaPendencia($p);
        $origem = $p['payload']['origem'] ?? 'manual';
        $origemTexto = $origem === 'foto' ? '📷 lida de foto'
            : ($origem === 'texto' ? '⌨️ digitada' : '✍️ manual');

        $linha = $p['tipo'] === 'agua' ? self::textoAgua($p, $r, $nomes) : self::textoEnergia($p, $r, $nomes);
        $avisos = self::avisosDaPendencia($p, $r);

        $saida = $linha . "\n\n_Origem: {$origemTexto}_";
        if ($avisos) {
            $saida .= "\n\n⚠️ *Confira antes de confirmar:*\n";
            foreach ($avisos as $a) {
                $saida .= '• ' . $a . "\n";
            }
        }
        $saida .= "\nConfira os números acima. Só vou gravar depois que você tocar em Confirmar.";
        return $saida;
    }

    private static function textoAgua(array $p, array $r, array $nomes): string
    {
        $d = $p['payload'];
        $c1 = $nomes['casa1'] ?? 'Casa 1';
        $c2 = $nomes['casa2'] ?? 'Casa 2';
        $c3 = $nomes['casa3'] ?? 'Casa 3';

        $s = "💧 *Nova leitura de água* — ref. " . fmt_mes_ref($d['data'] ?? null) . "\n\n";
        $s .= 'CESAN: ' . fmt_num($d['cesan'] ?? 0, 0) . "\n";
        $s .= 'H1: ' . fmt_num($d['h1'] ?? 0, 0) . "\n";
        $s .= 'H2: ' . fmt_num($d['h2'] ?? 0, 0) . "\n";
        $s .= 'H3: ' . fmt_num($d['h3'] ?? 0, 0) . "\n";
        $s .= 'Fatura: ' . fmt_moeda($d['fatura'] ?? 0) . "\n\n";

        $s .= "*Rateio previsto*\n";
        $s .= 'Consumo total: ' . fmt_num($r['consumo_cesan'], 1) . " m³\n";
        $s .= 'Valor do m³: ' . fmt_moeda($r['valor_por_m3']) . "\n\n";
        $s .= "• {$c1} (H1−H2): " . fmt_num($r['consumo_h1'], 1) . ' m³ → *' . fmt_moeda($r['valor_h1']) . "*\n";
        $s .= "• {$c2} (H2): " . fmt_num($r['consumo_h2'], 1) . ' m³ → *' . fmt_moeda($r['valor_h2']) . "*\n";
        $s .= "• {$c3} (H3): " . fmt_num($r['consumo_h3'], 1) . ' m³ → *' . fmt_moeda($r['valor_h3']) . "*\n";
        $s .= '• Serviço/Comum: ' . fmt_num($r['consumo_servico'], 1) . ' m³ → *' . fmt_moeda($r['valor_servico']) . "*\n";
        return $s;
    }

    private static function textoEnergia(array $p, array $r, array $nomes): string
    {
        $d = $p['payload'];
        $c1 = $nomes['casa1'] ?? 'Casa 1';
        $c2 = $nomes['casa2'] ?? 'Casa 2';
        $c3 = $nomes['casa3'] ?? 'Casa 3';
        $l2 = $nomes['loja2'] ?? 'Loja 2';

        $s = "⚡ *Nova leitura de energia* — {$r['rotulo']}\n";
        $s .= 'Ref. ' . fmt_mes_ref($d['data'] ?? null) . "\n\n";
        $s .= 'Consumo total da fatura: ' . fmt_num($r['consumo_total'], 2) . " kWh\n";
        $s .= 'Valor da fatura: ' . fmt_moeda($r['valor_fatura_total']) . "\n";
        $s .= 'Valor do kWh: ' . fmt_moeda($r['valor_kwh']) . "\n\n";

        $s .= "*Rateio previsto*\n";
        $s .= "• {$r['interno_rotulo']}: " . fmt_num($r['consumo_interno'], 2) . ' kWh → *' . fmt_moeda($r['valor_interno'])
            . ($r['medido'] ? " (medido)" : " (residual)") . "*\n";
        $s .= "• {$r['externo_rotulo']}: " . fmt_num($r['consumo_externo'], 2) . ' kWh → *' . fmt_moeda($r['valor_externo'])
            . ($r['medido'] ? " (medido)" : " (residual)") . "*\n";

        if (!$r['medido']) {
            $s .= "\n_Sem o relógio do " . $r['externo_rotulo'] . " medido, o valor sai por diferença do total._";
        }
        return $s;
    }

    private static function avisosDaPendencia(array $p, array $r): array
    {
        $d = $p['payload'];
        $anterior = $p['tipo'] === 'agua' ? Banco::ultimaAguaBruta() : Banco::ultimaEnergiaBruta($d['local'] ?? 'rua_inhambu');
        $anterior = $anterior ?: [];

        if ($p['tipo'] === 'agua') {
            $ref = [
                'cesan' => $anterior['leitura_cesan'] ?? 0,
                'h1' => $anterior['leitura_h1'] ?? 0,
                'h2' => $anterior['leitura_h2'] ?? 0,
                'h3' => $anterior['leitura_h3'] ?? 0,
            ];
            return conferir_agua($d, $ref, $r);
        }

        $ref = [
            'interno_ref' => $anterior['leitura_interno_anterior_ref'] ?? $anterior['leitura_interno'] ?? 0,
            'interno2_ref' => $anterior['leitura_interno_2_anterior_ref'] ?? $anterior['leitura_interno_2'] ?? null,
        ];
        // os avisos do OCR (relógio velho, endereço trocado) eram guardados mas
        // nunca chegavam à mensagem — quem confirmava não os via
        return array_values(array_unique(array_merge(conferir_energia($d, $ref, $r), $d['avisos_ocr'] ?? [])));
    }

    /**
     * Calcula o rateio que será gravado. Usa a MESMA matemática do painel web
     * (Rateio.php), aplicada sobre a leitura anterior do banco.
     */
    public static function rateioDaPendencia(array $p): array
    {
        $d = $p['payload'];
        if ($p['tipo'] === 'agua') {
            $ant = Banco::ultimaAguaBruta();
            return rateio_agua($d, [
                'cesan' => $ant['leitura_cesan'] ?? 0,
                'h1' => $ant['leitura_h1'] ?? 0,
                'h2' => $ant['leitura_h2'] ?? 0,
                'h3' => $ant['leitura_h3'] ?? 0,
            ]);
        }
        $local = $d['local'] ?? 'rua_inhambu';
        $ant = Banco::ultimaEnergiaBruta($local);
        return rateio_energia($d, [
            'interno_ref' => $ant['leitura_interno_anterior_ref'] ?? $ant['leitura_interno'] ?? 0,
            'interno2_ref' => $ant['leitura_interno_2_anterior_ref'] ?? $ant['leitura_interno_2'] ?? null,
        ], $local);
    }

    /**
     * Grava a leitura. Só é chamada por quem venceu o claim atômico.
     *
     * @return array{ok:bool, id?:string, erro?:string}
     */
    public static function executar(array $p): array
    {
        $d = $p['payload'];
        try {
            if ($p['tipo'] === 'agua') {
                $id = Banco::inserirAgua([
                    'data' => self::dataSegura($d['data'] ?? null),
                    'cesan' => $d['cesan'] ?? 0,
                    'h1' => $d['h1'] ?? 0,
                    'h2' => $d['h2'] ?? 0,
                    'h3' => $d['h3'] ?? 0,
                    'fatura' => $d['fatura'] ?? 0,
                ]);
                return ['ok' => true, 'id' => $id];
            }

            if ($p['tipo'] === 'energia') {
                $id = Banco::inserirEnergia([
                    'data' => self::dataSegura($d['data'] ?? null),
                    'padrao' => $d['padrao'] ?? 0,
                    'interno' => $d['interno'] ?? 0,
                    'interno2' => $d['interno2'] ?? null,
                    'fatura' => $d['fatura'] ?? 0,
                    'local' => $d['local'] ?? 'rua_inhambu',
                    'ref1' => $d['ref1'] ?? null,
                    'ref2' => $d['ref2'] ?? null,
                ]);
                return ['ok' => true, 'id' => $id];
            }

            return self::executarTuya($d);
        } catch (Exception $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }

    /** Data no formato que a coluna DATE aceita, com fallback para hoje. */
    private static function dataSegura($data): string
    {
        return fmt_data_iso($data) ?? fmt_hoje();
    }

    /**
     * Dispara a coleta do whatímetro — a mesma rotina do cron diário.
     *
     * O coleta_tuya.php faz echo e usa globals, então isolamos com output buffer.
     * Se o .env do cron não existir devolvemos erro amigável em vez de deixar o
     * die() do script derrubar a resposta do webhook.
     *
     * Pública porque o /sincronizar, a foto do watímetro e a fatura da Av.
     * Brasil também disparam a coleta. Era private, e cada uma dessas chamadas
     * morria com erro fatal — o Telegram reenviava o update e o bot repetia.
     */
    public static function executarTuya(array $d): array
    {
        $arquivo = BOT_RAIZ . '/cron/coleta_tuya.php';
        $envDoCron = BOT_RAIZ . '/cron/.env';

        if (!is_readable($arquivo)) {
            return ['ok' => false, 'erro' => 'Script de coleta Tuya não encontrado no servidor.'];
        }
        if (!is_readable($envDoCron)) {
            return ['ok' => false, 'erro' => 'As credenciais do Tuya (cron/.env) não estão no servidor.'];
        }

        ob_start();
        try {
            include $arquivo;
        } catch (Exception $e) {
            echo 'ERRO: ' . $e->getMessage();
        }
        $saida = ob_get_clean();

        if (strpos($saida, 'Sucesso!') === false && strpos($saida, 'Atualizado') === false) {
            $resumo = trim(preg_replace('/\s+/', ' ', $saida));
            return ['ok' => false, 'erro' => 'A coleta não concluiu: ' . (substr($resumo, 0, 300) ?: 'sem detalhe')];
        }
        return ['ok' => true, 'detalhe' => trim($saida)];
    }
}
