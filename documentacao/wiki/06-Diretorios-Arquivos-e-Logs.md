# Diretórios, Arquivos e Logs — Telium PABX

Mapa do servidor da central: onde fica cada coisa, quem é dono, o que pode e o que não pode ser editado à mão, e onde estão os logs.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Resumo](#1-resumo)
2. [/opt/TeliumPABX e /opt/telium](#2-optteliumpabx-e-opttelium)
3. [/etc/telium](#3-etctelium)
4. [/etc/asterisk](#4-etcasterisk)
5. [Certificados](#5-certificados)
6. [/var/lib/asterisk](#6-varlibasterisk)
7. [/var/spool/asterisk](#7-varspoolasterisk)
8. [/var/Backup/pabx-telium](#8-varbackuppabx-telium)
9. [Logs](#9-logs)
10. [Outros arquivos do sistema](#10-outros-arquivos-do-sistema)
11. [Usuários, grupos e permissões](#11-usuários-grupos-e-permissões)
12. [Problemas comuns](#12-problemas-comuns)

---

## 1. Resumo

| O quê | Onde |
|---|---|
| Clone do Git (de onde o playbook roda) | `/opt/TeliumPABX` |
| Código publicado: API, console, scripts SQL | `/opt/telium` |
| Senhas de serviço, cofre de certificados | `/etc/telium` |
| Configuração do Asterisk | `/etc/asterisk` (gerada pelo console: `/etc/asterisk/telium`) |
| Certificado do console (nginx) | `/etc/ssl/telium` |
| Áudios enviados pelo console | `/var/lib/asterisk/sounds/telium` |
| Gravações | `/var/spool/asterisk/monitor` |
| Correio de voz e caixas postais | `/var/spool/asterisk/voicemail` |
| Backups do console | `/var/Backup/pabx-telium` |
| Logs da API | `/var/log/telium` |
| Logs do Asterisk | `/var/log/asterisk` |
| Banco | MariaDB, base `telium_pabx` |

---

## 2. /opt/TeliumPABX e /opt/telium

São duas coisas diferentes:

| | `/opt/TeliumPABX` | `/opt/telium` |
|---|---|---|
| O que é | O **clone do Git** | A **instalação** em uso |
| Quem atualiza | `git pull` | O playbook do Ansible, copiando do clone |
| Dono | root | `teliumpbx` |
| Usado pela central em funcionamento? | Não | Sim |

> **Clone ≠ instalação.** O `git pull` só atualiza o clone. É o playbook que copia o código novo para `/opt/telium`. Veja [Atualização](02-Atualizacao.md).

### O que importa no clone

| Caminho | Conteúdo |
|---|---|
| `infra/ansible/site.yml` | O playbook de instalação e atualização |
| `infra/ansible/verificar.yml` | O playbook de verificação |
| `infra/ansible/group_vars/all/main.yml` | Variáveis de fábrica (não edite: o `git pull` recusa) |
| `infra/ansible/group_vars/all/servidor.yml` | Variáveis **deste** servidor (nome da central etc.). Fora do Git; criado pela instalação |
| `infra/ansible/group_vars/all/vault.yml` | Senhas fixas, só se alguém gerou com `gerar-vault.sh`. Fora do Git |
| `infra/sql/` | Scripts do banco (origem dos publicados) |
| `infra/diagnostico.sh`, `infra/diagnostico-ami.sh` | Scripts de diagnóstico |

### O que tem na instalação

| Caminho | Conteúdo |
|---|---|
| `/opt/telium/web` | Console: `index.html`, `app.html`, `assets/`. Raiz do nginx |
| `/opt/telium/web/uploads/contatos` | Fotos da agenda de contatos |
| `/opt/telium/api` | API PHP (Slim): `bin/telium`, `public/`, `src/`, `vendor/` |
| `/opt/telium/api/COMMIT` | O commit do clone publicado (ex.: `ae4f854`), gravado pelo playbook a cada execução. É o que o console mostra ao lado do número da versão (`1.1.0 (ae4f854)`). Um `+` no fim indica que o clone tinha alteração fora do Git |
| `/opt/telium/api/CHANGELOG.md` | Histórico de versões, mostrado em **Telium › Versões e Novidades** |
| `/opt/telium/api/.env` | Configuração da API, **com senhas** (banco, AMI, ARI, segredo do TURN). `0640`, `teliumpbx` |
| `/opt/telium/api/var/cache` | Cache da API |
| `/opt/telium/api/var/migracao-snep` | O pacote (ou o dump) do SNEP enviado em Telium › Migração do SNEP, com os áudios e `.conf` dele, até a importação (ou por um dia). Tem as senhas SIP do SNEP: só o usuário da API lê |
| `/opt/telium/sql` | Scripts do banco usados por `telium migrar` |

> Não edite nada em `/opt/telium`: a próxima atualização sobrescreve. O `.env` é regerado pelo playbook a partir das variáveis.

---

## 3. /etc/telium

| Caminho | Conteúdo | Dono / modo |
|---|---|---|
| `/etc/telium/credenciais/` | Senhas de serviço geradas na instalação, uma por arquivo: `db_senha`, `db_cdr_senha`, `ami_senha`, `ari_senha`, `turn_segredo` | root, pasta `0700` |
| `/etc/telium/certificados/` | Cofre dos certificados enviados pelo console | `teliumpbx`, `0700` |
| `/etc/telium/msmtprc` | Configuração de e-mail usada pela API (gerada pelo `telium-smtp`) | `teliumpbx`, `0600` |
| `/etc/telium/instalacao.txt` | Resumo da instalação | root, `0600` |
| `/etc/telium/credenciais/senha_admin_console` | A senha inicial do admin do console e do Asternic, digitada na instalação (não fica no Git) | root, `0600` |
| `/etc/telium/.senha-admin-inicial` | Marca de que a senha inicial já foi definida (o playbook não volta a mexer nela) | |

- As senhas de serviço nunca aparecem na tela nem vão para o Git.
- Se existir `vault.yml` no clone, as senhas dele têm precedência sobre as de `credenciais/`.
- O playbook **reaproveita** os arquivos de `credenciais/` que já existem. Apagá-los gera senhas novas na próxima execução.

---

## 4. /etc/asterisk

Três grupos de arquivos, com regras diferentes.

### 4.1 Gerados pelo console: /etc/asterisk/telium

Escritos pela API a cada **Aplicar configurações** (ou `telium aplicar-config`), a partir do banco. **Não edite à mão**: a próxima aplicação desfaz.

| Arquivo | Conteúdo |
|---|---|
| `pjsip.endpoints.conf` | Ramais |
| `pjsip.trunks.conf` | Troncos |
| `pjsip.transports.conf` | Transportes (portas, IP público) |
| `pjsip.acl.conf` | Redes permitidas |
| `rtp.ice.conf` | Candidatos ICE (IP público para o áudio) |
| `extensions.ramais.conf`, `extensions.grupos.conf`, `extensions.filas.conf` | Ramais, grupos de toque, filas |
| `extensions.entrada.conf`, `extensions.saida.conf` | Rotas de entrada e de saída |
| `extensions.ura.conf`, `extensions.condicoes.conf` | URA e condições de horário |
| `extensions.recursos.conf` | Códigos de recurso (`*…`) |
| `extensions.callcenter.conf` | Call center |
| `extensions.conferencias.conf`, `extensions.paging.conf`, `extensions.disa.conf`, `extensions.estacionamento.conf` | Conferência, megafonia, DISA, estacionamento |
| `extensions.anuncios.conf`, `extensions.pesquisa.conf`, `extensions.confirmacao.conf` | Anúncios, pesquisa de satisfação, confirmação |
| `extensions.listanegra.conf`, `extensions.allowlist.conf` | Lista negra e lista permitida |
| `queues.conf`, `queuerules.conf` | Filas |
| `voicemail.conf`, `voicemail.geral.conf` | Correio de voz e caixas postais (contexto `telium`) |
| `confbridge.conf`, `res_parking.conf`, `features.conf`, `musicaespera.conf` | Conferência, estacionamento, códigos de recurso, música em espera |
| `pin-<id>.txt` | Conjuntos de PIN |

Pasta com dono `teliumpbx`, grupo `asterisk`, modo `2775` (os arquivos novos nascem no grupo `asterisk`).

### 4.2 Personalização: arquivos `_custom`

Criados vazios na instalação e **nunca sobrescritos** pelo console nem pelo playbook (o playbook só os cria quando não existem). É o lugar para um ajuste que o console não oferece. Cada arquivo do Asterisk gerado pelo console tem o seu, incluído **no fim** do arquivo correspondente; por isso o conteúdo continua na última seção aberta antes dele, a não ser que comece com o nome de uma seção (`[nome]`):

| Arquivo | Incluído em | Onde o conteúdo continua |
|---|---|---|
| `/etc/asterisk/telium/pjsip_custom.conf` | `pjsip.conf`, depois dos ramais e troncos | Comece cada objeto com `[nome]` e `type = …` |
| `/etc/asterisk/telium/extensions_custom.conf` | `extensions.conf`, no fim | Comece com o contexto: `[telium-custom]` (já incluído no contexto interno) ou um contexto próprio, alcançado por um **destino personalizado** |
| `/etc/asterisk/telium/queues_custom.conf` | `telium/queues.conf`, depois das filas | Comece com `[nome-da-fila]` |
| `/etc/asterisk/telium/queuerules_custom.conf` | `queuerules.conf`, depois das regras geradas | Comece com `[nome-da-regra]` |
| `/etc/asterisk/telium/confbridge_custom.conf` | `telium/confbridge.conf`, depois das salas | Comece com `[nome]` (perfil de sala, de usuário ou menu) |
| `/etc/asterisk/telium/voicemail_custom.conf` | `voicemail.conf`, no fim | Dentro do contexto `[telium]`: caixas a mais vão direto, uma por linha |
| `/etc/asterisk/telium/features_custom.conf` | `features.conf`, no fim | Dentro do `[featuremap]`; para `[applicationmap]`, comece com ele |
| `/etc/asterisk/telium/musiconhold_custom.conf` | `musiconhold.conf`, depois das classes do console | Comece com `[nome-da-classe]` |
| `/etc/asterisk/telium/res_parking_custom.conf` | `res_parking.conf`, depois dos lotes do console | Comece com `[nome-do-lote]` |
| `/etc/asterisk/telium/rtp_custom.conf` | `rtp.conf`, no fim | Dentro do `[general]` |
| `/etc/asterisk/manager_custom.conf` | `manager.conf` (AMI), no fim | Comece cada usuário com `[nome]`, e permita só os IPs necessários |
| `/etc/asterisk/ari_custom.conf` | `ari.conf`, no fim | Comece cada usuário com `[nome]` |

- Os de `telium/` são `teliumpbx:asterisk`, como os gerados. A tela **Configurações › Arquivos e Aplicação** edita o que está em `/etc/asterisk/telium`.
- O `manager_custom.conf` e o `ari_custom.conf` ficam **fora** de `/etc/asterisk/telium` de propósito, com dono `root:asterisk` e modo `0640`: um usuário do AMI com permissão de `command` ou `originate` tem poder de root sobre a central, e não pode ser criado pelo navegador. Só quem tem SSH mexe neles. São incluídos com `#tryinclude`: se faltarem, o AMI e o ARI sobem do mesmo jeito.
- A restauração de backup não devolve o `manager.conf`, o `ari.conf` e o `res_odbc.conf` (ficam os deste servidor), mas devolve os `_custom` junto com o resto de `/etc/asterisk`.
- Depois de editar um `_custom`, aplique: **Aplicar configurações** no console (ou `telium aplicar-config`). Para o AMI e o ARI, `sudo asterisk -rx "manager reload"` e `sudo asterisk -rx "module reload res_ari.so"`.

### 4.3 Publicados pelo Ansible: /etc/asterisk

Vêm de modelos do playbook e são **reescritos a cada execução**. Também não edite à mão.

| Arquivos | Observação |
|---|---|
| `asterisk.conf`, `modules.conf` | Só valem com o Asterisk reiniciando; o playbook agenda `core restart when convenient` |
| `extensions.conf`, `pjsip.conf`, `queues.conf`, `queuerules.conf`, `voicemail.conf`, `confbridge.conf`, `res_parking.conf`, `features.conf`, `musiconhold.conf`, `rtp.conf` | Base fixa que faz `#include` dos gerados em `telium/` e do `_custom` de cada um |
| `manager.conf` (AMI), `ari.conf`, `http.conf` | Com as senhas de serviço. `manager.conf` e `ari.conf` incluem `manager_custom.conf` e `ari_custom.conf` |
| `res_odbc.conf`, `func_odbc.conf`, `cdr.conf`, `cdr_adaptive_odbc.conf`, `cel.conf`, `cel_odbc.conf`, `extconfig.conf` | Ligação com o banco: CDR, CEL e `queue_log` |
| `logger.conf` | Arquivos de log do Asterisk |
| `indications.conf`, `udptl.conf`, `pjproject.conf`, `resolver_unbound.conf`, `res_fax.conf`, `acl.conf`, `ccss.conf`, `codecs.conf`, `amd.conf`, `websocket_client.conf`, `pjsip_notify.conf` | Demais módulos |

O playbook apaga `users.conf`, `extensions.ael` e `extensions.lua` (exemplos que só geravam aviso).

> Um `#include` de arquivo que não existe derruba o dialplan inteiro. Não apague arquivos de `/etc/asterisk/telium`; se sumiram, rode o playbook ou `telium aplicar-config`.

---

## 5. Certificados

| Caminho | Uso | Dono / grupo |
|---|---|---|
| `/etc/ssl/telium/pabx.crt` e `pabx.key` | Par **ativo** do nginx (console) | root / `ssl-cert` |
| `/etc/ssl/telium/fabrica.crt` e `fabrica.key` | Par **de fábrica** (autoassinado). Nunca é substituído: é para onde o sistema volta | root / `ssl-cert` |
| `/etc/asterisk/keys/asterisk.crt` e `asterisk.key` | SIP TLS (porta 5061) | root / `asterisk` |
| `/etc/coturn/turn.crt` e `turn.key` | TURN sobre TLS (porta 5349) | root / `turnserver` |
| `/etc/janus/certs/janus.crt` e `janus.key` | Janus, só se instalado | `janus` |
| `/etc/telium/certificados/` | Cofre: o que foi enviado pelo console, antes de aplicar | `teliumpbx` |

Troque certificados pelo console (**Administrador › Certificados**): o `telium-certificados` guarda o par anterior como `.anterior` e o devolve se o serviço recusar o novo.

---

## 6. /var/lib/asterisk

| Caminho | Conteúdo |
|---|---|
| `sounds/` | Áudios de fábrica do Asterisk (inglês, na raiz) |
| `sounds/pt_BR/` | Áudios em português que acompanham o projeto |
| `sounds/telium-cc/` | Áudios do call center (login, pausa, saída do agente) |
| `sounds/telium/` | **Áudios enviados pelo console**: anúncios, URA. Dono `teliumpbx`, grupo `asterisk` |
| `sounds/telium/moh/<classe>/` | Músicas de espera enviadas pelo console, uma pasta por classe |
| `moh/` | Música de espera padrão do Asterisk |
| `astdb.sqlite3` | Base interna do Asterisk: siga-me, não perturbe, despertador do `*68`, listas negra/permitida e outros estados dos códigos do telefone. Também guarda quem pode mexer nas listas pelo telefone (famílias `listanegra-tel` e `allowlist-tel`, regravadas a cada **Aplicar configurações**). Vai no backup desde a 1.1.0 |
| `agi-bin/` | Diretório de AGI configurado; o Telium não instala scripts nele |
| `.msmtprc` | Configuração de e-mail do Asterisk (recado por e-mail), gerada pelo `telium-smtp`. `0600`, `asterisk` |

> O playbook corrige o dono de `sounds/` para `asterisk` e devolve `sounds/telium` ao `teliumpbx`. Se o console não consegue gravar áudio, rode o playbook.

---

## 7. /var/spool/asterisk

Tudo com dono `asterisk`, modo `0750`.

| Caminho | Conteúdo |
|---|---|
| `monitor/AAAA/MM/DD/` | Gravações de chamadas (`.wav`). O nginx entrega pelo caminho interno `/gravacoes/`, só com sessão autorizada pela API |
| `voicemail/telium/<caixa>/` | Recados e saudações do correio de voz dos ramais e das caixas postais |
| `fax/` | Faxes recebidos: `AAAAMMDD-HHMMSS-<número>.tif`, onde o número de quem mandou entra **só com dígitos**. Quando o fax vai por e-mail ([Configurações › Fax](19-Configuracoes.md)), o PDF convertido fica ao lado (`.pdf`, mesmo nome). Os dois ficam no servidor depois do envio |
| `dictate/<ramal>/` | Ditados |
| `tmp/` | Temporários do Asterisk |

> As gravações não se apagam à mão pelo console. Com um **prazo de guarda** em **Aplicações › Gravação de Chamadas**, o executor de backup apaga uma vez por dia as gravações com mais dias que o prazo (e as pastas que ficam vazias). O padrão é zero: guarda para sempre. Controle o espaço em disco (`df -h /var/spool/asterisk`).

---

## 8. /var/Backup/pabx-telium

- Arquivos `telium-AAAAMMDD-HHMMSS.tar.gz` gerados por **Administrador › Backup & Restauração**.
- Pasta `root:teliumpbx` `0750` e arquivos `root:teliumpbx` `0640`: a API lê (para o **Baixar** e o envio ao destino remoto), mas só o root escreve. Os arquivos contêm senhas e chaves. O executor de backup corrige a permissão a cada rodada.
- A retenção apaga os mais antigos de cada rotina (os manuais contam entre si).
- O **Excluir** do console não apaga na hora: marca o backup e o executor, que roda como root, apaga o arquivo e o registro em até um minuto.

Detalhes em [Backup e Restauração](04-Backup-e-Restauracao.md).

---

## 9. Logs

| Log | Conteúdo | Rotação |
|---|---|---|
| `/var/log/asterisk/messages` | Avisos e erros do Asterisk. O console lê este | Diária, 14 cópias, até 200 MB |
| `/var/log/asterisk/full` | Tudo, com verbose 3 e DTMF | Diária, 14 cópias, até 200 MB |
| `/var/log/asterisk/security` | Eventos de segurança (senha errada no SIP). O **fail2ban** lê este | Diária, 14 cópias, até 200 MB |
| `/var/log/telium/php-api.log` | Erros da API (PHP-FPM) | Semanal, 8 cópias, até 100 MB |
| `/var/log/telium-smtp.log` | Envio de e-mail (msmtp) | Semanal, 8 cópias, até 100 MB |
| `/var/log/nginx/access.log` | Acessos ao console e à API. O fail2ban lê para o login do console | Do pacote nginx |
| `/var/log/nginx/error.log` | Erros do nginx | Do pacote nginx |
| `/var/log/mysql/error.log`, `slow.log` | Erros e consultas lentas (acima de 2 s) do MariaDB | — |
| journal do systemd | `telium-cc` (inclusive os erros de webhook), `telium-backup` (inclusive a limpeza das gravações e os alertas por e-mail), `telium-certificados`, `telium-despertador`, `telium-tarifar`, `coturn` | `journalctl -u <serviço>` |
| syslog, marca `telium-fax` | Envio do fax por e-mail: enviado, falha ou fax que não chegou | `journalctl -t telium-fax` |

- A rotação dos logs do Asterisk e da Telium está em `/etc/logrotate.d/asterisk`. Os logs do Asterisk são recriados como `asterisk` e o Asterisk é avisado (`logger reload`); se não fossem, o log pararia e o fail2ban junto.
- O `queue_log` das filas **não é arquivo**: vai para a tabela `queue_log` do banco.
- No acesso de provisionamento (`/p/…`), o nginx não grava o caminho no log, porque o caminho é o token do aparelho.
- Com o syslog central ligado (`syslog_central`), uma cópia dos logs do Asterisk, de autenticação e do sistema vai por UDP para o syslog do grupo VoIP (regras em `/etc/rsyslog.d/`). Os arquivos locais continuam.

---

## 10. Outros arquivos do sistema

| Caminho | O que é |
|---|---|
| `/etc/nginx/nginx.conf`, `/etc/nginx/conf.d/telium-pabx.conf` | nginx principal e site do PABX |
| `/etc/php/8.4/fpm/pool.d/telium.conf` | Pool do PHP-FPM da API (socket `/run/php/telium.sock`) |
| `/etc/mysql/mariadb.conf.d/60-telium.cnf` | Ajustes do MariaDB (só `127.0.0.1`, utf8mb4) |
| `/etc/odbc.ini`, `/etc/odbcinst.ini` | Conexão ODBC do Asterisk com o banco |
| `/etc/turnserver.conf`, `/etc/default/coturn` | coturn |
| `/etc/fail2ban/jail.d/telium.conf`, `/etc/fail2ban/filter.d/telium-console.conf` | Jails e filtro do console |
| `/etc/fail2ban/jail.d/telium-confiaveis.local` | Lista "Nunca bloquear" do console (gerado; não edite) |
| `/etc/systemd/system/asterisk.service`, `telium-cc.service`, `telium-*.service` e `.timer` | Serviços e temporizadores ([Comandos e serviços](05-Servidor-Comandos-e-Servicos.md)) |
| `/usr/local/sbin/telium-*` | Scripts executores (backup, certificados, smtp, fail2ban, despertador) e o `telium-fax-email`, que o Asterisk chama para mandar o fax recebido por e-mail |
| `/var/lib/telium/despertador/` | Estado das novas tentativas do despertador: um arquivo por despertar em curso (ramal, anúncio, tentativas restantes, próxima tentativa). Some quando a pessoa atende ou as tentativas acabam. Dono root |
| `/opt/janus` | Janus (opcional): binário e configuração em `/opt/janus/etc/janus` |
| `/usr/local/src/telium` | Fontes baixados na instalação (Asterisk, áudios) |
| `/var/lib/php/sessions` | Sessões do PHP |

Arquivos com "Gerado pelo Ansible — Não edite à mão" voltam ao original na próxima execução do playbook.

---

## 11. Usuários, grupos e permissões

| Usuário | Para quê |
|---|---|
| `teliumpbx` (grupo `teliumpbx`) | Roda a API (PHP-FPM), o `telium-cc` e o `telium tarifar`. Sem shell de login (`/usr/sbin/nologin`), home `/opt/telium`. Faz parte do grupo `asterisk` para escrever em `/etc/asterisk/telium` |
| `asterisk` | Roda o Asterisk. Sem shell de login, home `/var/lib/asterisk` |
| `nginx` | Roda o nginx; dono do socket do PHP-FPM; está no grupo `ssl-cert` para ler o certificado |
| `turnserver` | Roda o coturn |

### Regras de sudo do projeto

Cada uma permite ao `teliumpbx` rodar **um** programa como root, sem curinga:

| Arquivo | Permite |
|---|---|
| `/etc/sudoers.d/telium-backup` | `/usr/local/sbin/telium-backup` |
| `/etc/sudoers.d/telium-certificados` | `/usr/local/sbin/telium-certificados` |
| `/etc/sudoers.d/telium-smtp` | `/usr/local/sbin/telium-smtp` |
| `/etc/sudoers.d/telium-fail2ban` | `/usr/local/sbin/telium-fail2ban` |

Os executores recebem o pedido pelo banco, não por argumento (a exceção é o `telium-fail2ban`, que valida a jail e o IP).

---

## 12. Problemas comuns

### O console diz que não consegue gravar a configuração

O `doctor` mostra `escrita conf … sem permissão`. Confira:

```bash
ls -ld /etc/asterisk /etc/asterisk/telium
id teliumpbx
```

`/etc/asterisk/telium` deve ser `teliumpbx:asterisk` com `drwxrwsr-x`, e `teliumpbx` deve estar no grupo `asterisk`. Rodar o playbook corrige.

### O dialplan sumiu depois de mexer em /etc/asterisk

Quase sempre um arquivo do `#include` foi apagado ou renomeado:

```bash
sudo grep "does not exist" /var/log/asterisk/messages
```

Rode `sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config` para recriar os gerados.

### O log do Asterisk parou de crescer

O arquivo foi recriado com o dono errado. `ls -l /var/log/asterisk` deve mostrar `asterisk`. Corrija o dono e rode `sudo asterisk -rx "logger reload"`.

### O disco encheu

Os candidatos são `/var/spool/asterisk/monitor` (gravações), `/var/Backup/pabx-telium` (backups com gravações) e `/var/log`:

```bash
sudo du -sh /var/spool/asterisk/monitor /var/Backup/pabx-telium /var/log/asterisk
```

Para as gravações, defina o prazo de guarda em **Aplicações › Gravação de Chamadas**. Com o campo de alertas preenchido em **Configurações › Notificações e E-mail**, a central avisa por e-mail quando o disco das gravações passa de 90% de uso (menos de 10% livre).

---

## Asternic (opcional)

Só nas centrais com `instalar_asternic: true` ([Call Center › Stats](16-Call-Center.md)).

| Caminho | O que é |
|---|---|
| `/opt/asternic/stats` | A tela do Asternic, servida em `/stats/`. Dono `asternic`, grupo `nginx`. Guarda a licença (`asterniclic.php`) e o `config.php` |
| `/usr/local/parselog` | Os scripts do Asternic, entre eles o `asterniclog` |
| `/usr/local/ioncube` | O carregador ionCube, ligado só no PHP-FPM (`/etc/php/8.4/fpm/conf.d/00-ioncube.ini`) |
| `/etc/default/asterniclog` | Opções do serviço, com a senha do banco (só root) |
| `/etc/php/8.4/fpm/pool.d/asternic.conf` | O pool do PHP-FPM do Asternic |
| `/var/log/asterisk/queue_log` | O registro das filas em arquivo, que o Asternic lê |
| `/var/log/asternic/php.log` | Erros de PHP da tela do Asternic |
| `/var/lib/asternic/sessoes` | As sessões (logins) da tela do Asternic, separadas das do console |
| `/etc/telium/credenciais/asternic_*` | Senhas do banco e do AMI do Asternic. O admin do Asternic usa a senha do admin do console |
| `/etc/cron.d/asternic` | Cron dos relatórios agendados e alertas (`sched_check.php` a cada minuto) |
| `/var/log/asternic/agendados.log` | Erros dos relatórios agendados |
| `/var/log/asternic/historico.log` | A carga única do histórico de filas da central no Asternic |
| Banco `qstats` | Dados e configuração do Asternic |

