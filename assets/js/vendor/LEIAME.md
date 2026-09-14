# Bibliotecas de terceiros

Arquivos prontos, versionados de propósito: o console não tem etapa de
build, e um PABX instalado na rede do cliente não pode depender de CDN.

## jssip.min.js — JsSIP 3.13.8

O softphone do navegador fala SIP sobre WebSocket com o Asterisk. O JsSIP
não publica mais um bundle pronto no npm, então este arquivo é gerado uma
vez e commitado. Para refazer:

```sh
mkdir /tmp/jssip && cd /tmp/jssip
printf "import * as JsSIP from 'jssip';\nwindow.JsSIP = JsSIP;\n" > entrada.js
docker run --rm -v "$PWD:/b" -w /b node:22-alpine sh -c '
  npm init -y && npm install jssip@3.13.8 esbuild@0.24.0 &&
  npx esbuild entrada.js --bundle --minify --format=iife --target=es2020 \
      --outfile=jssip.min.js --legal-comments=none'
```

Licença MIT, do projeto JsSIP (https://jssip.net).

## qrcode.min.js — QR Code Generator 1.4.4

Desenha o QR Code da verificação em dois passos. O console não pode
depender de um serviço externo para isso: a URI do `otpauth://` carrega
o segredo do usuário, e mandá-la para o gerador de QR de terceiros seria
entregar a segunda barreira de propósito.

```sh
curl -sO https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js
docker run --rm -v "$PWD:/b" -w /b node:22-alpine sh -c '
  npm install -s esbuild@0.24.0 &&
  npx esbuild qrcode.js --minify --target=es2020 \
      --outfile=qrcode.min.js --legal-comments=inline'
```

Licença MIT, de Kazuhiko Arase (http://www.d-project.com/).
