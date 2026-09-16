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

## Antes de rodar

Ajuste `ansible/group_vars/all/main.yml`. O mínimo é uma linha:

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

### Dentro da própria VM (mais simples)

```bash
sudo apt update && sudo apt install -y ansible git
git clone https://github.com/guilhermeleao11/TeliumPABX.git /opt/telium-src
cd /opt/telium-src/infra/ansible
sudo ansible-playbook site.yml
```

### Da estação de desenvolvimento, por SSH

```bash
cp inventory/vm.ini.exemplo inventory/vm.ini   # ajuste IP e usuário
ansible-playbook -i inventory/vm.ini site.yml
```

### Instalar com um cenário para testar

Numa máquina de teste, vale subir com um cenário pronto — sem ele,
exercitar qualquer função começa com uns vinte cadastros à mão:

```bash
sudo ansible-playbook site.yml -e cenario_teste=true
```

Cria os ramais 1001 a 1004 (o 1003 é WebRTC; o 1004 só tem permissão
local, para provar o bloqueio), grupo de toque 2000, fila 3000 com dois
agentes, conferência 4000, uma URA, horário comercial, DID e rotas. A
senha SIP de todos é `T3st3_R4mal_@2024`.

É idempotente, e **número que já existe fica como está**: num servidor
com ramal 1001 de verdade, o script não o renomeia. Não use em
instalação de cliente — a base nasce vazia de propósito.

O cenário **não cria tronco** (não teria como adivinhar as credenciais da
operadora), e as duas rotas de saída dele só nascem se já houver um
tronco cadastrado.

## Áudios do sistema

A central **fala inglês**, que é o que o projeto Asterisk publica. Os
áudios vêm na compilação (`CORE-SOUNDS-EN` e `EXTRA-SOUNDS-EN`) e ficam
na raiz de `/var/lib/asterisk/sounds/`.

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
sudo -u telium php /opt/telium/api/bin/telium testar   # o grupo "Áudios"
sudo asterisk -rx "core show settings" | grep -i language
```

## Senhas

**Você não precisa gerar nada.** As senhas de serviço — banco, usuário
ODBC do CDR, AMI, ARI e o segredo do TURN — são criadas na primeira
instalação, com 28 caracteres aleatórios cada, e guardadas em
`/etc/telium/credenciais/` (um arquivo por senha, `0600`, só root).
Reexecutar o playbook reaproveita as mesmas; elas nunca aparecem na tela
nem entram no Git.

**A senha do console é fixa e conhecida:**

| Usuário | Senha |
|---|---|
| `admin` | `T3l1um_@2024_@aD1m` |

Ela é **restaurada a cada execução do playbook**, de propósito: garante
que sempre exista uma forma conhecida de entrar, mesmo que a conta tenha
sido bloqueada por tentativas erradas. Se você quer uma conta
administrativa com senha própria, crie um segundo usuário pelo console.

Trocar depois: `php bin/telium senha admin`.

As senhas SIP dos ramais são aleatórias por instalação — ficam no banco e
aparecem na tela de cada ramal.

Para escolher as senhas de serviço você mesmo, copie `vault.yml.exemplo`
para `group_vars/all/vault.yml` e preencha: elas têm precedência sobre as
geradas. Para cifrar: `ansible-vault encrypt group_vars/all/vault.yml`.

## Conferir a instalação

O playbook roda a bateria de testes no fim, e **falha ali interrompe a
entrega** — é exatamente o que se quer saber antes de entregar a central.
Para rodar de novo a qualquer momento:

```bash
sudo -u telium php /opt/telium/api/bin/telium testar
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
sudo ansible-playbook verificar.yml
```

**É essa saída que eu preciso ver quando algo falhar.**

## Depois de instalado

| O quê | Onde |
|---|---|
| Console | `https://<ip-da-vm>/` |
| API | `https://<ip-da-vm>/api/health` |
| Login | `admin` / `T3l1um_@2024_@aD1m` |

O roteiro de teste, módulo a módulo, está em
[../docs/TESTE-FUNCIONAL.md](../docs/TESTE-FUNCIONAL.md).

## Atualizar uma instalação existente

```bash
cd /opt/telium-src && git pull
cd infra/ansible
sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false
```

Os dois `-e` pulam as compilações, que é a parte demorada e quase nunca
muda. O playbook publica os scripts de banco e aplica todos eles, manda o
código novo, reescreve os `.conf` e recarrega o Asterisk.

Se só o banco estiver atrasado — um `git pull` sem playbook —,
`php bin/telium migrar` no `/opt/telium/api` resolve. Os scripts são
idempotentes; reaplicar é seguro.

Duas coisas o Asterisk só lê ao subir: o `asterisk.conf` e a lista de
módulos. Quando elas mudam, o playbook agenda um reinício para quando a
central estiver sem chamada e avisa no final. Para conferir se já
aconteceu: `sudo asterisk -rx "core show uptime"`.

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

```bash
sudo ansible-playbook site.yml -e limpar_banco=true
```

Apaga ramais, troncos, filas, URAs, rotas, CDR, gravações, contatos e
todos os usuários menos o `admin`. Preserva empresa, perfis, permissões e
a conta `admin`. **É destrutivo e não tem volta** — o backup é seu.

## Reprovisionar só um pedaço

```bash
sudo ansible-playbook site.yml --start-at-task="Publicar o código da API"
```
