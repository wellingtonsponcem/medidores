#!/usr/bin/env node
/**
 * Configura o webhook do bot do D-Meter.
 *
 * Uso:
 *   node scripts/telegram-setup.mjs status
 *   node scripts/telegram-setup.mjs set https://wedistinto.com/medidores/bot/index.php
 *   node scripts/telegram-setup.mjs comandos
 *   node scripts/telegram-setup.mjs delete
 *
 * Por que existe: apontar o webhook e criar o segredo são duas tarefas manuais
 * que ninguém faz direito na primeira vez. Este script faz as duas, e o
 * `set` SEM pede o segredo — gera um aleatório e grava no arquivo de env.
 * Sem segredo o webhook recusa tudo (falha fechada), então não deixa passar.
 *
 * Sem dependências: só fetch nativo do Node 18+.
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const candidatosEnv = [
    process.env.MEDIDORES_ENV_PATH,
    join(raiz, 'medidores.env'),
    join(raiz, '.env'),
    join(raiz, 'env'),
].filter(Boolean);

const apiBase = 'https://api.telegram.org';

function lerEnv() {
    for (const caminho of candidatosEnv) {
        if (!existsSync(caminho)) continue;
        const vars = {};
        for (const linha of readFileSync(caminho, 'utf8').split(/\r?\n/)) {
            const limpa = linha.trim();
            if (!limpa || limpa.startsWith('#')) continue;
            const i = limpa.indexOf('=');
            if (i < 0) continue;
            vars[limpa.slice(0, i).trim()] = limpa.slice(i + 1).trim().replace(/^["']|["']$/g, '');
        }
        return { caminho, vars };
    }
    return { caminho: null, vars: {} };
}

function gravarSecret(caminho, nome, valor) {
    const alvo = caminho ?? join(raiz, '.env');
    let conteudo = existsSync(alvo) ? readFileSync(alvo, 'utf8') : '';
    if (new RegExp(`^${nome}=`, 'm').test(conteudo)) {
        conteudo = conteudo.replace(new RegExp(`^${nome}=.*$`, 'm'), `${nome}=${valor}`);
    } else {
        conteudo = `${conteudo.replace(/\s*$/, '\n\n')}${nome}=${valor}\n`;
    }
    writeFileSync(alvo, conteudo, 'utf8');
    return alvo;
}

async function chamar(token, metodo, payload) {
    const res = await fetch(`${apiBase}/bot${token}/${metodo}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return res.json();
}

async function main() {
    const [acao, argumento] = process.argv.slice(2);
    const { caminho, vars } = lerEnv();
    const token = process.env.TELEGRAM_BOT_TOKEN || vars.TELEGRAM_BOT_TOKEN;

    if (!acao || acao === 'help') {
        console.log('Uso: node scripts/telegram-setup.mjs <status|set|comandos|delete>');
        console.log('  status                  mostra como o webhook está agora');
        console.log('  set <url>               aponta o webhook e cria o segredo');
        console.log('  comandos                publica o menu de comandos no Telegram');
        console.log('  delete                  remove o webhook');
        return;
    }

    if (!token) {
        console.error('✗ TELEGRAM_BOT_TOKEN não encontrado.');
        console.error('  1. Crie o bot com o @BotFather no Telegram e copie o token.');
        console.error(`  2. Adicione a linha TELEGRAM_BOT_TOKEN=<token> em: ${caminho ?? join(raiz, '.env')}`);
        process.exit(1);
    }

    if (acao === 'status') {
        const me = await chamar(token, 'getMe', {});
        const info = await chamar(token, 'getWebhookInfo', {});
        if (me.ok) console.log(`✓ Bot: @${me.result.username} (${me.result.first_name})`);
        else console.error('✗ Token inválido:', me.description);

        const w = info.result ?? {};
        console.log(w.url ? `✓ Webhook: ${w.url}` : '✗ Nenhum webhook configurado.');
        if (w.pending_update_count) console.log(`  Updates aguardando: ${w.pending_update_count}`);
        if (w.last_error_message) console.log(`  ⚠ Último erro: ${w.last_error_message} (${new Date(w.last_error_date * 1000).toLocaleString()})`);
        return;
    }

    if (acao === 'set') {
        if (!argumento) {
            console.error('✗ Informe a URL do webhook.');
            console.error('  node scripts/telegram-setup.mjs set https://wedistinto.com/medidores/bot/index.php');
            process.exit(1);
        }
        if (!/^https:\/\//.test(argumento)) {
            console.error('✗ O Telegram só aceita webhook em HTTPS.');
            process.exit(1);
        }

        let secret = process.env.TELEGRAM_SECRET_TOKEN || vars.TELEGRAM_SECRET_TOKEN;
        let veioDoArquivo = false;
        if (!secret) {
            secret = randomBytes(24).toString('hex');
            const alvo = gravarSecret(caminho, 'TELEGRAM_SECRET_TOKEN', secret);
            veioDoArquivo = true;
            console.log(`✓ Segredo gerado e gravado em: ${alvo}`);
        }

        const r = await chamar(token, 'setWebhook', {
            url: argumento,
            secret_token: secret,
            allowed_updates: ['message', 'callback_query'],
            drop_pending_updates: true,
        });
        if (!r.ok) {
            console.error('✗ Falha ao registrar o webhook:', r.description);
            process.exit(1);
        }
        console.log(`✓ Webhook apontado para: ${argumento}`);
        if (veioDoArquivo) {
            console.log('  O segredo está no arquivo de env local. No servidor, configure as variáveis');
            console.log('  TELEGRAM_BOT_TOKEN e TELEGRAM_SECRET_TOKEN no arquivo que fica FORA de public_html.');
        }

        const comandos = await chamar(token, 'setMyCommands', {
            commands: Object.entries(ROTAS).map(([command, description]) => ({ command: command.slice(1), description })),
        });
        console.log(comandos.ok ? '✓ Menu de comandos publicado.' : '⚠ Não publiquei o menu:', comandos.description);

        const me = await chamar(token, 'getMe', {});
        if (me.ok) console.log(`\nTeste agora: abra @${me.result.username} no Telegram e digite /ajuda`);
        return;
    }

    if (acao === 'comandos') {
        const r = await chamar(token, 'setMyCommands', {
            commands: Object.entries(ROTAS).map(([command, description]) => ({ command: command.slice(1), description })),
        });
        console.log(r.ok ? '✓ Menu de comandos publicado.' : '✗ ' + r.description);
        return;
    }

    if (acao === 'delete') {
        const r = await chamar(token, 'deleteWebhook', { drop_pending_updates: true });
        console.log(r.ok ? '✓ Webhook removido.' : '✗ ' + r.description);
        return;
    }

    console.error(`✗ Ação desconhecida: ${acao}`);
    process.exit(1);
}

/** Mesma lista do COMANDOS em bot/index.php — mantida aqui para o menu bater com o handler. */
const ROTAS = {
    '/resumo': 'Água e energia do último mês',
    '/agua': 'Rateio detalhado da água (CESAN)',
    '/energia': 'Rateio detalhado da energia',
    '/ultimo': 'Últimas leituras brutas',
    '/compartilhar': 'Texto do rateio para copiar',
    '/pendentes': 'Leituras aguardando confirmação',
    '/senha': 'Redefinir senha de usuário (Admin)',
    '/logo': 'Logo do D-Meter em PNG e JPG',
    '/vincular': 'Entrar com o usuário do painel',
    '/sair': 'Desvincular este aparelho',
    '/ajuda': 'Lista o que o bot faz',
};

main().catch((e) => {
    console.error('✗ Erro:', e.message);
    process.exit(1);
});
