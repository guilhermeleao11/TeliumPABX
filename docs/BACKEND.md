# Telium PABX — arquitetura do backend

## Visão geral

```
                        ┌── nginx 1.31 (TLS :443) ───────────────┐
 Navegador ── HTTPS ────┤  /          → front-end estático        │
            ── WSS  ────┤  /api       → PHP-FPM 8.4 (Slim)        │
                        │  /janus     → Janus (HTTP  :8088)       │
                        │  /janus-ws  → Janus (WS    :8188)       │
                        └────────────────────────────────────────┘
            ── WebRTC DTLS-SRTP (UDP 20000-20200) ──► Janus 1.4.1
                                                        │ SIP 5060 + RTP (loopback)
                                                        ▼
                                              Asterisk 22.11.0 (PJSIP)
                                                  │            │
                                       ODBC (CDR/CEL)      AMI :5038 / ARI :8090
                                                  ▼            ▲
                                            MariaDB 12.3.3 ────┘ (a API lê e escreve)
```

O navegador nunca fala SIP direto. Quem registra o ramal e carrega a mídia é o
**Janus**, através do plugin SIP; o Asterisk enxerga o Janus como um softphone
comum em 127.0.0.1. Isso mantém DTLS/ICE fora do Asterisk e simplifica o PJSIP.

> O transporte WSS do PJSIP está escrito e **comentado** em `pjsip.conf`.
> Se um dia quiser tirar o Janus do caminho, é só descomentar e marcar
> `webrtc = 1` no ramal — o gerador já trata esse campo.

## Portas

| Porta | Serviço | Exposição |
|---|---|---|
| 80 / 443 | nginx | rede |
| 5060 udp/tcp | Asterisk SIP | rede (telefones físicos) |
| 10000-10200 udp | RTP do Asterisk | rede |
| 20000-20200 udp | RTP/WebRTC do Janus | rede |
| 8088 / 8188 | Janus HTTP / WebSocket | **loopback** (via nginx) |
| 7088 | Janus admin | loopback |
| 8090 | Asterisk HTTP/ARI | loopback |
| 5038 | Asterisk AMI | loopback |
| 3306 | MariaDB | loopback |

## O fluxo "Aplicar configurações"

Foi a decisão de arquitetura desta fase: a API **gera arquivos `.conf`** em vez de
usar realtime. Funciona assim:

```
 1. Usuário edita um ramal no console        → API grava na tabela `ramais`
 2. API marca sistema.config_pendente = 1    → o console mostra "aplicar configurações"
 3. POST /api/config/aplicar
      ├── Gerador lê o banco
      ├── escreve /etc/asterisk/telium/*.conf de forma atômica (tmp + rename)
      └── Aplicador manda pelo AMI: pjsip reload, dialplan reload,
          module reload app_queue, voicemail reload
 4. Resultado vai para a tabela `config_aplicacoes` (auditoria do que recarregou)
```

Nada de `sudo`: a API escreve num diretório `2775 telium:asterisk` e recarrega
pelo AMI em 127.0.0.1.

### Arquivos gerados

| Arquivo | Origem no banco |
|---|---|
| `pjsip.endpoints.conf` | `ramais` |
| `pjsip.trunks.conf` | `troncos` |
| `extensions.ramais.conf` | `ramais` |
| `extensions.grupos.conf` | `grupos_toque` |
| `extensions.filas.conf` | `filas` |
| `extensions.ura.conf` | `ura` + `ura_opcoes` |
| `extensions.saida.conf` | `rotas_saida` + `troncos` |
| `extensions.entrada.conf` | `rotas_entrada` |
| `queues.conf` | `filas` + `fila_agentes` |
| `voicemail.conf` | `ramais` (com `voicemail = 1`) |

**Nunca tocados pelo gerador:** `pjsip_custom.conf` e `extensions_custom.conf`.
É onde vai qualquer ajuste manual.

O gerador é idempotente: compara o conteúdo (ignorando a linha de data) e só
reescreve o que mudou de fato.

### Filas e URA

A fila emite `queues.conf` com o que o `app_queue` entrega de verdade — sussurro
para o agente (`announce`), anúncio periódico, posição e tempo estimado, peso,
`maxlen`, `joinempty`/`leavewhenempty`, `autopause` e `memberdelay`. Não emite
`monitor-type`: o Asterisk 22 tirou a gravação do `app_queue`, e ela já acontece
em `sub-gravar`, no dialplan.

Failover é por motivo: tempo esgotado, fila sem agente e fila cheia têm destinos
próprios, escolhidos por `QUEUESTATUS`.

