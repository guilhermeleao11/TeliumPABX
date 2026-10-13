# Desenvolvimento — Telium PABX

Guia de quem vai manter o código: como subir a bancada, validar, escrever migração, acrescentar cadastro, tela e código de recurso, e o que conferir antes de entregar.

Antes daqui, leia [31-Arquitetura.md](31-Arquitetura.md). A referência das rotas está em [32-API-Referencia.md](32-API-Referencia.md).

---

## Sumário

1. [Bancada de desenvolvimento](#1-bancada-de-desenvolvimento)
2. [Lint sem PHP nem Node na máquina](#2-lint-sem-php-nem-node-na-máquina)
3. [Migrações de banco](#3-migrações-de-banco)
4. [Testar uma migração num banco descartável](#4-testar-uma-migração-num-banco-descartável)
5. [Bateria de testes](#5-bateria-de-testes)
6. [Cadastro novo com o CRUD genérico (Recurso)](#6-cadastro-novo-com-o-crud-genérico-recurso)
7. [Rota que não cabe no CRUD](#7-rota-que-não-cabe-no-crud)
8. [Tela nova no console](#8-tela-nova-no-console)
9. [Código de recurso novo](#9-código-de-recurso-novo)
10. [Convenções do código](#10-convenções-do-código)
11. [Git e versão](#11-git-e-versão)
12. [Checklist de entrega](#12-checklist-de-entrega)

---

## 1. Bancada de desenvolvimento

`infra/dev/` sobe uma central inteira em contêineres Docker: MariaDB, a API no servidor embutido do PHP e um **Asterisk de verdade**, carregando os mesmos `.conf` que o Ansible publica no cliente.

| Arquivo | Para que serve |
|---|---|
| `infra/dev/subir.sh` | Sobe tudo. Com `--limpar`, apaga e recria o banco e o diretório de trabalho |
| `infra/dev/derrubar.sh` | Derruba os contêineres. Com `--tudo`, apaga também o diretório de trabalho |
| `infra/dev/router-dev.php` | Roteador do `php -S`: `/api`, `/prov/` e `/p/` vão para `api/public/index.php`; `/uploads/contatos/` sai do diretório de fotos; o resto é arquivo estático ou `index.html` |
| `infra/dev/fone.py` | Telefone SIP de bancada, sem mídia: registra, atende e mostra cabeçalhos (`fone.py <ramal> <senha> <host> <porta> [--mostrar Call-Info,Alert-Info]`) |
| `infra/dev/LEIAME.md` | Endereços, usuário de entrada e o que a bancada não prova |

```sh
infra/dev/subir.sh             # sobe tudo
infra/dev/subir.sh --limpar    # recria o banco do zero
infra/dev/derrubar.sh          # derruba
infra/dev/derrubar.sh --tudo   # derruba e apaga o diretório de trabalho
```

O que o `subir.sh` faz, na ordem:

1. Sobe o MariaDB (contêiner `v-db`) e aplica todos os `infra/sql/*.sql`.
2. Escreve `api/.env` de bancada e sobe a API (contêiner `v-web`, imagem `php:8.4-cli`) em `http://127.0.0.1:18110/`.
3. Renderiza os templates `infra/ansible/roles/asterisk/templates/*.conf.j2` com valores de bancada e cria os `*_custom.conf` vazios.
4. Roda `php bin/telium gerar-config`.
5. Sobe o Asterisk (contêiner `v-ast`, na rede do `v-web`), instala o driver ODBC para CDR/CEL e acerta o fuso.
6. Instala áudios: os pacotes oficiais (em cache em `~/.cache/telium-pabx/sons`), o `pt_BR.tar.xz` e os de `telium-cc`.

Comandos úteis:

| Para | Comando |
|---|---|
| Console do Asterisk | `docker exec -it v-ast asterisk -rvvv` |
| Bateria de testes | `docker exec v-web sh -c 'cd /w/api && php bin/telium testar'` |
| Aplicar configuração | `docker exec v-web sh -c 'cd /w/api && php bin/telium aplicar-config'` |
| Banco | `docker exec -it v-db mariadb -uroot -p telium` (senha de bancada no `subir.sh`) |
| Reiniciar só o Asterisk | `docker exec v-ast sh -c 'pkill -f "asterisk -f"; rm -f /var/run/asterisk/asterisk.ctl'` e depois `docker exec -d v-ast asterisk -f -vvv` |

Variáveis: `TELIUM_DEV_DIR` (padrão `/tmp/telium-dev`), `TELIUM_DEV_PORTA` (padrão `18110`), `TELIUM_DEV_SONS`.

> **Atenção:** o `subir.sh` sobrescreve `api/.env`. Ele não vai para o Git (está no `.gitignore`), mas não guarde nada seu ali.

Diferenças de propósito em relação ao servidor: o Asterisk roda como `root`, `ASTERISK_GRUPO=root`, senhas de bancada e `APP_DEBUG=true`. A bancada **não prova** áudio de verdade (em parte), mídia WebRTC nem tronco de operadora.

> O `router.php` da raiz do repositório está vazio. Use o `infra/dev/router-dev.php`.

---

## 2. Lint sem PHP nem Node na máquina

Não precisa instalar nada: use as imagens oficiais.

```sh
# PHP: um arquivo
docker run --rm -v "$PWD":/w -w /w php:8.4-cli php -l api/src/Http/Recurso.php

# PHP: tudo (menos vendor)
docker run --rm -v "$PWD":/w -w /w php:8.4-cli sh -c \
  'find api -name "*.php" -not -path "api/vendor/*" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors"; php -l api/bin/telium'

# JavaScript do console
docker run --rm -v "$PWD":/w -w /w node:22-alpine sh -c \
  'for f in assets/js/*.js; do node --check "$f" || exit 1; done'
```

O `node --check` só confere sintaxe. Os arquivos do console são scripts globais (sem módulos); referência a função de outro arquivo não é detectada aqui, só no navegador.

---

## 3. Migrações de banco

### Onde e como rodam

- Ficam em `infra/sql/NN-nome.sql`, numeradas (a última hoje é `45-click2call.sql`). O próximo número é o seguinte.
- **Todas rodam, sempre, em ordem**, a cada execução do playbook (role `mariadb`, tarefa "Aplicar schema") e no `php bin/telium migrar`. Não existe tabela de "migrações já aplicadas".
- `infra/sql/opcional/` (`limpar.sql`, `cenario-teste.sql`) não roda sozinho: só com `-e limpar_banco=true` ou equivalentes no playbook.

Por isso **toda migração tem de ser idempotente**: rodar duas, dez vezes, dá o mesmo banco.

### Regras

| Situação | Como escrever |
|---|---|
| Tabela nova | `CREATE TABLE IF NOT EXISTS ...` |
| Coluna nova | `ALTER TABLE t ADD COLUMN IF NOT EXISTS ...` |
| Índice novo | `ADD UNIQUE KEY IF NOT EXISTS`, `ADD INDEX IF NOT EXISTS`; para trocar, `DROP INDEX IF EXISTS` antes |
| Dado de catálogo | `INSERT ... ON DUPLICATE KEY UPDATE` só das colunas que são do sistema (nunca a que o cliente personaliza, como o `codigo` de um código de recurso) ou `INSERT IGNORE` |
| Trocar um valor de fábrica | `UPDATE ... WHERE <coluna> = '<valor antigo>'`, para não desfazer o que o cliente mudou. Se pode bater em chave única, `UPDATE IGNORE` (exemplo: `42-callcenter-agente-e-pausas.sql`) |
| Permissão para um módulo novo | `INSERT IGNORE INTO perfil_modulos (perfil_id, modulo) SELECT id, '<modulo>' FROM perfis WHERE chave = '...'` |
| Cabeçalho | Comentário explicando o porquê e `SET NAMES utf8mb4;` |

**Nada de marcar configuração pendente** (`sistema.config_pendente`) numa migração. O playbook já gera e aplica a configuração logo depois (`gerar-config` e `aplicar-config` no role `api`), e o `migrar` manual é seguido do Aplicar pelo próprio técnico.

### O código precisa conhecer o esquema

`api/src/Suporte/Esquema.php` lista as tabelas (`TABELAS`) e as colunas acrescentadas depois (`COLUNAS`) que o código exige. É o que faz o `/api/health` e o Aplicar dizerem "rode a migração" em vez de estourar com `SQLSTATE 42S02`. **Tabela ou coluna nova usada pelo código entra lá.**

Coluna nova no `cdr` também exige o `module reload cdr_adaptive_odbc.so`, que já está no Aplicar.

---

## 4. Testar uma migração num banco descartável

Antes de entregar, aplique **todos** os scripts **duas vezes** num MariaDB limpo. A segunda passada é a que prova a idempotência.

```sh
docker run -d --name t-db -e MARIADB_ROOT_PASSWORD=teste -e MARIADB_DATABASE=telium mariadb:11
until docker exec t-db mariadb -uroot -pteste -e 'SELECT 1' >/dev/null 2>&1; do sleep 1; done

for passada in 1 2; do
  echo "== passada $passada"
  for f in infra/sql/*.sql; do
    docker exec -i t-db mariadb -uroot -pteste telium < "$f" || { echo "FALHOU: $f"; break; }
  done
done

docker rm -f t-db
```

Para provar a migração num banco **com dados** (o caso do cliente), rode-a na bancada (`infra/dev/subir.sh` sem `--limpar` aplica todos os scripts de novo sobre o banco que já existe) e depois a bateria de testes.

> A bancada usa `mariadb:12.3.3`; o teste descartável acima usa `mariadb:11`. Vale testar nas duas quando a migração usar sintaxe menos comum.

---

## 5. Bateria de testes

- Código: `api/src/Suporte/Testes.php`. Comando: `php bin/telium testar` (sai com código 1 se algo falhar).
- O playbook roda a bateria no fim do role `api`; falha interrompe a instalação.
- Sem banco, roda só os grupos que não dependem dele. Sem AMI, pula "Aplicar no Asterisk" e "Portas dos transportes".
- Cada caso nasceu de um defeito real. Corrigiu um defeito? Acrescente o caso que o pegaria.

Como escrever: crie um método privado e registre-o em `rodar()` com `$this->grupo('Nome do grupo', $this->metodo(...))`. Dentro, use `$this->ok(condicao, 'descrição do que deve valer')`.

Grupos que vale conhecer:

| Grupo | O que confere |
|---|---|
| Portas da API (`rotas()`) | Monta o app Slim (constante `TELIUM_SEM_RUN`), percorre **todas** as rotas e exige 401 sem sessão, exceto as públicas. Rota nova sem `MwAutenticacao` falha aqui |
| Permissão por referência | O `Permissao` com a lista `tambem` |
| Geração de dialplan, Destinos de chamada | Saída dos geradores |
| Aplicar no Asterisk | Os comandos de `Aplicador::comandosDeRecarga()` existem nesta versão do Asterisk |
| Banco e esquema | `Esquema::conferir()` |

---

## 6. Cadastro novo com o CRUD genérico (Recurso)

A maioria dos cadastros não tem controller: é uma entrada na tabela `$recursos` de `api/public/index.php`. Para cada entrada, o laço "CRUD genérico" no fim do arquivo cria:

| Método e caminho | Método do Recurso | Permissão |
|---|---|---|
| `GET /api/<caminho>` | `listar` | módulo, ou um dos módulos em `referencia` |
| `GET /api/<caminho>/{id}` | `obter` | módulo, ou um dos módulos em `escrita` |
| `POST /api/<caminho>` | `criar` | módulo + ação `criar` (exceto `usuarios`, que tem criação própria) |
| `PUT /api/<caminho>/{id}` | `atualizar` | módulo + ação `editar` |
| `DELETE /api/<caminho>/{id}` | `remover` | módulo + ação `excluir` |

### A entrada em `$recursos`

```php
'caixas-postais' => [
    'modulo' => 'apps.caixaspostais',        // módulo dono (permissão)
    'referencia' => ESCOLHEM_DESTINO,        // quem mais pode LER a lista
    // 'escrita' => ['cfg.tarifas'],         // outra tela que também grava aqui
    'recurso' => new Recurso(
        tabela: 'caixas_postais',
        colunas: ['numero', 'nome', 'senha', ...],
        ...
    ),
],
```

### Parâmetros do `Recurso` (construtor em `api/src/Http/Recurso.php`)

| Parâmetro | Para que serve |
|---|---|
| `tabela` | Tabela do banco. Precisa ter `id` |
| `colunas` | As colunas que o cliente pode gravar. O que não está aqui é descartado em silêncio |
| `ordem` | `ORDER BY` da listagem (padrão `id`) |
| `busca` | Colunas varridas por `?q=` (LIKE) |
| `filtros` | Colunas filtráveis por igualdade (`?coluna=valor`) |
| `ocultas` | Nunca saem na resposta nem na auditoria (senhas, PINs, segredos). Na edição, valor em branco quer dizer "manter"; `null` quer dizer "remover", exceto se a regra tem `min` ou `obrigatorio` |
| `afetaAsterisk` | `true`: ao gravar, marca `config_pendente` e passa a recusar `${` e `$[` em qualquer campo (injeção de expressão no dialplan) |
| `modulo` | Nome usado na auditoria (padrão: o nome da tabela) |
| `regras` | Validação por campo (tabela abaixo) |
| `unicas` | Colunas com índice único, para traduzir o erro 1062 em 409 apontando o campo |
| `normalizar` | `fn(array $dados, array $atual): array`. Ajuste final antes de gravar (depois da validação e da conferência). Exemplo: ramal WebRTC liga DTLS, ICE, AVPF e corta os codecs |
| `conferir` | `fn(string $acao, array $dados, array $atual, array $usuario): ?array`. A última palavra: `$acao` é `criar`, `editar` ou `excluir`. Devolve `null` para permitir ou `['mensagem' => ..., 'campo' => ..., 'codigo' => ...]` (código padrão 409). É onde mora o que olha fora da linha: último administrador, colisão de código, número que já é de ramal |
| `colunasReferencia` | Colunas devolvidas a quem lê só por referência (seletor de destino). Vazio: todas |
| `referenciaCompleta` | Módulos que, mesmo entrando por referência, recebem todas as colunas |
| `modelos` | Campos que aceitam as variáveis `${VM_...}` do Asterisk (assunto e corpo do e-mail do correio de voz) |
| `lista` | Lista em tabela própria editada no mesmo formulário: `['campo' => ..., 'ler' => ..., 'preparar' => ..., 'gravar' => ...]`. Grava na mesma transação da linha. Exemplo: padrões da rota de saída (`RotasSaida`) |

### Regras de validação (`regras`)

| Chave | Efeito |
|---|---|
| `rotulo` | Nome do campo nas mensagens |
| `obrigatorio` | Exigido na criação; não pode ficar vazio na edição |
| `min`, `max` | Tamanho em caracteres |
| `email` | Formato de e-mail |
| `em` | Lista de valores aceitos |
| `padrao` | Regex |
| `mensagem` | Mensagem própria para `min`, `em` e `padrao` |

Campo ausente na edição não é validado ("não mexi"). Campo vazio só é erro se for obrigatório.

### Comportamentos automáticos

- `""` vindo de um `<select>` ou campo numérico vira `NULL` (coluna anulável) ou o padrão da coluna; em coluna de texto fica `""`.
- Toda gravação vai para a auditoria (`Telium\Dominio\Auditoria`), sem as colunas ocultas.
- Erros de banco viram mensagens: 1364/1048 (campo obrigatório) → 422, 1062 (duplicado) → 409, 1452 (vínculo inexistente) → 422, 1451 (registro em uso) → 409.
- Listagem: `?limite=` (1 a 500, padrão 200) e `?pagina=`.

### Passo a passo de um cadastro novo

1. Migração com a tabela (seção 3) e a permissão do módulo para os perfis.
2. Tabela em `Esquema::TABELAS`.
3. Entrada em `$recursos` com `regras`, `unicas` e `ocultas` pensadas.
4. Se o cadastro vira configuração do Asterisk: `afetaAsterisk: true` e o gerador correspondente em `api/src/Gerador/` (e o comando de recarga no `Aplicador`, se for um módulo novo do Asterisk).
5. Se é destino de chamada: acrescente o módulo em `ESCOLHEM_DESTINO` e o tipo em `Destino` e `Conferencia`.
6. A tela (seção 8).
7. Teste na bateria.

---

## 7. Rota que não cabe no CRUD

Quando a regra é por linha, há efeito colateral (AMI, arquivo) ou a resposta não é uma tabela, crie um controller em `api/src/Http/Controllers/` e declare a rota em `api/public/index.php`, **dentro** do grupo autenticado:

```php
$g->get('/minha-area/{id}/coisa', [MeuController::class, 'coisa'])
  ->add(new Permissao('apps.minhaarea'));
$g->post('/minha-area/{id}/coisa', [MeuController::class, 'salvar'])
  ->add(new Permissao('apps.minhaarea', 'editar'));
```

- Responda sempre com `Resposta::json()` ou `Resposta::erro()` (`api/src/Suporte/Resposta.php`). Arquivos grandes: `Resposta::arquivo()`, que faz fluxo e Range.
- Rotas com segmento fixo que colidiriam com `/{id}` do CRUD (como `/ramais/csv` e `/pesquisas/resultados`) são declaradas **antes** do laço do CRUD.
- Rota pública (fora do grupo) só com motivo forte. Se for o caso, acrescente-a na lista `$publicas` do teste `rotas()`; se não, o teste reprova.
- Regras de negócio vão para `api/src/Dominio/`; o controller só lê o pedido e monta a resposta.
- Grave na auditoria o que muda estado (`Auditoria::registrar(...)`).

---

## 8. Tela nova no console

### 1. Entrada no menu

Em `assets/js/data.js`, acrescente o item no grupo certo de `MENU`. O `id` é a chave do módulo e da permissão:

```js
{ id: 'apps.minhaarea', label: 'Minha Área', icon: 'grid' }
```

Os ícones disponíveis estão em `assets/js/icons.js` (`ICONS`). O menu é filtrado por `Auth.can(id)`; o roteador (`App.route()` em `app.js`) recusa a tela se o perfil não tem o módulo.

### 2. A página

Toda tela é `PAGES['<id>'] = { async render(ctx) { ... return html }, mount(ctx) { ... } }`. O `ctx` traz `sess` (a sessão), `item`, `group` e `can(acao)` (as ações do perfil). Coloque a tela no arquivo da área (`pages.js`, `pages2.js`, `callcenter.js`...); um arquivo novo precisa de um `<script>` em `app.html`, antes do `app.js`.

Para um cadastro simples, use `paginaCrud(cfg)` (`assets/js/pages.js`):

```js
PAGES['apps.minhaarea'] = paginaCrud({
  recurso: 'minha-area',                 // caminho do Recurso na API
  titulo: 'Minha Área',
  sub: 'Para que serve esta tela.',
  ico: 'grid', plural: 'itens', rotuloNovo: 'Novo item',
  textoBusca: i => `${i.nome} ${i.numero}`,
  colunas: [
    { label: 'Nome', render: i => `<b>${esc(i.nome)}</b>` }
  ],
  campos: (i, ctx, pagina) => [
    { campo: 'nome', label: 'Nome', obrigatorio: true },
    { campo: 'ativo', label: 'Ativo', tipo: 'switch', padrao: 1 }
  ]
});
```

Opções e ganchos do `paginaCrud`:

| Chave | Para que serve |
|---|---|
| `recurso`, `params` | Caminho na API e parâmetros do GET da lista |
| `titulo`, `sub`, `ico`, `plural`, `rotuloNovo`, `vazioTitulo`, `vazioTexto`, `placeholderBusca` | Textos e ícone |
| `colunas` | `[{ label, render(item, ctx, pagina), classe, thClasse }]` |
| `campos(item, ctx, pagina)` | Campos do formulário. Tipos: texto (padrão), `number`, `switch`, `select` (com `opcoes: [{valor, rotulo}]`), `textarea`, `destino`, `nota`. Também `aba`, `ajuda`, `padrao`, `largura: 'full'`, `obrigatorio`, `padraoValido`/`mensagemPadrao`, `limpavel` (segredo com "remover") |
| `textoBusca(item)` | Texto usado no filtro da lista |
| `aoCarregar(pagina, ctx)` | Depois do GET da lista, antes de desenhar: carregar dados auxiliares (ex.: a lista de filas) |
| `antesDaLista(pagina, ctx)` | HTML entre o cabeçalho e a tabela |
| `aoMontar(pagina, ctx)` | Depois de montar o DOM: ligar eventos extras |
| `aoAbrirFormulario(drawer, item, pagina)` | Ao abrir a gaveta de edição |
| `acoesExtra(ctx)`, `acoesLinha(item, ctx)`, `filtrosExtra(itens)` | Botões e filtros adicionais |
| `tituloNovo`, `tituloEditar(item)`, `subFormulario`, `tituloExcluir(item)`, `textoExcluir(item)` | Textos da gaveta e da confirmação |
| `somenteLeitura` | Sem criar, editar nem excluir |

O `paginaCrud` já trata: botões conforme `ctx.can('criar'|'editar'|'excluir')`, confirmação de exclusão, erro com `campo` marcado no formulário e `App.route()` depois de gravar.

### 3. Componentes e utilitários

| Nome | Arquivo | Uso |
|---|---|---|
| `Api.get(caminho, params)`, `Api.post`, `Api.put`, `Api.delete` | `api.js` | Chamadas à API. Erros viram `ErroApi` com `message`, `status` e `detalhe` (o JSON de erro) |
| `Api.salvarArquivo`, `Api.baixar`, `Api.upload` | `api.js` | Downloads com autenticação e upload multipart |
| `Auth.can(modulo)`, `Auth.cap(acao)`, `Auth.sessao` | `auth.js` | Permissões e sessão (só para esconder; o servidor decide) |
| `Drawer.open({ titulo, sub, corpo, rodape, wide, aoAbrir, aoFechar })`, `Drawer.close()` | `ui.js` | Gaveta lateral. `titulo` e `sub` são escapados; HTML de propósito vai em `subHtml` |
| `await Modal.confirm({ titulo, texto, ok, cancelar, tone, ico })` | `ui.js` | Confirmação; devolve `true`/`false` |
| `toast(mensagem, 'ok' or 'warn' or 'err')` | `app.js` | Aviso rápido |
| `esc(texto)` | `pages.js` | Escapar todo texto vindo do banco antes de pôr no HTML |
| `pageHead`, `blocoErro`, `vazio` | `pages.js` | Cabeçalho, erro, estado vazio |
| `icon(nome, classe)` | `icons.js` | Ícone SVG |

Regras do console:

- **Todo texto do banco passa por `esc()`.** Nome de contato, de fila, de tronco é texto digitado por alguém.
- **Nada de dado inventado:** base vazia mostra estado vazio.
- Telas do Meu Painel (`pcu.*`) nunca mandam o número do ramal; o servidor tira da sessão.

### 4. Permissão

O módulo novo precisa existir para algum perfil: migração com `INSERT IGNORE INTO perfil_modulos` (o administrador já tem `*`). Os perfis personalizados recebem pela tela **Perfis e Permissões**, que lê o `MENU`.

---

## 9. Código de recurso novo

Os códigos discados (`*xx`) vivem em `codigos_recurso`. O **código** é configurável; **o que ele faz** é fixo, escolhido pela `chave`, em `GeradorRecursos::passos()` (`api/src/Gerador/GeradorRecursos.php`).

1. **Migração:** insira o código no catálogo com uma `chave` nova. Siga o padrão de `07-codigos-recurso.sql`: `INSERT ... ON DUPLICATE KEY UPDATE nome, descricao, categoria, ordem, tipo, argumento`, **sem** atualizar `codigo`, para não desfazer o que o cliente trocou.
2. **Gerador:** acrescente o caso da chave no `match` de `passos()`. Sem argumento, a extensão é o próprio `$codigo`; com argumento (ramal, número, fila), use o padrão `$comArg` (`_<codigo>X!`) e leia o resto com `$arg` (`${EXTEN:n}`). Chave sem caso cai no `default => null` e não é escrita.
3. **Durante a chamada** (featuremap): use `tipo = 'featuremap'` e o mapeamento em `GeradorRecursos::FEATUREMAP`; vai para o `features.conf`.
4. **Colisão:** escolha um código livre. Códigos de recurso, motivos de pausa do call center e caixas postais dividem o mesmo espaço em `telium-recursos`, e `CodigosDiscagem::colisao()` recusa código igual a outro **ou começo de outro** (`*8` e `*85` não convivem: o telefone disca ao reconhecer o primeiro). A conferência roda no `conferir` dos recursos `codigos-recurso`, `cc-pausas` e `caixas-postais`; a migração não passa por ela, então confira à mão contra `07-codigos-recurso.sql`, `40-condicao-codigo.sql` e `42-callcenter-agente-e-pausas.sql`.
5. Um teste em `Testes.php` para o dialplan gerado.
6. Documente o código em [15-Codigos-de-Recurso.md](15-Codigos-de-Recurso.md).

O formato aceito para códigos de pausa e caixa é `CodigosDiscagem::FORMATO` (`*` ou `#` e de 1 a 6 dígitos).

---

## 10. Convenções do código

- **Português em tudo:** nomes de classe, método, variável, tabela, coluna, rota e mensagens. Termos técnicos consagrados ficam como são (endpoint, dialplan, token).
- **Comentários explicam o porquê**, não o quê. O padrão do projeto é contar o defeito que a linha evita ("sem isto, o ramal sumia da central e o console dizia aplicado"). Mantenha esse padrão: é o que impede alguém de "simplificar" e reabrir o defeito.
- **Mensagens para quem usa:** dizem o que fazer, não só o que deu errado ("Rode php bin/telium migrar no servidor").
- PHP com `declare(strict_types=1)`, classes `final`, namespace `Telium\...` (autoload PSR-4 pelo Composer).
- SQL sempre com parâmetros (`Bd::todos`, `Bd::um`, `Bd::valor`, `Bd::executar`).
- Valor que vai para o AMI ou para o dialplan é limpo antes (exemplo: `ClickToCall::numeroLimpo`).
- JavaScript sem build nem framework, sem dependência de CDN (a central roda na rede do cliente).

---

## 11. Git e versão

| Remoto | Endereço | Papel |
|---|---|---|
| `origin` | `git@gitlab.telium.com.br:Voip/teliumpabx.git` | Repositório oficial (GitLab da Telium) |
| `github` | `git@github.com:guilhermeleao11/TeliumPABX.git` | Espelho |

- Branch principal: `main`.
- Mensagens de commit em português, no estilo do histórico: frase curta dizendo o efeito para quem usa ("Click to call para outros sistemas, com chave de API").
- Segredos nunca entram: `api/.env`, `vault.yml`, inventário, `servidor.yml`, `*.pem` e `*.key` estão no `.gitignore`.

**Versão:** `api/src/Suporte/Versao.php` (`Versao::NUMERO`, hoje `1.0.1`). É a única fonte: aparece em `/api/health`, `/api/me` (console) e no `bin/telium`. Ao mudar de versão, confira também o texto fixo `Telium PABX 1.0.1` no resumo de instalação de `infra/ansible/site.yml`, que não lê essa constante.

---

## 12. Checklist de entrega

- [ ] Lint de PHP e JS limpo (seção 2).
- [ ] Migração nova idempotente, testada **duas vezes** num banco limpo e uma vez sobre um banco com dados (seção 4).
- [ ] Tabelas e colunas novas em `Esquema.php`.
- [ ] `php bin/telium testar` sem falha na bancada; caso novo para o defeito corrigido.
- [ ] Rota nova dentro do grupo autenticado e com `Permissao`.
- [ ] Mudou cadastro que vira configuração? Gerar e aplicar na bancada, e conferir no Asterisk (`pjsip show endpoints`, `dialplan show <contexto>`).
- [ ] Texto do banco escapado no console (`esc`).
- [ ] Nota na documentação de atualização (`documentacao/02-ATUALIZACAO.md` e [02-Atualizacao.md](02-Atualizacao.md)) quando a entrega exige algo além do playbook: reinício, passo manual, mudança de comportamento.
- [ ] Wiki atualizada na página da área afetada.
