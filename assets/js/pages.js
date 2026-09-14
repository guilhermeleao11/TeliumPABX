/* =========================================================
   Telium PABX — telas (núcleo)
   Todo dado vem da API. Base vazia mostra estado vazio,
   nunca número inventado.
   ========================================================= */

/* ------------------------- Helpers ------------------------- */
// A apóstrofe entra na lista porque um dia alguém escreve um atributo
// com aspas simples, e aí ela é a que abre a porta.
const esc = s => String(s ?? '').replace(/[&<>"']/g,
  c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const initials = n => String(n || '?').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
const num = v => Number(v || 0).toLocaleString('pt-BR');
const moeda = v => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

/** Bytes em unidade legível — 8 KB não deve virar "0.0 MB". */
function tamanho(bytes) {
  const b = Number(bytes || 0);
  if (b === 0) return '—';
  if (b < 1024) return `${b} B`;
  if (b < 1048576) return `${(b / 1024).toFixed(0)} KB`;
  if (b < 1073741824) return `${(b / 1048576).toFixed(1)} MB`;
  return `${(b / 1073741824).toFixed(2)} GB`;
}

function duracao(seg) {
  seg = Number(seg || 0);
  const h = Math.floor(seg / 3600), m = Math.floor((seg % 3600) / 60), s = seg % 60;
  return h > 0
    ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
    : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function dataHora(iso) {
  if (!iso) return '—';
  const d = new Date(String(iso).replace(' ', 'T'));
  return isNaN(d) ? String(iso) : d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'medium' });
}

function pageHead(titulo, sub, acoes = '') {
  return `<div class="page-head">
    <div><h1>${titulo}</h1>${sub ? `<p>${sub}</p>` : ''}</div>
    ${acoes ? `<div class="page-actions">${acoes}</div>` : ''}
  </div>`;
}

/**
 * Lê um formulário solto — fora da gaveta do CRUD, que tem o próprio
 * caminho. Campos de "switch" viram 1/0 e número vazio vira null, que é
 * o que a API espera.
 */
function lerFormulario(form) {
  const dados = {};
  form.querySelectorAll('[name]').forEach(el => {
    if (el.disabled) return;
    let v = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
    if (v === '' && el.type === 'number') v = null;
    dados[el.name] = v;
  });
  return dados;
}

function readOnlyNote(ctx) {
  return ctx.can('editar') ? '' :
    `<span class="badge badge-warn">${icon('eye','ico ico-sm')} Somente leitura</span>`;
}

/** Estado vazio — a base nasce assim, então isto é caminho normal. */
function vazio(ico, titulo, texto, acao = '') {
  return `<div class="empty">
    ${icon(ico)}<h3>${titulo}</h3><p>${texto}</p>
    ${acao ? `<div style="margin-top:16px">${acao}</div>` : ''}
  </div>`;
}

/** Bloco de erro — rede fora, permissão negada, falha do servidor. */
function blocoErro(e, contexto = '') {
  const semRede = e?.semRede;
  return `<div class="empty">
    ${icon(semRede ? 'wifi' : 'alert')}
    <h3>${semRede ? 'Sem comunicação com o servidor' : 'Não foi possível carregar'}</h3>
    <p>${esc(e?.message || 'Erro desconhecido')}${contexto ? ` (${contexto})` : ''}</p>
    <div style="margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="App.route()">
        ${icon('refresh','ico ico-sm')} Tentar de novo</button>
    </div>
  </div>`;
}

const badgeEstadoRamal = d => {
  const [tone, txt] = ESTADO_RAMAL[d] || ['', d || '—'];
  return `<span class="badge ${tone ? 'badge-' + tone : ''}"><i class="dot ${d === 'emchamada' ? 'dot-pulse' : ''}"></i>${txt}</span>`;
};

const badgeCdr = s => {
  const [tone, txt] = DISPOSICAO_CDR[s] || ['', s || '—'];
  return `<span class="badge ${tone ? 'badge-' + tone : ''}">${txt}</span>`;
};

function medidor(label, pct, tone, detalhe = '') {
  return `<div>
    <div class="row-between small" style="margin-bottom:6px">
      <span class="dim">${label}</span>
      <b class="num">${pct}%${detalhe ? ` <span class="muted" style="font-weight:400">${detalhe}</span>` : ''}</b>
    </div>
    <div class="track" style="display:block;height:8px;background:var(--surface-3);border-radius:6px;overflow:hidden">
      <div style="height:100%;width:${pct}%;background:var(--${tone})"></div>
    </div>
  </div>`;
}

function hbars(itens, formata = num) {
  if (!itens.length) return '<p class="small muted center" style="padding:20px">Sem dados no período.</p>';
  const max = Math.max(...itens.map(i => Number(i.valor) || 0)) || 1;
  return itens.map(i => `
    <div class="hbar" style="grid-template-columns:150px 1fr 92px">
      <span class="small truncate">${esc(i.label)}</span>
      <span class="track"><span class="fill" style="width:${(Number(i.valor) / max * 100).toFixed(1)}%"></span></span>
      <span class="val">${formata(i.valor)}</span>
    </div>`).join('');
}

/* ------------------------- Gráfico de barras empilhadas ------------------------- */
function stackedBarChart(el, cfg) {
  if (!cfg.labels?.length) {
    el.innerHTML = `<div class="empty" style="padding:32px">${icon('chart')}
      <p>Sem chamadas registradas no período.</p></div>`;
    return;
  }

  const W = 760, H = 260, PAD = { t: 14, r: 10, b: 28, l: 44 };
  const { labels, series } = cfg;
  const n = labels.length;
  const totals = labels.map((_, i) => series.reduce((s, se) => s + (se.values[i] || 0), 0));
  const rawMax = Math.max(...totals, 1);
  const step = Math.max(1, Math.pow(10, Math.floor(Math.log10(rawMax))) / 2);
  const max = Math.ceil(rawMax / step) * step;

  const pw = W - PAD.l - PAD.r, ph = H - PAD.t - PAD.b;
  const slot = pw / n, bw = Math.min(34, slot * 0.55);
  const y = v => PAD.t + ph - (v / max) * ph;

  const topRound = (x, yy, w, h, r) => {
    r = Math.max(0, Math.min(r, h, w / 2));
    return `M${x},${yy + h} L${x},${yy + r} Q${x},${yy} ${x + r},${yy}
            L${x + w - r},${yy} Q${x + w},${yy} ${x + w},${yy + r} L${x + w},${yy + h} Z`;
  };

  let grid = '', cols = '';
  for (let g = 0; g <= 4; g++) {
    const v = (max / 4) * g, yy = y(v);
    grid += `<line class="grid-line" x1="${PAD.l}" x2="${W - PAD.r}" y1="${yy}" y2="${yy}"/>
             <text class="axis-txt" x="${PAD.l - 8}" y="${yy + 3.5}" text-anchor="end">${v >= 1000 ? (v/1000)+'k' : v}</text>`;
  }

  labels.forEach((lb, i) => {
    const x = PAD.l + slot * i + (slot - bw) / 2;
    let acc = 0, segs = '';
    series.forEach((se, si) => {
      const v = se.values[i] || 0;
      const y0 = y(acc + v), y1 = y(acc);
      let h = y1 - y0;
      const isTop = si === series.length - 1;
      if (!isTop) h = Math.max(0, h - 2);
      const yy = isTop ? y0 : y1 - h;
      if (v > 0) segs += `<path class="bar-seg" d="${topRound(x, yy, bw, h, isTop ? 4 : 0)}" fill="${se.color}"/>`;
      acc += v;
    });
    cols += `<g class="col" data-i="${i}">${segs}
      <rect class="bar-hit" x="${PAD.l + slot * i}" y="${PAD.t}" width="${slot}" height="${ph}" fill="transparent"/>
      <text class="axis-txt" x="${PAD.l + slot * i + slot / 2}" y="${H - 8}" text-anchor="middle">${lb}</text></g>`;
  });

  el.innerHTML = `
    <svg class="chart-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${cfg.aria || 'Gráfico'}">
      ${grid}
      <line class="axis-line" x1="${PAD.l}" x2="${W - PAD.r}" y1="${PAD.t + ph}" y2="${PAD.t + ph}"/>
      ${cols}
    </svg>
    <div class="viz-tip"></div>`;

  const tip = el.querySelector('.viz-tip');
  el.querySelectorAll('.col').forEach(g => {
    g.addEventListener('mousemove', ev => {
      const i = +g.dataset.i;
      tip.innerHTML = `<b>${labels[i]}</b>` +
        series.map(se => `<div class="tr"><span class="row gap-6">
            <i class="sw" style="background:${se.color}"></i>${se.label}</span>
            <span class="v num">${se.values[i] || 0}</span></div>`).join('') +
        `<div class="tr" style="margin-top:5px;padding-top:5px;border-top:1px solid var(--border)">
           <span class="dim">Total</span><span class="v num">${totals[i]}</span></div>`;
      const r = el.getBoundingClientRect();
      tip.style.left = (ev.clientX - r.left) + 'px';
      tip.style.top = (ev.clientY - r.top - 6) + 'px';
      tip.classList.add('on');
    });
    g.addEventListener('mouseleave', () => tip.classList.remove('on'));
  });
}

/* ========================================================================
   PÁGINAS
   ======================================================================== */
const PAGES = {};

/* ------------------------- Painel · Visão geral ------------------------- */
PAGES['dash.visaogeral'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/painel/visaogeral'); }
    catch (e) { return pageHead('Visão Geral', '') + blocoErro(e); }

    this._dados = d;

    const kpis = d.kpis.map(k => `
      <div class="card kpi">
        <div class="k-top">
          <span class="k-label">${k.label}</span>
          <span class="k-ico" style="background:var(--${k.tone}-soft);color:var(--${k.tone})">${icon(k.ico)}</span>
        </div>
        <div class="k-val">${k.valor}</div>
        <div class="k-foot">
          ${k.delta === null ? '' : `<span class="k-delta ${k.delta >= 0 ? 'up' : 'down'}">
             ${icon(k.delta >= 0 ? 'arrowUp' : 'arrowDown','ico ico-sm')}${Math.abs(k.delta)}%</span>`}
          <span>${k.rodape}</span>
        </div>
      </div>`).join('');

    const legenda = (d.volume.series || []).map(s =>
      `<span class="li"><i class="sw" style="background:${s.color}"></i>${s.label}</span>`).join('');

    const top = d.topRamais.length
      ? hbars(d.topRamais.map(r => ({
          label: `${r.ramal}${r.nome ? ' · ' + r.nome : ''}`, valor: r.qtd })))
      : vazio('phone', 'Nenhuma chamada hoje',
              'Os ramais mais ativos aparecem aqui assim que houver tráfego.');

    const troncos = d.troncos.length ? `
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Tronco</th><th>Tipo</th><th>Host</th><th>Canais</th><th>Estado</th></tr></thead>
        <tbody>${d.troncos.map(t => `
          <tr><td><b>${esc(t.nome)}</b></td>
            <td><span class="badge">${esc(t.tipo)}</span></td>
            <td class="mono small dim">${esc(t.host || '—')}</td>
            <td class="num">${t.canais_max || '—'}</td>
            <td>${badgeEstadoRamal(t.estado)}</td></tr>`).join('')}
        </tbody></table></div>`
      : vazio('network', 'Nenhum tronco cadastrado',
              'Cadastre um tronco para conectar a central à operadora.',
              ctx.can('criar') ? '<a class="btn btn-primary btn-sm" href="#/conn.troncos">Cadastrar tronco</a>' : '');

    const ativ = d.atividades.length
      ? d.atividades.map(a => `
        <div class="row gap-12" style="padding:9px 0;border-bottom:1px solid var(--border)">
          <span class="avatar avatar-sm">${initials(a.usuario_nome)}</span>
          <div class="grow" style="min-width:0">
            <div class="small truncate"><b>${esc(a.usuario_nome || 'sistema')}</b>
              ${esc(a.acao)} em ${esc(a.modulo)}${a.objeto ? ` (${esc(a.objeto)})` : ''}</div>
            <div class="tiny muted">${esc(a.ip || '')}</div>
          </div>
          <span class="tiny muted">${esc(a.hora)}</span>
        </div>`).join('')
      : '<p class="small muted center" style="padding:24px">Nenhuma atividade registrada ainda.</p>';

    return pageHead('Visão Geral',
      `Números da central, apurados do CDR e do estado do Asterisk.`,
      `<button class="btn btn-outline btn-sm" id="btnRefresh">${icon('refresh','ico ico-sm')} Atualizar</button>`
    ) + `
    <div class="grid g-4" style="margin-bottom:16px">${kpis}</div>
    <div class="grid g-2-1" style="margin-bottom:16px">
      <div class="card">
        <div class="card-head">
          <div><div class="card-title">Volume de chamadas por hora</div>
               <div class="card-sub">Hoje</div></div>
          <div class="legend">${legenda}</div>
        </div>
        <div class="card-body"><div class="chart-wrap" id="chartVolume"></div></div>
      </div>
      <div class="card">
        <div class="card-head"><div><div class="card-title">Ramais mais ativos</div>
             <div class="card-sub">Chamadas atendidas hoje</div></div></div>
        <div class="card-body">${top}</div>
      </div>
    </div>
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div><div class="card-title">Troncos</div>
             <div class="card-sub">Estado de registro no Asterisk</div></div>
          <a href="#/conn.troncos" class="small">Gerenciar</a></div>
        <div class="card-body tight">${troncos}</div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Atividade recente</div></div>
        <div class="card-body" style="padding-top:4px">${ativ}</div>
      </div>
    </div>`;
  },

  mount() {
    const el = document.getElementById('chartVolume');
    if (el && this._dados) {
      stackedBarChart(el, { ...this._dados.volume, aria: 'Chamadas atendidas e perdidas por hora' });
    }
    document.getElementById('btnRefresh')?.addEventListener('click', () => App.route());
  }
};

