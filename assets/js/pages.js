/* =========================================================
   Telium PABX — Telas
   Cada chave de PAGES corresponde a um id de item de menu.
   render(ctx) -> HTML     mount(ctx) -> comportamento pós-render
   ctx = { sess, item, group, can(acao) }
   ========================================================= */

/* ------------------------- Helpers de UI ------------------------- */
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const initials = n => n.split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();

function pageHead(titulo, sub, acoes = '') {
  return `<div class="page-head">
    <div>
      <h1>${titulo}</h1>
      ${sub ? `<p>${sub}</p>` : ''}
    </div>
    ${acoes ? `<div class="page-actions">${acoes}</div>` : ''}
  </div>`;
}

function readOnlyNote(ctx) {
  if (ctx.can('editar')) return '';
  return `<span class="badge badge-warn">${icon('eye','ico ico-sm')} Somente leitura</span>`;
}

const RAMAL_STATUS = {
  disponivel: ['ok',     'Disponível'],
  emchamada:  ['info',   'Em chamada'],
  ausente:    ['warn',   'Ausente'],
  nperturbe:  ['danger', 'Não perturbe'],
  offline:    ['',       'Offline']
};
const badgeRamal = d => {
  const [tone, txt] = RAMAL_STATUS[d] || ['', d];
  return `<span class="badge ${tone ? 'badge-' + tone : ''}"><i class="dot ${d === 'emchamada' ? 'dot-pulse' : ''}"></i>${txt}</span>`;
};

const CDR_STATUS = {
  atendida:   ['ok',     'Atendida'],
  perdida:    ['danger', 'Perdida'],
  abandonada: ['warn',   'Abandonada'],
  ocupado:    ['warn',   'Ocupado'],
  falha:      ['danger', 'Falha']
};
const badgeCdr = s => {
  const [tone, txt] = CDR_STATUS[s] || ['', s];
  return `<span class="badge badge-${tone}">${txt}</span>`;
};

const DIR_ICO = { entrada: 'arrowDown', saida: 'arrowUp', interna: 'shuffle' };

function meter(label, pct, tone) {
  return `<div>
    <div class="row-between small" style="margin-bottom:6px">
      <span class="dim">${label}</span><b class="num">${pct}%</b>
    </div>
    <div class="hbar" style="grid-template-columns:1fr;padding:0">
      <div class="track" style="height:8px">
        <div class="fill" style="width:${pct}%;background:var(--${tone})"></div>
      </div>
    </div>
  </div>`;
}

/* ------------------------- Gráfico: barras empilhadas -------------------------
   Paleta de séries validada (slots 1 e 2 da paleta categórica padrão),
   legenda sempre presente com 2 séries e tooltip por coluna.
------------------------------------------------------------------------------ */
function stackedBarChart(el, cfg) {
  const W = 760, H = 260, PAD = { t: 14, r: 10, b: 28, l: 40 };
  const labels = cfg.labels, series = cfg.series;
  const n = labels.length;
  const totals = labels.map((_, i) => series.reduce((s, se) => s + se.values[i], 0));
  const rawMax = Math.max(...totals);
  const step = Math.pow(10, Math.floor(Math.log10(rawMax))) / 2;
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
      const v = se.values[i];
      const y0 = y(acc + v), y1 = y(acc);
      let h = y1 - y0;
      const isTop = si === series.length - 1;
      if (!isTop) h = Math.max(0, h - 2);            // 2px de respiro entre segmentos
      const yy = isTop ? y0 : y1 - h;
      segs += `<path class="bar-seg" d="${topRound(x, yy, bw, h, isTop ? 4 : 0)}" fill="${se.color}"/>`;
      acc += v;
    });
    cols += `<g class="col" data-i="${i}">
      ${segs}
      <rect class="bar-hit" x="${PAD.l + slot * i}" y="${PAD.t}" width="${slot}" height="${ph}" fill="transparent"/>
      <text class="axis-txt" x="${PAD.l + slot * i + slot / 2}" y="${H - 8}" text-anchor="middle">${lb}</text>
    </g>`;
  });

  el.innerHTML = `
    <svg class="chart-svg" viewBox="0 0 ${W} ${H}" role="img"
         aria-label="${cfg.aria || 'Gráfico de barras empilhadas'}">
      ${grid}
      <line class="axis-line" x1="${PAD.l}" x2="${W - PAD.r}" y1="${PAD.t + ph}" y2="${PAD.t + ph}"/>
      ${cols}
    </svg>
    <div class="viz-tip" id="${el.id}-tip"></div>`;

  const tip = el.querySelector('.viz-tip');
  el.querySelectorAll('.col').forEach(g => {
    g.addEventListener('mousemove', ev => {
      const i = +g.dataset.i;
      tip.innerHTML = `<b>${labels[i]}</b>` +
        series.map(se => `<div class="tr"><span class="row gap-6">
            <i class="sw" style="background:${se.color}"></i>${se.label}</span>
            <span class="v num">${se.values[i]}</span></div>`).join('') +
        `<div class="tr" style="margin-top:5px;padding-top:5px;border-top:1px solid var(--border)">
           <span class="dim">Total</span><span class="v num">${totals[i]}</span></div>`;
      const r = el.getBoundingClientRect();
      tip.style.left = (ev.clientX - r.left) + 'px';
      tip.style.top  = (ev.clientY - r.top - 6) + 'px';
      tip.classList.add('on');
    });
    g.addEventListener('mouseleave', () => tip.classList.remove('on'));
  });
}

/* ========================================================================
   PÁGINAS
   ======================================================================== */
const PAGES = {};

