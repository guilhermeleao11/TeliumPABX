/* =========================================================
   Telium PABX — sessão e permissões
   A verdade está no servidor; aqui guardamos só o resultado.
   ========================================================= */
const THEME_KEY = 'telium.theme';

/* Armazenamento tolerante: abrindo por file:// alguns navegadores
   bloqueiam Web Storage. */
function makeStore(kind) {
  try {
    const s = window[kind];
    s.setItem('__probe__', '1'); s.removeItem('__probe__');
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
  /** Preenchido por carregar() a partir de GET /api/me. */
  sessao: null,

  async login(usuario, senha, lembrar) {
    try {
      const r = await Api.post('/auth/login', { usuario, senha });

      if (r.precisa_2fa) return { ok: false, precisa2fa: true };

      Api.token = r.token;
      if (lembrar) {
        try { localStorage.setItem('telium.token', r.token); } catch { /* ignora */ }
      }
      return { ok: true, usuario: r.usuario };
    } catch (e) {
      return { ok: false, erro: e.message };
    }
  },

  /** Busca no servidor quem somos e o que podemos. */
  async carregar() {
    const dados = await Api.get('/me');
    this.sessao = {
      ...dados.usuario,
      allow: dados.permissoes.allow,
      caps: dados.permissoes.caps,
      empresa: dados.empresa,
      softphone: dados.softphone
    };
    return this.sessao;
  },

  async logout() {
    try { await Api.post('/auth/logout'); } catch { /* segue mesmo se falhar */ }
    Api.token = null;
    this.sessao = null;
  },

  /** O perfil enxerga esta chave de menu? Espelha a regra do servidor. */
  can(key) {
    const allow = this.sessao?.allow || [];
    return allow.some(regra =>
      regra === '*' ||
      regra === key ||
      (regra.endsWith('.*') && key.startsWith(regra.slice(0, -1)))
    );
  },

  /** O perfil pode executar a ação? Só esconde botão — o servidor decide. */
  cap(acao) { return (this.sessao?.caps || []).includes(acao); },

  /** Menu filtrado pelas permissões recebidas do servidor. */
  menu() {
    return MENU
      .map(g => ({ ...g, items: g.items.filter(i => this.can(i.id)) }))
      .filter(g => g.items.length);
  },

  home() {
    const m = this.menu();
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

document.documentElement.setAttribute('data-theme', LS.getItem(THEME_KEY) || 'light');
