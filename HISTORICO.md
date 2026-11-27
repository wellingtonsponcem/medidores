# Histórico de Alterações - Projeto Medidores

Este arquivo registra o histórico de desenvolvimento deste projeto.

## Diretrizes para IAs / IDEs
1. **Idioma**: Sempre responda e documente em português do Brasil.
2. **Atualização**: Mantenha este arquivo `HISTORICO.md` sempre atualizado a cada nova funcionalidade, alteração ou correção. Não crie outros arquivos de histórico.
3. **Limite**: Este arquivo deve ter no máximo 70 linhas.
4. **Commits**: Sempre sugira um título de commit descritivo ao concluir uma alteração.
5. **Arquitetura & Testes**: Priorize testes baseados em propriedades (Property-Based Testing) e observe o consumo de recursos (CPU/Memória), resiliência a falhas de banco de dados e conexões.

---

## Histórico

- **2026-05-27**:
  - Inicialização do repositório Git na branch `main`. Configuração do repositório remoto.
  - Implementação de botões de Upload e Colar nos cards de leitura inteligente (Água e Energia), compressão automática JPEG e tratamento de erros de clipboard.
- **2026-07-04**:
  - Migração de toda a arquitetura de banco de dados do Supabase para o MySQL local da Hospedagem (Hostinger), criação de `db_config.php`, `migration.php` e `api.php`.
  - Refatoração completa das mensagens de WhatsApp e modais de compartilhamento com nomes dinâmicos dos moradores do banco. Coleta Tuya via MySQL.
- **2026-08 e 2026-10**:
  - Atualização MySQL (`ativo` em `usuarios`), modo sandbox, melhorias OCR Gemini e sincronia Tuya ref. 10/2026.
- **2026-10-22**:
  - Bot do Telegram (webhook em PHP) para lançar e consultar medidores com health check, `secret_token` obrigatório e dedupe por `update_id`.
  - Regra inegociável: confirmação obrigatória via botões (`Pendencia`). Port da matemática e OCR do painel para `Rateio.php` e `Ocr.php`.
  - Testes sem framework (`PdoFalso`) e isolamento de credenciais (`.gitignore`, `.env.example`).
- **2026-10-23 a 2026-10-28**:
  - Calibração de OCR com IA para faturas EDP (Av. Brasil e Inhambu), CESAN (Água), hidrômetros (H1, H2, H3) e watímetros (Nansen e Tuya).
  - Fluxo loja2 (Fernando): permissão para envio do watímetro e conta da Av. Brasil, sincronia imediata com Tuya Cloud, cálculo diferencial e visualização restrita à própria unidade.
  - Admins (admin e maria): retorno automático de mensagens separadas por morador prontas para WhatsApp (Água e Inhambu) e comando `/sincronizar`.
  - Correção no processamento de fotos do Telegram (`resultado`/`descricao`), visibilidade pública de `Pendencia::executarTuya`, validação de faturas da Loja 2 e avisos de divergência.
  - Camada de segurança e autenticação server-side (`Acesso.php`, `api.php`, `sync.php`), hash de senhas com rehash automático e isolamento de `app_secrets`.
  - Renderização do logo em PNG de alta resolução (fundo transparente) e JPG (fundo branco) a partir do SVG; comando `/logo` e `/senha`.
  - Correção na identificação de fotos em álbum no Telegram: classificação conjunta via `Ocr::classificarAlbum`, lock atômico por álbum (`master:<id>`) imune a divergência de timezone e fim das mensagens duplicadas de erro.
  - Cobertura expandida para 353 verificações 100% aprovadas. Deploy na Hostinger com purga de cache.
- **2026-10-29**:
  - Lançamento oficial da fatura CESAN ref. 10/2026 (R$ 493,78 / 30 m³) e hidrômetros H1: 507, H2: 295, H3: 195.
  - Broadcast automático de rateio e mensagens individuais por morador (WhatsApp) para todos os admins cadastrados (Wellington e Maria) e novo comando `/mensagens`.
