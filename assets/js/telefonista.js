/* =========================================================
   Telium PABX — Mesa da Telefonista (Telefonista › Mesa)
   Todos os ramais da central ao vivo: livre, tocando, em
   chamada, em espera, fora do ar e não perturbe.
   A tela é desenhada uma vez; a cada 3 s só os cartões mudam,
   e a busca não perde o foco nem o que foi digitado.
   ========================================================= */

const ESTADO_MESA = {
  disponivel:   { tom: 'ok',     rotulo: 'Disponível' },
  tocando:      { tom: 'warn',   rotulo: 'Tocando' },
  chamando:     { tom: 'info',   rotulo: 'Chamando' },
  emchamada:    { tom: 'info',   rotulo: 'Em chamada' },
  espera:       { tom: 'warn',   rotulo: 'Em espera' },
  offline:      { tom: '',       rotulo: 'Offline' },
  desconhecido: { tom: '',       rotulo: 'Sem leitura' }
};

PAGES['tel.mesa'] = {
  _filtro: 'todos',
  _busca: '',
  _setor: '',
  _dados: null,
  _base: 0,

  async render(ctx) {
    this._ctx = ctx;
    let d;
    try { d = await Api.get('/telefonista/ramais'); }
    catch (e) { return pageHead('Mesa da Telefonista', '') + blocoErro(e); }
    this._dados = d;
    this._base = Date.now();

    const setores = [...new Set(d.ramais.map(r => r.setor).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'));

    return pageHead('Mesa da Telefonista',
      'Todos os ramais da central, ao vivo. Clique num ramal para ligar ou passar a sua ligação para ele.',
      `<span class="badge badge-ok" id="mesaVivo"><i class="dot dot-pulse"></i>Ao vivo</span>`) + `
      <div class="mesa-resumo" id="mesaResumo"></div>
      <div class="card" style="margin-bottom:14px">
        <div class="card-body row gap-10" style="flex-wrap:wrap;align-items:center">
          <div class="mesa-busca">
            ${icon('search', 'ico ico-sm')}
            <input class="input" id="mesaBusca" type="search" placeholder="Buscar por ramal, nome ou setor"
                   value="${esc(this._busca)}" autocomplete="off">
          </div>
          ${setores.length ? `<select class="input" id="mesaSetor" style="width:auto;min-width:160px">
              <option value="">Todos os setores</option>
              ${setores.map(s => `<option ${s === this._setor ? 'selected' : ''}>${esc(s)}</option>`).join('')}
            </select>` : ''}
          <span class="grow"></span>
          <span class="small muted" id="mesaEu"></span>
        </div>
      </div>
      <div id="mesaAviso"></div>
      <div class="mesa-grade" id="mesaGrade"></div>`;
  },

  mount() {
    this.pintar();

    const busca = document.getElementById('mesaBusca');
    busca?.addEventListener('input', () => { this._busca = busca.value; this.pintarGrade(); });
    document.getElementById('mesaSetor')?.addEventListener('change', e => {
      this._setor = e.target.value; this.pintarGrade();
    });
    document.getElementById('mesaResumo').addEventListener('click', e => {
      const b = e.target.closest('[data-filtro]');
      if (!b) return;
      this._filtro = this._filtro === b.dataset.filtro ? 'todos' : b.dataset.filtro;
      this.pintar();
    });
    document.getElementById('mesaGrade').addEventListener('click', e => this.clique(e));

    clearInterval(this._t);
    clearInterval(this._relogio);
    this._t = setInterval(() => {
      if (!location.hash.includes('tel.mesa')) { clearInterval(this._t); return; }
      this.atualizar();
    }, 3000);
    // O tempo de cada ligação anda entre uma leitura e outra.
    this._relogio = setInterval(() => {
      if (!location.hash.includes('tel.mesa')) { clearInterval(this._relogio); return; }
      const passou = Math.floor((Date.now() - this._base) / 1000);
      document.querySelectorAll('#mesaGrade [data-dur]').forEach(el => {
        el.textContent = duracaoMesa(Number(el.dataset.dur) + passou);
      });
    }, 1000);
  },

  async atualizar() {
    if (this._ocupado) return;
    this._ocupado = true;
    try {
      this._dados = await Api.get('/telefonista/ramais');
      this._base = Date.now();
      this.pintar();
    } catch (e) {
      const v = document.getElementById('mesaVivo');
      if (v) { v.className = 'badge badge-danger'; v.innerHTML = '<i class="dot"></i>Sem conexão'; }
    } finally {
      this._ocupado = false;
    }
  },

  pintar() {
    const d = this._dados;
    if (!d || !document.getElementById('mesaGrade')) return;

    const v = document.getElementById('mesaVivo');
    if (v) {
      v.className = d.asterisk ? 'badge badge-ok' : 'badge badge-danger';
      v.innerHTML = d.asterisk ? '<i class="dot dot-pulse"></i>Ao vivo' : '<i class="dot"></i>Asterisk não respondeu';
    }

    const conta = { todos: d.ramais.length, disponivel: 0, ocupado: 0, tocando: 0, dnd: 0, offline: 0 };
    for (const r of d.ramais) {
      const g = grupoMesa(r);
      if (g in conta) conta[g]++;
      if (r.dnd) conta.dnd++;
    }
    const caixas = [
      ['todos', 'Todos', ''], ['disponivel', 'Disponíveis', 'ok'], ['ocupado', 'Em chamada', 'info'],
      ['tocando', 'Tocando', 'warn'], ['dnd', 'Não perturbe', 'danger'], ['offline', 'Offline', '']
    ];
    document.getElementById('mesaResumo').innerHTML = caixas.map(([k, rot, tom]) => `
      <button class="mesa-cont ${tom ? 'tom-' + tom : ''} ${this._filtro === k ? 'on' : ''}" data-filtro="${k}"
              aria-pressed="${this._filtro === k}">
        <b class="num">${conta[k]}</b><span>${rot}</span></button>`).join('');

    const eu = document.getElementById('mesaEu');
    if (eu) {
      eu.innerHTML = d.eu
        ? `Seu ramal: <b class="mono">${esc(d.eu)}</b>${d.em_chamada
            ? ' · <span class="badge badge-info"><i class="dot dot-pulse"></i>em ligação: clique em Transferir no ramal de destino</span>' : ''}`
        : 'Sua conta não tem ramal vinculado: dá para ver e mexer no não perturbe, mas não para ligar nem transferir.';
    }

    document.getElementById('mesaAviso').innerHTML = d.asterisk ? '' : `
      <div class="card" style="margin-bottom:14px">${vazio('alert', 'Sem comunicação com o Asterisk',
        `Os ramais aparecem sem estado até a central responder. ${esc(d.asterisk_erro || '')}`)}</div>`;

    this.pintarGrade();
  },

  pintarGrade() {
    const d = this._dados;
    const grade = document.getElementById('mesaGrade');
    if (!d || !grade) return;

    const t = this._busca.trim().toLowerCase();
    const lista = d.ramais.filter(r => {
      if (this._setor && r.setor !== this._setor) return false;
      if (this._filtro === 'dnd' ? !r.dnd : (this._filtro !== 'todos' && grupoMesa(r) !== this._filtro)) return false;
      return !t || `${r.numero} ${r.nome} ${r.setor}`.toLowerCase().includes(t);
    });

    if (!d.ramais.length) {
      grade.innerHTML = `<div class="card" style="grid-column:1/-1">${vazio('phone', 'Nenhum ramal ativo',
        'Cadastre ramais em Conectividade › Ramais para vê-los aqui.')}</div>`;
      return;
    }
    if (!lista.length) {
      grade.innerHTML = `<div class="card" style="grid-column:1/-1">${vazio('search', 'Nenhum ramal neste filtro',
        'Troque o filtro ou limpe a busca.')}</div>`;
      return;
    }

    const podeDnd = this._ctx?.can('editar');
    const passou = Math.floor((Date.now() - this._base) / 1000);
    grade.innerHTML = lista.map(r => {
      const e = ESTADO_MESA[r.estado] || ESTADO_MESA.desconhecido;
      const sou = d.eu && r.numero === d.eu;
      const ativo = ['tocando', 'chamando', 'emchamada', 'espera'].includes(r.estado);
      const outro = r.outro_nome || r.outro_numero
        ? `${r.direcao === 'saida' ? icon('arrowUp', 'ico ico-sm') : icon('arrowDown', 'ico ico-sm')}
           <span class="truncate">${esc(r.outro_nome || '')}${r.outro_nome && r.outro_numero ? ' · ' : ''}<span class="mono">${esc(r.outro_numero || '')}</span></span>`
        : '';

      const botoes = [];
      if (!sou && d.eu && d.em_chamada && r.estado !== 'offline') {
        botoes.push(`<button class="btn btn-primary btn-xs" data-acao="transferir" title="Passar a sua ligação para ${esc(r.numero)}">
          ${icon('shuffle', 'ico ico-sm')} Transferir</button>`);
      } else if (!sou && d.eu && r.estado !== 'offline') {
        botoes.push(`<button class="btn btn-outline btn-xs" data-acao="ligar" title="Ligar para ${esc(r.numero)}">
          ${icon('phone', 'ico ico-sm')} Ligar</button>`);
      }
      if (!sou && d.eu && d.captura && r.estado === 'tocando') {
        botoes.push(`<button class="btn btn-outline btn-xs" data-acao="puxar" title="Atender no seu ramal a ligação que toca no ${esc(r.numero)}">
          ${icon('phoneIn', 'ico ico-sm')} Puxar</button>`);
      }

      return `<div class="mesa-ramal est-${r.estado} ${r.dnd ? 'com-dnd' : ''} ${sou ? 'sou-eu' : ''}" data-numero="${esc(r.numero)}"
                   data-nome="${esc(r.nome)}">
        <div class="row-between" style="gap:8px">
          <b class="mesa-num mono">${esc(r.numero)}</b>
          <span class="badge ${e.tom ? 'badge-' + e.tom : ''}"><i class="dot ${ativo ? 'dot-pulse' : ''}"></i>${e.rotulo}</span>
        </div>
        <div class="mesa-nome truncate" title="${esc(r.nome)}">${esc(r.nome || '—')}${sou ? ' <span class="tiny muted">(você)</span>' : ''}</div>
        <div class="tiny muted truncate">${esc(r.setor || '')}&nbsp;</div>
        <div class="mesa-linha small">${ativo
          ? `${outro}<span class="grow"></span><span class="num mono" data-dur="${r.duracao}">${duracaoMesa(r.duracao + passou)}</span>`
          : '&nbsp;'}</div>
        <div class="row-between" style="gap:6px;margin-top:8px">
          ${podeDnd
            ? `<button class="mesa-dnd ${r.dnd ? 'on' : ''}" data-acao="dnd" aria-pressed="${r.dnd}"
                 title="${r.dnd ? 'Tirar o não perturbe' : 'Colocar em não perturbe'}">${icon('bell', 'ico ico-sm')} ${r.dnd ? 'Não perturbe' : 'DND'}</button>`
            : (r.dnd ? `<span class="badge badge-danger">${icon('bell', 'ico ico-sm')} Não perturbe</span>` : '<span></span>')}
          <div class="row gap-6">${botoes.join('')}</div>
        </div>
      </div>`;
    }).join('');
  },

  async clique(e) {
    const b = e.target.closest('[data-acao]');
    if (!b) return;
    const card = b.closest('[data-numero]');
    const numero = card.dataset.numero;
    const nome = card.dataset.nome;
    const r = this._dados.ramais.find(x => x.numero === numero);

    if (b.dataset.acao === 'ligar') { Softphone.discarPara(numero, nome); return; }
    if (b.dataset.acao === 'puxar') { Softphone.discarPara(this._dados.captura + numero, `Puxar ${numero}`); return; }

    b.disabled = true;
    try {
      if (b.dataset.acao === 'dnd') {
        const ativo = !r.dnd;
        const x = await Api.put(`/telefonista/ramais/${encodeURIComponent(numero)}/dnd`, { ativo });
        r.dnd = x.dnd;
        toast(x.mensagem, x.aplicado ? 'ok' : 'warn');
        this.pintar();
      } else if (b.dataset.acao === 'transferir') {
        const x = await Api.post('/telefonista/transferir', { destino: numero });
        toast(x.mensagem, 'ok');
        this.atualizar();
      }
    } catch (err) {
      toast(err.message || 'Não foi possível concluir.', 'err');
    } finally {
      b.disabled = false;
    }
  }
};

/** Em que contador do topo o ramal entra. */
function grupoMesa(r) {
  if (r.estado === 'tocando') return 'tocando';
  if (['emchamada', 'chamando', 'espera'].includes(r.estado)) return 'ocupado';
  if (r.estado === 'offline' || r.estado === 'desconhecido') return 'offline';
  return 'disponivel';
}

function duracaoMesa(s) {
  s = Math.max(0, s | 0);
  const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60;
  const p = n => String(n).padStart(2, '0');
  return h ? `${h}:${p(m)}:${p(ss)}` : `${p(m)}:${p(ss)}`;
}
