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
  - Renderização do logo em PNG de alta resolução (fundo transparente) e JPG (fundo branco) a partir do SVG; envio imediato para admins via Telegram e novo comando `/logo`.
  - Novo comando `/senha` para administradores redefinirem senhas de usuários diretamente pelo Telegram com hash seguro.
  - Cobertura expandida para 9 suites e 340 verificações 100% aprovadas. Deploy na Hostinger com purga de cache via Hostinger MCP.
