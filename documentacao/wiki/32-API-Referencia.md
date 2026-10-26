# Referência da API — Telium PABX

A API REST que o console usa e que outros sistemas podem chamar. Todas as rotas estão declaradas em `api/public/index.php`; esta página foi extraída de lá. Se as duas divergirem, vale o código.

Veja também: [31-Arquitetura.md](31-Arquitetura.md) e [30-Desenvolvimento.md](30-Desenvolvimento.md).

---

## Sumário

1. [Base e convenções](#1-base-e-convenções)
2. [Autenticação](#2-autenticação)
3. [Formato de resposta e erros](#3-formato-de-resposta-e-erros)
4. [Recursos genéricos: listagem, filtros e paginação](#4-recursos-genéricos-listagem-filtros-e-paginação)
5. [Rotas públicas](#5-rotas-públicas)
6. [Click to call para outros sistemas](#6-click-to-call-para-outros-sistemas)
7. [Rotas autenticadas por área](#7-rotas-autenticadas-por-área)
8. [Recursos CRUD genéricos](#8-recursos-crud-genéricos)

---

## 1. Base e convenções

- **Base:** `https://<central>/api`. Exceções: o provisionamento, em `/prov/...` e `/p/...` (sem o `/api`).
- **Corpo:** JSON (`Content-Type: application/json`). Upload de arquivo é `multipart/form-data`.
- **Respostas:** JSON UTF-8, exceto downloads (áudio, CSV, backup, PEM) e o provisionamento.
- **Versão:** a versão da central e o commit publicado saem em `GET /api/me` (`versao`, por exemplo `"1.1.0 (ae4f854)"`), em `GET /api/sistema/versoes` e em `GET /api/health`.
- **Cabeçalhos em toda resposta:** `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, `X-Telium-Api: 1.0`.
- **Limites do nginx:** 30 pedidos/s por IP no `/api/` (com folga de 60), 10 por minuto no `/api/auth/login` (folga de 5). Passou do limite: **429**.
- **Coluna "Permissão"** nas tabelas: `módulo` exige o módulo no perfil; `módulo + ação` exige também a ação (`criar`, `editar`, `excluir`, `exportar`, `reiniciar`, `permissoes`). "Também:" lista módulos que abrem a mesma porta (em geral, só para ler por referência). "sessão" quer dizer só estar logado.

---

## 2. Autenticação

### Login

```
POST /api/auth/login
{ "usuario": "admin", "senha": "...", "codigo": "123456" }
```

`codigo` é o TOTP, só para contas com verificação em dois passos.

| Resposta | Quando |
|---|---|
| 200 `{ "token": "...", "usuario": {...} }` + cookie `telium_sessao` | Entrou |
| 200 `{ "precisa_2fa": true }` | A conta tem 2FA e o `codigo` não veio: repita o pedido com ele |
| 401 | Usuário ou senha inválidos (mensagem genérica), código 2FA inválido ou já usado |
| 403 | Usuário inativo/bloqueado no cadastro, ou 2FA ligado sem segredo |
| 422 | Faltou usuário ou senha |
| 429 | Conta bloqueada por 15 min após 5 erros (para a origem que errou) ou 20 falhas da mesma origem em 10 min |

### Usando a sessão

Mande o token em **um** destes:

- `Authorization: Bearer <token>`
- cookie `telium_sessao` (HttpOnly, SameSite=Strict, Secure), gravado pelo login

A sessão vale `SESSAO_MINUTOS` (padrão 30) de inatividade e é renovada a cada pedido. Sem sessão válida, toda rota autenticada responde **401** (`"Não autenticado"` ou `"Sessão expirada ou inválida"`). Sem permissão, **403** com `modulo` ou `acao` no corpo.

### Conta própria e 2FA

| Método | Caminho | O que faz |
|---|---|---|
| POST | `/auth/logout` | Encerra a sessão e apaga o cookie |
| GET | `/me` | Usuário, permissões (`allow`, `caps`), empresa, versão (`versao`: número e, quando conhecido, o commit entre parênteses, como `1.1.0 (ae4f854)`) e dados do softphone |
| POST | `/me/senha` | Troca a própria senha (`atual`, `nova`); encerra as outras sessões |
| POST | `/me/2fa/iniciar` | Gera o segredo TOTP e a URI do QR Code (ainda não liga) |
| POST | `/me/2fa/confirmar` | Liga o 2FA provando um `codigo` |
| DELETE | `/me/2fa` | Desliga o 2FA (corpo com `senha`) |

---

## 3. Formato de resposta e erros

`api/src/Suporte/Resposta.php`:

- **Sucesso:** o JSON do recurso, com status 200 (ou 201 na criação).
- **Erro:** `{ "erro": "mensagem para o usuário" }` mais campos extras quando houver, por exemplo `{ "erro": "...", "campo": "numero" }` (o console marca o campo no formulário), `{ "modulo": "..." }` ou `{ "acao": "..." }` no 403.
- **Arquivos** (`Resposta::arquivo`): fluxo com suporte a `Range` (206 Partial Content, 416 fora da faixa).
- **Erro não tratado:** 500 `{ "erro": "Erro interno na API" }`. Com `APP_DEBUG=true`, a mensagem real, `arquivo` e `linha`. Rota inexistente: 404; método errado: 405.

Exceção: o click to call responde no formato `{ "ok": false, "erro": "..." }` (seção 6).

Códigos usados:

| Código | Significado no projeto |
|---|---|
| 200 / 201 | Sucesso / criado |
| 401 | Sem sessão (ou chave de API inválida no click to call) |
| 403 | Sem o módulo ou a ação |
| 404 | Registro não encontrado |
| 409 | Conflito: valor duplicado em coluna única, registro em uso, impedimento de regra de negócio |
| 422 | Validação: campo obrigatório, formato, valor fora da lista |
| 429 | Limite de tentativas ou de pedidos |
| 502 | Serviço externo falhou (AMI no click to call, SMTP, destino de backup) |
| 202 | Aceito, termina em segundo plano (ex.: `DELETE /api/backup/{id}` com o arquivo no disco) |
| 503 | `/api/health` com alguma dependência fora |

---

## 4. Recursos genéricos: listagem, filtros e paginação

Os cadastros da seção 8 usam o CRUD genérico (`api/src/Http/Recurso.php`).

### Listar

```
GET /api/ramais?q=joao&setor=Suporte&limite=50&pagina=2
```

| Parâmetro | Efeito |
|---|---|
| `q` | Busca textual (LIKE) nas colunas de busca do recurso |
| `<coluna>=<valor>` | Igualdade, só nas colunas declaradas como filtro |
| `limite` | 1 a 500 (padrão 200) |
| `pagina` | A partir de 1 |

Resposta:

```json
{ "dados": [ { "id": 1, "numero": "1001", ... } ], "total": 73, "pagina": 2, "limite": 50 }
```

Colunas sensíveis (senha SIP, PINs, segredos) **nunca** voltam. Quem lê a lista só por referência (outra tela com seletor) pode receber menos colunas.

### Obter, criar, atualizar, remover

| Pedido | Resposta |
|---|---|
| `GET /api/<recurso>/{id}` | O registro, ou 404 |
| `POST /api/<recurso>` com os campos | 201 e o registro criado |
| `PUT /api/<recurso>/{id}` com só os campos que mudam | 200 e o registro atualizado |
| `DELETE /api/<recurso>/{id}` | `{ "removido": true }` |

Regras da escrita:

- Campos fora da lista de colunas graváveis são ignorados. Nenhum campo válido: 422.
- Campo secreto em branco na edição = manter o valor atual; `null` = remover (se o campo não tiver tamanho mínimo).
- Recursos que viram configuração do Asterisk recusam `${` e `$[` em qualquer campo (422) e marcam a configuração como pendente (aplicar com `POST /api/config/aplicar`).
- Erros de banco traduzidos: duplicado → 409 com `campo`; vínculo inexistente → 422; registro em uso → 409.

---

## 5. Rotas públicas

Sem sessão. São só estas:

| Método | Caminho | O que faz |
|---|---|---|
| GET | `/api/health` | Saúde da API |
| POST | `/api/auth/login` | Login (seção 2) |
| GET, POST | `/api/click2call` | Click to call com chave de API (seção 6) |
| GET | `/prov/{segredo}/{arquivo}` | Configuração do telefone IP |
| GET | `/p/{token}` | Configuração do Linphone lida pelo QR Code |

### GET /api/health

Confere PHP, banco, esquema (o banco acompanha o código?) e Asterisk (AMI).

- Sem sessão: `{ "servico", "versao", "saudavel" }`.
- Com sessão válida (Bearer ou cookie): também `commit` (o publicado, de `api/COMMIT`; termina em `+` se o clone tinha alteração fora do Git), `ambiente`, `horario` e `checagens` com o detalhe de cada uma (inclusive `config_pendente`).
- Status **200** quando tudo está bem, **503** quando algo falhou. É a rota para monitoramento.

### GET /prov/{segredo}/{arquivo}

O telefone busca a própria configuração no boot. O MAC vem do nome do arquivo (`cfg<mac>.xml` na Grandstream, `<mac>.cfg` nos outros). Entrega se: o segredo confere, a origem é rede local (quando a central exige), o MAC está cadastrado em Dispositivos e o dispositivo tem ramal ativo. Responde o arquivo (`text/xml` ou `text/plain`, `Cache-Control: no-store`). **Qualquer recusa responde 404**, sem dizer o motivo; o motivo fica no registro de provisionamento. O nginx dá a este caminho um limite maior (folga de 120) para os aparelhos que sobem juntos.

### GET /p/{token}

O Linphone lendo o QR Code gerado em `POST /api/provisionamento/linphone`. O token é aleatório, vale 10 minutos e uma vez só. Token desconhecido: 404; token usado ou vencido: 410. Também responde pela porta 80, para centrais sem certificado de confiança.

---

## 6. Click to call para outros sistemas

A central toca o ramal e, quando ele atende, disca o número. Para CRM, ERP ou páginas. A chave é criada no console em **Telium › Integrações / API** e só é mostrada na criação (o banco guarda o hash). Começa com `tlm_c2c_`.

Controller: `api/src/Http/Controllers/ClickToCall.php`; regras: `api/src/Dominio/ClickToCall.php`.

### Pedido

```
GET  /api/click2call?ramal=1001&numero=11999990000&nome=Maria
POST /api/click2call   { "ramal": "1001", "numero": "11999990000", "nome": "Maria" }
```

| Parâmetro | Obrigatório | Descrição |
|---|---|---|
| `ramal` | sim | Ramal que vai tocar. Só os dígitos são considerados |
| `numero` | sim | Número a discar. Ficam só dígitos, `*`, `#` e `+` |
| `nome` | não | Nome mostrado no visor do ramal (até 40 caracteres; aspas e `< >` são retirados). Vazio: mostra o número |
| `token` | um dos três | A chave, como parâmetro (para sistemas que só abrem URL) |

A chave pode ir em `X-Api-Key: <chave>`, `Authorization: Bearer <chave>` ou no parâmetro `token`, nessa ordem de preferência. Query string e corpo são mesclados.

```sh
curl -X POST https://pabx.exemplo.com.br/api/click2call \
  -H 'X-Api-Key: tlm_c2c_...' -H 'Content-Type: application/json' \
  -d '{"ramal":"1001","numero":"11999990000","nome":"Maria"}'
```

A chamada entra pelo contexto do próprio ramal: passa pela permissão de discagem, limite de chamadas e rota de saída como se a pessoa tivesse discado. Se a chave tem "atender automaticamente", o ramal recebe os cabeçalhos de atendimento automático (`Call-Info` e `Alert-Info`).

### Resposta de sucesso (200)

```json
{ "ok": true, "id": "c2c-0123456789abcdef", "ramal": "1001",
  "numero": "11999990000", "mensagem": "O ramal 1001 vai tocar; ao atender, a central disca 11999990000." }
```

O `id` é o uniqueid da chamada no CDR: guarde-o para achar o registro e a gravação depois.

### Erros

Formato: `{ "ok": false, "erro": "..." }`.

| Código | Quando |
|---|---|
| 401 | Chave ausente, inválida ou desativada |
| 403 | A chave não pode ser usada deste IP, ou não pode fazer este ramal tocar |
| 404 | O ramal não existe ou está desativado |
| 409 | O ramal não está registrado na central, ou está registrado mas não responde |
| 422 | Faltou `ramal` ou `numero` (ou o número ficou vazio depois da limpeza) |
| 429 | 20 ou mais pedidos com chave ausente, inválida ou desativada do mesmo IP nos últimos 10 minutos. Os pedidos de uma chave que foi excluída depois não entram nessa conta |
| 502 | A central não respondeu ou recusou a chamada (falha no AMI) |

Todo pedido, aceito ou recusado, fica em `click2call_pedidos` e aparece na tela de Integrações.

### Administração das chaves (autenticado)

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/click2call/chaves` | `telium.integracoes` | Chaves (sem o texto) e os últimos 50 pedidos |
| POST | `/click2call/chaves` | `telium.integracoes` + criar | Cria; devolve `token` uma única vez |
| PUT | `/click2call/chaves/{id}` | `telium.integracoes` + editar | Nome, ramais, IPs, atender automático, ativo |
| POST | `/click2call/chaves/{id}/renovar` | `telium.integracoes` + editar | Gera chave nova; a antiga para na hora |
| DELETE | `/click2call/chaves/{id}` | `telium.integracoes` + excluir | Remove |
| POST | `/click2call/testar` | `telium.integracoes` + editar | A mesma chamada, pelo console |

---

## 7. Rotas autenticadas por área

Todos os caminhos abaixo são relativos a `/api`.

### Meu Painel e telefone do navegador

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| POST | `/discar` | `pcu.meuramal`. Também: `pcu.contatos`, `pcu.chamadas`, `admin.contatos`, `rel.cdr`, `conn.ramais`, `fone.webrtc` | Click to call a partir do ramal da sessão |
| GET | `/me/telefone` | `fone.webrtc` | Dados da tela do telefone |
| PUT | `/me/telefone/dnd` | `fone.webrtc` | Não perturbe do próprio ramal |
| GET | `/me/ramal` | `pcu.meuramal`. Também: `pcu.sigame`, `pcu.perfil` | O próprio ramal |
| PUT | `/me/ramal` | `pcu.meuramal`. Também: `pcu.sigame` | Altera o próprio ramal (siga-me e afins) |
| GET | `/me/chamadas` | `pcu.chamadas` | Chamadas do próprio ramal |
| POST | `/me/webrtc/chamada` | `pcu.meuramal`. Também: `fone.webrtc` | Resumo do áudio de uma chamada do softphone |
| GET | `/me/correiovoz` | `pcu.correiovoz` | Recados do próprio correio |
| GET | `/me/correiovoz/audio` | `pcu.correiovoz` | Áudio de um recado |
| DELETE | `/me/correiovoz` | `pcu.correiovoz` | Apaga recado |

### Painel e relatórios

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/painel/visaogeral` | `dash.visaogeral` | Indicadores da visão geral |
| GET | `/tempo-real` | `dash.temporeal` | Wallboard |
| GET | `/sistema/estatisticas` | `dash.sistema` | Estatísticas do servidor |
| GET | `/sistema/asterisk` | `dash.asterisk` | Informações do Asterisk |
| GET | `/cdr` | `rel.cdr` | Relatório de chamadas. Filtros: `de`, `ate` (AAAA-MM-DD), `direcao`, `status` (disposition), `q` (origem, destino ou nome); `limite` 10 a 200 (padrão 50), `pagina` |
| GET | `/cdr/exportar` | `rel.cdr` + exportar | O mesmo filtro do `/cdr`, em CSV (`cdr-AAAA-MM-DD.csv`): UTF-8 com BOM, separador `;`, custo com vírgula decimal. Colunas: Data e hora, Origem, Nome de quem ligou, Destino, Sentido, Duração (s), Falada (s), Resultado, Tronco, Motivo, Fila, Custo (R$), Gravação. Texto que começa com `=`, `+`, `-` ou `@` sai com `'` na frente (não vira fórmula). Acima de 200 000 chamadas no filtro: 422 pedindo um período menor. Fica na auditoria (`exportar`, `rel.cdr`) |
| GET | `/relatorios/filas` | `rel.filas` | Relatório de filas |
| GET | `/relatorios/agentes` | `rel.agentes` | Produtividade de quem atendeu chamada de fila, pelo `queue_log`. `dias` de 1 a 90 (padrão 7; a tela usa 1, 7, 30 e 90). Cada linha: `identificacao` (matrícula do agente do call center ou o membro da fila), `callcenter`, `nome`, `atendidas`, `tma`, `nao_atendeu`, `pausado`, `nota`, `respostas` |
| GET | `/relatorios/ramais` | `rel.ramais` | Relatório por ramal |
| GET | `/relatorios/troncos` | `rel.troncos` | Ocupação de troncos: `chamadas`, `atendidas`, `ocupadas` (BUSY), `sem_resposta` (NO ANSWER), `falhas` (FAILED), `congestionadas` (CONGESTION), `segundos`, `custo` |
| GET | `/relatorios/eventos` | `rel.cel` | Eventos (CEL) |
| GET | `/relatorios/tarifacao` | `telium.tarifacao` | Tarifação |
| POST | `/relatorios/tarifacao` | `telium.tarifacao` + editar | Tarifa agora as chamadas de saída do `mes` (AAAA-MM) ainda sem custo. Com `refazer: 1` (o botão "Recalcular o mês"), recalcula **todas** as do mês com os preços de agora; sem `mes`, vale o mês corrente. A chamada que ficou sem tarifa que se aplique perde o custo antigo |
| GET | `/auditoria` | `admin.auditoria` | Trilha de auditoria |
| GET | `/sistema/versoes` | `telium.versoes` | `versao`, `commit`, `exibicao` e o histórico lido do `api/CHANGELOG.md`: `introducao` e `versoes` (cada uma com `numero`, `data`, `commits`, `resumo` e `secoes`, cada seção com `titulo` e `itens`) |
| GET | `/gravacoes` | `apps.gravacao` | Lista gravações (não há rota de exclusão, de propósito). Traz também `retencao`: `dias` (prazo de guarda) e `ultima` (resultado da última limpeza) |
| PUT | `/gravacoes/retencao` | `apps.gravacao` + editar | Prazo de guarda: `{ "dias": 0 a 3650 }`. Zero guarda para sempre. Não apaga na hora: o executor de backup apaga, uma vez por dia (e na passada seguinte ao salvar), as gravações mais antigas que o prazo. Valor fora da faixa: 422. Fica na auditoria com o prazo de antes e o novo |
| GET | `/gravacoes/arquivo` | `apps.gravacao` | Áudio de uma gravação |
| POST | `/gravacoes/pacote` | `apps.gravacao` + exportar | Pacote de gravações para baixar |

### Usuários, perfis e empresa

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/perfis` | `admin.permissoes`. Também: `admin.usuarios` | Lista perfis |
| POST | `/perfis` | `admin.permissoes` + permissoes | Cria perfil |
| PUT | `/perfis/{id}` | `admin.permissoes` + permissoes | Altera perfil |
| DELETE | `/perfis/{id}` | `admin.permissoes` + permissoes | Remove perfil |
| PUT | `/perfis/{id}/permissoes` | `admin.permissoes` + permissoes | Grava módulos e ações do perfil |
| POST | `/usuarios` | `admin.usuarios` + criar | Cria usuário (com senha). Os demais métodos de `/usuarios` são do CRUD genérico |
| POST | `/usuarios/{id}/senha` | `admin.usuarios` + editar | Redefine a senha |
| POST | `/usuarios/{id}/2fa/desligar` | `admin.usuarios` + editar | Desliga o 2FA de outro usuário |
| POST | `/usuarios/{id}/destravar` | `admin.usuarios` + editar | Destrava conta bloqueada por tentativas |
| GET | `/empresa` | sessão | Dados da empresa |
| PUT | `/empresa` | `cfg.empresa` + editar | Altera dados da empresa |

### Ramais (além do CRUD)

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/ramais/csv/modelo` | `conn.ramais` | Planilha modelo |
| GET | `/ramais/csv` | `conn.ramais` + exportar | Exporta ramais em CSV |
| POST | `/ramais/csv` | `conn.ramais` + criar | Importa ramais de CSV |
| GET | `/ramais/{id}/credenciais` | `conn.ramais` + editar | Credenciais SIP do ramal |

### Diagnóstico, rede e firewall

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/diagnostico/rede` | `conn.rede` | Endereço público e redes locais |
| PUT | `/diagnostico/rede` | `conn.rede` + editar | Altera IP público e redes locais (NAT) |
| GET | `/diagnostico/rede/descobrir` | `conn.rede` | Descobre o IP público |
| GET | `/diagnostico/webrtc` | `conn.webrtc` | O caminho do softphone: transporte wss, WebSocket do Asterisk, ICE e ramal WebRTC |
| POST | `/diagnostico/webrtc` | `conn.webrtc` + editar | Liga ou desliga o telefone do navegador |
| GET | `/diagnostico/seguranca` | `conn.firewall` | Estado da proteção |
| GET | `/diagnostico/banidos` | `conn.firewall` | IPs banidos |
| POST | `/diagnostico/desbanir` | `conn.firewall` + editar | Desbane um IP |
| GET | `/firewall/confiaveis` | `conn.firewall` | Lista "nunca bloquear" |
| POST | `/firewall/confiaveis` | `conn.firewall` + editar | Acrescenta à lista |
| DELETE | `/firewall/confiaveis/{id}` | `conn.firewall` + editar | Remove da lista |
| GET | `/diagnostico/sip` | `cfg.sip` | Estado do SIP |
| GET | `/diagnostico/dids` | `conn.did` | DIDs |
| GET | `/diagnostico/troncos` | `conn.troncos` | Estado dos troncos na central |

### E-mail, backup, áudios e certificados

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/email` | `cfg.notificacoes` | Configuração de envio |
| PUT | `/email` | `cfg.notificacoes` + editar | Grava a configuração. Inclui `alertas_para`: e-mails dos alertas do sistema, separados por vírgula (aceita também `;` e espaço). Um endereço inválido na lista: 422 com `campo: alertas_para` |
| POST | `/email/testar` | `cfg.notificacoes` + editar | Envia e-mail de teste |
| GET | `/backup` | `admin.backup` | Backups e estado |
| POST | `/backup/executar` | `admin.backup` + criar | Faz backup agora |
| GET | `/backup/destino` | `admin.backup` | Destino remoto |
| PUT | `/backup/destino` | `admin.backup` + editar | Grava destino remoto |
| POST | `/backup/destino/testar` | `admin.backup` + editar | Testa o destino remoto |
| POST | `/backup/{id}/enviar` | `admin.backup` + exportar | Envia ao destino remoto |
| GET | `/backup/{id}/baixar` | `admin.backup` + exportar | Baixa o arquivo |
| POST | `/backup/{id}/restaurar` | `admin.backup` + reiniciar | Restaura |
| DELETE | `/backup/{id}` | `admin.backup` + excluir | Remove. Sem arquivo no disco: apaga o registro e responde 200. Com arquivo: marca o backup como `removendo` e responde **202** com `pendente: true` e `detalhe`; o executor de backup (root) apaga o arquivo e o registro em até um minuto. Backup na fila ou executando: 409 |
| GET | `/audios` | `admin.gravacoes`. Também: telas que escolhem destino | Lista áudios do sistema |
| POST | `/audios` | `admin.gravacoes` + criar. Também: `cfg.musica` | Envia áudio |
| GET | `/audios/{id}/ouvir` | `admin.gravacoes`. Também: `cfg.musica` | Toca o áudio |
| PUT | `/audios/{id}` | `admin.gravacoes` + editar. Também: `cfg.musica` | Altera |
| DELETE | `/audios/{id}` | `admin.gravacoes` + excluir. Também: `cfg.musica` | Remove |
| GET | `/certificados` | `admin.certificados` | Lista certificados |
| POST | `/certificados` | `admin.certificados` + criar | Envia certificado |
| POST | `/certificados/autoassinado` | `admin.certificados` + criar | Gera autoassinado |
| POST | `/certificados/csr` | `admin.certificados` + criar | Gera CSR |
| POST | `/certificados/aplicar` | `admin.certificados` + reiniciar | Atribui certificados aos serviços e dispara o aplicador |
| GET | `/certificados/{id}/pem` | `admin.certificados` | Baixa o PEM |
| POST | `/certificados/{id}/assinar` | `admin.certificados` + editar | Recebe o certificado emitido para um CSR |
| PUT | `/certificados/{id}` | `admin.certificados` + editar | Altera |
| DELETE | `/certificados/{id}` | `admin.certificados` + excluir | Remove |

### Call center

O fluxo ao vivo (SSE) está em `GET /api/cc/eventos`, servido pelo serviço `telium-cc` (127.0.0.1:8095) por trás do nginx, e não pelo PHP. Ele confere a sessão pelo cabeçalho Authorization. Detalhes do módulo em [16-Call-Center.md](16-Call-Center.md).

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/cc/estado` | `cc.agente`. Também: `cc.supervisor` | Foto do call center |
| GET | `/cc/eu` | `cc.agente` | Estado do agente logado |
| POST | `/cc/eu/entrar` | `cc.agente` | Entra no atendimento |
| POST | `/cc/eu/sair` | `cc.agente` | Sai do atendimento |
| POST | `/cc/eu/pausa` | `cc.agente` | Entra em pausa |
| POST | `/cc/eu/volta` | `cc.agente` | Volta da pausa |
| POST | `/cc/atendimentos/{id}/tabular` | `cc.agente` | Registra a tabulação |
| POST | `/cc/agentes/{id}/comando` | `cc.supervisor` + editar | Comando do supervisor: `acao` = pausar, voltar, sair, escutar, sussurrar ou intervir (`motivo_id` na pausa) |
| GET | `/cc/agentes` | `cc.agentes`. Também: `cc.supervisor` | Lista agentes |
| POST | `/cc/agentes` | `cc.agentes` + criar | Cria agente |
| PUT | `/cc/agentes/{id}` | `cc.agentes` + editar | Altera agente |
| DELETE | `/cc/agentes/{id}` | `cc.agentes` + excluir | Remove agente |
| GET | `/cc/retornos` | `cc.retornos`. Também: `cc.supervisor` | Lista retornos |
| POST | `/cc/retornos/{id}` | `cc.retornos` + editar | Atualiza um retorno |
| GET | `/cc/config` | `cc.config` | Configuração do módulo |
| PUT | `/cc/config` | `cc.config` + editar | Grava a configuração |
| GET | `/cc/relatorio` | `cc.relatorios` | Relatório do call center |

### Webhooks (Integrações / API)

O cadastro é o recurso `integracoes` (seção 8). Além dele:

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| POST | `/integracoes/{id}/testar` | `telium.integracoes` + editar | Manda agora um aviso de teste para a URL do webhook, assinado como os de verdade. Responde `{ "ok": true/false, "detalhe": "..." }` (o código HTTP que o destino deu ou o erro de conexão). Integração que não é do tipo `webhook`: 404 |

**O que a central manda.** Um `POST` com `Content-Type: application/json` e `User-Agent: Telium-PABX`, prazo de 5 segundos e sem seguir redirecionamento. Quem envia é o serviço `telium-cc`; com ele parado, não há aviso. Os eventos:

| Evento | Quando | Campos além de `evento`, `id` e `quando` |
|---|---|---|
| `chamada.tocando` | Um ramal começou a tocar (um aviso por ramal que toca) | `ramal`, `de`, `nome`, `para` |
| `chamada.atendida` | Alguém atendeu (o primeiro atendimento da chamada) | `ramal`, `espera_segundos`, `de`, `nome`, `para` |
| `chamada.encerrada` | A chamada terminou (só as que tocaram algum ramal) | `atendida` (true/false), `duracao_segundos`, `conversa_segundos`, `causa`, `de`, `nome`, `para` |

`id` é o identificador da chamada no Asterisk (o `Linkedid`), o mesmo nos três avisos; `quando` é a data e hora ISO 8601; `de` e `nome` são o número e o nome de quem ligou; `para` é o ramal. O aviso de teste traz `evento: "teste"` e `mensagem`.

```json
{ "evento": "chamada.atendida", "id": "1760360000.123", "quando": "2026-10-13T10:15:02-03:00",
  "ramal": "1001", "espera_segundos": 7, "de": "11999990000", "nome": "Maria", "para": "1001" }
```

**Assinatura.** Com o campo Segredo preenchido, cada aviso leva `X-Telium-Assinatura: sha256=<hex>`, o HMAC-SHA256 do corpo exato com o segredo. Quem recebe calcula o mesmo HMAC sobre o corpo cru e compara.

**Resultado.** Cada envio grava `ultimo_disparo` e `ultimo_status` (`ok (HTTP 200)`, `falhou (HTTP 500)` ou `falhou: <erro>`) no cadastro.

### Aplicações (além do CRUD)

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/filas/{id}/agentes` | `apps.filas` | Membros da fila |
| PUT | `/filas/{id}/agentes` | `apps.filas` + editar | Grava membros (ramais) |
| PUT | `/filas/{id}/cc-agentes` | `apps.filas` + editar | Grava agentes do call center na fila |
| GET | `/filas/{id}/situacao` | `apps.filas` | Situação da fila no Asterisk |
| GET | `/pesquisas/resultados` | `apps.pesquisas` | Resultados da pesquisa de satisfação |
| GET | `/pesquisas/respostas` | `apps.pesquisas` | Respostas |
| GET | `/pesquisas/respostas/csv` | `apps.pesquisas` + exportar | Respostas em CSV |
| GET | `/ura/{id}/opcoes` | `apps.ura` | Opções do menu da URA |
| PUT | `/ura/{id}/opcoes` | `apps.ura` + editar | Grava as opções |
| GET | `/conferencias/{id}/situacao` | `apps.conferencias` | Quem está na sala |
| GET | `/grupos-horario/{id}/faixas` | `apps.grupohorario` | Faixas de horário |
| PUT | `/grupos-horario/{id}/faixas` | `apps.grupohorario` + editar | Grava as faixas |
| GET | `/condicoes-horarias/{id}/agora` | `apps.condicoes` | Situação da condição agora |
| POST | `/condicoes-horarias/{id}/forcar` | `apps.condicoes` + editar | Força aberta, fechada ou automática (`modo`: `aberto`, `fechado`, `auto`) |
| GET | `/feriados/datas?ano=` | `apps.feriados` | Os dias de cada feriado no ano e o feriado de agora (`hoje`) |
| POST | `/feriados/nacionais` | `apps.feriados` + criar | Cadastra os feriados nacionais que faltam (sem áudio) |
| GET | `/destinos/contextos` | `admin.destinos` | Contextos disponíveis para destino personalizado |

### Agenda de contatos

Não usa o CRUD genérico: quem tem só a agenda pessoal vê os contatos da empresa mas mexe apenas nos seus (decidido por linha no controller).

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/contatos` | `pcu.contatos`. Também: `admin.contatos`, `fone.webrtc` | Lista |
| GET | `/contatos/{id}` | `pcu.contatos`. Também: `admin.contatos` | Obtém |
| POST | `/contatos` | `pcu.contatos` + criar. Também: `admin.contatos` | Cria |
| PUT | `/contatos/{id}` | `pcu.contatos` + editar. Também: `admin.contatos` | Altera |
| DELETE | `/contatos/{id}` | `pcu.contatos` + excluir. Também: `admin.contatos` | Remove |
| POST | `/contatos/{id}/foto` | `pcu.contatos` + editar. Também: `admin.contatos` | Envia foto |
| DELETE | `/contatos/{id}/foto` | `pcu.contatos` + editar. Também: `admin.contatos` | Remove foto |

### Provisionamento (console)

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/provisionamento` | `conn.provisionamento` | Configuração e registro de pedidos |
| PUT | `/provisionamento` | `conn.provisionamento` + editar | Grava a configuração |
| GET | `/provisionamento/{id}/previa` | `conn.provisionamento` + editar | Prévia do arquivo de um dispositivo |
| POST | `/provisionamento/linphone` | `conn.provisionamento` + editar | Gera o convite (QR Code) do Linphone |

### Console do Asterisk e configuração

| Método | Caminho | Permissão | O que faz |
|---|---|---|---|
| GET | `/cli/sugestoes` | `admin.cli` | Sugestões de comando |
| POST | `/cli` | `admin.cli` | Executa um comando do Asterisk |
| GET | `/config/estado` | sessão | Há configuração pendente? Quantos arquivos mudariam e a última aplicação |
| GET | `/config/arquivos` | `cfg.avancadas` | Arquivos gerados, personalizados, permissão e histórico |
| POST | `/config/gerar` | `cfg.avancadas` + editar | Gera os .conf sem recarregar; devolve `arquivos` e `problemas` |
| POST | `/config/aplicar` | `cfg.avancadas` + reiniciar | Gera e recarrega o Asterisk. Sempre 200, com `aplicado`, `etapas`, `saida`, `arquivos` e `problemas` |

---

## 8. Recursos CRUD genéricos

Cada linha abaixo tem as cinco rotas do CRUD genérico (seção 4):

```
GET    /api/<caminho>        listar   (módulo, ou "leitura também")
GET    /api/<caminho>/{id}   obter    (módulo, ou "escrita também")
POST   /api/<caminho>        criar    (módulo + criar)
PUT    /api/<caminho>/{id}   editar   (módulo + editar)
DELETE /api/<caminho>/{id}   excluir  (módulo + excluir)
```

Exceção: `POST /api/usuarios` é a rota própria de `Cadastros::criarUsuario` (com senha), com a mesma permissão.

"Destino" na coluna de leitura é a lista `ESCOLHEM_DESTINO`: as telas com seletor de destino (URA, filas, anúncios, condições, rotas de entrada e saída, siga-me, correio de voz etc.) podem listar sem ter o módulo dono.

| Caminho | Tabela | Módulo | Leitura também | Aplica no Asterisk |
|---|---|---|---|---|
| `ramais` | `ramais` | `conn.ramais` | destino, `fone.webrtc` | sim |
| `troncos` | `troncos` | `conn.troncos` | destino | sim |
| `filas` | `filas` | `apps.filas` | destino, `cc.tabulacoes`, `cc.agentes`, `cc.relatorios`, `cc.retornos` | sim |
| `estacionamentos` | `estacionamentos` | `apps.estacionamento` | destino | sim |
| `despertadores` | `despertadores` | `apps.despertar` | — | não |
| `grupos-horario` | `grupos_horario` | `apps.grupohorario` | destino | sim |
| `condicoes-horarias` | `condicoes_horarias` | `apps.condicoes` | destino | sim |
| `feriados` | `feriados` | `apps.feriados` | — | sim |
| `anuncios` | `anuncios` | `apps.anuncios` | destino | sim |
| `caixas-postais` | `caixas_postais` | `apps.caixaspostais` | destino | sim |
| `conferencias` | `conferencias` | `apps.conferencias` | destino | sim |
| `pesquisas` | `pesquisas` | `apps.pesquisas` | destino | sim |
| `rotas-entrada` | `rotas_entrada` | `conn.rotasentrada` | — | sim |
| `rotas-saida` | `rotas_saida` (+ `padroes` na mesma gravação) | `conn.rotassaida` | — | sim |
| `ura` | `ura` | `apps.ura` | destino | sim |
| `ura-opcoes` | `ura_opcoes` | `apps.ura` | — | sim |
| `grupos-toque` | `grupos_toque` | `apps.grupostoque` | destino | sim |
| `usuarios` | `usuarios` | `admin.usuarios` | — | não |
| `destinos-personalizados` | `destinos_personalizados` | `admin.destinos` | destino | sim |
| `dispositivos` | `dispositivos` | `conn.provisionamento` | — | não |
| `grupos-paging` | `grupos_paging` | `apps.paging` | destino | sim |
| `disa` | `disa` | `apps.disa` | destino | sim |
| `voicemail-geral` | `voicemail_geral` | `cfg.correiovoz` | — | sim |
| `fax-geral` | `fax_geral` | `cfg.fax` | — | sim |
| `pin-sets` | `pin_sets` | `cfg.pinsets` | destino | sim |
| `tarifas` | `tarifas` | `telium.tarifacao` | `cfg.tarifas` (também grava) | não |
| `codigos-recurso` | `codigos_recurso` | `admin.codigos` | — | sim |
| `lista-negra` | `lista_negra` | `admin.listanegra` | — | sim |
| `lista-permitida` | `lista_permitida` | `admin.allowlist` | — | sim |
| `backup-rotinas` | `backup_rotinas` | `admin.backup` | — | não |
| `cc-pausas` | `cc_pausas_motivos` | `cc.pausas` | `cc.agente`, `cc.supervisor` | sim |
| `cc-tabulacoes` | `cc_tabulacoes` | `cc.tabulacoes` | `cc.agente`, `cc.supervisor` | não |
| `integracoes` | `integracoes` | `telium.integracoes` | — | não |

Colunas graváveis, regras de validação, colunas ocultas, filtros e busca de cada recurso estão na declaração dele em `api/public/index.php`. Alguns pontos que costumam surpreender:

- **ramais:** `senha_sip` (mínimo 12 caracteres), `vm_senha` e `pin` nunca voltam. Marcar `webrtc` força DTLS, ICE, AVPF, rtcp-mux, transporte `wss`, `max_contatos = 1` e codecs do navegador. Quem lê por referência recebe só `id`, `numero`, `nome`, `setor`, `ativo`, `webrtc`.
- **troncos:** `tipo` só aceita `pjsip`; `senha` nunca volta.
- **usuarios:** `senha_hash` e `totp_secret` nunca voltam; impedimentos (último administrador, própria conta, ramal de outra pessoa) em `Usuarios::conferir`.
- **codigos-recurso, cc-pausas, caixas-postais:** o código discado não pode colidir com outro (igual ou começo de outro): 409 com `campo: codigo`.
- **cc-pausas:** os motivos da central (`sistema = 1`) não podem ser excluídos, renomeados nem ganhar código.
- **rotas-saida:** os padrões de discagem vão no campo `padroes` do corpo e voltam na resposta; gravados na mesma transação.
- **conferencias, disa, pin-sets, caixas-postais, integracoes:** PINs, senhas e segredos nunca voltam.
- **codigos-recurso:** `codigo` obrigatório no formato `^[*#][0-9*#]{1,6}$` (422). Os pares de fábrica (`*8` com `*80`/`*82`/`*85`/`*86`/`*87`/`*88`, `*55` com `*555`, `*77` com `*7777`) não são recusados como colisão.
- **grupos-toque:** `estrategia` = `ringall` (todos juntos) ou `linear` (um de cada vez, na ordem da lista); `tempo_toque` de 5 a 300 segundos; destino de falha em `destino_falha_tipo`/`destino_falha_valor`.
- **despertadores:** `tentativas` de 1 a 10 e `intervalo` de 60 a 3600 segundos. `ultimo_estado` é gravado pelo script `telium-despertador` do servidor: vazio ao começar a ligar, `atendido` ou `nao_atendeu` depois das tentativas (a tela também sabe mostrar `falha`, mas o script de hoje não grava esse valor).
- **conferencias:** PIN de administrador igual ao PIN de entrada: 409 com `campo: pin_admin`. `esperar_admin` ligado sem PIN de administrador: 409. A conferência considera o valor que vai ficar (PIN em branco na edição mantém o gravado).
- **fax-geral:** `minimo_bits` e `maximo_bits` só aceitam 2400, 4800, 7200, 9600, 12000 ou 14400; `email_destino` em formato de e-mail.
- **pin-sets:** a listagem não traz os PINs, mas traz `pins_qtd`, a quantidade, recontada a cada gravação do campo `pins`.
- **integracoes:** só webhook tem efeito. Na criação o `tipo` é sempre `webhook` (o campo não é gravável); os tipos antigos (`api_key`, `syslog`, `app`) continuam só na listagem. `url` obrigatória, começando com `http://` ou `https://`; `eventos` com `chamada.tocando`, `chamada.atendida` e/ou `chamada.encerrada`, separados por vírgula, `;` ou espaço (em branco, todos). A listagem traz `ultimo_disparo` e `ultimo_status`.
