/* =========================================================
   Telium PABX — estrutura de navegação
   Só a árvore de menu vive aqui. Usuários, perfis, ramais,
   filas e qualquer outro dado vêm da API.
   ========================================================= */

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
      { id: 'admin.usuarios',        label: 'Gerenciador de Usuários',  icon: 'user' },
      { id: 'admin.permissoes',      label: 'Perfis e Permissões',      icon: 'key' },
      { id: 'admin.backup',          label: 'Backup & Restauração',     icon: 'database' },
      { id: 'admin.certificados',    label: 'Certificados',             icon: 'lock' },
      { id: 'admin.listanegra',      label: 'Lista Negra',              icon: 'phoneOff' },
      { id: 'admin.allowlist',       label: 'Allowlist',                icon: 'checkCirc' },
      { id: 'admin.cli',             label: 'CLI Asterisk',             icon: 'terminal' },
      { id: 'admin.contatos',        label: 'Gerenciador de Contatos',  icon: 'book' },
      { id: 'admin.destinos',        label: 'Destinos Personalizados',  icon: 'branch' },
      { id: 'admin.codigos',         label: 'Códigos de Recurso',       icon: 'grid' },
      { id: 'admin.gravacoes',       label: 'Gravações do Sistema',     icon: 'mic' }
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
      { id: 'cfg.tarifas',      label: 'Tabela de Tarifas',     icon: 'creditCard' },
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
   Rótulos de apoio usados pela interface
   --------------------------------------------------------- */

/** Cor do badge por chave de perfil — o resto vem do banco. */
const COR_PERFIL = { admin: 'brand', supervisor: 'info', operador: 'ok', auditor: 'warn' };

/** Estados de ramal reportados pelo Asterisk. */
const ESTADO_RAMAL = {
  disponivel: ['ok',     'Disponível'],
  emchamada:  ['info',   'Em chamada'],
  ausente:    ['warn',   'Ausente'],
  nperturbe:  ['danger', 'Não perturbe'],
  offline:    ['',       'Offline'],
  registrado: ['ok',     'Registrado'],
  desconhecido: ['',     'Sem registro'],
  inativo:    ['',       'Inativo']
};

/** Disposições do CDR do Asterisk. */
const DISPOSICAO_CDR = {
  ANSWERED:    ['ok',     'Atendida'],
  'NO ANSWER': ['danger', 'Não atendida'],
  BUSY:        ['warn',   'Ocupado'],
  FAILED:      ['danger', 'Falha'],
  CONGESTION:  ['warn',   'Congestionada']
};

const DIRECAO_ICO = { entrada: 'arrowDown', saida: 'arrowUp', interna: 'shuffle' };
