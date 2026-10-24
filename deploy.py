import ftplib
import os
import sys

HOST = os.environ.get('MEDIDORES_FTP_HOST', '147.93.38.189')
USER = os.environ.get('MEDIDORES_FTP_USER', 'u306254544.wedistinto.com')
PASS = os.environ.get('MEDIDORES_FTP_PASS')

# Raiz do FTP na Hostinger é /home/u306254544 — o mesmo nível acima de
# public_html onde o bot procura medidores.env.
PASTA_APP = '/public_html/medidores'

ARQUIVOS = [
    'index.html',
    'dashboard.html',
    'admin-painel.html',
    'admin.js',
    'admin-painel.js',
    'app.js',
    'local-db-client.js',
    'api.php',
    'sync.php',
    'styles.css',
    'logo.svg',
    # bot do Telegram
    'bot/index.php',
    'bot/config.php',
    'db_config.php',
    # defesa contra .env cair na webroot
    '.htaccess',
    # exemplo para quem for novo no projeto — sem segredo, pode ir junto
    '.env.example',
]

# Pasta inteira, porque o bot tem onze arquivos em lib/ e ninguém vai lembrar
# de listar todos. Escondidos de fora ficam de fora.
PASTAS = ['bot/lib']

ARQUIVOS_APAGAR = [
    'agua.html', 'energia.html', 'agua.js', 'energia.js', 'agua.css', 'energia.css',
]


def garantir_dir(ftp, caminho):
    """Cria o diretório se não existir — o mkd levanta error_perm quando já existe."""
    try:
        ftp.mkd(caminho)
    except ftplib.error_perm:
        pass


def enviar_arquivo(ftp, local, remoto):
    if not os.path.exists(local):
        print(f'[Aviso] Ignorado: {local} (não encontrado)')
        return False
    diretorio = os.path.dirname(remoto)
    if diretorio:
        garantir_dir(ftp, diretorio)
    print(f'Enviando -> {remoto}')
    with open(local, 'rb') as f:
        ftp.storbinary(f'STOR {remoto}', f)
    return True


def enviar_pasta(ftp, local, remoto):
    """Sobe uma pasta inteira, criando os diretórios no servidor conforme desce."""
    if not os.path.isdir(local):
        print(f'[Aviso] Pasta ignorada: {local} (não encontrada)')
        return
    garantir_dir(ftp, remoto)
    for entrada in sorted(os.listdir(local)):
        if entrada.startswith('.'):
            continue
        origem = os.path.join(local, entrada)
        destino = f'{remoto}/{entrada}'
        if os.path.isdir(origem):
            enviar_pasta(ftp, origem, destino)
        else:
            enviar_arquivo(ftp, origem, destino)


def main():
    # Falha cedo e com a mensagem certa: subir app sem o bot é subir meio
    # sistema, e a metade que falta é a que ninguém percebe que faltou.
    if not PASS:
        sys.exit(
            'Falta a senha do FTP.\n'
            'Defina MEDIDORES_FTP_PASS no ambiente antes de rodar:\n'
            '  Windows: $env:MEDIDORES_FTP_PASS="sua_senha"\n'
            '  Linux/macOS: MEDIDORES_FTP_PASS="sua_senha" python deploy.py\n\n'
            'Não cole a senha neste arquivo: ele é versionado.'
        )

    # O .env de produção tem que existir localmente para ser enviado — sem ele o
    # bot sobe sem chave do Gemini e sem allowlist, e aí não funciona nada.
    env_local = 'medidores.env'
    env_existe = os.path.exists(env_local)
    if not env_existe:
        print(f'[Aviso] {env_local} não encontrado na raiz.')
        print('       O bot vai subir SEM as chaves. Copie .env.example para')
        print(f'       {env_local} e preencha antes de continuar.')

    print(f'Conectando ao FTP: {HOST} ...')
    try:
        ftp = ftplib.FTP(HOST)
        ftp.login(USER, PASS)
        print('Conexão estabelecida!')

        try:
            ftp.cwd(PASTA_APP)
            print(f'Navegado para {PASTA_APP}')
        except Exception as e:
            print(f'Falha ao navegar, tentando criar a pasta...', e)
            ftp.cwd('/public_html')
            ftp.mkd('medidores')
            ftp.cwd('medidores')

        print('Iniciando upload...')
        for f in ARQUIVOS:
            enviar_arquivo(ftp, f, f)

        for pasta in PASTAS:
            enviar_pasta(ftp, pasta, pasta)

        print('Limpando arquivos legados no servidor...')
        for f in ARQUIVOS_APAGAR:
            try:
                ftp.delete(f)
                print(f'Deletado do servidor -> {f}')
            except Exception:
                pass

        # Sobe para a raiz do FTP (fora de public_html) e larga o .env lá — é
        # de onde o bot.php lê, e o Apache não serve essa pasta.
        if env_existe:
            ftp.cwd('/')
            enviar_arquivo(ftp, env_local, 'medidores.env')
            print(f'{env_local} enviado para /medidores.env (fora da webroot)')

        ftp.quit()
        print('=' * 35)
        print('Deploy finalizado com sucesso!')
        print('Depois: node scripts/telegram-setup.mjs set')
        print('=' * 35)
    except Exception as e:
        print(f'Erro no deploy via FTP: {e}')
        sys.exit(1)


if __name__ == '__main__':
    main()
