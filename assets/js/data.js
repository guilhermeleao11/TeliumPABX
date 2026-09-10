/* =========================================================
   Telium PABX — Dados da aplicação
   Menu, perfis de acesso, usuários e massa de dados demo.
   ========================================================= */

/* ---------------------------------------------------------
   1. ÁRVORE DE MENU
   Cada item possui um id que também é a chave de permissão.
   --------------------------------------------------------- */
const MENU = [
  {
    id: 'dash', label: 'Painel de Indicadores', icon: 'gauge',
    items: [
      { id: 'dash.visaogeral', label: 'Visão Geral',            icon: 'activity' },
      { id: 'dash.temporeal',  label: 'Tempo Real (Wallboard)', icon: 'target', pill: 'live' },
      { id: 'dash.sistema',    label: 'Estatísticas do Sistema',icon: 'cpu' },
      { id: 'dash.asterisk',   label: 'Asterisk Info',          icon: 'server' }
    ]
  },
  {
    id: 'admin', label: 'Administrador', icon: 'shield',
    items: [
      { id: 'admin.administradores', label: 'Administradores',          icon: 'users' },
      { id: 'admin.usuarios',        label: 'Gerenciador de Usuários',  icon: 'user' },
      { id: 'admin.permissoes',      label: 'Perfis e Permissões',      icon: 'key' },
      { id: 'admin.modulos',         label: 'Admin Módulos',            icon: 'package' },
      { id: 'admin.backup',          label: 'Backup & Restauração',     icon: 'database' },
      { id: 'admin.certificados',    label: 'Certificados',             icon: 'lock' },
      { id: 'admin.listanegra',      label: 'Lista Negra',              icon: 'phoneOff' },
      { id: 'admin.allowlist',       label: 'Allowlist',                icon: 'checkCirc' },
      { id: 'admin.cli',             label: 'CLI Asterisk',             icon: 'terminal' },
      { id: 'admin.configedit',      label: 'Config Edit',              icon: 'file' },
      { id: 'admin.contatos',        label: 'Gerenciador de Contatos',  icon: 'book' },
      { id: 'admin.destinos',        label: 'Destinos Personalizados',  icon: 'branch' },
      { id: 'admin.codigos',         label: 'Códigos de Recurso',       icon: 'grid' },
      { id: 'admin.gravacoes',       label: 'Gravações do Sistema',     icon: 'mic' },
      { id: 'admin.idiomas',         label: 'Idiomas de Som',           icon: 'globe' },
      { id: 'admin.updates',         label: 'Atualizações',             icon: 'refresh', pill: '3' }
    ]
  },
  {
    id: 'apps', label: 'Aplicações', icon: 'grid',
    items: [
      { id: 'apps.filas',        label: 'Filas de Atendimento',   icon: 'headset' },
      { id: 'apps.grupostoque',  label: 'Grupos de Toque',        icon: 'users' },
      { id: 'apps.ura',          label: 'URA / Atendimento Digital', icon: 'branch' },
      { id: 'apps.conferencias', label: 'Conferências',           icon: 'users' },
      { id: 'apps.gravacao',     label: 'Gravação de Chamadas',   icon: 'mic' },
      { id: 'apps.anuncios',     label: 'Anúncios',               icon: 'speaker' },
      { id: 'apps.condicoes',    label: 'Condições Horárias',     icon: 'clock' },
      { id: 'apps.grupohorario', label: 'Grupos de Horário',      icon: 'calendar' },
      { id: 'apps.sigame',       label: 'Siga-me',                icon: 'shuffle' },
      { id: 'apps.correiovoz',   label: 'Correio de Voz',         icon: 'voicemail' },
      { id: 'apps.disa',         label: 'DISA',                   icon: 'key' },
      { id: 'apps.diretorio',    label: 'Diretório',              icon: 'book' },
      { id: 'apps.estacionamento', label: 'Estacionamento',       icon: 'package' },
      { id: 'apps.paging',       label: 'Megafonia e Interfonia', icon: 'volume' },
      { id: 'apps.retorno',      label: 'Chamada de Retorno',     icon: 'phoneIn' },
      { id: 'apps.fluxo',        label: 'Controle de Fluxo',      icon: 'route' },
      { id: 'apps.idchamador',   label: 'Definir ID do Chamador', icon: 'creditCard' },
      { id: 'apps.tts',          label: 'Texto em Voz',           icon: 'mic' },
      { id: 'apps.despertar',    label: 'Chamadas de Despertar',  icon: 'clock' }
    ]
  },
  {
    id: 'conn', label: 'Conectividade', icon: 'plug',
    items: [
      { id: 'conn.ramais',        label: 'Ramais',                  icon: 'phone' },
      { id: 'conn.troncos',       label: 'Troncos',                 icon: 'network' },
      { id: 'conn.rotasentrada',  label: 'Rotas de Entrada',        icon: 'arrowDown' },
      { id: 'conn.rotassaida',    label: 'Rotas de Saída',          icon: 'arrowUp' },
      { id: 'conn.did',           label: 'DIDs / Numeração',        icon: 'list' },
      { id: 'conn.firewall',      label: 'Firewall',                icon: 'shield' },
      { id: 'conn.provisionamento', label: 'Provisionamento',       icon: 'download' },
      { id: 'conn.rede',          label: 'Configurações de Rede',   icon: 'wifi' },
      { id: 'conn.webrtc',        label: 'WebRTC / Softphone',      icon: 'globe' }
    ]
  },
  {
    id: 'rel', label: 'Relatórios', icon: 'chart',
    items: [
      { id: 'rel.cdr',       label: 'CDR — Registro de Chamadas', icon: 'list' },
      { id: 'rel.gravacoes', label: 'Gravações',                  icon: 'play' },
      { id: 'rel.filas',     label: 'Desempenho de Filas',        icon: 'headset' },
      { id: 'rel.ramais',    label: 'Desempenho de Ramais',       icon: 'phone' },
      { id: 'rel.troncos',   label: 'Ocupação de Troncos',        icon: 'network' },
      { id: 'rel.cel',       label: 'Eventos de Canal (CEL)',     icon: 'activity' },
      { id: 'rel.logs',      label: 'Registro de Atividades',     icon: 'file' },
      { id: 'rel.agentes',   label: 'Produtividade de Agentes',   icon: 'users' }
    ]
  },
  {
    id: 'cfg', label: 'Configurações', icon: 'sliders',
    items: [
      { id: 'cfg.avancadas',  label: 'Configurações Avançadas', icon: 'settings' },
      { id: 'cfg.sip',        label: 'SIP / PJSIP',             icon: 'network' },
      { id: 'cfg.correiovoz', label: 'Correio de Voz',          icon: 'voicemail' },
      { id: 'cfg.musica',     label: 'Música em Espera',        icon: 'volume' },
      { id: 'cfg.pinsets',    label: 'Conjuntos de PIN',        icon: 'key' },
      { id: 'cfg.fax',        label: 'Fax',                     icon: 'file' },
      { id: 'cfg.notificacoes', label: 'Notificações e E-mail', icon: 'mail' },
      { id: 'cfg.empresa',      label: 'Dados da Empresa',      icon: 'building' }
    ]
  },
  {
    id: 'telium', label: 'Telium', icon: 'star',
    items: [
      { id: 'telium.painel',      label: 'Painel Telium',    icon: 'building' },
      { id: 'telium.tarifacao',   label: 'Tarifação',        icon: 'creditCard' },
      { id: 'telium.licencas',    label: 'Licenciamento',    icon: 'key' },
      { id: 'telium.integracoes', label: 'Integrações / API',icon: 'layers' },
      { id: 'telium.suporte',     label: 'Suporte',          icon: 'helpCircle' }
    ]
  },
  {
    id: 'pcu', label: 'PCU — Meu Painel', icon: 'user',
    items: [
      { id: 'pcu.meuramal',   label: 'Meu Ramal',        icon: 'phone' },
      { id: 'pcu.chamadas',   label: 'Minhas Chamadas',  icon: 'list' },
      { id: 'pcu.correiovoz', label: 'Meu Correio de Voz', icon: 'voicemail', pill: '2' },
      { id: 'pcu.sigame',     label: 'Meu Siga-me',      icon: 'shuffle' },
      { id: 'pcu.contatos',   label: 'Meus Contatos',    icon: 'book' },
      { id: 'pcu.perfil',     label: 'Meu Perfil e Segurança', icon: 'lock' }
    ]
  }
];

