/* =========================================================
   Telium PABX — Componentes de interface
   Softphone, drawer, modal, paleta de comandos, player,
   skeleton e modo TV.
   ========================================================= */

/* ============================== DRAWER ============================== */
const Drawer = {
  open({ titulo, sub, corpo, rodape = '', wide = false, aoAbrir, aoFechar }) {
    this.close();
    const bd = document.createElement('div');
    bd.className = 'drawer-backdrop'; bd.id = 'drawerBackdrop';
    const dw = document.createElement('aside');
    dw.className = 'drawer' + (wide ? ' wide' : ''); dw.id = 'drawerEl';
    dw.setAttribute('role', 'dialog'); dw.setAttribute('aria-modal', 'true');
    dw.innerHTML = `
      <div class="drawer-head">
        <div><h3>${titulo}</h3>${sub ? `<p>${sub}</p>` : ''}</div>
        <button class="icon-btn" data-drawer-close aria-label="Fechar">${icon('x')}</button>
      </div>
      <div class="drawer-body">${corpo}</div>
      ${rodape ? `<div class="drawer-foot">${rodape}</div>` : ''}`;
    document.body.append(bd, dw);
    document.body.style.overflow = 'hidden';
    bd.onclick = () => this.close();
    dw.querySelectorAll('[data-drawer-close]').forEach(b => b.onclick = () => this.close());
    addEventListener('keydown', this._esc = e => e.key === 'Escape' && this.close());
    this._aoFechar = aoFechar || null;
    if (aoAbrir) aoAbrir(dw);
  },
  close() {
    document.getElementById('drawerBackdrop')?.remove();
    document.getElementById('drawerEl')?.remove();
    document.body.style.overflow = '';
    if (this._esc) { removeEventListener('keydown', this._esc); this._esc = null; }
    // Quem abriu a gaveta pode ter deixado algo de pé — uma chamada de
    // consulta, por exemplo — que precisa ser desfeito ao fechar, seja
    // pelo botão, pelo fundo ou pelo Esc.
    const fim = this._aoFechar;
    this._aoFechar = null;
    if (fim) fim();
  }
};

