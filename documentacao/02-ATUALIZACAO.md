# 02 — Atualização

Levar uma central que já está no ar para a versão nova do Git.

O playbook é o mesmo da instalação e só mexe no que mudou: publica o
código novo em `/opt/telium`, aplica as mudanças de banco, reescreve a
configuração do Asterisk, recarrega o que precisa e roda a bateria de
testes. **Chamadas em andamento não caem**: o Asterisk é recarregado, não
reiniciado. Quando uma mudança exige reinício, ele é agendado para quando
a central estiver sem chamada nenhuma.

O jeito recomendado é atualizar **da sua máquina**, pela rede (o preparo
dela está em [01 — Instalação](01-INSTALACAO.md#2-preparar-a-sua-máquina)).
De dentro do servidor continua funcionando.

## Resumo

Da sua máquina, na pasta do clone:

```bash
git pull
cd infra/ansible
ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k
```

De dentro do servidor:

```bash
cd /opt/TeliumPABX && sudo git pull && cd infra/ansible && sudo ansible-playbook site.yml
```

Nos dois casos, o playbook mostra a versão instalada e a do clone e
pergunta o que fazer: **Enter** atualiza mantendo tudo.

O passo a passo, com o que conferir, vem a seguir.

## 1. Antes

**Faça um backup.** Pelo console, em **Administrador › Backup &
Restauração › Fazer backup agora**; ou pelo terminal, como está em
[03 — Backup e restauração](03-BACKUP-E-RESTAURACAO.md#backup-rápido-pelo-terminal).

**Anote a versão atual**, para saber para onde voltar se precisar: ela
aparece no rodapé do menu do console, com o commit (ex.: `1.6.1 (fb357b8)`),
e no menu da atualização.

**Escolha a hora.** A atualização não derruba chamada, mas recarrega a
central inteira. Num horário de pouco movimento, qualquer surpresa afeta
menos gente.

## 2. Baixar a versão nova

No clone de onde o playbook vai rodar (o da sua máquina, ou o
`/opt/TeliumPABX` do servidor):

```bash
git pull
git log --oneline -1
```

A última linha mostra a versão que vai ser publicada. O código que vai para
a central é o desse clone: um clone atrasado faz o playbook parar com "A
central está na versão X e este clone tem a Y".

### Se o `git pull` recusar

```
error: Your local changes to the following files would be overwritten by merge:
        infra/ansible/group_vars/all/main.yml
```

Alguém editou o `main.yml` no clone. Os ajustes de cada central ficam no
servidor dela, em `/etc/telium-servidor.yml`. Para mudar:

```bash
git diff infra/ansible/group_vars/all/main.yml      # veja o que foi mudado
```

Passe cada valor que é de uma central para o `/etc/telium-servidor.yml`
daquela central (a mesma linha, com o valor dela):

```bash
sudo nano /etc/telium-servidor.yml
```

Depois desfaça a edição no `main.yml` e baixe de novo:

```bash
git checkout -- infra/ansible/group_vars/all/main.yml
git pull
```

No clone do servidor, use `sudo` nos comandos do Git.

## 3. Aplicar

Da sua máquina:

```bash
cd ~/TeliumPABX/infra/ansible
ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k
```

De dentro do servidor:

```bash
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook site.yml
```

O playbook mostra o menu:

```
 voz.cliente.com.br (200.170.198.176) já tem o Telium PABX.
 Versão instalada: 1.6.1    Versão deste clone: 1.7.0

 1  Atualizar, mantendo tudo (ramais, troncos, gravações, ajustes)
 2  LIMPAR O BANCO: apaga ramais, troncos, filas, URAs, rotas, CDR, ...
 3  Cancelar, sem mexer em nada
Opção [1]:
```

- **1** (ou Enter) atualiza mantendo tudo. É a resposta de toda central com
  cliente.
- **2** só em central de teste: pede que você digite o nome da central para
  confirmar e pergunta se carrega o cenário de teste. Nunca é guardado: a
  próxima atualização não apaga nada.
- **3** cancela sem mexer em nada.
- **Várias centrais de uma vez**: `-i "IP1,IP2,"`. Todas precisam já ter
  o Telium PABX (senão a execução inteira para: "... ainda não tem o
  Telium PABX"). O menu é perguntado uma vez e vale para todas (o 3
  cancela todas; o 2 é recusado); cada uma usa os ajustes dela.
- Não precisa mais de `-e compilar_asterisk=false -e compilar_janus=false`:
  o Asterisk e o Janus só são compilados quando a versão fixada muda. Os
  dois `-e` continuam valendo.
- Sem terminal, ou com `-e perguntar=false`, o menu não aparece e a central
  é atualizada.
- Tem de terminar com `failed=0`. Se parar com erro, **não siga**: copie a
  saída e veja [04 — Verificação e problemas comuns](04-VERIFICACAO-E-PROBLEMAS.md).

> **Nunca use `-e limpar_banco=true` nem `-e cenario_teste=true` numa
> central com cliente.** O primeiro apaga a operação inteira sem perguntar
> nada (mesmo que no menu se escolha 1); o segundo cria ramais de teste com
> senha conhecida.

**Central instalada até a 1.6.1**: na primeira atualização, os ajustes que
estavam no `infra/ansible/group_vars/all/servidor.yml` do clone do servidor
(`/opt/TeliumPABX` ou, nas de até setembro de 2026, `/opt/telium-src`) vão
sozinhos para `/etc/telium-servidor.yml`, e o antigo vira
`servidor.yml.migrado`. Com mais de um clone com `servidor.yml`, o playbook
para sem mudar nada e pede qual vale: `-e servidor_yml_antigo=<caminho>`.
Valores com `{{ }}` do Ansible não valem mais nesse arquivo: escreva por
extenso. Sem ajustes, o nome e o IP público são lidos do
`.env` da API. Daqui em diante, edite os ajustes em
`/etc/telium-servidor.yml`, no servidor.

Rodando de fora, se o **seu** clone tiver `group_vars/all/servidor.yml` ou
`vault.yml` (de quando o clone era de uma central só), o playbook para:
eles valeriam para toda central. Renomeie para `.antigo` e rode de novo.

## 4. Conferir

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar | tail -3
sudo asterisk -rx "core show uptime"
systemctl is-active telium-cc
```

- A bateria termina sem falha.
- `telium-cc` (o serviço do call center) responde `active`.
- O playbook avisa no fim se deixou um **reinício do Asterisk agendado**
  (para quando não houver chamada). O `core show uptime` mostra se ele já
  aconteceu.

No navegador:

1. Recarregue o console. Na primeira atualização depois da versão
   `53b3c9f` use **Ctrl + Shift + R** (no Mac, Cmd + Shift + R). A partir
   dela, o navegador sempre confere se o console mudou e basta recarregar.
2. Se aparecer a faixa amarela **"Alterações ainda não aplicadas"**, clique
   em **Aplicar configurações** e confira que todas as etapas ficam ✓.
3. Faça uma chamada de teste: de um ramal para outro, uma de fora para
   dentro e uma para fora.

## 5. Se precisar voltar atrás

Volte o clone para a versão anotada no passo 1 e rode o playbook de novo
com `-e permitir_versao_antiga=true` (sem ele, o playbook se recusa a
publicar uma versão mais antiga que a instalada):

```bash
git checkout <versão anotada>
cd infra/ansible
ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k -e permitir_versao_antiga=true
# de dentro do servidor: sudo ansible-playbook site.yml -e permitir_versao_antiga=true
```

As mudanças de banco deste projeto só **acrescentam** tabelas e colunas,
então o código anterior costuma funcionar com o banco já atualizado. Se
não funcionar, restaure o backup do passo 1
([03 — Backup e restauração](03-BACKUP-E-RESTAURACAO.md#restaurar)).

Uma versão anterior à 1.7.0 não conhece o `/etc/telium-servidor.yml`:
voltar para ela é de dentro do servidor, com o `servidor.yml.migrado`
renomeado de volta para `servidor.yml`.

Para voltar a acompanhar o Git depois:

```bash
git checkout main && git pull
```

## Notas das versões

### O projeto mudou para o GitLab da Telium
O repositório agora é `gitlab.telium.com.br/Voip/teliumpabx`. Numa
central instalada a partir do GitHub, aponte o clone para o endereço novo
**uma vez**, antes do `git pull`:

```bash
git remote set-url origin https://gitlab.telium.com.br/Voip/teliumpabx.git
git pull
```

Vale para o clone da sua máquina e para o `/opt/TeliumPABX` do servidor
(neste, com `sudo`). O GitLab pede usuário e token (ou use uma chave de
implantação).

O que muda na operação, e não só por dentro.

### 1.7.1 — migração do SNEP mantém a senha curta
- O ramal migrado do SNEP fica com a **mesma senha SIP**, mesmo com menos de 12 caracteres: o telefone registra sem reconfigurar. A prévia avisa quais são curtas, para trocar depois. Só o ramal **sem senha** no SNEP ganha uma nova (vem na lista de senhas novas).
- Ramal com senha curta agora é publicado na central (antes, abaixo de 8 caracteres ficava de fora). O cadastro continua exigindo 12 para senha digitada.

### 1.7.0 — instalar e atualizar de fora; SIP só para redes conhecidas
- **Instalar e atualizar da sua máquina**, pela rede: `ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k` (em `infra/ansible`, com o clone em dia). De dentro do servidor continua igual. Várias centrais já instaladas de uma vez: `-i "IP1,IP2,"`.
- **A instalação pergunta** o que é do cliente (nome, cliente, NAT, IP do áudio sem DNS, Janus, Asternic, SIP só para redes conhecidas, syslog, cliente ou laboratório) e confirma antes de instalar. **A atualização mostra um menu**: 1 atualizar (Enter), 2 limpar o banco (pede o nome da central), 3 cancelar.
- **Os ajustes de cada central saem do clone** e vão para `/etc/telium-servidor.yml`, no servidor. A primeira atualização leva o `servidor.yml` antigo sozinha (vira `servidor.yml.migrado`). Daqui em diante, edite lá. Valores com `{{ }}` não valem mais nesse arquivo: escreva por extenso.
- **SIP só para redes conhecidas** (opcional, desligado de fábrica): veja [Segurança](wiki/08-Seguranca.md#sip-só-para-redes-conhecidas). Ligando, confira os IPs da operadora (SBC, nuvem) e dos ramais de fora do Brasil.
- Não precisa mais de `-e compilar_asterisk=false -e compilar_janus=false`.
- O playbook não publica uma versão mais antiga que a instalada (`-e permitir_versao_antiga=true` para voltar de propósito).
- As senhas de serviço são geradas e lidas no próprio servidor; nada secreto vem da máquina de quem roda.

### 1.6.1 — fail2ban contra varredura SIP
- Novas cadeias `asterisk-varredura` e `asterisk-varredura-tcp`: quem pede 3 vezes em 1 hora um ramal que não existe fica 24 horas sem a porta SIP. Não vale para o IP de tronco, IP com ramal registrado nem o **Nunca bloquear**.

### 1.6.0 — feriados com áudio próprio
- Nova tela **Aplicações › Feriados**: cada feriado com o seu áudio e, se quiser, um destino depois dele. **Carregar feriados nacionais** cadastra os que faltam. As condições horárias ganham "Nos feriados, tocar o áudio do feriado" (ligado); sem feriado cadastrado, nada muda.
- Caixa postal: o `#` não leva mais para a transferência; só quem liga de um ramal pode transferir.
- Siga-me: a caixa postal do ramal de destino não atende mais dentro da perna do siga-me; o recado fica na caixa do dono.

### 1.5.3 — exportar ramais com senhas
- Em Ramais, o botão **Exportar com senhas** traz a planilha com a senha SIP e a do correio de voz. Só para quem pode editar ramais, e registrado na auditoria.

### 1.5.2 — revisão da migração e das rotas por grupo
- Corrige "Unknown column 'grupo_saida'" (banco desatualizado). Numa central com o erro: `sudo -u teliumpbx php /opt/telium/api/bin/telium migrar`, ou rode a atualização completa.
- A migração do SNEP não grava nada que deixaria a central com erro, e informa o que fica para ser feito à mão.

### 1.5.1 — escolher o que importar do SNEP
- Na prévia da Migração do SNEP, cada item e cada grupo têm uma caixa: só o marcado é importado.

### 1.5.0 — rotas por grupo e horário; migração do SNEP com horários
- Rotas de saída podem valer **só para** grupos de ramais e **só num horário**. O ramal ganha "Grupos de saída", na aba Permissões. Sem usar isso, nada muda.
- A migração do SNEP traz os grupos, os horários das regras (inclusive manhã/tarde/noite e feriados), as regras de saída por grupo e as filas dos agentes; e passou a escolher a regra que o SNEP de fato usa quando duas pegam o mesmo número.

### 1.4.1 — comando do SNEP em dois
- Na Migração do SNEP, o comando que gera o pacote virou dois (a senha e o pacote): colado inteiro, o pedido de senha engolia a linha seguinte.

### 1.4.0 — migração do SNEP completa
- A tela de Migração do SNEP mostra um comando para gerar, no SNEP, um pacote com o banco (sem CDR), os áudios e as músicas de espera. Enviado o pacote, a migração traz também os agentes, os horários das regras e os áudios.

### 1.3.1 — falso alarme do AMI na instalação
- A atualização avisava "O AMI não autenticou" mesmo com o AMI funcionando. Era só o aviso; nada deixava de funcionar.

### 1.3.0 — migração do SNEP
- Nova tela **Telium › Migração do SNEP**: traz troncos, ramais, filas e as regras de entrada e de saída de uma central SNEP 3, a partir do dump do banco, com prévia antes de importar. Só o Administrador tem a tela de fábrica.

### 1.2.8 — senha do admin fora do repositório
- A instalação nova pergunta a senha inicial do admin (use a padrão da Telium). Centrais já instaladas: nada muda, a atualização não pergunta.

### 1.2.7 — Asternic sem download externo
- A instalação do Asternic usa os arquivos do projeto, sem baixar dos sites dos fornecedores.

### 1.2.6 — revisão do Asternic
- O admin do Asternic passa a entrar com a mesma senha do admin do console.
- Relatórios agendados e alertas do Asternic funcionam sem configurar nada à mão.

### 1.2.5 — tempo real e serviço do Asternic
- O tempo real do Asternic não conectava no AMI, e o serviço `asterniclog` podia ficar sem o script. Corrigido: rode a atualização.

### 1.2.4 — login e agentes no Asternic
- O login do Asternic respondia "Bad username or password" com a senha certa. Corrigido: rode a atualização.
- Os agentes e filas com movimento antes da instalação do Asternic passam a aparecer nos relatórios dele (o histórico da central é carregado uma vez).

### 1.2.3 — login do Asternic (de novo)
- A correção da 1.2.2 deixou a sessão numa pasta que o Asternic não alcança. Corrigido: rode a atualização.

### 1.2.2 — login do Asternic
- Com a senha certa, o Asternic voltava para o login. Corrigido: rode a atualização.

### 1.2.1 — correção da instalação do Asternic
- A instalação com `instalar_asternic: true` parava em "Ligar o asterniclog". Corrigido: basta rodar a atualização de novo.

### 1.2.0 — Asternic em Call Center › Stats
- Novo menu **Call Center › Stats**, com o Asternic Call Center Stats PRO. **Só para centrais com licença comprada**: acrescente `instalar_asternic: true` nos ajustes daquela central (hoje, `/etc/telium-servidor.yml` no servidor) e rode a atualização. Detalhes na página 16 (Call Center).
- Nas outras centrais, nada muda: a tela Stats explica como ligar.

### 1.1.4 — backup da empresa sem linha repetida
- O bloco em **`/root/.backup_telium`** passa a trazer só o que o arquivo ainda não tem (inclusive `/Telium`). Nada fica repetido: se `/etc`, `/home`, `/root` ou `/var/Backup` já estão nas linhas de vocês, não entram de novo no bloco.

### 1.1.3 — a central inteira no backup da empresa
- O bloco em **`/root/.backup_telium`** passa a levar `/etc`, `/home`, `/root`, `/opt`, `/usr/local/sbin`, `/var/Backup`, `/var/spool/asterisk` e `/var/lib/asterisk`: tudo para voltar a central copiando os arquivos.
- Onde nenhuma rotina de backup automática estava ligada, a atualização liga a **rotina diária** (03:00). Ajuste a hora para um pouco antes da ferramenta da empresa, em Administrador › Backup & Restauração.

### 1.1.2 — o essencial no backup da empresa
- O playbook mantém em **`/root/.backup_telium`** um bloco "Telium PABX" com o essencial da central, sem mexer nas outras linhas. Sem o arquivo, nada é feito.
- Para o banco entrar consistente, **deixe ligada a rotina diária** de backup em Administrador › Backup & Restauração: é ela que gera o dump em `/var/Backup/pabx-telium`.

### 1.1.1 — backups em /var/Backup
- Os backups da central passam para **`/var/Backup/pabx-telium`**, dentro do diretório que o backup da empresa já leva. A atualização move os arquivos que estavam em `/var/backup/pabx-telium` e apaga a pasta antiga se ela ficar vazia. Nada a fazer no console.
- Corrige o playbook, que parava no resumo inicial no ansible-core 2.19 ou mais novo.

### 1.1.0 — versão na tela, backup completo, fax, webhooks e alertas
O que mudou, item a item, está em **Telium › Versões e Novidades** (e em `api/CHANGELOG.md`). O que pede atenção de quem opera:

- **A versão aparece com o commit** no rodapé do menu e no Suporte ("1.1.0 (abc1234)"). Cite os dois ao abrir chamado.
- **Lista Negra e Allowlist pelo telefone** (`*30` a `*39`): só o ramal de um usuário com a tela da lista consegue usar os códigos. **Aplique as configurações** depois de atualizar.
- **`*45`** só põe na fila quem está na lista de agentes dela como "Entra por código".
- **Fax**: a tela passa a valer. Preencha o e-mail em **Configurações › Fax** para receber em PDF; os troncos passam a aceitar T.38.
- **Alertas**: preencha "Mandar os alertas do sistema para" em **Configurações › Notificações e E-mail**.
- **Arquivos personalizados**: cada arquivo do Asterisk gerado pelo console tem o seu `_custom.conf` em `/etc/asterisk/telium`; o AMI e o ARI ganham `manager_custom.conf` e `ari_custom.conf` em `/etc/asterisk`.
- **Baixar**, **Enviar para o destino remoto** e **Excluir** do backup voltam a funcionar. A pasta `/var/Backup/pabx-telium` passa a ser `root:teliumpbx` (`0750`) e os arquivos `0640`: a API lê, só o root escreve. A primeira rodada do executor corrige os arquivos que já existem.
- **O backup leva mais**: os recados do correio de voz e das caixas postais, os fax recebidos, a base interna do Asterisk (siga-me, não perturbe…) e o certificado do console. A opção "Áudios do sistema" virou **Áudios, recados e fax**.
- **A restauração volta tudo o que está no arquivo** (inclusive gravações, recados e certificados), preserva as senhas deste servidor e o histórico de backups, para o call center junto com o Asterisk e refaz a configuração a partir do banco restaurado.
- **A retenção é por rotina:** um backup manual não apaga mais os arquivos de uma rotina, e vice-versa.
- **Gravações com prazo:** em **Aplicações › Gravação de Chamadas**, defina por quantos dias guardar. O padrão é zero (guarda para sempre): nada é apagado sem alguém definir o prazo.
- **Recuperar o 2FA pelo terminal:** `sudo -u teliumpbx php /opt/telium/api/bin/telium desligar-2fa <usuario>`, para o administrador que perdeu o celular.
- `-e limpar_banco=true` agora apaga também call center, caixas postais, chaves de click to call e os demais cadastros novos.
- Textos corrigidos: Meu Siga-me ensinava `*72`/`*73` (o certo é `*21`/`*22`) e a megafonia citava `*81`.

### Click to call para outros sistemas
- Em **Telium › Integrações / API**, crie uma **chave** para cada sistema
  (CRM, ERP, site). Ele chama `POST https://<central>/api/click2call` com
  `ramal` e `numero` (e, se quiser, `nome`), mandando a chave no cabeçalho
  `X-Api-Key`. A central toca o ramal e, quando ele atende, disca o número.
- A chave aparece **uma vez só**, na criação. Ela pode ficar presa a
  alguns ramais e a alguns IPs, e pedir ao telefone que atenda sozinho.
- A chamada segue a permissão e a rota de saída do ramal.
- A mesma tela tem o botão **Testar** e os últimos pedidos, com o motivo
  de cada recusa.

### Call center, caixas postais e rotas de saída com vários padrões
- **Os códigos do agente mudaram de lugar** — avise quem atende. Entrar
  passa de `*40` para **`*11`**, sair de `*44` para **`*12`** e voltar da
  pausa de `*49` para **`*13`**. Só muda quem estava no código de fábrica.
- **Cada pausa tem o seu código**, e o `*42` + número acabou: Almoço
  **`*14`**, Banheiro `*15`, Café `*16`, Treinamento `*17`, Reunião `*18`,
  Feedback `*19`. Motivos criados por vocês viram `*10` + dois dígitos
  (o 12 vira `*1012`). Confira e troque em **Call Center › Motivos de Pausa**.
- O **agente não precisa mais de usuário** do console: nome, matrícula e
  PIN bastam para atender pelo telefone ou softphone.
- `*45` e `*46` **não funcionam mais em fila de call center**: nela se
  entra e se pausa pelos códigos do agente.
- Nenhum código pode ser igual a outro nem o começo de outro (códigos de
  recurso, pausas e caixas postais): a tela recusa ao salvar.
- **Caixas postais sem ramal** em **Aplicações › Caixas Postais**: recado
  fora do horário, ouvido no dia seguinte pelo código da caixa.
- **Rota de saída com vários padrões**: a atualização não junta as rotas que
  já existem. Duas rotas feitas só por causa do 0 (`9XXXXXXXX` e
  `09XXXXXXXX`) continuam duas; para juntar, acrescente o padrão `09…`
  (tirando o 0) numa delas e exclua a outra.
- Depois do playbook, **Aplicar configurações** no console.

### A senha do admin não volta mais ao padrão
- Até aqui, cada atualização devolvia a senha do `admin` à de fábrica. Agora
  o playbook só a define **uma vez** por servidor. Na primeira atualização
  com esta versão ela ainda é definida, e daí em diante é preservada.
- **Depois de atualizar, troque a senha do admin pelo console.**
- Perdeu a senha? `sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin`.

### Pente fino de produção
- **Login:** errar a senha bloqueia a conta só para o IP que errou; quem
  entra de outro lugar continua entrando.
- **Siga-me:** a atualização não religa mais o siga-me que o usuário desligou.
- **Horários:** o grupo "Comercial" de fábrica volta a ser de segunda a
  sexta, e as faixas que se repetiam a cada atualização são limpas.
  **Confira em Aplicações › Grupos de Horário** se o expediente está certo.
- O **nome da empresa** posto no console não volta mais ao de fábrica.
- **Logs** do Asterisk, da API e do e-mail agora giram (logrotate).
- O firewall abre a faixa do **fax T.38** (4000–4999 UDP).
- **Pesquisa:** a nota fora da escala não some mais. Tirar a tela de
  pesquisas de um perfil agora é definitivo.

### A central fala português
- Os avisos do sistema (correio de voz, conferência, fila, números,
  "ativado", "número inválido") passam a ser em **português do Brasil**. O
  pacote vem com o projeto e é instalado pelo playbook.
- A primeira atualização com ele agenda um **reinício do Asterisk** para
  quando não houver chamada: o idioma só muda depois dele. Para conferir,
  `sudo asterisk -rx "core show settings" | grep -i language` deve mostrar
  `pt_BR`.

### Código de cada condição horária
- Cada condição tem o seu código no telefone: `*27` + o número dela
  (`*271`, `*272`…), mostrado em **Aplicações › Condições Horárias**. Discar
  força a condição; discar de novo volta a seguir o horário. Numa tecla BLF
  do telefone, ela fica acesa enquanto a condição está forçada.
- Na condição, escolha o que o código faz — **fechar** (padrão), abrir ou
  inverter — e, se quiser, um **PIN**.
- Mudou: antes o mesmo código alternava entre três estados com o mesmo
  aviso. Agora é liga/desliga, com "activated" e "de-activated".

### Rotas de saída e de entrada
- O padrão aceita o jeito que se escreve: `x.` pega tudo, `0xx xxxx xxxx`
  vira `_0XXXXXXXXXX`. As telas têm modelos prontos e um teste "para onde
  vai este número".

### O nome do servidor

- A instalação **pergunta o nome** quando ele ainda é o de fábrica
  (`pabx.telium.local`) e o guarda nos ajustes da central (hoje,
  `/etc/telium-servidor.yml`). Numa central que já
  estava no ar com o nome de fábrica, **a próxima atualização vai
  perguntar**: responda com o nome do DNS (ex.: `voz.telium.com.br`). Nas
  seguintes, não pergunta mais.
- Com o nome novo, o certificado de fábrica é refeito. Se a central usa
  um certificado enviado pelo console, ele continua o mesmo.

### Pesquisa de satisfação (`5f390c2`)
- Novo módulo em **Aplicações › Pesquisa de Satisfação**. A pesquisa que
  já existia é mantida, com as respostas antigas.
- No relatório de agentes do call center, a coluna "Nota" virou
  **Satisfação**: um índice de 0 a 100, que não mistura escalas diferentes.

### Ramais por planilha (`43e7d6f`)
- **Conectividade › Ramais** ganhou **Modelo CSV**, **Exportar** e
  **Importar CSV**.

### Revisões de segurança e telefonia (`0214351`, `a2a26e3`)
- **Siga-me, desvio e transferência às cegas** seguem a permissão do ramal
  dono. Um ramal só-local com siga-me para celular precisa ganhar a
  permissão "Celular".
- **DISA** não disca internacional, e uma DISA com senha de menos de 6
  dígitos fica desativada até ganhar uma senha nova.
- **Escuta (*555)** só funciona no ramal de um usuário com perfil de
  supervisor.
- **0800/0300** seguem a permissão de ligação local.
- Os **áudios enviados pelo console** voltam a tocar (URA, filas, anúncios):
  vale ligar e conferir a saudação.