/* ---------------------------------------------------------
   2. PERFIS DE ACESSO
   allow: chaves de menu liberadas ('*' = tudo, 'grupo.*' = grupo inteiro)
   caps : ações permitidas dentro das telas liberadas
   --------------------------------------------------------- */
const ROLES = {
  admin: {
    label: 'Administrador',
    color: 'brand',
    desc: 'Acesso irrestrito a todos os módulos, incluindo configuração do sistema.',
    allow: ['*'],
    caps: ['criar', 'editar', 'excluir', 'exportar', 'reiniciar', 'permissoes']
  },
  supervisor: {
    label: 'Supervisor',
    color: 'info',
    desc: 'Acompanha operação, filas e relatórios. Edita aplicações de atendimento.',
    allow: [
      'dash.*', 'rel.*', 'pcu.*',
      'apps.filas', 'apps.grupostoque', 'apps.ura', 'apps.conferencias',
      'apps.gravacao', 'apps.anuncios', 'apps.condicoes', 'apps.grupohorario',
      'conn.ramais', 'telium.painel', 'telium.tarifacao'
    ],
    caps: ['criar', 'editar', 'exportar']
  },
  operador: {
    label: 'Operador',
    color: 'ok',
    desc: 'Atendente. Enxerga apenas o próprio painel e recursos pessoais.',
    allow: ['dash.visaogeral', 'pcu.*', 'apps.sigame', 'apps.correiovoz'],
    caps: ['editar']
  },
  auditor: {
    label: 'Auditoria',
    color: 'warn',
    desc: 'Somente leitura de relatórios, gravações e trilhas de auditoria.',
    allow: ['dash.visaogeral', 'rel.*', 'admin.gravacoes', 'pcu.chamadas'],
    caps: ['exportar']
  }
};

