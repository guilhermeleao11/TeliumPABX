# Telium PABX — Validação do front-end

Referência: FreePBX/Sangoma 17, 3CX, Issabel, Wazo e portais de operadoras (Vivo Fixo, Zenvia).
Legenda: **✅ pronto** · **◻ estrutura pronta, conteúdo genérico** · **⏳ depende do backend** · **✖ fora do escopo do front**

> **Atualização:** o front deixou de usar massa fictícia. Todas as telas listadas
> como ✅ consomem a API real; onde não há dado, aparece estado vazio explicando
> o que fazer. Módulos sem tela própria dizem "não implementado" em vez de exibir
> configuração falsa.

---

## 1. Acesso e segurança da interface

| Item | Estado | Onde |
|---|---|---|
| Tela de login com identidade da marca | ✅ | `index.html` |
| Mostrar/ocultar senha, "manter conectado", erro tratado | ✅ | `index.html` |
| Perfis de acesso (admin, supervisor, operador, auditoria) | ✅ | `data.js` → `ROLES` |
| Permissão por módulo (menu montado por perfil) | ✅ | `auth.js` → `Auth.menuFor` |
| Permissão por ação (criar/editar/excluir/exportar/reiniciar) | ✅ | `Auth.cap` — desabilita botões |
| Tela de "acesso não autorizado" ao forçar URL | ✅ | `pages.js` → `paginaNegada` |
| Matriz visual de permissões por perfil | ✅ | `admin.permissoes` |
| Troca de senha, 2FA (TOTP), sessões ativas | ✅ (UI) | `pcu.perfil` |
| Timeout de sessão por inatividade | ✅ (UI) | `pcu.perfil` |
| Bloqueio após N tentativas, política de senha, SSO/LDAP | ⏳ | exige backend |
| Trilha de auditoria (quem fez o quê) | ✅ visão / ⏳ dados | `rel.logs`, atividade recente |

## 2. Navegação e produtividade

| Item | Estado |
|---|---|
| Menu lateral recolhível com flyout no modo estreito | ✅ |
| Busca de módulos na sidebar e no cabeçalho | ✅ |
| Paleta de comandos `Ctrl/⌘+K` (módulos, ramais, contatos, ações) | ✅ |
| Atalhos de teclado (`/`, `[`, `D`, `?`, `Esc`) com ajuda | ✅ |
| Trilha de navegação (breadcrumb) e título de página dinâmico | ✅ |
| Tema claro/escuro persistente | ✅ |
| Responsivo (gaveta no mobile, tabelas com rolagem) | ✅ |
| Estados de carregamento (skeleton), vazio e erro | ✅ skeleton/vazio · ◻ erro de rede |
| Toasts, modais de confirmação e gavetas de formulário | ✅ |
| Internacionalização (pt-BR/en/es) | ✖ nesta fase — textos ainda fixos no código |
| Acessibilidade (foco visível, ARIA, contraste) | ✅ parcial — falta auditoria WCAG AA completa |

## 3. Telefonia — configuração

| Item | Estado | Onde |
|---|---|---|
| Ramais: lista, filtros, estado em tempo real | ✅ | `conn.ramais` |
| Ramal: formulário completo (geral, voz, rede/SIP, permissões) | ✅ | gaveta com abas |
| Exclusão com confirmação | ✅ | modal |
| Troncos SIP/E1 com registro, ocupação e latência | ✅ | `conn.troncos` |
| Rotas de entrada (DID → destino, condição horária) | ✅ | `conn.rotasentrada` |
| Rotas de saída (precedência, padrão, tronco, PIN) | ✅ | `conn.rotassaida` |
| URA — construtor visual da árvore de opções | ✅ | `apps.ura` |
| Filas de atendimento (estratégia, agentes, SLA) | ✅ | `apps.filas` |
| Grupos de toque, conferência, siga-me, DISA, estacionamento, megafonia, callback, despertar, blacklist, condições horárias, TTS | ◻ | tela genérica de configuração |
| Correio de voz (config global e por ramal) | ✅ por ramal · ◻ global | |
| Música em espera, códigos de recurso, PIN sets, fax | ◻ | |

## 4. Operação em tempo real

| Item | Estado |
|---|---|
| Wallboard de filas (espera, agentes, TME, SLA) | ✅ `dash.temporeal` |
| Modo TV / tela cheia para o painel | ✅ |
| Chamadas em andamento com ações (escuta, gravar, encerrar) | ✅ UI · ⏳ ações reais (AMI/ARI) |
| Softphone WebRTC embutido: discador, DTMF, mudo, espera, transferência, histórico | ✅ UI completa · ⏳ SIP.js + WSS |
| Click-to-call em ramais, contatos e correio de voz | ✅ |
| Presença/BLF dos ramais | ✅ visual · ⏳ eventos ao vivo |
| Atualização automática (5 s) e notificações de alerta | ◻ UI pronta · ⏳ WebSocket |

## 5. Relatórios e qualidade

