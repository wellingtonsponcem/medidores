---
description: Realizar novo Deploy via FTP na WeDistinto (public_html/medidores)
---

Este workflow realiza a sincronização dos arquivos finalizados do frontend da Calculadora de Consumo no servidor FTP remoto.
Os dados da hospedagem (`147.93.38.189`, usuário `u306254544.wedistinto.com`, etc.) estão armazenados na constante do arquivo `deploy.js`.

// turbo
`python deploy.py`

Este comando subirá as versões revisadas do HTML, CSS e JavaScript para a pasta remota predefinida.
