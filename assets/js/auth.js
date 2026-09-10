/* =========================================================
   Telium PABX — Sessão, permissões e tema
   Protótipo de front-end: a validação real deve ocorrer no
   servidor. Aqui a sessão vive em sessionStorage/localStorage.
   ========================================================= */
const SESSION_KEY = 'telium.session';
const THEME_KEY   = 'telium.theme';

/* ---------------------------------------------------------
   Armazenamento tolerante a falhas.
   Abrindo as páginas por file:// alguns navegadores bloqueiam
   Web Storage; nesse caso usamos window.name como cofre da
   sessão (sobrevive à navegação dentro da mesma aba).
   --------------------------------------------------------- */
function makeStore(kind) {
  try {
    const s = window[kind];
    const probe = '__telium_probe__';
    s.setItem(probe, '1'); s.removeItem(probe);
    return s;
  } catch {
    return {
      _read() { try { return JSON.parse(window.name || '{}')[kind] || {}; } catch { return {}; } },
      _write(o) {
        let all = {}; try { all = JSON.parse(window.name || '{}'); } catch { all = {}; }
        all[kind] = o; window.name = JSON.stringify(all);
      },
      getItem(k) { const v = this._read()[k]; return v === undefined ? null : v; },
      setItem(k, v) { const o = this._read(); o[k] = String(v); this._write(o); },
      removeItem(k) { const o = this._read(); delete o[k]; this._write(o); }
    };
  }
}
const LS = makeStore('localStorage');
const SS = makeStore('sessionStorage');

const Auth = {
  /** Autentica contra a base demo. Retorna {ok, user} ou {ok:false, erro}. */
  login(usuario, senha, lembrar) {
    const u = USERS.find(x => x.user.toLowerCase() === String(usuario).trim().toLowerCase());
    if (!u || u.pass !== senha) return { ok: false, erro: 'Usuário ou senha inválidos.' };
    if (u.status === 'bloqueado') return { ok: false, erro: 'Usuário bloqueado. Procure o administrador.' };
    if (u.status === 'inativo')   return { ok: false, erro: 'Usuário inativo. Acesso não permitido.' };

    const sess = {
      user: u.user, name: u.name, role: u.role, ramal: u.ramal,
      email: u.email, setor: u.setor, desde: Date.now()
    };
    const store = lembrar ? LS : SS;
    store.setItem(SESSION_KEY, JSON.stringify(sess));
    (lembrar ? SS : LS).removeItem(SESSION_KEY);
    return { ok: true, user: sess };
  },

  /** Sessão corrente ou null. */
  current() {
    const raw = SS.getItem(SESSION_KEY) || LS.getItem(SESSION_KEY);
    try { return raw ? JSON.parse(raw) : null; } catch { return null; }
  },

  logout() {
    SS.removeItem(SESSION_KEY);
    LS.removeItem(SESSION_KEY);
  },

  /** Redireciona para o login se não houver sessão. */
  requireSession(redirect = 'index.html') {
    const s = this.current();
    if (!s) { location.replace(redirect); return null; }
    return s;
  },

  role(sess) { return ROLES[sess?.role] || ROLES.operador; },

  /** O perfil enxerga esta chave de menu? Suporta '*' e 'grupo.*'. */
  can(sess, key) {
    const allow = this.role(sess).allow || [];
    return allow.some(rule =>
      rule === '*' ||
      rule === key ||
      (rule.endsWith('.*') && key.startsWith(rule.slice(0, -1)))
    );
  },

  /** O perfil pode executar a ação (criar/editar/excluir/exportar/...)? */
  cap(sess, acao) { return (this.role(sess).caps || []).includes(acao); },

  /** Menu filtrado pelas permissões do perfil. */
  menuFor(sess) {
    return MENU
      .map(g => ({ ...g, items: g.items.filter(i => this.can(sess, i.id)) }))
      .filter(g => g.items.length);
  },

  /** Primeira rota disponível para o perfil. */
  homeFor(sess) {
    const m = this.menuFor(sess);
    return m.length ? m[0].items[0].id : null;
  }
};

/* ---------------- Tema claro / escuro ---------------- */
const Theme = {
  get() { return LS.getItem(THEME_KEY) || 'light'; },
  apply(t) {
    document.documentElement.setAttribute('data-theme', t);
    LS.setItem(THEME_KEY, t);
    document.querySelectorAll('[data-theme-icon]').forEach(el => {
      el.innerHTML = icon(t === 'dark' ? 'sun' : 'moon');
    });
  },
  toggle() { this.apply(this.get() === 'dark' ? 'light' : 'dark'); },
  init() { this.apply(this.get()); }
};

/* Aplica o tema o quanto antes para evitar "flash" de cor. */
document.documentElement.setAttribute('data-theme', LS.getItem(THEME_KEY) || 'light');
