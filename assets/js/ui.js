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

/* ============================== SOFTPHONE ============================== */
const Softphone = {
  aberto: false, estado: 'idle', numero: '', nome: '', seg: 0, tid: null,
  mudo: false, espera: false, gravando: false, teclado: false,
  /* Preenchido pelas chamadas feitas na própria sessão. */
  historico: [],

  montar() {
    if (!document.getElementById('spFab')) {
      const fab = document.createElement('button');
      fab.id = 'spFab'; fab.className = 'sp-fab';
      // O rótulo depende do que ele vai realmente fazer: com o telefone
      // pelo navegador desligado, este botão liga pelo aparelho de mesa.
      const peloNavegador = Auth.sessao?.softphone?.modo === 'navegador';
      fab.title = peloNavegador ? 'Telefone pelo navegador' : 'Discador — liga pelo seu telefone';
      fab.setAttribute('aria-label', 'Abrir o discador');
      fab.innerHTML = icon('headset');
      fab.onclick = () => this.abrir();
      document.body.appendChild(fab);
    }
    const dock = document.createElement('div');
    dock.id = 'spDock'; document.body.appendChild(dock);

    this.conectar();
  },

  /** Registra o ramal do usuário, se ele tiver um marcado como WebRTC. */
  conectar() {
    const cfg = Auth.sessao?.softphone;
    this.modo = cfg?.modo || (cfg?.disponivel ? 'navegador' : 'nenhum');

    this.registro = cfg?.disponivel
      ? { estado: 'registrando', motivo: '' }
      : { estado: this.modo === 'aparelho' ? 'aparelho' : 'indisponivel',
          motivo: cfg?.motivo || 'A sua conta não está vinculada a nenhum ramal.' };

    // Sem softphone no navegador não há nada a registrar — e nada de
    // errado: o discador liga pelo telefone de mesa, que é o caminho que
    // não depende de NAT, de TURN nem de certificado.
    if (!cfg?.disponivel) { this.pintar(); return; }

    SipLink.ao((evento, dados) => this.doSip(evento, dados));
    SipLink.iniciar({
      // O endereço sai da própria origem da página, e não do hostname
      // configurado no servidor: quem abre o console pelo IP tentaria
      // abrir o WebSocket num nome que a máquina dele não resolve, e o
      // registro falharia sem dizer por quê. Pela origem, o certificado
      // também já é o que o navegador aceitou para entrar aqui.
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
      const novo = !this.aberto;
      this.aberto = true;
      this.pintar(novo);
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
    // Áudio que não passa pela rede: a chamada fica de pé e muda, e sem
    // este aviso o usuário só vê o cronômetro correr.
    if (evento === 'midia') {
      this.midia = dados.estado;
      if (dados.estado === 'falhou') toast(dados.motivo, 'err');
      if (dados.estado === 'instavel') toast(dados.motivo, 'warn');
      // Autoplay recusado: um clique em qualquer lugar da página libera,
      // e é isso que a frase pede. Sem ela a chamada fica muda sem motivo.
      if (dados.estado === 'bloqueado') toast(dados.motivo, 'warn');
      this.pintar();
      return;
    }
    // O que a central respondeu ao REFER. "Transferindo" é o que se sabe
    // na hora de mandar; se deu certo, só o NOTIFY diz.
    if (evento === 'transferencia') {
      toast(dados.ok ? 'Transferência concluída.' : dados.motivo, dados.ok ? 'ok' : 'err');
      return;
    }
    // A segunda perna da transferência com consulta.
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

  abrir() { const novo = !this.aberto; this.aberto = true; this.pintar(novo); },
  fechar() { if (this.estado !== 'idle') { toast('Encerre a chamada antes de fechar o discador.', 'warn'); return; }
             this.aberto = false; this.pintar(); },

  discarPara(num, nome = '') {
    const novo = !this.aberto;
    this.numero = num; this.nome = nome; this.aberto = true;
    this.pintar(novo); this.ligar();
  },

  /** Click-to-call: a central origina, o aparelho de mesa toca. */
  async ligarPeloAparelho(destino) {
    if (!destino) { toast('Informe um número para discar.', 'warn'); return; }
    try {
      const r = await Api.post('/discar', { destino });
      toast(r.mensagem, 'ok');
      this.numero = '';
      this.aberto = false;
      this.pintar();
    } catch (e) {
      // 422 é a conta sem ramal vinculado — a frase do servidor já
      // explica, e repetir "erro ao discar" antes dela só atrapalha.
      toast(e.status === 422 || e.status === 502
        ? e.message
        : (this.registro?.motivo || 'Não foi possível originar a chamada.'), 'err');
    }
  },

  tecla(t) {
    if (this.estado === 'em chamada') { SipLink.dtmf(t); return; }
    this.numero += t;
    this.visor();            // atualiza só o campo — redesenhar tudo causava piscada
  },

  /** Reflete this.numero no visor sem reconstruir o softphone. */
  visor() {
    const el = document.getElementById('spNum');
    if (!el) { this.pintar(); return; }
    el.value = this.numero;
    el.focus();
    el.setSelectionRange(el.value.length, el.value.length);
  },

  ligar() {
    if (!this.numero) { toast('Informe um número para discar.', 'warn'); return; }

    // Sem softphone pronto no navegador, quem toca é o aparelho de mesa:
    // a central liga para o ramal e, quando ele atende, disca o destino.
    // Antes o botão "Ligar" simplesmente não fazia nada para quem não usa
    // o telefone do navegador — que é a maioria de quem tem aparelho.
    if (this.registro?.estado !== 'pronto') {
      this.ligarPeloAparelho(this.numero.replace(/[^0-9*#+]/g, ''));
      return;
    }

    // A chamada só sai depois que o navegador libera o microfone, e isso
    // é uma pergunta ao usuário: o estado só muda quando o SIP avisa.
    if (!SipLink.ligar(this.numero.replace(/[^0-9*#+]/g, ''))) {
      toast('Não foi possível iniciar a chamada.', 'err');
      return;
    }
    this.estado = 'chamando';
    this.pintar();
  },

  atender() {
    SipLink.atender();
  },

  desligar() {
    SipLink.desligar();
    this.limpar();
  },

  /** Volta o discador ao repouso, sem mexer na camada SIP. */
  limpar() {
    clearInterval(this.tid);
    this.tid = null;
    if (this.estado === 'em chamada' && this.numero) {
      this.historico.unshift({
        dir: 'saida', num: this.numero,
        quando: new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
      });
    }
    this.estado = 'idle'; this.numero = ''; this.nome = '';
    this.midia = ''; this.consulta = null;
    this.mudo = this.espera = this.gravando = this.teclado = false;
    this.pintar();
  },

  fmt(s) { return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`; },

  /** Uma linha dizendo se dá para ligar, e por que não quando não dá. */
  textoRegistro() {
    const r = this.registro || {};
    const ramal = Auth.sessao?.softphone?.ramal;
    switch (r.estado) {
      case 'pronto':       return `Ramal ${ramal} registrado`;
      case 'registrando':  return `Registrando o ramal ${ramal}…`;
      case 'erro':         return r.motivo || 'Falha no registro';
      // Não é falha: é o modo normal de quem usa telefone de mesa.
      case 'aparelho':     return 'Disca pelo seu telefone de mesa';
      case 'indisponivel': return r.motivo;
      default:             return 'Discador desconectado';
    }
  },

  pintar(animar = false) {
    const dock = document.getElementById('spDock');
    const fab  = document.getElementById('spFab');
    if (!dock) return;
    fab.hidden = this.aberto;
    fab.innerHTML = icon('headset') + (this.estado !== 'idle' ? '<span class="ring"></span>' : '');
    if (!this.aberto) { dock.innerHTML = ''; return; }

    const KEYS = [['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],
                  ['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']];
    const teclado = `<div class="sp-keys">${KEYS.map(([k, l]) =>
      `<button class="sp-key" data-k="${k}"><b>${k}</b>${l ? `<small>${l}</small>` : ''}</button>`).join('')}</div>`;

    let corpo = '';
    if (this.estado === 'idle') {
      corpo = `
        <div class="sp-display">
          <input class="sp-num" id="spNum" value="${this.numero}" placeholder="Digite o número" aria-label="Número">
          <div class="sp-state">${this.modo === 'aparelho'
            ? 'A central chama o seu ramal e completa a ligação quando você atender.'
            : this.textoRegistro()}</div>
        </div>
        ${teclado}
        <div class="sp-actions">
          <button class="sp-call" id="spCall">${icon('phone','ico ico-sm')}
            ${this.modo === 'navegador' && this.registro?.estado === 'pronto'
              ? 'Ligar' : 'Ligar pelo meu telefone'}</button>
        </div>`;
    } else if (this.estado === 'recebendo') {
      corpo = `
        <div class="sp-display">
          <div class="sp-num">${this.numero}</div>
          <div class="sp-state">${this.nome || 'Desconhecido'} · chamada recebida</div>
        </div>
        <div class="sp-actions">
          <button class="sp-hang" id="spHang">${icon('phoneOff','ico ico-sm')} Recusar</button>
          <button class="sp-call" id="spAnswer">${icon('phone','ico ico-sm')} Atender</button>
        </div>`;
    } else {
      const emChamada = this.estado === 'em chamada';
      corpo = `
        <div class="sp-peer">
          <span class="avatar avatar-sm">${(this.nome || this.numero).slice(0, 2).toUpperCase()}</span>
          <div class="grow"><b>${this.numero}</b><small>${this.nome
            || (emChamada
                  ? (this.midia === 'falhou'
                      ? 'Conectado, sem áudio'
                      : this.midia === 'instavel' ? 'Conectado, áudio instável' : 'Conectado')
                  : 'Chamando…')}</small></div>
          ${emChamada ? `<span class="sp-timer" id="spTimer">${this.fmt(this.seg)}</span>`
                      : `<span class="badge badge-warn"><i class="dot dot-pulse"></i>Chamando</span>`}
        </div>
        <div class="sp-incall">
          <button class="sp-tool ${this.mudo ? 'on' : ''}" data-t="mudo">${icon('mic','ico')}<span>Mudo</span></button>
          <button class="sp-tool ${this.espera ? 'on' : ''}" data-t="espera">${icon('clock','ico')}<span>Espera</span></button>
          <button class="sp-tool" data-t="transf">${icon('shuffle','ico')}<span>Transf.</span></button>
          <button class="sp-tool ${this.teclado ? 'on' : ''}" data-t="kpad">${icon('grid','ico')}<span>Teclado</span></button>
        </div>
        ${this.teclado ? teclado : ''}
        <div class="sp-actions">
          <button class="sp-hang" id="spHang">${icon('phoneOff','ico ico-sm')} Desligar</button>
        </div>`;
    }

    dock.innerHTML = `
      <section class="softphone${animar ? ' sp-enter' : ''}" aria-label="Discador">
        <div class="sp-head">
          ${icon('headset','ico')}
          <div class="grow"><div class="sp-title">${this.modo === 'navegador' ? 'Softphone' : 'Discador'}</div>
            <div class="sp-sub">${this.textoRegistro()}</div></div>
          <button class="icon-btn" id="spClose" title="Fechar">${icon('x','ico ico-sm')}</button>
        </div>
        <div class="sp-body">${corpo}</div>
        ${this.estado === 'idle' && this.historico.length ? `<div class="sp-history">
          ${this.historico.slice(0, 4).map(h => `
            <div class="sp-hist-item" data-num="${h.num}">
              ${icon(h.dir === 'saida' ? 'arrowUp' : h.dir === 'perdida' ? 'phoneOff' : 'arrowDown', 'ico ico-sm')}
              <span style="color:var(--${h.dir === 'perdida' ? 'danger' : 'text'})">${h.num}</span>
              <time>${h.quando}</time>
            </div>`).join('')}
        </div>` : ''}
      </section>`;

    // eventos
    dock.querySelector('#spClose').onclick = () => this.fechar();
    dock.querySelectorAll('.sp-key').forEach(b => b.onclick = () => this.tecla(b.dataset.k));
    const num = dock.querySelector('#spNum');
    if (num) num.oninput = e => this.numero = e.target.value;
    dock.querySelector('#spCall')?.addEventListener('click', () => this.ligar());
    dock.querySelector('#spAnswer')?.addEventListener('click', () => this.atender());
    dock.querySelector('#spHang')?.addEventListener('click', () => this.desligar());
    dock.querySelectorAll('.sp-hist-item').forEach(el =>
      el.onclick = () => { this.numero = el.dataset.num; this.visor(); });
    dock.querySelectorAll('.sp-tool').forEach(b => b.onclick = () => {
      const t = b.dataset.t;
      if (t === 'transf') { this.transferir(); return; }
      this[t === 'kpad' ? 'teclado' : t] = !this[t === 'kpad' ? 'teclado' : t];
      if (t === 'mudo')   SipLink.mudo(this.mudo);
      if (t === 'espera') SipLink.espera(this.espera);
      this.pintar();
    });
  },

  transferir() {
    Drawer.open({
      titulo: 'Transferir chamada',
      sub: `Chamada com ${this.numero}`,
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

        dw.querySelector('#spConsultar').onclick = () => {
          const destino = limpo();
          if (!destino) { toast('Informe o destino.', 'warn'); return; }
          if (!SipLink.consultar(destino)) { toast('Não foi possível chamar o destino.', 'err'); return; }
          this.consulta = { estado: 'chamando', numero: destino };
          this.pintarConsulta();
        };

        const lista = dw.querySelector('#spRamais');
        try {
          const r = await Api.get('/ramais', { limite: 200 });
          lista.innerHTML = r.dados.length
            ? r.dados.map(x => `
                <div class="ura-node" style="margin-bottom:8px;cursor:pointer" data-ramal="${x.numero}">
                  <span class="avatar avatar-sm">${(x.nome || '?').slice(0, 2).toUpperCase()}</span>
                  <div class="grow"><b>${x.numero}</b> · ${x.nome}
                    <div class="tiny muted">${x.setor || ''}</div></div>
                </div>`).join('')
            : '<p class="small muted">Nenhum ramal cadastrado.</p>';
          lista.querySelectorAll('[data-ramal]').forEach(el => el.onclick = () => {
            campo.value = el.dataset.ramal;
            campo.focus();
          });
        } catch (e) {
          lista.innerHTML = `<p class="small" style="color:var(--danger)">${e.message}</p>`;
        }
      },
      aoFechar: () => {
        // Fechar a gaveta no meio de uma consulta deixaria a segunda
        // chamada viva sem nenhum botão para encerrá-la.
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
        <span class="avatar avatar-sm">${(c.numero || '?').slice(0, 2).toUpperCase()}</span>
        <div class="grow"><b>${c.numero}</b>
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
