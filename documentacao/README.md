# Documentação — Telium PABX

Guias para quem instala, atualiza e mantém a central.

| Documento | Para quê |
|---|---|
| [01 — Instalação](01-INSTALACAO.md) | Servidor novo, do zero até o primeiro login |
| [02 — Atualização](02-ATUALIZACAO.md) | Levar uma central que já está no ar para a versão nova do Git |
| [03 — Backup e restauração](03-BACKUP-E-RESTAURACAO.md) | Antes de mexer, e para voltar atrás |
| [04 — Verificação e problemas comuns](04-VERIFICACAO-E-PROBLEMAS.md) | Conferir se está tudo de pé e o que fazer quando não está |

## Onde cada coisa fica no servidor

Vale para todos os guias.

| O quê | Onde |
|---|---|
| Clone do Git (de onde o playbook roda) | a pasta onde você clonou — na VPS de produção, `/opt/TeliumPABX` |
| API instalada (o CLI `telium`) | `/opt/telium/api` |
| Console (HTML, JS, CSS) | `/opt/telium/web` |
| Usuário de sistema da API | `teliumpbx` |
| Configuração do Asterisk | `/etc/asterisk` (gerada: `/etc/asterisk/telium`) |
| Senhas de serviço (banco, AMI, TURN…) | `/etc/telium/credenciais` (só root) |
| Backups | `/var/backup/pabx-telium` |
| Gravações | `/var/spool/asterisk/monitor` |
| Áudios enviados pelo console | `/var/lib/asterisk/sounds/telium` |
| Logs da API | `/var/log/telium` |
| Logs do Asterisk | `/var/log/asterisk` |
| Banco de dados | MariaDB, base `telium_pabx` |

> **Clone ≠ instalação.** O `git pull` atualiza só o clone. É o playbook
> que copia o código novo para `/opt/telium`, aplica o banco e recarrega
> o Asterisk. `git pull` sem playbook não muda nada na central.

## Acesso ao console

| Endereço | Usuário | Senha |
|---|---|---|
| `https://<nome ou IP do servidor>/` | `admin` | `T3l1um_@2024_@aD1m` |

Essa é a senha **inicial**: troque-a no primeiro acesso. O playbook só a
define na primeira instalação, e as atualizações não voltam a mexer nela.
Perdeu a senha? No servidor:
`sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin`.

## Outros documentos do projeto

- [`docs/CADERNO-DE-TESTE.md`](../docs/CADERNO-DE-TESTE.md) — o caderno de
  aceitação: o que testar antes de entregar a central a um cliente.
- [`docs/PENDENCIAS.md`](../docs/PENDENCIAS.md) — o que ainda falta e o
  que é limitação conhecida.
- [`infra/README.md`](../infra/README.md) — referência técnica do
  provisionamento: variáveis, áudios em português, TLS, TURN.