**Confirmação de atendimento** troca o membro `PJSIP/<ramal>` por
`Local/<ramal>@telium-confirma-<fila>/n`. O `U()` do `Dial` roda
`sub-confirmar-atendimento` no canal de quem vai atender; sem o dígito 1,
`GOSUB_RESULT=ABORT` desfaz e a fila procura outro.

**Pesquisa de satisfação** usa a opção `c` do `Queue`, que devolve o cliente ao
dialplan quando o atendente desliga. `QUEUESTATUS` vazio é justamente isso, e é
o que leva ao contexto `telium-pesquisa`. A nota vai para `pesquisa_respostas`
por `func_odbc`; quem ouviu e não respondeu entra com `nota = NULL`.

**Fila de call center** é uma marcação: `PUT /api/filas/{id}/agentes` recusa a
edição e explica que quem atribui os agentes é o módulo de call center.

Na URA, as entradas do menu não são gravadas uma a uma:
`PUT /api/ura/{id}/opcoes` recebe a lista inteira e a substitui, que é como a
tela monta a URA e as teclas na mesma gaveta. A tecla aceita o que o cliente
aperta (`1`, `0`, `*`, `#`) ou um padrão de dialplan (`_2XX`). Insistir numa
opção inválida o número de tentativas configurado leva ao destino de inválido.

### Conferências

Cada sala vira três seções no `confbridge.conf` gerado: a ponte (limite de
gente), o perfil do participante e o do administrador — que é quem tranca a
sala e tira quem entrou por último. O menu de teclas é um só para todas.

Detalhe que custou uma passada no Asterisk de verdade: em `confbridge.conf` o
`type` vai numa **linha própria**. Os parênteses depois do nome da seção são
herança de template, então `[sala-5000](type=bridge)` faz o Asterisk procurar um
template chamado `type=bridge`, não achar, e descartar o perfil inteiro.

O PIN é conferido no dialplan, e não pelo ConfBridge, porque assim o mesmo
número serve para os dois: quem digita o PIN de administrador entra com o perfil
que manda na sala. A gravação também é ligada no dialplan, que é quem monta o
nome do arquivo com a data — e registra a gravação em `gravacoes` por
`func_odbc`, já que a conferência é uma ponte e não uma chamada, e não teria
CDR para ser achada depois.

### Gravações de chamada

`GET /api/gravacoes` mostra as duas origens como uma coisa só: o CDR, que guarda
o arquivo de cada chamada, e a tabela `gravacoes`, onde entram as que não têm
CDR próprio — hoje, as conferências.

**Não existe rota de exclusão**, de propósito: gravação é prova de atendimento,
e apagar uma pela web não deveria ser um clique. Quem limpa o disco é a retenção
do backup, com prazo definido.

Ouvir e baixar passam por `GET /api/gravacoes/arquivo?caminho=…`, e vários de
uma vez por `POST /api/gravacoes/pacote`, que devolve um zip. O caminho pedido é
resolvido com `realpath` e comparado com a raiz das gravações antes de qualquer
leitura — sem isso, `../../etc/passwd` seria servido. Baixar fica na auditoria.

No navegador, `<a href>` e `<audio src>` não mandam o cabeçalho `Authorization`,
então o cliente busca o conteúdo como blob e cria uma URL local.

### Códigos de recurso

64 códigos em 19 categorias, no desenho do FreePBX: a **chave** é a identidade
(o gerador sabe o que ela faz), o **código discado** é trocável pelo console e a
unicidade é por `(codigo, tipo)` — o mesmo `**` pode ser captura direcionada ao
discar e outra coisa durante a chamada, que são namespaces diferentes.

- `tipo = dialplan` → vira extensão em `telium-recursos`. Quem precisa de um
  argumento (ramal, destino, fila) sai como padrão `_<codigo>X.` e lê o resto
  com `${EXTEN:n}`; os demais são extensões exatas.
- `tipo = featuremap` → vira linha do `[featuremap]` em `telium/features.conf`,
  incluído pelo `features.conf`.

O estado por ramal (não perturbe, os três desvios, siga-me, chamada em espera,
aviso de perdida, recusa de interfonia, despertador) mora na base interna do
Asterisk, que é o que as sub-rotinas do `extensions.conf` consultam em tempo de
chamada. O que precisa chegar ao banco — lista negra, allowlist, agenda e
chamadas perdidas — passa por `func_odbc.conf`. "Remover da lista" é `ativo = 0`,
porque o usuário do DSN não tem DELETE e porque assim o histórico continua
visível no console.

Número exato de lista negra e allowlist vale pela base do Asterisk; **padrão**
(`_1199X.`) continua no dialplan gerado, que a sub-rotina consulta logo depois.
"Aplicar configurações" repõe as duas famílias a partir do banco, senão console
e telefone divergiriam.