- **2026-11-04 e 2026-11-05**:
  - Calibração de OCR para fatura EDP e watímetro Nansen Lumen MD (display 5 dígitos, 05202 -> 5202 e consumo 282 kWh).
  - Cascata resiliente no Gemini contra sobrecarga/spikes e padronização completa das mensagens individuais da Casa 1 e Casa 2.
  - Lançamento oficial de energia Rua Inhambu ref. 11/2026 (fatura R$ 332,93 / 282 kWh; Mirian R$ 216,05; Rose R$ 116,88) gravado e disparado via Telegram.
  - Auditoria e recuperação histórica da telemetria Tuya Cloud (569 eventos de telemetria) para extração da leitura de 04/11 14:00 (6.377,70 kWh).
  - Registro da leitura do watímetro Nansen do Fernando ref. 10/2026 (2.830 kWh) e definição da referência para 11/2026.
  - Lançamento oficial de energia Av. Brasil ref. 11/2026 gravado e atualizado com o watímetro Nansen medido do Fernando (3.414 kWh, consumo 584,00 kWh / R$ 652,07; Graça Tuya 787,99 kWh / R$ 879,84) e disparado com sucesso via Telegram aos administradores.
- **2026-11-27**:
  - Casa 2 (Rua Inhambu) passa da Mirian para o Wellington a partir da leitura **05210** do submedidor Nansen (27/11/2026). Último rateio da Casa 2 foi em 04/11 com **5202**: a Mirian consumiu **8 kWh** de 04/11 a 27/11, que entram na divisão da fatura de energia de 12/2026 (o restante da Casa 2 é do Wellington). O 5210 é ponto de corte, não foi gravado como última foto do relógio.
  - Bot: nome da Casa 2 vem do cadastro (sem "Mirian" fixo); fatura da CESAN sozinha agora guarda a leitura e pede os hidrômetros (estado `montando_agua`, 24 h), em vez de lançar H1-H3 zerados. Publicado no servidor via API da Hostinger (6 arquivos do bot).
  - A fatura CESAN de 27/11 (leitura 1.861 / R$ 407,06), enviada antes da correção, foi religada à leitura em andamento e a pendência zerada (`agua-2689ed989e`) descartada.
  - Segurança no servidor: `.htaccess` passou a bloquear `env` (entregava a chave do Gemini publicamente), `debug.php`, `migration.php`, `fix-view.php`, `fix-energia-views.php`, saídas `*_output.json`, `test*.js`, `.py`, `.sh`, `.sql` e `.md`. A chave do Gemini precisa ser trocada.
  - Água 11/2026 lançada (CESAN 1.861, H1 518, H2 299, H3 202, fatura R$ 407,06; anterior 27/10: 1.836 / 507 / 295 / 195). Consumo 25 m³ a R$ 16,28/m³: H1 7 m³, H2 4, H3 7, serviço 7. O OCR do bot errou os hidrômetros dessa vez (leu H2 como 199 e não leu H1 e H3), então os valores foram lidos à mão nas fotos e gravados direto por script de uso único, que avisou no Telegram e se apagou. Depois, as mensagens de rateio por casa foram disparadas aos admins (como o bot faz ao confirmar).
  - Fechada a água de 11/2026 (período da Mirian), o cadastro da Casa 2 foi trocado: login `mirian` desativado e criado `wellington` (perfil casa2, senha aleatória ainda não definida). Nenhum chat do Telegram estava vinculado à Casa 2.
  - Bot: `/convite usuário` (só admin) gera link de uso único `t.me/dmeter_nh_bot?start=<código>`, válido por 72 h, que vincula o aparelho ao perfil do usuário sem senha. Só o hash SHA-256 do código fica em `telegram_convites`; não vale para perfil admin nem troca o perfil de um aparelho já vinculado. Publicado no servidor e enviado o convite do `wellington` (Casa 2).
