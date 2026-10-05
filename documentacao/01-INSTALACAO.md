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
| 3478 | UDP e TCP | TURN/STUN — áudio do softphone do navegador |
| 5349 | TCP | TURN sobre TLS |
| 49152–49500 | UDP | áudio retransmitido pelo TURN |

> A faixa de RTP é a que mais se esquece de abrir, e a falha não dá erro:
> **a chamada conecta e ninguém ouve.**

## 2. Preparar o servidor

```bash
sudo apt update && sudo apt install -y ansible git
sudo git clone https://github.com/guilhermeleao11/TeliumPABX.git /opt/TeliumPABX
cd /opt/TeliumPABX/infra/ansible
```

## 3. O nome do servidor

**A instalação pergunta o nome** logo no começo, porque cada cliente tem o
seu:

```
 Qual é o nome deste servidor?
 ...
Nome do servidor: voz.cliente.com.br
```

- Use o nome que está no DNS apontando para o servidor. Ainda sem DNS,
  responda com o **IP público**.
- A resposta é conferida: um nome `.local` ou com `_` é recusado, com o
  motivo, porque o navegador não conseguiria usar a central com ele.
- O nome fica guardado em `infra/ansible/group_vars/all/servidor.yml`,
  **fora do Git**, e as execuções seguintes (atualizações) **não perguntam
  de novo**.
- Rodando sem terminal (automação, CI), passe o nome na linha de comando:
  `sudo ansible-playbook site.yml -e pabx_hostname=voz.cliente.com.br`.
  Sem nome, a instalação para em vez de seguir com um nome errado.

Para trocar o nome depois, edite o `pabx_hostname` no `servidor.yml` e rode
o playbook de novo. O certificado de fábrica é refeito com o nome novo; um
certificado enviado pelo console não é tocado.

### Outros ajustes deste servidor (opcionais)

Ficam no mesmo `servidor.yml`, que a instalação cria a partir de
`servidor.yml.exemplo`. Para ajustar **antes** da primeira instalação:

```bash
cd /opt/TeliumPABX/infra/ansible/group_vars/all
sudo cp servidor.yml.exemplo servidor.yml
sudo nano servidor.yml
```

Revise:

| Variável | Quando mexer |
|---|---|
| `turn_dominio` | O nome ainda não existe no DNS: ponha o **IP público** do servidor. |
| `syslog_central` e `syslog_central_*` | O `main.yml` manda os logs para o syslog do grupo VoIP da Telium. Num cliente com outro syslog, troque os IPs; sem syslog central, `syslog_central: false`. |
| `pabx_empresa` | Nome da empresa. |

**Não edite o `main.yml`.** Tudo que estiver no `servidor.yml` ganha dele,
e o `main.yml` editado faz o `git pull` da atualização recusar.

Não é preciso mexer em:
- **IP público e redes locais**: são ajustados depois pelo console, em
  **Conectividade › Configurações de Rede**, que tem um botão para
  descobrir o IP público.
- **Certificado**: a instalação gera um provisório. O navegador reclama na
  primeira visita, e isso é esperado. O certificado de verdade é enviado
  depois pelo console.
- **Senhas de serviço** (banco, AMI, TURN): são geradas na instalação e
  guardadas em `/etc/telium/credenciais`, só para root.

## 4. Instalar

```bash
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook site.yml
```

- Primeiro vem a **pergunta do nome** (seção 3).
- A **compilação do Asterisk leva de 5 a 15 minutos**. É a parte demorada.
- No fim, o playbook roda a **bateria de testes**, e uma falha ali
  **interrompe a entrega**. É o que se quer: não entregue uma central que
  não passou.
- O resultado precisa terminar com `failed=0`.

### Opções

| Comando | Para quê |
|---|---|
| `sudo ansible-playbook site.yml -e cenario_teste=true` | **Só em servidor de teste:** cria ramais 1001–1004, fila 3000, grupo 2000, conferência 4000, URA e rotas prontas. A senha SIP deles é `T3st3_R4mal_@2024`. |
| `sudo ansible-playbook site.yml -e instalar_janus=true` | Instala também o Janus (opcional; o softphone do navegador não precisa dele). |

## 5. Primeiro acesso

| Endereço | Usuário | Senha |
|---|---|---|
| `https://voz.cliente.com.br/` | `admin` | `T3l1um_@2024_@aD1m` (inicial) |

> **Troque a senha no primeiro acesso** (Meu Perfil e Segurança). A senha
> inicial é a mesma de fábrica em toda central e está no repositório. O
> playbook só a define na primeira instalação: as atualizações não voltam a
> mexer nela. Perdeu a senha? No servidor:
> `sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin`.
> Para uma senha inicial diferente, ponha `senha_padrao_console` no
> `servidor.yml` antes de instalar.

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

Tem de terminar sem falha. O resto da conferência (Asterisk, portas,
áudio) está em [04 — Verificação e problemas comuns](04-VERIFICACAO-E-PROBLEMAS.md).

Antes de entregar a um cliente, preencha o
[caderno de aceitação](../docs/CADERNO-DE-TESTE.md).
