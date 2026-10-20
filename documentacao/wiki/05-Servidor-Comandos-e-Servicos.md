# Servidor: Comandos e Serviços — Telium PABX

Referência dos comandos úteis no servidor da central: o utilitário `telium`, os scripts instalados pelo Ansible, os serviços e temporizadores do systemd, e os comandos de Asterisk, MariaDB, firewall e logs.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Antes de começar](#1-antes-de-começar)
2. [O utilitário telium](#2-o-utilitário-telium)
3. [Scripts em /usr/local/sbin](#3-scripts-em-usrlocalsbin)
4. [Scripts de diagnóstico](#4-scripts-de-diagnóstico)
5. [Serviços](#5-serviços)
6. [Temporizadores (timers)](#6-temporizadores-timers)
7. [Asterisk](#7-asterisk)
8. [MariaDB](#8-mariadb)
9. [Firewall e fail2ban](#9-firewall-e-fail2ban)
10. [nginx e PHP](#10-nginx-e-php)
11. [Logs](#11-logs)
12. [Problemas comuns](#12-problemas-comuns)

---

## 1. Antes de começar

| O quê | Onde |
|---|---|
| Clone do Git (de onde o playbook roda) | `/opt/TeliumPABX` |
| Código publicado (o que está no ar) | `/opt/telium` |
| Utilitário `telium` | `/opt/telium/api/bin/telium` |
| Usuário de sistema da API | `teliumpbx` (sem login) |

- O `telium` roda **sempre como `teliumpbx`**: `sudo -u teliumpbx php /opt/telium/api/bin/telium <comando>`. Rodar como root cria arquivos com o dono errado.
- Os comandos de Asterisk, MariaDB e systemd pedem `sudo`.
- Pelo navegador, **Administrador › CLI Asterisk** dá um terminal do Asterisk sem SSH.

Mais detalhes sobre os caminhos em [Diretórios, arquivos e logs](06-Diretorios-Arquivos-e-Logs.md).

---

## 2. O utilitário telium

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium <comando>
```

Sem comando (ou com um desconhecido), mostra a versão e a ajuda, com todos os comandos abaixo.

| Comando | O que faz |
|---|---|
| `migrar` | Aplica os scripts SQL (`/opt/telium/sql`). Todos são idempotentes: reaplicar é seguro |
| `gerar-config` | Gera os `.conf` de `/etc/asterisk/telium` a partir do banco, sem recarregar |
| `aplicar-config` | Gera e recarrega o Asterisk. É o mesmo que **Aplicar configurações** no console |
| `doctor` | Testa banco, AMI, Janus (se houver) e a escrita em `/etc/asterisk/telium` |
| `senha <usuario>` | Define nova senha de um usuário do console |
| `desligar-2fa <usuario>` | Desliga a verificação em dois passos de um usuário (celular perdido) |
| `alertas` | Manda por e-mail os alertas do sistema pendentes (backup, certificado, disco) |
| `testar` | Roda a bateria de testes |
| `backup-enviar <id>` | Envia um backup ao destino remoto |
| `tarifar [AAAA-MM]` | Calcula o custo das chamadas ainda sem custo |
| `cc-servico` | Serviço de tempo real do call center (quem roda é o `telium-cc`) |

### migrar

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium migrar
```

- Procura os `.sql` em `SQL_DIR` do `.env` (`/opt/telium/sql`) e aplica todos, em ordem.
- Mostra `✓` ou `✗` por arquivo e termina com `✓ banco atualizado`. Com erro, sai com código 1.
- O playbook já roda as migrações; use à mão só quando orientado.

### gerar-config e aplicar-config

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium gerar-config
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config
```

- `gerar-config` lista cada arquivo com `inalterado`, `criado` ou `atualizado`. Com mudança, marca a configuração como pendente (a faixa amarela do console).
- `aplicar-config` gera e então: sincroniza as listas na base do Asterisk (lista negra, permitida, não perturbe, chamada em espera…) e recarrega, um a um, ramais e troncos (`res_pjsip`), dialplan, filas, correio de voz, conferências, estacionamento, música em espera, códigos de recurso, colunas do CDR e candidatos ICE. Por fim confere se todos os ramais e troncos carregaram.
- Cada etapa sai como `ok` ou `falhou`. Uma falha lista o ramal/tronco que o Asterisk recusou.
- Só uma geração por vez: com outra em andamento, o comando avisa e não faz nada.

### doctor

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium doctor
```

| Linha | Confere |
|---|---|
| `banco` | Conecta no MariaDB e conta os ramais |
| `ami` | Faz login no AMI e lê a versão do Asterisk |
| `janus` | Só se o Janus responder em `127.0.0.1:8088`; senão mostra "não instalado (opcional)" |
| `escrita conf` | Se a API consegue escrever em `/etc/asterisk/telium` |

Sai com código 0 se tudo passou.

### senha

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin
```

- No terminal, pede a senha sem mostrar. Também aceita pela entrada padrão: `echo 'NovaSenha' | sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin`.
- A senha passa pela mesma regra de força do console.
- Derruba as sessões abertas do usuário e zera o bloqueio por tentativas erradas.
- É o caminho para quem perdeu a senha do `admin`.
- **Não** desliga a verificação em dois passos: para isso, use `desligar-2fa`.

### desligar-2fa

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium desligar-2fa admin
```

- Para quando o único administrador perdeu o celular do autenticador e não consegue entrar no console.
- Desliga a verificação em dois passos do usuário, derruba as sessões dele e registra na auditoria (`2fa_desligado`, origem terminal).
- Depois de entrar, a pessoa liga a verificação de novo em **Meu Perfil e Segurança**.

### alertas

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium alertas
```

- Confere e manda por e-mail os alertas do sistema para os endereços de **Configurações › Notificações e E-mail › Mandar os alertas do sistema para** ([Configurações](19-Configuracoes.md)).
- São quatro: **backup que falhou** (nas últimas 24 h), **nenhum backup concluído há mais de 26 horas** com alguma rotina ligada, **certificado do console** (o que o nginx apresenta na porta 443) **a menos de 15 dias de vencer** ou vencido, e **disco das gravações com menos de 10% livre**.
- Cada alerta sai no máximo **uma vez por dia**; o de backup com falha, uma vez por backup. A marca do último envio fica na tabela `sistema` (chaves `alerta_…`).
- Não manda nada se o envio de e-mail estiver desligado ou o campo dos alertas estiver em branco.
- Mostra o assunto de cada alerta enviado, ou `nenhum aviso a mandar`.
- Não precisa rodar à mão: o executor de backup (`telium-backup`) o chama de hora em hora (no minuto 07) e logo depois de um backup que falha. Rodar à mão serve para testar o envio.

### testar

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

Bateria de verificação da instalação (banco, configuração gerada, Asterisk, áudios, permissões, certificados, segurança da API e outros). Termina com `✓ N testes, nenhuma falha` ou lista o que falhou, saindo com código 1. O playbook roda a bateria no fim e para se ela falhar. Sem banco, roda só os testes que não dependem dele.

### backup-enviar

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium backup-enviar 42
```

| Código de saída | Significado |
|---|---|
| 0 | Enviado, ou nenhum destino remoto ligado |
| 1 | Falha no envio (ou backup não encontrado) |
| 64 | Uso errado (sem id) |

O resultado fica registrado e aparece na tela de backup. Veja [Backup e Restauração](04-Backup-e-Restauracao.md).

### tarifar

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium tarifar            # o que falta, de qualquer mês
sudo -u teliumpbx php /opt/telium/api/bin/telium tarifar 2026-09    # só aquele mês
```

- Chamada já tarifada não é recalculada. Para refazer um mês inteiro com os preços de agora, use **Recalcular o mês** em **Telium › Tarifação** ([Tarifação](20-Telium-Tarifacao-Integracoes-Suporte.md)).
- Avisa quando todas as tarifas estão com preço zero e quando há chamadas sem tarifa que se aplique.
- Roda sozinho de hora em hora pelo `telium-tarifar.timer`.

### cc-servico

Escuta os eventos de fila do Asterisk e alimenta os painéis do call center em `127.0.0.1:8095`. Também é ele que manda os **webhooks** de chamada (`chamada.tocando`, `chamada.atendida`, `chamada.encerrada`) cadastrados em **Telium › Integrações / API**. **Não rode à mão**: quem o mantém no ar é o serviço `telium-cc` ([seção 5](#5-serviços)).

---

## 3. Scripts em /usr/local/sbin

Instalados pelo Ansible. Quase todos rodam como root e **não recebem argumentos** (exceto o `telium-fail2ban`): leem o que fazer no banco. A API os aciona por regras de sudo próprias em `/etc/sudoers.d`. O `telium-fax-email` é a exceção: quem o chama é o Asterisk, com argumentos.

| Script | Para que serve | Quem aciona |
|---|---|---|
| `telium-backup` | Executa a restauração pedida, apaga os backups marcados para excluir, roda as rotinas vencidas e os backups na fila; uma vez por dia, apaga as gravações que passaram do prazo de guarda; de hora em hora (no minuto 07) e logo depois de um backup que falha, roda `telium alertas` | Console + `telium-backup.timer` |
| `telium-certificados` | Aplica certificados pendentes no nginx, Asterisk (SIP TLS), coturn e Janus; volta o anterior se o serviço recusar | Console + `telium-certificados.timer` |
| `telium-smtp` | Gera a configuração do msmtp (envio de recado por e-mail) a partir da tabela `smtp` | Console (ao salvar o e-mail) e playbook |
| `telium-fail2ban` | Ponte do console com o fail2ban: lista e desbane IPs, aplica a lista "Nunca bloquear" | Console (Conectividade › Firewall) e playbook |
| `telium-despertador` | Liga para os ramais com despertador marcado (pelo `*68` ou pelo console). Se ninguém atende, tenta de novo: o despertador do console, quantas vezes e com o intervalo do cadastro; o do `*68`, mais duas vezes, a cada dois minutos. As tentativas em curso ficam em `/var/lib/telium/despertador`, e o resultado (atendeu ou não atendeu) vai para o cadastro | `telium-despertador.timer` |
| `telium-fax-email` | Manda o fax recebido por e-mail: converte o TIFF em PDF (com o `tiff2pdf`, do pacote `libtiff-tools`; sem ele, vai o TIFF) e envia pelo mesmo `sendmail` (msmtp) do recado do correio de voz. Só aceita arquivo em `/var/spool/asterisk/fax` e registra o resultado no syslog com a marca `telium-fax` | O Asterisk, ao fim de cada fax recebido, quando há e-mail em **Configurações › Fax** (roda como `asterisk`) |

Uso à mão:

```bash
sudo /usr/local/sbin/telium-backup                   # processa o que estiver pendente (não cria backup novo)
sudo /usr/local/sbin/telium-certificados             # aplica atribuições pendentes
sudo /usr/local/sbin/telium-certificados servicos    # lista os serviços que sabe atualizar
sudo /usr/local/sbin/telium-smtp                     # republica a configuração de e-mail
sudo /usr/local/sbin/telium-fail2ban listar          # banidos, em JSON
sudo /usr/local/sbin/telium-fail2ban desbanir asterisk 203.0.113.10
sudo /usr/local/sbin/telium-fail2ban confiaveis      # reaplica a lista "Nunca bloquear"
```

> Os scripts são gerados pelo playbook ("Não edite à mão"). Mudança feita direto neles some na próxima atualização.

---

## 4. Scripts de diagnóstico

Ficam no clone, não no código publicado.

| Comando | Para quê |
|---|---|
| `sudo bash /opt/TeliumPABX/infra/diagnostico.sh` | Relatório curto: serviços, versões, portas em escuta, contagens do banco, endpoints e ODBC do Asterisk, Janus, `/api/health`, arquivos gerados e erros recentes. A saída é feita para colar inteira num chamado |
| `sudo bash /opt/TeliumPABX/infra/diagnostico-ami.sh` | Quando o AMI recusa o login: confere a porta 5038, os usuários do manager, compara a senha do `manager.conf` com a origem usada pelo Ansible (sem mostrá-la inteira), tenta um login real e mostra o `manager.conf` com a senha mascarada |
| `cd /opt/TeliumPABX/infra/ansible && sudo ansible-playbook verificar.yml` | Diagnóstico completo pelo Ansible. É a saída a mandar ao pedir ajuda |

> O `diagnostico.sh` confere `janus` na lista de serviços; numa central sem Janus ele aparece como inativo, o que é normal.

---

## 5. Serviços

| Serviço | O que faz | Observação |
|---|---|---|
| `asterisk` | A central telefônica | Roda como `asterisk`. `systemctl reload asterisk` faz `core reload` |
| `telium-cc` | Tempo real do call center: painéis, códigos de agente no telefone, retornos; e o envio dos webhooks de chamada | Roda como `teliumpbx`, reinicia sozinho se cair. Sem ele o console funciona, mas o telefone não loga agente |
| `mariadb` | Banco `telium_pabx` | Escuta só em `127.0.0.1` |
| `nginx` | HTTPS do console, API, `/ws` do softphone | |
| `php8.4-fpm` | Executa a API (pool `telium`, socket `/run/php/telium.sock`) | |
| `coturn` | TURN do softphone do navegador | Instalado por padrão (`instalar_turn`) |
| `fail2ban` | Bane IPs que erram senha (SIP, console, SSH) | |
| `janus` | Gateway WebRTC **opcional** | Só existe com `instalar_janus=true`; o softphone não precisa dele |
| `asterniclog` | Asternic (**opcional**): lê `/var/log/asterisk/queue_log` para o banco `qstats` | Só existe com `instalar_asternic: true`. O Asternic roda no pool `asternic` do PHP-FPM (`/run/php/asternic.sock`) |

Comandos:

```bash
systemctl is-active mariadb nginx php8.4-fpm asterisk telium-cc coturn fail2ban
sudo systemctl status asterisk
sudo systemctl restart telium-cc
journalctl -u telium-cc -f
journalctl -u asterisk -n 100 --no-pager
```

> ⚠️ `systemctl restart asterisk` **derruba todas as chamadas**. Para reiniciar sem cortar ninguém: `sudo asterisk -rx "core restart when convenient"` (espera a central ficar sem chamadas).

O Asterisk depende do MariaDB de forma fraca (`Wants`): reiniciar o MariaDB não reinicia o Asterisk.

---

## 6. Temporizadores (timers)

| Timer | Executa | Quando (`OnCalendar`) |
|---|---|---|
| `telium-backup.timer` | `telium-backup.service` → `/usr/local/sbin/telium-backup` (e, por ele, a limpeza diária das gravações e os alertas de hora em hora) | A cada minuto (`*:0/1`) |
| `telium-certificados.timer` | `telium-certificados.service` → `/usr/local/sbin/telium-certificados` | A cada minuto (`*:0/1`) |
| `telium-despertador.timer` | `telium-despertador.service` → `/usr/local/sbin/telium-despertador` | A cada minuto (`*:0/1`) |
| `telium-tarifar.timer` | `telium-tarifar.service` → `telium tarifar` (como `teliumpbx`) | De hora em hora (`hourly`) |

Rodar a cada minuto não quer dizer trabalhar a cada minuto: cada um olha o banco e sai com "nada a fazer" quando não há pendência.

```bash
systemctl list-timers 'telium-*'
journalctl -u telium-backup -n 50 --no-pager
journalctl -u telium-certificados -n 50 --no-pager
journalctl -u telium-despertador -n 50 --no-pager
journalctl -u telium-tarifar -n 50 --no-pager
sudo systemctl start telium-tarifar.service      # roda agora, fora de hora
```

> 📷 **Print:** saída de `systemctl list-timers 'telium-*'`.

---

## 7. Asterisk

Entrar no console do Asterisk: `sudo asterisk -rvvv` (sair com `exit`). Um comando avulso: `sudo asterisk -rx "<comando>"`.

### Estado geral

| Comando | Mostra |
|---|---|
| `core show uptime` | Há quanto tempo está no ar e o último reload |
| `core show version` | Versão |
| `core show channels` | Chamadas em curso |
| `core show settings` | Configuração em uso (idioma, limites) |

### Ramais e troncos (PJSIP)

| Comando | Mostra |
|---|---|
| `pjsip show endpoints` | Ramais e troncos carregados e o estado |
| `pjsip show endpoint 1001` | Detalhe de um ramal |
| `pjsip show contacts` | Aparelhos registrados (IP, latência) |
| `pjsip show registrations` | Registro da central na operadora |
| `pjsip show transports` | Transportes udp, tcp, tls e wss |
| `pjsip set logger on` / `off` | Mostra as mensagens SIP no console (depuração) |
| `rtp set debug on` / `off` | Mostra os pacotes de áudio (depuração; muito verboso) |

> O `sip set debug` não existe nesta central: o Asterisk 22 só tem PJSIP. Use `pjsip set logger on`.

### Filas, dialplan e recarga

| Comando | Para quê |
|---|---|
| `queue show` | Filas, membros e quem espera |
| `queue show <fila>` | Uma fila |
| `dialplan show telium-ramais` | Um contexto do dialplan |
| `dialplan reload` | Relê o dialplan |
| `core reload` | Relê toda a configuração |
| `database show <família>` | Base interna (ex.: `database show dnd`, `database show sigame`) |
| `odbc show all` | Conexão do Asterisk com o banco (CDR, queue_log) |
| `manager show users` | Usuários do AMI |
| `logger reload` | Reabre os arquivos de log |

> Prefira **Aplicar configurações** (ou `telium aplicar-config`) a `core reload`: ele gera os arquivos a partir do banco antes de recarregar e confere o resultado. Um reload sem gerar só relê o que já estava no disco.

### Exemplos

```bash
sudo asterisk -rx "pjsip show contacts"
sudo asterisk -rx "pjsip show registrations"
sudo asterisk -rx "queue show"
sudo asterisk -rx "core show channels"
```

---

## 8. MariaDB

O root do MariaDB entra pelo socket, sem senha, com `sudo`:

```bash
sudo mariadb telium_pabx
```

A senha do usuário da aplicação (`telium`) fica em `/etc/telium/credenciais/db_senha`; não é preciso para o dia a dia.

Tabelas mais consultadas:

| Tabela | Conteúdo |
|---|---|
| `ramais`, `troncos` | Cadastro de ramais e troncos |
| `filas`, `fila_agentes` | Filas e membros |
| `rotas_entrada`, `rotas_saida` | Rotas |
| `usuarios`, `perfis`, `sessoes` | Contas do console |
| `cdr`, `cel` | Registro das chamadas |
| `queue_log` | Eventos das filas (relatórios de call center) |
| `cc_agentes`, `cc_atendimentos`, `cc_retornos` | Call center |
| `sistema` | Ajustes gerais (ex.: `config_pendente`, `gravacoes_reter_dias`, a marca do último envio de cada alerta) |
| `backups`, `backup_rotinas` | Backup |
| `auditoria` | Quem fez o quê no console |

Exemplos:

```sql
SELECT numero, nome FROM ramais ORDER BY numero;
SELECT calldate, src, dst, disposition FROM cdr ORDER BY calldate DESC LIMIT 20;
```

Dump do banco:

```bash
sudo mariadb-dump --single-transaction --routines --triggers telium_pabx | gzip > /root/telium_pabx-$(date +%F).sql.gz
```

> Não altere tabelas à mão sem orientação: o console confere regras que o SQL direto ignora. Depois de qualquer mudança feita no banco, rode `telium aplicar-config`.

---

## 9. Firewall e fail2ban

### ufw

```bash
sudo ufw status numbered
```

Portas liberadas pelo playbook: veja [Requisitos de rede e portas](03-Requisitos-Rede-e-Portas.md).

### fail2ban

Cadeias (jails) da central:

| Jail | Vigia | Tentativas | Banimento |
|---|---|---|---|
| `asterisk` (UDP) e `asterisk-tcp` | `/var/log/asterisk/security`, portas 5060 e 5061 | 5 em 10 min | 24 h |
| `telium-console` | `/var/log/nginx/access.log`, login do console que volta 401/429 | 15 em 10 min | 1 h |
| `sshd` | SSH | padrão: 5 em 10 min | 1 h |

```bash
sudo fail2ban-client status                       # lista as jails
sudo fail2ban-client status asterisk              # banidos naquela jail
sudo fail2ban-client set asterisk unbanip 203.0.113.10
```

Pelo console: **Conectividade › Firewall** lista e desbloqueia, e a lista **Nunca bloquear** vira o `ignoreip` em `/etc/fail2ban/jail.d/telium-confiaveis.local` (não edite esse arquivo à mão).

---

## 10. nginx e PHP

```bash
sudo nginx -t                         # confere a configuração
sudo systemctl reload nginx
sudo systemctl restart php8.4-fpm
curl -sk https://127.0.0.1/api/health  # a API responde?
```

---

## 11. Logs

| Log | Comando |
|---|---|
| Asterisk (completo) | `sudo tail -f /var/log/asterisk/full` |
| Asterisk (avisos e erros) | `sudo tail -f /var/log/asterisk/messages` |
| Asterisk (segurança, falhas de senha) | `sudo tail -f /var/log/asterisk/security` |
| API | `sudo tail -f /var/log/telium/php-api.log` |
| Envio de e-mail | `sudo tail -f /var/log/telium-smtp.log` |
| Fax por e-mail | `journalctl -t telium-fax` |
| nginx | `sudo tail -f /var/log/nginx/error.log` e `access.log` |
| MariaDB | `sudo tail -f /var/log/mysql/error.log` |
| Serviços Telium | `journalctl -u telium-cc -f` (e `telium-backup`, `telium-certificados`, `telium-despertador`, `telium-tarifar`). Os alertas por e-mail aparecem no `telium-backup` com o prefixo `alertas:`; os erros de webhook, no `telium-cc` com o prefixo `webhook:` |
| coturn, fail2ban | `journalctl -u coturn`, `journalctl -u fail2ban` |

Rotação e localização de cada arquivo em [Diretórios, arquivos e logs](06-Diretorios-Arquivos-e-Logs.md).

---

## 12. Problemas comuns

### "Permission denied" ao rodar o telium

Rode como `teliumpbx`: `sudo -u teliumpbx php /opt/telium/api/bin/telium …`. Rodando como root, arquivos gerados ficam com o dono errado e a API deixa de conseguir regravá-los; corrija com outro `aplicar-config` como `teliumpbx` ou rodando o playbook.

### doctor acusa o AMI

```bash
sudo systemctl status asterisk
sudo bash /opt/TeliumPABX/infra/diagnostico-ami.sh
```

### "Outra aplicação de configuração está em andamento"

Alguém está aplicando pelo console (ou outro comando está rodando). Espere alguns segundos e repita.

### O telefone não loga agente (`*11`)

O `telium-cc` está parado: `sudo systemctl restart telium-cc` e `journalctl -u telium-cc -n 50`.

### Mudei um .conf à mão e a mudança sumiu

Os arquivos de `/etc/asterisk/telium` são regerados a cada **Aplicar configurações**, e os de `/etc/asterisk` a cada playbook. Personalização vai no `_custom.conf` correspondente (`pjsip_custom.conf`, `extensions_custom.conf`, `queues_custom.conf`, `voicemail_custom.conf`…), e usuário extra do AMI ou do ARI em `/etc/asterisk/manager_custom.conf` ou `ari_custom.conf`: nem o console nem o playbook sobrescrevem esses arquivos. Veja [Diretórios, arquivos e logs](06-Diretorios-Arquivos-e-Logs.md).
