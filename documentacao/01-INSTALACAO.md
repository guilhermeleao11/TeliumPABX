# 01 — Instalação

Um servidor novo, do zero até o primeiro login. Uma execução do playbook
instala a central inteira: MariaDB, PHP 8.4, nginx com TLS, Asterisk 22
compilado, TURN, backup, firewall, o serviço do call center e o console.

## 1. O que você precisa

**Servidor**
- **Debian 13 (trixie)**, instalação limpa. Ubuntu 24.04 também funciona.
- Acesso **root** (ou `sudo`).
- Saída para a internet: o playbook baixa pacotes e o código do Asterisk.
- Recomendação de partida: **2 vCPU, 4 GB de RAM e 40 GB de disco**. As
  gravações de chamada são o que mais ocupa disco: calcule o espaço pela
  retenção que o cliente quer.

**Rede**
- Um **nome no DNS** apontando para o servidor (ex.: `voz.cliente.com.br`).
  Ele vira o endereço do console, o domínio do TURN e o nome no
  certificado. Precisa ser alcançável **de quem usa o console**, não só
  de dentro do servidor. Começa por letra e não tem `_`.
- As portas abaixo chegando ao servidor. O firewall do próprio servidor
  (ufw) é aberto pelo playbook; um firewall **na frente** dele (da nuvem,
  do roteador, NAT) precisa ser liberado por você.

| Porta | Protocolo | Para quê |
|---|---|---|
| 22 | TCP | SSH |
| 80 e 443 | TCP | console, API e o WebSocket do softphone do navegador |
| 5060 | UDP e TCP | SIP — telefones e operadora |
| 5061 | TCP | SIP sobre TLS |
| **10000–20000** | **UDP** | **áudio das chamadas (RTP)** |
| 4000–4999 | UDP | fax T.38 |
| 3478 | UDP e TCP | TURN/STUN — áudio do softphone do navegador |
| 5349 | TCP | TURN sobre TLS |
| 49152–49500 | UDP | áudio retransmitido pelo TURN |

> A faixa de RTP é a que mais se esquece de abrir, e a falha não dá erro:
> **a chamada conecta e ninguém ouve.**

## 2. Preparar a sua máquina

O jeito recomendado é instalar **da sua máquina**, pela rede: o servidor só
precisa do Debian instalado e do SSH aberto para você.

```bash
sudo apt update && sudo apt install -y ansible git sshpass
git clone https://gitlab.telium.com.br/Voip/teliumpabx.git ~/TeliumPABX
```

- **Ansible**: o `ansible-core` (testado com a 2.21). O playbook usa só os
  módulos que vêm com ele, sem coleção extra.
- **`sshpass`**: só para entrar no SSH com senha (`-k`). Com chave SSH, não
  precisa.
- **O clone em dia**: o código que vai para a central é o do seu clone.
  Antes de cada instalação ou atualização, `git pull`.
- **SSH até o servidor**: os servidores da Telium costumam aceitar SSH só
  das redes da Telium. Fora delas, entre pela VPN.
- Na sua máquina, o seu **usuário comum** basta (sem root). Nada secreto
  vem dela: as senhas de serviço são geradas e lidas no próprio servidor.

## 3. Instalar

Na pasta `infra/ansible` do clone (o comando precisa do `ansible.cfg` que
está nela):

```bash
cd ~/TeliumPABX/infra/ansible
ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k
```

- A **vírgula** depois do IP diz ao Ansible que aquilo é a lista de
  servidores, e não um arquivo.
- `-k` pede a senha do SSH. Com chave SSH, tire o `-k`. Com um usuário que
  tem `sudo`: `-u usuario -K` (e `-k`, se ele entra por senha). O Debian, de
  fábrica, não deixa o `root` entrar por senha.
- `TeliumPBX_instalacao.yml` é o mesmo `site.yml` de sempre, com outro nome.
- A **compilação do Asterisk leva de 5 a 15 minutos**. É a parte demorada.
- No fim, o playbook roda a **bateria de testes**, e uma falha ali
  **interrompe a entrega**. É o que se quer: não entregue uma central que
  não passou.
- O resultado precisa terminar com `failed=0`.

**De dentro do servidor** (quando não há SSH da sua máquina), como antes:

```bash
sudo apt update && sudo apt install -y ansible git
sudo git clone https://gitlab.telium.com.br/Voip/teliumpabx.git /opt/TeliumPABX
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook site.yml
```

As perguntas, os ajustes e o resultado são os mesmos.

## 4. As perguntas

Numa central nova, o playbook pergunta o que muda de um cliente para
outro, mostra um resumo e só instala depois do **Instalar? [S/n]**:

| Pergunta | O que responder |
|---|---|
| Nome do servidor | O nome do DNS (ex.: `voz.cliente.com.br`). Ainda sem DNS, o IP público. Um nome `.local` ou com `_` é recusado |
| Nome do cliente | A empresa dona da central |
| IP público | Só aparece com o servidor atrás de NAT (IP interno). O Enter sugere o IP do `-i`, se for público; sem IP público, `nenhum` |
| IP para o áudio | Só aparece se o nome ainda não está no DNS: o IP público, para o softphone do navegador ter áudio até o DNS sair |
| Instalar o Janus? | Na dúvida, não. Nenhuma chamada passa por ele |
| Instalar o Asternic? | Só com licença comprada para **este** servidor (presa ao MAC) |
| Restringir o SIP? | Sim: o SIP e o áudio só respondem ao Brasil, à Telium, às redes internas, aos troncos, ao "Nunca bloquear" e aos telefones já registrados. Diga não se houver ramal ou operadora fora do Brasil sem IP conhecido. Se a operadora manda as chamadas de um IP diferente do host do tronco (SBC, nuvem como AWS ou Google, mesmo no Brasil), esse IP vai nas redes extras |
| Redes extras | Só com a anterior em sim: IPs ou redes de fora do Brasil que precisam chegar à central, e os IPs de onde a operadora manda as chamadas |
| Mandar os logs? | Sim, se a central fala com a rede da Telium |
| Ambiente | `1` cliente (banco vazio) ou `2` laboratório (com o cenário de teste). **Nunca `2` em cliente** |

