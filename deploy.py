import ftplib
import os

HOST = '147.93.38.189'
USER = 'u306254544.wedistinto.com'
PASS = '!@Jeane&w#1'

files_to_upload = [
    "index.html",
    "dashboard.html",
    "admin-painel.html",
    "app.js",
    "admin-painel.js",
    "styles.css",
    "logo.svg"
]

files_to_delete = [
    "agua.html",
    "energia.html",
    "agua.js",
    "energia.js",
    "agua.css",
    "energia.css"
]

def deploy():
    print(f"Conectando ao FTP: {HOST} ...")
    try:
        ftp = ftplib.FTP(HOST)
        ftp.login(USER, PASS)
        print("Conexão estabelecida!")
        
        # Cria ou navega no diretório
        try:
            ftp.cwd("/public_html/medidores")
            print("Navegado para public_html/medidores")
        except Exception as e:
            print("Falha ao navegar, tentando criar a pasta...", e)
            try:
                ftp.cwd("/public_html")
                ftp.mkd("medidores")
                ftp.cwd("medidores")
            except Exception as inner_e:
                print("Não foi possível acessar ou criar o diretório base.", inner_e)
                print("Prosseguindo apenas se a raiz for public_html.")

        print("Iniciando upload...")
        for f in files_to_upload:
            if os.path.exists(f):
                print(f"Enviando -> {f}")
                with open(f, 'rb') as file_obj:
                    ftp.storbinary(f'STOR {f}', file_obj)
            else:
                print(f"[Aviso] Ignorado: {f} (não encontrado)")

        print("Limpando arquivos legados no servidor...")
        for f in files_to_delete:
            try:
                ftp.delete(f)
                print(f"Deletado do servidor -> {f}")
            except Exception:
                pass

        
        ftp.quit()
        print("=" * 35)
        print("Deploy finalizado com sucesso!")
        print("Sistema disponível online em seu servidor.")
        print("=" * 35)
    except Exception as e:
        print(f"Erro no deploy via FTP: {e}")

if __name__ == '__main__':
    deploy()
