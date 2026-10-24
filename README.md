# medidores

D-Meter — controle de consumo de água (CESAN) e energia de um condomínio, com
rateio por unidade. Painel web + bot do Telegram.

O painel está em `https://wedistinto.com/medidores/`.

---

## O que existe aqui

| Caminho | O que é |
| --- | --- |
| `index.html`, `dashboard.html`, `admin-painel.html` | Painel (morador e administrador) |
| `api.php` | CRUD do banco, em PHP |
| `local-db-client.js` | Wrapper que faz o Supabase Client falar com a `api.php` |
| `bot/` | Bot do Telegram (webhook em PHP) |
| `cron/coleta_tuya.php` | Coleta automática do whatímetro da Av. Brasil |
| `scripts/telegram-setup.mjs` | Configura o webhook e o menu de comandos |
| `tests/` | Testes — `npm test` |
| `deploy.py` | Deploy por FTP |
| `HISTORICO.md` | Registro de cada alteração (fonte de verdade do projeto) |

---

## Bot do Telegram

O bot faz duas coisas: **consulta** (qualquer morador) e **lançamento**
(só o administrador).

### Lançar leitura

De três jeitos, todos terminando na mesma pendência de confirmação:

1. **Foto** — envie a fatura CESAN e/ou as fotos dos hidrômetros. O bot lê com
   o Gemini, mostra o rateio já calculado e pede confirmação.
2. **Texto** — digite direto, por exemplo:
   `agua cesan 1806 h1 490 h2 189 h3 284 fatura 560.18`
3. **`/tuya`** — sincroniza o whatímetro da Av. Brasil pela API da Tuya.

### Consultar

`/resumo`, `/agua`, `/energia` (use `/energia av` para a Avenida Brasil),
`/ultimo`, `/compartilhar`, `/pendentes`, `/sair`.

### A regra que não se negocia

**Nenhuma leitura entra no banco sem o administrador apertar o botão de
confirmar.** O bot propõe com a prévia do rateio; o grava é
`Pendencia::executar`, e só depois que `Banco::reivindicarPendencia` vence o
claim atômico. Toque duplo, reenvio do Telegram ou rede caindo não geram linha
em dobro — e linha duplicada estraga o rateio do mês inteiro do condomínio.

### Segredos

O bot lê as variáveis de `medidores.env`, que mora **fora da webroot**, em
`/home/u306254544/`. Copie o exemplo e preencha:

```bash
cp .env.example medidores.env
```

A ordem de precedência é: variável de ambiente real > `medidores.env` >
`.env` (este último só para desenvolvimento local).

> **Se uma chave foi versionada um dia, o `git rm` não resolve.** O valor
> continua no histórico do Git e em qualquer clone já feito. A única saída é
> rotacionar a chave no painel do fornecedor.

---

## Subir o bot pela primeira vez

### 1. Criar o bot no BotFather

Cada token é de um app só — não dá para dividir o do outro projeto.

1. Abra o Telegram e fale com `@BotFather`.
2. `/newbot`.
3. Nome sugerido: `D-Meter Medidores`. Usuário sugerido: algo no formato
   `dmedidor_bot` (tem que terminar em `bot` e ser único).
4. O BotFather devolve um token. **Não é para colar em arquivo versionado.**

### 2. Configurar o webhook e o menu

```bash
export TELEGRAM_BOT_TOKEN="cole_o_token_aqui"
node scripts/telegram-setup.mjs status    # confere o estado atual
node scripts/telegram-setup.mjs set       # gera o secret e registra o webhook
node scripts/telegram-setup.mjs comandos  # instala o menu
```

O `set` gera o `TELEGRAM_SECRET_TOKEN` sozinho e grava no banco. Sem essa
assinatura o webhook recusa **toda** requisição, inclusive as válidas.

### 3. Liberar os moradores

Sem `TELEGRAM_ALLOWED_USER_IDS` **ninguém** usa o bot — a checagem falha
fechada, não aberta. Pegue o `user_id` de cada um com `/start` e cadastre
no painel admin.

### 4. Testar

```
/start      ->  deve responder com a saudação
/resumo     ->  deve mostrar o último rateio
```

---

## Testes

```bash
npm test        # ou: php tests/rodar.php
```

Não há framework: o PHP desta máquina não tem sqlite, curl nem mbstring, e
instalar um driver só para testar regras de rateio faria o teste quebrar em
outra máquina. Cada arquivo de teste roda em processo separado, e o
`PdoFalso` interpreta **só** o SQL que o `Banco.php` emite — se o SQL mudar
sem o duplo acompanhar, o teste falha alto em vez de passar em silêncio.

Os testes são dirigidos por propriedade, porque o que importa aqui não é o
valor de um caso, e sim o invariante:

- quantos cliques forem, **uma linha** gravada;
- a resposta de um morador **nunca** contém os números de outra unidade;
- qualquer entrada que venha da IA vira array ou nulo, nunca exceção.

---

## Deploy

```bash
export MEDIDORES_FTP_PASS="sua_senha"
npm run deploy
```

A senha do FTP vem do ambiente, não do código. O deploy sobe o app, o `bot/`,
o `.htaccess` e leva `medidores.env` para a raiz do FTP, fora da webroot.

---

## Regras do projeto

1. Responder e documentar sempre em **português do Brasil**.
2. `HISTORICO.md` atualizado a cada mudança, com no máximo 70 linhas.
3. Sempre sugerir um título de commit ao concluir.
4. Testes dirigidos por propriedade, e observar CPU/memória, resiliência a
   falhas de banco e de conexão.
