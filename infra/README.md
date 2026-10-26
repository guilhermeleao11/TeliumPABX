# Instalação — Telium PABX

Provisionamento por Ansible. Alvo: **Debian 13 (trixie)**; também roda em
Ubuntu 24.04 (os repositórios se ajustam sozinhos).

Uma execução instala a central inteira: banco, PHP, nginx com TLS,
Asterisk compilado, TURN próprio, backup, firewall e o console. O
playbook é idempotente — rode quantas vezes quiser, ele só mexe no que
mudou.

## O que é instalado

| Componente | Versão | Origem |
|---|---|---|
| MariaDB | 12.3.3 | repositório oficial MariaDB |
| PHP + FPM | 8.4 | repositório do Debian 13 |
| Asterisk | 22.11.0 | **compilado do fonte** (Debian 13 não empacota) |
| coturn (TURN/STUN) | do sistema | credencial com prazo, gerada a cada entrada |
| nginx | mainline (1.31.x) | repositório oficial nginx.org |
| msmtp | do sistema | envio de e-mail (recado do correio de voz, alertas) |
| fail2ban + ufw | do sistema | proteção SIP e SSH |
| Janus | v1.4.1 | **opcional e desligado** — `instalar_janus=true` |

A compilação do Asterisk leva de **5 a 15 minutos**. É a única parte
demorada.

O WebRTC do console **não usa Janus**: o navegador fala SIP sobre
WebSocket direto com o Asterisk, pelo `/ws` do nginx, com o mesmo
certificado do console. O Janus continua instalável, fora do caminho da
chamada, para um módulo futuro de sala de vídeo.

### Duas coisas de que o softphone do navegador depende

Ambas já vêm certas de fábrica; estão aqui porque, quando quebram, a
falha é sempre a mesma — *"a chamada conecta e ninguém ouve"* — e não
aparece erro em lugar nenhum.

- **O transporte `wss` não pode ficar no loopback.** É dele que o
  Asterisk tira o endereço do soquete de RTP: preso em `127.0.0.1`, ele
  anuncia no SDP um endereço correto que não consegue usar, não responde
  a um único teste de conexão do navegador e enche o log de *"Error
  sending STUN request: Invalid argument"*. Isso não expõe o WebSocket:
  quem escuta a 8090 continua sendo o `http.conf`, em `127.0.0.1`.
- **`pabx_hostname` precisa ser um nome válido de RFC 3261** — começar
  por letra, sem `_`. O softphone segue a gramática à risca e **descarta
  em silêncio** tudo que a central assinar com um nome fora dela:
  a verificação de presença não é respondida, o contato vira
  inalcançável e nenhuma chamada entra no ramal do navegador.

A tela **Conectividade → WebRTC / Softphone** confere as duas, e o botão
*Testar agora* diz, do navegador de quem está olhando, se existe caminho
de áudio até a central.

## A chamada que entra

O caminho é sempre o mesmo, e cada passo é configurável no console:

```
operadora → tronco (normaliza o número) → rota de entrada (casa DID e origem)
          → lista negra → destino
```

O **destino** é qualquer coisa que a central alcance: condição de
horário, URA, fila, ramal, grupo de toque, anúncio, conferência, DISA,
correio de voz, megafonia, destino personalizado, número externo — ou
desligar com ocupado.

**Se a chamada não entra, o problema quase sempre é o formato do
número.** Cada operadora entrega do jeito dela: uma manda
`+551133255800`, outra `1133255800`, outra só `3255800`. Acerte isso
**uma vez no tronco**, em *Conectividade → Troncos*:

| Campo | Para quê |
|---|---|
| `Tirar do começo do número recebido` | `+55` some quando vier; quem entregar sem ele não é mutilado |
| `Ficar só com os últimos N dígitos` | com 8, `1133255800` vira `33255800` |

Tronco que **não entrega número nenhum** — muito gateway FXO, E1 e
alguns SIP — cai na rota com DID em branco (ou `*`), que é a rota
"qualquer número". Sem uma dessas cadastrada, quem liga ouve o aviso de
número inexistente em vez de a chamada morrer calada.

Uma rota pode casar também por **quem ligou**, e aí a precedência é a do
Asterisk: origem exata vence padrão, que vence a rota sem origem. É o
que permite mandar um cliente específico direto para o gerente e o resto
do DDD 11 para a fila, no mesmo número.