Depois vem a **senha inicial do admin** (seção 5).

- As respostas ficam no servidor, em **`/etc/telium-servidor.yml`** (só
  root), e as atualizações **não perguntam de novo**. O que já está nele
  não é perguntado. O ambiente de laboratório não é guardado: vale só
  naquela execução.
- Uma central nova por vez (`-i "IP,"`). Um servidor que já tem o Telium
  (com a marca de instalação ou com código publicado em `/opt/telium/api`)
  nunca recebe estas perguntas: recebe o menu da atualização.
- Rodando sem terminal (automação, CI), nada disso é perguntado e valem os
  valores de fábrica; passe o nome na linha de comando
  (`-e pabx_hostname=voz.cliente.com.br`). Sem nome, a instalação para em
  vez de seguir com um nome errado.

### Mudar um ajuste depois

Edite **no servidor** e rode o playbook de novo:

```bash
sudo nano /etc/telium-servidor.yml
```

| Variável | Quando mexer |
|---|---|
| `pabx_hostname` | Trocar o nome. O certificado de fábrica é refeito; um certificado enviado pelo console não é tocado. |
| `turn_dominio` | O nome ainda não existe no DNS: o **IP público** do servidor. Tire a linha quando o DNS sair. |
| `syslog_central` e `syslog_central_*` | O `main.yml` manda os logs para o syslog do grupo VoIP da Telium. Num cliente com outro syslog, troque os IPs; sem syslog central, `syslog_central: false`. |
| `pabx_empresa` | Nome da empresa. |
| `instalar_asternic` | `true` para instalar o Asternic depois. |
| `sip_restringir_redes` e `sip_redes_extras` | Ligar ou desligar o SIP só para redes conhecidas, e as redes a mais. |

Escreva cada valor por extenso: `{{ ... }}` e `{% ... %}` do Ansible não
valem neste arquivo (o playbook para e diz qual linha usa).

**Não edite o `main.yml`.** Ele vai para o Git e vale para todas as
centrais. Tudo que estiver no `/etc/telium-servidor.yml` ganha dele.

Não é preciso mexer em:
- **IP público e redes locais** depois da instalação: são ajustados pelo
  console, em **Conectividade › Configurações de Rede**, que tem um botão
  para descobrir o IP público.
- **Certificado**: a instalação gera um provisório. O navegador reclama na
  primeira visita, e isso é esperado. O certificado de verdade é enviado
  depois pelo console.
- **Senhas de serviço** (banco, AMI, TURN): são geradas na instalação e
  guardadas em `/etc/telium/credenciais`, só para root.

### Opções

| Opção | Para quê |
|---|---|
| `-e instalar_janus=true` | Instala também o Janus sem perguntar. |
| `-e perguntar=false` | Não pergunta nada além do nome e da senha do admin: valem os valores de fábrica. |
| `-e cenario_teste=true` | **Só em servidor de teste**, e sem perguntar: ramais 1001–1004, fila 3000, grupo 2000, conferência 4000, URA e rotas prontas, com uma senha SIP fixa que está no repositório. Prefira responder `2` na pergunta do ambiente. |

## 5. Primeiro acesso

| Endereço | Usuário | Senha |
|---|---|---|
| `https://voz.cliente.com.br/` | `admin` | a senha padrão da Telium, digitada na instalação (guardada em `/etc/telium/credenciais/senha_admin_console`, só root) |

> A instalação **pergunta a senha inicial do admin** (duas vezes, sem
> aparecer na tela): use a senha padrão da Telium. Ela não fica no Git. O
> playbook só a define na primeira instalação: as atualizações não voltam a
> perguntar nem a mexer nela. Sem terminal, passe
> `-e senha_padrao_console=...`. Perdeu a senha? No servidor:
> `sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin`.

## 6. Depois de instalar, pelo console

Nesta ordem:

1. **Conectividade › Configurações de Rede**: confira o IP público (o botão
   descobre sozinho) e as redes locais.
2. **Administrador › Certificados**: envie o certificado de verdade e clique
   em **Aplicar nos serviços**.
3. **Conectividade › Troncos**: cadastre a operadora.
4. **Conectividade › Rotas de Saída** e **Rotas de Entrada**: por onde as
   chamadas saem e para onde as que entram vão.
5. **Conectividade › Ramais**: um a um, ou em lote com **Importar CSV** (o
   botão **Modelo CSV** baixa a planilha de exemplo).
6. **Administrador › Backup & Restauração**: crie uma rotina de backup e,
   de preferência, um destino fora do servidor.
7. Clique em **Aplicar configurações** (a faixa amarela no topo avisa
   quando há algo pendente).

## 7. Conferir

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

Tem de terminar sem falha. Da sua máquina, o diagnóstico completo:
`ansible-playbook -i "200.170.198.176," verificar.yml -u root -k` (em
`infra/ansible`). O resto da conferência (Asterisk, portas, áudio) está em [04 — Verificação e problemas comuns](04-VERIFICACAO-E-PROBLEMAS.md).

Antes de entregar a um cliente, preencha o
[caderno de aceitação](../docs/CADERNO-DE-TESTE.md).
