# Telium PABX 1.0.1

Central telefônica completa: Asterisk 22 com PJSIP, console web em HTML, CSS e
JavaScript puro (sem build), API em PHP 8.4 e provisionamento por Ansible em
Debian 13. Toda a configuração do Asterisk é **gerada a partir do banco** —
ninguém edita `.conf` à mão.

**Está em teste.** O roteiro de validação, módulo a módulo, está em
[docs/TESTE-FUNCIONAL.md](docs/TESTE-FUNCIONAL.md); o que ainda não faz
nada está em [docs/PENDENCIAS.md](docs/PENDENCIAS.md).

## Instalar

Uma execução instala a central inteira numa VM Debian 13 — banco, PHP,
nginx com TLS, Asterisk compilado, TURN, backup, firewall e o console:

```bash
sudo apt update && sudo apt install -y ansible git
git clone https://github.com/guilhermeleao11/TeliumPABX.git /opt/telium-src
cd /opt/telium-src/infra/ansible
sudo ansible-playbook site.yml
```

O guia completo — variáveis, senhas, atualização, diagnóstico e o cenário
de teste — está em **[infra/README.md](infra/README.md)**.

Para mexer só no front-end, sem instalar nada:

```bash
python3 -m http.server 8080   # e acesse http://localhost:8080
```

### Acesso

| Usuário | Senha |
|---|---|
| `admin` | `T3l1um_@2024_@aD1m` |

É a única conta que nasce com a instalação, e essa senha é restaurada a cada
provisionamento — é a garantia de que sempre há como entrar. As demais contas
são criadas pelo console, em *Administrador → Gerenciador de Usuários*, e essas
sim mantêm a senha que você definir.

## Estrutura do repositório

```
index.html · app.html · assets/     front-end (protótipo funcional)
api/                                API PHP 8.4 + Slim 4
infra/ansible/                      provisionamento da VM (Debian 13)
infra/sql/                          schema e seed do MariaDB
docs/                               arquitetura e validação
```

## Estrutura do front-end

```
index.html                  tela de login
app.html                    shell do console (sidebar + header + conteúdo)
docs/CHECKLIST-FRONTEND.md  validação do que um PABX moderno precisa ter + contrato para o backend
assets/css/base.css         tokens de design (marca Telium), reset e componentes
assets/css/login.css        tela de login
assets/css/app.css          sidebar recolhível, header, gráficos, responsividade
assets/css/components.css   softphone, gaveta, modal, paleta de comandos, player, URA, skeleton
assets/img/                 favicon vetorial e logotipos oficiais da Telium
assets/js/icons.js          ícones SVG + marca vetorial (TELIUM_MARK)
assets/js/data.js           menu, perfis, usuários, empresa e massa de dados demo
assets/js/auth.js           sessão, permissões e tema (com fallback para file://)
assets/js/ui.js             softphone, drawer, modal, paleta Ctrl+K, player, modo TV
assets/js/pages.js          telas: painel, ramais, troncos, filas, CDR, usuários, permissões
assets/js/pages2.js         telas: rotas, URA, gravações, tarifação, provisionamento,
                            firewall, backup, integrações, PCU e dados da empresa
assets/js/app.js            sidebar, roteador por hash, cabeçalho, atalhos e notificações
```

## Identidade visual

Cores extraídas do logotipo oficial (telium.com.br): azul `#2958a5`, ciano `#1997cd`
e azul profundo `#0c629f`. A marca é vetorial (`TELIUM_MARK` em `icons.js`), então
fica nítida em qualquer tamanho — inclusive no favicon.

## Recursos

- **Menu lateral recolhível** — clique em *Recolher menu* ou tecle `[`. No modo estreito
  (rail) cada grupo abre um *flyout* ao passar o mouse. O estado fica salvo no navegador.
- **Permissões por perfil** — o menu é montado a partir de `Auth.menuFor(sessão)`; abrir uma
  URL sem permissão mostra a tela de *Acesso não autorizado*. As ações (criar/editar/excluir/
  exportar) desabilitam botões conforme `ROLES[perfil].caps`.
- **Tema claro e escuro** com tokens CSS; a preferência é lembrada.
- **Busca de módulos** na sidebar e no header (tecle `/` para focar).
- **Responsivo** — em telas ≤ 900 px a sidebar vira gaveta com sobreposição.
- **Softphone WebRTC** (botão flutuante ou tecla `D`) — discador, DTMF, mudo, espera,
  transferência com consulta e histórico. Click-to-call em ramais, contatos e correio de voz.