/* ============================== MODAL ============================== */
const Modal = {
  confirm({ titulo, texto, ok = 'Confirmar', cancelar = 'Cancelar', tone = 'danger', ico = 'alert' }) {
    return new Promise(resolve => {
      const bd = document.createElement('div');
      bd.className = 'modal-backdrop';
      bd.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true">
          <div class="modal-body">
            <div class="m-ico" style="background:var(--${tone}-soft);color:var(--${tone})">${icon(ico,'ico ico-lg')}</div>
            <h3>${titulo}</h3><p>${texto}</p>
          </div>
          <div class="modal-foot">
            <button class="btn btn-outline" data-no>${cancelar}</button>
            <button class="btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}" data-yes>${ok}</button>
          </div>
        </div>`;
      document.body.appendChild(bd);
      const fim = v => { bd.remove(); resolve(v); };
      bd.querySelector('[data-no]').onclick = () => fim(false);
      bd.querySelector('[data-yes]').onclick = () => fim(true);
      bd.onclick = e => e.target === bd && fim(false);
    });
  }
};

/* ====================== PALETA DE COMANDOS (Ctrl+K) ====================== */
const Palette = {
  aberta: false, idx: 0, itens: [],

  build() {
    const itens = [];
    Auth.menu().forEach(g => g.items.forEach(i =>
      itens.push({ grupo: 'Módulos', label: i.label, meta: g.label, ico: i.icon || 'grid', href: '#/' + i.id })));
    itens.push(
      { grupo: 'Ações', label: 'Alternar tema claro/escuro', meta: 'tema', ico: 'moon', acao: () => Theme.toggle() },
      { grupo: 'Ações', label: 'Abrir softphone', meta: 'discador', ico: 'headset', acao: () => Softphone.abrir() },
      { grupo: 'Ações', label: 'Recolher / expandir menu', meta: '[', ico: 'menu',
        acao: () => document.getElementById('sbCollapse').click() },
      { grupo: 'Ações', label: 'Encerrar sessão', meta: 'sair', ico: 'logout',
        acao: async () => { await Auth.logout(); location.replace('index.html'); } }
    );
    this.itens = itens;
    this.carregarDiscaveis();
  },

  /**
   * Ramais e contatos vêm da API — só entram na paleta se existirem.
   *
   * Quem não tem o módulo não pede a lista: o .catch já engolia o erro,
   * mas o 403 continuava aparecendo no console do navegador de todo
   * usuário do portal, poluindo o lugar onde se procura problema.
   */
  async carregarDiscaveis() {
    const [ramais, contatos] = await Promise.all([
      Auth.can('conn.ramais')
        ? Api.get('/ramais', { limite: 500 }).catch(() => ({ dados: [] }))
        : { dados: [] },
      Auth.can('pcu.contatos') || Auth.can('admin.contatos')
        ? Api.get('/contatos', { limite: 500 }).catch(() => ({ dados: [] }))
        : { dados: [] }
    ]);

    ramais.dados.forEach(r => this.itens.push({
      grupo: 'Ramais', label: `${r.numero} · ${r.nome}`, meta: 'Ligar', ico: 'phone',
      acao: () => Softphone.discarPara(r.numero, r.nome)
    }));
    contatos.dados.forEach(c => this.itens.push({
      grupo: 'Contatos', label: c.nome, meta: c.numero, ico: 'book',
      acao: () => Softphone.discarPara(c.numero, c.nome)
    }));
  },

  abrir() {
    if (this.aberta) return;
    this.aberta = true; this.idx = 0;
    const bd = document.createElement('div');
    bd.className = 'palette-backdrop'; bd.id = 'paletteBd';
    bd.innerHTML = `
      <div class="palette" role="dialog" aria-modal="true" aria-label="Paleta de comandos">
        <div class="palette-input">${icon('search')}
          <input id="palQ" placeholder="Buscar módulo, ramal, contato ou ação…" autocomplete="off">
          <kbd class="k">Esc</kbd>
        </div>
        <div class="palette-list" id="palList"></div>
        <div class="palette-foot">
          <span><kbd class="k">↑↓</kbd> navegar</span>
          <span><kbd class="k">Enter</kbd> abrir</span>
          <span><kbd class="k">Ctrl</kbd>+<kbd class="k">K</kbd> alternar</span>
        </div>
      </div>`;
    document.body.appendChild(bd);
    const q = bd.querySelector('#palQ');
    bd.onclick = e => e.target === bd && this.fechar();
    q.addEventListener('input', () => this.pintar(q.value));
    q.addEventListener('keydown', e => {
      const vis = [...document.querySelectorAll('.palette-item')];
      if (e.key === 'Escape') this.fechar();
      if (e.key === 'ArrowDown') { e.preventDefault(); this.idx = Math.min(this.idx + 1, vis.length - 1); this.marcar(vis); }
      if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx - 1, 0); this.marcar(vis); }
      if (e.key === 'Enter')     { e.preventDefault(); vis[this.idx]?.click(); }
    });
    this.pintar('');
    q.focus();
  },

  pintar(termo) {
    const t = termo.trim().toLowerCase();
    const achados = this.itens.filter(i => !t || i.label.toLowerCase().includes(t) || (i.meta || '').toLowerCase().includes(t));
    const lista = document.getElementById('palList');
    if (!achados.length) { lista.innerHTML = `<div class="empty" style="padding:28px">${icon('search')}<p>Nada encontrado para “${termo}”.</p></div>`; return; }
    let html = '', grupoAtual = '';
    achados.slice(0, 40).forEach(i => {
      if (i.grupo !== grupoAtual) { grupoAtual = i.grupo; html += `<div class="palette-group">${grupoAtual}</div>`; }
      html += `<button class="palette-item">${icon(i.ico, 'ico ico-sm')}<span>${i.label}</span><span class="meta">${i.meta || ''}</span></button>`;
    });
    lista.innerHTML = html;
    const vis = [...lista.querySelectorAll('.palette-item')];
    const usados = achados.slice(0, 40);
    vis.forEach((el, k) => el.onclick = () => {
      const it = usados[k]; this.fechar();
      if (it.href) location.hash = it.href; else it.acao?.();
    });
    this.idx = 0; this.marcar(vis);
  },

  marcar(vis) {
    vis.forEach((el, k) => el.classList.toggle('sel', k === this.idx));
    vis[this.idx]?.scrollIntoView({ block: 'nearest' });
  },

  fechar() { document.getElementById('paletteBd')?.remove(); this.aberta = false; }
};

/* ============================== TELEFONE (WebRTC) ============================== */
/*
 * O telefone do navegador. Deixou de ser um painel flutuante no canto:
 * é a tela "Telefone" (fone.webrtc), e só quem tem esse módulo registra
 * o ramal no navegador — sem ele, a senha SIP nem chega aqui (/me).
 *
 * Este objeto é o controlador: guarda o estado da chamada e desenha o
 * aparelho dentro de #foneTelefone quando a tela está aberta. Fora dela,
 * sobram só o ícone do cabeçalho e o aviso de chamada entrando — uma
 * ligação não pode tocar muda porque a pessoa está em outra tela.
 */
const Softphone = {
  estado: 'idle', numero: '', nome: '', seg: 0, tid: null, direcao: '',
  mudo: false, espera: false, teclado: false, midia: '', consulta: null,
  /* As chamadas desta sessão do console. */
  historico: [],

  permitido() { return typeof Auth !== 'undefined' && Auth.can('fone.webrtc'); },
  naTela() { return !!document.getElementById('foneTelefone'); },

  montar() {
    if (!document.getElementById('foneAviso')) {
      const a = document.createElement('div');
      a.id = 'foneAviso';
      document.body.appendChild(a);
    }
    this.conectar();
    this.pintar();
  },

  /** Registra o ramal do usuário — só com o módulo e ramal WebRTC. */
  conectar() {
    const cfg = Auth.sessao?.softphone;
    this.modo = cfg?.modo || (cfg?.disponivel ? 'navegador' : 'nenhum');

    this.registro = cfg?.disponivel
      ? { estado: 'registrando', motivo: '' }
      : { estado: this.modo === 'aparelho' ? 'aparelho' : 'indisponivel',
          motivo: cfg?.motivo || 'A sua conta não está vinculada a nenhum ramal.' };

    if (!cfg?.disponivel) return;

    SipLink.ao((evento, dados) => this.doSip(evento, dados));
    SipLink.iniciar({
      // O endereço sai da própria origem da página, e não do hostname
      // configurado no servidor: quem abre o console pelo IP tentaria
      // abrir o WebSocket num nome que a máquina dele não resolve.
      ws: location.protocol === 'https:'
        ? `wss://${location.host}/ws`
        : (cfg.ws || `ws://${location.host}/ws`),
      ramal: cfg.ramal,
      senha: cfg.senha,
      dominio: cfg.dominio || location.hostname,
      nome: cfg.nome,
      ice: cfg.ice
    });
  },

  /** Tudo o que a camada SIP avisa chega aqui. */
  doSip(evento, dados) {
    if (evento === 'estado') {
      this.registro = { estado: SipLink.estado, motivo: SipLink.motivo };
      this.pintar();
      return;
    }
    if (evento === 'entrante') {
      this.numero = dados.numero || 'desconhecido';
      this.nome = dados.nome || '';
      this.estado = 'recebendo';
      this.direcao = 'entrada';
      this.pintar();
      return;
    }
    if (evento === 'chamando') { this.estado = 'chamando'; this.pintar(); return; }
    if (evento === 'atendida') {
      if (this.estado === 'em chamada') return;
      this.estado = 'em chamada';
      this.seg = 0;
      clearInterval(this.tid);
      this.tid = setInterval(() => {
        this.seg++;
        const el = document.getElementById('spTimer');
        if (el) el.textContent = this.fmt(this.seg);
      }, 1000);
      this.pintar();
      return;
    }
    if (evento === 'encerrada') {
      if (dados.motivo) toast(dados.motivo, 'warn');
      this.limpar();
      return;
    }
    if (evento === 'midia') {
      this.midia = dados.estado;
      if (dados.estado === 'falhou') toast(dados.motivo, 'err');
      if (dados.estado === 'instavel') toast(dados.motivo, 'warn');
      if (dados.estado === 'bloqueado') toast(dados.motivo, 'warn');
      this.pintar();
      return;
    }
    if (evento === 'transferencia') {
      toast(dados.ok ? 'Transferência concluída.' : dados.motivo, dados.ok ? 'ok' : 'err');
      return;
    }
    if (evento === 'consulta') {
      if (dados.estado === 'chamando')  this.consulta = { estado: 'chamando', numero: this.consulta?.numero || '' };
      if (dados.estado === 'atendida')  this.consulta = { estado: 'atendida', numero: this.consulta?.numero || '' };
      if (dados.estado === 'encerrada') {
        if (dados.motivo) toast(dados.motivo, 'warn');
        this.consulta = null;
      }
      this.pintarConsulta();
    }
  },

  /** O ícone do cabeçalho e a tecla D levam à tela — para quem pode. */
  abrir() {
    if (this.permitido()) { location.hash = '#/fone.webrtc'; return; }
    toast('O telefone pelo navegador não está liberado para o seu perfil.', 'warn');
  },

  /**
   * Discar a partir de outra tela (agenda, ramais, relatório).
   * Com o telefone do navegador pronto, liga por ele; senão a central
   * chama o telefone de mesa da pessoa e completa a ligação.
   */
  discarPara(num, nome = '') {
    if (this.permitido() && this.registro?.estado === 'pronto') {
      if (this.estado !== 'idle') { toast('Encerre a chamada atual antes de discar outra.', 'warn'); return; }
      this.numero = String(num); this.nome = nome;
      if (!this.naTela()) location.hash = '#/fone.webrtc';
      this.ligar();
      return;
    }
    this.ligarPeloAparelho(String(num).replace(/[^0-9*#+]/g, ''));
  },

  /** Click-to-call: a central origina, o aparelho de mesa toca. */
  async ligarPeloAparelho(destino) {
    if (!destino) { toast('Informe um número para discar.', 'warn'); return; }
    try {
      const r = await Api.post('/discar', { destino });
      toast(r.mensagem, 'ok');
      this.numero = '';
      this.pintar();
    } catch (e) {
      toast(e.status === 422 || e.status === 502
        ? e.message
        : (this.registro?.motivo || 'Não foi possível originar a chamada.'), 'err');
    }
  },

  tecla(t) {
    if (this.estado === 'em chamada') { SipLink.dtmf(t); return; }
    this.numero += t;
    this.visor();
  },

  /** Reflete this.numero no visor sem redesenhar o aparelho. */
  visor() {
    const el = document.getElementById('spNum');
    if (!el) { this.pintar(); return; }
    el.value = this.numero;
    el.focus();
    el.setSelectionRange(el.value.length, el.value.length);
  },

  async ligar() {
    const numero = this.numero.replace(/[^0-9*#+]/g, '');
    if (!numero) { toast('Informe um número para discar.', 'warn'); return; }

    if (this.registro?.estado !== 'pronto') {
      this.ligarPeloAparelho(numero);
      return;
    }

    this.estado = 'chamando';
    this.direcao = 'saida';
    this.pintar();
    // A chamada só sai depois que o navegador libera o microfone.
    if (!await SipLink.ligar(numero)) {
      if (this.estado === 'chamando') this.limpar();
      toast('Não foi possível iniciar a chamada.', 'err');
    }
  },

  atender() { SipLink.atender(); },

  desligar() {
    SipLink.desligar();
    this.limpar();
  },

  /** Volta o aparelho ao repouso e anota a chamada no histórico. */
  limpar() {
    clearInterval(this.tid);
    this.tid = null;
    if (this.numero && this.direcao) {
      this.historico.unshift({
        dir: this.direcao === 'entrada' && this.estado !== 'em chamada' ? 'perdida' : this.direcao,
        num: this.numero, nome: this.nome, seg: this.estado === 'em chamada' ? this.seg : 0,
        quando: new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
      });
      this.historico = this.historico.slice(0, 30);
    }
    this.estado = 'idle'; this.numero = ''; this.nome = ''; this.direcao = '';
    this.midia = ''; this.consulta = null;
    this.mudo = this.espera = this.teclado = false;
    this.pintar();
    document.dispatchEvent(new CustomEvent('telium:fone-historico'));
  },

  fmt(s) { return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`; },

  /** Uma linha dizendo se dá para ligar, e por que não quando não dá. */
  textoRegistro() {
    const r = this.registro || {};
    const ramal = Auth.sessao?.softphone?.ramal;
    switch (r.estado) {
      case 'pronto':       return `Ramal ${ramal} registrado neste navegador`;
      case 'registrando':  return `Registrando o ramal ${ramal}…`;
      case 'erro':         return r.motivo || 'Falha no registro';
      case 'aparelho':     return r.motivo || 'Disca pelo seu telefone de mesa';
      case 'indisponivel': return r.motivo;
      default:             return 'Desconectado';
    }
  },

  /** Ícone do cabeçalho: só aparece para quem pode, e pulsa com chamada viva. */
  pintarIndicador() {
    const b = document.getElementById('phoneBtn');
    if (!b) return;
    b.hidden = !this.permitido();
    b.title = this.estado === 'recebendo' ? 'Chamada entrando' : this.estado !== 'idle' ? 'Em chamada' : 'Telefone';
    b.classList.toggle('fone-vivo', this.estado !== 'idle');
  },

  /** Chamada entrando com a pessoa em outra tela: um aviso no topo. */
  pintarAviso() {
    const a = document.getElementById('foneAviso');
    if (!a) return;
    if (this.estado !== 'recebendo' || this.naTela()) { a.innerHTML = ''; return; }
    a.innerHTML = `<div class="fone-aviso" role="alertdialog" aria-label="Chamada entrando">
      <span class="fone-aviso-ico">${icon('phoneIn','ico')}</span>
      <div class="grow"><b class="mono">${esc(this.numero)}</b>
        <div class="tiny">${esc(this.nome || 'Chamada entrando')}</div></div>
      <button class="btn btn-sm fone-recusar" data-av="recusar">${icon('phoneOff','ico ico-sm')} Recusar</button>
      <button class="btn btn-sm fone-atender" data-av="atender">${icon('phone','ico ico-sm')} Atender</button>
    </div>`;
    a.querySelector('[data-av="atender"]').onclick = () => { this.atender(); location.hash = '#/fone.webrtc'; };
    a.querySelector('[data-av="recusar"]').onclick = () => this.desligar();
  },

  pintar() {
    this.pintarIndicador();
    this.pintarAviso();
    const alvo = document.getElementById('foneTelefone');
    if (!alvo) return;

    const KEYS = [['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],
                  ['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']];
    const teclado = `<div class="sp-keys">${KEYS.map(([k, l]) =>
      `<button class="sp-key" data-k="${k}"><b>${k}</b>${l ? `<small>${l}</small>` : ''}</button>`).join('')}</div>`;
    const pronto = this.registro?.estado === 'pronto';

    let corpo = '';
    if (this.estado === 'idle') {
      corpo = `
        <div class="sp-display">
          <input class="sp-num" id="spNum" value="${esc(this.numero)}" placeholder="Digite o número" aria-label="Número"
                 inputmode="tel" autocomplete="off">
          <div class="sp-state">${esc(this.textoRegistro())}</div>
        </div>
        ${teclado}
        <div class="sp-actions">
          <button class="sp-call" id="spCall">${icon('phone','ico ico-sm')}
            ${pronto ? 'Ligar' : 'Ligar pelo meu telefone'}</button>
        </div>`;
    } else if (this.estado === 'recebendo') {
      corpo = `
        <div class="sp-display">
          <div class="sp-num">${esc(this.numero)}</div>
          <div class="sp-state">${esc(this.nome || 'Desconhecido')} · chamada recebida</div>
        </div>
        <div class="sp-actions">
          <button class="sp-hang" id="spHang">${icon('phoneOff','ico ico-sm')} Recusar</button>
          <button class="sp-call" id="spAnswer">${icon('phone','ico ico-sm')} Atender</button>
        </div>`;
    } else {
      const emChamada = this.estado === 'em chamada';
      corpo = `
        <div class="sp-peer">
          <span class="avatar avatar-sm">${esc((this.nome || this.numero).slice(0, 2).toUpperCase())}</span>
          <div class="grow"><b>${esc(this.numero)}</b><small>${esc(this.nome
            || (emChamada
                  ? (this.midia === 'falhou' ? 'Conectado, sem áudio'
                     : this.midia === 'instavel' ? 'Conectado, áudio instável' : 'Conectado')
                  : 'Chamando…'))}</small></div>
          ${emChamada ? `<span class="sp-timer" id="spTimer">${this.fmt(this.seg)}</span>`
                      : `<span class="badge badge-warn"><i class="dot dot-pulse"></i>Chamando</span>`}
        </div>
        <div class="sp-incall">
          <button class="sp-tool ${this.mudo ? 'on' : ''}" data-t="mudo" ${emChamada ? '' : 'disabled'}>${icon('mic','ico')}<span>${this.mudo ? 'Mudo' : 'Mutar'}</span></button>
          <button class="sp-tool ${this.espera ? 'on' : ''}" data-t="espera" ${emChamada ? '' : 'disabled'}>${icon('clock','ico')}<span>Espera</span></button>
          <button class="sp-tool" data-t="transf" ${emChamada ? '' : 'disabled'}>${icon('shuffle','ico')}<span>Transferir</span></button>
          <button class="sp-tool ${this.teclado ? 'on' : ''}" data-t="kpad" ${emChamada ? '' : 'disabled'}>${icon('grid','ico')}<span>Teclado</span></button>
        </div>
        ${this.teclado ? teclado : ''}
        <div class="sp-actions">
          <button class="sp-hang" id="spHang">${icon('phoneOff','ico ico-sm')} Desligar</button>
        </div>`;
    }

    alvo.innerHTML = corpo;

    alvo.querySelectorAll('.sp-key').forEach(b => b.onclick = () => this.tecla(b.dataset.k));
    const num = alvo.querySelector('#spNum');
    if (num) {
      num.oninput = e => this.numero = e.target.value;
      num.onkeydown = e => { if (e.key === 'Enter') this.ligar(); };
    }
    alvo.querySelector('#spCall')?.addEventListener('click', () => this.ligar());
    alvo.querySelector('#spAnswer')?.addEventListener('click', () => this.atender());
    alvo.querySelector('#spHang')?.addEventListener('click', () => this.desligar());
    alvo.querySelectorAll('.sp-tool').forEach(b => b.onclick = () => {
      const t = b.dataset.t;
      if (t === 'transf') { this.transferir(); return; }
      const chave = t === 'kpad' ? 'teclado' : t;
      this[chave] = !this[chave];
      if (t === 'mudo')   SipLink.mudo(this.mudo);
      if (t === 'espera') SipLink.espera(this.espera);
      this.pintar();
    });
    document.dispatchEvent(new CustomEvent('telium:fone-estado'));
  },

  /** Transferência a partir da lista de contatos ou ramais da tela. */
  transferirPara(destino) {
    const d = String(destino).replace(/[^0-9*#+]/g, '');
    if (!d || this.estado !== 'em chamada') return;
    if (!SipLink.transferir(d)) { toast('Não foi possível transferir.', 'err'); return; }
    toast(`Transferindo para ${d}…`);
  },

  transferir() {
    Drawer.open({
      titulo: 'Transferir chamada',
      sub: `Chamada com ${esc(this.numero)}`,
      corpo: `
        <div class="field" style="margin-bottom:16px">
          <label class="label" for="spDestTransf">Destino</label>
          <input class="input" id="spDestTransf" placeholder="Ramal, fila ou número externo"
                 inputmode="tel" autocomplete="off">
          <p class="tiny muted" style="margin-top:6px">
            <b>Transferir agora</b> passa a chamada e você sai na hora.
            <b>Falar antes</b> põe quem está na linha em espera, liga para o destino
            e só une as duas pontas quando você confirmar.</p>
        </div>
        <div id="spConsulta"></div>
        <div class="label" style="margin-bottom:8px">Ramais cadastrados</div>
        <div id="spRamais"><p class="small muted">Carregando…</p></div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-outline" id="spConsultar">Falar antes</button>
               <button class="btn btn-primary" id="spDoTransf">Transferir agora</button>`,
      aoAbrir: async dw => {
        this._dwTransf = dw;
        const campo = dw.querySelector('#spDestTransf');
        const limpo = () => campo.value.trim().replace(/[^0-9*#+]/g, '');

        dw.querySelector('#spDoTransf').onclick = () => {
          const destino = limpo();
          if (!destino) { toast('Informe o destino.', 'warn'); return; }
          if (!SipLink.transferir(destino)) { toast('Não foi possível transferir.', 'err'); return; }
          Drawer.close();
          toast(`Transferindo para ${destino}…`);
        };

        dw.querySelector('#spConsultar').onclick = async () => {
          const destino = limpo();
          if (!destino) { toast('Informe o destino.', 'warn'); return; }
          this.consulta = { estado: 'chamando', numero: destino };
          this.pintarConsulta();
          if (!await SipLink.consultar(destino)) {
            this.consulta = null;
            this.pintarConsulta();
            toast('Não foi possível chamar o destino.', 'err');
          }
        };

        const lista = dw.querySelector('#spRamais');
        try {
          const r = await Api.get('/ramais', { limite: 500 });
          lista.innerHTML = r.dados.length
            ? r.dados.map(x => `
                <div class="ura-node" style="margin-bottom:8px;cursor:pointer" data-ramal="${esc(x.numero)}">
                  <span class="avatar avatar-sm">${esc((x.nome || '?').slice(0, 2).toUpperCase())}</span>
                  <div class="grow"><b>${esc(x.numero)}</b> · ${esc(x.nome)}
                    <div class="tiny muted">${esc(x.setor || '')}</div></div>
                </div>`).join('')
            : '<p class="small muted">Nenhum ramal cadastrado.</p>';
          lista.querySelectorAll('[data-ramal]').forEach(el => el.onclick = () => {
            campo.value = el.dataset.ramal;
            campo.focus();
          });
        } catch (e) {
          lista.innerHTML = `<p class="small" style="color:var(--danger)">${esc(e.message)}</p>`;
        }
      },
      aoFechar: () => {
        if (SipLink.consultando()) SipLink.cancelarConsulta();
        this.consulta = null;
        this._dwTransf = null;
      }
    });
  },

  /** O trecho da gaveta que mostra a segunda perna da transferência. */
  pintarConsulta() {
    const dw = this._dwTransf;
    const alvo = dw?.querySelector('#spConsulta');
    if (!alvo) return;

    const c = this.consulta;
    const consultar = dw.querySelector('#spConsultar');
    const transferir = dw.querySelector('#spDoTransf');
    const campo = dw.querySelector('#spDestTransf');

    if (!c) {
      alvo.innerHTML = '';
      if (consultar) { consultar.hidden = false; consultar.disabled = false; }
      if (transferir) transferir.hidden = false;
      if (campo) campo.disabled = false;
      return;
    }

    if (consultar) consultar.hidden = true;
    if (transferir) transferir.hidden = true;
    if (campo) campo.disabled = true;

    const pronta = c.estado === 'atendida';
    alvo.innerHTML = `
      <div class="ura-node" style="margin-bottom:16px">
        <span class="avatar avatar-sm">${esc((c.numero || '?').slice(0, 2).toUpperCase())}</span>
        <div class="grow"><b>${esc(c.numero)}</b>
          <div class="tiny muted">${pronta
            ? 'Atendeu. Fale e confirme quando quiser passar a chamada.'
            : 'Chamando… quem estava na linha está em espera.'}</div></div>
      </div>
      <div class="sp-actions" style="margin-bottom:16px">
        <button class="btn btn-outline" id="spVoltar">Voltar para a chamada</button>
        <button class="btn btn-primary" id="spConcluir" ${pronta ? '' : 'disabled'}>Concluir transferência</button>
      </div>`;

    alvo.querySelector('#spVoltar').onclick = () => {
      SipLink.cancelarConsulta();
      this.consulta = null;
      this.pintarConsulta();
    };
    alvo.querySelector('#spConcluir').onclick = () => {
      if (!SipLink.completarTransferencia()) { toast('Não foi possível concluir a transferência.', 'err'); return; }
      this.consulta = null;
      Drawer.close();
    };
  }
};

/* ============================== PLAYER ============================== */
function playerHTML(id, dur = '04:12') {
  const barras = Array.from({ length: 48 }, (_, i) => {
    const h = 20 + Math.abs(Math.sin(i * 0.7) * 60) + (i % 5) * 4;
    return `<i style="height:${Math.min(100, h)}%" ${i < 14 ? 'class="on"' : ''}></i>`;
  }).join('');
  return `<div class="player" data-player="${id}">
    <button class="p-btn" aria-label="Reproduzir">${icon('play','ico ico-sm')}</button>
    <div class="wave">${barras}</div>
    <span class="p-time">01:12 / ${dur}</span>
    <button class="btn btn-ghost btn-sm btn-icon" data-tip="Baixar">${icon('download','ico ico-sm')}</button>
  </div>`;
}

/* ============================== SKELETON ============================== */
function skeletonPage() {
  return `<div class="content-inner">
    <div class="sk sk-line" style="width:220px;height:22px;margin-bottom:14px"></div>
    <div class="sk sk-line" style="width:420px;margin-bottom:24px"></div>
    <div class="grid g-4" style="margin-bottom:16px">
      ${'<div class="sk sk-card"></div>'.repeat(4)}
    </div>
    <div class="grid g-2-1">
      <div class="sk sk-card" style="height:320px"></div>
      <div class="sk sk-card" style="height:320px"></div>
    </div>
  </div>`;
}

/* ============================== MODO TV ============================== */
const TV = {
  entrar() {
    document.body.classList.add('tv-mode');
    const b = document.createElement('button');
    b.className = 'btn btn-outline btn-sm tv-exit'; b.id = 'tvExit';
    b.innerHTML = `${icon('x','ico ico-sm')} Sair do modo TV`;
    b.onclick = () => this.sair();
    document.body.appendChild(b);
    document.documentElement.requestFullscreen?.().catch(() => {});
  },
  sair() {
    document.body.classList.remove('tv-mode');
    document.getElementById('tvExit')?.remove();
    if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
  }
};
