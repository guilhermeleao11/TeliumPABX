# Requisitos de rede e portas

Todas as portas que a central abre ou usa, o que liberar no firewall do cliente e da operadora, como tratar NAT e o que o softphone do navegador precisa para ter áudio.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Resumo para o firewall do cliente](#1-resumo-para-o-firewall-do-cliente)
2. [Portas abertas para a rede](#2-portas-abertas-para-a-rede)
3. [Portas internas (só no próprio servidor)](#3-portas-internas-só-no-próprio-servidor)
4. [Conexões que a central faz para fora](#4-conexões-que-a-central-faz-para-fora)
5. [O firewall do próprio servidor (ufw e fail2ban)](#5-o-firewall-do-próprio-servidor-ufw-e-fail2ban)
6. [DNS e nome do servidor](#6-dns-e-nome-do-servidor)
7. [NAT, IP externo e rede local](#7-nat-ip-externo-e-rede-local)
8. [Operadora (tronco SIP)](#8-operadora-tronco-sip)
9. [Softphone no navegador (WSS e TURN)](#9-softphone-no-navegador-wss-e-turn)
10. [Problemas comuns](#10-problemas-comuns)

---

## 1. Resumo para o firewall do cliente

O que precisa **chegar** ao servidor, vindo de fora (redirecionar no NAT, liberar no firewall da nuvem ou do roteador):

| Porta | Protocolo | Para quê | Obrigatória? |
|---|---|---|---|
| 443 | TCP | Console, API, provisionamento de telefones e WebSocket do softphone (`/ws`) | Sim |
| 80 | TCP | Redireciona para o 443; QR Code do Linphone em central sem certificado válido | Recomendada |
| 5060 | UDP e TCP | SIP: telefones e operadora | Sim |
| 5061 | TCP | SIP sobre TLS | Se algum telefone ou tronco usar TLS |
| 10000–20000 | UDP | Áudio das chamadas (RTP) | **Sim** |
| 4000–4999 | UDP | Fax T.38 | Se usar fax |
| 3478 | UDP e TCP | TURN/STUN do softphone do navegador | Se usar softphone no navegador |
| 5349 | TCP | TURN sobre TLS | Se usar softphone no navegador |
| 49152–49500 | UDP | Áudio retransmitido pelo TURN | Se usar softphone no navegador |
| 10050 | TCP | Agente do Zabbix | Com a monitoria ligada (`instalar_zabbix`). O playbook libera só para os servidores Zabbix da Telium |
| 22 | TCP | SSH, para o suporte e para instalar e atualizar da máquina do técnico | De preferência só dos IPs do suporte (nos servidores da Telium, as redes da Telium) |

> A faixa **UDP 10000–20000** é a que mais se esquece de liberar, e a falha não dá erro: **a chamada conecta e ninguém ouve.**

Restrinja a origem sempre que possível: SIP só da operadora e das redes dos telefones; SSH só do suporte. O próprio servidor pode fazer parte disso: com o **SIP só para redes conhecidas** ligado (seção 5), o SIP e o áudio só respondem ao Brasil, à Telium, às redes internas, aos troncos e à lista **Nunca bloquear**.

> **Instalar e atualizar da sua máquina** usa só o SSH (TCP 22) da sua máquina até o servidor. Os servidores da Telium costumam aceitar SSH só das redes da Telium: de fora delas, entre pela VPN. Veja [Instalação](01-Instalacao.md).

---

## 2. Portas abertas para a rede

Confirmado nas configurações publicadas pelo playbook (`infra/ansible`).

| Porta | Protocolo | Serviço | Onde está configurado |
|---|---|---|---|
| 22 | TCP | SSH | ufw (role `seguranca`) |
| 80 | TCP | nginx: redireciona tudo para HTTPS, menos o caminho `/p/` (QR Code do Linphone) | `telium-pabx.conf` do nginx |
| 443 | TCP | nginx (HTTPS, TLS 1.2 e 1.3): console, `/api/`, `/prov/` (telefones buscam a configuração), `/p/`, `/ws` (softphone), `/api/cc/eventos` (tempo real do call center) | `telium-pabx.conf` do nginx |
| 5060 | UDP e TCP | Asterisk, transportes `transport-udp` e `transport-tcp` | `sip_porta`; transportes gerados pelo console |
| 5061 | TCP | Asterisk, `transport-tls` (TLS 1.2) | `sip_tls_porta` |
| 10000–20000 | UDP | RTP do Asterisk | `ast_rtp_inicio`/`ast_rtp_fim`, `rtp.conf` |
| 4000–4999 | UDP | UDPTL (fax T.38) | `udptl_inicio`/`udptl_fim`, `udptl.conf` |
| 3478 | UDP e TCP | coturn (TURN e STUN) | `turn_porta`, `turnserver.conf` |
| 5349 | TCP | coturn sobre TLS | `turn_porta_tls` |
| 49152–49500 | UDP | Faixa de retransmissão do coturn | `turn_rtp_inicio`/`turn_rtp_fim` |
| 20001–20200 | UDP | Mídia do Janus | `janus_rtp_inicio`/`janus_rtp_fim`. O ufw só abre essa faixa com `instalar_janus=true`; a regra antiga `20000:20200`, aberta mesmo sem Janus, é apagada pelo playbook |

- As portas de TURN só são abertas no ufw com `instalar_turn` ligado (padrão).
- Trocar a porta SIP é tarefa do playbook (`sip_porta` no `/etc/telium-servidor.yml` e nova execução): o Asterisk não troca a porta de escuta num recarregamento.

---

## 3. Portas internas (só no próprio servidor)

Estas escutam só em `127.0.0.1` (ou em socket local) e **não devem** ser liberadas para fora.

| Porta | Serviço | Observação |
|---|---|---|
| 3306 | MariaDB | `bind-address = 127.0.0.1` |
| 5038 | AMI do Asterisk | Escuta em 127.0.0.1 e o usuário só aceita 127.0.0.1. A API usa para aplicar configurações e originar chamadas |
| 8090 | HTTP do Asterisk (ARI e WebSocket) | O nginx repassa o `/ws` para cá |
| 8080 | Status do nginx (`/nginx_status`), para o Zabbix | Só com a monitoria do Zabbix |
| 8095 | Serviço de tempo real do call center (`telium-cc`) | O nginx publica só `/api/cc/eventos` |
| 8088, 8188, 7088 | Janus (HTTP, WebSocket, admin) | Só com `instalar_janus=true` |
| `/run/php/telium.sock` | PHP-FPM da API | Socket local, usado pelo nginx |

---

## 4. Conexões que a central faz para fora

O ufw libera toda saída (`default allow outgoing`). Se o cliente filtrar a saída, a central precisa alcançar:

| Destino | Porta | Para quê | Quando |
|---|---|---|---|
| Servidor DNS | 53 | Resolver nomes | Sempre |
| Operadora | SIP (em geral 5060) e a faixa RTP dela | Tronco | Sempre |
| Repositórios Debian, `mirror.mariadb.org`, `mariadb.org`, `nginx.org`, `downloads.asterisk.org` | 443/80 | Pacotes e código-fonte | Instalação e atualização |
| `gitlab.telium.com.br` | 443 (HTTPS) ou 22 (SSH) | `git pull` | Só em central atualizada de dentro. Atualizando da sua máquina, quem baixa do GitLab é ela, e o código vai para a central pelo SSH |
| `github.com` | 443 | Código do Janus | Só com `instalar_janus=true` |
| `stun.l.google.com:19302`, `stun.cloudflare.com:3478` | UDP | Botão **Descobrir** do IP público | Só quando alguém clica (`stun_descoberta`) |
| Syslog central da Telium | UDP 514 | Encaminhamento de logs | Com `syslog_central: true` (padrão) |
| Servidor SMTP do cliente | a do console (padrão 587) | E-mail de recado, alertas | Se configurado no console |
| Destino de backup remoto | FTP, FTPS ou SFTP (padrão 21) | Cópia do backup para fora | Se configurado no console |
| Servidor de hora | NTP | Hora certa (chrony) | Sempre |

Os IPs do syslog central estão em `infra/ansible/group_vars/all/main.yml` (`syslog_central_*`). Num cliente com outro syslog, troque no `/etc/telium-servidor.yml` da central; sem syslog, `syslog_central: false`.

---

## 5. O firewall do próprio servidor (ufw e fail2ban)

O playbook (role `seguranca`) instala e configura:

- **ufw**: libera as portas da seção 2, nega toda entrada que não esteja na lista e libera toda saída. Com `-e instalar_firewall=false`, o playbook não mexe no ufw.
- **fail2ban**, com estas regras:

| Regra | O que vigia | Bloqueio |
|---|---|---|
| `asterisk` e `asterisk-tcp` | Falhas de autenticação SIP (5060 e 5061), lidas em `/var/log/asterisk/security` | 5 erros, 24 horas |
| `telium-console` | Erros de login no console (`/var/log/nginx/access.log`) | 15 erros em 10 minutos, 1 hora |
| `sshd` | SSH | padrão do fail2ban |

Além disso, o nginx limita o login do console a 10 tentativas por minuto por IP.

Pelo console, em **Conectividade › Firewall**, dá para ver as origens com falha, os IPs **bloqueados agora** (e desbloquear) e manter a lista **Nunca bloquear** (IP ou faixa, ex.: o IP do escritório e do suporte). Mais em [Segurança](08-Seguranca.md).

> 📷 **Print:** Conectividade › Firewall, com a lista "Nunca bloquear".

### SIP só para redes conhecidas (opcional)

Perguntado na instalação (`sip_restringir_redes`, desligado de fábrica). Ligado, uma tabela própria do nftables descarta, nas portas de voz (SIP 5060/5061, RTP 10000–20000, fax 4000–4999, TURN 3478/5349 e 49152–49500, e a faixa do Janus se instalado), quem não está na lista: as faixas do Brasil, os IPs da Telium, as redes internas, as redes extras da central, o **Nunca bloquear**, os troncos e os telefones que já registraram. O resto da internet nem chega ao Asterisk. O console, o SSH e as outras portas não mudam.

Telefone ou operadora **fora do Brasil**, ou operadora que manda as chamadas de um IP diferente do host do tronco (SBC, nuvem como AWS ou Google, mesmo no Brasil): ponha o IP em **Nunca bloquear** (vale em segundos) ou em `sip_redes_extras`. Detalhes em [Segurança](08-Seguranca.md).

---

## 6. DNS e nome do servidor

- Crie um registro **A** com o nome da central (ex.: `voz.cliente.com.br`) apontando para o **IP público** do servidor.
- O nome precisa resolver **na máquina de quem usa o console** (e do softphone), não só no servidor.
- Sem `_`, a última parte começando por letra, e nada de `.local`. O softphone do navegador descarta em silêncio o que a central assina com um nome fora dessa regra.
- Ainda sem DNS? Use o IP público como nome e aponte o TURN para ele (`turn_dominio` no `/etc/telium-servidor.yml`; a instalação pergunta isso quando o nome ainda não resolve).
- O certificado de verdade precisa cobrir esse nome.

Detalhes da instalação: [Instalação](01-Instalacao.md#2-o-nome-do-servidor-dns).

---

## 7. NAT, IP externo e rede local

Quando o servidor tem IP de rede interna e sai para a internet por outro, a central precisa saber qual é o de fora. Sem isso, o convite SIP anuncia o endereço interno: **o tronco não registra** ou **a chamada completa sem áudio**.

**Onde configurar:** **Conectividade › Configurações de Rede**, cartão **Central atrás de NAT**.

| Campo | O que faz |
|---|---|
| Endereço público | O IP que o mundo vê. O botão **Descobrir** pergunta a um servidor STUN de fora. Em rede com IP público direto, deixe em branco |
| Faixas da rede interna | Para quem está nestas faixas, a central usa o endereço interno. Fábrica: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 |

Depois de salvar, clique em **Aplicar configurações**. Não precisa reiniciar o Asterisk.

- A tela mostra também os **transportes SIP** carregados e a **faixa de RTP**, lidos do próprio Asterisk.
- Se o servidor sai pela internet com IP interno e o endereço público está vazio, a tela mostra o aviso **"a chamada conecta e ninguém ouve"**.
- O endereço público vale para o SIP (UDP, TCP, TLS e WSS) e para os candidatos de áudio do softphone do navegador.
- O `nat_ip_publico` e o `redes_locais` do Ansible são só o ponto de partida; depois da primeira gravação no console, vale o que está no banco.

> **TURN atrás de NAT:** o `external-ip` do `/etc/turnserver.conf` vem do `nat_ip_publico` do `/etc/telium-servidor.yml` (perguntado na instalação, com o servidor atrás de NAT) e, sem ele, do **Endereço público** gravado no console. O coturn só é reescrito pelo playbook: depois de gravar ou trocar o endereço público no console, rode o playbook (ou a atualização) para ele chegar ao TURN.

**No roteador ou firewall de NAT:** redirecione para o IP interno do servidor as portas da [seção 1](#1-resumo-para-o-firewall-do-cliente), com as **mesmas** portas de fora e de dentro (principalmente a faixa RTP inteira).

**Opções por ramal** (aba **Rede** do ramal em **Conectividade › Ramais**): **Forçar rport** e **Reescrever contato** vêm ligadas de fábrica e ajudam com telefones atrás de NAT. Na aba **Mídia**, **Mídia direta entre aparelhos** faz o áudio ir de telefone a telefone; o ramal do navegador nunca usa mídia direta. Veja [Conectividade](17-Conectividade.md).

> 📷 **Print:** Conectividade › Configurações de Rede, com o cartão "Central atrás de NAT" preenchido.

---

## 8. Operadora (tronco SIP)

O que passar à operadora e o que pedir a ela:

- **IP público do servidor** e porta SIP (5060 UDP/TCP, ou 5061 TCP se o tronco for TLS), para ela liberar no lado dela.
- **IP de sinalização e faixa de mídia dela**, para você liberar na entrada (seção 1) e, se quiser, restringir o SIP a esses IPs. Com o **SIP só para redes conhecidas** ligado, o host do tronco cadastrado entra na lista sozinho, em até 5 minutos; se a operadora usa outros IPs além do host (um SBC, mídia, servidores na nuvem como AWS e Google, **mesmo no Brasil**), ponha-os em **Nunca bloquear**, senão as chamadas de entrada não chegam.
- **Fax T.38**: com a recepção de fax ligada em **Configurações › Fax**, os troncos passam a aceitar T.38 (depois de **Aplicar configurações**). Peça à operadora para ativar o T.38 do lado dela, e a faixa UDP 4000–4999 precisa chegar ao servidor; sem ela a operadora negocia o T.38 e o fax não passa.
- Se a operadora entrega o número em formato diferente do esperado, isso se ajusta no tronco, em **Conectividade › Troncos** (veja [Conectividade](17-Conectividade.md)).

---

## 9. Softphone no navegador (WSS e TURN)

O softphone do console fala SIP sobre WebSocket direto com o Asterisk, pelo nginx, no **mesmo endereço e certificado** do console: `wss://<nome-do-servidor>/ws`. Não usa Janus nem porta extra para a sinalização. O telefone pelo navegador nasce **desligado** e é ligado em **Conectividade › WebRTC / Softphone**.

O que o navegador do usuário precisa alcançar:

| Destino | Porta | Para quê |
|---|---|---|
| Central | TCP 443 | Console e sinalização (`/ws`) |
| Central | UDP 10000–20000 | Áudio direto com o Asterisk |
| Central (TURN) | UDP/TCP 3478, TCP 5349, UDP 49152–49500 | Áudio retransmitido quando o caminho direto não passa |
| `stun.l.google.com` | UDP 19302 | Descobrir o próprio IP público (STUN do navegador, `softphone_stun`) |

Outros requisitos:

- **Nome válido no DNS**, que resolva na máquina do usuário (seção 6). Com um nome que o navegador não resolve, a chamada conecta sem áudio e o navegador acusa *"ICE failed, your TURN server appears to be broken"*.
- **Certificado aceito pelo navegador.** Envie o certificado de verdade em **Administrador › Certificados** e aplique também no TURN.
- **TURN alcançável.** Ele é o plano B para rede que bloqueia UDP de saída, NAT simétrico, hotel e 4G corporativo. As credenciais do TURN são temporárias (6 horas), geradas a cada entrada; não há senha fixa.
- **Ramal marcado como WebRTC** e vinculado ao usuário.

A tela **Conectividade › WebRTC / Softphone** confere a configuração e o botão **Testar agora** diz, do navegador de quem está olhando, se existe caminho de áudio até a central. Mais em [Telefone WebRTC](11-Telefone-WebRTC.md).

> 📷 **Print:** Conectividade › WebRTC / Softphone, resultado do **Testar agora**.

---

## 10. Problemas comuns

### A chamada completa, mas ninguém ouve (ou só um lado ouve)
1. A faixa **UDP 10000–20000** chega ao servidor? Confira o firewall da nuvem ou do roteador.
2. **Conectividade › Configurações de Rede**: o endereço público é o do servidor e as faixas internas incluem a rede dos telefones? Depois, **Aplicar configurações**.
3. Atrás de NAT, a faixa RTP precisa estar redirecionada inteira para o servidor.

### O tronco não registra
Servidor atrás de NAT sem endereço público: o REGISTER sai com o IP interno. Preencha o endereço público no console e aplique. Confira também se a operadora liberou o IP público do servidor.

### Telefones param de registrar de repente
O IP deles pode ter sido bloqueado pelo fail2ban (5 erros de senha SIP = 24 horas). Veja e desbloqueie em **Conectividade › Firewall**, e coloque o IP do escritório em **Nunca bloquear**.

### Telefone ou operadora de fora do Brasil não registra
Com o **SIP só para redes conhecidas** ligado, quem não está na lista nem chega ao Asterisk (não aparece no log). Ponha o IP em **Conectividade › Firewall › Nunca bloquear**: vale em segundos. Tronco registrado e chamada de entrada que não chega: a operadora manda de outro IP (SBC, nuvem); ponha esse IP também. `sudo telium-firewall-sip mostrar` mostra a lista em vigor.

### O softphone do navegador conecta e fica mudo
Nome do servidor que o navegador não resolve, TURN inalcançável (portas 3478/5349/49152–49500), certificado não aceito ou servidor atrás de NAT com o TURN sem endereço público (o playbook não rodou depois de gravar o endereço público no console, e o `/etc/telium-servidor.yml` não tem `nat_ip_publico`). Use **Testar agora** em **Conectividade › WebRTC / Softphone**.

### Fax não passa
Libere UDP 4000–4999 até o servidor, confira se a recepção está ligada em **Configurações › Fax** (é ela que faz os troncos aceitarem T.38) e se a operadora ativou o T.38. Veja [Configurações](19-Configuracoes.md).

### Cada chamada demora uns 9 segundos para começar a discar
O `rtp.conf` tem um `stunaddr` que não responde. A tela **Configurações de Rede** mostra o aviso **STUN ativo no RTP**. Deixe `rtp_stunaddr` vazio (padrão) e rode o playbook.

Mais casos em [Solução de problemas](07-Solucao-de-Problemas.md).