- **Paleta de comandos** `Ctrl/⌘ + K` — módulos, ramais, contatos e ações.
- **Modo TV** no wallboard, para telão da operação.
- Gavetas de formulário com abas, modais de confirmação, skeleton de carregamento e toasts.

## Como adicionar um módulo

1. Em `assets/js/data.js`, inclua o item no grupo desejado de `MENU`:
   ```js
   { id: 'conn.pontes', label: 'Pontes SIP', icon: 'network' }
   ```
2. Libere o módulo para os perfis em `ROLES[...].allow` (`'conn.*'` já cobre o grupo inteiro).
3. Opcional: crie a tela em `assets/js/pages.js`:
   ```js
   PAGES['conn.pontes'] = {
     render(ctx) { return pageHead('Pontes SIP', 'Descrição…') + '<div class="card">…</div>'; },
     mount(ctx) { /* eventos, gráficos… */ }
   };
   ```
   Sem `PAGES[id]` o roteador usa a tela genérica de configuração.

## Validação funcional

`docs/CHECKLIST-FRONTEND.md` lista, módulo a módulo, o que um PABX moderno precisa ter,
o que já está pronto aqui, o que é apenas estrutura e o que depende do backend —
incluindo os endpoints e eventos que o servidor precisará expor.

## Backend

A pilha de produção está em `infra/` e `api/`:

| Componente | Versão |
|---|---|
| Debian | 13 (trixie) |
| Telium PABX | 1.0.1 |
| Asterisk | 22.11.0 — PJSIP, compilado do fonte |
| coturn | TURN/STUN próprio, credencial com prazo |
| Janus | 1.4.1 — opcional e **desligado**, fora do caminho da chamada |
| MariaDB | 12.3.3 |
| nginx | mainline 1.31.x |
| PHP | 8.4 + Slim 4 |

- `infra/README.md` — como instalar, atualizar e verificar a VM
- `docs/BACKEND.md` — arquitetura, portas, fluxo de "Aplicar configurações" e API

A API **gera os arquivos `.conf`** do Asterisk a partir do banco e recarrega pelo
AMI — sem realtime e sem `sudo`.

## Dados

**Não há dado fictício em lugar nenhum.** A base nasce com a empresa, os quatro
perfis de acesso e a conta `admin` — mais nada. Ramais, troncos, filas, rotas e
URAs são cadastrados pelo console; CDR, CEL e gravações são escritos pelo próprio
Asterisk conforme as chamadas acontecem.

Telas sem dado mostram estado vazio explicando o que fazer, nunca número inventado.

Para zerar a base operacional de uma instalação já em uso:

```bash
sudo ansible-playbook site.yml -e limpar_banco=true
```

Preserva empresa, perfis, permissões e a conta `admin`.

Numa **máquina de teste**, vale o contrário: subir com um cenário pronto,
para exercitar as funções sem vinte cadastros à mão antes da primeira
ligação.

```bash
sudo ansible-playbook site.yml -e cenario_teste=true
```

Ramais 1001 a 1004, grupo de toque, fila com agentes, conferência, URA,
horário comercial, DID e rotas. Nunca em instalação de cliente.

## O que falta

A lista honesta, com o estrago de cada pendência, está em
[docs/PENDENCIAS.md](docs/PENDENCIAS.md). O resumo:

- **Quatro módulos têm tela e nenhum efeito:** Tarifação, Tabela de
  Tarifas, Provisionamento e Integrações/API.
- Barramento de tempo real (AMI → WebSocket): hoje as telas de estado
  perguntam ao Asterisk a cada carga e não se atualizam sozinhas.
- Exportação de relatórios em CSV/PDF.

O softphone do navegador **não depende mais do Janus**: fala SIP sobre
WebSocket direto com o Asterisk, pelo `/ws` do nginx.

## Paleta de dados

As séries dos gráficos usam os dois primeiros slots de uma paleta categórica validada
para daltonismo e contraste (azul `#2a78d6` / laranja `#eb6834` no claro; `#3987e5` /
`#d95926` no escuro), com legenda sempre visível e tooltip por coluna.