/* ------------------------- Painel · Tempo real ------------------------- */
PAGES['dash.temporeal'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/tempo-real'); }
    catch (e) { return pageHead('Wallboard — Tempo Real', '') + blocoErro(e); }

    const cards = d.filas.length ? d.filas.map(f => `
      <div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:12px">
          <div><b>${esc(f.nome)}</b><div class="tiny muted">Fila ${esc(f.numero)} · ${esc(f.estrategia)}</div></div>
          <span class="badge ${f.espera > 2 ? 'badge-warn' : 'badge-ok'}">
            <i class="dot ${f.espera ? 'dot-pulse' : ''}"></i>${f.espera} na fila</span>
        </div>
        <div class="grid" style="grid-template-columns:repeat(3,1fr);gap:10px">
          <div><div class="tiny muted">Agentes</div><b class="num" style="font-size:18px">${f.online}/${f.agentes}</b></div>
          <div><div class="tiny muted">Atendidas</div><b class="num" style="font-size:18px">${f.completadas}</b></div>
          <div><div class="tiny muted">Abandonos</div><b class="num" style="font-size:18px">${f.abandonadas}</b></div>
        </div>
      </div>`).join('')
      : `<div class="card span-2">${vazio('headset', 'Nenhuma fila cadastrada',
          'Crie uma fila de atendimento para acompanhar o tempo real.',
          ctx.can('criar') ? '<a class="btn btn-primary btn-sm" href="#/apps.filas">Criar fila</a>' : '')}</div>`;

    const chamadas = d.chamadas.length ? `
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Canal</th><th>Origem</th><th>Destino</th><th>Estado</th><th>Aplicação</th><th>Duração</th></tr></thead>
        <tbody>${d.chamadas.map(c => `
          <tr><td class="mono small">${esc(c.canal)}</td>
            <td class="mono">${esc(c.cid || '—')}</td>
            <td class="mono">${esc(c.exten || '—')}</td>
            <td><span class="badge badge-info"><i class="dot dot-pulse"></i>${esc(c.estado)}</span></td>
            <td class="small dim">${esc(c.aplicacao || '—')}</td>
            <td class="num">${duracao(c.duracao)}</td></tr>`).join('')}
        </tbody></table></div>`
      : d.asterisk
        ? vazio('phone', 'Nenhuma chamada em andamento',
                'A central está conectada e ociosa neste momento. Esta tela se atualiza a cada 5 segundos.')
        : vazio('alert', 'Sem comunicação com o Asterisk',
                `Não foi possível ler os canais ativos. ${esc(d.asterisk_erro || '')}`);

    return pageHead('Wallboard — Tempo Real',
      'Filas e chamadas ativas, lidas direto do Asterisk.',
      d.asterisk
        ? `<span class="badge badge-ok"><i class="dot dot-pulse"></i>Ao vivo</span>`
        : `<span class="badge badge-danger" title="${esc(d.asterisk_erro || '')}">
             <i class="dot"></i>Asterisk não respondeu</span>`) + `
      <div class="grid g-4" style="margin-bottom:16px">${cards}</div>
      <div class="card">
        <div class="card-head"><div class="card-title">Chamadas em andamento</div>
          <span class="badge">${d.asterisk ? d.chamadas.length + ' canais' : 'sem leitura'}</span></div>
        <div class="card-body tight">${chamadas}</div>
      </div>`;
  },
  mount() {
    clearInterval(this._t);
    this._t = setInterval(() => {
      if (location.hash.includes('dash.temporeal')) App.route(); else clearInterval(this._t);
    }, 5000);
  }
};

/* ------------------------- Painel · Sistema ------------------------- */
PAGES['dash.sistema'] = {
  async render() {
    let s;
    try { s = await Api.get('/sistema/estatisticas'); }
    catch (e) { return pageHead('Estatísticas do Sistema', '') + blocoErro(e); }

    const linhas = [
      ['Sistema', s.sistema], ['Kernel', s.kernel], ['Uptime', s.uptime],
      ['PHP', s.php], ['Banco', s.banco],
      ['Asterisk', s.asterisk.ok ? s.asterisk.versao : 'indisponível'],
      ['Canais ativos', s.asterisk.ok ? s.asterisk.canais_ativos : '—'],
      ['Chamadas ativas', s.asterisk.ok ? s.asterisk.chamadas_ativas : '—'],
    ];

    return pageHead('Estatísticas do Sistema', 'Recursos do servidor, medidos agora.',
      `<button class="btn btn-outline btn-sm" onclick="App.route()">${icon('refresh','ico ico-sm')} Atualizar</button>`) + `
      <div class="grid g-2-1">
        <div class="card">
          <div class="card-head"><div class="card-title">Recursos</div></div>
          <div class="card-body grid" style="gap:18px">
            ${medidor('CPU', s.cpu, s.cpu > 80 ? 'danger' : s.cpu > 60 ? 'warn' : 'ok')}
            ${medidor('Memória', s.memoria.pct, s.memoria.pct > 85 ? 'danger' : s.memoria.pct > 70 ? 'warn' : 'ok',
                      `${num(s.memoria.usado_mb)} / ${num(s.memoria.total_mb)} MB`)}
            ${medidor('Disco /', s.disco.pct, s.disco.pct > 85 ? 'danger' : s.disco.pct > 70 ? 'warn' : 'ok',
                      `${s.disco.usado_gb} / ${s.disco.total_gb} GB`)}
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-title">Sistema</div></div>
          <div class="card-body"><div class="deflist">
            ${linhas.map(([k, v]) => `<div class="defrow" style="grid-template-columns:150px 1fr;padding:9px 0">
                <div class="dt"><b>${k}</b></div><div class="mono small">${esc(v)}</div></div>`).join('')}
          </div></div>
        </div>
      </div>`;
  }
};

/* ------------------------- Painel · Asterisk ------------------------- */
PAGES['dash.asterisk'] = {
  async render() {
    let d;
    try { d = await Api.get('/sistema/asterisk'); }
    catch (e) { return pageHead('Asterisk Info', '') + blocoErro(e); }

    return pageHead('Asterisk Info', 'Saída dos comandos de CLI, pelo AMI.',
      `<button class="btn btn-outline btn-sm" onclick="App.route()">${icon('refresh','ico ico-sm')} Recarregar</button>`) +
      Object.entries(d.comandos).map(([cmd, saida]) => `
        <div class="card" style="margin-bottom:16px">
          <div class="card-head"><div class="card-title mono">${esc(cmd)}</div></div>
          <div class="card-body"><div class="code">${esc(saida) || '(sem saída)'}</div></div>
        </div>`).join('');
  }
};

/* ========================================================================
   Gerador de tela de cadastro
   Lista + busca + gaveta de formulário + exclusão com confirmação.
   Usado por ramais, troncos, filas, rotas, usuários e afins.
   ======================================================================== */