/* ---------------------------------------------------------
   3. USUÁRIOS DEMO
   --------------------------------------------------------- */
const USERS = [
  { user: 'admin',      pass: 'T3l1um_@2024_@aD1m',      name: 'Guilherme Leão',  role: 'admin',      ramal: '1000', email: 'grp-voip@telium.com.br', setor: 'TI / VoIP',      status: 'ativo',    ultimo: 'Agora' },
  { user: 'supervisor', pass: 'T3l1um_@2024_@aD1m',   name: 'Marina Duarte',   role: 'supervisor', ramal: '1010', email: 'marina@telium.com.br',   setor: 'Atendimento',    status: 'ativo',    ultimo: 'Há 12 min' },
  { user: 'operador',   pass: 'T3l1um_@2024_@aD1m',    name: 'Rafael Santos',   role: 'operador',   ramal: '2031', email: 'rafael@telium.com.br',   setor: 'Suporte N1',     status: 'ativo',    ultimo: 'Há 3 min' },
  { user: 'auditor',    pass: 'T3l1um_@2024_@aD1m',   name: 'Carla Nogueira',  role: 'auditor',    ramal: '1500', email: 'carla@telium.com.br',    setor: 'Compliance',     status: 'ativo',    ultimo: 'Ontem, 17:42' },
  { user: 'jmartins',   pass: 'T3l1um_@2024_@aD1m',   name: 'João Martins',    role: 'operador',   ramal: '2032', email: 'joao@telium.com.br',     setor: 'Suporte N1',     status: 'ativo',    ultimo: 'Há 1 h' },
  { user: 'pcosta',     pass: 'T3l1um_@2024_@aD1m',   name: 'Patrícia Costa',  role: 'operador',   ramal: '2033', email: 'patricia@telium.com.br', setor: 'Comercial',      status: 'inativo',  ultimo: '02/09/2026' },
  { user: 'lferreira',  pass: 'T3l1um_@2024_@aD1m',   name: 'Lucas Ferreira',  role: 'supervisor', ramal: '1011', email: 'lucas@telium.com.br',    setor: 'Comercial',      status: 'ativo',    ultimo: 'Há 26 min' },
  { user: 'abraga',     pass: 'T3l1um_@2024_@aD1m',   name: 'Ana Braga',       role: 'operador',   ramal: '2034', email: 'ana@telium.com.br',      setor: 'Financeiro',     status: 'bloqueado',ultimo: '28/08/2026' }
];

/* ---------------------------------------------------------
   4. MASSA DE DADOS DEMO
   --------------------------------------------------------- */