## Portas que precisam chegar no servidor

| Porta | Protocolo | Para quê |
|---|---|---|
| 443 | TCP | console e o WebSocket do softphone (`/ws`) |
| 5060 | UDP/TCP | SIP dos telefones e do tronco |
| 5061 | TCP | SIP sobre TLS |
| 3478 / 5349 | UDP/TCP | TURN e STUN, para o softphone do navegador |
| **10000–20000** | **UDP** | **áudio (RTP)** |

A faixa de RTP é a que mais esquece de abrir, e a falha dela não dá erro:
a chamada conecta e ninguém ouve. Em NAT, ela precisa estar redirecionada
para a central — o firewall do próprio servidor já é aberto pelo playbook.

### SIP só para redes conhecidas (opcional)

Com `sip_restringir_redes: true` (perguntado na instalação; desligado de
fábrica), uma tabela própria do nftables, `inet telium_sip`, descarta nas
portas de voz — SIP UDP/TCP/TLS, RTP 10000–20000, fax T.38 4000–4999,
TURN 3478/5349 e a faixa 49152–49500, e a do Janus se instalado — quem não
está na lista. Quem está segue para o ufw como sempre. Por ser à parte, não
depende do ufw, do fail2ban nem de um `iptables` antigo (`rules.v4`) que o
servidor tenha.

A lista é a soma de `sip_redes_liberadas` (IPs e faixas da Telium, as
faixas do Brasil e as redes internas) + `sip_redes_extras` + o "Nunca
bloquear" do console (`firewall_confiaveis`) + o host de cada tronco
(resolvido) + os telefones já registrados. Troncos e telefones vistos ficam
em `/var/lib/telium-firewall-sip/vistos.conf` e nunca saem sozinhos.
Conexões já estabelecidas e o próprio servidor passam. Só o IPv4 é
restrito, a não ser que a lista fixa tenha uma faixa IPv6.

| Peça | O que é |
|---|---|
| `/etc/telium-sip-redes.conf` | Lista fixa, gerada pelo playbook. Sem ela, o recurso está desligado |
| `/usr/local/sbin/telium-firewall-sip aplicar\|desligar\|mostrar` | Monta, apaga ou mostra a tabela |
| `telium-firewall-sip.timer` | Reaplica a cada 5 minutos (tronco novo entra sozinho). Salvar o "Nunca bloquear" no console reaplica em segundos (`systemctl start --no-block`, sem segurar o console) |

Sem o banco, o `aplicar` deixa a tabela que já estava, em vez de montar uma
que cortaria a operadora. No playbook, uma falha do `aplicar` só avisa: o
timer tenta de novo em 5 minutos.

Só o **host** do tronco entra sozinho. Operadora que manda as chamadas de
outro IP (SBC, nuvem como AWS ou Google, mesmo no Brasil) precisa desse IP
em `sip_redes_extras` ou no "Nunca bloquear", senão a entrada não chega.

## Antes de rodar

Os ajustes de cada central ficam **no próprio servidor**, em
`/etc/telium-servidor.yml` (root, `0600`), e não no clone: o mesmo clone
instala e atualiza qualquer central, de dentro dela ou de fora. A primeira
execução cria o arquivo a partir de `ansible/group_vars/all/servidor.yml.exemplo`
e grava nele as respostas da instalação. O que estiver nele ganha do
`main.yml`; o que vier por `-e` ganha dele. Fica fora de `/etc/telium` de
propósito: aquela pasta é do usuário da API, e o arquivo é lido pelo
Ansible como root. Os valores vão **por extenso**: `{{ }}` e `{% %}` não
valem nesse arquivo (o playbook para e diz qual chave usa).

Centrais de até a 1.6.1 tinham esses ajustes em
`infra/ansible/group_vars/all/servidor.yml` do clone que está nelas
(`/opt/TeliumPABX`, ou `/opt/telium-src` nas de até setembro de 2026). A
primeira execução da versão nova (de dentro ou de fora) procura o arquivo
em todo clone do servidor (`find /opt /root /home /srv -maxdepth 7`; de
dentro, vale o do clone que roda), copia para `/etc/telium-servidor.yml` e
renomeia o antigo para `servidor.yml.migrado`. Com mais de um clone com
`servidor.yml`, para sem mudar nada e pede `-e servidor_yml_antigo=<caminho>`.
Sem ajustes, o nome e o IP público vêm do `.env`
da API (`SIP_DOMINIO` e `NAT_IP_PUBLICO`).

