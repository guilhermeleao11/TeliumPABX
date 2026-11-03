/* =========================================================
   Telium PABX — Relatório consolidado
   Uma seleção (a central, uma fila ou um ramal) num dia ou num
   período: recebidas, feitas, completadas, pesquisa de satisfação,
   por atendente e por hora. Termina com a conferência: cada total
   e as partes que o formam, para quem lê ver que a conta fecha.
   ========================================================= */

const RC_TIPOS = { central: 'Toda a central', fila: 'Uma fila', ramal: 'Um ramal' };

/** "03/11/2026", ou "01/11/2026 a 03/11/2026". */
function rcPeriodo(p) {
  const br = d => d.split('-').reverse().join('/');
  return p.de === p.ate ? br(p.de) : `${br(p.de)} a ${br(p.ate)}`;
}

/** Segundos em "1h 02min 03s", "4min 05s", "42s". */
function rcTempo(seg) {
  seg = Math.round(Number(seg || 0));
  if (seg <= 0) return '0s';
  const h = Math.floor(seg / 3600), m = Math.floor((seg % 3600) / 60), s = seg % 60;
  if (h) return `${h}h ${String(m).padStart(2, '0')}min ${String(s).padStart(2, '0')}s`;
  if (m) return `${m}min ${String(s).padStart(2, '0')}s`;
  return `${s}s`;
}

const rcPct = (n, de) => de > 0 ? `${String(Math.round(1000 * n / de) / 10).replace('.', ',')}%` : '—';

function rcIndicador(i) {
  if (i.valor === null || i.valor === undefined) return '—';
  return i.unidade === 's' ? rcTempo(i.valor) : `${String(i.valor).replace('.', ',')}%`;
}