/* ------------------------- Visão geral ------------------------- */
PAGES['dash.visaogeral'] = {
  render(ctx) {
    const kpis = DEMO.kpis.map(k => `
      <div class="card kpi">
        <div class="k-top">
          <span class="k-label">${k.label}</span>
          <span class="k-ico" style="background:var(--${k.tone}-soft);color:var(--${k.tone})">${icon(k.ico)}</span>
        </div>
        <div class="k-val">${k.value}</div>
        <div class="k-foot">
          ${k.delta === null ? '' : `<span class="k-delta ${k.delta > 0 ? 'up' : 'down'}">
             ${icon(k.delta > 0 ? 'arrowUp' : 'arrowDown','ico ico-sm')}${Math.abs(k.delta)}%</span>`}
          <span>${k.foot}</span>
        </div>
      </div>`).join('');

    const legend = DEMO.volume.series.map(s =>
      `<span class="li"><i class="sw" style="background:${s.color}"></i>${s.label}</span>`).join('');

    const maxTop = Math.max(...DEMO.topRamais.map(r => r.qtd));
    const top = DEMO.topRamais.map(r => `
      <div class="hbar">
        <span class="small truncate">${r.ramal}</span>
        <span class="track"><span class="fill" style="width:${(r.qtd / maxTop * 100).toFixed(1)}%"></span></span>
        <span class="val">${r.qtd}</span>
      </div>`).join('');

    const troncos = DEMO.troncos.map(t => `
      <tr>
        <td><b>${t.nome}</b><div class="tiny muted">${t.tipo} · ${t.ip}</div></td>
        <td class="num">${t.canais}</td>
        <td style="width:130px">
          <div class="track" style="height:7px;background:var(--surface-3);border-radius:6px;overflow:hidden">
            <div style="height:100%;width:${t.uso}%;background:var(--${t.uso > 80 ? 'danger' : t.uso > 60 ? 'warn' : 'ok'})"></div>
          </div>
        </td>
        <td>${t.status === 'registrado' ? '<span class="badge badge-ok"><i class="dot"></i>Registrado</span>'
             : t.status === 'alerta' ? '<span class="badge badge-warn"><i class="dot"></i>Latência alta</span>'
             : '<span class="badge badge-danger"><i class="dot"></i>Offline</span>'}</td>
        <td class="num small dim">${t.latencia}</td>
      </tr>`).join('');

    const ativ = DEMO.atividades.map(a => `
      <div class="row gap-12" style="padding:9px 0;border-bottom:1px solid var(--border)">
        <span class="avatar avatar-sm">${initials(a.user)}</span>
        <div class="grow">
          <div class="small"><b>${a.user}</b> ${a.acao}</div>
          <div class="tiny muted">${a.ip}</div>
        </div>
        <span class="tiny muted">${a.hora}</span>
      </div>`).join('');

    return pageHead(
      'Visão Geral',
      `Resumo operacional da central — atualizado às 14:42 de 10/09/2026.`,
      `<button class="btn btn-outline btn-sm" id="btnRefresh">${icon('refresh','ico ico-sm')} Atualizar</button>
       ${ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar</button>` : ''}
       <span class="badge badge-ok"><i class="dot dot-pulse"></i>Sistema operacional</span>`
    ) + `
    <div class="grid g-4" style="margin-bottom:16px">${kpis}</div>

    <div class="grid g-2-1" style="margin-bottom:16px">
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">Volume de chamadas por hora</div>
            <div class="card-sub">Hoje · 07h às 18h</div>
          </div>
          <div class="legend">${legend}</div>
        </div>
        <div class="card-body">
          <div class="chart-wrap" id="chartVolume"></div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><div class="card-title">Ramais mais ativos</div>
               <div class="card-sub">Chamadas atendidas hoje</div></div>
        </div>
        <div class="card-body">${top}</div>
      </div>
    </div>

    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head">
          <div><div class="card-title">Troncos</div>
               <div class="card-sub">Estado de registro e ocupação de canais</div></div>
          <a href="#/conn.troncos" class="small">Ver todos</a>
        </div>
        <div class="card-body tight table-wrap">
          <table class="table">
            <thead><tr><th>Tronco</th><th>Canais</th><th>Ocupação</th><th>Estado</th><th>Latência</th></tr></thead>
            <tbody>${troncos}</tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div class="card-title">Atividade recente</div></div>
        <div class="card-body" style="padding-top:4px">${ativ}</div>
      </div>
    </div>`;
  },
  mount(ctx) {
    const el = document.getElementById('chartVolume');
    el.id = 'chartVolume';
    stackedBarChart(el, { ...DEMO.volume, aria: 'Chamadas atendidas e perdidas por faixa de hora' });
    const b = document.getElementById('btnRefresh');
    if (b) b.onclick = () => toast('Indicadores atualizados.', 'ok');
  }
};

/* ------------------------- Tempo real ------------------------- */
PAGES['dash.temporeal'] = {
  render(ctx) {
    const cards = DEMO.filas.map(f => `
      <div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:12px">
          <div><b>${f.nome}</b><div class="tiny muted">Fila ${f.num} · ${f.estrategia}</div></div>
          <span class="badge ${f.espera > 2 ? 'badge-warn' : 'badge-ok'}"><i class="dot ${f.espera ? 'dot-pulse' : ''}"></i>${f.espera} na fila</span>
        </div>
        <div class="grid" style="grid-template-columns:repeat(3,1fr);gap:10px">
          <div><div class="tiny muted">Agentes</div><b class="num" style="font-size:18px">${f.online}/${f.agentes}</b></div>
          <div><div class="tiny muted">TME</div><b class="num" style="font-size:18px">${f.tme}</b></div>
          <div><div class="tiny muted">SLA</div><b class="num" style="font-size:18px;color:var(--${f.sla >= 90 ? 'ok' : 'warn'})">${f.sla}%</b></div>
        </div>
      </div>`).join('');

    const ativas = DEMO.ramais.filter(r => r.disp === 'emchamada').map(r => `
      <tr>
        <td><span class="row gap-8"><i class="dot dot-pulse" style="color:var(--ok)"></i><b>${r.num}</b> ${r.nome}</span></td>
        <td class="mono">11 98877-1234</td>
        <td><span class="badge badge-info">${icon('arrowDown','ico ico-sm')}Entrada</span></td>
        <td class="num">00:03:12</td>
        <td>SIP-Vivo-Principal</td>
        <td class="col-actions">
          <span class="row-actions">
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Escuta">${icon('headset','ico ico-sm')}</button>
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Gravar">${icon('mic','ico ico-sm')}</button>
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Encerrar">${icon('phoneOff','ico ico-sm')}</button>
          </span>
        </td>
      </tr>`).join('');

    return pageHead('Wallboard — Tempo Real',
      'Filas, agentes e chamadas em andamento. Atualização automática a cada 5 s.',
      `<span class="badge badge-ok"><i class="dot dot-pulse"></i>Ao vivo</span>`) + `
      <div class="grid g-4" style="margin-bottom:16px">${cards}</div>
      <div class="card">
        <div class="card-head"><div class="card-title">Chamadas em andamento</div>
          <span class="badge">${DEMO.sistema.canaisAtivos} canais ativos</span></div>
        <div class="card-body tight table-wrap">
          <table class="table">
            <thead><tr><th>Ramal</th><th>Número</th><th>Sentido</th><th>Duração</th><th>Tronco</th><th></th></tr></thead>
            <tbody>${ativas}</tbody>
          </table>
        </div>
      </div>`;
  }
};

/* ------------------------- Estatísticas do sistema ------------------------- */
PAGES['dash.sistema'] = {
  render() {
    const s = DEMO.sistema;
    return pageHead('Estatísticas do Sistema', 'Recursos do servidor e informações da distribuição.') + `
      <div class="grid g-2-1">
        <div class="card">
          <div class="card-head"><div class="card-title">Recursos</div></div>
          <div class="card-body grid" style="gap:18px">
            ${meter('CPU', s.cpu, 'ok')}
            ${meter('Memória', s.mem, 'warn')}
            ${meter('Disco /', s.disco, 'ok')}
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-title">Sistema</div></div>
          <div class="card-body">
            <div class="deflist">
              ${[['Distribuição', s.distro], ['Asterisk', s.asterisk], ['Kernel', s.kernel],
                 ['Uptime', s.uptime], ['Canais ativos', s.canaisAtivos], ['SIP peers', s.sipPeers]]
                .map(([k, v]) => `<div class="defrow" style="grid-template-columns:150px 1fr;padding:9px 0">
                    <div class="dt"><b>${k}</b></div><div class="mono small">${v}</div></div>`).join('')}
            </div>
          </div>
        </div>
      </div>`;
  }
};

/* ------------------------- Asterisk Info ------------------------- */
PAGES['dash.asterisk'] = {
  render() {
    return pageHead('Asterisk Info', 'Saída direta do núcleo Asterisk.') + `
      <div class="card">
        <div class="card-head"><div class="card-title">core show channels</div>
          <button class="btn btn-outline btn-sm">${icon('refresh','ico ico-sm')} Recarregar</button></div>
        <div class="card-body">
          <pre class="mono small" style="margin:0;padding:16px;background:var(--surface-2);border-radius:var(--r-md);overflow-x:auto">Channel              Location             State   Application(Data)
PJSIP/1010-00001a2b  600@from-internal    Up      Queue(600,tT)
PJSIP/2031-00001a2c  s@macro-dial         Up      Dial(PJSIP/trunk-vivo)
PJSIP/3001-00001a2d  s@ivr-1              Up      Background(ura-principal)
DAHDI/1-1            s@from-pstn          Up      Queue(601)

4 active channels
2 active calls
3184 calls processed</pre>
        </div>
      </div>`;
  }
};

/* ------------------------- Conectividade · Ramais ------------------------- */
PAGES['conn.ramais'] = {
  render(ctx) {
    return pageHead('Ramais',
      'Cadastro de ramais SIP/PJSIP, dispositivos e recursos por usuário.',
      `${readOnlyNote(ctx)}
       ${ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar CSV</button>` : ''}
       ${ctx.can('criar') ? `<button class="btn btn-primary btn-sm" data-act="novo-ramal">${icon('plus','ico ico-sm')} Adicionar ramal</button>` : ''}`
    ) + `
    <div class="card">
      <div class="toolbar">
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" id="fRamal" placeholder="Buscar ramal, nome ou setor…">
        </div>
        <select class="select" id="fStatus" style="width:170px">
          <option value="">Todos os estados</option>
          <option value="disponivel">Disponível</option>
          <option value="emchamada">Em chamada</option>
          <option value="ausente">Ausente</option>
          <option value="nperturbe">Não perturbe</option>
          <option value="offline">Offline</option>
        </select>
        <select class="select" id="fSetor" style="width:170px">
          <option value="">Todos os setores</option>
          ${[...new Set(DEMO.ramais.map(r => r.setor))].map(s => `<option>${s}</option>`).join('')}
        </select>
        <span class="grow"></span>
        <span class="small muted" id="ramalCount"></span>
      </div>
      <div class="table-wrap">
        <table class="table" id="tabRamais">
          <thead><tr>
            <th style="width:90px">Ramal</th><th>Nome</th><th>Tecnologia</th><th>Setor</th>
            <th>Dispositivo</th><th>Correio</th><th>Gravação</th><th>Estado</th><th></th>
          </tr></thead>
          <tbody>
            ${DEMO.ramais.map(r => `
              <tr data-busca="${esc((r.num + ' ' + r.nome + ' ' + r.setor).toLowerCase())}"
                  data-status="${r.disp}" data-setor="${esc(r.setor)}">
                <td><b class="mono">${r.num}</b></td>
                <td><span class="row gap-8"><span class="avatar avatar-sm">${initials(r.nome)}</span>${r.nome}</span></td>
                <td><span class="badge">${r.tec}</span></td>
                <td class="dim">${r.setor}</td>
                <td class="dim small">${r.device}</td>
                <td>${r.vm ? icon('checkCirc','ico ico-sm') : '<span class="muted">—</span>'}</td>
                <td>${r.gravar ? '<span class="badge badge-brand">Ativa</span>' : '<span class="muted">—</span>'}</td>
                <td>${badgeRamal(r.disp)}</td>
                <td class="col-actions"><span class="row-actions">
                  <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ligar" data-call="${r.num}" data-nome="${esc(r.nome)}">${icon('phone','ico ico-sm')}</button>
                  <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-edit="${r.num}" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
                  <button class="btn btn-ghost btn-sm btn-icon" data-tip="Provisionar">${icon('download','ico ico-sm')}</button>
                  <button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-del="${r.num}" ${ctx.can('excluir') ? '' : 'disabled'}>${icon('trash','ico ico-sm')}</button>
                </span></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
  },
  mount(ctx) {
    const q = document.getElementById('fRamal'), st = document.getElementById('fStatus'),
          se = document.getElementById('fSetor'), cnt = document.getElementById('ramalCount'),
          rows = [...document.querySelectorAll('#tabRamais tbody tr')];
    const filtrar = () => {
      const t = q.value.trim().toLowerCase();
      let n = 0;
      rows.forEach(r => {
        const ok = (!t || r.dataset.busca.includes(t)) &&
                   (!st.value || r.dataset.status === st.value) &&
                   (!se.value || r.dataset.setor === se.value);
        r.hidden = !ok; if (ok) n++;
      });
      cnt.textContent = `${n} de ${rows.length} ramais`;
    };
    [q, st, se].forEach(el => el.addEventListener('input', filtrar));
    filtrar();

    const nv = document.querySelector('[data-act="novo-ramal"]');
    if (nv) nv.onclick = () => formRamal(null, ctx);

    document.querySelectorAll('[data-call]').forEach(b =>
      b.onclick = () => Softphone.discarPara(b.dataset.call, b.dataset.nome));

    document.querySelectorAll('[data-edit]').forEach(b =>
      b.onclick = () => formRamal(DEMO.ramais.find(r => r.num === b.dataset.edit), ctx));

    document.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      const r = DEMO.ramais.find(x => x.num === b.dataset.del);
      const ok = await Modal.confirm({
        titulo: `Excluir o ramal ${r.num}?`,
        texto: `${r.nome} perderá acesso imediato à central. Gravações e histórico de chamadas são preservados.`,
        ok: 'Excluir ramal'
      });
      if (ok) { b.closest('tr').remove(); toast(`Ramal ${r.num} excluído.`, 'ok'); }
    });
  }
};

