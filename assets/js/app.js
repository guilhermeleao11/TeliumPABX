/* =========================================================
   Telium PABX — Shell: sidebar, roteador, cabeçalho
   ========================================================= */
const App = {
  sess: null,
  menu: [],
  key: null,

  init() {
    this.sess = Auth.requireSession();
    if (!this.sess) return;

    Theme.init();
    this.menu = Auth.menuFor(this.sess);

    if (LS.getItem('telium.sidebar') === 'collapsed' && innerWidth > 900) {
      document.getElementById('app').classList.add('is-collapsed');
    }

    this.renderSidebar();
    this.renderUser();
    this.renderNotifs();
    this.bindHeader();

    Softphone.montar();
    Palette.build(this.sess);

    addEventListener('hashchange', () => this.route());
    this.route();
  },

  /* ---------------- Sidebar ---------------- */
  renderSidebar() {
    const nav = document.getElementById('sbNav');
    nav.innerHTML = this.menu.map(g => `
      <div class="sb-group" data-group="${g.id}">
        <button class="sb-item" data-toggle="${g.id}">
          ${icon(g.icon)}
          <span class="lbl">${g.label}</span>
          <span class="badge-count"></span>
          ${icon('chevronD', 'ico caret')}
        </button>
        <div class="sb-sub">
          <ul>
            ${g.items.map(i => `<li><a href="#/${i.id}" data-key="${i.id}">
              <span class="truncate">${i.label}</span>
              ${i.pill ? `<span class="pill" style="margin-left:auto">${i.pill}</span>` : ''}
            </a></li>`).join('')}
          </ul>
        </div>
        <div class="sb-flyout">
          <div class="fly-title">${g.label}</div>
          ${g.items.map(i => `<a href="#/${i.id}" data-key="${i.id}">${icon(i.icon || 'grid','ico ico-sm')}
            <span class="truncate">${i.label}</span></a>`).join('')}
        </div>
      </div>`).join('');

    nav.querySelectorAll('[data-toggle]').forEach(b => {
      b.onclick = () => {
        const g = b.closest('.sb-group');
        const abrindo = !g.classList.contains('open');
        if (document.getElementById('app').classList.contains('is-collapsed') && innerWidth > 900) {
          location.hash = '#/' + this.menu.find(x => x.id === b.dataset.toggle).items[0].id;
          return;
        }
        nav.querySelectorAll('.sb-group').forEach(x => x !== g && x.classList.remove('open'));
        g.classList.toggle('open', abrindo);
      };
    });

    // busca lateral
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

    // recolher / expandir
    document.getElementById('sbCollapse').onclick = () => {
      const app = document.getElementById('app');
      const col = app.classList.toggle('is-collapsed');
      LS.setItem('telium.sidebar', col ? 'collapsed' : 'open');
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
    const s = this.sess, r = Auth.role(s);
    document.getElementById('userBtn').innerHTML = `
      <span class="avatar">${s.name.split(' ').map(n => n[0]).slice(0,2).join('')}</span>
      <span class="u-meta"><b>${s.name}</b><small>${r.label} · ramal ${s.ramal}</small></span>
      ${icon('chevronD','ico ico-sm')}`;

    document.getElementById('userMenu').innerHTML = `
      <div class="dd-head">
        <div class="row gap-10">
          <span class="avatar">${s.name.split(' ').map(n => n[0]).slice(0,2).join('')}</span>
          <div class="grow" style="min-width:0">
            <b class="truncate" style="display:block">${s.name}</b>
            <small class="muted truncate" style="display:block">${s.email}</small>
          </div>
        </div>
        <div class="row gap-6" style="margin-top:10px">
          <span class="badge badge-${r.color}">${r.label}</span>
          <span class="badge">Ramal ${s.ramal}</span>
        </div>
      </div>
      <div class="dd-list">
        <a class="dd-item" href="#/pcu.meuramal">${icon('user','ico ico-sm')} Meu ramal</a>
        <a class="dd-item" href="#/pcu.chamadas">${icon('list','ico ico-sm')} Minhas chamadas</a>
        <button class="dd-item" id="ddSenha">${icon('key','ico ico-sm')} Alterar senha</button>
        <button class="dd-item" id="ddTema">${icon('moon','ico ico-sm')} Alternar tema</button>
        <div class="dd-sep"></div>
        <button class="dd-item danger" id="ddSair">${icon('logout','ico ico-sm')} Encerrar sessão</button>
      </div>`;

    document.getElementById('ddSair').onclick = () => { Auth.logout(); location.replace('index.html'); };
    document.getElementById('ddTema').onclick = () => Theme.toggle();
    document.getElementById('ddSenha').onclick = () => toast('Alteração de senha — integrar ao backend.', 'ok');
  },

  renderNotifs() {
    document.getElementById('notifMenu').innerHTML = `
      <div class="dd-head row-between"><b>Notificações</b>
        <span class="badge badge-danger">${DEMO.notificacoes.length}</span></div>
      ${DEMO.notificacoes.map(n => `
        <div class="notif">
          <span class="n-ico" style="background:var(--${n.tone}-soft);color:var(--${n.tone})">${icon(n.ico,'ico ico-sm')}</span>
          <div class="grow"><b>${n.titulo}</b><p>${n.texto}</p></div>
          <time>${n.tempo}</time>
        </div>`).join('')}
      <div class="dd-list"><button class="dd-item center" style="justify-content:center">Marcar todas como lidas</button></div>`;
  },

  bindHeader() {
    // dropdowns
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

    // menu mobile
    const app = document.getElementById('app'), scrim = document.getElementById('scrim');
    document.getElementById('hamburger').onclick = () => {
      app.classList.toggle('nav-open'); scrim.classList.toggle('on');
    };
    scrim.onclick = () => { app.classList.remove('nav-open'); scrim.classList.remove('on'); };

    // busca global
    const hq = document.getElementById('hSearch'), hres = document.getElementById('hResults');
    const buscar = () => {
      const t = hq.value.trim().toLowerCase();
      if (!t) { hres.hidden = true; return; }
      const achados = [];
      this.menu.forEach(g => g.items.forEach(i => {
        if (i.label.toLowerCase().includes(t) || i.id.includes(t))
          achados.push({ i, g });
      }));
      hres.hidden = false;
      hres.innerHTML = achados.length
        ? achados.slice(0, 8).map(({ i, g }) =>
            `<a href="#/${i.id}">${icon(i.icon || 'grid','ico ico-sm')}<span>${i.label}</span>
             <span class="grp">${g.label}</span></a>`).join('')
        : `<div class="none">Nenhum módulo encontrado para “${hq.value}”.</div>`;
    };
    hq.addEventListener('input', buscar);
    hq.addEventListener('focus', buscar);
    hq.addEventListener('keydown', e => {
      if (e.key === 'Escape') { hq.value = ''; hres.hidden = true; hq.blur(); }
      if (e.key === 'Enter') { const a = hres.querySelector('a'); if (a) { a.click(); hq.value = ''; hres.hidden = true; } }
    });
    hres.onclick = () => { hq.value = ''; hres.hidden = true; };
    document.addEventListener('click', e => { if (!e.target.closest('.h-search')) hres.hidden = true; });

    // atalhos de teclado
    addEventListener('keydown', e => {
      const digitando = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault(); Palette.aberta ? Palette.fechar() : Palette.abrir(); return;
      }
      if (digitando) return;
      if (e.key === '/') { e.preventDefault(); hq.focus(); }
      if (e.key === '[') document.getElementById('sbCollapse').click();
      if (e.key.toLowerCase() === 'd') { e.preventDefault(); Softphone.abrir(); }
      if (e.key === '?') { e.preventDefault(); App.atalhos(); }
    });
  },

  atalhos() {
    Drawer.open({
      titulo: 'Atalhos de teclado', sub: 'Para navegar sem tirar as mãos do teclado',
      corpo: `<div class="deflist">
        ${[['Ctrl / ⌘ + K', 'Paleta de comandos — módulos, ramais, contatos e ações'],
           ['/', 'Focar a busca de módulos'],
           ['[', 'Recolher ou expandir o menu lateral'],
           ['D', 'Abrir o softphone'],
           ['Esc', 'Fechar painéis, gavetas e a paleta'],
           ['?', 'Mostrar esta ajuda']].map(([k, d]) => `
          <div class="defrow" style="grid-template-columns:150px 1fr;padding:11px 0">
            <div><kbd class="k">${k}</kbd></div><div class="small dim">${d}</div></div>`).join('')}
      </div>`
    });
  },

  /* ---------------- Roteador ---------------- */
  route() {
    const key = (location.hash.replace(/^#\/?/, '') || Auth.homeFor(this.sess) || '');
    const grupo = MENU.find(g => g.items.some(i => i.id === key));
    const item  = grupo && grupo.items.find(i => i.id === key);
    const alvo  = document.getElementById('content');

    document.getElementById('app').classList.remove('nav-open');
    document.getElementById('scrim').classList.remove('on');

    if (!item) { location.replace('#/' + Auth.homeFor(this.sess)); return; }

    this.key = key;
    document.title = `${item.label} · Telium PABX`;

    // trilha
    document.getElementById('crumbs').innerHTML =
      `<span class="c-item">${grupo.label}</span>${icon('chevronR','ico')}<span class="c-item cur">${item.label}</span>`;

    if (!Auth.can(this.sess, key)) {
      alvo.innerHTML = `<div class="content-inner">${paginaNegada(this.sess, key)}</div>`;
      this.marcarAtivo();
      return;
    }

    const ctx = { sess: this.sess, item, group: grupo, can: a => Auth.cap(this.sess, a) };
    const page = PAGES[key];

    // estado de carregamento (o backend real substituirá o setTimeout por fetch)
    alvo.innerHTML = skeletonPage();
    this.marcarAtivo();
    clearTimeout(this._render);
    this._render = setTimeout(() => {
      alvo.innerHTML = `<div class="content-inner">${page ? page.render(ctx) : paginaGenerica(ctx)}</div>`;
      if (page && page.mount) page.mount(ctx);
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
    }, 160);
  }
};

/* ---------------- Toasts ---------------- */
function toast(msg, tipo = '') {
  const box = document.getElementById('toasts');
  const t = document.createElement('div');
  const ico = { ok: 'checkCirc', warn: 'alert', err: 'x' }[tipo] || 'info';
  t.className = `toast ${tipo}`;
  t.innerHTML = `${icon(ico,'ico ico-sm')}<span class="grow">${msg}</span>`;
  box.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; }, 3200);
  setTimeout(() => t.remove(), 3600);
}

document.addEventListener('DOMContentLoaded', () => App.init());