**A instalação pergunta** o nome da central (enquanto valer o de fábrica,
`pabx.telium.local`), o cliente, o IP público (servidor atrás de NAT), o IP
do TURN enquanto o nome não resolve, o Janus, o Asternic, o SIP só para
redes conhecidas, o syslog e se é central de cliente ou de laboratório. O
que já está no arquivo não é perguntado. Sem terminal, ou com
`-e perguntar=false`, nada disso é perguntado; o nome vai por
`-e pabx_hostname=voz.cliente.com.br`. Para deixar escrito de antemão, é
uma linha no `/etc/telium-servidor.yml`:

```yaml
pabx_hostname: "pabx.suaempresa.com.br"
```

Esse nome vira o endereço do console, o domínio do TURN e o nome no
certificado.

**Ele precisa resolver e ser alcançável da máquina de quem usa o
console**, não só de dentro do servidor. O TURN sai daí, e o softphone
do navegador depende dele: com um nome que o navegador não resolve — um
`.local`, por exemplo — o navegador não consegue oferecer candidato
nenhum além do endereço da LAN dele, a chamada conecta e não passa
áudio. A mensagem que aparece é a dele mesmo: *“ICE failed, your TURN
server appears to be broken”*.

Se o nome ainda não existe no DNS, aponte o TURN direto para o IP
público:

```yaml
turn_dominio: "200.170.198.144"
```

A tela **Conectividade → WebRTC / Softphone** confere isso e diz qual
servidor de ICE o navegador não vai alcançar.

Duas variáveis que costumam confundir e **não precisam ser mexidas**:

- `nat_ip_publico` e `redes_locais` são apenas o ponto de partida. Quem
  manda depois é o console, em **Conectividade → Configurações de Rede** — inclusive com um
  botão que descobre o endereço público. Trocar de link não exige mais
  editar arquivo nem rodar o playbook.
- `tls_autoassinado: true` gera um certificado de laboratório. O
  navegador vai reclamar na primeira visita; é esperado. Para um
  certificado de verdade, envie o par pelo console em
  **Administrador → Certificados**.

## Instalar

### Da sua máquina, pela rede (recomendado)

Precisa do `ansible-core` (testado com a 2.21; só `ansible.builtin`, sem
coleção), do `sshpass` para entrar com senha, do clone **em dia**
(`git pull`: é o código dele que vai para a central) e de SSH até o
servidor (os da Telium costumam aceitar só as redes da Telium).

```bash
cd infra/ansible
ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k
```

- A vírgula faz do IP um inventário em linha. Várias centrais (só para
  atualizar, todas já instaladas): `-i "IP1,IP2,"`.
- `-k` pede a senha do SSH; com chave, tire-o. Com usuário de `sudo`:
  `-u usuario -K`.
- Rode de dentro de `infra/ansible`: é lá que está o `ansible.cfg`.
- `TeliumPBX_instalacao.yml` só importa o `site.yml`; os plays são
  `hosts: all`. Um arquivo de inventário (`-i inventory/vm.ini`, a partir
  de `vm.ini.exemplo`) também serve.
- Rodando de fora, o playbook **para** se o clone tiver
  `group_vars/all/servidor.yml` ou `vault.yml` (valeriam para toda
  central), e se a versão do clone for **mais antiga** que a instalada
  (`-e permitir_versao_antiga=true` passa por cima).

### Dentro da própria VM

```bash
sudo apt update && sudo apt install -y ansible git
sudo git clone https://gitlab.telium.com.br/Voip/teliumpabx.git /opt/TeliumPABX
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook site.yml
```

O inventário padrão (`inventory/local.ini`) é o `localhost`, com conexão
local. As perguntas e o arquivo de ajustes são os mesmos.

### Instalar com um cenário para testar

Numa máquina de teste, vale subir com um cenário pronto — sem ele,
exercitar qualquer função começa com uns vinte cadastros à mão. Responda
**2 — LABORATÓRIO** na pergunta do ambiente (ou passe
`-e cenario_teste=true`). Vale só naquela execução: nunca vai para o
arquivo de ajustes.

