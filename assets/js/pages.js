/* =========================================================
   Telium PABX — telas (núcleo)
   Todo dado vem da API. Base vazia mostra estado vazio,
   nunca número inventado.
   ========================================================= */

/* ------------------------- Helpers ------------------------- */
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
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
          dw.querySelectorAll('[data-aba]').forEach(t => t.onclick = () => {
            dw.querySelectorAll('[data-aba]').forEach(x => x.classList.remove('on'));
            t.classList.add('on');
            dw.querySelectorAll('[data-painel]').forEach(p =>
              p.hidden = p.dataset.painel !== t.dataset.aba);
          });

          dw.querySelector('[data-salvar]').onclick = async ev => {
            const botao = ev.currentTarget;
            const dados = {};
            let invalido = null;

            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (!el) return;
              let v = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
              if (c.obrigatorio && (v === '' || v === null)) invalido ??= c;
              if (v === '' && c.tipo === 'number') v = null;
              dados[c.campo] = v;
            });

            if (invalido) {
              toast(`Preencha o campo "${invalido.label}".`, 'warn');
              dw.querySelector(`[name="${invalido.campo}"]`)?.focus();
              return;
            }

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
              toast(e.message, 'err');
            }
          };
        }
      });
    }
  };
}

/** Um campo do formulário da gaveta. */
function campoHtml(c, item) {
  const v = item[c.campo] ?? c.padrao ?? '';
  const largura = c.largura === 'full' ? ' full' : '';
  const desabilitado = c.somenteLeitura ? 'disabled' : '';

  if (c.tipo === 'switch') {
    return `<div class="field${largura}">
      <label class="label">${esc(c.label)}</label>
      <label class="switch"><input type="checkbox" name="${c.campo}" ${Number(v) ? 'checked' : ''} ${desabilitado}>
        <span class="track"></span></label>
      ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
    </div>`;
  }

  if (c.tipo === 'select') {
    return `<div class="field${largura}">
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
    return `<div class="field${largura}">
      <label class="label">${esc(c.label)}</label>
      <textarea class="textarea" name="${c.campo}" placeholder="${esc(c.placeholder || '')}" ${desabilitado}>${esc(v)}</textarea>
      ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
    </div>`;
  }

  return `<div class="field${largura}">
    <label class="label">${esc(c.label)}${c.obrigatorio ? ' *' : ''}</label>
    <input class="input${c.mono ? ' mono' : ''}" type="${c.tipo || 'text'}" name="${c.campo}"
           value="${esc(v)}" placeholder="${esc(c.placeholder || '')}" ${desabilitado}>
    ${c.ajuda ? `<span class="hint">${esc(c.ajuda)}</span>` : ''}
  </div>`;
}

/* ------------------------- Conectividade · Ramais ------------------------- */
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
    { campo: 'numero', label: 'Número do ramal', obrigatorio: true, mono: true,
      placeholder: '1001', somenteLeitura: !!r.id, ajuda: r.id ? 'O número não muda depois de criado.' : '' },
    { campo: 'nome', label: 'Nome de exibição', obrigatorio: true, placeholder: 'Nome do usuário' },
    { campo: 'setor', label: 'Setor / centro de custo', placeholder: 'Atendimento' },
    { campo: 'email', label: 'E-mail', tipo: 'email', placeholder: 'usuario@empresa.com.br' },
    { campo: 'senha_sip', label: r.id ? 'Nova senha SIP' : 'Senha SIP', obrigatorio: !r.id, mono: true,
      placeholder: r.id ? 'deixe em branco para manter' : 'mínimo 12 caracteres',
      ajuda: 'Use uma senha longa e aleatória: é o que protege o ramal contra fraude.' },
    { campo: 'ativo', label: 'Ramal ativo', tipo: 'switch', padrao: 1 },

    { aba: 'Voz', campo: 'voicemail', label: 'Correio de voz', tipo: 'switch', padrao: 1 },
    { aba: 'Voz', campo: 'vm_email', label: 'Enviar mensagens por e-mail', tipo: 'switch', padrao: 1 },
    { aba: 'Voz', campo: 'gravar', label: 'Gravação de chamadas', tipo: 'select',
      opcoes: [{valor:'nao',rotulo:'Não gravar'},{valor:'entrada',rotulo:'Só entrantes'},
               {valor:'saida',rotulo:'Só saintes'},{valor:'ambas',rotulo:'Entrantes e saintes'}] },
    { aba: 'Voz', campo: 'tempo_toque', label: 'Tempo de toque (s)', tipo: 'number', padrao: 20 },
    { aba: 'Voz', campo: 'siga_me', label: 'Siga-me', placeholder: 'Celular ou outro ramal', largura: 'full' },
    { aba: 'Voz', campo: 'dnd', label: 'Não perturbe', tipo: 'switch' },

    { aba: 'Rede', campo: 'transporte', label: 'Transporte', tipo: 'select',
      opcoes: ['udp','tcp','tls','wss'], padrao: 'udp' },
    { aba: 'Rede', campo: 'codecs', label: 'Codecs', mono: true, padrao: 'opus,alaw,ulaw,g722' },
    { aba: 'Rede', campo: 'max_contatos', label: 'Máximo de contatos', tipo: 'number', padrao: 2 },
    { aba: 'Rede', campo: 'srtp', label: 'Exigir mídia criptografada (SRTP)', tipo: 'switch' },
    { aba: 'Rede', campo: 'contexto', label: 'Contexto', mono: true, padrao: 'interno' },
    { aba: 'Rede', campo: 'callgroup', label: 'Grupo de chamada', mono: true },
    { aba: 'Rede', campo: 'pickupgroup', label: 'Grupo de captura', mono: true },
    { aba: 'Rede', campo: 'redes_permitidas', label: 'Restringir a redes', mono: true,
      placeholder: '10.0.0.0/24', largura: 'full' },

    { aba: 'Permissões', campo: 'perm_local', label: 'Ligações locais', tipo: 'switch', padrao: 1 },
    { aba: 'Permissões', campo: 'perm_celular', label: 'Celular', tipo: 'switch', padrao: 1 },
    { aba: 'Permissões', campo: 'perm_ddd', label: 'DDD nacional', tipo: 'switch', padrao: 1 },
    { aba: 'Permissões', campo: 'perm_ddi', label: 'Internacional', tipo: 'switch' },
    { aba: 'Permissões', campo: 'no_diretorio', label: 'Aparece no diretório', tipo: 'switch', padrao: 1 }
  ],

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

  colunas: [
    { label: 'Tronco', render: t => `<b>${esc(t.nome)}</b>` },
    { label: 'Tipo', render: t => `<span class="badge">${String(t.tipo || '').toUpperCase()}</span>` },
    { label: 'Host', render: t => `<span class="mono small dim">${esc(t.host || '—')}:${t.porta || 5060}</span>` },
    { label: 'Canais', render: t => `<span class="num">${t.canais_max || '—'}</span>` },
    { label: 'Registra', render: t => Number(t.registrar) ? '<span class="badge badge-info">Sim</span>' : '<span class="muted">Não</span>' },
    { label: 'Estado', render: t => Number(t.ativo)
        ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
        : '<span class="badge"><i class="dot"></i>Inativo</span>' }
  ],

  campos: (t) => [
    { campo: 'nome', label: 'Nome do tronco', obrigatorio: true, placeholder: 'SIP-Operadora' },
    { campo: 'tipo', label: 'Tipo', tipo: 'select', opcoes: ['pjsip','dahdi'], padrao: 'pjsip' },
    { campo: 'host', label: 'Host da operadora', obrigatorio: true, mono: true, placeholder: 'sip.operadora.com.br' },
    { campo: 'porta', label: 'Porta', tipo: 'number', padrao: 5060 },
    { campo: 'transporte', label: 'Transporte', tipo: 'select', opcoes: ['udp','tcp','tls'], padrao: 'udp' },
    { campo: 'canais_max', label: 'Canais contratados', tipo: 'number', placeholder: '30' },
    { campo: 'usuario', label: 'Usuário de autenticação', mono: true },
    { campo: 'senha', label: t.id ? 'Nova senha' : 'Senha', mono: true,
      placeholder: t.id ? 'deixe em branco para manter' : '' },
    { campo: 'registrar', label: 'Registrar no provedor', tipo: 'switch', padrao: 1 },
    { campo: 'ativo', label: 'Tronco ativo', tipo: 'switch', padrao: 1 },
    { campo: 'from_user', label: 'From user', mono: true },
    { campo: 'from_domain', label: 'From domain', mono: true },
    { campo: 'cid_saida', label: 'Identificação de saída', mono: true, placeholder: '1133255800' },
    { campo: 'codecs', label: 'Codecs', mono: true, padrao: 'alaw,ulaw,g729' },
    { campo: 'contexto_entrada', label: 'Contexto de entrada', mono: true, padrao: 'de-tronco', largura: 'full' }
  ]
});

/* ------------------------- Aplicações · Filas ------------------------- */
PAGES['apps.filas'] = paginaCrud({
  recurso: 'filas',
  titulo: 'Filas de Atendimento',
  sub: 'Distribuição de chamadas, agentes e metas de nível de serviço.',
  ico: 'headset',
  plural: 'filas',
  rotuloNovo: 'Nova fila',
  tituloNovo: 'Nova fila',
  vazioTitulo: 'Nenhuma fila cadastrada',
  vazioTexto: 'Filas distribuem as chamadas entre os atendentes e medem o nível de serviço.',
  placeholderBusca: 'Buscar por número ou nome…',
  textoBusca: f => `${f.numero} ${f.nome}`,
  tituloEditar: f => `Fila ${f.numero} — ${f.nome}`,
  tituloExcluir: f => `Excluir a fila ${f.numero}?`,

  colunas: [
    { label: 'Fila', render: f => `<b class="mono">${esc(f.numero)}</b>` },
    { label: 'Nome', render: f => esc(f.nome) },
    { label: 'Estratégia', render: f => `<span class="badge mono">${esc(f.estrategia)}</span>` },
    { label: 'SLA', render: f => `<span class="num">${f.sla_segundos}s</span>` },
    { label: 'Espera máx.', render: f => `<span class="num">${duracao(f.max_espera)}</span>` },
    { label: 'Gravação', render: f => Number(f.gravar) ? '<span class="badge badge-brand">Ativa</span>' : '<span class="muted">—</span>' }
  ],

  campos: () => [
    { campo: 'numero', label: 'Número da fila', obrigatorio: true, mono: true, placeholder: '600' },
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Suporte Técnico' },
    { campo: 'estrategia', label: 'Estratégia', tipo: 'select',
      opcoes: ['ringall','leastrecent','fewestcalls','random','rrmemory','linear','wrandom'], padrao: 'ringall',
      ajuda: 'ringall toca em todos; rrmemory faz rodízio com memória.' },
    { campo: 'timeout_agente', label: 'Toque por agente (s)', tipo: 'number', padrao: 20 },
    { campo: 'retry', label: 'Intervalo entre tentativas (s)', tipo: 'number', padrao: 5 },
    { campo: 'wrapuptime', label: 'Pausa pós-atendimento (s)', tipo: 'number', padrao: 10 },
    { campo: 'sla_segundos', label: 'Meta de SLA (s)', tipo: 'number', padrao: 20 },
    { campo: 'max_espera', label: 'Espera máxima (s)', tipo: 'number', padrao: 300 },
    { campo: 'musica_espera', label: 'Música em espera', padrao: 'default' },
    { campo: 'anuncio_posicao', label: 'Anunciar posição na fila', tipo: 'switch', padrao: 1 },
    { campo: 'gravar', label: 'Gravar chamadas', tipo: 'switch', padrao: 1 },
    { campo: 'ativo', label: 'Fila ativa', tipo: 'switch', padrao: 1 }
  ]
});

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
            <td class="mono">${esc(c.dst)}</td>
            <td>${c.direcao ? `<span class="badge">${icon(DIRECAO_ICO[c.direcao] || 'phone','ico ico-sm')}${esc(c.direcao)}</span>` : '<span class="muted">—</span>'}</td>
            <td class="num">${duracao(c.duration)}</td>
            <td class="num">${duracao(c.billsec)}</td>
            <td class="small dim">${esc(c.tronco || '—')}</td>
            <td>${badgeCdr(c.disposition)}</td>
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
      Drawer.open({
        titulo: novo ? 'Novo usuário' : `Editar ${u.nome}`,
        sub: novo ? 'Defina a senha agora: a conta só entra depois disso.' : '',
        corpo: `<div class="form-grid">
          ${campoHtml({ campo:'nome', label:'Nome completo', obrigatorio:true }, u || {})}
          ${campoHtml({ campo:'usuario', label:'Usuário de login', obrigatorio:true, mono:true,
                        somenteLeitura: !novo }, u || {})}
          ${campoHtml({ campo:'email', label:'E-mail', tipo:'email' }, u || {})}
          ${campoHtml({ campo:'ramal', label:'Ramal vinculado', mono:true }, u || {})}
          ${campoHtml({ campo:'setor', label:'Setor' }, u || {})}
          ${campoHtml({ campo:'perfil_id', label:'Perfil de acesso', tipo:'select',
                        opcoes: this._perfis.map(p => ({ valor:p.id, rotulo:p.nome })) }, u || {})}
          ${campoHtml({ campo:'status', label:'Estado', tipo:'select',
                        opcoes:[{valor:'ativo',rotulo:'Ativo'},{valor:'inativo',rotulo:'Inativo'},
                                {valor:'bloqueado',rotulo:'Bloqueado'}] }, u || {})}
          ${novo ? `
            <div class="field full"><label class="label">Senha de acesso *</label>
              <input class="input" type="password" name="senha" autocomplete="new-password"
                     placeholder="mínimo 10 caracteres">
              <span class="hint">A conta só consegue entrar depois que uma senha for definida.</span></div>
            <div class="field full"><label class="label">Repetir a senha *</label>
              <input class="input" type="password" name="senha2" autocomplete="new-password"></div>` : ''}
        </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-salvar>${novo ? 'Criar usuário' : 'Salvar'}</button>`,
        aoAbrir: dw => dw.querySelector('[data-salvar]').onclick = async ev => {
          const botao = ev.currentTarget;
          const dados = {};
          ['nome','usuario','email','ramal','setor','perfil_id','status'].forEach(c => {
            const el = dw.querySelector(`[name="${c}"]`);
            if (el && !el.disabled) dados[c] = el.value;
          });

          if (!dados.nome || (novo && !dados.usuario)) {
            toast('Nome e usuário são obrigatórios.', 'warn'); return;
          }

          if (novo) {
            const s1 = dw.querySelector('[name="senha"]').value;
            const s2 = dw.querySelector('[name="senha2"]').value;
            if (s1.length < 10) { toast('A senha precisa de pelo menos 10 caracteres.', 'warn'); return; }
            if (s1 !== s2) { toast('As senhas não conferem.', 'warn'); return; }
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
            botao.textContent = novo ? 'Criar usuário' : 'Salvar';
            toast(e.message, 'err');
          }
        }
      });
    };

    const senhaForm = (u) => Drawer.open({
      titulo: `Definir senha de ${u.nome}`,
      sub: 'Mínimo de 10 caracteres. As sessões abertas desse usuário são encerradas.',
      corpo: `<div class="grid" style="gap:16px">
        <div class="field"><label class="label">Nova senha</label>
          <input class="input" type="password" name="senha" autocomplete="new-password"></div>
        <div class="field"><label class="label">Repetir a senha</label>
          <input class="input" type="password" name="senha2" autocomplete="new-password"></div>
      </div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" data-ok>Definir senha</button>`,
      aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async () => {
        const s1 = dw.querySelector('[name="senha"]').value;
        const s2 = dw.querySelector('[name="senha2"]').value;
        if (s1.length < 10) { toast('A senha precisa de pelo menos 10 caracteres.', 'warn'); return; }
        if (s1 !== s2) { toast('As senhas não conferem.', 'warn'); return; }
        try {
          await Api.post(`/usuarios/${u.id}/senha`, { senha: s1 });
          Drawer.close(); toast('Senha definida.', 'ok'); App.route();
        } catch (e) { toast(e.message, 'err'); }
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
