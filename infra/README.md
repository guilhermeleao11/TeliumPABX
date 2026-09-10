# Infraestrutura — Telium PABX

Provisionamento da VM de teste com Ansible. Alvo: **Debian 13 (trixie)**.
Também roda em Ubuntu 24.04 (os repositórios se ajustam sozinhos).

## O que é instalado

| Componente | Versão | Origem |
|---|---|---|
| MariaDB | 12.3.3 | repositório oficial MariaDB |
| PHP + FPM | 8.4 | repositório do Debian 13 |
| Asterisk | 22.11.0 | **compilado do fonte** (Debian 13 não empacota) |
| Janus | v1.4.1 | **compilado do fonte** (plugin SIP) |
| nginx | mainline (1.31.x) | repositório oficial nginx.org |
| fail2ban + ufw | do sistema | proteção SIP e SSH |

A compilação do Asterisk leva de **5 a 15 minutos**; a do Janus, de 3 a 5.

## Como rodar

### Opção A — dentro da própria VM (mais simples)

```bash
sudo apt update && sudo apt install -y ansible git
git clone https://github.com/guilhermeleao11/TeliumPABX.git /opt/telium-src
cd /opt/telium-src/infra/ansible
sudo ansible-playbook site.yml
```

### Opção B — da estação de desenvolvimento, por SSH

```bash
cp inventory/vm.ini.exemplo inventory/vm.ini   # ajuste IP e usuário
ansible-playbook -i inventory/vm.ini site.yml
```

### Senhas

**Você não precisa gerar nada.** As senhas de serviço — banco de dados,
usuário ODBC do CDR, AMI e ARI — são criadas na primeira instalação, com
28 caracteres aleatórios cada, e guardadas em `/etc/telium/credenciais/`
(um arquivo por senha, `0600`, só root). Reexecutar o playbook reaproveita
as mesmas; elas nunca aparecem na tela nem entram no Git.

**A senha do console é fixa e conhecida:**

| Usuário | Senha |
|---|---|
| `admin` | `T3l1um_@2024_@aD1m` |

Essa senha é **restaurada a cada execução do playbook**, de propósito: garante
que sempre exista uma forma conhecida de entrar na central, mesmo que a conta
tenha sido bloqueada por tentativas erradas. Se você precisa de uma conta
administrativa com senha própria, crie um segundo usuário pelo console.

Todas as contas do seed nascem com ela. Troque depois com
`php bin/telium senha admin`.

As senhas SIP dos ramais também são aleatórias por instalação — ficam no
banco e aparecem na tela de cada ramal no console.

Se quiser escolher as senhas de serviço você mesmo, copie
`vault.yml.exemplo` para `group_vars/all/vault.yml` e preencha: elas têm
precedência sobre as geradas. Para cifrar:
`ansible-vault encrypt group_vars/all/vault.yml`.

### Antes de rodar

Ajuste `group_vars/all/main.yml` — o mínimo:

```yaml
pabx_hostname: "pabx.suaempresa.local"
nat_ip_publico: ""       # preencha se a VM estiver atrás de NAT
redes_locais: [...]      # suas faixas de rede interna
```

## Verificar a instalação

```bash
ansible-playbook verificar.yml
```

Mostra serviços, versões, portas em escuta, contagem de registros no banco,
saída de `pjsip show endpoints`, estado do Janus, resposta da API e os
últimos erros do Asterisk. **É essa saída que eu preciso ver quando algo falhar.**

## Depois de instalado

| O quê | Onde |
|---|---|
| Console | `https://<ip-da-vm>/` |
| API | `https://<ip-da-vm>/api/health` |
| Janus | `https://<ip-da-vm>/janus/info` |
| Login | `admin` / `T3l1um_@2024_@aD1m` |

O certificado é autoassinado: o navegador vai reclamar na primeira visita.
Isso é esperado no laboratório, e o WebRTC exige HTTPS para funcionar.

## Comandos úteis na VM

```bash
cd /opt/telium/api
php bin/telium doctor           # testa banco, AMI, Janus e permissões de escrita
php bin/telium gerar-config     # regenera os .conf a partir do banco
php bin/telium aplicar-config   # gera e recarrega o Asterisk
php bin/telium senha admin      # troca a senha de um usuário

asterisk -rvvv                  # console do Asterisk
journalctl -u janus -f          # log do Janus
tail -f /var/log/telium/php-api.log
```

## Reprovisionar sem recompilar

Depois da primeira instalação, para rodar de novo pulando as compilações:

```bash
sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false
```

Ou aplicar apenas um pedaço:

```bash
sudo ansible-playbook site.yml --tags never --limit pabx   # (tags a definir)
sudo ansible-playbook site.yml --start-at-task="Publicar o código da API"
```