Cria os ramais 1001 a 1004 (o 1003 é WebRTC; o 1004 só tem permissão
local, para provar o bloqueio), grupo de toque 2000, fila 3000 com dois
agentes, conferência 4000, uma URA, horário comercial, DID e rotas. A
senha SIP de todos é fixa e está no repositório
(`infra/sql/opcional/cenario-teste.sql`).

É idempotente, e **número que já existe fica como está**: num servidor
com ramal 1001 de verdade, o script não o renomeia. Não use em
instalação de cliente — a base nasce vazia de propósito.

O cenário **não cria tronco** (não teria como adivinhar as credenciais da
operadora), e as duas rotas de saída dele só nascem se já houver um
tronco cadastrado.

## Áudios do sistema

A central **fala português do Brasil**. O projeto Asterisk não publica
áudios em português; o pacote vem com este projeto, em
`ansible/roles/asterisk/files/sons/pt_BR.tar.xz` (origem, licença
CC BY-SA 3.0 e os acréscimos nossos no `README.md` ao lado), e vai para
`/var/lib/asterisk/sounds/pt_BR/`. O inglês da compilação continua na raiz
de `sounds/`: é o que o Asterisk toca se faltar algum arquivo em português.

Isso vale para os avisos **do sistema**: "todos os circuitos ocupados",
"número inválido", o menu do correio de voz, a posição na fila. O que o
cliente ouve primeiro — saudação, URA, música em espera — são gravações
dele, enviadas pelo console em **Aplicações → Anúncios**, e essas podem
estar em português desde o primeiro dia.

A bateria de testes confere, no fim do provisionamento, se os áudios
existem no idioma configurado: uma instalação sem som para a entrega em
vez de chegar muda ao cliente.

### Para mudar o idioma depois

**O Asterisk não publica áudios em português.** Os idiomas oficiais são
`en`, `en_AU`, `en_GB`, `en_NZ`, `es`, `fr`, `it`, `ja`, `ru` e `sv` —
não existe `pt` nem `pt_BR`. Um pacote em português precisa ser gravado
ou obtido de terceiros.

Quando tiver um, são duas variáveis:

```yaml
pabx_idioma: "pt_BR"
sons_dir: "/caminho/para/os/audios-pt-BR"
```

O conteúdo é copiado como está para `sounds/pt_BR/`, subpastas
inclusive. Formatos que o Asterisk lê direto: `.gsm`, `.wav` (8 kHz
16 bit mono), `.ulaw`, `.alaw`, `.g722`, `.sln`.

**Não mude `pabx_idioma` sem o pacote.** O Asterisk procuraria
`sounds/pt_BR/`, não acharia e cairia no inglês do mesmo jeito — só que
sem ninguém saber. Por isso a bateria falha nesse caso.

### O que um pacote precisa cobrir

Medido no pacote oficial em inglês:

| Conjunto | Arquivos | Para quê |
|---|---|---|
| **Avulsos do dialplan** | **16** | os avisos que este dialplan toca direto |
| `digits/` | 94 | SayNumber, hora certa, posição na fila |
| `vm-*` | 114 | correio de voz e o menu de recados |
| `conf-*` | 38 | conferência: PIN, entrada e saída |
| `queue-*` | 13 | fila: posição e tempo de espera |
| `dir-*` | 15 | catálogo de ramais por nome |
| `letters/`, `phonetic/` | 88 | soletrar nome no catálogo |

Os **16 avulsos** que este dialplan toca diretamente:

```
activated                       de-activated
agent-loggedoff                 demo-echotest
agent-loginok                   goodbye
all-circuits-busy-now           hello
cannot-complete-as-dialed       pbx-invalid
conf-onlyperson                 queue-callswaiting
ss-noservice                    vm-enter-num-to-call
vm-extension                    vm-then-pound
```

Todos os 16 estão no pacote **core**: o dialplan não depende do
`EXTRA-SOUNDS`, que é selecionado na compilação com "falha aqui não
interrompe" e pode simplesmente não entrar.

Esses 16 mais `digits/` já cobrem o que se ouve todos os dias.

