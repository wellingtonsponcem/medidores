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
  - Inicialização do repositório Git na branch `main`.
  - Criação do arquivo `README.md`.
  - Configuração do repositório remoto para `https://github.com/wellingtonsponcem/medidores.git`.
  - Criação do arquivo de histórico inicial `HISTORICO.md`.
  - Conclusão do primeiro commit e do envio (`git push`) para a branch remota `main`.
  - Planejamento e definição dos passos para sincronização via SSH/Deploy do repositório no servidor de produção.
  - Implementação de botões de Upload e Colar nos cards de leitura inteligente (Água e Energia), permitindo colar imagens diretamente do clipboard (Clipboard API) ou via atalho Ctrl+V.
  - Correção de problemas de cache do script `admin-painel.js` no navegador adicionando atualização de versão (cache buster) no HTML.
  - Aprimoramento da colagem com `window.focus()` e tratamento customizado de erros de clipboard vazio para evitar falsas exceções de permissão.
  - Implementação de compressão automática de imagens (JPEG 80%, max 1200px) no frontend, otimizando o envio e corrigindo erros de payload no processamento OCR.
  - Correção do erro HTTP 401 Unauthorized adicionando explicitamente os cabeçalhos de autorização anon nas chamadas da Edge Function do Supabase.
- **2026-07-04**:
  - Correção do acesso ao painel de admin no layout mobile, adicionando o botão de configurações no header mobile quando o usuário logado for admin.
  - Correção de possíveis race conditions de carregamento de DOM no `app.js`, `dashboard.html` e `admin-painel.js` substituindo listeners puros de `DOMContentLoaded` por checagens dinâmicas de `document.readyState`.
  - Adição de tratamento a valores nulos em `userAuth` para evitar quebras silenciosas na execução dos scripts globais.
  - Atualização da senha do usuário `admin` no banco de dados para `'admin'`, facilitando o login real com dados completos.
  - Implementação de normalização automática de input em `index.html` que corrige o erro de digitação comum `"admn"` para `"admin"` no campo de usuário.
  - Aprimoramento do fallback de login legado no HTML para dar suporte a ambas as variações de escrita e senha (`admin`/`admn` e `admin`/`admin123`).
  - Adição de botões para Logout (Sair) tanto no modo Desktop quanto no layout Mobile do Painel Administrativo (`admin-painel.html`), limpando as credenciais de sessão local.
  - Migração de toda a arquitetura de banco de dados do Supabase (Cloud PostgreSQL) para o MySQL local da Hospedagem (Hostinger).
  - Criação do arquivo de configurações de conexão `db_config.php`.
  - Criação do script de migração automatizada `migration.php` que recria a estrutura das tabelas/views e importa todos os registros do Supabase via cURL.
  - Implementação do endpoint centralizado de API em PHP `api.php` para tratamento seguro de requisições de CRUD.
  - Criação do wrapper JS transparente `local-db-client.js` no frontend para simular as APIs do Supabase Client direcionando as chamadas para a `api.php`.
  - Remoção/ocultação do seletor de localidade da Avenida Brasil na aba de indicadores de água do Dashboard, visto que a Av. Brasil não possui medidor de água.
  - Refatoração completa das mensagens de WhatsApp e do modal de compartilhamento para buscar e exibir os nomes dos moradores dinamicamente do banco de dados (de acordo com o cadastro de cada perfil), removendo todos os nomes chumbados no código.
  - Alteração dos scripts de cron/coleta Tuya (`coleta_tuya.php` e `coleta_tuya.js`) para salvar as medições no banco de dados MySQL local da hospedagem em vez do Supabase.












