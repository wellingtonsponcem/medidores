# Guia: Configurando o Cron Job na Hostinger

Para automatizar a coleta de dados do medidor Tuya, siga estes passos no painel da Hostinger:

## 1. Upload dos Arquivos
Certifique-se de que os seguintes arquivos foram enviados para o seu servidor dentro da pasta `cron/`:
- `cron/coleta_tuya.js`
- `cron/.env` (contendo suas chaves)

*(O arquivo `sync.php` deve ficar na raiz do projeto junto com os arquivos .html)*

## 2. Configuração do Cron Job
1. Acesse o painel da Hostinger e procure por **Cron Jobs**.
2. No campo **Comando**, insira o caminho para o Node.js seguido do caminho do seu script:
   ```bash
   /usr/local/bin/node /home/uXXXXXXX/domains/seudominio.com/public_html/cron/coleta_tuya.js
   ```
   *(Substitua `/home/uXXXXXXX/...` pelo caminho absoluto real que aparece no seu Gerenciador de Arquivos)*.

3. Escolha a **Frequência**:
   - Sugestão: **Uma vez por hora** ou **Uma vez por dia** (dependendo de quão atualizado você quer o dashboard).
   - Para rodar a cada hora: `0 * * * *`

4. Clique em **Salvar**.

## 3. Verificação no Supabase
Execute o script manualmente uma vez via SSH (se tiver acesso) ou aguarde a primeira execução do cron. Verifique a tabela `leitura_energia` no Supabase para confirmar que os dados estão entrando.

---
*Nota: Se o comando `node` não estiver no caminho padrão, você pode consultar o suporte da Hostinger ou verificar na aba "Informações do PHP/Servidor" o caminho correto do binário Node.js.*