### Conferir

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar   # o grupo "Áudios"
sudo asterisk -rx "core show settings" | grep -i language
```

## Senhas

**Você não precisa gerar nada.** As senhas de serviço — banco, usuário
ODBC do CDR, AMI, ARI, Asternic e o segredo do TURN — são criadas na
primeira instalação, com 28 caracteres aleatórios cada, e guardadas em
`/etc/telium/credenciais/` (um arquivo por senha, `0600`, só root). Quem
gera e lê é o próprio servidor (`tarefas/credenciais.yml`, no começo do
`site.yml`): rodando de fora, nada secreto vem da estação, e o usuário dela
não precisa ser root. Reexecutar o playbook reaproveita as mesmas; elas nunca aparecem na tela
nem entram no Git.

**A senha inicial do console é a mesma em toda instalação:**

| Usuário | Senha |
|---|---|
| `admin` | a senha inicial, mostrada no fim da instalação e em `/etc/telium/instalacao.txt` (só root) |

O playbook a define **só na primeira instalação** (a marca é
`/etc/telium/.senha-admin-inicial`); as atualizações não voltam a mexer
nela. **Troque-a no primeiro acesso**, em Meu Perfil e Segurança.

Perdeu a senha: `sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin`.
Perdeu o celular do 2FA: `sudo -u teliumpbx php /opt/telium/api/bin/telium desligar-2fa admin`.

As senhas SIP dos ramais são aleatórias por instalação — ficam no banco e
aparecem na tela de cada ramal.

Para escolher as senhas de serviço você mesmo, copie `vault.yml.exemplo`
para `group_vars/all/vault.yml` e preencha: elas têm precedência sobre as
geradas. Para cifrar: `ansible-vault encrypt group_vars/all/vault.yml`.
Isso vale só rodando **de dentro** do servidor: de fora, o playbook para
se o clone tiver um `vault.yml`.

## Conferir a instalação

O playbook roda a bateria de testes no fim, e **falha ali interrompe a
entrega** — é exatamente o que se quer saber antes de entregar a central.
Para rodar de novo a qualquer momento:

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

Tem de terminar em `✓ N testes, nenhuma falha`. Se falhar, pare: nada
mais que você testar depois será confiável.

Em seguida, os cinco comandos que respondem "a central subiu inteira?":

```bash
sudo asterisk -rx "core reload"            # não pode sair nenhum ERROR
sudo asterisk -rx "pjsip show transports"  # udp, tcp, tls e wss
sudo asterisk -rx "pjsip show endpoints"   # os ramais cadastrados
sudo asterisk -rx "pjsip show registrations"
curl -sk https://127.0.0.1/api/health | head -c 400
```

Se algo falhar, o diagnóstico completo — serviços, versões, portas em
escuta, registros no banco, saída do Asterisk e os últimos erros:

```bash
sudo ansible-playbook verificar.yml                              # de dentro
ansible-playbook -i "200.170.198.176," verificar.yml -u root -k   # de fora
```

O `verificar.yml` só lê: não cria nem migra o arquivo de ajustes e não
aplica a trava do clone com `servidor.yml`.

**É essa saída que eu preciso ver quando algo falhar.**

## Depois de instalado

| O quê | Onde |
|---|---|
| Console | `https://<ip-da-vm>/` |
| API | `https://<ip-da-vm>/api/health` |
| Login | `admin`, com a senha inicial mostrada no fim da instalação e em `/etc/telium/instalacao.txt` (só root) |

Antes de virar a chave num cliente, preencha o
[caderno de aceitação](../docs/CADERNO-DE-TESTE.md): ele separa o que é
bloqueador do que é combinável, e termina num termo assinado. O passeio
pelas telas, módulo a módulo, está em
[../docs/TESTE-FUNCIONAL.md](../docs/TESTE-FUNCIONAL.md).

## Em que idioma a central fala

De fábrica, **português do Brasil** (`pabx_idioma: "pt_BR"`), com o
pacote que vem no projeto — nada é baixado de terceiros. O playbook
instala e confere no disco: a bateria reprova a instalação se faltar um
áudio que o dialplan toca, ou um pedaço dos números em português (cem,
mil, horas), porque `Playback` de arquivo que não existe não é erro para
o Asterisk — ele só segue, e a central fica muda.

Para usar **outro pacote** (outro idioma, ou um português gravado pelo
cliente), diga o idioma e traga o pacote por uma de duas portas, no
`/etc/telium-servidor.yml` da central; com qualquer uma delas, o pacote do
projeto não é instalado:

```yaml
pabx_idioma: "pt_BR"

# um .tar.gz que o SERVIDOR alcance
sons_url:
  - "https://arquivos.suaempresa.com.br/sons-pt_BR.tar.gz"

# ou uma pasta na máquina de onde o playbook roda
sons_dir: "/home/voip/sons-pt-br"
```