const DEMO = {
  kpis: [
    { label: 'Chamadas hoje',      value: '3.184', delta: +12.4, ico: 'phone',    tone: 'brand', foot: 'vs. 2.833 ontem' },
    { label: 'Em atendimento',     value: '27',    delta: null,  ico: 'headset',  tone: 'ok',    foot: '4 em espera na fila' },
    { label: 'Taxa de abandono',   value: '4,1%',  delta: -1.8,  ico: 'phoneOff', tone: 'warn',  foot: 'meta ≤ 5%' },
    { label: 'TMA — tempo médio',  value: '3m 42s',delta: +0.6,  ico: 'clock',    tone: 'info',  foot: '1.412 chamadas medidas' }
  ],

  /* Volume por faixa de hora — 2 séries (empilhadas) */
  volume: {
    labels: ['07h','08h','09h','10h','11h','12h','13h','14h','15h','16h','17h','18h'],
    series: [
      { key: 'atendidas', label: 'Atendidas', color: 'var(--series-1)',
        values: [64, 188, 274, 312, 298, 176, 205, 289, 331, 306, 241, 118] },
      { key: 'perdidas',  label: 'Perdidas',  color: 'var(--series-2)',
        values: [ 6,  21,  33,  29,  24,  38,  18,  26,  31,  22,  17,   9] }
    ]
  },

  topRamais: [
    { ramal: '2031 · Rafael',  qtd: 214 },
    { ramal: '1010 · Marina',  qtd: 187 },
    { ramal: '2032 · João',    qtd: 163 },
    { ramal: '2034 · Ana',     qtd: 128 },
    { ramal: '1011 · Lucas',   qtd:  96 },
    { ramal: '2033 · Patrícia',qtd:  74 }
  ],

  troncos: [
    { nome: 'SIP-Vivo-Principal', tipo: 'PJSIP', canais: '30/60', uso: 50, status: 'registrado', ip: '187.45.12.8',  latencia: '18 ms' },
    { nome: 'SIP-Claro-Backup',   tipo: 'PJSIP', canais: '4/30',  uso: 13, status: 'registrado', ip: '200.98.4.221', latencia: '24 ms' },
    { nome: 'E1-Embratel',        tipo: 'DAHDi', canais: '22/30', uso: 73, status: 'registrado', ip: '—',            latencia: '—' },
    { nome: 'SIP-Filial-RJ',      tipo: 'PJSIP', canais: '0/10',  uso: 0,  status: 'offline',    ip: '10.20.0.4',    latencia: '—' },
    { nome: 'SIP-Callcenter-4G',  tipo: 'PJSIP', canais: '8/16',  uso: 50, status: 'alerta',     ip: '177.12.9.40',  latencia: '186 ms' }
  ],

  ramais: [
    { num: '1000', nome: 'Guilherme Leão', tec: 'PJSIP', setor: 'TI / VoIP',   disp: 'disponivel', device: 'Yealink T54W',   vm: true,  gravar: true  },
    { num: '1010', nome: 'Marina Duarte',  tec: 'PJSIP', setor: 'Atendimento', disp: 'emchamada',  device: 'Softphone Zoiper',vm: true,  gravar: true  },
    { num: '1011', nome: 'Lucas Ferreira', tec: 'PJSIP', setor: 'Comercial',   disp: 'disponivel', device: 'Grandstream GXP', vm: true,  gravar: false },
    { num: '1500', nome: 'Carla Nogueira', tec: 'PJSIP', setor: 'Compliance',  disp: 'ausente',    device: 'Yealink T31',     vm: true,  gravar: true  },
    { num: '2031', nome: 'Rafael Santos',  tec: 'PJSIP', setor: 'Suporte N1',  disp: 'emchamada',  device: 'Headset USB',     vm: false, gravar: true  },
    { num: '2032', nome: 'João Martins',   tec: 'PJSIP', setor: 'Suporte N1',  disp: 'disponivel', device: 'Yealink T31',     vm: true,  gravar: true  },
    { num: '2033', nome: 'Patrícia Costa', tec: 'PJSIP', setor: 'Comercial',   disp: 'offline',    device: 'Softphone MicroSIP', vm: true, gravar: false },
    { num: '2034', nome: 'Ana Braga',      tec: 'PJSIP', setor: 'Financeiro',  disp: 'nperturbe',  device: 'Yealink T54W',    vm: true,  gravar: true  },
    { num: '3001', nome: 'Recepção',       tec: 'PJSIP', setor: 'Recepção',    disp: 'disponivel', device: 'Fanvil X4U',      vm: false, gravar: false },
    { num: '3002', nome: 'Sala de Reunião',tec: 'PJSIP', setor: 'Corporativo', disp: 'offline',    device: 'Polycom IP5000',  vm: false, gravar: false }
  ],

  filas: [
    { num: '600', nome: 'Suporte Técnico', estrategia: 'ringall',   agentes: 6, online: 4, espera: 3, tme: '00:42', sla: 92, abandono: 3.4 },
    { num: '601', nome: 'Comercial',       estrategia: 'leastrecent', agentes: 4, online: 3, espera: 1, tme: '00:18', sla: 97, abandono: 1.9 },
    { num: '602', nome: 'Financeiro',      estrategia: 'linear',     agentes: 3, online: 1, espera: 0, tme: '01:12', sla: 78, abandono: 8.6 },
    { num: '603', nome: 'Retenção',        estrategia: 'fewestcalls',agentes: 5, online: 5, espera: 0, tme: '00:26', sla: 95, abandono: 2.2 }
  ],

  cdr: [
    { data: '10/09/2026 14:38:02', origem: '2031', destino: '11 3255-8800', dir: 'saida',   dur: '00:04:12', status: 'atendida',  tronco: 'SIP-Vivo-Principal', grav: true },
    { data: '10/09/2026 14:36:55', origem: '11 98877-1234', destino: '600', dir: 'entrada', dur: '00:07:33', status: 'atendida',  tronco: 'SIP-Vivo-Principal', grav: true },
    { data: '10/09/2026 14:34:10', origem: '11 3011-2244', destino: '601', dir: 'entrada', dur: '00:00:00', status: 'perdida',   tronco: 'SIP-Claro-Backup',   grav: false },
    { data: '10/09/2026 14:31:47', origem: '1010', destino: '2032',        dir: 'interna', dur: '00:01:05', status: 'atendida',  tronco: '—',                  grav: false },
    { data: '10/09/2026 14:28:19', origem: '11 99123-7788', destino: '3001',dir: 'entrada', dur: '00:02:47', status: 'atendida',  tronco: 'E1-Embratel',        grav: true },
    { data: '10/09/2026 14:25:03', origem: '2034', destino: '0800 111 2233',dir: 'saida',  dur: '00:00:22', status: 'ocupado',   tronco: 'SIP-Vivo-Principal', grav: false },
    { data: '10/09/2026 14:22:58', origem: '11 3888-9000', destino: '602',  dir: 'entrada', dur: '00:12:04', status: 'atendida',  tronco: 'SIP-Vivo-Principal', grav: true },
    { data: '10/09/2026 14:19:31', origem: '2032', destino: '11 97654-3210',dir: 'saida',  dur: '00:03:38', status: 'atendida',  tronco: 'SIP-Claro-Backup',   grav: true },
    { data: '10/09/2026 14:15:44', origem: '11 3222-1100', destino: '600',  dir: 'entrada', dur: '00:00:00', status: 'abandonada',tronco: 'E1-Embratel',        grav: false },
    { data: '10/09/2026 14:12:09', origem: '1500', destino: '1000',         dir: 'interna', dur: '00:06:51', status: 'atendida',  tronco: '—',                  grav: true },
    { data: '10/09/2026 14:08:27', origem: '11 96543-0099', destino: '601', dir: 'entrada', dur: '00:04:19', status: 'atendida',  tronco: 'SIP-Vivo-Principal', grav: true },
    { data: '10/09/2026 14:03:15', origem: '2031', destino: '11 3777-4455', dir: 'saida',   dur: '00:00:00', status: 'falha',     tronco: 'SIP-Filial-RJ',      grav: false }
  ],

  sistema: {
    cpu: 34, mem: 61, disco: 47, uptime: '38 dias, 06:12',
    asterisk: '20.9.2', distro: 'TeliumPBX 3.4.1', kernel: 'Linux 6.1.0-23-amd64',
    canaisAtivos: 27, sipPeers: '48/52', chamadasHoje: 3184
  },

  notificacoes: [
    { tone: 'danger', ico: 'alert',     titulo: 'Tronco SIP-Filial-RJ offline',   texto: 'Sem registro há 14 minutos.', tempo: 'há 14 min' },
    { tone: 'warn',   ico: 'clock',     titulo: 'Fila Financeiro acima do TME',   texto: 'Tempo médio de espera em 01:12.', tempo: 'há 32 min' },
    { tone: 'info',   ico: 'refresh',   titulo: '3 módulos com atualização',      texto: 'Framework, CDR e Filas.', tempo: 'há 2 h' },
    { tone: 'ok',     ico: 'checkCirc', titulo: 'Backup concluído',               texto: 'backup-diario-2026-09-10.tar.gz', tempo: 'há 6 h' }
  ],

  /* Identidade da central — um PABX, uma empresa */
  empresa: {
    nome: 'Telium Networks', razao: 'Telium Networks Telecomunicações Ltda.',
    cnpj: '04.235.678/0001-90', endereco: 'Av. Paulista, 1000 — São Paulo/SP',
    telefone: '11 3255-8800', fuso: 'America/Sao_Paulo', idioma: 'Português (Brasil)',
    ramaisContratados: 150, ramaisUsados: 128, troncos: 5, plano: 'Enterprise'
  },

  /* Rotas */
  rotasEntrada: [
    { did: '11 3255-8800', desc: 'Comercial 0800',  cid: 'qualquer', destino: 'URA Principal',      hora: 'Comercial', prio: 1 },
    { did: '11 3255-8801', desc: 'Suporte direto',  cid: 'qualquer', destino: 'Fila 600 — Suporte', hora: 'Comercial', prio: 2 },
    { did: '11 3255-8899', desc: 'Fax',             cid: 'qualquer', destino: 'Fax → e-mail',       hora: '24x7',      prio: 3 },
    { did: 'qualquer',     desc: 'Catch-all',       cid: 'qualquer', destino: 'Recepção 3001',      hora: '24x7',      prio: 99 }
  ],
  rotasSaida: [
    { nome: 'Local / Fixo',     padrao: '[2-5]XXXXXXX',  tronco: 'SIP-Vivo-Principal', prefixo: '0', pin: false, ordem: 1 },
    { nome: 'Celular',          padrao: '9XXXXXXXX',     tronco: 'SIP-Vivo-Principal', prefixo: '0', pin: false, ordem: 2 },
    { nome: 'DDD Nacional',     padrao: '0XX.',          tronco: 'SIP-Claro-Backup',   prefixo: '',  pin: true,  ordem: 3 },
    { nome: 'Internacional',    padrao: '00.',           tronco: 'E1-Embratel',        prefixo: '',  pin: true,  ordem: 4 },
    { nome: 'Emergência',       padrao: '1XX',           tronco: 'E1-Embratel',        prefixo: '',  pin: false, ordem: 0 }
  ],

  /* URA */
  ura: {
    nome: 'URA Principal', audio: 'ura-principal.wav', timeout: 8, tentativas: 3,
    opcoes: [
      { tecla: '1', label: 'Suporte Técnico',  destino: 'Fila 600 — Suporte Técnico', tipo: 'fila' },
      { tecla: '2', label: 'Comercial',        destino: 'Fila 601 — Comercial',       tipo: 'fila' },
      { tecla: '3', label: 'Financeiro',       destino: 'Fila 602 — Financeiro',      tipo: 'fila' },
      { tecla: '4', label: 'Falar com atendente', destino: 'Recepção 3001',           tipo: 'ramal' },
      { tecla: '9', label: 'Repetir menu',     destino: 'URA Principal',              tipo: 'ura' },
      { tecla: '0', label: 'Diretório por nome', destino: 'Diretório',                tipo: 'app' }
    ],
    semResposta: 'Correio de voz da recepção',
    invalida: 'Repetir menu (3x) → desligar'
  },

  /* Gravações */
  gravacoes: [
    { id: 'rec-8842', data: '10/09/2026 14:38', origem: '2031', destino: '11 3255-8800', dur: '04:12', tam: '1,9 MB', agente: 'Rafael Santos', fila: '—',   tags: ['saída'] },
    { id: 'rec-8841', data: '10/09/2026 14:36', origem: '11 98877-1234', destino: '600', dur: '07:33', tam: '3,4 MB', agente: 'Marina Duarte', fila: '600', tags: ['entrada','suporte'] },
    { id: 'rec-8840', data: '10/09/2026 14:28', origem: '11 99123-7788', destino: '3001',dur: '02:47', tam: '1,2 MB', agente: 'Recepção',      fila: '—',   tags: ['entrada'] },
    { id: 'rec-8839', data: '10/09/2026 14:22', origem: '11 3888-9000',  destino: '602', dur: '12:04', tam: '5,6 MB', agente: 'Ana Braga',     fila: '602', tags: ['entrada','financeiro'] },
    { id: 'rec-8838', data: '10/09/2026 14:19', origem: '2032', destino: '11 97654-3210',dur: '03:38', tam: '1,7 MB', agente: 'João Martins',  fila: '—',   tags: ['saída'] }
  ],

  /* Tarifação */
  tarifacao: {
    mes: 'Setembro/2026', total: 4287.65, minutos: 18422, economia: 12.4,
    porSetor: [
      { setor: 'Comercial',   min: 7420, custo: 1834.20, chamadas: 1284 },
      { setor: 'Suporte N1',  min: 6110, custo: 1188.40, chamadas: 1902 },
      { setor: 'Financeiro',  min: 2380, custo:  742.15, chamadas:  431 },
      { setor: 'TI / VoIP',   min: 1512, custo:  318.90, chamadas:  208 },
      { setor: 'Recepção',    min: 1000, custo:  204.00, chamadas:  366 }
    ],
    porDestino: [
      { tipo: 'Celular',        min: 9840, custo: 2458.00 },
      { tipo: 'Fixo local',     min: 5320, custo:  638.40 },
      { tipo: 'DDD nacional',   min: 2410, custo:  795.30 },
      { tipo: 'Internacional',  min:  452, custo:  361.60 },
      { tipo: '0800 / gratuito',min:  400, custo:   34.35 }
    ]
  },

  /* Provisionamento */
  dispositivos: [
    { mac: '80:5E:C0:11:A2:3F', modelo: 'Yealink T54W', ramal: '1000', fw: '96.86.0.5',  ip: '10.0.10.21', estado: 'provisionado', visto: 'há 2 min' },
    { mac: '00:0B:82:4C:11:98', modelo: 'Grandstream GXP2170', ramal: '1011', fw: '1.0.11.74', ip: '10.0.10.35', estado: 'provisionado', visto: 'há 8 min' },
    { mac: '0C:38:3E:77:12:A0', modelo: 'Fanvil X4U',   ramal: '3001', fw: '2.12.10',   ip: '10.0.10.44', estado: 'provisionado', visto: 'há 1 min' },
    { mac: '80:5E:C0:22:B4:17', modelo: 'Yealink T31',  ramal: '2032', fw: '124.86.0.1',ip: '10.0.10.52', estado: 'pendente',     visto: '—' },
    { mac: '64:16:7F:30:C2:55', modelo: 'Polycom IP5000', ramal: '3002', fw: '5.9.5',   ip: '10.0.10.60', estado: 'erro',         visto: 'há 3 h' }
  ],

  /* Segurança */
  firewall: {
    fail2ban: true, ipsBloqueados: 37, tentativas24h: 1284, portas: [
      { porta: '5060/udp', servico: 'SIP', zona: 'Confiável', estado: 'aberta' },
      { porta: '5061/tcp', servico: 'SIP TLS', zona: 'Internet', estado: 'aberta' },
      { porta: '8089/tcp', servico: 'WebRTC (WSS)', zona: 'Internet', estado: 'aberta' },
      { porta: '10000-20000/udp', servico: 'RTP', zona: 'Internet', estado: 'aberta' },
      { porta: '22/tcp',   servico: 'SSH', zona: 'Confiável', estado: 'restrita' }
    ],
    bloqueios: [
      { ip: '45.148.10.92',  motivo: 'SIP brute force', tentativas: 412, quando: 'há 12 min', pais: 'RU' },
      { ip: '193.32.162.14', motivo: 'Registro inválido', tentativas: 208, quando: 'há 48 min', pais: 'NL' },
      { ip: '203.0.113.77',  motivo: 'Scan de extensões', tentativas: 96, quando: 'há 3 h',  pais: 'CN' }
    ]
  },

  /* Backups */
  backups: [
    { nome: 'backup-diario-2026-09-10.tar.gz', tipo: 'Completo', tam: '218 MB', quando: 'Hoje, 03:00', destino: 'S3 + local', estado: 'ok' },
    { nome: 'backup-diario-2026-09-09.tar.gz', tipo: 'Completo', tam: '214 MB', quando: 'Ontem, 03:00', destino: 'S3 + local', estado: 'ok' },
    { nome: 'backup-config-2026-09-08.tar.gz', tipo: 'Configuração', tam: '4,2 MB', quando: '08/09, 03:00', destino: 'local', estado: 'ok' },
    { nome: 'backup-diario-2026-09-07.tar.gz', tipo: 'Completo', tam: '209 MB', quando: '07/09, 03:00', destino: 'S3', estado: 'falha' }
  ],

  /* Agenda corporativa */
  contatos: [
    { nome: 'Suporte Vivo (operadora)', num: '10315',        grupo: 'Fornecedores', fav: true },
    { nome: 'ACME — Compras',           num: '11 3444-1200', grupo: 'Clientes',     fav: true },
    { nome: 'Contabilidade Prisma',     num: '11 3777-8890', grupo: 'Fornecedores', fav: false },
    { nome: 'Marina Duarte',            num: '1010',         grupo: 'Interno',      fav: true },
    { nome: 'João Martins',             num: '2032',         grupo: 'Interno',      fav: false },
    { nome: 'Portaria — Prédio',        num: '3010',         grupo: 'Interno',      fav: false }
  ],

  /* Integrações */
  integracoes: [
    { nome: 'Webhook — CRM Pipedrive', tipo: 'Webhook', evento: 'call.answered, call.ended', estado: 'ativo', ult: 'há 4 min' },
    { nome: 'API REST — Portal do cliente', tipo: 'API Key', evento: 'leitura de CDR', estado: 'ativo', ult: 'há 22 min' },
    { nome: 'Click-to-call — Zendesk', tipo: 'App', evento: 'originate', estado: 'ativo', ult: 'há 1 h' },
    { nome: 'SIEM — envio de logs', tipo: 'Syslog', evento: 'auditoria', estado: 'inativo', ult: '—' }
  ],

  /* Desempenho de agentes */
  agentes: [
    { nome: 'Rafael Santos',  ramal: '2031', atendidas: 214, tma: '03:12', pausa: '00:42', sla: 94, nota: 4.6 },
    { nome: 'Marina Duarte',  ramal: '1010', atendidas: 187, tma: '04:05', pausa: '00:28', sla: 96, nota: 4.8 },
    { nome: 'João Martins',   ramal: '2032', atendidas: 163, tma: '03:48', pausa: '01:10', sla: 88, nota: 4.2 },
    { nome: 'Ana Braga',      ramal: '2034', atendidas: 128, tma: '05:22', pausa: '00:55', sla: 79, nota: 3.9 },
    { nome: 'Lucas Ferreira', ramal: '1011', atendidas:  96, tma: '02:58', pausa: '00:33', sla: 97, nota: 4.7 }
  ],

  /* Série de SLA por dia (relatório de filas) */
  slaSemana: {
    labels: ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'],
    series: [
      { key: 'dentro', label: 'Dentro do SLA', color: 'var(--series-1)', values: [412, 398, 445, 471, 502, 168, 74] },
      { key: 'fora',   label: 'Fora do SLA',   color: 'var(--series-2)', values: [38, 52, 31, 44, 61, 22, 9] }
    ]
  },

  atividades: [
    { hora: '14:36', user: 'admin',      acao: 'Editou o ramal 2031',                ip: '10.0.0.14' },
    { hora: '14:12', user: 'supervisor', acao: 'Exportou relatório CDR (312 linhas)',ip: '10.0.0.32' },
    { hora: '13:58', user: 'admin',      acao: 'Aplicou configurações no Asterisk',  ip: '10.0.0.14' },
    { hora: '13:20', user: 'lferreira',  acao: 'Criou a fila 603 — Retenção',        ip: '10.0.0.51' },
    { hora: '11:47', user: 'auditor',    acao: 'Baixou 4 gravações',                 ip: '10.0.0.77' }
  ]
};