/* Formulário de ramal (gaveta com abas) — usado para criar e editar */
function formRamal(ramal, ctx) {
  const novo = !ramal;
  const r = ramal || { num: '', nome: '', tec: 'PJSIP', setor: '', device: '', vm: true, gravar: true };
  const abas = [
    ['geral', 'Geral'], ['voz', 'Voz e correio'], ['rede', 'Rede / SIP'], ['perm', 'Permissões']
  ];
  Drawer.open({
    wide: true,
    titulo: novo ? 'Adicionar ramal' : `Ramal ${r.num} — ${r.nome}`,
    sub: novo ? 'O ramal fica disponível assim que o dialplan for aplicado.' : 'Alterações exigem aplicar configurações.',
    corpo: `
      <div class="tabs" id="ramalTabs">
        ${abas.map(([k, l], i) => `<button class="tab ${i === 0 ? 'on' : ''}" data-tab="${k}">${l}</button>`).join('')}
      </div>

      <div data-pane="geral">
        <div class="form-grid">
          <div class="field"><label class="label">Número do ramal</label>
            <input class="input mono" value="${r.num}" placeholder="2035" ${novo ? '' : 'disabled'}></div>
          <div class="field"><label class="label">Nome de exibição</label><input class="input" value="${esc(r.nome)}" placeholder="Nome do usuário"></div>
          <div class="field"><label class="label">Setor / centro de custo</label><input class="input" value="${esc(r.setor)}" placeholder="Suporte N1"></div>
          <div class="field"><label class="label">Tecnologia</label>
            <select class="select"><option ${r.tec === 'PJSIP' ? 'selected' : ''}>PJSIP</option><option>SIP (legado)</option><option>DAHDi</option></select></div>
          <div class="field"><label class="label">Dispositivo</label><input class="input" value="${esc(r.device || '')}" placeholder="Yealink T31"></div>
          <div class="field"><label class="label">E-mail do usuário</label><input class="input" type="email" placeholder="usuario@telium.com.br"></div>
          <div class="field full"><label class="label">Grupo de captura</label><input class="input mono" value="1" placeholder="1,2"></div>
        </div>
      </div>

      <div data-pane="voz" hidden>
        <div class="deflist">
          <div class="defrow"><div class="dt"><b>Correio de voz</b><small>Cria a caixa postal do ramal</small></div>
            <div class="right"><label class="switch"><input type="checkbox" ${r.vm ? 'checked' : ''}><span class="track"></span></label></div></div>
          <div class="defrow"><div class="dt"><b>Enviar mensagens por e-mail</b><small>Anexa o áudio e apaga da caixa</small></div>
            <div class="right"><label class="switch"><input type="checkbox" checked><span class="track"></span></label></div></div>
          <div class="defrow"><div class="dt"><b>Gravação de chamadas</b><small>Entrantes e saintes</small></div>
            <div class="right"><label class="switch"><input type="checkbox" ${r.gravar ? 'checked' : ''}><span class="track"></span></label></div></div>
          <div class="defrow"><div class="dt"><b>Siga-me</b><small>Destino após tocar sem resposta</small></div>
            <div><input class="input" placeholder="Celular ou outro ramal"></div></div>
          <div class="defrow"><div class="dt"><b>Tempo de toque</b><small>Antes de cair no correio de voz</small></div>
            <div><select class="select"><option>15 segundos</option><option selected>20 segundos</option><option>30 segundos</option></select></div></div>
        </div>
      </div>

      <div data-pane="rede" hidden>
        <div class="form-grid">
          <div class="field"><label class="label">Senha SIP</label><input class="input mono" type="password" value="********************"></div>
          <div class="field"><label class="label">Transporte</label>
            <select class="select"><option>TLS (recomendado)</option><option>UDP</option><option>TCP</option><option>WSS (WebRTC)</option></select></div>
          <div class="field"><label class="label">Codecs permitidos</label><input class="input mono" value="opus,alaw,g722"></div>
          <div class="field"><label class="label">Máximo de contatos</label><input class="input" type="number" value="2"></div>
          <div class="field full"><label class="label">Restringir a IPs / redes</label><input class="input mono" placeholder="10.0.0.0/24, 189.45.22.7"></div>
          <div class="field full"><label class="check"><input type="checkbox" checked> <span>Exigir mídia criptografada (SRTP)</span></label></div>
        </div>
      </div>

      <div data-pane="perm" hidden>
        <div class="deflist">
          ${[['Ligações locais e celular', true], ['DDD nacional', true],
             ['Internacional', false], ['0300 / 0900', false],
             ['Pode escutar chamadas de outros ramais', false],
             ['Aparece no diretório da URA', true]].map(([l, on]) => `
            <div class="defrow"><div class="dt"><b>${l}</b></div>
              <div class="right"><label class="switch"><input type="checkbox" ${on ? 'checked' : ''}><span class="track"></span></label></div></div>`).join('')}
          <div class="defrow"><div class="dt"><b>Conjunto de PIN</b><small>Exigido para chamadas tarifadas</small></div>
            <div><select class="select"><option>Nenhum</option><option>PIN Comercial</option><option>PIN Diretoria</option></select></div></div>
        </div>
      </div>`,
    rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
             <button class="btn btn-primary" id="okRamal">${novo ? 'Criar ramal' : 'Salvar alterações'}</button>`,
    aoAbrir: dw => {
      dw.querySelectorAll('#ramalTabs .tab').forEach(t => t.onclick = () => {
        dw.querySelectorAll('#ramalTabs .tab').forEach(x => x.classList.remove('on'));
        t.classList.add('on');
        dw.querySelectorAll('[data-pane]').forEach(p => p.hidden = p.dataset.pane !== t.dataset.tab);
      });
      dw.querySelector('#okRamal').onclick = () => {
        Drawer.close();
        toast(novo ? 'Ramal criado. Aplique as configurações para publicar.' : 'Ramal atualizado.', 'ok');
      };
    }
  });
}

/* ------------------------- Conectividade · Troncos ------------------------- */
PAGES['conn.troncos'] = {
  render(ctx) {
    return pageHead('Troncos', 'Entroncamentos SIP e E1 com as operadoras.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Novo tronco</button>` : readOnlyNote(ctx)) + `
    <div class="card"><div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tronco</th><th>Tipo</th><th>Host / IP</th><th>Canais</th><th>Ocupação</th><th>Latência</th><th>Estado</th><th></th></tr></thead>
        <tbody>${DEMO.troncos.map(t => `
          <tr>
            <td><b>${t.nome}</b></td>
            <td><span class="badge">${t.tipo}</span></td>
            <td class="mono small dim">${t.ip}</td>
            <td class="num">${t.canais}</td>
            <td style="width:150px">
              <div class="track" style="height:7px;background:var(--surface-3);border-radius:6px;overflow:hidden">
                <div style="height:100%;width:${t.uso}%;background:var(--${t.uso > 80 ? 'danger' : t.uso > 60 ? 'warn' : 'ok'})"></div>
              </div>
              <div class="tiny muted num" style="margin-top:3px">${t.uso}%</div>
            </td>
            <td class="num small">${t.latencia}</td>
            <td>${t.status === 'registrado' ? '<span class="badge badge-ok"><i class="dot"></i>Registrado</span>'
                 : t.status === 'alerta' ? '<span class="badge badge-warn"><i class="dot"></i>Latência alta</span>'
                 : '<span class="badge badge-danger"><i class="dot"></i>Offline</span>'}</td>
            <td class="col-actions"><span class="row-actions">
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Reregistrar" ${ctx.can('reiniciar') ? '' : 'disabled'}>${icon('refresh','ico ico-sm')}</button>
            </span></td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div></div>`;
  }
};

/* ------------------------- Aplicações · Filas ------------------------- */
PAGES['apps.filas'] = {
  render(ctx) {
    return pageHead('Filas de Atendimento', 'Distribuição de chamadas, agentes e metas de nível de serviço.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Nova fila</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-2" style="margin-bottom:16px">
      ${DEMO.filas.map(f => `
        <div class="card" style="padding:18px">
          <div class="row-between" style="margin-bottom:14px">
            <div class="row gap-12">
              <span class="k-ico" style="background:var(--brand-soft);color:var(--brand);width:38px;height:38px;border-radius:11px;display:grid;place-items:center">${icon('headset')}</span>
              <div><b style="font-size:15px">${f.nome}</b>
                   <div class="tiny muted">Fila ${f.num} · estratégia <span class="mono">${f.estrategia}</span></div></div>
            </div>
            <label class="switch"><input type="checkbox" checked ${ctx.can('editar') ? '' : 'disabled'}><span class="track"></span></label>
          </div>
          <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px">
            <div><div class="tiny muted">Agentes</div><b class="num">${f.online}/${f.agentes}</b></div>
            <div><div class="tiny muted">Em espera</div><b class="num">${f.espera}</b></div>
            <div><div class="tiny muted">TME</div><b class="num">${f.tme}</b></div>
            <div><div class="tiny muted">Abandono</div><b class="num" style="color:var(--${f.abandono > 5 ? 'danger' : 'ok'})">${f.abandono}%</b></div>
          </div>
          <div style="margin-top:14px">
            <div class="row-between tiny" style="margin-bottom:5px"><span class="muted">Nível de serviço</span><b class="num">${f.sla}%</b></div>
            <div class="track" style="height:7px;background:var(--surface-3);border-radius:6px;overflow:hidden">
              <div style="height:100%;width:${f.sla}%;background:var(--${f.sla >= 90 ? 'ok' : 'warn'})"></div>
            </div>
          </div>
        </div>`).join('')}
    </div>`;
  }
};