Os arquivos vão para `sounds/<idioma>/` como estão, inclusive as
subpastas (`digits/`, `vm-*`, `conf-*`).

## Atualizar uma instalação existente

O passo a passo completo, com backup, conferência e como voltar atrás,
está em [documentacao/02-ATUALIZACAO.md](../documentacao/02-ATUALIZACAO.md).

```bash
git pull
cd infra/ansible
ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k
# de dentro: cd /opt/TeliumPABX && sudo git pull && cd infra/ansible && sudo ansible-playbook site.yml
```

Numa central já instalada (a marca `/etc/telium/.senha-admin-inicial`, ou
uma `Versao.php` publicada em `/opt/telium/api`, para as de antes da marca),
o playbook mostra a versão instalada e a do clone e um menu: **1**
atualizar mantendo tudo (Enter), **2** limpar o banco (pede o nome da
central e, depois, se carrega o cenário de teste; uma central por vez),
**3** cancelar. Limpar e cenário nunca são guardados nos ajustes. Com
`-i "IP1,IP2,"`, todas precisam já estar instaladas (senão a execução
inteira para), a resposta vale para todas e o 2 é recusado. Sem
terminal, ou com `-e perguntar=false`, atualiza direto.

`-e compilar_asterisk=false -e compilar_janus=false` não é mais preciso:
as roles só compilam quando a versão instalada difere da fixada
(`asterisk_versao`, `janus_versao`). Os dois `-e` continuam valendo. O
playbook publica os scripts de banco e aplica todos eles, manda o
código novo, reescreve os `.conf` e recarrega o Asterisk.

Se só o banco estiver atrasado — um `git pull` sem playbook —,
`php bin/telium migrar` no `/opt/telium/api` resolve. Os scripts são
idempotentes; reaplicar é seguro.

Duas coisas o Asterisk só lê ao subir: o `asterisk.conf` e a lista de
módulos. Quando elas mudam, o playbook agenda um reinício para quando a
central estiver sem chamada e avisa no final. Para conferir se já
aconteceu: `sudo asterisk -rx "core show uptime"`.

## Call center e syslog

O serviço de tempo real do call center roda à parte:

```bash
systemctl status telium-cc          # ativo, e reinicia sozinho se cair
journalctl -u telium-cc -f          # o que ele está fazendo
```

Ele escuta só em `127.0.0.1:8095`; o nginx publica `/api/cc/eventos` e
nada mais. O `queue_log` das filas vai para o banco (tabela `queue_log`),
e é de lá que saem os relatórios.

Os logs do Asterisk (facility `local5`), de autenticação e do sistema
são encaminhados ao syslog central do grupo VoIP (`syslog_central_*` em
`group_vars/all/main.yml`). Os arquivos locais do Asterisk continuam:
o fail2ban lê o `security`. `syslog_central: false` desliga.

## Comandos úteis na VM

```bash
cd /opt/telium/api
php bin/telium doctor           # banco, AMI e permissões de escrita
php bin/telium testar           # a bateria completa
php bin/telium migrar           # aplica os scripts de banco que faltam
php bin/telium gerar-config     # regenera os .conf a partir do banco
php bin/telium aplicar-config   # gera e recarrega o Asterisk
php bin/telium senha admin      # troca a senha de um usuário

sudo asterisk -rvvv             # console do Asterisk
sudo asterisk -rx "pjsip set logger on"   # trace SIP no log
tail -f /var/log/asterisk/full
tail -f /var/log/telium/php-api.log
```

## Zerar a base operacional

Pelo menu da atualização (opção **2**), que pede o nome da central para
confirmar. O `-e limpar_banco=true` faz o mesmo **sem perguntar**, e vale
mesmo que no menu se escolha 1.

Apaga ramais, troncos, filas, URAs, rotas, CDR, gravações, contatos e
todos os usuários menos o `admin`. Preserva empresa, perfis, permissões e
a conta `admin`. **É destrutivo e não tem volta** — o backup é seu.

## Reprovisionar só um pedaço

Não use `--start-at-task`: ele pula as etapas iniciais, que leem os
ajustes da central e as senhas de serviço do servidor, e o playbook para
no meio (ou publica o console com o nome de fábrica). Rode o playbook
inteiro: o que já está certo não é refeito, e o Asterisk só é compilado
quando muda de versão.