O despertador tem disparador próprio: `*68` grava a hora na base do Asterisk e
`/usr/local/sbin/telium-despertador`, num temporizador de um minuto, origina a
chamada e apaga a marcação.

### Contextos do dialplan

```
[interno]          contexto dos ramais
  ├── telium-recursos   *43 eco · *60 hora · *97/*98 correio · *8 captura · *411 diretório
  ├── telium-ramais     gerado
  ├── telium-grupos     gerado
  ├── telium-filas      gerado
  ├── telium-ura        gerado (uma URA = um contexto telium-ura-N)
  ├── telium-saida      gerado
  └── telium-custom     seu

[de-tronco]        chamadas das operadoras
  └── telium-entrada    gerado
```

Sub-rotinas estáticas que o código gerado chama:

- `sub-ramal(numero, tempo, mailbox)` — disca e trata ocupado/não atende
- `sub-permissao(classe)` — bloqueia a chamada se o ramal não pode discar aquela classe
- `sub-gravar(direcao)` — inicia o MixMonitor e grava o caminho no CDR

A permissão de discagem viaja no próprio endpoint PJSIP:
`set_var = TELIUM_PERM=emergencia,local,celular,ddd`. O dialplan testa com `REGEX`.

## Banco de dados

39 tabelas. Os três grupos:

- **Aplicação** — `empresa`, `perfis`, `perfil_modulos`, `perfil_acoes`, `usuarios`,
  `sessoes`, `auditoria`, `config_aplicacoes`, `sistema`
- **Telefonia** — `ramais`, `troncos`, `rotas_entrada`, `rotas_saida`, `filas`,
  `fila_agentes`, `grupos_toque`, `ura`, `ura_opcoes`, `condicoes_horarias`,
  `grupos_horario`, `grupo_horario_faixas`, `pin_sets`, `dispositivos`, `tarifas`
- **Operação** — `cdr`, `cel` (escritas pelo próprio Asterisk via ODBC),
  `gravacoes`, `contatos`, `integracoes`, `eventos`
- **Módulos** — `backup_rotinas`, `backups`, `audios`, `codigos_recurso`,
  `lista_negra`, `lista_permitida`, `certificados`, `certificado_servicos`,
  `destinos_personalizados`

Scripts em `infra/sql/`, aplicados pelo Ansible. DDL idempotente.

## API

PHP 8.4 + Slim 4, sem framework pesado. Rotas sob `/api`.

### Implementado

**Sessão**
```
GET  /api/health                     diagnóstico (banco, AMI, Janus) — público
POST /api/auth/login                 usuário + senha (+ 2FA quando ativo)
POST /api/auth/logout
GET  /api/me                         usuário, permissões resolvidas e dados do Janus
```

**Operação** — números apurados do CDR e do estado do Asterisk
```
GET  /api/painel/visaogeral          KPIs, volume por hora, top ramais, troncos, auditoria
GET  /api/tempo-real                 filas e canais ativos, lidos pelo AMI
GET  /api/sistema/estatisticas       CPU, memória, disco, uptime, versões
GET  /api/sistema/asterisk           saída bruta de comandos do CLI
```

**Relatórios**
```
GET  /api/cdr                        filtros de data, sentido, estado e busca; paginado
GET  /api/relatorios/filas           SLA e abandono por fila, série por dia
GET  /api/relatorios/agentes         volume e TMA por ramal
GET  /api/relatorios/tarifacao       custo por setor no mês
GET  /api/gravacoes
GET  /api/auditoria
```

**Cadastros** — CRUD completo, via um recurso REST genérico
```
/api/ramais          /api/troncos        /api/filas
/api/rotas-entrada   /api/rotas-saida    /api/ura      /api/ura-opcoes
/api/grupos-toque    /api/usuarios       /api/dispositivos
/api/tarifas         /api/integracoes    /api/codigos-recurso
/api/lista-negra     /api/lista-permitida
/api/destinos-personalizados
```
Cada um aceita `GET` (lista com `?q=`, filtros e paginação), `GET /{id}`,
`POST`, `PUT /{id}` e `DELETE /{id}`, sempre com verificação de módulo e de ação.

**Agenda de contatos** — fora do CRUD genérico: a regra é por linha
```
GET    /api/contatos[?escopo=corporativo|pessoal|todos&q=&grupo=]
GET    /api/contatos/{id}
POST   /api/contatos                 escopo corporativo exige admin.contatos
PUT    /api/contatos/{id}            contato da empresa: só quem administra
DELETE /api/contatos/{id}
POST   /api/contatos/{id}/foto       multipart; reduzida para 512 px
DELETE /api/contatos/{id}/foto
```
A agenda corporativa é `usuario_id IS NULL` e todo mundo enxerga. A pessoal
pertence a um usuário e só ele (ou quem tem `admin.contatos`) alcança.

