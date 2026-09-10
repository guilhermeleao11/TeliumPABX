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

30 tabelas. Os três grupos:

- **Aplicação** — `empresa`, `perfis`, `perfil_modulos`, `perfil_acoes`, `usuarios`,
  `sessoes`, `auditoria`, `config_aplicacoes`, `sistema`
- **Telefonia** — `ramais`, `troncos`, `rotas_entrada`, `rotas_saida`, `filas`,
  `fila_agentes`, `grupos_toque`, `ura`, `ura_opcoes`, `condicoes_horarias`,
  `grupos_horario`, `grupo_horario_faixas`, `pin_sets`, `dispositivos`, `tarifas`
- **Operação** — `cdr`, `cel` (escritas pelo próprio Asterisk via ODBC),
  `gravacoes`, `contatos`, `integracoes`, `eventos`

Scripts em `infra/sql/`, aplicados pelo Ansible. DDL idempotente.

## API

PHP 8.4 + Slim 4, sem framework pesado. Rotas sob `/api`.

### Já implementado

```
GET  /api/health              diagnóstico (banco, AMI, Janus) — público
POST /api/auth/login          usuário + senha (+ código 2FA quando ativo)
POST /api/auth/logout
GET  /api/me                  usuário, permissões resolvidas e dados do Janus
GET  /api/config/estado       há configuração pendente?
POST /api/config/gerar        regenera os .conf
POST /api/config/aplicar      regenera e recarrega o Asterisk
```

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
