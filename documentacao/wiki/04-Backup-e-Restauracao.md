# Backup e Restauração — Telium PABX

Como a central guarda os próprios dados, para onde manda a cópia, como voltar atrás e o que a ferramenta de backup da empresa precisa levar do servidor.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [A tela Backup & Restauração](#2-a-tela-backup--restauração)
3. [O que entra em cada opção](#3-o-que-entra-em-cada-opção)
4. [Fazer backup agora](#4-fazer-backup-agora)
5. [Rotinas e retenção](#5-rotinas-e-retenção)
6. [Cópia para fora do servidor (destino remoto)](#6-cópia-para-fora-do-servidor-destino-remoto)
7. [Baixar, enviar e excluir](#7-baixar-enviar-e-excluir)
8. [Restaurar pelo console](#8-restaurar-pelo-console)
9. [Backup e restauração manuais pelo terminal](#9-backup-e-restauração-manuais-pelo-terminal)
10. [Levar a central para outro servidor](#10-levar-a-central-para-outro-servidor)
11. [Backup corporativo (ferramenta da empresa)](#11-backup-corporativo-ferramenta-da-empresa)
12. [Como funciona por dentro](#12-como-funciona-por-dentro)
13. [Problemas comuns](#13-problemas-comuns)

---

## 1. Visão geral

São três camadas, e uma não substitui a outra:

| Camada | O que é | Quem cuida |
|---|---|---|
| **Backup do console** | Um arquivo `.tar.gz` com banco, configuração, áudios e (opcional) gravações, em `/var/Backup/pabx-telium` | O administrador da central, em **Administrador › Backup & Restauração** |
| **Destino remoto** | O mesmo arquivo enviado por FTP, FTPS ou SFTP logo depois de pronto | Configurado na mesma tela |
| **Backup corporativo** | A ferramenta de backup da empresa copiando os diretórios do servidor | A equipe de infraestrutura ([seção 11](#11-backup-corporativo-ferramenta-da-empresa)) |

> ⚠️ **O backup contém segredos.** Senhas de serviço (banco, AMI, ARI, TURN), chaves privadas dos certificados, senha SIP de cada ramal e tronco, senha do destino remoto e do SMTP. Guarde o arquivo como se fosse a senha de administrador.

---

## 2. A tela Backup & Restauração

**Administrador › Backup & Restauração**

| Bloco | O que mostra |
|---|---|
| Cabeçalho | Onde os arquivos ficam no servidor e o botão **Fazer backup agora**. Enquanto um backup roda, aparece **Backup em andamento** |
| Espaço livre | Espaço livre do disco onde fica `/var/Backup/pabx-telium`. Fica vermelho acima de 85% de uso |
| Rotinas | Cada rotina, com quando roda, o que inclui, quantos arquivos mantém, a chave liga/desliga e **Ajustar** |
| Histórico | Os últimos 30 backups: arquivo, origem (`manual` ou `agendado`), conteúdo, tamanho, data e estado |
| Cópia para fora do servidor | O destino remoto e o resultado do último envio |

Estados no histórico:

| Estado | Significado |
|---|---|
| **Na fila** | Pedido registrado, esperando o executor |
| **Executando** | O executor está montando o arquivo |
| **Concluído** | Arquivo pronto |
| **Falhou** | Algo deu errado. O botão de detalhes mostra a saída (ex.: `banco: FALHOU — …`) |
| **Excluindo** | Exclusão pedida pelo console; o executor apaga o arquivo e o registro em até um minuto |

Quando a retenção já apagou o arquivo, a coluna Arquivo mostra **removido pela retenção**.

Ações por linha (dependem das permissões do perfil):

| Botão | Permissão do perfil |
|---|---|
| Ver detalhes | ver a tela |
| Baixar o arquivo | exportar |
| Enviar para o destino remoto (só com o destino ligado) | exportar |
| Restaurar (só backup concluído) | reiniciar |
| Excluir | excluir |

> 📷 **Print:** tela Backup & Restauração com uma rotina, o histórico com um backup concluído e o bloco "Cópia para fora do servidor".

---

## 3. O que entra em cada opção

Cada backup é um arquivo `telium-AAAAMMDD-HHMMSS.tar.gz`, do root, com leitura para o grupo da API (`root:teliumpbx`, `0640`): é assim que o console consegue entregar o **Baixar** e mandar o arquivo ao destino remoto. A pasta é `root:teliumpbx`, `0750`: a API lê, mas só o root escreve. Dentro dele:

| Opção no console | Padrão | O que vai no arquivo | De onde vem |
|---|---|---|---|
| **Banco de dados** | ligado | `banco.sql` | Dump da base `telium_pabx` (`mariadb-dump --single-transaction --routines`): ramais, troncos, filas, rotas, usuários, CDR, call center, tarifas, configuração de e-mail e do destino remoto — tudo o que o console guarda |
| | | `astdb.sqlite3` | `/var/lib/asterisk/astdb.sqlite3`: os estados ligados pelo telefone (siga-me, desvio, não perturbe, listas, despertador do `*68`) |
| **Configuração** | ligado | `config/asterisk/` | `/etc/asterisk` inteiro, incluindo os `.conf` gerados (`/etc/asterisk/telium`) e as chaves do SIP TLS (`/etc/asterisk/keys`) |
| | | `config/telium/` | `/etc/telium` inteiro: senhas de serviço (`credenciais/`), cofre de certificados enviados pelo console (`certificados/`), `msmtprc`, `instalacao.txt` |
| | | `config/ssl-telium/` | `/etc/ssl/telium`: o certificado ativo do console e o par de fábrica |
| | | `config/janus/` | `/opt/janus/etc/janus`, só se o Janus estiver instalado |
| | | `config/odbc.ini`, `config/odbcinst.ini` | `/etc/odbc.ini` e `/etc/odbcinst.ini` (conexão do Asterisk com o banco) |
| **Áudios, recados e fax** | ligado | `audios/` | `/var/lib/asterisk/sounds/telium`: anúncios, saudações de URA e música em espera enviados pelo console (a música fica em `moh/`) |
| | | `recados/` | `/var/spool/asterisk/voicemail`: os recados do correio de voz dos ramais e das caixas postais |
| | | `fax/` | `/var/spool/asterisk/fax`: os fax recebidos (o `.tif` e, quando o fax foi mandado por e-mail, o `.pdf`) |
| **Gravações de chamadas** | desligado | `gravacoes/` | `/var/spool/asterisk/monitor` inteiro (pastas `AAAA/MM/DD`) |
| (sempre) | — | `manifesto.txt` | Data, nome do servidor, opções escolhidas e versão do Asterisk |

**O que NÃO entra em nenhuma opção:**

| Fica de fora | Onde está |
|---|---|
| Ditados | `/var/spool/asterisk/dictate` |
| Certificado do TURN | `/etc/coturn` |
| Fotos dos contatos da agenda | `/opt/telium/web/uploads/contatos` |
| Ajustes da central (nome, NAT, SIP, Asternic, syslog…) | `/etc/telium-servidor.yml` |
| Áudios do idioma (pt_BR) e do call center | `/var/lib/asterisk/sounds` (o playbook reinstala) |

Esses itens precisam estar no [backup corporativo](#11-backup-corporativo-ferramenta-da-empresa).

---

## 4. Fazer backup agora

1. **Administrador › Backup & Restauração › Fazer backup agora**.
2. Escolha o conteúdo: **Banco de dados**, **Configuração**, **Áudios, recados e fax**, **Gravações de chamadas**.
3. **Quantos arquivos manter** (padrão 5, de 1 a 20): conta só entre os backups feitos à mão. Ao passar disso, os manuais mais antigos são apagados; os das rotinas seguem a retenção de cada rotina.
4. **Iniciar backup**. Ele roda em segundo plano; pode sair da tela.

- Só um backup por vez: com outro na fila ou executando, o console recusa (*Já existe um backup em andamento*).
- Se o disparo imediato falhar, o pedido fica na fila e o temporizador do sistema o pega em até um minuto.
- Gravações podem deixar o arquivo muito grande. Com o disco apertado, deixe desligado e cubra as gravações pelo backup corporativo.

> 📷 **Print:** painel lateral "Fazer backup agora" com as quatro opções e o campo de retenção.

---

## 5. Rotinas e retenção

A central já vem com a rotina **Backup diário** (todo dia às 03:00, banco + configuração + áudios, mantém 5), **desligada**. Ligue-a na chave do cartão ou em **Ajustar**.

| Campo | Valores |
|---|---|
| Nome da rotina | Livre |
| Quando executar | Todo dia, Uma vez por semana, Uma vez por mês, Só quando eu mandar |
| Horário | HH:MM |
| Dia da semana (se semanal) | Domingo a Sábado |
| Dia do mês (se mensal) | Número do dia |
| Conteúdo | As mesmas quatro opções do backup manual |
| Quantos arquivos manter | Retenção |
| Rotina ativa | Liga/desliga |

Como a rotina dispara:

- O temporizador `telium-backup.timer` roda **a cada minuto**. Quando a hora da rotina bate com a hora atual (e o dia, para semanal e mensal), ele cria um backup `agendado` e o executa.
- Cada rotina roda **no máximo uma vez por dia**.
- **Retenção:** depois de cada backup, ficam só os N mais recentes **do mesmo grupo**: cada rotina conta os seus, e os backups manuais contam entre si. Um backup manual com retenção 2 não apaga os arquivos de uma rotina que mantém 5.
- Prefira dia do mês até 28 nas rotinas mensais, para valer em fevereiro.

> 📷 **Print:** painel "Ajustar Backup diário".

---

## 6. Cópia para fora do servidor (destino remoto)

Backup guardado só no servidor some junto com ele. Ligado o destino remoto, **cada backup concluído sem erro é enviado logo depois de pronto**.

| Campo | Para que serve |
|---|---|
| Enviar para fora depois de cada backup | Liga o envio automático |
| Protocolo | **FTP com TLS (recomendado)** (FTPS explícito, `AUTH TLS` na porta 21), **FTP simples** (usuário e senha passam em texto claro), **SFTP (sobre SSH)** |
| Endereço do servidor | Nome ou IP do servidor de destino |
| Porta | Padrão 21 (FTP/FTPS) ou 22 (SFTP) quando inválida |
| Usuário / Senha | Credenciais no destino. A senha nunca volta para a tela; **em branco mantém a guardada** |
| Pasta no servidor remoto | Criada se não existir |
| Modo passivo | Deixe ligado; desligue só se o destino exigir modo ativo |
| Aceitar certificado autoassinado | Só para servidor interno: desliga a conferência do certificado do destino |

- FTPS implícito (porta 990) não é suportado.
- O envio usa a extensão `curl` do PHP. Sem ela, a tela avisa: instale `php8.4-curl` e recarregue o PHP-FPM.
- O arquivo é enviado com o mesmo nome. Os backups antigos **não são apagados no destino**: a limpeza de lá é por conta de quem administra o destino.

### Testar

**Testar agora** salva o que está na tela e envia um arquivo `telium-teste-AAAAMMDD-HHMMSS.txt`, apagando-o em seguida. Só listar a pasta não provaria que dá para gravar nela. A mensagem traduz o erro:

| Mensagem | O que conferir |
|---|---|
| Usuário ou senha recusados pelo servidor remoto | Credenciais |
| O servidor remoto recusou a gravação | Se a pasta existe e o usuário pode escrever nela |
| O endereço do servidor remoto não resolve | Nome/DNS |
| Não foi possível conectar | Endereço, porta, firewall (saída do servidor da central) |
| O certificado do servidor remoto não foi aceito | Certificado válido no destino, ou a opção de autoassinado |
| O servidor remoto não respondeu no tempo esperado | Rede/destino lento |

O selo do bloco mostra **último envio: ok**, **último envio: falhou** ou **ainda não enviou**, e o texto do último resultado.

> 📷 **Print:** bloco "Cópia para fora do servidor" preenchido, com a mensagem de teste confirmado.

---

## 7. Baixar, enviar e excluir

- **Baixar o arquivo**: leva o `.tar.gz` para o computador. Só aparece para backup concluído cujo arquivo ainda está no servidor.
- **Enviar para o destino remoto**: reenvia um backup específico agora (útil depois de corrigir o destino).
- **Excluir**: apaga o arquivo e o registro. Como o arquivo é do root, a linha passa a **Excluindo** e o executor de backup apaga os dois em até um minuto. Backup na fila ou em andamento não pode ser excluído.

Pelo terminal, o envio de um backup é `sudo -u teliumpbx php /opt/telium/api/bin/telium backup-enviar <id>` (o `id` é o número do backup). Veja [Comandos e serviços](05-Servidor-Comandos-e-Servicos.md).

---

## 8. Restaurar pelo console

**Administrador › Backup & Restauração**, no backup desejado, **Restaurar**, e confirme.

> ⚠️ **O Asterisk para durante a restauração e as chamadas em andamento caem.** Faça fora do horário de atendimento.

O que acontece, em ordem:

1. O arquivo é extraído numa pasta temporária.
2. O call center (`telium-cc`) e o Asterisk são parados.
3. Se houver `banco.sql`, a base `telium_pabx` é restaurada. O **histórico de backups** fica o deste servidor, e não o do arquivo, para o console continuar enxergando os arquivos que estão na pasta.
4. Se houver `astdb.sqlite3`, os estados do telefone (siga-me, não perturbe…) voltam.
5. Se houver `config/asterisk`, ele é copiado por cima de `/etc/asterisk`, **menos** `manager.conf`, `res_odbc.conf` e `ari.conf`: esses têm as senhas deste servidor, e as do arquivo desligariam a API e o CDR.
6. Do `config/telium` volta só o cofre de certificados (`/etc/telium/certificados`). As senhas de serviço (`/etc/telium/credenciais`) ficam as deste servidor.
7. Se houver `config/ssl-telium`, o certificado do console volta e o nginx é recarregado.
8. Áudios, recados, fax e gravações voltam para as pastas de cada um, com o dono certo.
9. O Asterisk sobe, a configuração é refeita a partir do banco restaurado (como num **Aplicar configurações**) e o call center volta.

Outros pontos:

- A cópia é **por cima**: arquivo criado depois do backup continua lá.
- Não voltam: `config/janus`, `odbc.ini` e `odbcinst.ini` (são do playbook; copie à mão do arquivo extraído, se precisar).
- Confira com a bateria de testes: `sudo -u teliumpbx php /opt/telium/api/bin/telium testar`.

> 📷 **Print:** confirmação "Restaurar este backup?".

---

## 9. Backup e restauração manuais pelo terminal

Útil antes de uma atualização ou quando o console não está acessível.

### Backup rápido

```bash
sudo mkdir -p /root/backup-manual && cd /root/backup-manual
DATA=$(date +%F-%H%M)
sudo mariadb-dump --single-transaction --routines --triggers telium_pabx | gzip > banco-$DATA.sql.gz
sudo tar czf config-$DATA.tgz --ignore-failed-read \
  /etc/telium /etc/asterisk /etc/telium-servidor.yml
ls -lh
```

| Arquivo | Conteúdo |
|---|---|
| `banco-*.sql.gz` | O banco inteiro: cadastros, usuários, CDR e resultados |
| `config-*.tgz` | Senhas de serviço (`/etc/telium/credenciais`), cofre de certificados, configuração do Asterisk e os ajustes da central (`/etc/telium-servidor.yml`) |

Áudios e gravações ficam de fora (podem ser grandes). Se quiser:

```bash
sudo tar czf audios-$DATA.tgz /var/lib/asterisk/sounds/telium
```

**Guarde fora do servidor.** O `config-*.tgz` tem senhas: trate como segredo.

### Restaurar o backup rápido

```bash
cd /root/backup-manual
sudo systemctl stop telium-cc asterisk
gunzip -c banco-AAAA-MM-DD-HHMM.sql.gz | sudo mariadb telium_pabx
sudo tar xzf config-AAAA-MM-DD-HHMM.tgz -C /
sudo systemctl start asterisk telium-cc
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config
```

### Restaurar a partir de um arquivo do console, à mão

Quando o console não abre, mas o `.tar.gz` está disponível:

```bash
sudo mkdir /root/restaurar && cd /root/restaurar
sudo tar xzf /var/Backup/pabx-telium/telium-AAAAMMDD-HHMMSS.tar.gz
cat manifesto.txt
sudo systemctl stop telium-cc asterisk
sudo mariadb telium_pabx < banco.sql
sudo cp -a config/asterisk/. /etc/asterisk/ && sudo chown -R asterisk:asterisk /etc/asterisk
sudo cp -a audios/. /var/lib/asterisk/sounds/telium/
sudo systemctl start asterisk telium-cc
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config
```

Depois, confira como em [Solução de problemas](07-Solucao-de-Problemas.md).

---

## 10. Levar a central para outro servidor

As senhas de serviço (banco, AMI, ARI, TURN) são geradas na instalação e guardadas em `/etc/telium/credenciais`, uma por arquivo. O playbook **reaproveita** o arquivo que já existe. Por isso a ordem importa:

1. No servidor novo, antes do playbook, copie do antigo `/etc/telium/credenciais` (permissão `0700` na pasta, só root) e `/etc/telium-servidor.yml` (root, `0600`). Com os ajustes no lugar, a instalação não pergunta de novo o que já foi respondido. (Se a central antiga ainda tinha os ajustes no `infra/ansible/group_vars/all/servidor.yml` de um clone, em `/opt/TeliumPABX` ou `/opt/telium-src`, copie esse arquivo para `/etc/telium-servidor.yml` no servidor novo.)
2. Rode a [Instalação](01-Instalacao.md) normalmente.
3. Restaure o banco, os áudios e, se quiser, as gravações, recados e faxes.
4. `sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config` e a bateria `testar`.

> A restauração pelo console mantém o `manager.conf`, o `res_odbc.conf`, o `ari.conf` e o `/etc/telium/credenciais` do servidor em que roda, e refaz a configuração a partir do banco. Restaurar o backup de um servidor em outro não derruba mais o AMI nem o CDR. Quem copiar o `/etc/asterisk` **à mão** de um servidor para outro sem as mesmas credenciais ainda cai nesse problema: rode o playbook de novo para reescrever esses arquivos.

---

## 11. Backup corporativo (ferramenta da empresa)

O backup do console não cobre tudo ([seção 3](#3-o-que-entra-em-cada-opção)). A ferramenta de backup da empresa deve levar os diretórios abaixo.

### O bloco automático em /root/.backup_telium

A cada instalação e atualização, o playbook confere o arquivo da ferramenta da empresa, **`/root/.backup_telium`**, e acrescenta num bloco marcado **só o que ainda falta** da lista abaixo. Nada se repete:
- Uma linha que o arquivo já tem fora do bloco não entra de novo.
- Um diretório coberto por outro mais acima também não entra: com `I /var/lib`, o `/var/lib/asterisk` não é acrescentado.
- Se o arquivo já tiver tudo, o bloco nem aparece.

As linhas de vocês nunca são alteradas. Se o arquivo não existir no servidor, nada é feito. Para outro caminho, use `backup_empresa_lista` no `/etc/telium-servidor.yml`; vazio desliga.

A lista que a central precisa: `/etc`, `/home`, `/root`, `/Telium`, `/opt`, `/usr/local/sbin`, `/var/Backup`, `/var/spool/asterisk` e `/var/lib/asterisk`.

Exemplo, num servidor que vinha do SNEP:

```
I /etc
I /home
I /root
I /Telium
I /var/Backup
I /var/www
I /var/lib/asterisk/sounds
# BEGIN Telium PABX — gerado pelo Ansible, não edite este bloco
I /opt
I /usr/local/sbin
I /var/spool/asterisk
I /var/lib/asterisk
# END Telium PABX — gerado pelo Ansible, não edite este bloco
```

Depois da troca do SNEP pelo Telium, a linha `/var/www` pode sair: o Telium não usa.

| Linha | O que leva |
|---|---|
| `/Telium` | A pasta da empresa nos servidores |
| `/etc` | Sistema e serviços, `/etc/telium-servidor.yml` (os ajustes da central), `/etc/telium` (senhas de serviço, certificados enviados), `/etc/asterisk` (com os `_custom.conf`), `/etc/ssl/telium`, nginx, coturn, fail2ban |
| `/home`, `/root` | Contas dos administradores, a própria lista da ferramenta e backups manuais |
| `/opt` | O código publicado `/opt/telium` (com o `api/.env`) e, numa central instalada de dentro, o clone `/opt/TeliumPABX` |
| `/usr/local/sbin` | Os executores da central (backup, certificados, despertador, fax) |
| `/var/Backup` | O **banco em dump consistente** (`/var/Backup/pabx-telium`) |
| `/var/spool/asterisk` | Gravações, recados (correio de voz e caixas postais) e fax |
| `/var/lib/asterisk` | Áudios enviados, música de espera, chaves e a base interna (siga-me, desvio, não perturbe) |

**O banco:** o `/var/lib/mysql` cru fica de fora de propósito. Copiado com o MariaDB rodando, ele não volta. Quem garante o banco é o dump em `/var/Backup/pabx-telium`, gerado pela rotina diária do console. O playbook liga a rotina de fábrica quando nenhuma rotina automática está ligada (`backup_ligar_rotina: false` no `/etc/telium-servidor.yml` desliga isso). Ponha a rotina um pouco **antes** do horário da ferramenta da empresa.

### Voltar a central a partir desses arquivos

**Mesma VPS** (arquivo apagado ou configuração estragada): devolva os diretórios afetados e, se o banco também se perdeu, restaure o dump mais recente:

```bash
sudo systemctl stop telium-cc asterisk
sudo mkdir -p /tmp/restaura
sudo tar -xzf /var/Backup/pabx-telium/telium-AAAAMMDD-HHMMSS.tar.gz -C /tmp/restaura ./banco.sql
sudo mariadb telium_pabx < /tmp/restaura/banco.sql
sudo systemctl start asterisk telium-cc
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config
```

**VPS nova** (a antiga se perdeu):
1. Instale o Debian 13 e devolva do backup **`/etc/telium`** (as senhas de serviço), **`/etc/telium-servidor.yml`** (os ajustes da central) e **`/opt`** (o código publicado e, se a central foi instalada de dentro, o clone).
2. Rode o playbook de instalação ([Instalação](01-Instalacao.md)): ele instala os pacotes e compila o Asterisk usando as mesmas senhas e o mesmo nome.
3. Devolva `/var/Backup`, `/var/spool/asterisk` e `/var/lib/asterisk`.
4. Restaure o banco pelo console (**Administrador › Backup & Restauração › Restaurar**, no backup mais recente) ou pelo terminal, como acima.
5. **Aplicar configurações** e faça uma ligação de teste.

Não devolva o `/etc` inteiro numa VPS nova: rede, discos e chaves do SSH são da máquina antiga. Dele, só o que a central usa (`/etc/telium`, `/etc/telium-servidor.yml`, `/etc/asterisk/telium/*_custom.conf`, `/etc/asterisk/manager_custom.conf`, `/etc/asterisk/ari_custom.conf`).

### Lista de diretórios

| Diretório | O que tem de importante |
|---|---|
| `/etc` | `/etc/telium-servidor.yml` (ajustes da central), `/etc/telium-sip-redes.conf` (SIP só para redes conhecidas), `/etc/telium` (credenciais, cofre de certificados, `msmtprc`), `/etc/asterisk` (com `telium/` gerado e `keys/`), `/etc/ssl/telium` (certificado do console e par de fábrica), `/etc/coturn` e `/etc/turnserver.conf`, `/etc/nginx`, `/etc/janus/certs` (se houver Janus), `/etc/odbc.ini`, `/etc/fail2ban/jail.d`, `/etc/sudoers.d/telium-*`, `/etc/systemd/system/telium-*`, `/etc/mysql/mariadb.conf.d/60-telium.cnf` |
| `/home` | Contas dos administradores |
| `/root` | Backups manuais (`/root/backup-manual`) e histórico de comandos |
| `/opt` | `/opt/TeliumPABX` (o clone, só numa central instalada de dentro; o `vault.yml`, se houver), `/opt/telium` (o publicado: `api/.env`, `web/uploads/contatos`), `/opt/janus` (se instalado) |
| `/var/Backup` | `/var/Backup/pabx-telium`, os arquivos do console (atenção ao B maiúsculo: o Linux diferencia). Pequeno quando as gravações estão desligadas |
| `/var/spool/asterisk` | `monitor/` (gravações), `voicemail/` (recados, incluindo as caixas postais), `fax/`, `dictate/` |
| `/var/www` | Incluído por padrão de política; o Telium não usa (o console fica em `/opt/telium/web`) |
| `/var/lib` | `/var/lib/asterisk/sounds` (áudios do idioma, do call center e `telium/` do console), `/var/lib/asterisk/moh` (música de espera padrão), `/var/lib/asterisk/astdb.sqlite3`, `/var/lib/mysql` (ver alerta abaixo) |

### O banco: leve o dump, não os arquivos

Copiar `/var/lib/mysql` com o MariaDB rodando **não dá um banco consistente**: a cópia pode não abrir na restauração. O backup corporativo deve levar um **dump** gerado antes da janela de cópia, de um destes jeitos:

- **Rotina do console** com o banco ligado, num horário anterior ao da ferramenta da empresa. O dump vai dentro do `.tar.gz` em `/var/Backup/pabx-telium`.
- **Dump próprio**, num agendamento do sistema antes da janela:

```bash
mariadb-dump --single-transaction --routines --triggers telium_pabx | gzip > /var/Backup/telium_pabx-$(date +%F).sql.gz
```

O mesmo vale, em menor grau, para o `astdb.sqlite3`: é um SQLite aberto pelo Asterisk. Perder a cópia dele custa pouco (ver tabela abaixo).

### O que perco se faltar cada item

| Se faltar… | Perde-se |
|---|---|
| Dump do banco | Todos os cadastros, usuários, CDR, relatórios de call center, tarifas. Sem ele não há central para restaurar |
| `/etc/telium/credenciais` | As senhas de serviço. Uma central nova gera outras, e os arquivos restaurados do Asterisk deixam de casar com a API e o banco |
| `/etc/telium/certificados` | Os certificados enviados pelo console (é preciso reenviar e reaplicar) |
| `/etc/asterisk` | Ajustes manuais em `pjsip_custom.conf` e `extensions_custom.conf`. O resto é refeito pelo playbook e pelo **aplicar-config** a partir do banco |
| `/etc/ssl/telium`, `/etc/asterisk/keys`, `/etc/coturn` | Os pares TLS ativos. O playbook gera um autoassinado novo; o certificado comprado precisa ser reaplicado |
| `/etc/telium-servidor.yml` | O nome da central e os ajustes próprios dela (NAT, SIP, Asternic, syslog). Na falta dele, o nome e o IP público são lidos do `api/.env`; o resto volta ao de fábrica, e uma instalação do zero pergunta tudo de novo |
| `/var/lib/asterisk/sounds/telium` | Anúncios, saudações de URA e músicas de espera enviados pelo console. A URA e as filas ficam mudas |
| `/var/spool/asterisk/monitor` | As gravações de chamadas |
| `/var/spool/asterisk/voicemail` | Recados e saudações do correio de voz e das caixas postais |
| `/var/spool/asterisk/fax` e `dictate` | Faxes recebidos e ditados |
| `astdb.sqlite3` | Siga-me, não perturbe e despertadores marcados pelo telefone. Listas negra/permitida, não perturbe e chamada em espera do cadastro voltam no próximo **Aplicar configurações** |
| `/opt/telium/web/uploads/contatos` | Fotos dos contatos da agenda |
| `/var/Backup/pabx-telium` | O histórico de backups do console |

> ⚠️ **O backup corporativo contém segredos**: credenciais, chaves privadas, `.env` da API, senhas SIP no banco. O destino precisa de acesso restrito e, de preferência, cifragem.

---

## 12. Como funciona por dentro

- A API **não copia arquivo nenhum**: grava o pedido na tabela `backups` (ou a chave `restaurar_backup_id` na tabela `sistema`) e dispara `sudo -n /usr/local/sbin/telium-backup`, que roda como root.
- A regra de sudo (`/etc/sudoers.d/telium-backup`) não aceita argumento. O executor descobre o que fazer lendo o banco: primeiro uma restauração pedida, depois os backups marcados para excluir, as rotinas vencidas, os backups na fila (até 3 por rodada) e, uma vez por dia, as gravações que passaram do prazo de guarda ([Aplicações › Gravação de Chamadas](13-Aplicacoes-Filas-URA-e-Atendimento.md)).
- O `telium-backup.timer` roda o mesmo executor a cada minuto, como rede de segurança.
- Depois de um backup sem erro, o executor chama `telium backup-enviar <id>` como `teliumpbx` para o destino remoto, e só então aplica a retenção.
- **Alertas por e-mail:** logo depois de um backup que falhou, e de hora em hora (no minuto 07), o executor roda `telium alertas` como `teliumpbx`. Ele avisa backup com falha (um e-mail por backup) e rotina ligada sem nenhum backup concluído há mais de 26 horas, além de certificado perto de vencer e disco quase cheio. Os e-mails vão para o campo **Mandar os alertas do sistema para**, em **Configurações › Notificações e E-mail**; em branco, nenhum alerta sai. Veja [Configurações](19-Configuracoes.md).

> O comando `telium-backup` chamado sozinho **não faz um backup novo**: executa o que o console pediu e as rotinas vencidas. Para um backup na hora, use o console ou o [terminal](#9-backup-e-restauração-manuais-pelo-terminal).

Tabelas envolvidas: `backups`, `backup_rotinas`, `backup_destino_remoto`, `sistema`.

---

## 13. Problemas comuns

### O backup fica "Na fila" e não anda

O executor não rodou. Confira o temporizador e o último log:

```bash
systemctl status telium-backup.timer
journalctl -u telium-backup -n 50 --no-pager
```

### O backup termina em "Falhou"

Com os alertas configurados em **Configurações › Notificações e E-mail**, quem cuida da central recebe um e-mail a cada backup que falha, e outro (uma vez por dia) quando há rotina ligada e nenhum backup concluído há mais de 26 horas.

Abra **Ver detalhes**. `banco: FALHOU` indica problema no dump (MariaDB parado, disco cheio); `tar: FALHOU` quase sempre é disco cheio em `/var/Backup` ou em `/tmp` (o arquivo é montado em `/tmp` antes de compactar). Veja o **Espaço livre** na própria tela e `df -h`.

### O botão Baixar não aparece

O arquivo não está mais em `/var/Backup/pabx-telium` (a retenção pode tê-lo apagado), ou a pasta está com a permissão de antes desta versão. O executor de backup corrige a permissão sozinho a cada minuto; se continuar, confira com `ls -ld /var/Backup/pabx-telium` (deve ser `root teliumpbx`, `drwxr-x---`). Como alternativa, copie pelo terminal (`scp` como root).

### O envio remoto falhou

Use **Testar agora** e siga a mensagem ([seção 6](#testar)). Corrigido o destino, reenvie com o botão **Enviar para o destino remoto** da linha do backup.

### Depois de restaurar, o console diz que o Asterisk não responde

```bash
sudo systemctl status asterisk
sudo -u teliumpbx php /opt/telium/api/bin/telium doctor
```

Se o `doctor` acusar o AMI depois de restaurar a configuração de outro servidor, veja a [seção 10](#10-levar-a-central-para-outro-servidor).