| Item | Estado |
|---|---|
| CDR com filtros, paginação e exportação | ✅ (paginação visual) |
| Gravações com player, tags, busca e download | ✅ |
| Desempenho de filas com gráfico de SLA | ✅ `rel.filas` |
| Produtividade de agentes (TMA, pausa, SLA, nota) | ✅ `rel.agentes` |
| Ocupação de troncos, eventos de canal (CEL) | ◻ |
| Tarifação: custo por setor, destino e rateio | ✅ `telium.tarifacao` |
| Exportação CSV/PDF e envio agendado por e-mail | ✅ UI · ⏳ geração |
| Gráficos com paleta validada para daltonismo, legenda e tooltip | ✅ |

## 6. Dispositivos e rede

| Item | Estado |
|---|---|
| Provisionamento por MAC (modelo, firmware, estado, reprovisionar) | ✅ `conn.provisionamento` |
| Reiniciar aparelho remotamente | ✅ UI · ⏳ ação |
| Configurações de rede, DIDs | ◻ |
| WebRTC/gateway (endpoint, STUN/TURN, codecs) | ✅ `conn.webrtc` |

## 7. Segurança da telefonia

| Item | Estado |
|---|---|
| Firewall SIP: portas, zonas, fail2ban, IPs bloqueados | ✅ `conn.firewall` |
| Antifraude (limite de chamadas internacionais) | ✅ exibido · ⏳ regra |
| TLS/SRTP e certificados | ✅ indicado · ◻ tela de certificados |

## 8. Administração do sistema

| Item | Estado |
|---|---|
| Backup/restauração, agendamento, destino remoto, retenção | ✅ `admin.backup` |
| Gerenciador de usuários e administradores | ✅ `admin.usuarios` |
| Módulos/atualizações, CLI Asterisk, Config Edit | ◻ (CLI já tem saída de exemplo) |
| Saúde do sistema (CPU, memória, disco, uptime, canais) | ✅ `dash.sistema` |
| Registro de atividades | ◻ |

## 9. Autoatendimento do usuário (PCU)

| Item | Estado |
|---|---|
| Meu ramal (DND, siga-me, gravação, correio por e-mail) | ✅ |
| Minhas chamadas | ✅ |
| Meu correio de voz com player e retorno de ligação | ✅ |
| Meus contatos com click-to-call e grupos | ✅ |
| Meu perfil, senha, 2FA e sessões | ✅ |

## 10. Integrações

| Item | Estado |
|---|---|
| Webhooks de eventos de chamada (payload documentado) | ✅ `telium.integracoes` |
| Chaves de API, conectores CRM/helpdesk, syslog/SIEM | ✅ listagem · ⏳ geração de chave |

---

## Pendências assumidas do front (antes do "pronto para produção")

1. **i18n** — extrair os textos para dicionário (pt-BR/en/es).
2. **Erro de rede/offline** — tela e toast padronizados para falha de API.
3. **Paginação e ordenação reais** nas tabelas (hoje o filtro é client-side sobre dados fixos).
4. **Arrastar e soltar de verdade** na URA e nas rotas de saída (hoje o "grab" é visual).
5. **Auditoria WCAG AA** — navegação completa por teclado nas gavetas/tabelas e leitura por leitor de tela.
6. **Testes** — pelo menos smoke de rotas e de permissões por perfil.

---

## O que o backend precisa expor (contrato para a próxima fase)

**Autenticação**
```
POST /api/auth/login        → { token, user, role, caps[], allow[] }
POST /api/auth/logout
POST /api/auth/2fa/verify
GET  /api/me                → perfil, ramal, permissões
```

**Configuração** (CRUD REST, todos com verificação de permissão **no servidor**)
```
/api/ramais            /api/troncos          /api/rotas/entrada    /api/rotas/saida
/api/filas             /api/ura              /api/condicoes        /api/dispositivos
/api/usuarios          /api/perfis           /api/empresa          /api/backup
POST /api/asterisk/apply   → aplica o dialplan (equivale ao "Apply Config")
```

**Relatórios**
```
GET /api/cdr?de&ate&dir&status&q&page       GET /api/gravacoes/:id/audio
GET /api/relatorios/filas   /agentes   /tarifacao?mes=
POST /api/exportacoes       → CSV/PDF assíncrono
```

**Tempo real (WebSocket ou SSE)** — o wallboard, o BLF e o softphone dependem disso:
```
evento: queue.update | call.start | call.answered | call.ended
        agent.status | trunk.status | extension.presence | system.alert
```

**Telefonia ao vivo**
```
POST /api/chamadas/originate     (click-to-call)
POST /api/chamadas/:id/transfer  /hangup  /spy  /whisper  /barge
WSS  /ws  (SIP over WebSocket para o softphone — Asterisk res_http_websocket)
```

**Regra inegociável:** o filtro de menu e os botões desabilitados são conveniência de UI.
Toda permissão precisa ser revalidada no backend a cada requisição.
