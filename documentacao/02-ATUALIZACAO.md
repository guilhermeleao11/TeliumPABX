# 02 — Atualização

Levar uma central que já está no ar para a versão nova do Git.

O playbook é o mesmo da instalação e só mexe no que mudou: publica o
código novo em `/opt/telium`, aplica as mudanças de banco, reescreve a
configuração do Asterisk, recarrega o que precisa e roda a bateria de
testes. **Chamadas em andamento não caem**: o Asterisk é recarregado, não
reiniciado. Quando uma mudança exige reinício, ele é agendado para quando
a central estiver sem chamada nenhuma.

> Nos comandos abaixo o clone está em `/opt/TeliumPABX`, como na VPS de
> produção. Se o seu estiver em outra pasta, troque o caminho.

## Resumo

```bash
cd /opt/TeliumPABX && git pull && cd infra/ansible && sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false
```

O passo a passo, com o que conferir, vem a seguir.

## 1. Antes

**Faça um backup.** Pelo console, em **Administrador › Backup &
Restauração › Fazer backup agora**; ou pelo terminal, como está em
[03 — Backup e restauração](03-BACKUP-E-RESTAURACAO.md#backup-rápido-pelo-terminal).

**Anote a versão atual**, para saber para onde voltar se precisar:

```bash
cd /opt/TeliumPABX && git log --oneline -1
```

**Escolha a hora.** A atualização não derruba chamada, mas recarrega a
central inteira. Num horário de pouco movimento, qualquer surpresa afeta
menos gente.

## 2. Baixar a versão nova

```bash
cd /opt/TeliumPABX
sudo git pull
git log --oneline -1
```

A última linha mostra a versão que chegou.

### Se o `git pull` recusar

```
error: Your local changes to the following files would be overwritten by merge:
        infra/ansible/group_vars/all/main.yml
```

Alguém editou o `main.yml` direto no servidor. Os ajustes deste servidor
devem ficar no `servidor.yml`, fora do Git. Para mudar:

```bash
cd /opt/TeliumPABX
git diff infra/ansible/group_vars/all/main.yml      # veja o que foi mudado
```

Passe cada valor mudado para o `servidor.yml` (a mesma linha, com o valor
do servidor). Se o arquivo ainda não existe, crie a partir do modelo:

```bash
cd /opt/TeliumPABX/infra/ansible/group_vars/all
sudo cp -n servidor.yml.exemplo servidor.yml
sudo nano servidor.yml
```

Depois desfaça a edição no `main.yml` e baixe de novo:

```bash
cd /opt/TeliumPABX
sudo git checkout -- infra/ansible/group_vars/all/main.yml
sudo git pull
```

## 3. Aplicar

```bash
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false
```

- Os dois `-e` pulam as compilações, que são a parte demorada e quase
  nunca mudam. **Só rode sem eles** quando a nota da versão disser que a
  versão do Asterisk ou do Janus mudou.
- Tem de terminar com `failed=0`. Se parar com erro, **não siga**: copie a
  saída e veja [04 — Verificação e problemas comuns](04-VERIFICACAO-E-PROBLEMAS.md).

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

Volte o código para a versão anotada no passo 1 e rode o playbook de novo:

```bash
cd /opt/TeliumPABX
sudo git checkout <versão anotada>
cd infra/ansible
sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false
```

As mudanças de banco deste projeto só **acrescentam** tabelas e colunas,
então o código anterior costuma funcionar com o banco já atualizado. Se
não funcionar, restaure o backup do passo 1
([03 — Backup e restauração](03-BACKUP-E-RESTAURACAO.md#restaurar)).

Para voltar a acompanhar o Git depois:

```bash
cd /opt/TeliumPABX && sudo git checkout main && sudo git pull
```

## Notas das versões

### O projeto mudou para o GitLab da Telium
O repositório agora é `gitlab.telium.com.br/Voip/teliumpabx`. Numa
central instalada a partir do GitHub, aponte o clone para o endereço novo
**uma vez**, antes do `git pull`:

```bash
cd /opt/TeliumPABX
sudo git remote set-url origin https://gitlab.telium.com.br/Voip/teliumpabx.git
sudo git pull
```

O GitLab pede usuário e token (ou use uma chave de implantação).

O que muda na operação, e não só por dentro.

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
  (`pabx.telium.local`) e o guarda no `servidor.yml`. Numa central que já
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
