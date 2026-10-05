# Áudios da central

## `pt_BR.tar.xz` — avisos do sistema em português do Brasil

Os avisos que o Asterisk toca sozinho — correio de voz, conferência, fila,
números, "número inválido", "ativado" — em português. O projeto Asterisk
não publica áudios em português; estes vêm de:

- **Asterisk Sounds** (https://www.asterisksounds.org/pt-br), tradução dos
  áudios oficiais do Asterisk;
- reunidos por **Marcel Savegnago** em
  https://github.com/marcelsavegnago/issabel_sounds_pt_BR
  (commit `1d1b6a43e485697675d2cf43defc42c6c9c58566`).

**Licença:** Creative Commons Atribuição-CompartilhaIgual 3.0 — texto em
`LICENCA-pt_BR.txt`. Podem ser distribuídos e modificados, desde que com
esta atribuição e sob a mesma licença. A licença vale para os áudios, não
para o código do Telium PABX.

Formatos incluídos: `alaw` (operadoras no Brasil), `ulaw` (telefones) e
`gsm`. O `sln16` (alta definição, 146 MB) ficou de fora para o repositório
não pesar: para o softphone do navegador o Asterisk converte a partir do
alaw, com qualidade de telefone.

Para refazer o pacote a partir do repositório de origem:

```bash
tar -cJf pt_BR.tar.xz --exclude='*.sln16' --sort=name --owner=0 --group=0 \
  --numeric-owner --mtime='2026-10-05 00:00Z' -C <clone> pt_BR
```

**Acréscimos do Telium PABX.** A gramática do português no Asterisk pede
arquivos que o pacote de origem não tem com o nome que ela procura; sem
eles a fila dizia "duzentos e cinquenta" para 1250 e o correio de voz
parava a data em "às sete", sem os minutos. São cópias de gravações que já
estão no pacote, com o nome que o Asterisk pede:

| Pedido pelo Asterisk | Cópia de | Fala |
|---|---|---|
| `digits/100` | `digits/hundred` | cem |
| `digits/1000` | `digits/thousand` | mil |
| `digits/1000000` | `digits/million` | milhão |
| `digits/hours` | `hours` | horas |
| `digits/minutes` | `minutes` | minutos |

Para refazer o pacote, faça as mesmas cópias antes do `tar`.

O que falta nele em relação ao inglês (`basic-pbx-ivr-main`,
`confbridge-binaural-on/off`, `please-try-call-later`) não é tocado por
esta central; se algum dia for, o Asterisk usa o inglês, que continua
instalado na raiz de `sounds/`.

## `telium-cc/` — avisos do call center

Gravados para este projeto (login, pausa, agente inválido…).
