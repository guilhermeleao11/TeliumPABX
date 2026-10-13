# Solução de Problemas — Telium PABX

Guia de diagnóstico organizado por sintoma: para cada problema, as causas mais prováveis, como confirmar e como resolver. Antes de mexer em qualquer coisa, rode a bateria de testes da [seção 1](#1-antes-de-tudo-a-central-está-de-pé).

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Antes de tudo: a central está de pé?](#1-antes-de-tudo-a-central-está-de-pé)
2. [Onde olhar: telas e logs](#2-onde-olhar-telas-e-logs)
3. [Ramal não registra](#3-ramal-não-registra)
4. [Ramal registra, mas não toca](#4-ramal-registra-mas-não-toca)
5. [Chamada conecta e ninguém ouve (ou áudio de um lado só)](#5-chamada-conecta-e-ninguém-ouve-ou-áudio-de-um-lado-só)
6. [Tronco não registra](#6-tronco-não-registra)
7. [Chamada de entrada não chega ou cai na rota errada](#7-chamada-de-entrada-não-chega-ou-cai-na-rota-errada)
8. [Chamada de saída recusada](#8-chamada-de-saída-recusada)
9. [Softphone do navegador (WebRTC) não registra ou fica mudo](#9-softphone-do-navegador-webrtc-não-registra-ou-fica-mudo)
10. [Console não abre, erro 502 ou erro de banco depois do git pull](#10-console-não-abre-erro-502-ou-erro-de-banco-depois-do-git-pull)
11. [Faixa "Alterações ainda não aplicadas" e falha ao aplicar](#11-faixa-alterações-ainda-não-aplicadas-e-falha-ao-aplicar)
12. [Fila não distribui chamadas](#12-fila-não-distribui-chamadas)
13. [Correio de voz por e-mail não chega (e alertas e fax)](#13-correio-de-voz-por-e-mail-não-chega)
14. [Certificado vencido ou renovação](#14-certificado-vencido-ou-renovação)
15. [Login bloqueado ou senha do admin perdida](#15-login-bloqueado-ou-senha-do-admin-perdida)
16. [Backup falhou](#16-backup-falhou)
17. [Disco cheio](#17-disco-cheio)
18. [Reinício do Asterisk agendado](#18-reinício-do-asterisk-agendado)
19. [Provisionamento de telefone não funciona](#19-provisionamento-de-telefone-não-funciona)
20. [DTMF não funciona na URA](#20-dtmf-não-funciona-na-ura)
21. [Gravação não aparece](#21-gravação-não-aparece)
22. [Coletar informações para o suporte](#22-coletar-informações-para-o-suporte)
23. [Webhook não chega ao CRM](#23-webhook-não-chega-ao-crm)

---

## 1. Antes de tudo: a central está de pé?

**1. A bateria de testes.** Confere banco, esquema, geração da configuração, Asterisk, áudios, certificados, e-mail, backup e mais. Tem de terminar sem falha.

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

- Termina em `✓ N testes, nenhuma falha` quando está tudo certo.
- Com falha, a saída lista cada teste reprovado e o motivo. Resolva isso primeiro: nada que se teste depois é confiável.
- Sem o Asterisk no ar, o grupo **Aplicar no Asterisk** é pulado com o aviso `sem AMI — este grupo precisa do Asterisk no ar`.

**2. Os serviços.** Todos têm de responder `active`:

```bash
systemctl is-active mariadb nginx php8.4-fpm asterisk telium-cc coturn fail2ban
```

**3. O diagnóstico rápido das dependências:**

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium doctor
```

Confere banco, AMI, Janus (opcional; aparece como "não instalado" quando desligado) e a permissão de escrita em `/etc/asterisk/telium`.

**4. A saúde pela API:**

```bash
curl -sk https://127.0.0.1/api/health
```

Sem sessão, a resposta traz só a versão e `saudavel` (verdadeiro ou falso), com código 200 ou 503. O detalhe de cada checagem (php, banco, esquema, asterisk) aparece para quem está logado, e é o que a tela **Telium › Suporte** mostra.

> 📷 **Print:** tela **Telium › Suporte** com o quadro "Esta instalação" (checagens, configuração pendente e versão).

---

## 2. Onde olhar: telas e logs

**Telas do console que ajudam no diagnóstico:**

| Tela | O que mostra |
|---|---|
| Telium › Suporte | Checagens da API, configuração pendente, versão (com o commit) e comandos úteis |
| Telium › Versões e Novidades | A versão e o commit desta central e o que mudou em cada versão: confira ali se a correção que você procura já está instalada |
| Conectividade › Firewall | Falhas de autenticação SIP por origem, falhas de login no console, IPs bloqueados, lista "Nunca bloquear" |
| Conectividade › Troncos | Situação de cada tronco (publicado, registrado, no ar) |
| Conectividade › DIDs / Numeração | Os números de entrada e para onde vão (pelo nome do destino), com aviso de número duplicado |
| Conectividade › Configurações de Rede | IP público, redes locais, transportes, RTP e STUN |
| Conectividade › WebRTC / Softphone | Transporte wss, domínio, servidores ICE, ramais WebRTC e últimas chamadas do navegador |
| Configurações › SIP / PJSIP | Saída de `pjsip show settings`, `transports`, `endpoints`, `registrations` e tabela de codecs |
| Configurações › Arquivos e Aplicação | Arquivos gerados, histórico das últimas aplicações |
| Administrador › CLI Asterisk | Terminal do Asterisk pelo navegador |
| Relatórios › CDR — Registro de Chamadas | Cada chamada, com o número que chegou e o motivo de não completar |
| Relatórios › Eventos de Canal (CEL) | Passo a passo de uma chamada |

**Logs no servidor:**

| Arquivo | Conteúdo |
|---|---|
| `/var/log/asterisk/full` | Tudo do Asterisk, inclusive verbose e DTMF |
| `/var/log/asterisk/messages` | Só avisos e erros do Asterisk |
| `/var/log/asterisk/security` | Eventos de autenticação SIP (é o que o fail2ban lê) |
| `/var/log/telium/php-api.log` | Erros da API |
| `/var/log/nginx/error.log` e `access.log` | Servidor web |
| `/var/log/telium-smtp.log` | Envio de e-mail (msmtp) |
| `journalctl -u telium-cc` | Serviço de tempo real do call center, inclusive erros dos webhooks |
| `journalctl -u telium-backup` | Execuções do backup, a limpeza diária das gravações e os alertas por e-mail |
| `journalctl -t telium-fax` | Envio do fax recebido por e-mail |

Os logs do Asterisk giram todo dia (14 cópias, até 200 MB cada); os da API e do e-mail, toda semana (8 cópias).

**Comandos do Asterisk mais usados:**

```bash
sudo asterisk -rx "core show uptime"
sudo asterisk -rx "core show channels"          # chamadas em curso
sudo asterisk -rx "pjsip show transports"       # udp, tcp, tls e wss
sudo asterisk -rx "pjsip show contacts"         # aparelhos registrados
sudo asterisk -rx "pjsip show registrations"    # registro na operadora
sudo asterisk -rx "pjsip set logger on"         # trace SIP no log
sudo asterisk -rvvv                             # console interativo
```

---

## 3. Ramal não registra

**Causas prováveis:**
- Senha SIP errada ou aparelho apontando para outro servidor/porta.
- O IP do aparelho foi **bloqueado pelo fail2ban** depois de cinco senhas erradas (bloqueio de 24 horas no SIP).
- O aparelho está fora das redes de **Restringir a redes** (aba **Rede** do ramal).
- Ramal inativo, ou criado e ainda não aplicado.
- Transporte do aparelho diferente do configurado no ramal (UDP, TCP ou TLS).
- A porta 5060 (ou 5061 em TLS) não chega ao servidor.

**Como verificar:**

1. **Conectividade › Firewall**: veja se o IP aparece em **Bloqueados agora pelo firewall** e em **Origens com falha de autenticação SIP**. Se a tentativa nem aparece, o pacote não está chegando.
2. No servidor:
   ```bash
   sudo asterisk -rx "pjsip show contacts" | grep 1001
   sudo grep 1001 /var/log/asterisk/security | tail
   sudo fail2ban-client status asterisk
   ```
3. Confira se a faixa amarela **Alterações ainda não aplicadas** está no topo do console.

**Como resolver:**
- IP bloqueado: em **Conectividade › Firewall**, clique em **Desbloquear** ao lado do IP. Endereços de confiança (escritório, monitoramento) podem ir para **Nunca bloquear**. Detalhes em [08 — Segurança](08-Seguranca.md).
- Corrija a senha no aparelho (a senha SIP aparece no cadastro do ramal).
- Ajuste ou esvazie **Restringir a redes** e aplique.
- Clique em **Aplicar configurações**.

> 📷 **Print:** **Conectividade › Firewall** com a tabela "Bloqueados agora pelo firewall" e o botão Desbloquear.

---

## 4. Ramal registra, mas não toca

**Causas prováveis:**
- **Não perturbe** ou **siga-me** ligado no ramal (pelo console ou por código no telefone).
- O aparelho está registrado, mas **não responde** ao teste de presença (NAT fechou a porta, aparelho fora da rede).
- Ramal marcado como **WebRTC** usado por um softphone comum (UDP): ver [seção 9](#9-softphone-do-navegador-webrtc-não-registra-ou-fica-mudo).
- A chamada de fora não está chegando ao ramal: ver [seção 7](#7-chamada-de-entrada-não-chega-ou-cai-na-rota-errada).

**Como verificar:**
```bash
sudo asterisk -rx "pjsip show contacts"     # o estado do contato do ramal (Avail / Unavail)
sudo tail -f /var/log/asterisk/full         # ligue para o ramal e acompanhe
```
- No cadastro do ramal, confira o estado de não perturbe e siga-me.

**Como resolver:**
- Desligue não perturbe e siga-me (no cadastro do ramal ou pelo próprio usuário em **PCU — Meu Painel**).
- Aparelho atrás de NAT: mantenha ligados **Forçar rport**, **Reescrever contato** e **RTP simétrico** (aba **Rede** do ramal; vêm ligados de fábrica) e ajuste **Verificar o aparelho a cada (s)**.
- Se o ramal toca em mais de um aparelho, confira **Máximo de aparelhos** (padrão 2).

---

## 5. Chamada conecta e ninguém ouve (ou áudio de um lado só)

É quase sempre o **áudio (RTP)** que não chega. A falha não gera erro: a chamada conecta e fica muda.

**Causas prováveis:**
- A faixa **UDP 10000–20000** não chega ao servidor (firewall da nuvem, do roteador ou do provedor). O firewall do próprio servidor já é aberto pelo playbook.
- Servidor atrás de NAT sem a faixa de RTP redirecionada para ele.
- **IP público** errado em **Conectividade › Configurações de Rede**: a central anuncia o endereço errado no SDP (`external_media_address`).
- **Redes locais** incompletas: a rede dos telefones não está na lista, e a central trata o aparelho interno como externo.
- Codec sem tradução (g729 no tronco e ramal sem g729): nesse caso a chamada costuma **cair** logo ao atender.

**Como verificar:**
1. **Conectividade › Configurações de Rede**: confira IP público e redes locais. O botão **Descobrir** consulta um STUN e mostra o IP público visto de fora.
2. No servidor, durante uma chamada:
   ```bash
   sudo asterisk -rx "rtp show settings"
   sudo asterisk -rx "core show channels"
   ```
3. Áudio de um lado só: anote **qual lado** não ouve. A tela **Telium › Suporte** lembra que costuma ser NAT ou falta de TURN.

**Como resolver:**
- Abra e redirecione a faixa UDP 10000–20000 para a central.
- Corrija o IP público e as redes locais e clique em **Aplicar configurações**. Essas mudanças recarregam sem reiniciar o Asterisk.
- Codec: siga o aviso que a conferência mostra ao aplicar ("oferece g729, que o Asterisk não sabe traduzir...") e tire o codec do tronco ou coloque em todos os ramais.

Portas completas em [03 — Requisitos de Rede e Portas](03-Requisitos-Rede-e-Portas.md).

> 📷 **Print:** **Conectividade › Configurações de Rede** com IP público, redes locais e o botão Descobrir.

---

## 6. Tronco não registra

**Como verificar:** **Conectividade › Troncos** mostra a situação de cada tronco. As mensagens e o que fazer:

| Situação na tela | O que fazer |
|---|---|
| desativado no cadastro | Ative o tronco |
| não publicado — aplique as configurações | Clique em **Aplicar configurações** |
| usuário de autenticação sem senha — informe a senha da operadora | Preencha a senha |
| marcado para registrar, mas sem usuário e senha da operadora | Preencha usuário e senha ou desmarque o registro |
| a central não carregou a autenticação deste tronco — aplique de novo | Aplique; se persistir, veja o log |
| operadora recusou o registro — confira usuário e senha | Confira credenciais com a operadora |
| publicado, operadora não registrou o tronco | Veja o trace SIP |
| registrando na operadora… | Aguarde alguns segundos |
| publicado, operadora não respondeu ao teste | Rota até a operadora, firewall, endereço |
| sem resposta da central | O Asterisk não respondeu ao console (veja a seção 1) |

No servidor:
```bash
sudo asterisk -rx "pjsip show registrations"
sudo asterisk -rx "pjsip set logger on"
sudo tail -f /var/log/asterisk/full
```

> 📷 **Print:** **Conectividade › Troncos** com a coluna de situação.

---

## 7. Chamada de entrada não chega ou cai na rota errada

**Causas prováveis:**
- O número que a operadora entrega **não casa** com o DID da rota (`+5511...`, `11...`, só 8 dígitos).
- Não existe rota "qualquer número" (DID em branco ou `*`).
- A rota tem regra de **origem** (quem ligou) e o número não casou com nenhuma.
- O destino da rota foi apagado ou desativado.
- O número está na **Lista Negra**. A coluna **Bloqueios** da lista sobe a cada chamada barrada: se ela está subindo, é a lista negra que está recusando.

**O que quem liga ouve:**
- DID sem rota e sem rota coringa: a central atende e toca o aviso de serviço indisponível. No log aparece `DID ... chegou pelo tronco ... e não tem rota de entrada`.
- Todas as rotas daquele DID exigem origem e nenhuma casou: o mesmo aviso, com `nenhuma rota de origem casou` no log.

**Como verificar:**
1. **Relatórios › CDR — Registro de Chamadas**: veja o número exato que chegou.
2. **Conectividade › DIDs / Numeração**: confira para onde cada número vai.
3. No log:
   ```bash
   sudo grep -i "não tem rota de entrada\|nenhuma rota" /var/log/asterisk/full | tail
   ```
4. Ao aplicar, a conferência avisa `rota de entrada X aponta para ..., que não existe ou está inativo`.

**Como resolver:**
- Normalize o número **no tronco** (Conectividade › Troncos): **Tirar do começo do número recebido** (ex.: `+55`) e **Ficar só com os últimos N dígitos**.
- Crie uma rota de entrada com DID em branco ou `*` para pegar o que não casar.
- Precedência quando há regra de origem: número exato vence padrão, que vence a rota sem origem.
- Corrija o destino apontado e aplique.

---

## 8. Chamada de saída recusada

**Causas prováveis:**
- O ramal **não tem permissão** para a classe da rota (local, celular, DDD, internacional). Quem ouve: "não é possível completar a ligação como discada" e o log mostra `Ramal X sem permissão para <classe>`.
- O número discado não casa com nenhum **padrão** de rota de saída.
- Duas rotas com o **mesmo padrão**: só a primeira na ordem vale.
- Rota ativa **sem nenhum padrão**: nenhum número sai por ela.
- Rota usando um **tronco desativado**.
- Ramal no contexto `telium-bloqueado` (sem saída).
- Teto de chamadas de saída do ramal atingido.
- Tronco fora do ar (ver [seção 6](#6-tronco-não-registra)).

**Como verificar:**
- Aba **Permissões** do ramal: **Ligações locais e 0800/0300**, **Celular**, **DDD nacional**, **Internacional**. Emergência é sempre permitida.
- Ao aplicar, a conferência lista: `usa o mesmo padrão ... da rota ...`, `não tem nenhum padrão de discagem`, `usa o tronco ..., que está desativado`.
- No log:
  ```bash
  sudo grep "sem permissão para" /var/log/asterisk/full | tail
  ```
- **Relatórios › CDR** mostra o motivo de a ligação não completar.

**Como resolver:** ajuste a permissão do ramal ou o padrão/ordem das rotas em **Conectividade › Rotas de Saída** e clique em **Aplicar configurações**. A permissão também vale para siga-me, desvio e transferência às cegas: um ramal só-local não consegue desviar para celular.

---

## 9. Softphone do navegador (WebRTC) não registra ou fica mudo

Comece por **Conectividade › WebRTC / Softphone**: ela confere o caminho inteiro e o botão **Testar agora** diz, do navegador de quem está olhando, se há caminho de áudio até a central.

> 📷 **Print:** **Conectividade › WebRTC / Softphone** com as checagens e o resultado de "Testar agora".

**Não registra (fica em "registrando"):**

| Causa | Como conferir / resolver |
|---|---|
| Telefone do navegador desligado na central | É o padrão. Ligue em **Conectividade › WebRTC / Softphone**; cada usuário recarrega o console |
| Perfil sem a permissão **Telefone (WebRTC)** | Mensagem: "O telefone pelo navegador não está liberado para o seu perfil." Ajuste em **Administrador › Perfis e Permissões** |
| Usuário sem ramal vinculado | Mensagem: "A sua conta não está vinculada a nenhum ramal." |
| Ramal sem **WebRTC** marcado | Mensagem: "O ramal X não está marcado como WebRTC..." |
| `pabx_hostname` inválido | Precisa começar por letra, sem `_`, e não terminar em `.local`. Fora disso, o navegador descarta tudo em silêncio |
| Certificado não aceito | Com o certificado de fábrica o navegador avisa e às vezes bloqueia. Envie um certificado válido (seção 14) |
| WebSocket bloqueado | No navegador, F12 › Rede › filtro WS: a conexão com `/ws` deve aparecer com status 101 |

**Registra, mas a chamada fica muda:**
- **Transporte wss no loopback.** Se o `wss` estiver preso em `127.0.0.1`, o RTP nasce no loopback, o ICE não fecha e o log enche de `Error sending STUN request: Invalid argument`. A tela mostra onde o wss está amarrado. O WebSocket continua só no `127.0.0.1:8090` do `http.conf`, atrás do nginx.
- **TURN inalcançável.** O nome do TURN (vem do `pabx_hostname`) precisa resolver e ser alcançável da máquina do usuário. A mensagem do navegador é "ICE failed, your TURN server appears to be broken". Sem DNS, aponte `turn_dominio` para o IP público no `servidor.yml` e rode o playbook.
- **Portas do TURN** fechadas: 3478 UDP/TCP, 5349 TCP e 49152–49500 UDP.
- **Ramal WebRTC usado por softphone comum.** A tela marca como "aparelho incompatível": a chamada conecta e ninguém ouve.

A tela também guarda as últimas chamadas do navegador com o veredito medido (mudo, só ouviu, só falou, não fechou). Mais em [11 — Telefone WebRTC](11-Telefone-WebRTC.md).

---

## 10. Console não abre, erro 502 ou erro de banco depois do git pull

**Console não abre ou dá 502:**
```bash
systemctl status nginx php8.4-fpm
sudo tail -20 /var/log/nginx/error.log
sudo tail -20 /var/log/telium/php-api.log
```
- 502 é o nginx sem resposta do PHP-FPM (socket `/run/php/telium.sock`). Reinicie o `php8.4-fpm` e veja o log da API.
- Entra pelo IP, mas não pelo nome: confira o DNS (`nslookup`), o certificado e o `pabx_hostname`. Teste do próprio servidor:
  ```bash
  curl -k --resolve voz.cliente.com.br:443:127.0.0.1 https://voz.cliente.com.br/api/health
  ```
  Se responder, o problema está entre o navegador e o servidor.
- Tela ou botão novo não aparece depois de atualizar: **Ctrl + Shift + R**. Confira também a versão (`git log --oneline -1` em `/opt/TeliumPABX`) e a permissão do perfil.

**Erro de banco depois de git pull (migração faltando):**

Quem faz `git pull` sem rodar o playbook ou a migração fica com o banco atrás do código. A checagem **esquema** do `/api/health` e da tela **Telium › Suporte** acusa:

> O banco está atrás do código: falta tabela ... Rode "php bin/telium migrar" no servidor, ou o playbook do Ansible.

Ao aplicar ou abrir uma tela, a mensagem pode ser: "O banco está desatualizado para esta versão do código — ...".

**Como resolver:**
```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium migrar
```
Os scripts são idempotentes; reaplicar é seguro. O caminho completo de atualização (com playbook) está em [02 — Atualização](02-Atualizacao.md).

---

## 11. Faixa "Alterações ainda não aplicadas" e falha ao aplicar

Tudo o que é salvo no console vai para o banco. O Asterisk só passa a usar quando alguém clica em **Aplicar configurações** na faixa amarela do topo. Só perfis com a ação **reiniciar** veem o botão; os demais leem "Peça a um administrador para aplicar."

> 📷 **Print:** a faixa amarela "Alterações ainda não aplicadas no Asterisk" com o botão Aplicar configurações.

**O que acontece ao aplicar:** os arquivos são gerados a partir do banco, as listas são repostas na base interna do Asterisk (lista negra, allowlist, siga-me e o modo dele, não perturbe, permissões de discagem, quem pode escutar, quem pode mexer na lista negra e na allowlist pelo telefone) e os módulos são recarregados um a um (ramais e troncos, dialplan, filas, correio de voz, conferências, estacionamento, música em espera, códigos de recurso, colunas do CDR, candidatos ICE e portas de voz). No fim, a central confere se cada ramal e tronco ativo carregou de fato.

**Resultados possíveis na faixa:**

| Mensagem | Significado |
|---|---|
| Configuração aplicada no Asterisk | Tudo certo; a faixa some sozinha |
| Configuração aplicada, com N problemas no cadastro | Aplicou, mas a conferência achou erros de cadastro (lista abaixo) |
| O Asterisk recusou parte da recarga | Uma etapa falhou; use **Ver a saída** |

**Etapas que falham com mais frequência:**
- **ramais e troncos carregados** — a saída diz `O Asterisk não carregou: 1005, ...`. Uma opção do ramal ou tronco foi recusada pelo PJSIP. Veja o motivo:
  ```bash
  sudo grep -i "1005" /var/log/asterisk/full | tail
  ```
- **conexão com o Asterisk** — o Asterisk está parado ou o AMI recusou a senha. `sudo systemctl status asterisk`; para o AMI, rode `sudo bash /opt/TeliumPABX/infra/diagnostico-ami.sh`, que compara a senha do `manager.conf` com a de origem (sem mostrá-la) e testa o login. Usuários do AMI criados à mão ficam em `/etc/asterisk/manager_custom.conf`, nunca no `manager.conf`, que o playbook reescreve.
- **aplicação em curso** — "Outra aplicação de configuração está em andamento. Espere ela terminar e tente de novo."

**Problemas que a conferência do cadastro mostra:**

| Onde | Texto (resumo) |
|---|---|
| rota de entrada, fila, URA, grupo | aponta para ..., que não existe ou está inativo — a chamada vai cair sem destino |
| fila | está sem nenhum agente — ninguém vai atender (aviso) |
| grupo de toque | inclui o ramal X, que não existe ou está inativo |
| tronco | oferece g729, que o Asterisk não sabe traduzir (aviso) |
| rota de saída | mesmo padrão de outra rota; sem nenhum padrão; tronco desativado |
| arquivos gerados | modo ou grupo errado: o Asterisk não consegue ler o arquivo |

O histórico fica em **Configurações › Arquivos e Aplicação** (últimas aplicações, com resultado). Pelo terminal, o mesmo processo:
```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config
```

Mudança na **porta** de um transporte não vale com recarga: o Asterisk mantém a porta anterior até reiniciar.

---

## 12. Fila não distribui chamadas

**Causas prováveis:**
- Fila sem nenhum agente (a conferência avisa ao aplicar).
- Agentes com o telefone não registrado.
- Fila de **call center**: ninguém está logado, todos estão em pausa ou em pós-atendimento esperando tabulação.
- A fila foi criada ou alterada e não foi aplicada.
- O destino da rota não aponta para a fila.
- O agente tentou entrar pelo `*45` e ouviu que a operação falhou: o `*45` só aceita quem está na lista de agentes da fila como **Entra por código** (gaveta de agentes da fila). Inclua o ramal assim e aplique. Fila de call center não aceita `*45`: o agente entra pelos códigos do call center.

**Como verificar:**
```bash
sudo asterisk -rx "queue show 3000"
journalctl -u telium-cc -n 50
```
- Em fila de call center, o **Monitor ao Vivo** mostra quem está livre, falando ou em pausa.

**Como resolver:** aplique as configurações, registre os aparelhos e siga [16 — Call Center](16-Call-Center.md) (seção de problemas comuns) para login, pausa e tabulação.

---

## 13. Correio de voz por e-mail não chega

O envio é feito pelo **msmtp** (relay; não há servidor de e-mail na central). A configuração vem de **Configurações › Notificações e E-mail**.

**Causas prováveis:**
- Envio de e-mail desligado, sem servidor ou sem remetente: a configuração do msmtp é apagada.
- Credenciais, porta ou segurança (TLS, STARTTLS) erradas.
- O ramal sem **e-mail** cadastrado. A tela **Aplicações › Correio de Voz** mostra "sem e-mail cadastrado".
- **Apagar depois de mandar** só vale quando o recado vai de fato, com o áudio anexado, para um e-mail. Sem e-mail no ramal (ou sem o anexo), o recado fica na caixa e não é apagado.
- O correio de voz do ramal desligado.
- Mensagem caindo no spam do destinatário.

**Como verificar:**
1. Em **Configurações › Notificações e E-mail**, use o teste de envio. Mensagens possíveis:
   - "Mensagem entregue ao servidor de saída. Confira a caixa de ... — e o spam, na primeira vez."
   - "O servidor de saída recusou: ..." (com o motivo do servidor).
   - "Não foi possível publicar a configuração de envio no servidor..." — a ponte `/usr/local/sbin/telium-smtp` ou a regra de sudo não foi instalada: rode o playbook.
2. No servidor:
   ```bash
   sudo tail -30 /var/log/telium-smtp.log
   sudo /usr/local/sbin/telium-smtp
   ```
   O segundo comando republica a configuração e diz "configuração de e-mail publicada para servidor:porta" ou "envio de e-mail desligado".

> 📷 **Print:** **Configurações › Notificações e E-mail** com o resultado do teste de envio.

### Os alertas do sistema não chegam

Backup com falha ou atrasado, certificado perto de vencer e disco quase cheio vão para os e-mails do campo **Mandar os alertas do sistema para**, em **Configurações › Notificações e E-mail**.

- Com o campo em branco, ou com o envio de e-mail desligado, nenhum alerta sai.
- O executor de backup confere os alertas **de hora em hora** (no minuto 07) e logo depois de um backup que falha. Cada tipo de aviso sai **no máximo uma vez por dia**; o de backup com falha, uma vez por backup.
- Para mandar agora o que estiver pendente:
  ```bash
  sudo -u teliumpbx php /opt/telium/api/bin/telium alertas
  ```
  A resposta é "nenhum aviso a mandar" ou a lista dos avisos enviados. Um aviso que já saiu hoje não sai de novo.

### O fax não chega por e-mail

- Em **Configurações › Fax**, a recepção precisa estar ligada e o campo **Mandar o fax recebido para** preenchido. Com a recepção desligada, o código de receber fax avisa que o serviço não está disponível.
- Mudou algo na tela de fax? **Aplique as configurações**: os ajustes vão para o dialplan.
- O fax sai pelo mesmo envio do correio de voz: se o teste de e-mail falha, o fax também não chega.
- Veja o que aconteceu: `sudo journalctl -t telium-fax -n 20`. "não chegou" quer dizer que o fax em si falhou (linha ruim, sem T.38): tente baixar a velocidade máxima para 9600 e peça T.38 à operadora. "falha ao enviar" é problema do e-mail.
- Sem o `tiff2pdf` (pacote `libtiff-tools`, instalado pelo playbook), o fax vai em TIFF, e não em PDF.

---

## 14. Certificado vencido ou renovação

**Sintomas:** o navegador avisa que a conexão não é segura, o softphone do navegador não conecta, telefones em TLS não registram.

**Como verificar:** **Administrador › Certificados** mostra cada certificado, a validade e os serviços em que está em uso: **Interface web (nginx)**, **SIP TLS (Asterisk)**, **TURN / STUN (coturn)** e **WebRTC (Janus)**.

**Como renovar:** a central não renova sozinha. Pela mesma tela:
- envie o novo par (certificado `.crt`/`.pem`/`.cer` e a chave `.key`), ou
- gere o pedido (CSR) no console, mande à autoridade e cole o certificado emitido;
- depois, aponte cada serviço para o certificado novo e aplique.

Mensagens comuns ao enviar: "O certificado enviado não corresponde ao pedido gerado aqui.", "A chave privada deste pedido não está mais no cofre.", "Este certificado está em uso em: ... Aponte esses serviços para outro certificado antes de excluir." Ao enviar um certificado já vencido, a tela avisa.

A bateria `telium testar` (grupo **Certificados TLS**) confere se o certificado chegou a cada serviço. Mais em [08 — Segurança](08-Seguranca.md).

---

## 15. Login bloqueado ou senha do admin perdida

**Mensagens de login:**

| Mensagem | Motivo | Saída |
|---|---|---|
| Usuário ou senha inválidos | Senha errada ou usuário inexistente | Conferir |
| Conta temporariamente bloqueada por excesso de tentativas | 5 erros nesta conta, a partir deste IP: 15 minutos | Esperar ou o administrador libera |
| Tentativas demais desta origem. Tente de novo em alguns minutos. | 20 falhas do mesmo IP em 10 minutos | Esperar (quem digita a senha certa ainda entra) |
| Código de verificação inválido | Código do 2FA errado | Conferir o relógio do celular |
| Este código já foi usado. Espere o próximo aparecer no aplicativo. | Código de 2FA repetido | Esperar 30 s |
| Usuário inativo. Procure o administrador. (ou "Usuário bloqueado.") | Situação da conta no cadastro | O administrador muda para ativo |

O nginx também limita o login a 10 pedidos por minuto por IP (com folga de 5). Quem insiste (15 respostas 401/429 em 10 minutos) é banido pelo fail2ban por 1 hora na cadeia `telium-console`; veja e desbloqueie em **Conectividade › Firewall**.

**Liberar pelo console:** em **Administrador › Gerenciador de Usuários**, o administrador pode liberar o bloqueio ("Bloqueio liberado."), definir uma nova senha ou desligar a verificação em dois passos de quem perdeu o celular.

**Senha do admin perdida (pelo servidor):**
```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin
```
O comando pede a senha nova sem eco, aplica a política (8 caracteres, um número, um símbolo), zera o bloqueio e derruba as sessões abertas. Em script, aceita a senha por pipe. O comando **não desliga** a verificação em dois passos.

**Celular do 2FA perdido e nenhum outro administrador para desligar:**
```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium desligar-2fa admin
```
Desliga a verificação em dois passos e derruba as sessões. Depois de entrar, ligue de novo em **Meu Perfil e Segurança**.

---

## 16. Backup falhou

**Como verificar:**
- **Administrador › Backup & Restauração** mostra o estado de cada backup e a saída da execução (o que entrou, tamanho, erro do `tar`).
- No servidor:
  ```bash
  systemctl status telium-backup.timer
  journalctl -u telium-backup -n 50
  ls -lh /var/backup/pabx-telium/
  ```

Com os alertas por e-mail configurados ([seção 13](#13-correio-de-voz-por-e-mail-não-chega)), a central avisa sozinha quando um backup falha e quando há rotina ligada sem nenhum backup concluído há mais de 26 horas.

**Causas prováveis:** disco cheio (as gravações pesam), falha do `tar`, ou o destino remoto recusou o envio. Sem destino remoto ligado, o envio responde "nenhum destino remoto ligado — o backup fica só no servidor", o que não é erro.

Detalhes em [04 — Backup e Restauração](04-Backup-e-Restauracao.md).

---

## 17. Disco cheio

**Sintomas:** backup falhando, gravações sem arquivo, banco recusando gravação, erros estranhos na API.

**Como verificar:**
```bash
df -h
sudo du -sh /var/spool/asterisk/monitor /var/backup/pabx-telium /var/log/asterisk /var/lib/mysql
```
A tela **Aplicações › Gravação de Chamadas** mostra o uso do disco das gravações. Com os alertas por e-mail configurados, a central avisa quando o disco das gravações fica com menos de 10% livre.

**Onde costuma crescer:**
- **Gravações** (`/var/spool/asterisk/monitor`): com o prazo de guarda em zero (o padrão), a central guarda todas para sempre.
- **Backups** (`/var/backup/pabx-telium`): a retenção de cada rotina mantém só os N arquivos mais recentes (padrão 5). Backup com gravações incluídas ocupa muito.
- **Logs**: giram sozinhos (ver [seção 2](#2-onde-olhar-telas-e-logs)).

**Como resolver:** combine com o cliente por quantos dias guardar as gravações e defina o prazo em **Aplicações › Gravação de Chamadas** (a limpeza roda uma vez por dia). Reduza também a retenção do backup e tire as gravações do backup local, mandando-as para o destino remoto.

---

## 18. Reinício do Asterisk agendado

Duas coisas o Asterisk só lê ao subir: o `asterisk.conf` e a lista de módulos (`modules.conf`). Quando o playbook muda uma delas, ele agenda `core restart when convenient` e avisa no final que o reinício fica para quando não houver chamada em curso.

**Como verificar se já aconteceu:**
```bash
sudo asterisk -rx "core show uptime"
```

**Central que nunca fica vazia:** o reinício fica pendurado. Para forçar, derrubando as chamadas em curso, faça num horário combinado:
```bash
sudo systemctl restart asterisk
```

---

## 19. Provisionamento de telefone não funciona

O aparelho busca a configuração em `/prov/<segredo>/<arquivo>`. Toda recusa responde 404 para o aparelho, e cada pedido fica registrado no console (**Conectividade › Provisionamento**), com o resultado:

| Resultado registrado | Causa | Como resolver |
|---|---|---|
| segredo_errado | O endereço no aparelho não tem o segredo certo | Copie o endereço exibido na tela |
| rede_negada | Pedido de fora das redes locais, com "só rede local" ligado | Inclua a rede em **Configurações de Rede** ou provisione de dentro |
| mac_desconhecido | MAC não cadastrado ou nome de arquivo fora do padrão | Cadastre o aparelho com o MAC certo |
| sem_ramal | Aparelho sem ramal ativo vinculado | Vincule o ramal |
| entregue | Configuração entregue | — |

Os aparelhos usam o caminho `/prov/` com limite próprio no nginx, para que vários telefones subindo juntos depois de uma queda de energia não sejam barrados. Para o QR Code do Linphone, o convite tem validade de 10 minutos e uso único. Mais em [14 — Recursos do Ramal](14-Aplicacoes-Recursos-do-Ramal.md).

> 📷 **Print:** **Conectividade › Provisionamento** com o registro dos pedidos.

---

## 20. DTMF não funciona na URA

**Causas prováveis:**
- Modo de DTMF do ramal diferente do que o aparelho envia.
- A operadora envia DTMF de um jeito diferente do padrão RFC 4733. O tronco não tem modo de DTMF configurável no console e usa o padrão do Asterisk.
- Áudio da URA não toca (anúncio inativo ou não aplicado), e o cliente não sabe o que digitar.

**Como verificar:** o log `full` registra os dígitos recebidos. Ligue para a URA, digite e acompanhe:
```bash
sudo tail -f /var/log/asterisk/full | grep -i dtmf
```
Se nenhum dígito aparece, o DTMF não está chegando.

**Como resolver:** no ramal, aba **Mídia**, campo **Sinalização DTMF**: RFC 4733 (padrão), No áudio (inband), SIP INFO, Automático ou Automático, com INFO. Combine com o modo configurado no aparelho e aplique.

---

## 21. Gravação não aparece

**Causas prováveis:**
- Nenhuma regra mandou gravar. No ramal (aba **Gravação**) há quatro regras (externa recebida, externa feita, interna recebida, interna feita) e a gravação sob demanda (`*1`). "Nunca" de qualquer lado proíbe e "Forçar" de qualquer lado obriga.
- A chamada não foi atendida.
- O arquivo foi apagado ou não chegou ao disco (disco cheio).
- O perfil do usuário não tem acesso a **Aplicações › Gravação de Chamadas**.

**Como verificar:** a tela de gravações cruza o CDR com o disco; quando o registro existe e o arquivo não, a gravação aparece sem arquivo e o download responde "Gravação não encontrada no servidor." No servidor:
```bash
ls -lt /var/spool/asterisk/monitor | head
```

---

## 22. Coletar informações para o suporte

Antes de abrir o chamado, junte:

1. **A versão e o commit.** Aparecem em **Telium › Versões e Novidades**, em **Telium › Suporte** e no rodapé do menu, no formato `1.1.0 (ae4f854)`. Um `+` no fim do commit indica um código publicado com alterações fora do Git. Sem login, `curl -sk https://127.0.0.1/api/health` mostra só o número da versão.
2. **O que aconteceu:** o que foi discado, de qual ramal, e a hora com minuto. Se for áudio, se falta nos dois lados ou em um só.
3. **A bateria de testes:**
   ```bash
   sudo -u teliumpbx php /opt/telium/api/bin/telium testar
   ```
4. **O relatório de diagnóstico** (curto, para colar inteiro):
   ```bash
   sudo bash /opt/TeliumPABX/infra/diagnostico.sh
   ```
   Traz serviços, versões, portas em escuta, contagens do banco, endpoints e ODBC do Asterisk, saúde da API, arquivos gerados e os últimos erros do Asterisk, nginx e API.
5. **O diagnóstico completo pelo Ansible:**
   ```bash
   cd /opt/TeliumPABX/infra/ansible
   sudo ansible-playbook verificar.yml
   ```
6. **Problema de AMI** (console diz que o Asterisk não responde, mas ele está no ar):
   ```bash
   sudo bash /opt/TeliumPABX/infra/diagnostico-ami.sh
   ```
   A senha não aparece na saída: só o tamanho e os quatro primeiros caracteres.
7. **Os trechos de log** do horário do problema: `/var/log/asterisk/full`, `/var/log/telium/php-api.log` e, se for o caso, `/var/log/telium-smtp.log`.

Não mande senhas: o arquivo `/etc/telium/credenciais/` e o `vault.yml` nunca entram no chamado.

> 📷 **Print:** **Telium › Suporte**, quadro "Antes de abrir o chamado".

---

## 23. Webhook não chega ao CRM

Os webhooks são cadastrados em **Telium › Integrações / API** ([20 — Telium: Tarifação, Integrações e Suporte](20-Telium-Tarifacao-Integracoes-Suporte.md)).

**Como verificar:**
1. Na lista de webhooks, a coluna **Último envio** mostra quando foi o último aviso e o resultado: `ok (HTTP 200)`, `falhou (HTTP 500)` ou `falhou:` com o erro de rede. "nunca" quer dizer que nenhum evento casou com o cadastro.
2. O botão de teste da linha manda um aviso de exemplo na hora. Respostas: "O destino respondeu HTTP …" ou "Não chegou: …" com o erro.
3. Quem manda os avisos é o serviço de tempo real (`telium-cc`):
   ```bash
   systemctl status telium-cc
   journalctl -u telium-cc -n 50 | grep -i webhook
   ```

**Causas prováveis:**
- O `telium-cc` está parado: sem ele, nenhum aviso sai (o teste da tela sai mesmo assim, porque é a API que o faz).
- O webhook está inativo, ou o campo **Eventos** não tem o evento esperado (em branco, valem os três).
- O destino demora mais de 5 segundos para responder, ou responde com redirecionamento (a central não segue).
- O CRM recusa porque confere a assinatura `X-Telium-Assinatura` com um segredo diferente do cadastrado.
- A linha está marcada **sem efeito**: é um cadastro de tipo antigo (chave de API, syslog), que não manda nada. Crie um webhook novo.
- Um cadastro alterado leva até 15 segundos para o serviço perceber.