/* ------------------------- Relatórios · CDR ------------------------- */
PAGES['rel.cdr'] = {
  render(ctx) {
    return pageHead('CDR — Registro de Chamadas', 'Histórico detalhado de chamadas entrantes, saintes e internas.',
      ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar</button>
        <button class="btn btn-outline btn-sm">${icon('mail','ico ico-sm')} Agendar envio</button>` : readOnlyNote(ctx)) + `
    <div class="card">
      <div class="toolbar">
        <input class="input" type="date" value="2026-09-10" style="width:160px">
        <span class="muted small">até</span>
        <input class="input" type="date" value="2026-09-10" style="width:160px">
        <select class="select" id="cdrDir" style="width:150px">
          <option value="">Todos os sentidos</option><option value="entrada">Entrada</option>
          <option value="saida">Saída</option><option value="interna">Interna</option>
        </select>
        <select class="select" id="cdrSt" style="width:160px">
          <option value="">Todos os estados</option>
          ${Object.entries(CDR_STATUS).map(([k, v]) => `<option value="${k}">${v[1]}</option>`).join('')}
        </select>
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" id="cdrQ" placeholder="Número ou ramal…">
        </div>
      </div>
      <div class="table-wrap">
        <table class="table" id="tabCdr">
          <thead><tr><th>Data / hora</th><th>Origem</th><th>Destino</th><th>Sentido</th>
                     <th>Duração</th><th>Tronco</th><th>Estado</th><th>Gravação</th></tr></thead>
          <tbody>${DEMO.cdr.map(c => `
            <tr data-busca="${esc((c.origem + ' ' + c.destino).toLowerCase())}" data-dir="${c.dir}" data-st="${c.status}">
              <td class="mono small">${c.data}</td>
              <td class="mono">${c.origem}</td>
              <td class="mono">${c.destino}</td>
              <td><span class="badge">${icon(DIR_ICO[c.dir],'ico ico-sm')}${c.dir[0].toUpperCase() + c.dir.slice(1)}</span></td>
              <td class="num">${c.dur}</td>
              <td class="small dim">${c.tronco}</td>
              <td>${badgeCdr(c.status)}</td>
              <td>${c.grav ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Ouvir">${icon('play','ico ico-sm')}</button>` : '<span class="muted">—</span>'}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <div class="card-foot pager">
        <span class="small muted" id="cdrCount"></span>
        <div class="pages">
          <button>${icon('chevronL','ico ico-sm')}</button>
          <button class="on">1</button><button>2</button><button>3</button><button>…</button><button>27</button>
          <button>${icon('chevronR','ico ico-sm')}</button>
        </div>
      </div>
    </div>`;
  },
  mount() {
    const q = document.getElementById('cdrQ'), d = document.getElementById('cdrDir'),
          s = document.getElementById('cdrSt'), cnt = document.getElementById('cdrCount'),
          rows = [...document.querySelectorAll('#tabCdr tbody tr')];
    const f = () => {
      const t = q.value.trim().toLowerCase(); let n = 0;
      rows.forEach(r => {
        const ok = (!t || r.dataset.busca.includes(t)) &&
                   (!d.value || r.dataset.dir === d.value) &&
                   (!s.value || r.dataset.st === s.value);
        r.hidden = !ok; if (ok) n++;
      });
      cnt.textContent = `Exibindo ${n} de 3.184 registros`;
    };
    [q, d, s].forEach(el => el.addEventListener('input', f)); f();
  }
};

/* ------------------------- Administrador · Usuários ------------------------- */
PAGES['admin.usuarios'] = {
  render(ctx) {
    return pageHead('Gerenciador de Usuários', 'Contas de acesso ao console e vínculo com ramais.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Novo usuário</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-4" style="margin-bottom:16px">
      ${Object.entries(ROLES).map(([k, r]) => {
        const n = USERS.filter(u => u.role === k).length;
        return `<div class="card kpi">
          <div class="k-top"><span class="k-label">${r.label}</span>
            <span class="k-ico" style="background:var(--${r.color}-soft);color:var(--${r.color})">${icon('users')}</span></div>
          <div class="k-val">${n}</div>
          <div class="k-foot"><span>${r.desc}</span></div>
        </div>`;
      }).join('')}
    </div>
    <div class="card"><div class="table-wrap">
      <table class="table">
        <thead><tr><th>Usuário</th><th>Perfil</th><th>Ramal</th><th>Setor</th><th>E-mail</th><th>Último acesso</th><th>Estado</th><th></th></tr></thead>
        <tbody>${USERS.map(u => {
          const r = ROLES[u.role];
          return `<tr>
            <td><span class="row gap-10"><span class="avatar avatar-sm">${initials(u.name)}</span>
                <span><b>${u.name}</b><div class="tiny muted mono">${u.user}</div></span></span></td>
            <td><span class="badge badge-${r.color}">${icon('shield','ico ico-sm')}${r.label}</span></td>
            <td class="mono">${u.ramal}</td>
            <td class="dim">${u.setor}</td>
            <td class="small dim">${u.email}</td>
            <td class="small dim">${u.ultimo}</td>
            <td>${u.status === 'ativo' ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
                 : u.status === 'inativo' ? '<span class="badge"><i class="dot"></i>Inativo</span>'
                 : '<span class="badge badge-danger"><i class="dot"></i>Bloqueado</span>'}</td>
            <td class="col-actions"><span class="row-actions">
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Redefinir senha" ${ctx.can('editar') ? '' : 'disabled'}>${icon('key','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" ${ctx.can('excluir') ? '' : 'disabled'}>${icon('trash','ico ico-sm')}</button>
            </span></td>
          </tr>`;
        }).join('')}
        </tbody>
      </table>
    </div></div>`;
  }
};

/* ------------------------- Administrador · Perfis e permissões ------------------------- */
PAGES['admin.permissoes'] = {
  render(ctx) {
    const roles = Object.entries(ROLES);
    const editavel = ctx.can('permissoes');

    const linhas = MENU.map(g => `
      <tr class="group-row"><td colspan="${roles.length + 1}">${g.label}</td></tr>
      ${g.items.map(i => `
        <tr>
          <td><span class="mod-name">${icon(i.icon || 'grid','ico ico-sm')}${i.label}
              <span class="tiny muted mono">${i.id}</span></span></td>
          ${roles.map(([k, r]) => {
            const on = Auth.can({ role: k }, i.id);
            return `<td class="role-cell">
              <label class="switch"><input type="checkbox" ${on ? 'checked' : ''} ${editavel ? '' : 'disabled'}
                     data-role="${k}" data-key="${i.id}"><span class="track"></span></label></td>`;
          }).join('')}
        </tr>`).join('')}
    `).join('');

    return pageHead('Perfis e Permissões',
      'Defina o que cada perfil enxerga no menu lateral e quais ações pode executar.',
      editavel ? `<button class="btn btn-outline btn-sm">${icon('plus','ico ico-sm')} Novo perfil</button>
                  <button class="btn btn-primary btn-sm" id="salvarPerms">${icon('check','ico ico-sm')} Salvar alterações</button>`
               : readOnlyNote(ctx)) + `

    <div class="grid g-4" style="margin-bottom:16px">
      ${roles.map(([k, r]) => `
        <div class="card" style="padding:16px">
          <div class="row gap-10" style="margin-bottom:10px">
            <span class="k-ico" style="background:var(--${r.color}-soft);color:var(--${r.color});width:34px;height:34px;border-radius:10px;display:grid;place-items:center">${icon('shield')}</span>
            <div><b>${r.label}</b><div class="tiny muted mono">${k}</div></div>
          </div>
          <p class="small dim" style="min-height:38px">${r.desc}</p>
          <div class="row wrap gap-4" style="margin-top:10px">
            ${r.caps.map(c => `<span class="badge badge-brand">${c}</span>`).join('') || '<span class="badge">somente leitura</span>'}
          </div>
        </div>`).join('')}
    </div>

    <div class="card">
      <div class="card-head">
        <div><div class="card-title">Matriz de acesso aos módulos</div>
             <div class="card-sub">Marque para liberar o módulo no menu lateral do perfil.</div></div>
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" id="permQ" placeholder="Filtrar módulo…"></div>
      </div>
      <div class="table-wrap" style="max-height:620px;overflow-y:auto">
        <table class="table matrix" id="tabPerm">
          <thead><tr><th>Módulo</th>
            ${roles.map(([, r]) => `<th class="role-col">${r.label}</th>`).join('')}
          </tr></thead>
          <tbody>${linhas}</tbody>
        </table>
      </div>
    </div>`;
  },
  mount(ctx) {
    const q = document.getElementById('permQ');
    q.addEventListener('input', () => {
      const t = q.value.trim().toLowerCase();
      document.querySelectorAll('#tabPerm tbody tr').forEach(tr => {
        if (tr.classList.contains('group-row')) { tr.hidden = !!t; return; }
        tr.hidden = t && !tr.textContent.toLowerCase().includes(t);
      });
    });
    const s = document.getElementById('salvarPerms');
    if (s) s.onclick = () => toast('Permissões salvas (protótipo — sem persistência).', 'ok');
  }
};

/* ------------------------- PCU · Meu ramal ------------------------- */
PAGES['pcu.meuramal'] = {
  render(ctx) {
    const s = ctx.sess;
    const opcoes = [
      ['Não perturbe (DND)', 'Encaminha as chamadas direto ao correio de voz.', false],
      ['Siga-me', 'Toca também no celular após 15 segundos.', true],
      ['Gravação de chamadas', 'Grava automaticamente chamadas entrantes e saintes.', true],
      ['Correio de voz por e-mail', `Envia o áudio para ${s.email}.`, true],
      ['Chamada em espera', 'Permite receber uma segunda chamada.', false]
    ];
    return pageHead('Meu Ramal', 'Preferências pessoais do seu ramal.') + `
      <div class="grid g-2-1">
        <div class="card">
          <div class="card-head"><div class="card-title">Recursos do ramal ${s.ramal}</div></div>
          <div class="card-body">
            <div class="deflist">
              ${opcoes.map(([t, d, on]) => `
                <div class="defrow">
                  <div class="dt"><b>${t}</b><small>${d}</small></div>
                  <div class="right">
                    <label class="switch"><input type="checkbox" ${on ? 'checked' : ''}><span class="track"></span></label>
                  </div>
                </div>`).join('')}
            </div>
          </div>
          <div class="card-foot right">
            <button class="btn btn-primary btn-sm">${icon('check','ico ico-sm')} Salvar preferências</button>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div class="card-title">Meu cartão</div></div>
          <div class="card-body center">
            <div class="avatar avatar-lg" style="margin:6px auto 12px">${initials(s.name)}</div>
            <b style="font-size:16px">${s.name}</b>
            <div class="small dim">${s.setor}</div>
            <div class="badge badge-brand" style="margin-top:10px">Ramal ${s.ramal}</div>
            <div class="deflist" style="margin-top:16px;text-align:left">
              ${[['Usuário', s.user], ['E-mail', s.email], ['Perfil', Auth.role(s).label]]
                .map(([k, v]) => `<div class="defrow" style="grid-template-columns:90px 1fr;padding:9px 0">
                   <div class="dt"><b>${k}</b></div><div class="small dim truncate">${v}</div></div>`).join('')}
            </div>
          </div>
        </div>
      </div>`;
  }
};

/* ------------------------- PCU · Minhas chamadas ------------------------- */
PAGES['pcu.chamadas'] = {
  render(ctx) {
    const meu = ctx.sess.ramal;
    const linhas = DEMO.cdr.slice(0, 8).map(c => `
      <tr>
        <td class="mono small">${c.data}</td>
        <td><span class="badge">${icon(DIR_ICO[c.dir],'ico ico-sm')}${c.dir}</span></td>
        <td class="mono">${c.dir === 'saida' ? c.destino : c.origem}</td>
        <td class="num">${c.dur}</td>
        <td>${badgeCdr(c.status)}</td>
      </tr>`).join('');
    return pageHead('Minhas Chamadas', `Histórico do ramal ${meu}.`) + `
      <div class="card"><div class="table-wrap">
        <table class="table">
          <thead><tr><th>Data / hora</th><th>Sentido</th><th>Contato</th><th>Duração</th><th>Estado</th></tr></thead>
          <tbody>${linhas}</tbody>
        </table>
      </div></div>`;
  }
};

/* ------------------------- Fallback genérico ------------------------- */
function paginaGenerica(ctx) {
  const { item, group } = ctx;
  return pageHead(item.label,
    `Módulo <span class="mono">${item.id}</span> do grupo ${group.label}.`,
    `${readOnlyNote(ctx)}
     ${ctx.can('editar') ? `<button class="btn btn-primary btn-sm">${icon('check','ico ico-sm')} Aplicar configurações</button>` : ''}`) + `
  <div class="grid g-2-1">
    <div class="card">
      <div class="card-head">
        <div><div class="card-title">Configurações do módulo</div>
             <div class="card-sub">Estrutura de exemplo — ligar aos endpoints reais do Asterisk.</div></div>
      </div>
      <div class="card-body">
        <div class="deflist">
          <div class="defrow">
            <div class="dt"><b>Módulo habilitado</b><small>Desative para ocultar o recurso da central.</small></div>
            <div><label class="switch"><input type="checkbox" checked ${ctx.can('editar') ? '' : 'disabled'}><span class="track"></span></label></div>
          </div>
          <div class="defrow">
            <div class="dt"><b>Nome de exibição</b><small>Como o módulo aparece para os usuários.</small></div>
            <div><input class="input" value="${esc(item.label)}" ${ctx.can('editar') ? '' : 'disabled'}></div>
          </div>
          <div class="defrow">
            <div class="dt"><b>Contexto Asterisk</b><small>Contexto de origem no dialplan.</small></div>
            <div><input class="input mono" value="from-internal" ${ctx.can('editar') ? '' : 'disabled'}></div>
          </div>
          <div class="defrow">
            <div class="dt"><b>Destino padrão</b><small>Para onde a chamada segue ao final.</small></div>
            <div><select class="select" ${ctx.can('editar') ? '' : 'disabled'}>
              <option>Ramal 1000 — Guilherme Leão</option><option>Fila 600 — Suporte Técnico</option>
              <option>URA Principal</option><option>Correio de Voz</option><option>Desligar</option>
            </select></div>
          </div>
          <div class="defrow">
            <div class="dt"><b>Observações</b><small>Notas internas da equipe de TI.</small></div>
            <div><textarea class="textarea" placeholder="Anotações sobre este módulo…" ${ctx.can('editar') ? '' : 'disabled'}></textarea></div>
          </div>
        </div>
      </div>
    </div>

    <div class="grid" style="align-content:start">
      <div class="card">
        <div class="card-head"><div class="card-title">Estado</div></div>
        <div class="card-body grid" style="gap:12px">
          <div class="row-between"><span class="dim small">Módulo</span><span class="badge badge-ok">Habilitado</span></div>
          <div class="row-between"><span class="dim small">Versão</span><span class="mono small">17.0.3</span></div>
          <div class="row-between"><span class="dim small">Publicador</span><span class="small">Telium Networks</span></div>
          <div class="row-between"><span class="dim small">Licença</span><span class="small">GPLv3+</span></div>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Seu acesso</div></div>
        <div class="card-body">
          <p class="small dim" style="margin-bottom:10px">Perfil <b>${Auth.role(ctx.sess).label}</b> — ações liberadas nesta tela:</p>
          <div class="row wrap gap-4">
            ${['criar','editar','excluir','exportar','reiniciar'].map(c =>
              `<span class="badge ${ctx.can(c) ? 'badge-ok' : ''}">${ctx.can(c) ? icon('check','ico ico-sm') : icon('x','ico ico-sm')}${c}</span>`).join('')}
          </div>
        </div>
      </div>
    </div>
  </div>`;
}

/* ------------------------- Acesso negado ------------------------- */
function paginaNegada(sess, key) {
  return `<div class="denied">
    <div class="lockcircle">${icon('lock')}</div>
    <h2 style="font-size:19px;margin-bottom:8px">Acesso não autorizado</h2>
    <p class="dim">O perfil <b>${Auth.role(sess).label}</b> não tem permissão para abrir
       <span class="mono">${esc(key)}</span>. Fale com um administrador do PABX.</p>
    <div style="margin-top:18px"><a class="btn btn-primary btn-sm" href="#/${Auth.homeFor(sess)}">Voltar ao início</a></div>
  </div>`;
}