PAGES['rel.consolidado'] = {
  _f: null,
  _r: null,

  async render(ctx) {
    let op;
    try { op = await Api.get('/relatorios/consolidado'); }
    catch (e) { return pageHead('Relatório Consolidado', '') + blocoErro(e); }
    this._op = op;
    this._f ??= { tipo: 'fila', alvo: op.filas[0]?.numero || '', de: diaLocal(), ate: diaLocal() };
    if (this._f.tipo === 'fila' && !op.filas.length) this._f.tipo = 'central';

    const f = this._f;
    const alvos = f.tipo === 'fila' ? op.filas : f.tipo === 'ramal' ? op.ramais : [];

    const filtros = `
      <div class="card no-print" style="padding:14px;margin-bottom:16px">
        <div class="row gap-8 wrap" style="align-items:flex-end">
          <label class="field" style="min-width:160px"><span class="small muted">Seleção</span>
            <select class="select" id="rcTipo">
              ${Object.entries(RC_TIPOS).map(([k, v]) =>
                `<option value="${k}" ${f.tipo === k ? 'selected' : ''} ${k === 'fila' && !op.filas.length ? 'disabled' : ''}>${v}</option>`).join('')}
            </select></label>
          ${f.tipo === 'central' ? '' : `
          <label class="field" style="min-width:220px"><span class="small muted">${f.tipo === 'fila' ? 'Fila' : 'Ramal'}</span>
            <select class="select" id="rcAlvo">
              ${alvos.map(a => `<option value="${esc(a.numero)}" ${String(f.alvo) === String(a.numero) ? 'selected' : ''}>${esc(a.numero)} — ${esc(a.nome)}</option>`).join('')}
            </select></label>`}
          <label class="field"><span class="small muted">De</span>
            <input class="input" type="date" id="rcDe" value="${esc(f.de)}" max="${diaLocal()}"></label>
          <label class="field"><span class="small muted">Até</span>
            <input class="input" type="date" id="rcAte" value="${esc(f.ate)}" max="${diaLocal()}"></label>
          <button class="btn btn-primary" id="rcGerar">${icon('chart', 'ico ico-sm')} Gerar relatório</button>
          <span class="grow"></span>
          ${this._r && ctx.can('exportar') ? `<button class="btn btn-outline btn-sm" data-rc-baixar="pdf">${icon('download', 'ico ico-sm')} Baixar PDF</button>
          <button class="btn btn-ghost btn-sm" data-rc-baixar="csv">${icon('download', 'ico ico-sm')} Planilha (CSV)</button>` : ''}
        </div>
        <div class="tiny muted" style="margin-top:8px">Um dia, ou um período de até ${op.max_dias} dias. Para um dia só, deixe "De" e "Até" iguais.</div>
      </div>`;

    return pageHead('Relatório Consolidado',
      'O resumo de uma fila, de um ramal ou da central inteira no dia que você escolher.') +
      filtros + (this._r ? this.relatorio(this._r) :
        `<div class="card">${vazio('chart', 'Escolha a seleção e o dia',
          'Depois clique em "Gerar relatório". Os números saem do registro de chamadas, do registro das filas e da pesquisa de satisfação.')}</div>`);
  },

  relatorio(r) {
    const res = r.resumo;
    const kpi = (rotulo, valor, rodape, tom = 'brand') => `
      <div class="card kpi">
        <div class="k-top"><span class="k-label">${rotulo}</span></div>
        <div class="k-val" style="color:var(--${tom})">${valor}</div>
        <div class="k-foot"><span>${rodape}</span></div>
      </div>`;

    const totalRodape = res.internas === null
      ? `${num(res.recebidas)} recebidas + ${num(res.feitas)} feitas`
      : `${num(res.recebidas)} recebidas + ${num(res.feitas)} feitas + ${num(res.internas)} internas`;

    const kpis = `<div class="grid g-4 rc-kpis" style="margin-bottom:16px">
      ${kpi('Total de ligações', num(res.total), totalRodape)}
      ${kpi('Completadas', num(res.completadas), `${rcPct(res.completadas, res.total)} do total · alguém atendeu`, 'ok')}
      ${kpi('Não completadas', num(res.nao_completadas), `${rcPct(res.nao_completadas, res.total)} do total`, res.nao_completadas ? 'warn' : 'ok')}
      ${kpi('Tempo de conversa', rcTempo(res.falado), `média de ${rcTempo(res.tma)} por ligação atendida`, 'info')}
    </div>`;

    const blocos = r.blocos.map(b => `
      <div class="card rc-bloco">
        <div class="card-head"><div><div class="card-title">${esc(b.titulo)} <span class="muted">· ${num(b.total)}</span></div>
          <div class="card-sub">${esc(b.descricao)}</div></div></div>
        <div class="card-body">
          <table class="table rc-tabela"><tbody>
            ${b.desfechos.map(d => `<tr><td>${esc(d.rotulo)}</td><td class="num"><b>${num(d.n)}</b></td>
              <td class="num muted small" style="width:70px">${rcPct(d.n, b.total)}</td></tr>`).join('')}
            <tr class="rc-soma"><td><b>Total</b></td><td class="num"><b>${num(b.total)}</b></td><td></td></tr>
          </tbody></table>
          ${b.indicadores.length ? `<div class="rc-indicadores">${b.indicadores.map(i => `
            <div><div class="tiny muted"${i.ajuda ? ` data-tip="${esc(i.ajuda)}"` : ''}>${esc(i.rotulo)}</div>
                 <b>${rcIndicador(i)}</b></div>`).join('')}</div>` : ''}
        </div>
      </div>`).join('');

    const pesquisas = r.pesquisas.length ? r.pesquisas.map(p => {
      const s = p.resumo;
      const maior = Math.max(1, ...p.por_nota.map(n => n.quantidade));
      return `<div class="card rc-bloco">
        <div class="card-head"><div><div class="card-title">Pesquisa de satisfação · ${esc(p.pesquisa.nome)}</div>
          <div class="card-sub">Nota de ${p.pesquisa.nota_min} a ${p.pesquisa.nota_max}, ${p.pesquisa.sentido === 'menor_melhor' ? p.pesquisa.nota_min : p.pesquisa.nota_max} é a melhor</div></div></div>
        <div class="card-body">
          <div class="rc-indicadores" style="margin-top:0;margin-bottom:12px">
            <div><div class="tiny muted">Oferecidas</div><b>${num(s.ofertadas)}</b></div>
            <div><div class="tiny muted">Responderam</div><b>${num(s.respondidas)}</b> <span class="tiny muted">${rcPct(s.respondidas, s.ofertadas)}</span></div>
            <div><div class="tiny muted">Nota média</div><b>${s.media_nota === null ? '—' : String(s.media_nota).replace('.', ',')}</b></div>
            <div><div class="tiny muted" data-tip="De 0 (pior) a 100 (melhor), no sentido da pesquisa">Satisfação</div><b>${s.satisfacao === null ? '—' : String(s.satisfacao).replace('.', ',')}</b></div>
          </div>
          <table class="table rc-tabela"><tbody>
            <tr><td>Responderam</td><td class="num"><b>${num(s.respondidas)}</b></td></tr>
            <tr><td>Não responderam (não digitaram nada)</td><td class="num"><b>${num(s.sem_resposta)}</b></td></tr>
            ${s.invalidas ? `<tr><td>Tecla inválida</td><td class="num"><b>${num(s.invalidas)}</b></td></tr>` : ''}
            ${s.desligaram ? `<tr><td>Desligaram no meio</td><td class="num"><b>${num(s.desligaram)}</b></td></tr>` : ''}
            <tr class="rc-soma"><td><b>Oferecidas</b></td><td class="num"><b>${num(s.ofertadas)}</b></td></tr>
          </tbody></table>
          ${s.respondidas ? `<div style="margin-top:12px">${p.por_nota.map(n => `
            <div class="row gap-8" style="align-items:center;margin:3px 0">
              <span class="small mono" style="width:56px">Nota ${n.nota}</span>
              <div class="barra-uso grow"><span style="width:${Math.round(100 * n.quantidade / maior)}%"></span></div>
              <span class="small num" style="width:32px;text-align:right">${num(n.quantidade)}</span>
            </div>`).join('')}</div>` : ''}
          ${s.outra_escala ? `<div class="tiny muted" style="margin-top:8px">${num(s.outra_escala)} resposta(s) foram dadas numa escala antiga e não entram na nota média, só no índice de satisfação.</div>` : ''}
        </div>
      </div>`;
    }).join('') : `<div class="card rc-bloco"><div class="card-head"><div class="card-title">Pesquisa de satisfação</div></div>
        <div class="card-body small muted">Nenhuma pesquisa foi oferecida nesta seleção no período.</div></div>`;

    const fila = r.selecao.tipo === 'fila';
    const atendentes = r.por_atendente.length ? `
      <div class="card rc-bloco rc-largo">
        <div class="card-head"><div><div class="card-title">${fila ? 'Por agente' : 'Por ramal'}</div>
          <div class="card-sub">${fila ? 'Atendidas da fila e ligações feitas por cada agente.'
            : 'Cada ramal com o que recebeu e o que fez. Uma ligação interna aparece nos dois ramais.'}</div></div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr>${fila
            ? '<th>Agente</th><th>Ramal</th><th class="num">Atendidas</th><th class="num">TMA</th><th class="num" data-tip="Vezes em que tocou e o agente não atendeu a tempo">Não atendeu</th><th class="num">Feitas</th><th class="num">Feitas atendidas</th><th class="num" data-tip="Índice da pesquisa, de 0 a 100">Satisfação</th>'
            : '<th>Ramal</th><th class="num">Recebidas</th><th class="num">Atendeu</th><th class="num">Ninguém atendeu</th><th class="num">Feitas</th><th class="num">Feitas atendidas</th><th class="num">Tempo falado</th>'}</tr></thead>
          <tbody>${r.por_atendente.map(a => fila ? `
            <tr><td>${esc(a.nome)}</td><td class="mono">${esc(a.ramal || '—')}</td><td class="num">${num(a.atendidas)}</td>
              <td class="num">${a.tma ? rcTempo(a.tma) : '—'}</td><td class="num">${num(a.nao_atendeu)}</td>
              <td class="num">${num(a.feitas)}</td><td class="num">${num(a.feitas_atendidas)}</td>
              <td class="num">${a.nota === null ? '—' : String(a.nota).replace('.', ',')}${a.respostas ? ` <span class="tiny muted">(${a.respostas})</span>` : ''}</td></tr>` : `
            <tr><td><b class="mono">${esc(a.ramal)}</b> <span class="small muted">${esc(a.nome)}</span></td>
              <td class="num">${num(a.recebidas)}</td><td class="num">${num(a.atendidas)}</td><td class="num">${num(a.nao_atendeu)}</td>
              <td class="num">${num(a.feitas)}</td><td class="num">${num(a.feitas_atendidas)}</td><td class="num">${rcTempo(a.falado)}</td></tr>`).join('')}
          </tbody></table></div>
      </div>` : '';

    const filas = r.filas && r.filas.length ? `
      <div class="card rc-bloco rc-largo">
        <div class="card-head"><div><div class="card-title">Por fila</div>
          <div class="card-sub">Das ligações recebidas, as que passaram por fila.</div></div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Fila</th><th class="num">Recebidas</th><th class="num">Atendidas</th><th class="num">Abandonadas</th><th class="num">Nível de serviço</th></tr></thead>
          <tbody>${r.filas.map(f => `<tr><td><b class="mono">${esc(f.numero)}</b> ${esc(f.nome)}</td>
            <td class="num">${num(f.recebidas)}</td><td class="num">${num(f.atendidas)}</td><td class="num">${num(f.abandonadas)}</td>
            <td class="num">${f.sla === null ? '—' : String(f.sla).replace('.', ',') + '%'}</td></tr>`).join('')}</tbody>
        </table></div>
      </div>` : '';

    const comInternas = res.internas !== null;
    const horas = r.por_hora.length ? `
      <div class="card rc-bloco rc-largo">
        <div class="card-head"><div class="card-title">Por hora</div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Hora</th><th class="num">Recebidas</th><th class="num">Feitas</th>${comInternas ? '<th class="num">Internas</th>' : ''}<th class="num">Total</th><th class="num">Atendidas</th></tr></thead>
          <tbody>${r.por_hora.map(h => `<tr><td class="mono">${String(h.hora).padStart(2, '0')}:00</td>
            <td class="num">${num(h.recebidas)}</td><td class="num">${num(h.feitas)}</td>${comInternas ? `<td class="num">${num(h.internas)}</td>` : ''}
            <td class="num"><b>${num(h.recebidas + h.feitas + h.internas)}</b></td><td class="num">${num(h.atendidas)}</td></tr>`).join('')}
            <tr class="rc-soma"><td><b>Total</b></td>
              <td class="num"><b>${num(r.por_hora.reduce((s, h) => s + h.recebidas, 0))}</b></td>
              <td class="num"><b>${num(r.por_hora.reduce((s, h) => s + h.feitas, 0))}</b></td>
              ${comInternas ? `<td class="num"><b>${num(r.por_hora.reduce((s, h) => s + h.internas, 0))}</b></td>` : ''}
              <td class="num"><b>${num(res.total)}</b></td>
              <td class="num"><b>${num(r.por_hora.reduce((s, h) => s + h.atendidas, 0))}</b></td></tr>
          </tbody></table></div>
      </div>` : '';

    const conferencia = `
      <div class="card rc-bloco rc-largo">
        <div class="card-head"><div><div class="card-title">Conferência ${r.confere
            ? '<span class="badge badge-ok">todas as contas fecham</span>'
            : '<span class="badge badge-danger">há conta que não fecha</span>'}</div>
          <div class="card-sub">Cada total e as partes que o formam.</div></div></div>
        <div class="table-wrap"><table class="table">
          <tbody>${r.conferencia.map(c => `<tr><td>${esc(c.descricao)}</td><td class="mono small">${esc(c.conta)}</td>
            <td>${c.ok ? '<span class="badge badge-ok">fecha</span>' : '<span class="badge badge-danger">não fecha</span>'}</td></tr>`).join('')}</tbody>
        </table></div>
        <div class="card-body" style="padding-top:0">
          <ul class="small muted rc-obs">${r.observacoes.map(o => `<li>${esc(o)}</li>`).join('')}</ul>
        </div>
      </div>`;

    return `<div class="rc-relatorio" id="rcRelatorio">
      <div class="rc-cabecalho">
        <div>
          <div class="tiny muted">Relatório consolidado</div>
          <h2 style="margin:2px 0 4px">${esc(r.selecao.nome)}</h2>
          <div class="small muted">${esc(r.selecao.descricao)}</div>
        </div>
        <div style="text-align:right">
          <div class="small"><b>${rcPeriodo(r.periodo)}</b>${r.periodo.dias > 1 ? ` <span class="muted">(${r.periodo.dias} dias)</span>` : ''}</div>
          <div class="tiny muted">gerado em ${dataHora(r.gerado_em)}</div>
        </div>
      </div>
      ${kpis}
      <div class="grid g-2 rc-grade">${blocos}${pesquisas}</div>
      ${atendentes}${filas}${horas}${conferencia}
    </div>`;
  },

  mount() {
    const f = this._f;
    if (!f) return;
    const mudou = () => { this._r = null; };
    document.getElementById('rcTipo')?.addEventListener('change', e => {
      f.tipo = e.target.value;
      const lista = f.tipo === 'fila' ? this._op.filas : f.tipo === 'ramal' ? this._op.ramais : [];
      f.alvo = lista[0]?.numero || '';
      mudou(); App.route();
    });
    document.getElementById('rcAlvo')?.addEventListener('change', e => { f.alvo = e.target.value; });
    document.getElementById('rcDe')?.addEventListener('change', e => {
      f.de = e.target.value;
      // Um dia é o caso comum: mudar o início leva o fim junto, se ele ficou para trás.
      if (!f.ate || f.ate < f.de) { f.ate = f.de; document.getElementById('rcAte').value = f.de; }
    });
    document.getElementById('rcAte')?.addEventListener('change', e => { f.ate = e.target.value; });

    document.getElementById('rcGerar')?.addEventListener('click', async ev => {
      const b = ev.currentTarget;
      b.disabled = true;
      try {
        this._r = await Api.get('/relatorios/consolidado', { tipo: f.tipo, alvo: f.alvo, de: f.de, ate: f.ate || f.de });
        App.route();
      } catch (e) {
        toast(e.message, 'err');
        b.disabled = false;
      }
    });
    // O que está na tela, e não o que foi mexido nos filtros depois.
    document.querySelectorAll('[data-rc-baixar]').forEach(b => b.addEventListener('click', async () => {
      const r = this._r;
      const q = new URLSearchParams({ tipo: r.selecao.tipo, alvo: r.selecao.alvo, de: r.periodo.de, ate: r.periodo.ate,
                                      formato: b.dataset.rcBaixar });
      const antes = b.innerHTML;
      b.disabled = true;
      b.innerHTML = '<span class="spin"></span> Gerando…';
      try { await Api.salvarArquivo(`/relatorios/consolidado?${q}`); } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
      b.innerHTML = antes;
    }));
  }
};
