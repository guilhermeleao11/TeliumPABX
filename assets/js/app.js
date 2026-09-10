/* =========================================================
   Telium PABX — shell: sessão, sidebar, roteador e cabeçalho
   ========================================================= */
const App = {
  menu: [],
  key: null,
  configPendente: false,

  async init() {
    Theme.init();

    // Sem sessão válida no servidor não há console.
    try {
      await Auth.carregar();
    } catch (e) {
      if (e.status === 401 || e.status === 0) { location.replace('index.html'); return; }
      document.getElementById('content').innerHTML =
        `<div class="content-inner">${blocoErro(e, 'ao carregar a sessão')}</div>`;
      return;
    }

    this.menu = Auth.menu();

    if (LS.getItem('telium.sidebar') === 'collapsed' && innerWidth > 900) {
      document.getElementById('app').classList.add('is-collapsed');
    }

    this.renderSidebar();
    this.renderUser();
    this.bindHeader();

    Softphone.montar();
    Palette.build();

    addEventListener('hashchange', () => this.route());
    await this.route();
    this.verificarConfig();
  },

  /* ---------------- Sidebar ---------------- */
  renderSidebar() {
    const nav = document.getElementById('sbNav');

    if (!this.menu.length) {
      nav.innerHTML = `<p class="small" style="padding:16px;color:var(--sb-text-dim)">
        Seu perfil não tem nenhum módulo liberado.</p>`;
      return;
    }

    nav.innerHTML = this.menu.map(g => `
      <div class="sb-group" data-group="${g.id}">
        <button class="sb-item" data-toggle="${g.id}">
          ${icon(g.icon)}<span class="lbl">${g.label}</span>${icon('chevronD', 'ico caret')}
        </button>
        <div class="sb-sub"><ul>
          ${g.items.map(i => `<li><a href="#/${i.id}" data-key="${i.id}">
            <span class="truncate">${i.label}</span></a></li>`).join('')}
        </ul></div>
        <div class="sb-flyout">
          <div class="fly-title">${g.label}</div>
          ${g.items.map(i => `<a href="#/${i.id}" data-key="${i.id}">${icon(i.icon || 'grid','ico ico-sm')}
            <span class="truncate">${i.label}</span></a>`).join('')}
        </div>
      </div>`).join('');

    nav.querySelectorAll('[data-toggle]').forEach(b => {
      b.onclick = () => {
        const g = b.closest('.sb-group');
        if (document.getElementById('app').classList.contains('is-collapsed') && innerWidth > 900) {
          location.hash = '#/' + this.menu.find(x => x.id === b.dataset.toggle).items[0].id;
          return;
        }
        const abrindo = !g.classList.contains('open');
        nav.querySelectorAll('.sb-group').forEach(x => x !== g && x.classList.remove('open'));
        g.classList.toggle('open', abrindo);
      };
    });

    const q = document.getElementById('sbSearch');
    q.addEventListener('input', () => {
      const t = q.value.trim().toLowerCase();
      nav.querySelectorAll('.sb-group').forEach(g => {
        let achou = 0;
        g.querySelectorAll('.sb-sub li').forEach(li => {
          const ok = !t || li.textContent.toLowerCase().includes(t);
          li.hidden = !ok; if (ok) achou++;
        });
        g.hidden = t && !achou;
        if (t && achou) g.classList.add('open');
      });
      if (!t) this.marcarAtivo();
    });

    document.getElementById('sbCollapse').onclick = () => {
      const app = document.getElementById('app');
      LS.setItem('telium.sidebar', app.classList.toggle('is-collapsed') ? 'collapsed' : 'open');
    };
  },

  marcarAtivo() {
    const nav = document.getElementById('sbNav');
    nav.querySelectorAll('a[data-key]').forEach(a =>
      a.classList.toggle('active', a.dataset.key === this.key));
    nav.querySelectorAll('.sb-group').forEach(g => {
      const dentro = !!g.querySelector(`a[data-key="${this.key}"]`);
      g.classList.toggle('open', dentro);
      g.querySelector('.sb-item').classList.toggle('active', dentro);
    });
  },

  /* ---------------- Cabeçalho ---------------- */
  renderUser() {
    const s = Auth.sessao;
    const cor = COR_PERFIL[s.perfil.chave] || 'brand';

    document.getElementById('userBtn').innerHTML = `
      <span class="avatar">${initials(s.nome)}</span>
      <span class="u-meta"><b>${esc(s.nome)}</b>
        <small>${esc(s.perfil.nome)}${s.ramal ? ' · ramal ' + esc(s.ramal) : ''}</small></span>
      ${icon('chevronD','ico ico-sm')}`;

    document.getElementById('userMenu').innerHTML = `
      <div class="dd-head">
        <div class="row gap-10">
          <span class="avatar">${initials(s.nome)}</span>
          <div class="grow" style="min-width:0">
            <b class="truncate" style="display:block">${esc(s.nome)}</b>
            <small class="muted truncate" style="display:block">${esc(s.email || s.usuario)}</small>
          </div>
        </div>
        <div class="row gap-6" style="margin-top:10px">
          <span class="badge badge-${cor}">${esc(s.perfil.nome)}</span>
          ${s.ramal ? `<span class="badge">Ramal ${esc(s.ramal)}</span>` : ''}
        </div>
      </div>
      <div class="dd-list">
        <button class="dd-item" id="ddTema">${icon('moon','ico ico-sm')} Alternar tema</button>
        <button class="dd-item" id="ddAtalhos">${icon('helpCircle','ico ico-sm')} Atalhos de teclado</button>
        <div class="dd-sep"></div>
        <button class="dd-item danger" id="ddSair">${icon('logout','ico ico-sm')} Encerrar sessão</button>
      </div>`;

    document.getElementById('ddSair').onclick = async () => {
      await Auth.logout();
      location.replace('index.html');
    };
    document.getElementById('ddTema').onclick = () => Theme.toggle();
    document.getElementById('ddAtalhos').onclick = () => this.atalhos();
  },

  /** Avisos reais: configuração pendente e saúde dos serviços. */
  async verificarConfig() {
    let estado = null;
    let saude = null;
    try { estado = await Api.get('/config/estado'); } catch { /* sem permissão ou fora do ar */ }
    // /health devolve 503 quando algum serviço está fora — e é justamente
    // esse corpo que queremos mostrar. O erro carrega o JSON já decodificado.
    try { saude = await Api.get('/health'); }
    catch (e) { saude = e.detalhe && e.detalhe.checagens ? e.detalhe : null; }

    this.configPendente = !!estado?.pendente;
    this.pintarStatus(saude);

    const avisos = [];
    if (this.configPendente) {
      avisos.push({
        tone: 'warn', ico: 'alert',
        titulo: 'Configuração pendente',
        texto: 'Há alterações que ainda não foram aplicadas no Asterisk.',
        acao: 'aplicar'
      });
    }
    Object.entries(saude?.checagens || {}).forEach(([nome, c]) => {
      if (!c.ok) {
        avisos.push({ tone: 'danger', ico: 'alert', titulo: `${nome} indisponível`, texto: c.detalhe });
      }
    });

    const badge = document.querySelector('#notifBtn .badge-dot');
    if (badge) badge.hidden = avisos.length === 0;

    document.getElementById('notifMenu').innerHTML = avisos.length ? `
      <div class="dd-head row-between"><b>Avisos</b>
        <span class="badge badge-${avisos.some(a => a.tone === 'danger') ? 'danger' : 'warn'}">${avisos.length}</span></div>
      ${avisos.map(a => `
        <div class="notif">
          <span class="n-ico" style="background:var(--${a.tone}-soft);color:var(--${a.tone})">${icon(a.ico,'ico ico-sm')}</span>
          <div class="grow"><b>${esc(a.titulo)}</b><p>${esc(a.texto)}</p>
            ${a.acao === 'aplicar' && Auth.cap('reiniciar')
              ? `<button class="btn btn-primary btn-sm" id="aplicarConfig" style="margin-top:8px">
                   ${icon('check','ico ico-sm')} Aplicar configurações</button>` : ''}
          </div>
        </div>`).join('')}`
      : `<div class="dd-head"><b>Avisos</b></div>
         <div class="empty" style="padding:26px">${icon('checkCirc')}<p>Nada pendente. Tudo em ordem.</p></div>`;

    document.getElementById('aplicarConfig')?.addEventListener('click', ev => this.aplicarConfig(ev.currentTarget));
  },

  /** Rodapé da sidebar: estado real do Asterisk, sem número fixo. */
  pintarStatus(saude) {
    const el = document.getElementById('sbStatus');
    if (!el) return;

    const ast = saude?.checagens?.asterisk;
    if (!ast) {
      el.innerHTML = `<i class="dot" style="color:var(--text-muted)"></i>
        <span class="grow"><b>Estado desconhecido</b></span>`;
      return;
    }

    if (!ast.ok) {
      el.innerHTML = `<i class="dot" style="color:var(--danger)"></i>
        <span class="grow"><b>Asterisk fora do ar</b><br>
        <span style="opacity:.7" class="truncate">${esc(ast.detalhe || '')}</span></span>`;
      return;
    }

    const versao = (ast.detalhe || '').replace(/^Asterisk\s*/i, '') || 'ativo';
    el.innerHTML = `<i class="dot dot-pulse" style="color:var(--ok)"></i>
      <span class="grow"><b>Asterisk ${esc(versao)}</b><br>
      <span style="opacity:.7">central respondendo</span></span>`;
  },

  async aplicarConfig(botao) {
    botao.disabled = true;
    botao.innerHTML = `<span class="spin"></span> Aplicando…`;
    try {
      const r = await Api.post('/config/aplicar');
      toast(r.aplicado
        ? 'Configuração aplicada no Asterisk.'
        : 'A configuração foi gerada, mas o Asterisk recusou parte da recarga.',
        r.aplicado ? 'ok' : 'warn');
      this.verificarConfig();
    } catch (e) {
      toast(e.message, 'err');
    } finally {
      botao.disabled = false;
      botao.innerHTML = `${icon('check','ico ico-sm')} Aplicar configurações`;
    }
  },

  bindHeader() {
    document.querySelectorAll('[data-dd]').forEach(btn => {
      btn.onclick = e => {
        e.stopPropagation();
        const alvo = document.getElementById(btn.dataset.dd);
        document.querySelectorAll('.dd-panel').forEach(p => p !== alvo && (p.hidden = true));
        alvo.hidden = !alvo.hidden;
      };
    });
    document.addEventListener('click', () =>
      document.querySelectorAll('.dd-panel').forEach(p => p.hidden = true));
    document.querySelectorAll('.dd-panel').forEach(p => p.onclick = e => e.stopPropagation());

    document.getElementById('themeBtn').onclick = () => Theme.toggle();
    document.getElementById('phoneBtn').onclick = () => Softphone.abrir();
    document.getElementById('paletteBtn').onclick = () => Palette.abrir();

    const app = document.getElementById('app'), scrim = document.getElementById('scrim');
    document.getElementById('hamburger').onclick = () => {
      app.classList.toggle('nav-open'); scrim.classList.toggle('on');
    };
    scrim.onclick = () => { app.classList.remove('nav-open'); scrim.classList.remove('on'); };

    const hq = document.getElementById('hSearch'), hres = document.getElementById('hResults');
    const buscar = () => {
      const t = hq.value.trim().toLowerCase();
      if (!t) { hres.hidden = true; return; }
      const achados = [];
      this.menu.forEach(g => g.items.forEach(i => {
        if (i.label.toLowerCase().includes(t) || i.id.includes(t)) achados.push({ i, g });
      }));
      hres.hidden = false;
      hres.innerHTML = achados.length
        ? achados.slice(0, 8).map(({ i, g }) =>
            `<a href="#/${i.id}">${icon(i.icon || 'grid','ico ico-sm')}<span>${i.label}</span>
             <span class="grp">${g.label}</span></a>`).join('')
        : `<div class="none">Nenhum módulo encontrado para “${esc(hq.value)}”.</div>`;
    };
    hq.addEventListener('input', buscar);
    hq.addEventListener('focus', buscar);
    hq.addEventListener('keydown', e => {
      if (e.key === 'Escape') { hq.value = ''; hres.hidden = true; hq.blur(); }
      if (e.key === 'Enter') { const a = hres.querySelector('a'); if (a) { a.click(); hq.value = ''; hres.hidden = true; } }
    });
    hres.onclick = () => { hq.value = ''; hres.hidden = true; };
    document.addEventListener('click', e => { if (!e.target.closest('.h-search')) hres.hidden = true; });

    addEventListener('keydown', e => {
      const digitando = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault(); Palette.aberta ? Palette.fechar() : Palette.abrir(); return;
      }
      if (digitando) return;
      if (e.key === '/') { e.preventDefault(); hq.focus(); }
      if (e.key === '[') document.getElementById('sbCollapse').click();
      if (e.key.toLowerCase() === 'd') { e.preventDefault(); Softphone.abrir(); }
      if (e.key === '?') { e.preventDefault(); this.atalhos(); }
    });
  },

  atalhos() {
    Drawer.open({
      titulo: 'Atalhos de teclado',
      corpo: `<div class="deflist">
        ${[['Ctrl / ⌘ + K', 'Paleta de comandos'], ['/', 'Focar a busca de módulos'],
           ['[', 'Recolher ou expandir o menu'], ['D', 'Abrir o softphone'],
           ['Esc', 'Fechar painéis e gavetas'], ['?', 'Mostrar esta ajuda']].map(([k, d]) => `
          <div class="defrow" style="grid-template-columns:150px 1fr;padding:11px 0">
            <div><kbd class="k">${k}</kbd></div><div class="small dim">${d}</div></div>`).join('')}
      </div>`
    });
  },

  /* ---------------- Roteador ---------------- */
  async route() {
    const key = (location.hash.replace(/^#\/?/, '') || Auth.home() || '');
    const grupo = MENU.find(g => g.items.some(i => i.id === key));
    const item = grupo && grupo.items.find(i => i.id === key);
    const alvo = document.getElementById('content');

    document.getElementById('app').classList.remove('nav-open');
    document.getElementById('scrim').classList.remove('on');

    if (!item) {
      const casa = Auth.home();
      if (casa) { location.replace('#/' + casa); }
      else { alvo.innerHTML = `<div class="content-inner">${paginaNegada(key || '—')}</div>`; }
      return;
    }

    this.key = key;
    document.title = `${item.label} · Telium PABX`;
    document.getElementById('crumbs').innerHTML =
      `<span class="c-item">${grupo.label}</span>${icon('chevronR','ico')}<span class="c-item cur">${item.label}</span>`;
    this.marcarAtivo();

    if (!Auth.can(key)) {
      alvo.innerHTML = `<div class="content-inner">${paginaNegada(key)}</div>`;
      return;
    }

    alvo.innerHTML = skeletonPage();

    const ctx = { sess: Auth.sessao, item, group: grupo, can: a => Auth.cap(a) };
    const page = PAGES[key];
    const meuTurno = ++this._turno;

    try {
      const html = page ? await page.render(ctx) : paginaGenerica(ctx);
      if (meuTurno !== this._turno) return;      // a rota mudou durante a busca
      alvo.innerHTML = `<div class="content-inner">${html}</div>`;
      if (page?.mount) page.mount(ctx);
    } catch (e) {
      if (meuTurno !== this._turno) return;
      alvo.innerHTML = `<div class="content-inner">${blocoErro(e, item.label)}</div>`;
    }

    if (key === 'dash.temporeal') {
      const bar = alvo.querySelector('.page-actions');
      if (bar) {
        const b = document.createElement('button');
        b.className = 'btn btn-outline btn-sm';
        b.innerHTML = `${icon('target','ico ico-sm')} Modo TV`;
        b.onclick = () => TV.entrar();
        bar.prepend(b);
      }
    }

    scrollTo({ top: 0 });
  },
  _turno: 0
};

/* ---------------- Toasts ---------------- */
function toast(msg, tipo = '') {
  const box = document.getElementById('toasts');
  const t = document.createElement('div');
  const ico = { ok: 'checkCirc', warn: 'alert', err: 'x' }[tipo] || 'info';
  t.className = `toast ${tipo}`;
  t.innerHTML = `${icon(ico,'ico ico-sm')}<span class="grow">${esc(msg)}</span>`;
  box.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; }, 4000);
  setTimeout(() => t.remove(), 4400);
}

document.addEventListener('DOMContentLoaded', () => App.init());
