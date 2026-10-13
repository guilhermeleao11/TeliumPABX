# Instalação

Como subir uma central nova do zero, até a primeira chamada. Uma execução do playbook Ansible instala a central inteira: MariaDB, PHP 8.4, nginx com HTTPS, Asterisk 22 compilado do fonte, TURN, backup, firewall, o serviço do call center e o console.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [O que você precisa](#1-o-que-você-precisa)
2. [O nome do servidor (DNS)](#2-o-nome-do-servidor-dns)
3. [Preparar o servidor e clonar o repositório](#3-preparar-o-servidor-e-clonar-o-repositório)
4. [Ajustes do servidor: servidor.yml](#4-ajustes-do-servidor-servidoryml)
5. [Rodar o playbook](#5-rodar-o-playbook)
6. [Opções da linha de comando](#6-opções-da-linha-de-comando)
7. [O que cada parte instala](#7-o-que-cada-parte-instala)
8. [Primeiro acesso ao console](#8-primeiro-acesso-ao-console)
9. [Certificado](#9-certificado)
10. [Checklist pós-instalação](#10-checklist-pós-instalação)
11. [Conferir a instalação](#11-conferir-a-instalação)
12. [Problemas comuns](#12-problemas-comuns)

---

## 1. O que você precisa

**Servidor**

| Item | O que vale |
|---|---|
| Sistema operacional | **Debian 13 (trixie)**, instalação limpa. É o alvo homologado. Ubuntu 24.04 também roda (para teste). Outra família que não seja Debian é recusada logo no início |
| Acesso | `root` ou usuário com `sudo` |
| Internet | Saída para a internet: o playbook baixa pacotes (Debian, MariaDB, nginx.org) e o código-fonte do Asterisk |
| Tamanho de partida | **2 vCPU, 4 GB de RAM e 40 GB de disco** (recomendação da documentação do projeto, não um limite do playbook) |
| Disco | As gravações de chamada são o que mais ocupa. Calcule o espaço pela retenção que o cliente quer |

- O limite de carga do Asterisk (`maxload`) acompanha o número de vCPUs da máquina: 1,5 × vCPUs.
- O limite de canais simultâneos (`maxcalls`) é 480 (cada chamada usa dois canais).

**Rede**

- Um **nome no DNS** apontando para o servidor (seção 2).
- As portas liberadas até o servidor. O firewall do próprio servidor (ufw) é aberto pelo playbook; um firewall **na frente** dele (nuvem, roteador, NAT) precisa ser liberado por você. A lista completa está em [Requisitos de rede e portas](03-Requisitos-Rede-e-Portas.md).

> A faixa de áudio **UDP 10000–20000** é a que mais se esquece de liberar, e a falha não dá erro: **a chamada conecta e ninguém ouve.**

---

## 2. O nome do servidor (DNS)

O nome do servidor (variável `pabx_hostname`) vira:

- o endereço do console: `https://<nome>/`;
- o nome no certificado de fábrica;
- o domínio SIP dos telefones;
- o domínio do TURN (áudio do softphone do navegador), salvo se `turn_dominio` for trocado.

Regras (o playbook confere e recusa, explicando o motivo):

- Precisa apontar para o servidor no DNS e ser **alcançável de quem usa o console**, não só de dentro do servidor.
- Letras, números, hífen e pontos; **sem `_`**; a última parte começa por letra. Ex.: `voz.cliente.com.br`.
- **Não pode terminar em `.local`**: o navegador não resolve e o softphone fica sem áudio.
- Ainda sem DNS? Use o **IP público** (IPv4) do servidor.
- O nome de fábrica `pabx.telium.local` não serve; enquanto ele estiver valendo, o playbook pergunta.

O playbook também grava o nome em `/etc/hosts` como `127.0.1.1 <nome>`.

---

## 3. Preparar o servidor e clonar o repositório

O repositório oficial é o GitLab da Telium: `gitlab.telium.com.br/Voip/teliumpabx`. O GitLab pede usuário e token (ou uma chave de implantação).

```bash
sudo apt update && sudo apt install -y ansible git
sudo git clone https://gitlab.telium.com.br/Voip/teliumpabx.git /opt/TeliumPABX
cd /opt/TeliumPABX/infra/ansible
```

- O clone fica em `/opt/TeliumPABX`. O código publicado (o que roda de verdade) vai para `/opt/telium`.
- O inventário padrão (`inventory/local.ini`) roda o playbook **dentro da própria máquina** (`localhost`, conexão local).

**Instalar a partir de outra máquina, por SSH** (alternativa):

```bash
cd infra/ansible
cp inventory/vm.ini.exemplo inventory/vm.ini   # ajuste IP, usuário e senha do sudo
ansible-playbook -i inventory/vm.ini site.yml
```

O `inventory/vm.ini` fica fora do Git.

---

## 4. Ajustes do servidor: servidor.yml

Os ajustes de **cada servidor** ficam em `infra/ansible/group_vars/all/servidor.yml`, **fora do Git**:

- o `git pull` da atualização nunca conflita com ele;
- o que estiver nele **ganha** do `main.yml` (o Ansible lê a pasta em ordem alfabética e o que vem depois vale).

A primeira execução cria o `servidor.yml` sozinha, a partir de `servidor.yml.exemplo`, e grava nele o nome que você responder. Para ajustar **antes** da primeira instalação:

```bash
cd /opt/TeliumPABX/infra/ansible/group_vars/all
sudo cp servidor.yml.exemplo servidor.yml
sudo nano servidor.yml
```

Ponha no `servidor.yml` só o que muda neste servidor, uma linha por variável, por exemplo:

```yaml
pabx_hostname: "voz.cliente.com.br"
pabx_empresa: "Cliente S.A."
syslog_central: false
```

> **Nunca edite o `main.yml`.** Ele vai para o Git: editado, faz o `git pull` da atualização recusar. Veja [Atualização](02-Atualizacao.md).

### Variáveis que o implantador pode querer mudar

Todas vêm de `infra/ansible/group_vars/all/main.yml`, com o valor de fábrica abaixo.

**Identidade**

| Variável | Fábrica | O que faz |
|---|---|---|
| `pabx_hostname` | `pabx.telium.local` | Nome da central (seção 2). Perguntado na instalação |
| `pabx_empresa` | `Telium Networks` | Nome da empresa: vai para o certificado de fábrica e para o dialplan |
| `pabx_timezone` | `America/Sao_Paulo` | Fuso horário do servidor |
| `pabx_locale` | `pt_BR.UTF-8` | Locale do sistema |
| `pabx_idioma` | `pt_BR` | Idioma em que a central fala. Só mude junto com um pacote de áudios (`sons_url` ou `sons_dir`) |
| `senha_padrao_console` | (senha de fábrica) | Senha inicial do `admin`. Só é usada na **primeira** instalação |

**Áudios do sistema**

| Variável | Fábrica | O que faz |
|---|---|---|
| `sons_url` | vazio | Lista de `.tar.gz` baixados pelo servidor e descompactados em `sounds/<idioma>/`. Preenchida, o pacote pt_BR do projeto não é instalado |
| `sons_dir` | vazio | Pasta na máquina de onde o playbook roda, copiada para `sounds/<idioma>/`. Mesma regra |

Se `sons_url` ou `sons_dir` for declarado e falhar, o playbook **para**.

**Rede e NAT (ponto de partida)**

| Variável | Fábrica | O que faz |
|---|---|---|
| `nat_ip_publico` | vazio | IP público. Só o valor inicial: depois quem manda é o console, em **Conectividade › Configurações de Rede** |
| `redes_locais` | 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 | Faixas da rede interna. Mesmo caso do anterior |
| `sip_porta` | 5060 | Porta SIP (UDP e TCP) |
| `sip_tls_porta` | 5061 | Porta SIP sobre TLS |
| `ast_rtp_inicio` / `ast_rtp_fim` | 10000 / 20000 | Faixa de áudio (RTP). A mesma que o firewall abre |
| `udptl_inicio` / `udptl_fim` | 4000 / 4999 | Faixa do fax T.38 |
| `stun_descoberta` | stun.l.google.com:19302, stun.cloudflare.com:3478 | A quem o botão **Descobrir** pergunta o IP público. Vazio desliga o botão |
| `softphone_stun` | stun:stun.l.google.com:19302 | STUN que o **navegador** usa |
| `rtp_stunaddr` | vazio | STUN do próprio Asterisk. Deixe vazio (preenchido, um endereço que não responde atrasa cada chamada em 9 segundos) |

Trocar porta SIP depois da instalação exige rodar o playbook de novo: o Asterisk não troca a porta de escuta num simples recarregamento.

**Softphone do navegador e TURN**

| Variável | Fábrica | O que faz |
|---|---|---|
| `webrtc_habilitado` | `false` | Ponto de partida do telefone pelo navegador. Depois, quem liga e desliga é o console, em **Conectividade › WebRTC / Softphone** |
| `instalar_turn` | `true` | Instala o coturn (TURN próprio) |
| `turn_dominio` | o `pabx_hostname` | Nome ou IP que o navegador usa para chegar ao TURN. Sem DNS ainda, ponha o IP público |
| `turn_porta` / `turn_porta_tls` | 3478 / 5349 | Portas do TURN |
| `turn_rtp_inicio` / `turn_rtp_fim` | 49152 / 49500 | Faixa de mídia retransmitida pelo TURN |
| `turn_sessoes_max` | 200 | Teto de sessões no TURN |
| `turn_max_bps` | 128000 | Teto de banda por sessão |
| `turn_peers_liberados` | vazio | Redes internas que o TURN pode alcançar. Vazio é o certo |
| `turn_segredo` | vazio | Vazio: gerado na instalação |

**Certificado**

| Variável | Fábrica | O que faz |
|---|---|---|
| `tls_autoassinado` | `true` | Gera o certificado de fábrica (autoassinado). O certificado de verdade é enviado depois pelo console |

**Execução e componentes**

| Variável | Fábrica | O que faz |
|---|---|---|
| `compilar_asterisk` | `true` | Compila o Asterisk se a versão instalada for diferente de `asterisk_versao` |
| `instalar_janus` | `false` | Instala o Janus. Opcional: o softphone do navegador **não** usa Janus |
| `compilar_janus` | `true` | Compila o Janus se a versão for diferente (só vale com `instalar_janus`) |
| `instalar_firewall` | `true` | Abre as portas no ufw e liga o firewall |
| `asterisk_max_chamadas` | 480 | `maxcalls` do Asterisk (canais simultâneos) |
| `asterisk_max_carga` | 1,5 × vCPUs | `maxload` do Asterisk |

**Syslog central**

| Variável | Fábrica | O que faz |
|---|---|---|
| `syslog_central` | `true` | Encaminha os logs ao syslog do grupo VoIP da Telium. `false` desliga |
| `syslog_central_asterisk` | (IP da Telium) | Destino dos logs do Asterisk |
| `syslog_central_auth` | (IP da Telium) | Destino dos logs de autenticação |
| `syslog_central_sistema` | (IP da Telium) | Destino do resto do sistema |
| `syslog_central_porta` | 514 | Porta UDP do syslog |

Num cliente com outro syslog, troque os IPs; sem syslog central, `syslog_central: false`.

**Não mexa** (servem para o projeto, não para a implantação): versões fixadas (`asterisk_versao`, `janus_versao`, `mariadb_versao`, `nginx_canal`), caminhos (`telium_base`, `backup_dir`, `gravacoes_dir` etc.), usuários de sistema e portas internas (`ast_http_porta`, `ami_porta`, portas do Janus).

### Senhas de serviço

Não é preciso gerar nada. As senhas do banco, do usuário ODBC do CDR, do AMI, da ARI e o segredo do TURN são criadas na primeira instalação (28 caracteres aleatórios cada) e guardadas em `/etc/telium/credenciais/`, um arquivo por senha, só para root. Rodar o playbook de novo reaproveita as mesmas.

Para escolher essas senhas você mesmo: copie `infra/ansible/vault.yml.exemplo` para `group_vars/all/vault.yml` e preencha (ou rode `./gerar-vault.sh`). Elas têm precedência sobre as geradas. Para cifrar: `ansible-vault encrypt group_vars/all/vault.yml`. O `vault.yml` fica fora do Git.

---

## 5. Rodar o playbook

```bash
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook site.yml
```

O que acontece, na ordem:

1. **Pergunta do nome**, se ainda valer o de fábrica. A resposta é conferida e gravada no `servidor.yml`; as execuções seguintes não perguntam mais.
2. **Resumo** do que será instalado (nome, sistema, versões).
3. As partes (roles), na ordem da seção 7. A **compilação do Asterisk leva de 5 a 15 minutos**: é a parte demorada.
4. A **bateria de testes** (`telium testar`). Uma falha ali **interrompe o playbook**. É o que se quer: não entregue uma central que não passou.
5. Resumo final com o endereço do console, salvo também em `/etc/telium/instalacao.txt` (só root).

O resultado tem de terminar com `failed=0`.

> 📷 **Print:** terminal com a pergunta "Qual é o nome deste servidor?".

> 📷 **Print:** fim da execução com o `PLAY RECAP` em `failed=0`.

**Rodando sem terminal** (automação), passe o nome na linha de comando; sem nome, a instalação para em vez de seguir com um nome errado:

```bash
sudo ansible-playbook site.yml -e pabx_hostname=voz.cliente.com.br
```

Se o AMI não autenticar durante a instalação, o playbook avisa no fim. Investigue com `sudo bash infra/diagnostico-ami.sh` (a partir da pasta do clone).

---

## 6. Opções da linha de comando

Toda opção vai com `-e`. O playbook pode ser rodado quantas vezes for preciso: ele só mexe no que mudou.

| Opção | O que faz | Apaga dados? |
|---|---|---|
| `-e pabx_hostname=voz.cliente.com.br` | Informa o nome sem pergunta | Não |
| `-e compilar_asterisk=false` | Não compila o Asterisk, mesmo que a versão tenha mudado | Não |
| `-e compilar_janus=false` | Não compila o Janus | Não |
| `-e instalar_janus=true` | Instala o Janus (opcional; fora do caminho das chamadas) | Não |
| `-e instalar_turn=false` | Não instala o TURN; o softphone fica sem TURN próprio | Não |
| `-e instalar_firewall=false` | Não abre portas nem liga o ufw | Não |
| `-e cenario_teste=true` | Carrega um cenário de teste (veja abaixo) | Não apaga, mas **cria cadastros com senha conhecida** |
| `-e limpar_banco=true` | **Zera a base operacional** (veja abaixo) | **SIM. Destrutivo, sem volta** |

Também existe `--start-at-task` do Ansible, para reprovisionar só a partir de uma etapa:

```bash
sudo ansible-playbook site.yml --start-at-task="Publicar o código da API"
```

### -e limpar_banco=true — APAGA DADOS

> **Destrutivo e sem volta. Nunca numa central com cliente.** O backup é seu.

Roda o script `infra/sql/opcional/limpar.sql` e reaplica os dados essenciais. Apaga:

- ramais, dispositivos, troncos, filas e seus agentes, URAs, grupos de toque, rotas de entrada e de saída, condições horárias, PIN sets e tarifas;
- CDR, CEL, eventos, o registro das gravações, contatos, integrações, auditoria e sessões;
- **todos os usuários, menos o `admin`**.

Preserva: empresa, perfis e permissões, a conta `admin` e os grupos de horário padrão. Os arquivos de gravação em disco não são apagados por ele.

### -e cenario_teste=true — só em servidor de teste

Roda `infra/sql/opcional/cenario-teste.sql`: ramais 1001 a 1004 (o 1003 é WebRTC; o 1004 só tem permissão local), grupo de toque 2000, fila 3000 com dois agentes, conferência 4000, uma URA, horário comercial, DID e rotas.

- **A senha SIP de todos esses ramais é fixa e está no repositório.** Numa central exposta à internet, isso é uma porta aberta para fraude.
- Número que já existir fica como está (não sobrescreve), mas os cadastros novos entram na base do cliente.
- Não cria tronco; as rotas de saída do cenário só nascem se já houver um tronco.
- **Nunca em instalação de cliente**: a base nasce vazia de propósito.

---

## 7. O que cada parte instala

O playbook `site.yml` roda estas roles, nesta ordem:

| Role | O que faz |
|---|---|
| `common` | Confere o sistema (família Debian), instala utilitários (curl, git, jq, chrony, tcpdump etc.), define fuso e locale pt_BR, cria a conta de serviço `teliumpbx` e os diretórios (`/opt/telium`, `/etc/telium`, `/var/log/telium`), grava o nome em `/etc/hosts` e gera o certificado de fábrica em `/etc/ssl/telium/` |
| `mariadb` | MariaDB 12.3 do repositório oficial, ouvindo só em 127.0.0.1; cria o banco `telium_pabx` e os usuários; aplica todos os scripts de `infra/sql/` (idempotentes); ODBC para o Asterisk gravar CDR/CEL. Aqui rodam `limpar_banco` e `cenario_teste` |
| `nginx` | nginx (canal mainline, repositório nginx.org) com HTTPS, o site do console, a API, o `/ws` do softphone e o provisionamento de telefones |
| `php` | PHP 8.4 com FPM e um pool dedicado à API |
| `asterisk` | Compila e instala o Asterisk 22 (só se a versão mudou), instala os áudios em português, a música de espera, os arquivos de configuração base, o logrotate, o despertador e a unidade systemd; testa o AMI |
| `janus` | Só com `instalar_janus=true`: compila e instala o Janus |
| `turn` | Só com `instalar_turn` (padrão: sim): coturn com credencial temporária derivada de um segredo |
| `backup` | Diretório `/var/backup/pabx-telium`, o executor e o temporizador do backup |
| `certificados` | O cofre `/etc/telium/certificados` e o aplicador que copia o certificado enviado pelo console para os serviços |
| `email` | msmtp, para o envio de e-mail (recado do correio de voz, alertas); a configuração vem do console |
| `api` | Publica o console (`/opt/telium/web`) e a API (`/opt/telium/api`), escreve o `.env`, define a senha inicial do `admin` (só na primeira vez), gera e aplica a configuração do Asterisk, instala a tarifação (de hora em hora) e o serviço do call center `telium-cc`, e roda a bateria de testes |
| `syslog` | Regras do rsyslog para o syslog central (ou as retira, com `syslog_central: false`) |
| `seguranca` | fail2ban (SIP, console, SSH) e ufw com as portas da central |

Detalhes de cada serviço: [Comandos e serviços](05-Servidor-Comandos-e-Servicos.md). Onde fica cada arquivo: [Diretórios, arquivos e logs](06-Diretorios-Arquivos-e-Logs.md).

---

## 8. Primeiro acesso ao console

| Campo | Valor |
|---|---|
| Endereço | `https://<nome-do-servidor>/` |
| Usuário | `admin` |
| Senha | a inicial de fábrica, mostrada no fim do playbook e em `/etc/telium/instalacao.txt` |

- Na primeira visita o navegador avisa que o certificado não é confiável. É esperado: é o certificado de fábrica (seção 9).
- **Troque a senha no primeiro acesso**, em **PCU — Meu Painel › Meu Perfil e Segurança**. A senha inicial é a mesma em toda central e está no repositório.
- O playbook define a senha do `admin` **uma vez só** por servidor; as atualizações não voltam a mexer nela.
- Para uma senha inicial diferente, ponha `senha_padrao_console` no `servidor.yml` antes de instalar.
- Perdeu a senha? No servidor:

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin
```

> 📷 **Print:** tela de login do console.

---

## 9. Certificado

- A instalação gera um **certificado de fábrica** autoassinado (válido por 10 anos), com o nome do servidor, `localhost` e o IP da máquina. Ele é usado no console (nginx), no SIP TLS (5061) e no TURN (5349).
- O navegador reclama dele, e o Linphone recusa. Para produção, envie o certificado de verdade pelo console em **Administrador › Certificados**, escolha onde ele vale e clique em **Aplicar nos serviços**.
- O par de fábrica fica guardado à parte: é para onde a central volta se o certificado enviado for removido.
- Trocou o `pabx_hostname`? Rode o playbook de novo: o certificado de fábrica é refeito com o nome novo. Um certificado enviado pelo console não é tocado.

> 📷 **Print:** Administrador › Certificados, com os cartões de cada serviço.

Mais em [Segurança](08-Seguranca.md) e [Administrador](12-Administrador.md).

---

## 10. Checklist pós-instalação

Pelo console, nesta ordem. Os detalhes de cada tela estão em [Conectividade](17-Conectividade.md).

1. **Trocar a senha do `admin`** (seção 8).
2. **Conectividade › Configurações de Rede**: confira o **Endereço público** (o botão **Descobrir** pergunta a um servidor de fora) e as **Faixas da rede interna**. Em servidor com IP público direto, deixe o endereço público em branco. Salve.
3. **Administrador › Certificados**: envie o certificado de verdade e clique em **Aplicar nos serviços**.
4. **Conectividade › Ramais**: crie o primeiro ramal (ou vários com **Importar CSV**; o botão **Modelo CSV** baixa a planilha de exemplo).
5. **Conectividade › Troncos**: cadastre a operadora.
6. **Conectividade › Rotas de Saída**: por onde as chamadas saem (padrão de número e tronco).
7. **Conectividade › Rotas de Entrada**: para onde vão as chamadas que chegam (DID e destino). Cadastre também uma rota com DID em branco, para tronco que não entrega número.
8. **Aplicar configurações**: a faixa amarela no topo do console avisa quando há algo pendente. Sem isso, nada do que foi cadastrado vale no Asterisk.
9. **Administrador › Backup & Restauração**: crie uma rotina de backup e, de preferência, um destino fora do servidor. Veja [Backup e restauração](04-Backup-e-Restauracao.md).
10. **Teste de chamada**:
    - registre um telefone ou softphone no ramal novo (usuário e senha SIP estão na tela do ramal; servidor = nome da central, porta 5060);
    - ramal para ramal;
    - de fora para dentro (pelo número da operadora);
    - de dentro para fora;
    - confira que **há áudio nos dois sentidos**.
11. Se o cliente vai usar o telefone pelo navegador: **Conectividade › WebRTC / Softphone**, **Ligar o telefone pelo navegador** e **Testar agora**. Veja [Telefone WebRTC](11-Telefone-WebRTC.md).

> 📷 **Print:** faixa amarela "Alterações ainda não aplicadas" com o botão **Aplicar configurações**.

Antes de entregar a um cliente, preencha o caderno de aceitação (`docs/CADERNO-DE-TESTE.md` no repositório).

---

## 11. Conferir a instalação

A bateria de testes (tem de terminar sem falha):

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

Os comandos que respondem "a central subiu inteira?":

```bash
sudo asterisk -rx "core reload"             # não pode sair nenhum ERROR
sudo asterisk -rx "pjsip show transports"   # udp, tcp, tls e wss
sudo asterisk -rx "pjsip show endpoints"    # os ramais cadastrados
sudo asterisk -rx "pjsip show registrations"
curl -sk https://127.0.0.1/api/health | head -c 400
systemctl is-active telium-cc
```

Diagnóstico completo (serviços, versões, portas em escuta, banco, Asterisk e últimos erros):

```bash
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook verificar.yml
```

Há também o relatório compacto `sudo bash /opt/TeliumPABX/infra/diagnostico.sh`. Mais em [Solução de problemas](07-Solucao-de-Problemas.md).

---

## 12. Problemas comuns

### O playbook recusa o nome do servidor
O nome termina em `.local`, tem `_`, ou a última parte não começa por letra. Use o nome do DNS (ex.: `voz.cliente.com.br`) ou o IP público.

### "Nenhum nome foi informado"
O playbook rodou sem terminal e não havia nome no `servidor.yml`. Passe `-e pabx_hostname=voz.cliente.com.br`.

### O playbook parou na bateria de testes
Não entregue a central. Copie a saída, rode `sudo ansible-playbook verificar.yml` e veja [Solução de problemas](07-Solucao-de-Problemas.md).

### A chamada completa e ninguém ouve
Quase sempre é a faixa **UDP 10000–20000** não chegando ao servidor (firewall da nuvem, roteador, NAT) ou o endereço público errado em **Conectividade › Configurações de Rede**. Veja [Requisitos de rede e portas](03-Requisitos-Rede-e-Portas.md).

### O tronco não registra
Servidor atrás de NAT sem endereço público configurado: o convite SIP sai com o IP interno. Preencha o **Endereço público** em **Conectividade › Configurações de Rede** e **Aplique configurações**.

### Entra pelo IP, mas não pelo nome
Confira o DNS (`nslookup voz.cliente.com.br` deve devolver o IP do servidor) e se o certificado em uso cobre o nome digitado.