**Certificados TLS**
```
GET    /api/certificados             cofre, serviços e dias para vencer
POST   /api/certificados             envio de cert + chave (+ cadeia), multipart ou colado
POST   /api/certificados/autoassinado
POST   /api/certificados/csr         gera chave + pedido para a autoridade
GET    /api/certificados/{id}/pem    parte pública ou o CSR — a chave nunca sai
POST   /api/certificados/{id}/assinar  conclui um pedido com o certificado emitido
POST   /api/certificados/aplicar     atribui aos serviços e dispara o aplicador
PUT    /api/certificados/{id}        nome e descrição
DELETE /api/certificados/{id}        recusado se algum serviço ainda usa
```
A API valida com openssl (chave confere com o certificado, cadeia cobre o
emissor, validade) e grava no cofre `/etc/telium/certificados`, que pertence a
ela. Quem copia para `/etc/ssl/telium`, `/etc/asterisk/keys` e `/etc/janus/certs`
e recarrega os serviços é `/usr/local/sbin/telium-certificados`, root, sem
argumento nenhum — mesmo desenho do backup. Ele guarda o par anterior antes de
trocar e o devolve se o `nginx -t` falhar, se o transporte TLS do pjsip não
subir ou se o Janus não voltar.

**Destinos personalizados**
```
GET  /api/destinos/contextos         quais contextos existem mesmo no dialplan
```
O CRUD é o genérico. O destino aponta para um contexto escrito à mão em
`extensions_custom.conf`; o gerador emite `Goto(contexto,extensao,prioridade)`,
e um destino apagado vira `NoOp(...não existe) + Hangup()` em vez de sumir.

**Especiais**
```
GET  /api/perfis                     perfis com módulos e ações
PUT  /api/perfis/{id}/permissoes     grava a matriz
GET  /api/empresa      PUT /api/empresa
POST /api/usuarios/{id}/senha        define senha e derruba as sessões
GET  /api/ramais/{id}/credenciais    senha SIP (registrado na auditoria)
GET  /api/config/estado              há configuração pendente?
POST /api/config/gerar               regenera os .conf
POST /api/config/aplicar             regenera e recarrega o Asterisk
```

Colunas sensíveis (`senha_sip`, `senha`, `senha_hash`, `totp_secret`, `segredo`)
nunca saem nas listagens.

### Credenciais

| O quê | Como nasce | Onde fica |
|---|---|---|
| Login do console | **fixa**: `T3l1um_@2024_@aD1m` | hash PBKDF2 na tabela `usuarios` |
| Senha do banco, ODBC do CDR, AMI, ARI | 28 caracteres aleatórios, **por instalação** | `/etc/telium/credenciais/`, 0600, só root |
| Senha SIP de cada ramal | aleatória, por instalação | tabela `ramais` |

O Ansible gera as senhas de serviço com o lookup `password`, que persiste em
arquivo: a primeira execução cria, as seguintes reaproveitam. Nada disso é
versionado nem impresso na tela. Definir `vault_db_senha` e companhia
(veja `vault.yml.exemplo`) sobrepõe as geradas.

### Autenticação

Token opaco de 32 bytes; no banco fica só o SHA-256. Vai por cookie
`HttpOnly; Secure; SameSite=Strict` e também é aceito em `Authorization: Bearer`.
Janela de inatividade renovável (`SESSAO_MINUTOS`, padrão 30).

Senhas em **PBKDF2-SHA256, 390.000 iterações**, formato
`pbkdf2_sha256$iter$salt$hash`, comparação com `hash_equals`. Cinco tentativas
erradas bloqueiam a conta por 15 minutos.

### Permissões

Duas camadas, e as duas são verificadas **no servidor**:

- `Permissao('conn.ramais')` — o perfil enxerga o módulo? (aceita `*` e `grupo.*`)
- `Permissao('conn.ramais', 'excluir')` — e pode executar a ação?

O menu filtrado no front é conveniência de interface. A decisão real está no
middleware, lendo `perfil_modulos` e `perfil_acoes`.

## Próximos passos

1. **CRUD dos módulos** — ramais, troncos, rotas, filas, URA (a base já existe;
   falta expor as rotas REST e ligar o front).
2. **Trocar o `DEMO` do front por `fetch`** — com estados de carregamento e erro.
3. **Tempo real** — daemon PHP consumindo AMI e publicando por WebSocket para
   wallboard, BLF e chamadas ativas.
4. **Softphone real** — `janus.js` no front, plugin SIP registrando o ramal.
5. **CDR e tarifação** — leitura das tabelas `cdr`/`cel` e cálculo por `tarifas`.