function paginaCrud(cfg) {
  return {
    _itens: [],

    async render(ctx) {
      let r;
      try { r = await Api.get('/' + cfg.recurso, cfg.params || null); }
      catch (e) { return pageHead(cfg.titulo, cfg.sub) + blocoErro(e); }

      this._itens = r.dados || [];
      if (cfg.aoCarregar) await cfg.aoCarregar(this, ctx);

      const podeCriar = ctx.can('criar') && !cfg.somenteLeitura;
      const botaoNovo = podeCriar
        ? `<button class="btn btn-primary btn-sm" data-novo>${icon('plus','ico ico-sm')} ${cfg.rotuloNovo || 'Adicionar'}</button>`
        : '';

      const cabecalho = pageHead(cfg.titulo, cfg.sub,
        `${readOnlyNote(ctx)}${cfg.acoesExtra ? cfg.acoesExtra(ctx) : ''}${botaoNovo}`);

      if (!this._itens.length) {
        return cabecalho + `<div class="card">${vazio(
          cfg.ico || 'grid',
          cfg.vazioTitulo || 'Nenhum registro cadastrado',
          cfg.vazioTexto || 'Comece adicionando o primeiro.',
          podeCriar ? `<button class="btn btn-primary btn-sm" data-novo>
            ${icon('plus','ico ico-sm')} ${cfg.rotuloNovo || 'Adicionar'}</button>` : ''
        )}</div>`;
      }

      const colunas = cfg.colunas;
      const linhas = this._itens.map(item => `
        <tr data-id="${item.id}" data-busca="${esc(cfg.textoBusca ? cfg.textoBusca(item).toLowerCase() : '')}">
          ${colunas.map(c => `<td${c.classe ? ` class="${c.classe}"` : ''}>${c.render(item, ctx)}</td>`).join('')}
          <td class="col-actions"><span class="row-actions">
            ${cfg.acoesLinha ? cfg.acoesLinha(item, ctx) : ''}
            ${ctx.can('editar') && !cfg.somenteLeitura
              ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-editar="${item.id}">${icon('edit','ico ico-sm')}</button>` : ''}
            ${ctx.can('excluir') && !cfg.somenteLeitura
              ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir="${item.id}">${icon('trash','ico ico-sm')}</button>` : ''}
          </span></td>
        </tr>`).join('');

      return cabecalho + `
      <div class="card">
        <div class="toolbar">
          <div class="input-icon search-mini">${icon('search','ico ico-sm')}
            <input class="input" data-filtro placeholder="${cfg.placeholderBusca || 'Buscar…'}">
          </div>
          ${cfg.filtrosExtra ? cfg.filtrosExtra(this._itens) : ''}
          <span class="grow"></span>
          <span class="small muted" data-contador></span>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr>${colunas.map(c => `<th${c.thClasse ? ` class="${c.thClasse}"` : ''}>${c.label}</th>`).join('')}<th></th></tr></thead>
            <tbody>${linhas}</tbody>
          </table>
        </div>
      </div>`;
    },

    mount(ctx) {
      const filtro = document.querySelector('[data-filtro]');
      const contador = document.querySelector('[data-contador]');
      const linhas = [...document.querySelectorAll('tbody tr[data-id]')];

      const aplicar = () => {
        const t = (filtro?.value || '').trim().toLowerCase();
        const extras = [...document.querySelectorAll('[data-filtro-campo]')];
        let n = 0;
        linhas.forEach(l => {
          const item = this._itens.find(i => String(i.id) === l.dataset.id);
          let ok = !t || l.dataset.busca.includes(t);
          extras.forEach(sel => {
            if (ok && sel.value) ok = String(item?.[sel.dataset.filtroCampo] ?? '') === sel.value;
          });
          l.hidden = !ok;
          if (ok) n++;
        });
        if (contador) contador.textContent = `${n} de ${linhas.length} ${cfg.plural || 'registros'}`;
      };
      filtro?.addEventListener('input', aplicar);
      document.querySelectorAll('[data-filtro-campo]').forEach(s => s.addEventListener('change', aplicar));
      aplicar();

      document.querySelectorAll('[data-novo]').forEach(b =>
        b.onclick = () => this.formulario(null, ctx));

      document.querySelectorAll('[data-editar]').forEach(b =>
        b.onclick = () => this.formulario(
          this._itens.find(i => String(i.id) === b.dataset.editar), ctx));

      document.querySelectorAll('[data-excluir]').forEach(b => b.onclick = async () => {
        const item = this._itens.find(i => String(i.id) === b.dataset.excluir);
        const ok = await Modal.confirm({
          titulo: cfg.tituloExcluir ? cfg.tituloExcluir(item) : 'Excluir este registro?',
          texto: cfg.textoExcluir ? cfg.textoExcluir(item) : 'Esta ação não pode ser desfeita.',
          ok: 'Excluir'
        });
        if (!ok) return;
        try {
          await Api.delete(`/${cfg.recurso}/${item.id}`);
          toast('Registro excluído.', 'ok');
          App.route();
        } catch (e) { toast(e.message, 'err'); }
      });

      if (cfg.aoMontar) cfg.aoMontar(this, ctx);
    },

    /** Gaveta de criação/edição montada a partir de cfg.campos. */
    formulario(item, ctx) {
      const novo = !item;
      const campos = cfg.campos(item || {}, ctx, this);
      const abas = [...new Set(campos.map(c => c.aba || 'Geral'))];

      const corpoAba = aba => `<div class="form-grid">${campos
        .filter(c => (c.aba || 'Geral') === aba)
        .map(c => campoHtml(c, item || {}))
        .join('')}</div>`;

      Drawer.open({
        wide: abas.length > 1,
        titulo: novo ? (cfg.tituloNovo || 'Novo registro')
                     : (cfg.tituloEditar ? cfg.tituloEditar(item) : 'Editar registro'),
        sub: cfg.subFormulario || '',
        corpo: (abas.length > 1
          ? `<div class="tabs" data-abas>${abas.map((a, i) =>
              `<button class="tab ${i === 0 ? 'on' : ''}" data-aba="${esc(a)}">${esc(a)}</button>`).join('')}</div>`
          : '') +
          abas.map((a, i) => `<div data-painel="${esc(a)}" ${i ? 'hidden' : ''}>${corpoAba(a)}</div>`).join(''),
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-salvar>${novo ? 'Criar' : 'Salvar alterações'}</button>`,
        aoAbrir: dw => {
          ligarRequisitos(dw);
          // Gancho para a página acrescentar o que é só dela — o gerador
          // de senha do ramal, por exemplo.
          if (cfg.aoAbrirFormulario) cfg.aoAbrirFormulario(dw, item, this);

          dw.querySelectorAll('[data-aba]').forEach(t => t.onclick = () => {
            dw.querySelectorAll('[data-aba]').forEach(x => x.classList.remove('on'));
            t.classList.add('on');
            dw.querySelectorAll('[data-painel]').forEach(p =>
              p.hidden = p.dataset.painel !== t.dataset.aba);
          });

          dw.querySelector('[data-salvar]').onclick = async ev => {
            const botao = ev.currentTarget;

            if (!validarCampos(dw, campos)) {
              dw.querySelector('.field.erro [name]')?.focus();
              return;
            }

            const dados = {};
            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (!el) return;
              // Um seletor de destino grava duas colunas.
              if (c.tipo === 'destino') {
                const d = lerDestino(el);
                dados[`${c.campo}_tipo`] = d.tipo;
                dados[`${c.campo}_valor`] = d.valor;
                return;
              }
              let v = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
              if (v === '' && c.tipo === 'number') v = null;
              // Segredo em branco o servidor mantém; marcar "remover" é
              // o único jeito de apagá-lo, e vai como null de propósito.
              if (c.limpavel && dw.querySelector(`[data-limpar="${c.campo}"]`)?.checked) v = null;
              dados[c.campo] = v;
            });

            botao.disabled = true;
            botao.textContent = 'Salvando…';
            try {
              if (novo) await Api.post('/' + cfg.recurso, dados);
              else await Api.put(`/${cfg.recurso}/${item.id}`, dados);
              Drawer.close();
              toast(novo ? 'Registro criado.' : 'Registro atualizado.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Criar' : 'Salvar alterações';
              if (e.detalhe?.campo) {
                limparErros(dw);
                marcarErro(dw, e.detalhe.campo, e.message);
                dw.querySelector(`[name="${e.detalhe.campo}"]`)?.focus();
              }
              toast(e.message, 'err');
            }
          };
        }
      });
    }
  };
}

/* ========================================================================
   Validação de formulário
   Marca cada campo com problema, em vez de só avisar num toast que
   some. O servidor continua validando tudo de novo.
   ======================================================================== */

/** Política de senha — o mesmo que Senha::validar() aplica no servidor. */
const POLITICA_SENHA = [
  { chave: 'tamanho', rotulo: 'Pelo menos 8 caracteres',
    testa: v => v.length >= 8 },
  { chave: 'numero',  rotulo: 'Pelo menos um número',
    testa: v => /\d/.test(v) },
  { chave: 'simbolo', rotulo: 'Pelo menos um símbolo — @ # ! _ - e afins',
    testa: v => /[^\p{L}\p{N}]/u.test(v) }
];

function senhaAtende(v) {
  return POLITICA_SENHA.every(r => r.testa(v || ''));
}

/** Campo de senha com a lista de exigências que se acende ao digitar. */
function campoSenha(nome, label, ajuda = '') {
  return `<div class="field full" data-campo="${nome}">
    <label class="label">${esc(label)} *</label>
    <input class="input" type="password" name="${nome}" autocomplete="new-password"
           data-senha-politica>
    ${ajuda ? `<span class="hint">${esc(ajuda)}</span>` : ''}
    <div class="requisitos" data-requisitos>
      <span class="titulo">Para a senha ser aceita</span>
      ${POLITICA_SENHA.map(r => `
        <div class="requisito" data-req="${r.chave}">
          <span class="marca">${icon('check','ico')}</span>${esc(r.rotulo)}
        </div>`).join('')}
      <div class="forca"><i></i><i></i><i></i><i></i></div>
      <div class="forca-txt" data-forca-txt>Digite para verificar</div>
    </div>
  </div>`;
}

/** Liga o checklist ao que está sendo digitado. */
function ligarRequisitos(escopo) {
  escopo.querySelectorAll('[data-senha-politica]').forEach(entrada => {
    const painel = entrada.closest('.field')?.querySelector('[data-requisitos]');
    if (!painel) return;

    const atualizar = () => {
      const v = entrada.value;
      let atendidos = 0;

      POLITICA_SENHA.forEach(r => {
        const ok = r.testa(v);
        if (ok) atendidos++;
        painel.querySelector(`[data-req="${r.chave}"]`)?.classList.toggle('ok', ok);
      });

      // A quarta barra premia comprimento acima do mínimo.
      const nivel = atendidos + (atendidos === POLITICA_SENHA.length && v.length >= 12 ? 1 : 0);
      const barra = painel.querySelector('.forca');
      barra.className = `forca f${v ? nivel : 0}`;

      const texto = painel.querySelector('[data-forca-txt]');
      texto.textContent = !v ? 'Digite para verificar'
        : atendidos < POLITICA_SENHA.length ? 'Ainda falta atender os itens acima'
        : v.length >= 12 ? 'Senha forte'
        : 'Senha aceita — passando de 12 caracteres fica mais forte';
    };

    entrada.addEventListener('input', atualizar);
    atualizar();
  });
}

/** Tira as marcas de erro de um formulário. */
function limparErros(escopo) {
  escopo.querySelectorAll('.field.erro').forEach(f => f.classList.remove('erro'));
  escopo.querySelectorAll('.campo-erro, .aviso-form').forEach(e => e.remove());
}

/** Marca um campo com problema e escreve o motivo embaixo dele. */
function marcarErro(escopo, nome, mensagem) {
  const el = escopo.querySelector(`[name="${nome}"]`);
  const campo = el?.closest('.field');
  if (!campo) return;

  campo.classList.add('erro');
  if (!campo.querySelector('.campo-erro')) {
    campo.insertAdjacentHTML('beforeend',
      `<span class="campo-erro">${icon('alert','ico')}${esc(mensagem)}</span>`);
  }
}

/** Resumo no topo, para o problema não passar batido num formulário longo. */
function avisoFormulario(escopo, problemas) {
  const alvo = escopo.querySelector('.drawer-body') || escopo;
  alvo.insertAdjacentHTML('afterbegin', `
    <div class="aviso-form">${icon('alert','ico')}
      <div><b>${problemas.length === 1 ? 'Falta uma informação' : `Faltam ${problemas.length} informações`}</b>
        <ul>${problemas.map(p => `<li>${esc(p)}</li>`).join('')}</ul></div>
    </div>`);
  alvo.scrollTo({ top: 0, behavior: 'smooth' });
}

/**
 * Valida os campos declarados e marca TODOS os problemas de uma vez.
 * Devolve true quando está tudo certo.
 */
function validarCampos(escopo, campos) {
  limparErros(escopo);
  const problemas = [];

  campos.forEach(c => {
    const el = escopo.querySelector(`[name="${c.campo}"]`);
    if (!el || el.disabled || el.type === 'checkbox') return;

    const valor = (el.value || '').trim();

    if (c.tipo === 'destino') {
      if (c.obrigatorio && (valor === '|' || valor === '')) {
        marcarErro(escopo, c.campo, 'Escolha para onde a chamada vai.');
        problemas.push(c.label);
      }
      return;
    }

    if (c.obrigatorio && valor === '') {
      marcarErro(escopo, c.campo, 'Este campo é obrigatório.');
      problemas.push(c.label);
      return;
    }
    if (valor === '') return;

    if (c.tipo === 'number' && Number.isNaN(Number(valor))) {
      marcarErro(escopo, c.campo, 'Informe um número.');
      problemas.push(`${c.label}: precisa ser um número`);
      return;
    }
    if (c.tipo === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(valor)) {
      marcarErro(escopo, c.campo, 'E-mail em formato inválido.');
      problemas.push(`${c.label}: e-mail inválido`);
      return;
    }
    if (c.padraoValido && !c.padraoValido.test(valor)) {
      marcarErro(escopo, c.campo, c.mensagemPadrao || 'Formato inválido.');
      problemas.push(`${c.label}: ${c.mensagemPadrao || 'formato inválido'}`);
    }
  });

  if (problemas.length) avisoFormulario(escopo, problemas);
  return problemas.length === 0;
}

/** Um campo do formulário da gaveta. */
function campoHtml(c, item) {
  const v = item[c.campo] ?? c.padrao ?? '';
  const largura = c.largura === 'full' ? ' full' : '';
  const desabilitado = c.somenteLeitura ? 'disabled' : '';

  // Explicação no meio do formulário, onde a regra é fixa e não há o
  // que configurar. Melhor do que um campo que promete uma escolha
  // que o sistema não faz.
  if (c.tipo === 'nota') {
    return `<div class="field${largura}"><p class="nota-form">${esc(c.texto)}</p></div>`;
  }

  if (c.tipo === 'switch') {
    return `<div class="field${largura}" data-campo="${c.campo}">
      <label class="label">${esc(c.label)}</label>
      <label class="switch"><input type="checkbox" name="${c.campo}" ${Number(v) ? 'checked' : ''} ${desabilitado}>
        <span class="track"></span></label>
      ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
    </div>`;
  }

  if (c.tipo === 'select') {
    return `<div class="field${largura}" data-campo="${c.campo}">
      <label class="label">${esc(c.label)}</label>
      <select class="select" name="${c.campo}" ${desabilitado}>
        ${(c.opcoes || []).map(o => {
          const val = o.valor ?? o;
          const rot = o.rotulo ?? o;
          return `<option value="${esc(val)}" ${String(v) === String(val) ? 'selected' : ''}>${esc(rot)}</option>`;
        }).join('')}
      </select>
      ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
    </div>`;
  }

  if (c.tipo === 'textarea') {
    return `<div class="field${largura}" data-campo="${c.campo}">
      <label class="label">${esc(c.label)}</label>
      <textarea class="textarea" name="${c.campo}" placeholder="${esc(c.placeholder || '')}" ${desabilitado}>${esc(v)}</textarea>
      ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
    </div>`;
  }

  // O seletor de destino é o mesmo das outras telas — um <select> com
  // todos os tipos agrupados, mais o campo do número externo. Entra aqui
  // como tipo de campo para qualquer cadastro poder usá-lo: a rota de
  // entrada usava um seletor próprio, de dois campos, que oferecia oito
  // tipos enquanto a central já entendia quinze.
  if (c.tipo === 'destino') {
    return destinoSelect(c.campo, item, c.destinos, {
      label: c.label, ajuda: c.ajuda, largura: c.largura,
      obrigatorio: c.obrigatorio, rotuloVazio: c.rotuloVazio
    });
  }

  // Campo secreto: a leitura nunca devolve o valor, então ele reabre
  // vazio e vazio quer dizer "não mexi nisso". Quem quiser tirar o
  // segredo precisa de um jeito de dizer isso — sem esta caixa, um PIN
  // posto uma vez não sairia nunca mais.
  const limpar = c.limpavel && item.id
    ? `<label class="check small" style="margin-top:6px">
         <input type="checkbox" data-limpar="${c.campo}"> Remover o que está gravado
       </label>`
    : '';

  return `<div class="field${largura}" data-campo="${c.campo}">
    <label class="label">${esc(c.label)}${c.obrigatorio ? ' *' : ''}</label>
    <input class="input${c.mono ? ' mono' : ''}" type="${c.tipo || 'text'}" name="${c.campo}"
           value="${esc(v)}" placeholder="${esc(c.placeholder || '')}" ${desabilitado}>
    ${limpar}
    ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
  </div>`;
}

/* ------------------------- Conectividade · Ramais ------------------------- */
/**
 * As cinco respostas do FreePBX para "grava esta chamada?".
 *
 * "Não importa" é o valor honesto quando o ramal não tem opinião: quem
 * decide é a fila, a rota ou o padrão da central. Só "forçar" e "nunca"
 * vencem as outras regras.
 */
const OPCOES_GRAVACAO = [
  { valor: 'indiferente', rotulo: 'Não importa — quem decide é a fila ou a rota' },
  { valor: 'sim',         rotulo: 'Sim, gravar' },
  { valor: 'nao',         rotulo: 'Não gravar' },
  { valor: 'forcar',      rotulo: 'Forçar — grava mesmo se outra regra disser que não' },
  { valor: 'nunca',       rotulo: 'Nunca — não grava nem se outra regra mandar' }
];

/** Senha SIP longa e aleatória, do gerador do próprio navegador. */
function gerarSenhaSip(tamanho = 20) {
  // Sem caracteres que confundem quem digita num aparelho: I, l, 1, O, 0.
  const alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  const simbolos = '@#%+=_-';
  const bytes = new Uint32Array(tamanho);
  crypto.getRandomValues(bytes);

  const corpo = [...bytes].map(b => alfabeto[b % alfabeto.length]);
  // Dois símbolos em posições sorteadas, para não cair sempre no fim.
  const pos = new Uint32Array(2);
  crypto.getRandomValues(pos);
  corpo[pos[0] % tamanho] = simbolos[pos[0] % simbolos.length];
  corpo[pos[1] % tamanho] = simbolos[pos[1] % simbolos.length];

  return corpo.join('');
}

PAGES['conn.ramais'] = paginaCrud({
  recurso: 'ramais',
  titulo: 'Ramais',
  sub: 'Cadastro de ramais SIP, dispositivos e recursos por usuário.',
  ico: 'phone',
  plural: 'ramais',
  rotuloNovo: 'Adicionar ramal',
  tituloNovo: 'Adicionar ramal',
  subFormulario: 'Depois de salvar, aplique as configurações para o Asterisk assumir.',
  vazioTitulo: 'Nenhum ramal cadastrado',
  vazioTexto: 'Cadastre o primeiro ramal para começar a receber e originar chamadas.',
  placeholderBusca: 'Buscar por número, nome ou setor…',
  textoBusca: r => `${r.numero} ${r.nome} ${r.setor || ''}`,
  tituloEditar: r => `Ramal ${r.numero} — ${r.nome}`,
  tituloExcluir: r => `Excluir o ramal ${r.numero}?`,
  textoExcluir: r => `${r.nome} perde o acesso à central assim que a configuração for aplicada. O histórico de chamadas é preservado.`,

  colunas: [
    { label: 'Ramal', thClasse: 'col-num', render: r => `<b class="mono">${esc(r.numero)}</b>` },
    { label: 'Nome', render: r => `<span class="row gap-8"><span class="avatar avatar-sm">${initials(r.nome)}</span>${esc(r.nome)}</span>` },
    { label: 'Setor', render: r => `<span class="dim">${esc(r.setor || '—')}</span>` },
    { label: 'Tecnologia', render: r => `<span class="badge">${String(r.tecnologia || '').toUpperCase()}</span>` },
    { label: 'Correio', render: r => Number(r.voicemail) ? icon('checkCirc','ico ico-sm') : '<span class="muted">—</span>' },
    { label: 'Gravação', render: r => r.gravar && r.gravar !== 'nao'
        ? `<span class="badge badge-brand">${esc(r.gravar)}</span>` : '<span class="muted">—</span>' },
    { label: 'Estado', render: r => Number(r.ativo)
        ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
        : '<span class="badge"><i class="dot"></i>Inativo</span>' }
  ],

  filtrosExtra: itens => {
    const setores = [...new Set(itens.map(i => i.setor).filter(Boolean))].sort();
    return `<select class="select" data-filtro-campo="setor" style="width:180px">
      <option value="">Todos os setores</option>
      ${setores.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('')}
    </select>`;
  },

  acoesLinha: (r, ctx) => ctx.can('editar')
    ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Credenciais SIP" data-credencial="${r.id}">${icon('key','ico ico-sm')}</button>`
    : '',

  campos: (r) => [
    // ---------------- Geral ----------------
    { campo: 'numero', label: 'Ramal do usuário', obrigatorio: true, mono: true,
      placeholder: '1001', somenteLeitura: !!r.id,
      padraoValido: /^[0-9]{2,10}$/, mensagemPadrao: 'só dígitos, de 2 a 10',
      ajuda: r.id ? 'O número não muda depois de criado.' : 'É o que se disca para chegar nesta pessoa.' },
    { campo: 'nome', label: 'Nome de exibição', obrigatorio: true, placeholder: 'Nome do usuário',
      ajuda: 'Aparece no visor de quem recebe a chamada.' },
    { campo: 'setor', label: 'Setor / centro de custo', placeholder: 'Atendimento' },
    { campo: 'email', label: 'E-mail', tipo: 'email', placeholder: 'usuario@empresa.com.br',
      ajuda: 'Recebe os recados do correio de voz.' },
    { campo: 'senha_sip', label: r.id ? 'Nova senha SIP' : 'Senha SIP', obrigatorio: !r.id, mono: true,
      largura: 'full',
      placeholder: r.id ? 'deixe em branco para manter' : 'mínimo 12 caracteres',
      ajuda: 'É ela que protege o ramal contra fraude de tarifação. Use o botão para gerar uma longa.' },
    { campo: 'pin', label: 'PIN do usuário', mono: true, tipo: 'password', limpavel: true,
      padraoValido: /^[0-9]{0,10}$/, mensagemPadrao: 'só dígitos',
      ajuda: 'Usado onde a central pede confirmação, como nas rotas com senha. '
           + 'Ao editar, em branco mantém o atual.' },
    { campo: 'accountcode', label: 'Código da conta', mono: true,
      ajuda: 'Vai para o CDR — serve para separar custo por cliente ou projeto.' },
    { campo: 'ativo', label: 'Ramal ativo', tipo: 'switch', padrao: 1 },

    // ---------------- DID e identificação ----------------
    { aba: 'Identificação', campo: 'did', label: 'DID atribuído', mono: true,
      placeholder: '1140041001',
      ajuda: 'O número público que cai direto neste ramal. A rota de entrada é criada à parte.' },
    { aba: 'Identificação', campo: 'did_descricao', label: 'Descrição do DID' },
    { aba: 'Identificação', campo: 'cid_entrada', label: 'CID de entrada', mono: true,
      ajuda: 'Identificação que a central mostra quando a chamada chega por este DID.' },
    { aba: 'Identificação', campo: 'cid_pseudo', label: 'Número de saída (pseudo CID)', mono: true,
      ajuda: 'O que a operadora recebe como origem nas ligações deste ramal.' },
    { aba: 'Identificação', campo: 'alias_sip', label: 'Apelido SIP', mono: true,
      ajuda: 'Um segundo nome pelo qual a central reconhece este ramal.' },
    { aba: 'Identificação', campo: 'contexto', label: 'Contexto', tipo: 'select', padrao: 'interno',
      opcoes: [{ valor: 'interno', rotulo: 'interno — discagem normal' },
               { valor: 'telium-bloqueado', rotulo: 'telium-bloqueado — sem saída' }],
      ajuda: 'Outro contexto faria o ramal pular a checagem de permissão de discagem.' },
    { aba: 'Identificação', campo: 'contexto_custom', label: 'Contexto personalizado', mono: true,
      ajuda: 'Só se você escreveu um contexto próprio no extensions_custom.conf. Substitui o de cima.' },

    // ---------------- Voz ----------------
    { aba: 'Voz', campo: 'voicemail', label: 'Correio de voz', tipo: 'switch', padrao: 1 },
    { aba: 'Voz', campo: 'vm_senha', label: 'Senha do correio', tipo: 'password', mono: true,
      padraoValido: /^[0-9]{0,10}$/, mensagemPadrao: 'só dígitos',
      ajuda: 'Em branco, a senha é o próprio número do ramal.' },
    { aba: 'Voz', campo: 'vm_email', label: 'Enviar recados por e-mail', tipo: 'switch', padrao: 1 },
    { aba: 'Voz', campo: 'tempo_toque', label: 'Tempo de toque (s)', tipo: 'number', padrao: 20 },
    { aba: 'Voz', campo: 'toque_sigame', label: 'Tempo de toque no siga-me (s)', tipo: 'number', padrao: 20 },
    { aba: 'Voz', campo: 'siga_me', label: 'Siga-me', mono: true,
      placeholder: 'ramal ou número externo', largura: 'full',
      ajuda: 'Detalhes e modo ficam em Aplicações › Siga-me.' },
    { aba: 'Voz', campo: 'dnd', label: 'Não perturbe', tipo: 'switch' },
    { aba: 'Voz', campo: 'chamada_espera', label: 'Chamada em espera', tipo: 'switch', padrao: 1,
      ajuda: 'Desligada, a segunda chamada ouve ocupado.' },
    { aba: 'Voz', campo: 'tom_espera', label: 'Bipe ao chegar segunda chamada', tipo: 'switch', padrao: 1 },
    { aba: 'Voz', campo: 'auto_resposta', label: 'Atender sozinho chamada interna', tipo: 'switch',
      ajuda: 'O aparelho abre o viva-voz sem tocar. Útil em PA de atendimento.' },
    { aba: 'Voz', campo: 'interfonia', label: 'Interfonia', tipo: 'select',
      opcoes: [{ valor: 'permitir', rotulo: 'Aceita ser chamado em interfonia' },
               { valor: 'negar', rotulo: 'Recusa interfonia' }] },
    { aba: 'Voz', campo: 'max_saidas', label: 'Limite de chamadas simultâneas de saída',
      tipo: 'number', padrao: 0, ajuda: '0 deixa sem limite.' },
    { aba: 'Voz', campo: 'estado_em_fila', label: 'Contar estado do ramal nas filas',
      tipo: 'switch', padrao: 1,
      ajuda: 'Desligado, a fila oferece chamada mesmo com o ramal ocupado.' },
    { aba: 'Voz', campo: 'rastreio_chamada', label: 'Permitir rastrear a última chamada',
      tipo: 'switch', ajuda: 'Libera o código *69 neste ramal.' },
    { aba: 'Voz', campo: 'no_diretorio', label: 'Aparece no diretório', tipo: 'switch', padrao: 1 },
    { aba: 'Voz', campo: 'opcoes_dial', label: 'Opções do Dial', mono: true, placeholder: 'tT',
      ajuda: 'Passadas direto ao Asterisk. Em branco, a central usa tT.' },

    // ---------------- Gravação ----------------
    { aba: 'Gravação', campo: 'grav_ext_entrada', label: 'Externa recebida', tipo: 'select',
      opcoes: OPCOES_GRAVACAO, largura: 'full' },
    { aba: 'Gravação', campo: 'grav_ext_saida', label: 'Externa feita', tipo: 'select',
      opcoes: OPCOES_GRAVACAO, largura: 'full' },
    { aba: 'Gravação', campo: 'grav_int_entrada', label: 'Interna recebida', tipo: 'select',
      opcoes: OPCOES_GRAVACAO, largura: 'full' },
    { aba: 'Gravação', campo: 'grav_int_saida', label: 'Interna feita', tipo: 'select',
      opcoes: OPCOES_GRAVACAO, largura: 'full' },
    { aba: 'Gravação', campo: 'grav_sob_demanda', label: 'Gravação sob demanda', tipo: 'select',
      opcoes: [{ valor: 'desabilitado', rotulo: 'Não permitir' },
               { valor: 'ativar', rotulo: 'Permitir o código *1 durante a chamada' },
               { valor: 'sobrepor', rotulo: 'Permitir e deixar vencer as regras acima' }],
      largura: 'full' },
    { aba: 'Gravação', tipo: 'nota', largura: 'full',
      texto: 'Quando os dois lados discordam, a ordem é sempre a mesma: '
           + '"nunca" de qualquer lado proíbe, "forçar" de qualquer lado obriga, '
           + 'e só então vale a preferência de cada um. Não há número de prioridade '
           + 'a configurar — a regra é fixa, para ser possível explicá-la ao cliente.' },
    { aba: 'Gravação', campo: 'gravar', label: 'Regra antiga (compatibilidade)', tipo: 'select',
      opcoes: [{ valor: 'nao', rotulo: 'Não gravar' }, { valor: 'entrada', rotulo: 'Só entrantes' },
               { valor: 'saida', rotulo: 'Só saintes' }, { valor: 'ambas', rotulo: 'Entrantes e saintes' }],
      ajuda: 'Mantida para instalações antigas. As quatro opções acima é que valem.' },

    // ---------------- Rede ----------------
    { aba: 'Rede', campo: 'transporte', label: 'Transporte', tipo: 'select',
      opcoes: [{ valor: 'udp', rotulo: 'UDP' }, { valor: 'tcp', rotulo: 'TCP' },
               { valor: 'tls', rotulo: 'TLS (SIP cifrado)' }], padrao: 'udp',
      ajuda: 'Com WebRTC ligado, o transporte passa a ser WSS automaticamente.' },
    { aba: 'Rede', campo: 'qualify_freq', label: 'Verificar o aparelho a cada (s)',
      tipo: 'number', padrao: 60, ajuda: '0 desliga a verificação.' },
    { aba: 'Rede', campo: 'max_contatos', label: 'Máximo de aparelhos', tipo: 'number', padrao: 2,
      ajuda: 'Quantos aparelhos podem registrar com este ramal ao mesmo tempo.' },
    { aba: 'Rede', campo: 'remove_existing', label: 'Derrubar o registro mais antigo ao encher',
      tipo: 'switch', padrao: 1 },
    { aba: 'Rede', campo: 'expira_min', label: 'Registro: expiração mínima (s)', tipo: 'number', padrao: 60 },
    { aba: 'Rede', campo: 'expira_max', label: 'Registro: expiração máxima (s)', tipo: 'number', padrao: 3600 },
    { aba: 'Rede', campo: 'forcar_rport', label: 'Forçar rport', tipo: 'switch', padrao: 1 },
    { aba: 'Rede', campo: 'reescrever_contato', label: 'Reescrever contato', tipo: 'switch', padrao: 1 },
    { aba: 'Rede', campo: 'rtp_simetrico', label: 'RTP simétrico', tipo: 'switch', padrao: 1 },
    { aba: 'Rede', campo: 'usar_transporte_recebido', label: 'Usar o transporte de onde veio',
      tipo: 'switch', ajuda: 'Necessário quando o aparelho está atrás de NAT com porta variável.' },
    { aba: 'Rede', campo: 'proxy_saida', label: 'Proxy de saída', mono: true,
      placeholder: 'sip:proxy.operadora.com.br', largura: 'full' },
    { aba: 'Rede', campo: 'redes_permitidas', label: 'Restringir a redes', mono: true,
      placeholder: '10.0.0.0/8, 192.168.0.0/16', largura: 'full',
      ajuda: 'No Asterisk essa lista vale para a central inteira: as redes de todos os ramais viram uma regra só.' },
    { aba: 'Rede', campo: 'contexto_mensagens', label: 'Contexto de mensagens (SIP MESSAGE)', mono: true },

    // ---------------- Mídia ----------------
    { aba: 'Mídia', campo: 'codecs', label: 'Codecs permitidos', mono: true,
      padrao: 'opus,alaw,ulaw,g722', largura: 'full',
      ajuda: 'Na ordem de preferência. alaw é o padrão no Brasil.' },
    { aba: 'Mídia', campo: 'codecs_negados', label: 'Codecs recusados', mono: true, largura: 'full' },
    { aba: 'Mídia', campo: 'dtmf_modo', label: 'Sinalização DTMF', tipo: 'select',
      opcoes: [{ valor: 'rfc4733', rotulo: 'RFC 4733 (padrão)' },
               { valor: 'inband', rotulo: 'No áudio (inband)' },
               { valor: 'info', rotulo: 'SIP INFO' },
               { valor: 'auto', rotulo: 'Automático' },
               { valor: 'auto_info', rotulo: 'Automático, com INFO' }], largura: 'full' },
    { aba: 'Mídia', campo: 'direct_media', label: 'Mídia direta entre aparelhos', tipo: 'switch',
      ajuda: 'Tira o Asterisk do caminho do áudio. Ligue só em rede local e sem gravação.' },
    { aba: 'Mídia', campo: 'media_address', label: 'Endereço de mídia', mono: true },
    { aba: 'Mídia', campo: 'max_audio', label: 'Fluxos de áudio', tipo: 'number', padrao: 1 },
    { aba: 'Mídia', campo: 'max_video', label: 'Fluxos de vídeo', tipo: 'number', padrao: 0 },
    { aba: 'Mídia', campo: 'rtp_timeout', label: 'Desligar sem áudio por (s)', tipo: 'number', padrao: 0,
      ajuda: '0 desliga a checagem.' },
    { aba: 'Mídia', campo: 'rtp_timeout_hold', label: 'Idem, em espera (s)', tipo: 'number', padrao: 0 },
    { aba: 'Mídia', campo: 'srtp', label: 'Exigir mídia criptografada (SRTP)', tipo: 'switch' },
    { aba: 'Mídia', campo: 'srtp_oportunista', label: 'Aceitar mídia sem criptografia', tipo: 'switch',
      ajuda: 'Tenta cifrar e aceita sem, se o outro lado não souber.' },
    { aba: 'Mídia', campo: 'timers_sessao', label: 'Temporizador de sessão', tipo: 'select',
      opcoes: [{ valor: 'sim', rotulo: 'Usar quando o outro lado usa' },
               { valor: 'nao', rotulo: 'Não usar' },
               { valor: 'obrigatorio', rotulo: 'Exigir' }] },
    { aba: 'Mídia', campo: 'timers_expira', label: 'Renovar a sessão a cada (s)',
      tipo: 'number', padrao: 1800 },

    // ---------------- WebRTC ----------------
    { aba: 'WebRTC', campo: 'webrtc', label: 'Softphone do navegador', tipo: 'switch', largura: 'full',
      ajuda: 'Liga tudo o que o navegador exige — transporte WSS, AVPF, ICE, rtcp-mux e DTLS — e usa o certificado do módulo de Certificados. O usuário do console vinculado a este ramal passa a discar pela própria tela, sem instalar nada.' },
    { aba: 'WebRTC', campo: 'avpf', label: 'AVPF', tipo: 'switch',
      ajuda: 'Ligado junto com o WebRTC. Aqui só para um aparelho que peça AVPF sem ser navegador.' },
    { aba: 'WebRTC', campo: 'ice', label: 'Suporte a ICE', tipo: 'switch' },
    { aba: 'WebRTC', campo: 'rtcp_mux', label: 'rtcp-mux', tipo: 'switch' },
    { aba: 'WebRTC', campo: 'dtls', label: 'DTLS', tipo: 'switch' },
    { aba: 'WebRTC', campo: 'dtls_verificar', label: 'Verificação do DTLS', tipo: 'select',
      opcoes: [{ valor: 'fingerprint', rotulo: 'Impressão digital (padrão)' },
               { valor: 'certificate', rotulo: 'Certificado' },
               { valor: 'yes', rotulo: 'Ambos' }, { valor: 'no', rotulo: 'Nenhuma' }] },
    { aba: 'WebRTC', campo: 'dtls_setup', label: 'Papel no DTLS', tipo: 'select',
      opcoes: [{ valor: 'actpass', rotulo: 'actpass (padrão)' },
               { valor: 'active', rotulo: 'active' }, { valor: 'passive', rotulo: 'passive' }] },
    { aba: 'WebRTC', campo: 'dtls_rekey', label: 'Trocar a chave DTLS a cada (s)',
      tipo: 'number', padrao: 0, ajuda: '0 não troca.' },

    // ---------------- Avançado ----------------
    { aba: 'Avançado', campo: 'callgroup', label: 'Grupos de chamada', mono: true,
      ajuda: 'Ex.: 1,3-5. Quem está no mesmo grupo pode ser capturado.' },
    { aba: 'Avançado', campo: 'pickupgroup', label: 'Grupos de captura', mono: true },
    { aba: 'Avançado', campo: 'trust_rpid', label: 'Confiar no RPID recebido', tipo: 'switch' },
    { aba: 'Avançado', campo: 'envia_rpid', label: 'Enviar RPID', tipo: 'switch' },
    { aba: 'Avançado', campo: 'envia_pai', label: 'Enviar P-Asserted-Identity', tipo: 'switch' },
    { aba: 'Avançado', campo: 'send_connected', label: 'Enviar identificação de quem atendeu',
      tipo: 'switch', padrao: 1 },
    { aba: 'Avançado', campo: 'user_eq_phone', label: 'Marcar user=phone na URI', tipo: 'switch' },
    { aba: 'Avançado', campo: 'refer_blind_progress', label: 'Avisar progresso na transferência cega',
      tipo: 'switch', padrao: 1 },
    { aba: 'Avançado', campo: 'mwi_tipo', label: 'Aviso de recado (MWI)', tipo: 'select',
      opcoes: [{ valor: 'auto', rotulo: 'Automático' },
               { valor: 'solicitado', rotulo: 'Só quando o aparelho pede' },
               { valor: 'nao_solicitado', rotulo: 'Enviar sem pedir' }], largura: 'full' },
    { aba: 'Avançado', campo: 'mwi_agregado', label: 'Agregar as caixas num aviso só', tipo: 'switch' },
    { aba: 'Avançado', campo: 'ditado', label: 'Serviço de ditado', tipo: 'switch',
      ajuda: 'Libera os códigos *34 e *35 neste ramal.' },
    { aba: 'Avançado', campo: 'ditado_formato', label: 'Formato do ditado', tipo: 'select',
      opcoes: [{ valor: 'wav', rotulo: 'WAV' }, { valor: 'gsm', rotulo: 'GSM' },
               { valor: 'ogg', rotulo: 'OGG' }] },
    { aba: 'Avançado', campo: 'ditado_email', label: 'E-mail do ditado', tipo: 'email', largura: 'full' },
    { aba: 'Avançado', campo: 'ditado_remetente', label: 'Remetente do ditado', largura: 'full' },

    { aba: 'Permissões', campo: 'perm_local', label: 'Ligações locais', tipo: 'switch', padrao: 1 },
    { aba: 'Permissões', campo: 'perm_celular', label: 'Celular', tipo: 'switch', padrao: 1 },
    { aba: 'Permissões', campo: 'perm_ddd', label: 'DDD nacional', tipo: 'switch', padrao: 1 },
    { aba: 'Permissões', campo: 'perm_ddi', label: 'Internacional', tipo: 'switch' }
  ],

  /** Acrescenta o gerador de senha ao campo de senha SIP. */
  aoAbrirFormulario: (dw) => {
    const campo = dw.querySelector('[name="senha_sip"]');
    if (!campo || campo.dataset.comGerador) return;
    campo.dataset.comGerador = '1';

    const linha = document.createElement('div');
    linha.className = 'row gap-6';
    linha.style.marginTop = '8px';
    linha.innerHTML = `
      <button type="button" class="btn btn-outline btn-sm" data-gerar-senha>
        ${icon('refresh','ico ico-sm')} Gerar senha</button>
      <button type="button" class="btn btn-ghost btn-sm" data-copiar-senha hidden>
        ${icon('copy','ico ico-sm')} Copiar</button>
      <span class="tiny muted" data-forca-senha></span>`;
    campo.insertAdjacentElement('afterend', linha);

    const aviso = linha.querySelector('[data-forca-senha]');
    const copiar = linha.querySelector('[data-copiar-senha]');

    linha.querySelector('[data-gerar-senha]').onclick = () => {
      campo.value = gerarSenhaSip();
      campo.type = 'text';
      copiar.hidden = false;
      aviso.textContent = `${campo.value.length} caracteres — guarde agora, ela não é mostrada depois.`;
    };

    copiar.onclick = async () => {
      try { await navigator.clipboard.writeText(campo.value); toast('Senha copiada.', 'ok'); }
      catch { campo.select(); document.execCommand('copy'); toast('Senha copiada.', 'ok'); }
    };
  },

  aoMontar: (pagina) => {
    document.querySelectorAll('[data-credencial]').forEach(b => b.onclick = async () => {
      try {
        const c = await Api.get(`/ramais/${b.dataset.credencial}/credenciais`);
        Drawer.open({
          titulo: `Credenciais SIP do ramal ${c.numero}`,
          sub: 'Use estes dados para configurar o aparelho ou o softphone.',
          corpo: `<div class="deflist">
            ${[['Usuário', c.numero], ['Senha', c.senha_sip], ['Transporte', c.transporte]]
              .map(([k, v]) => `<div class="defrow" style="grid-template-columns:140px 1fr;padding:12px 0">
                <div class="dt"><b>${k}</b></div><div class="mono">${esc(v)}</div></div>`).join('')}
          </div>
          <p class="hint" style="margin-top:16px">Esta consulta fica registrada na auditoria.</p>`
        });
      } catch (e) { toast(e.message, 'err'); }
    });
  }
});

/* ------------------------- Conectividade · Troncos ------------------------- */
PAGES['conn.troncos'] = paginaCrud({
  recurso: 'troncos',
  titulo: 'Troncos',
  sub: 'Entroncamentos com as operadoras.',
  ico: 'network',
  plural: 'troncos',
  rotuloNovo: 'Novo tronco',
  tituloNovo: 'Novo tronco',
  vazioTitulo: 'Nenhum tronco cadastrado',
  vazioTexto: 'Sem tronco a central só faz chamadas internas. Cadastre o entroncamento da operadora.',
  placeholderBusca: 'Buscar por nome ou host…',
  textoBusca: t => `${t.nome} ${t.host || ''}`,
  tituloEditar: t => `Tronco ${t.nome}`,
  tituloExcluir: t => `Excluir o tronco ${t.nome}?`,
  textoExcluir: () => 'As rotas de saída que usam este tronco deixarão de funcionar.',

  // "Ativo" dizia só que a linha do banco está ativa, e quem cadastrava
  // um tronco lia isso como "está no ar". O estado de verdade vem do
  // Asterisk, e é o que responde "cadastrei e não aparece".
  aoCarregar: async pagina => {
    pagina._situacao = await Api.get('/diagnostico/troncos')
      .catch(() => ({ central: false, troncos: [] }));
  },

  colunas: [
    { label: 'Tronco', render: t => `<b>${esc(t.nome)}</b>
        <div class="tiny muted mono">${esc(t.host || '—')}:${t.porta || 5060}</div>` },
    { label: 'Canais', render: t => `<span class="num">${t.canais_max || '—'}</span>` },
    { label: 'Registra', render: t => Number(t.registrar)
        ? '<span class="badge badge-info">Sim</span>' : '<span class="muted">Não</span>' },
    { label: 'No cadastro', render: t => Number(t.ativo)
        ? '<span class="badge badge-ok">Ativo</span>'
        : '<span class="badge">Inativo</span>' },
    { label: 'Na central', render: t => {
        const s = (PAGES['conn.troncos']._situacao?.troncos || [])
          .find(x => x.nome === t.nome);
        if (!s) return '<span class="muted small">—</span>';
        if (s.no_ar)
          return `<span class="badge badge-ok"><i class="dot dot-pulse"></i>${esc(s.situacao)}</span>`;
        // Recusa de registro é erro de credencial: quem cadastrou tem
        // o que corrigir agora, e não é a mesma coisa que esperar o
        // primeiro registro subir.
        const grave = !s.publicado || s.estado_registro === 'Rejected'
          || s.situacao.includes('não é publicado');
        return `<span class="badge ${grave ? 'badge-danger' : 'badge-warn'}"
          data-tip="${esc(s.situacao)}">${esc(s.situacao)}</span>`;
      } }
  ],

  acoesExtra: () => {
    const d = PAGES['conn.troncos']._situacao;
    if (!d) return '';
    if (!d.central)
      return '<span class="badge badge-warn">central sem resposta — o estado não pôde ser lido</span>';
    const fora = (d.troncos || []).filter(t => Number(t.ativo) && !t.no_ar).length;
    return fora
      ? `<span class="badge badge-warn">${fora} tronco${fora > 1 ? 's' : ''} fora do ar</span>`
      : '';
  },

  campos: (t) => [
    { campo: 'nome', label: 'Nome do tronco', obrigatorio: true, placeholder: 'SIP-Operadora' },
    { campo: 'tipo', label: 'Tipo', tipo: 'select', padrao: 'pjsip',
      opcoes: [{ valor: 'pjsip', rotulo: 'SIP (PJSIP)' }],
      ajuda: 'Esta central entronca por SIP. Placa analógica ou E1 exige chan_dahdi, '
           + 'que não faz parte desta instalação.' },
    { campo: 'host', label: 'Host da operadora', obrigatorio: true, mono: true, placeholder: 'sip.operadora.com.br' },
    { campo: 'porta', label: 'Porta', tipo: 'number', padrao: 5060 },
    { campo: 'transporte', label: 'Transporte', tipo: 'select', opcoes: ['udp','tcp','tls'], padrao: 'udp' },
    { campo: 'canais_max', label: 'Canais contratados', tipo: 'number', placeholder: '30' },
    { campo: 'usuario', label: 'Usuário de autenticação', mono: true },
    { campo: 'senha', label: t.id ? 'Nova senha' : 'Senha', mono: true,
      placeholder: t.id ? 'deixe em branco para manter' : '' },
    { campo: 'registrar', label: 'Registrar no provedor', tipo: 'switch', padrao: 1 },
    { campo: 'ativo', label: 'Tronco ativo', tipo: 'switch', padrao: 1 },
    { campo: 'from_user', label: 'From user', mono: true,
      padraoValido: /^[^@\s]*$/, mensagemPadrao: 'sem arroba e sem espaço',
      ajuda: 'A parte antes do @ no From. Em branco, vale a identificação de saída.' },
    { campo: 'from_domain', label: 'From domain', mono: true,
      padraoValido: /^$|^(?!\d+$)[A-Za-z0-9._-]+$/,
      mensagemPadrao: 'domínio ou IP — o número da conta vai em "From user"',
      ajuda: 'O domínio ou IP da operadora, como sip.operadora.com.br. Em branco, vale o host '
           + 'do tronco. Pôr o número da conta aqui gera um From que a operadora recusa com 404.' },
    { campo: 'cid_saida', label: 'Identificação de saída', mono: true, placeholder: '1133255800' },
    { campo: 'codecs', label: 'Codecs', mono: true, padrao: 'alaw,ulaw,g729' },
    { campo: 'contexto_entrada', label: 'Contexto de entrada', mono: true, padrao: 'de-tronco', largura: 'full' }
  ]
});

/* ------------------------- Aplicações · Filas ------------------------- */
const ESTRATEGIAS_FILA = [
  { valor: 'ringall',     rotulo: 'Tocar em todos ao mesmo tempo' },
  { valor: 'rrmemory',    rotulo: 'Rodízio com memória (mais justo)' },
  { valor: 'leastrecent', rotulo: 'Quem está há mais tempo sem atender' },
  { valor: 'fewestcalls', rotulo: 'Quem atendeu menos chamadas' },
  { valor: 'linear',      rotulo: 'Na ordem da lista, sempre do começo' },
  { valor: 'random',      rotulo: 'Aleatório' },
  { valor: 'wrandom',     rotulo: 'Aleatório com peso da penalidade' }
];

const VAZIA_FILA = [
  { valor: 'sim',     rotulo: 'Sim — entra e espera mesmo assim' },
  { valor: 'nao',     rotulo: 'Não — vai direto para o destino de fila vazia' },
  { valor: 'estrito', rotulo: 'Não, e conta pausado como ausente' }
];

PAGES['apps.filas'] = {
  async render(ctx) {
    let r, anuncios, pesquisas, audios;
    try {
      [r, anuncios, pesquisas, audios] = await Promise.all([
        Api.get('/filas', { limite: 200 }),
        Api.get('/anuncios', { limite: 200 }).catch(() => ({ dados: [] })),
        Api.get('/pesquisas', { limite: 100 }).catch(() => ({ dados: [] })),
        Api.get('/audios').catch(() => ({ dados: [] }))
      ]);
    } catch (e) { return pageHead('Filas de Atendimento', '') + blocoErro(e); }

    this._itens = r.dados || [];
    this._anuncios = anuncios.dados || [];
    this._pesquisas = pesquisas.dados || [];
    // Música em espera é classe do musiconhold, não anúncio: ela toca em
    // laço enquanto o cliente espera, e não uma vez com um destino depois.
    this._audios = audios.dados || [];

    const cabecalho = pageHead('Filas de Atendimento',
      'Distribuição das chamadas, agentes, anúncios e metas de nível de serviço.',
      `${ctx.can('criar')
        ? `<button class="btn btn-outline btn-sm" id="verPesquisas">
             ${icon('star','ico ico-sm')} Pesquisas</button>
           <button class="btn btn-primary btn-sm" data-nova-fila>
             ${icon('plus','ico ico-sm')} Nova fila</button>`
        : readOnlyNote(ctx)}`);

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('headset', 'Nenhuma fila cadastrada',
        `A fila distribui as chamadas entre os atendentes, toca música de espera, anuncia a
         posição e mede o nível de serviço.`,
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-nova-fila>Criar a primeira</button>' : '')}
      </div>`;
    }

    const linhas = this._itens.map(f => {
      const sinais = [
        Number(f.callcenter) && '<span class="badge badge-brand">call center</span>',
        Number(f.gravar) && '<span class="badge">grava</span>',
        f.pesquisa_id && '<span class="badge badge-info">pesquisa</span>',
        Number(f.confirmar_atendimento) && '<span class="badge">confirma</span>'
      ].filter(Boolean).join(' ');

      return `<tr data-id="${f.id}" data-busca="${esc(`${f.numero} ${f.nome} ${f.descricao || ''}`.toLowerCase())}">
        <td><b class="mono" style="font-size:15px">${esc(f.numero)}</b></td>
        <td><b>${esc(f.nome)}</b>
          ${f.descricao ? `<div class="tiny muted">${esc(f.descricao)}</div>` : ''}
          ${sinais ? `<div class="row gap-4 wrap" style="margin-top:4px">${sinais}</div>` : ''}</td>
        <td><span class="badge">${esc(ESTRATEGIAS_FILA.find(e => e.valor === f.estrategia)?.rotulo || f.estrategia)}</span></td>
        <td class="num">${f.sla_segundos}s</td>
        <td class="num">${duracao(f.max_espera)}</td>
        <td>${Number(f.ativo)
          ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>'
          : '<span class="badge">Parada</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          <button class="btn btn-ghost btn-sm btn-icon" data-tip="Situação agora" data-situacao="${f.id}">
            ${icon('activity','ico ico-sm')}</button>
          ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-agentes="${f.id}">
            ${icon('users','ico ico-sm')} Agentes</button>` : ''}
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-editar="${f.id}">
            ${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir="${f.id}">
            ${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td>
      </tr>`;
    }).join('');

    return cabecalho + `
      <div class="card">
        <div class="toolbar">
          <div class="input-icon search-mini">${icon('search','ico ico-sm')}
            <input class="input" data-filtro placeholder="Buscar por número ou nome…">
          </div>
          <span class="grow"></span>
          <span class="small muted" data-contador></span>
        </div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Fila</th><th>Nome</th><th>Estratégia</th><th>SLA</th>
                     <th>Espera máx.</th><th>Estado</th><th></th></tr></thead>
          <tbody>${linhas}</tbody>
        </table></div>
      </div>`;
  },

  /** Os campos da fila, em abas. */
  campos(f = {}) {
    const anuncios = () => opcoesAnuncio(this._anuncios);

    return [
      { aba: 'Geral', campo: 'numero', label: 'Número da fila', obrigatorio: true, mono: true,
        placeholder: '3000', ajuda: 'É o que se disca para cair na fila.' },
      { aba: 'Geral', campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Suporte Técnico' },
      { aba: 'Geral', campo: 'descricao', label: 'Descrição', largura: 'full' },
      { aba: 'Geral', campo: 'estrategia', label: 'Estratégia de toque', tipo: 'select',
        opcoes: ESTRATEGIAS_FILA, padrao: 'rrmemory', largura: 'full' },
      { aba: 'Geral', campo: 'timeout_agente', label: 'Toque por agente (s)', tipo: 'number', padrao: 20,
        ajuda: 'Quanto tempo o telefone de cada agente toca antes de passar para o próximo.' },
      { aba: 'Geral', campo: 'retry', label: 'Intervalo entre tentativas (s)', tipo: 'number', padrao: 5 },
      { aba: 'Geral', campo: 'wrapuptime', label: 'Pausa pós-atendimento (s)', tipo: 'number', padrao: 10,
        ajuda: 'Tempo que o agente fica livre de chamadas depois de desligar, para anotar o atendimento.' },
      { aba: 'Geral', campo: 'sla_segundos', label: 'Meta de SLA (s)', tipo: 'number', padrao: 20,
        ajuda: 'Atender dentro disso conta como dentro da meta nos relatórios.' },
      { aba: 'Geral', campo: 'gravar', label: 'Gravar as chamadas', tipo: 'switch', padrao: 1 },
      { aba: 'Geral', campo: 'ativo', label: 'Fila ativa', tipo: 'switch', padrao: 1 },
      { aba: 'Geral', campo: 'callcenter', label: 'Fila de call center', tipo: 'switch',
        ajuda: 'Marcando isto, os agentes não são montados aqui: quem atribui é o módulo de call center, na criação do agente.' },

      { aba: 'Áudios', campo: 'musica_espera', label: 'Música em espera', tipo: 'select',
        opcoes: [{ valor: 'default', rotulo: 'Padrão do sistema' },
                 ...this._audios.filter(a => a.categoria === 'espera')
                                .map(a => ({ valor: a.arquivo, rotulo: a.nome }))],
        padrao: 'default', largura: 'full' },
      { aba: 'Áudios', campo: 'anuncio_entrada_id', label: 'Anúncio de entrada, para o cliente',
        tipo: 'select', opcoes: anuncios(), largura: 'full',
        ajuda: 'Toca uma vez, assim que a chamada entra na fila. Ex.: "Você ligou para o suporte, aguarde."' },
      { aba: 'Áudios', campo: 'anuncio_agente_id', label: 'Sussurro, para quem vai atender',
        tipo: 'select', opcoes: anuncios(), largura: 'full',
        ajuda: 'Só o agente ouve, antes de a conversa começar. É como ele sabe de qual fila veio a chamada.' },
      { aba: 'Áudios', campo: 'anuncio_periodico_id', label: 'Anúncio periódico, para quem espera',
        tipo: 'select', opcoes: anuncios(), largura: 'full' },
      { aba: 'Áudios', campo: 'periodico_segundos', label: 'A cada quantos segundos', tipo: 'number',
        padrao: 60 },

      { aba: 'Espera', campo: 'anuncio_posicao', label: 'Anunciar a posição na fila', tipo: 'switch', padrao: 1 },
      { aba: 'Espera', campo: 'anuncio_espera', label: 'Anunciar o tempo estimado', tipo: 'switch',
        ajuda: 'O Asterisk calcula pela média das últimas chamadas.' },
      { aba: 'Espera', campo: 'anuncio_frequencia', label: 'Repetir os anúncios a cada (s)',
        tipo: 'number', padrao: 30 },
      { aba: 'Espera', campo: 'max_espera', label: 'Espera máxima (s)', tipo: 'number', padrao: 300,
        ajuda: 'Passando disso, a chamada sai para o destino de tempo esgotado.' },
      { aba: 'Espera', campo: 'max_chamadas', label: 'Máximo de chamadas na fila', tipo: 'number', padrao: 0,
        ajuda: '0 = sem limite. Ao encher, a chamada vai para o destino de fila cheia.' },

      { aba: 'Comportamento', campo: 'entrar_vazia', label: 'Entrar quando não há agente logado',
        tipo: 'select', opcoes: VAZIA_FILA, padrao: 'sim', largura: 'full' },
      { aba: 'Comportamento', campo: 'sair_vazia', label: 'Sair se a fila ficar sem agente',
        tipo: 'select', opcoes: VAZIA_FILA, padrao: 'nao', largura: 'full' },
      { aba: 'Comportamento', campo: 'peso', label: 'Peso da fila', tipo: 'number', padrao: 0,
        ajuda: 'Entre filas que dividem os mesmos agentes, a de maior peso é servida primeiro.' },
      { aba: 'Comportamento', campo: 'tocar_ocupado', label: 'Tocar em agente que já está em chamada',
        tipo: 'switch',
        ajuda: 'Deixe desligado, salvo se os agentes usam softphone com várias linhas.' },
      { aba: 'Comportamento', campo: 'pausa_automatica', label: 'Pausar quem não atende', tipo: 'select',
        opcoes: [{ valor: 'nao', rotulo: 'Não pausar' },
                 { valor: 'sim', rotulo: 'Pausar nesta fila' },
                 { valor: 'todas', rotulo: 'Pausar em todas as filas dele' }], largura: 'full',
        ajuda: 'Evita a chamada rodar num agente que saiu da mesa sem se pausar.' },
      { aba: 'Comportamento', campo: 'atraso_atendimento', label: 'Atraso antes de conectar (s)',
        tipo: 'number', padrao: 0,
        ajuda: 'Um ou dois segundos ajudam o agente a se preparar depois do sussurro.' },
      { aba: 'Comportamento', campo: 'confirmar_atendimento', label: 'Exigir confirmação do agente',
        tipo: 'switch', largura: 'full',
        ajuda: 'O agente ouve o sussurro e precisa apertar 1 para assumir. Evita a chamada morrer na caixa postal de um celular. Vale para os agentes montados nesta tela.' },

      { aba: 'Failover', campo: 'destino_estouro_tipo', label: 'Tempo de espera esgotado — tipo',
        tipo: 'select', opcoes: this._tiposDestino, largura: 'full' },
      { aba: 'Failover', campo: 'destino_estouro_valor', label: 'Tempo esgotado — destino', mono: true,
        ajuda: 'Ramal, fila, número da URA ou caixa postal, conforme o tipo.' },
      { aba: 'Failover', campo: 'destino_vazia_tipo', label: 'Fila sem agente — tipo',
        tipo: 'select', opcoes: this._tiposDestino, largura: 'full' },
      { aba: 'Failover', campo: 'destino_vazia_valor', label: 'Sem agente — destino', mono: true },
      { aba: 'Failover', campo: 'destino_cheia_tipo', label: 'Fila cheia — tipo',
        tipo: 'select', opcoes: this._tiposDestino, largura: 'full' },
      { aba: 'Failover', campo: 'destino_cheia_valor', label: 'Fila cheia — destino', mono: true },

      { aba: 'Pesquisa', campo: 'pesquisa_id', label: 'Pesquisa de satisfação', tipo: 'select',
        opcoes: [{ valor: '', rotulo: '— não perguntar nada —' },
                 ...this._pesquisas.filter(p => Number(p.ativo))
                                   .map(p => ({ valor: p.id, rotulo: p.nome }))],
        largura: 'full',
        ajuda: 'Quando o atendente desliga, o cliente vai automaticamente para a pesquisa em vez de a chamada cair.' }
    ];
  },

  get _tiposDestino() {
    return [{ valor: '', rotulo: '— nada, apenas desliga —' },
            { valor: 'ramal', rotulo: 'Ramal' }, { valor: 'fila', rotulo: 'Outra fila' },
            { valor: 'ura', rotulo: 'URA' }, { valor: 'voicemail', rotulo: 'Correio de voz' },
            { valor: 'anuncio', rotulo: 'Anúncio' },
            { valor: 'personalizado', rotulo: 'Destino personalizado' },
            { valor: 'desligar', rotulo: 'Desligar' }];
  },

  mount(ctx) {
    const pagina = this;
    const itens = this._itens || [];

    const filtro = document.querySelector('[data-filtro]');
    const contador = document.querySelector('[data-contador]');
    const linhas = [...document.querySelectorAll('tr[data-id]')];
    const aplicar = () => {
      const t = (filtro?.value || '').trim().toLowerCase();
      let n = 0;
      linhas.forEach(l => { const ok = !t || l.dataset.busca.includes(t); l.hidden = !ok; if (ok) n++; });
      if (contador) contador.textContent = `${n} de ${linhas.length} filas`;
    };
    filtro?.addEventListener('input', aplicar);
    aplicar();

    // ---------- cadastro ----------
    const formulario = item => {
      const novo = !item;
      const f = item || {};
      const campos = pagina.campos(f);
      const abas = [...new Set(campos.map(c => c.aba))];
      const corpoAba = aba => `<div class="form-grid">${campos
        .filter(c => c.aba === aba).map(c => campoHtml(c, f)).join('')}</div>`;

      Drawer.open({
        titulo: novo ? 'Nova fila' : `Fila ${f.numero} — ${f.nome}`,
        sub: 'Os agentes são montados no botão Agentes, na lista.',
        wide: true,
        corpo: `<div class="tabs" data-abas>${abas.map((a, i) =>
            `<button class="tab ${i === 0 ? 'on' : ''}" data-aba="${esc(a)}">${esc(a)}</button>`).join('')}</div>
          ${abas.map((a, i) => `<div data-painel="${esc(a)}" ${i ? 'hidden' : ''}>${corpoAba(a)}</div>`).join('')}`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar fila' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          dw.querySelectorAll('[data-aba]').forEach(t => t.onclick = () => {
            dw.querySelectorAll('[data-aba]').forEach(x => x.classList.remove('on'));
            t.classList.add('on');
            dw.querySelectorAll('[data-painel]').forEach(p => p.hidden = p.dataset.painel !== t.dataset.aba);
          });

          // Marcar call center apaga o sentido de montar agentes aqui.
          const cc = dw.querySelector('[name="callcenter"]');
          const confirma = dw.querySelector('[name="confirmar_atendimento"]');
          const revisarCc = () => {
            if (!cc || !confirma) return;
            const campo = confirma.closest('.field');
            campo.style.opacity = cc.checked ? '.5' : '';
            confirma.disabled = cc.checked;
            let nota = campo.querySelector('.nota-cc');
            if (cc.checked && !nota) {
              campo.insertAdjacentHTML('beforeend',
                '<span class="hint nota-cc">Numa fila de call center a confirmação é definida no agente.</span>');
            } else if (!cc.checked && nota) { nota.remove(); }
          };
          cc?.addEventListener('change', revisarCc);
          revisarCc();

          dw.querySelector('[data-ok]').onclick = async ev => {
            if (!validarCampos(dw, campos)) return;

            const dados = {};
            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (!el) return;
              dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
            });

            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              if (novo) await Api.post('/filas', dados);
              else await Api.put(`/filas/${f.id}`, dados);
              Drawer.close();
              toast('Fila salva. Aplique as configurações para valer no Asterisk.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Criar fila' : 'Salvar';
              if (e.detalhe?.campo) {
                const alvo = campos.find(c => c.campo === e.detalhe.campo);
                if (alvo) dw.querySelector(`[data-aba="${alvo.aba}"]`)?.click();
                marcarErro(dw, e.detalhe.campo, e.message);
                avisoFormulario(dw, [`${alvo?.label || e.detalhe.campo}: ${e.message}`]);
              } else if (e.status === 409) {
                marcarErro(dw, 'numero', 'Já existe uma fila com este número.');
              } else { toast(e.message, 'err'); }
            }
          };
        }
      });
    };

    document.querySelectorAll('[data-nova-fila]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editar)));

    document.querySelectorAll('[data-excluir]').forEach(b => b.onclick = async () => {
      const f = itens.find(x => String(x.id) === b.dataset.excluir);
      const ok = await Modal.confirm({
        titulo: `Excluir a fila ${f.numero}?`,
        texto: 'Os agentes são desvinculados e as rotas que apontavam para ela ficam sem destino.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/filas/${f.id}`); toast('Fila excluída.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });

    // ---------- agentes ----------
    document.querySelectorAll('[data-agentes]').forEach(b => b.onclick = async () => {
      let d;
      try { d = await Api.get(`/filas/${b.dataset.agentes}/agentes`); }
      catch (e) { toast(e.message, 'err'); return; }

      const linha = a => `<tr data-ramal="${a.ramal_id}">
        <td><b class="mono">${esc(a.numero)}</b> ${esc(a.nome)}
          ${a.origem === 'callcenter' ? '<span class="badge badge-brand">call center</span>' : ''}
          ${a.setor ? `<div class="tiny muted">${esc(a.setor)}</div>` : ''}</td>
        <td><input class="input mono" type="number" min="0" max="255" style="width:80px"
                   value="${a.penalidade ?? 0}" data-pen></td>
        <td><select class="select" data-tipo style="width:130px">
          <option value="estatico" ${a.tipo === 'estatico' ? 'selected' : ''}>Fixo</option>
          <option value="dinamico" ${a.tipo === 'dinamico' ? 'selected' : ''}>Entra por código</option>
        </select></td>
        <td class="col-actions">
          <button class="btn btn-ghost btn-sm btn-icon" data-tip="Tirar da fila" data-tirar>
            ${icon('x','ico ico-sm')}</button></td>
      </tr>`;

      Drawer.open({
        titulo: `Agentes da fila ${d.fila.numero}`,
        sub: d.fila.callcenter
          ? 'Esta é uma fila de call center: a lista vem do módulo de call center e não é editada aqui.'
          : 'Penalidade menor atende primeiro. "Entra por código" não vai para o arquivo: o agente entra e sai com *45.',
        wide: true,
        corpo: `
          ${d.fila.callcenter ? `<div class="aviso-form">${icon('info','ico')}
            <div><b>Fila de call center</b><div class="tiny">Os agentes são atribuídos quando o
              agente é criado no módulo de call center. Para montar a lista aqui, desmarque
              "fila de call center" no cadastro.</div></div></div>` : ''}

          <div class="row gap-8" style="margin-bottom:12px">
            <select class="select grow" id="novoAgente" ${d.fila.callcenter ? 'disabled' : ''}>
              <option value="">Escolha um ramal para adicionar…</option>
              ${d.disponiveis.map(r => `<option value="${r.id}">${esc(r.numero)} — ${esc(r.nome)}</option>`).join('')}
            </select>
            <button class="btn btn-outline" id="addAgente" ${d.fila.callcenter ? 'disabled' : ''}>
              ${icon('plus','ico ico-sm')} Adicionar</button>
          </div>

          <div class="table-wrap"><table class="table" id="tabelaAgentes">
            <thead><tr><th>Agente</th><th>Penalidade</th><th>Como entra</th><th></th></tr></thead>
            <tbody>${d.agentes.map(linha).join('')}</tbody>
          </table></div>
          ${d.agentes.length ? '' : '<p class="hint" style="margin-top:12px">Nenhum agente nesta fila ainda.</p>'}`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Fechar</button>
                 ${d.fila.callcenter ? '' : '<button class="btn btn-primary" data-ok>Salvar agentes</button>'}`,
        aoAbrir: dw => {
          const corpo = dw.querySelector('#tabelaAgentes tbody');

          const ligarRemocao = () => dw.querySelectorAll('[data-tirar]').forEach(x =>
            x.onclick = () => x.closest('tr').remove());
          ligarRemocao();

          dw.querySelector('#addAgente')?.addEventListener('click', () => {
            const sel = dw.querySelector('#novoAgente');
            if (!sel.value) { toast('Escolha um ramal.', 'warn'); return; }
            const r = d.disponiveis.find(x => String(x.id) === sel.value);
            corpo.insertAdjacentHTML('beforeend', linha({
              ramal_id: r.id, numero: r.numero, nome: r.nome, setor: r.setor,
              penalidade: 0, tipo: 'estatico', origem: 'manual'
            }));
            sel.querySelector(`option[value="${sel.value}"]`).remove();
            sel.value = '';
            ligarRemocao();
          });

          const ok = dw.querySelector('[data-ok]');
          if (ok) ok.onclick = async ev => {
            const agentes = [...corpo.querySelectorAll('tr[data-ramal]')].map(tr => ({
              ramal_id: Number(tr.dataset.ramal),
              penalidade: Number(tr.querySelector('[data-pen]').value || 0),
              tipo: tr.querySelector('[data-tipo]').value
            }));
            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              await Api.put(`/filas/${d.fila.id}/agentes`, { agentes });
              Drawer.close();
              toast(`${agentes.length} agente(s) na fila. Aplique as configurações.`, 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = 'Salvar agentes';
              toast(e.message, 'err');
            }
          };
        }
      });
    });

    // ---------- situação ao vivo ----------
    document.querySelectorAll('[data-situacao]').forEach(b => b.onclick = async () => {
      const f = itens.find(x => String(x.id) === b.dataset.situacao);
      let d;
      try { d = await Api.get(`/filas/${f.id}/situacao`); }
      catch (e) { toast(e.message, 'err'); return; }

      Drawer.open({
        titulo: `Fila ${f.numero} agora`,
        sub: d.disponivel ? `${d.esperando} chamada(s) esperando` : d.detalhe,
        corpo: !d.disponivel
          ? vazio('alert', 'Asterisk fora do ar', esc(d.detalhe))
          : `${d.membros.length ? `<div class="table-wrap"><table class="table">
              <thead><tr><th>Agente</th><th>Estado</th><th>Penalidade</th><th>Atendidas</th></tr></thead>
              <tbody>${d.membros.map(m => `<tr>
                <td><b class="mono">${esc(m.ramal)}</b> ${esc(m.nome)}
                  <div class="tiny muted mono">${esc(m.interface)}</div></td>
                <td>${m.pausado
                  ? `<span class="badge badge-warn">Pausado${m.motivo ? ` — ${esc(m.motivo)}` : ''}</span>`
                  : `<span class="badge ${m.estado === 'Not in use' ? 'badge-ok' : 'badge-info'}">${esc(m.estado)}</span>`}</td>
                <td class="num">${m.penalidade}</td>
                <td class="num">${m.chamadas}</td>
              </tr>`).join('')}</tbody></table></div>`
            : vazio('users', 'Nenhum agente logado', 'Ninguém está atendendo esta fila neste momento.')}
            <details style="margin-top:16px"><summary class="small muted">Saída bruta do Asterisk</summary>
              <pre class="mono tiny" style="white-space:pre-wrap;margin-top:8px">${esc(d.saida)}</pre></details>`,
        rodape: '<button class="btn btn-outline" data-drawer-close>Fechar</button>'
      });
    });

    // ---------- pesquisas ----------
    document.getElementById('verPesquisas')?.addEventListener('click', () => paginaPesquisas(ctx, pagina));
  }
};

/** Gaveta de pesquisas de satisfação, aberta pela tela de filas. */
async function paginaPesquisas(ctx, pagina) {
  let r;
  try { r = await Api.get('/pesquisas', { limite: 100 }); }
  catch (e) { toast(e.message, 'err'); return; }

  const anuncios = opcoesAnuncio(pagina._anuncios, '— sem anúncio, só um bipe —');

  const campos = p => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, largura: 'full', placeholder: 'Nota do atendimento' },
    { campo: 'descricao', label: 'Descrição', largura: 'full' },
    { campo: 'anuncio_pergunta_id', label: 'Anúncio da pergunta', tipo: 'select', opcoes: anuncios, largura: 'full',
      ajuda: 'Ex.: "De 1 a 5, que nota você dá para o atendimento?". Sem anúncio, o cliente só ouve um bipe — crie o seu em Aplicações › Anúncios.' },
    { campo: 'anuncio_obrigado_id', label: 'Anúncio de agradecimento', tipo: 'select', opcoes: anuncios, largura: 'full' },
    { campo: 'nota_min', label: 'Nota mínima', tipo: 'number', padrao: 1 },
    { campo: 'nota_max', label: 'Nota máxima', tipo: 'number', padrao: 5,
      ajuda: 'A resposta é um dígito só, então vai de 1 a 9.' },
    { campo: 'tentativas', label: 'Tentativas', tipo: 'number', padrao: 2 },
    { campo: 'segundos', label: 'Segundos para responder', tipo: 'number', padrao: 8 },
    { campo: 'ativo', label: 'Pesquisa ativa', tipo: 'switch', padrao: 1 }
  ];

  const formulario = item => {
    const novo = !item;
    const p = item || {};
    const cs = campos(p);
    Drawer.open({
      titulo: novo ? 'Nova pesquisa' : `Editar ${p.nome}`,
      sub: 'O cliente cai aqui quando o atendente desliga, se a fila tiver esta pesquisa escolhida.',
      corpo: `<div class="form-grid">${cs.map(c => campoHtml(c, p)).join('')}</div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" data-ok>Salvar</button>`,
      aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async () => {
        if (!validarCampos(dw, cs)) return;
        const dados = {};
        cs.forEach(c => {
          const el = dw.querySelector(`[name="${c.campo}"]`);
          if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
        });
        try {
          if (novo) await Api.post('/pesquisas', dados);
          else await Api.put(`/pesquisas/${p.id}`, dados);
          Drawer.close();
          toast('Pesquisa salva. Aplique as configurações.', 'ok');
          App.route();
        } catch (e) { toast(e.message, 'err'); }
      }
    });
  };

  Drawer.open({
    titulo: 'Pesquisas de satisfação',
    sub: 'Perguntam a nota logo depois que o atendente desliga a chamada.',
    wide: true,
    corpo: r.dados.length ? `<div class="table-wrap"><table class="table">
        <thead><tr><th>Pesquisa</th><th>Notas</th><th>Anúncio</th><th>Estado</th><th></th></tr></thead>
        <tbody>${r.dados.map(p => `<tr>
          <td><b>${esc(p.nome)}</b>${p.descricao ? `<div class="tiny muted">${esc(p.descricao)}</div>` : ''}</td>
          <td class="num">${p.nota_min} a ${p.nota_max}</td>
          <td>${p.anuncio_pergunta_id
            ? esc((pagina._anuncios || []).find(a => String(a.id) === String(p.anuncio_pergunta_id))?.nome || '—')
            : '<span class="badge badge-warn">só um bipe</span>'}</td>
          <td>${Number(p.ativo) ? '<span class="badge badge-ok">Ativa</span>' : '<span class="badge">Parada</span>'}</td>
          <td class="col-actions"><button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar"
                data-editar-pesq="${p.id}">${icon('edit','ico ico-sm')}</button></td>
        </tr>`).join('')}</tbody></table></div>`
      : vazio('star', 'Nenhuma pesquisa criada',
              'Crie uma para medir a satisfação logo depois do atendimento.'),
    rodape: `<button class="btn btn-outline" data-drawer-close>Fechar</button>
             ${ctx.can('criar') ? '<button class="btn btn-primary" id="novaPesquisa">Nova pesquisa</button>' : ''}`,
    aoAbrir: dw => {
      dw.querySelector('#novaPesquisa')?.addEventListener('click', () => formulario(null));
      dw.querySelectorAll('[data-editar-pesq]').forEach(b => b.onclick = () =>
        formulario(r.dados.find(x => String(x.id) === b.dataset.editarPesq)));
    }
  });
}

/* ------------------------- Relatórios · CDR ------------------------- */
PAGES['rel.cdr'] = {
  _f: { de: '', ate: '', direcao: '', status: '', q: '', pagina: 1 },

  async render(ctx) {
    const hoje = new Date().toISOString().slice(0, 10);
    if (!this._f.de) { this._f.de = hoje; this._f.ate = hoje; }

    let r;
    try { r = await Api.get('/cdr', this._f); }
    catch (e) { return pageHead('CDR — Registro de Chamadas', '') + blocoErro(e); }

    const corpo = r.dados.length ? `
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Data / hora</th><th>Origem</th><th>Destino</th><th>Sentido</th>
                   <th>Duração</th><th>Falado</th><th>Tronco</th><th>Estado</th><th>Gravação</th></tr></thead>
        <tbody>${r.dados.map(c => `
          <tr>
            <td class="mono small">${dataHora(c.calldate)}</td>
            <td class="mono">${esc(c.src)}</td>
            <td class="mono">${esc(c.dst)}
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ligar para este número"
                      data-ligar="${esc(c.direcao === 'entrada' ? c.src : c.dst)}"
                      aria-label="Ligar">${icon('phone','ico ico-sm')}</button></td>
            <td>${c.direcao ? `<span class="badge">${icon(DIRECAO_ICO[c.direcao] || 'phone','ico ico-sm')}${esc(c.direcao)}</span>` : '<span class="muted">—</span>'}</td>
            <td class="num">${duracao(c.duration)}</td>
            <td class="num">${duracao(c.billsec)}</td>
            <td class="small dim">${esc(c.tronco || '—')}</td>
            <td>${badgeCdr(c.disposition)}${c.motivo
              ? `<div class="tiny muted" data-tip="${esc(c.motivo)}">${esc(c.motivo)}</div>` : ''}</td>
            <td>${c.gravacao ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Ouvir">${icon('play','ico ico-sm')}</button>` : '<span class="muted">—</span>'}</td>
          </tr>`).join('')}
        </tbody></table></div>
      <div class="card-foot pager">
        <span class="small muted">${num(r.total)} registros · página ${r.pagina} de ${Math.max(1, r.paginas)}</span>
        <div class="pages">
          <button data-pagina="${r.pagina - 1}" ${r.pagina <= 1 ? 'disabled' : ''}>${icon('chevronL','ico ico-sm')}</button>
          <button class="on">${r.pagina}</button>
          <button data-pagina="${r.pagina + 1}" ${r.pagina >= r.paginas ? 'disabled' : ''}>${icon('chevronR','ico ico-sm')}</button>
        </div>
      </div>`
      : vazio('list', 'Nenhuma chamada no período',
              'O CDR é preenchido pelo próprio Asterisk conforme as chamadas acontecem.');

    return pageHead('CDR — Registro de Chamadas',
      'Histórico de chamadas entrantes, saintes e internas.',
      ctx.can('exportar') ? `<button class="btn btn-outline btn-sm" id="exportarCdr">${icon('download','ico ico-sm')} Exportar CSV</button>` : '') + `
    <div class="card">
      <div class="toolbar">
        <input class="input" type="date" data-f="de" value="${this._f.de}" style="width:160px">
        <span class="muted small">até</span>
        <input class="input" type="date" data-f="ate" value="${this._f.ate}" style="width:160px">
        <select class="select" data-f="direcao" style="width:150px">
          <option value="">Todos os sentidos</option>
          ${['entrada','saida','interna'].map(d =>
            `<option value="${d}" ${this._f.direcao === d ? 'selected' : ''}>${d}</option>`).join('')}
        </select>
        <select class="select" data-f="status" style="width:170px">
          <option value="">Todos os estados</option>
          ${Object.entries(DISPOSICAO_CDR).map(([k, v]) =>
            `<option value="${k}" ${this._f.status === k ? 'selected' : ''}>${v[1]}</option>`).join('')}
        </select>
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" data-f="q" value="${esc(this._f.q)}" placeholder="Número ou ramal…"></div>
      </div>
      ${corpo}
    </div>`;
  },

  mount() {
    let t;
    document.querySelectorAll('[data-f]').forEach(el => {
      const evento = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'input';
      el.addEventListener(evento, () => {
        clearTimeout(t);
        t = setTimeout(() => {
          this._f[el.dataset.f] = el.value;
          this._f.pagina = 1;
          App.route();
        }, evento === 'input' ? 400 : 0);
      });
    });
    document.querySelectorAll('[data-ligar]').forEach(b => b.onclick = ev => {
      ev.stopPropagation();
      Softphone.discarPara(b.dataset.ligar);
    });

    document.querySelectorAll('[data-pagina]').forEach(b => b.onclick = () => {
      this._f.pagina = Number(b.dataset.pagina);
      App.route();
    });
    document.getElementById('exportarCdr')?.addEventListener('click',
      () => toast('Exportação assíncrona ainda não implementada no servidor.', 'warn'));
  }
};

/* ------------------------- Administrador · Usuários ------------------------- */
PAGES['admin.usuarios'] = {
  async render(ctx) {
    let usuarios, perfis;
    try {
      [usuarios, perfis] = await Promise.all([Api.get('/usuarios'), Api.get('/perfis')]);
    } catch (e) { return pageHead('Gerenciador de Usuários', '') + blocoErro(e); }

    this._perfis = perfis.dados;
    this._usuarios = usuarios.dados;
    const porId = Object.fromEntries(perfis.dados.map(p => [p.id, p]));

    const cartoes = perfis.dados.map(p => `
      <div class="card kpi">
        <div class="k-top"><span class="k-label">${esc(p.nome)}</span>
          <span class="k-ico" style="background:var(--${p.cor}-soft);color:var(--${p.cor})">${icon('users')}</span></div>
        <div class="k-val">${p.usuarios}</div>
        <div class="k-foot"><span>${esc(p.descricao || '')}</span></div>
      </div>`).join('');

    const linhas = usuarios.dados.map(u => {
      const p = porId[u.perfil_id] || { nome: '?', cor: '' };
      return `<tr data-id="${u.id}">
        <td><span class="row gap-10"><span class="avatar avatar-sm">${initials(u.nome)}</span>
            <span><b>${esc(u.nome)}</b><div class="tiny muted mono">${esc(u.usuario)}</div></span></span></td>
        <td><span class="badge badge-${p.cor}">${icon('shield','ico ico-sm')}${esc(p.nome)}</span></td>
        <td class="mono">${esc(u.ramal || '—')}</td>
        <td class="dim">${esc(u.setor || '—')}</td>
        <td class="small dim">${esc(u.email || '—')}</td>
        <td class="small dim">${u.ultimo_acesso ? dataHora(u.ultimo_acesso) : 'nunca'}</td>
        <td>${u.status === 'ativo' ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
             : u.status === 'inativo' ? '<span class="badge"><i class="dot"></i>Inativo</span>'
             : '<span class="badge badge-danger"><i class="dot"></i>Bloqueado</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-editar="${u.id}">${icon('edit','ico ico-sm')}</button>
          <button class="btn btn-ghost btn-sm btn-icon" data-tip="Definir senha" data-senha="${u.id}">${icon('key','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') && u.usuario !== 'admin'
            ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir="${u.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td>
      </tr>`;
    }).join('');

    return pageHead('Gerenciador de Usuários',
      'Contas de acesso ao console e vínculo com ramais.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm" data-novo>${icon('plus','ico ico-sm')} Novo usuário</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-4" style="margin-bottom:16px">${cartoes}</div>
    <div class="card"><div class="table-wrap"><table class="table">
      <thead><tr><th>Usuário</th><th>Perfil</th><th>Ramal</th><th>Setor</th>
                 <th>E-mail</th><th>Último acesso</th><th>Estado</th><th></th></tr></thead>
      <tbody>${linhas}</tbody>
    </table></div></div>`;
  },

  mount(ctx) {
    const form = (u) => {
      const novo = !u;
      const campos = [
        { campo: 'nome', label: 'Nome completo', obrigatorio: true,
          placeholder: 'Como a pessoa aparece no console' },
        { campo: 'usuario', label: 'Usuário de login', obrigatorio: true, mono: true,
          somenteLeitura: !novo, placeholder: 'ex.: mduarte',
          padraoValido: /^[a-zA-Z0-9._-]{3,64}$/,
          mensagemPadrao: 'use de 3 a 64 letras, números, ponto, hífen ou sublinhado',
          ajuda: novo ? 'Não muda depois de criado.' : '' },
        { campo: 'email', label: 'E-mail', tipo: 'email', placeholder: 'pessoa@empresa.com.br' },
        { campo: 'ramal', label: 'Ramal vinculado', mono: true,
          ajuda: 'Liga a conta ao ramal no PCU e no softphone.' },
        { campo: 'setor', label: 'Setor' },
        { campo: 'perfil_id', label: 'Perfil de acesso', obrigatorio: true, tipo: 'select',
          opcoes: this._perfis.map(p => ({ valor: p.id, rotulo: p.nome })),
          ajuda: 'Define o que a pessoa enxerga e pode fazer.' },
        { campo: 'status', label: 'Estado', tipo: 'select',
          opcoes: [{valor:'ativo',rotulo:'Ativo'},{valor:'inativo',rotulo:'Inativo'},
                   {valor:'bloqueado',rotulo:'Bloqueado'}] }
      ];

      Drawer.open({
        titulo: novo ? 'Novo usuário' : `Editar ${u.nome}`,
        sub: novo ? 'Defina a senha agora: a conta só entra depois disso.' : '',
        corpo: `<div class="form-grid">
          ${campos.map(c => campoHtml(c, u || {})).join('')}
          ${novo ? campoSenha('senha', 'Senha de acesso') +
                   campoHtml({ campo: 'senha2', label: 'Repetir a senha', tipo: 'password',
                               obrigatorio: true, largura: 'full' }, {}) : ''}
        </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-salvar>${novo ? 'Criar usuário' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          ligarRequisitos(dw);

          dw.querySelector('[data-salvar]').onclick = async ev => {
            const botao = ev.currentTarget;
            const rotulo = novo ? 'Criar usuário' : 'Salvar';

            if (!validarCampos(dw, campos)) {
              dw.querySelector('.field.erro [name]')?.focus();
              return;
            }

            const dados = {};
            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (el && !el.disabled) dados[c.campo] = el.value;
            });

            if (novo) {
              const s1 = dw.querySelector('[name="senha"]').value;
              const s2 = dw.querySelector('[name="senha2"]').value;
              const faltando = POLITICA_SENHA.filter(r => !r.testa(s1)).map(r => r.rotulo.toLowerCase());

              if (faltando.length) {
                limparErros(dw);
                marcarErro(dw, 'senha', 'Ainda falta: ' + faltando.join('; ') + '.');
                avisoFormulario(dw, ['Senha de acesso: ' + faltando.join('; ')]);
                dw.querySelector('[name="senha"]').focus();
                return;
              }
              if (s1 !== s2) {
                limparErros(dw);
                marcarErro(dw, 'senha2', 'As senhas não são iguais.');
                avisoFormulario(dw, ['A repetição da senha não confere']);
                dw.querySelector('[name="senha2"]').focus();
                return;
              }
              dados.senha = s1;
            }

            botao.disabled = true;
            botao.textContent = 'Salvando…';
            try {
              if (novo) await Api.post('/usuarios', dados);
              else await Api.put(`/usuarios/${u.id}`, dados);
              Drawer.close();
              toast(novo ? 'Usuário criado.' : 'Usuário atualizado.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = rotulo;
              limparErros(dw);
              if (e.detalhe?.campo) {
                marcarErro(dw, e.detalhe.campo, e.message);
                dw.querySelector(`[name="${e.detalhe.campo}"]`)?.focus();
              } else {
                avisoFormulario(dw, [e.message]);
              }
            }
          };
        }
      });
    };

    const senhaForm = (u) => Drawer.open({
      titulo: `Definir senha de ${u.nome}`,
      sub: 'As sessões abertas dessa pessoa são encerradas assim que a senha muda.',
      corpo: `<div class="form-grid">
        ${campoSenha('senha', 'Nova senha')}
        ${campoHtml({ campo: 'senha2', label: 'Repetir a senha', tipo: 'password',
                      obrigatorio: true, largura: 'full' }, {})}
      </div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" data-ok>Definir senha</button>`,
      aoAbrir: dw => {
        ligarRequisitos(dw);

        dw.querySelector('[data-ok]').onclick = async ev => {
          const s1 = dw.querySelector('[name="senha"]').value;
          const s2 = dw.querySelector('[name="senha2"]').value;
          const faltando = POLITICA_SENHA.filter(r => !r.testa(s1)).map(r => r.rotulo.toLowerCase());

          limparErros(dw);
          if (faltando.length) {
            marcarErro(dw, 'senha', 'Ainda falta: ' + faltando.join('; ') + '.');
            dw.querySelector('[name="senha"]').focus();
            return;
          }
          if (s1 !== s2) {
            marcarErro(dw, 'senha2', 'As senhas não são iguais.');
            dw.querySelector('[name="senha2"]').focus();
            return;
          }

          ev.currentTarget.disabled = true;
          try {
            await Api.post(`/usuarios/${u.id}/senha`, { senha: s1 });
            Drawer.close(); toast('Senha definida.', 'ok'); App.route();
          } catch (e) {
            ev.currentTarget.disabled = false;
            marcarErro(dw, 'senha', e.message);
          }
        };
      }
    });

    document.querySelectorAll('[data-novo]').forEach(b => b.onclick = () => form(null));
    document.querySelectorAll('[data-editar]').forEach(b =>
      b.onclick = () => form(this._usuarios.find(u => String(u.id) === b.dataset.editar)));
    document.querySelectorAll('[data-senha]').forEach(b =>
      b.onclick = () => senhaForm(this._usuarios.find(u => String(u.id) === b.dataset.senha)));
    document.querySelectorAll('[data-excluir]').forEach(b => b.onclick = async () => {
      const u = this._usuarios.find(x => String(x.id) === b.dataset.excluir);
      const ok = await Modal.confirm({
        titulo: `Excluir ${u.nome}?`,
        texto: `A conta ${u.usuario} perde o acesso imediatamente.`, ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/usuarios/${u.id}`); toast('Usuário excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Administrador · Perfis e permissões ------------------------- */
const ACOES_PERFIL = [
  { chave: 'criar',      rotulo: 'Criar registros',            ajuda: 'Adicionar ramais, filas, rotas, usuários…' },
  { chave: 'editar',     rotulo: 'Editar registros',           ajuda: 'Alterar o que já está cadastrado' },
  { chave: 'excluir',    rotulo: 'Excluir registros',          ajuda: 'Remover cadastros em definitivo' },
  { chave: 'exportar',   rotulo: 'Exportar relatórios',        ajuda: 'Baixar CDR, gravações e tarifação' },
  { chave: 'reiniciar',  rotulo: 'Aplicar configurações',      ajuda: 'Recarregar o Asterisk e reiniciar aparelhos' },
  { chave: 'permissoes', rotulo: 'Gerenciar perfis',           ajuda: 'Criar perfis e alterar esta própria matriz' }
];

const CORES_PERFIL = [
  { valor: 'brand',  rotulo: 'Azul (padrão)' },
  { valor: 'info',   rotulo: 'Azul claro' },
  { valor: 'ok',     rotulo: 'Verde' },
  { valor: 'warn',   rotulo: 'Âmbar' },
  { valor: 'danger', rotulo: 'Vermelho' }
];

PAGES['admin.permissoes'] = {
  async render(ctx) {
    let r;
    try { r = await Api.get('/perfis'); }
    catch (e) { return pageHead('Perfis e Permissões', '') + blocoErro(e); }

    this._perfis = r.dados;
    const editavel = ctx.can('permissoes');

    const podeModulo = (p, id) => (p.allow || []).some(regra =>
      regra === '*' || regra === id || (regra.endsWith('.*') && id.startsWith(regra.slice(0, -1))));

    const cartoes = r.dados.map(p => `
      <div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:10px">
          <div class="row gap-10" style="min-width:0">
            <span class="k-ico" style="background:var(--${p.cor}-soft);color:var(--${p.cor});width:34px;height:34px;border-radius:10px;display:grid;place-items:center">${icon('shield')}</span>
            <div style="min-width:0"><b class="truncate" style="display:block">${esc(p.nome)}</b>
              <div class="tiny muted mono">${esc(p.chave)}</div></div>
          </div>
          ${editavel ? `<div class="row-actions" style="opacity:1">
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-editar-perfil="${p.id}">${icon('edit','ico ico-sm')}</button>
            ${Number(p.sistema) ? '' : `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir-perfil="${p.id}">${icon('trash','ico ico-sm')}</button>`}
          </div>` : ''}
        </div>
        <p class="small dim" style="min-height:36px">${esc(p.descricao || 'Sem descrição.')}</p>
        <div class="row-between" style="margin-top:10px">
          <span class="badge">${p.usuarios} usuário${p.usuarios === 1 ? '' : 's'}</span>
          ${Number(p.sistema) ? '<span class="badge">perfil do sistema</span>' : ''}
        </div>
      </div>`).join('');

    const colunaPerfis = r.dados.map(p => `<th class="role-col">${esc(p.nome)}</th>`).join('');

    const linhaAcoes = ACOES_PERFIL.map(a => `
      <tr>
        <td><span class="mod-name">${icon('key','ico ico-sm')}${esc(a.rotulo)}
            <span class="tiny muted">${esc(a.ajuda)}</span></span></td>
        ${r.dados.map(p => `<td class="role-cell">
          <label class="switch"><input type="checkbox" ${(p.caps || []).includes(a.chave) ? 'checked' : ''}
                 ${editavel ? '' : 'disabled'} data-perfil="${p.id}" data-acao="${a.chave}">
            <span class="track"></span></label></td>`).join('')}
      </tr>`).join('');

    const linhasModulos = MENU.map(g => `
      <tr class="group-row"><td colspan="${r.dados.length + 1}">${esc(g.label)}</td></tr>
      ${g.items.map(i => `
        <tr>
          <td><span class="mod-name">${icon(i.icon || 'grid','ico ico-sm')}${esc(i.label)}
              <span class="tiny muted mono">${esc(i.id)}</span></span></td>
          ${r.dados.map(p => `<td class="role-cell">
            <label class="switch"><input type="checkbox" ${podeModulo(p, i.id) ? 'checked' : ''}
                   ${editavel ? '' : 'disabled'} data-perfil="${p.id}" data-modulo="${esc(i.id)}">
              <span class="track"></span></label></td>`).join('')}
        </tr>`).join('')}`).join('');

    return pageHead('Perfis e Permissões',
      'Crie perfis sob medida e defina, por perfil, o que aparece no menu e o que pode ser feito. O servidor revalida tudo a cada requisição.',
      editavel
        ? `<button class="btn btn-outline btn-sm" id="novoPerfil">${icon('plus','ico ico-sm')} Novo perfil</button>
           <button class="btn btn-primary btn-sm" id="salvarPerms">${icon('check','ico ico-sm')} Salvar alterações</button>`
        : readOnlyNote(ctx)) + `

    <div class="grid g-4" style="margin-bottom:16px">${cartoes}</div>

    <div class="card">
      <div class="card-head">
        <div><div class="card-title">Matriz de permissões</div>
             <div class="card-sub">As ações valem para todos os módulos liberados ao perfil.</div></div>
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" id="permQ" placeholder="Filtrar módulo…"></div>
      </div>
      <div class="table-wrap" style="max-height:640px;overflow-y:auto">
        <table class="table matrix">
          <thead><tr><th>O que pode</th>${colunaPerfis}</tr></thead>
          <tbody>
            <tr class="group-row"><td colspan="${r.dados.length + 1}">Ações</td></tr>
            ${linhaAcoes}
            ${linhasModulos}
          </tbody>
        </table>
      </div>
    </div>`;
  },

  mount(ctx) {
    const q = document.getElementById('permQ');
    q?.addEventListener('input', () => {
      const t = q.value.trim().toLowerCase();
      document.querySelectorAll('.matrix tbody tr').forEach(tr => {
        if (tr.classList.contains('group-row')) { tr.hidden = !!t; return; }
        tr.hidden = t && !tr.textContent.toLowerCase().includes(t);
      });
    });

    const formPerfil = (p) => {
      const novo = !p;
      const campos = [
        { campo: 'nome', label: 'Nome do perfil', obrigatorio: true, placeholder: 'Supervisor de Filas' },
        ...(novo ? [{ campo: 'chave', label: 'Chave (opcional)', mono: true,
                      placeholder: 'gerada a partir do nome',
                      ajuda: 'Identificador interno. Deixe em branco para gerar automaticamente.' }] : []),
        { campo: 'cor', label: 'Cor da etiqueta', tipo: 'select', opcoes: CORES_PERFIL },
        { campo: 'descricao', label: 'Descrição', tipo: 'textarea', largura: 'full',
          placeholder: 'O que este perfil acompanha ou administra' }
      ];

      Drawer.open({
        titulo: novo ? 'Novo perfil' : `Editar ${p.nome}`,
        sub: novo
          ? 'O perfil nasce sem nenhuma permissão. Marque na matriz o que ele pode ver e fazer.'
          : 'Permissões continuam sendo ajustadas na matriz.',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, p || { cor: 'brand' })).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar perfil' : 'Salvar'}</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          const dados = {};
          campos.forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.value;
          });
          if (!dados.nome?.trim()) { toast('Informe o nome do perfil.', 'warn'); return; }

          ev.currentTarget.disabled = true;
          try {
            if (novo) await Api.post('/perfis', dados);
            else await Api.put(`/perfis/${p.id}`, dados);
            Drawer.close();
            toast(novo ? 'Perfil criado. Agora marque as permissões dele.' : 'Perfil atualizado.', 'ok');
            App.route();
          } catch (e) {
            ev.currentTarget.disabled = false;
            toast(e.message, 'err');
          }
        }
      });
    };

    document.getElementById('novoPerfil')?.addEventListener('click', () => formPerfil(null));
    document.querySelectorAll('[data-editar-perfil]').forEach(b =>
      b.onclick = () => formPerfil(this._perfis.find(p => String(p.id) === b.dataset.editarPerfil)));

    document.querySelectorAll('[data-excluir-perfil]').forEach(b => b.onclick = async () => {
      const p = this._perfis.find(x => String(x.id) === b.dataset.excluirPerfil);
      const ok = await Modal.confirm({
        titulo: `Excluir o perfil ${p.nome}?`,
        texto: p.usuarios > 0
          ? `Há ${p.usuarios} usuário(s) com este perfil. Mova essas contas antes.`
          : 'As permissões deste perfil serão perdidas.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/perfis/${p.id}`); toast('Perfil excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });

    document.getElementById('salvarPerms')?.addEventListener('click', async ev => {
      const botao = ev.currentTarget;
      botao.disabled = true;
      botao.innerHTML = `<span class="spin"></span> Salvando…`;

      try {
        for (const p of this._perfis) {
          const modulos = [...document.querySelectorAll(`[data-perfil="${p.id}"][data-modulo]:checked`)]
            .map(c => c.dataset.modulo);
          const acoes = [...document.querySelectorAll(`[data-perfil="${p.id}"][data-acao]:checked`)]
            .map(c => c.dataset.acao);

          // Só mantemos o curinga em quem já o tinha. Um perfil comum recebe a
          // lista explícita, para não herdar módulos futuros sem alguém decidir.
          const tinhaCuringa = (p.allow || []).includes('*');
          const total = document.querySelectorAll(`[data-perfil="${p.id}"][data-modulo]`).length;

          await Api.put(`/perfis/${p.id}/permissoes`, {
            allow: (tinhaCuringa && modulos.length === total) ? ['*'] : modulos,
            caps: acoes
          });
        }
        toast('Permissões salvas. Cada usuário verá a mudança no próximo carregamento.', 'ok');
        App.route();
      } catch (e) {
        botao.disabled = false;
        botao.innerHTML = `${icon('check','ico ico-sm')} Salvar alterações`;
        toast(e.message, 'err');
      }
    });
  }
};

/* ------------------------- Fallback e acesso negado ------------------------- */
function paginaGenerica(ctx) {
  const { item, group } = ctx;
  return pageHead(item.label, `Módulo do grupo ${group.label}.`) + `
    <div class="card">${vazio('package', 'Módulo ainda não implementado',
      `A tela de <b>${esc(item.label)}</b> (<span class="mono">${esc(item.id)}</span>) ainda não foi construída.
       Nada é exibido aqui para não passar a impressão de que existe configuração ativa.`)}
    </div>`;
}

function paginaNegada(key) {
  return `<div class="denied">
    <div class="lockcircle">${icon('lock')}</div>
    <h2 style="font-size:19px;margin-bottom:8px">Acesso não autorizado</h2>
    <p class="dim">Seu perfil não tem permissão para abrir
       <span class="mono">${esc(key)}</span>. Fale com um administrador.</p>
    <div style="margin-top:18px"><a class="btn btn-primary btn-sm" href="#/${Auth.home()}">Voltar ao início</a></div>
  </div>`;
}
