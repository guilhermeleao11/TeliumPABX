/* =========================================================
   Telium PABX — Pesquisa de satisfação
   Duas abas: os resultados (índice, notas, filas, atendentes e
   cada resposta com o número de quem ligou) e as pesquisas em si
   (mensagens, escala e o número de transferência).
   ========================================================= */

const PESQ_STATUS = {
  respondida:   ['Respondida', 'badge-ok'],
  sem_resposta: ['Não respondeu', ''],
  invalida:     ['Tecla inválida', 'badge-warn'],
  desligou:     ['Desligou no meio', 'badge-warn']
};
const PESQ_AVALIACAO = {
  satisfeito:   ['Satisfeito', 'badge-ok'],
  neutro:       ['Neutro', ''],
  insatisfeito: ['Insatisfeito', 'badge-danger']
};

/** "0 a 10 · 10 é a melhor" — a escala dita do jeito que o cliente a usa. */
function escalaPesquisa(p) {
  const melhor = p.sentido === 'menor_melhor' ? p.nota_min : p.nota_max;
  return `${p.nota_min} a ${p.nota_max} · ${melhor} é a melhor`;
}

/** Índice de 0 a 100 da nota, no sentido da pesquisa. */
function indiceDaNota(p, nota) {
  const min = Number(p.nota_min), max = Number(p.nota_max);
  if (max <= min) return 0;
  return Math.round(100 * (p.sentido === 'menor_melhor' ? max - nota : nota - min) / (max - min));
}

/** A cor do índice: as mesmas faixas que o servidor usa para contar. */
function corDoIndice(v, faixas = { satisfeito: 70, insatisfeito: 30 }) {
  if (v === null || v === undefined) return 'var(--text-muted)';
  return v >= faixas.satisfeito ? 'var(--ok)' : v <= faixas.insatisfeito ? 'var(--danger)' : 'var(--warn)';
}

const pct = v => v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}%`;
const decimal = v => v === null || v === undefined ? '—' : String(v).replace('.', ',');

PAGES['apps.pesquisas'] = {
  _aba: null,
  _f: { pesquisa: '', de: '', ate: '', fila: '', ramal: '', status: '', pagina: 1 },

  async render(ctx) {
    let pesquisas, anuncios, filas;
    try {
      [pesquisas, anuncios, filas] = await Promise.all([
        Api.get('/pesquisas', { limite: 200 }),
        Api.get('/anuncios', { limite: 500 }).catch(() => ({ dados: [] })),
        Api.get('/filas', { limite: 500 }).catch(() => ({ dados: [] }))
      ]);
    } catch (e) {
      return pageHead('Pesquisa de satisfação', '') + blocoErro(e);
    }
    this._pesquisas = pesquisas.dados || [];
    this._anuncios = anuncios.dados || [];
    this._filas = filas.dados || [];
    if (!this._aba) this._aba = this._pesquisas.length ? 'resultados' : 'pesquisas';

    const cabecalho = pageHead('Pesquisa de satisfação',
      'O cliente dá a nota do atendimento logo depois que o atendente desliga, ou quando é transferido para a pesquisa.',
      `${readOnlyNote(ctx)}${ctx.can('criar') ? `<button class="btn btn-primary btn-sm" data-nova-pesquisa>
        ${icon('plus','ico ico-sm')} Nova pesquisa</button>` : ''}`);

    const abas = `<div class="segmented" style="margin-bottom:16px" data-abas-pesquisa>
      <button data-aba="resultados" class="${this._aba === 'resultados' ? 'on' : ''}">Resultados</button>
      <button data-aba="pesquisas" class="${this._aba === 'pesquisas' ? 'on' : ''}">Pesquisas (${this._pesquisas.length})</button>
    </div>`;

    const corpo = this._aba === 'pesquisas' ? this.htmlPesquisas(ctx) : await this.htmlResultados(ctx);
    return cabecalho + abas + corpo;
  },

  // ------------------------------------------------------------ Pesquisas
  htmlPesquisas(ctx) {
    if (!this._pesquisas.length) {
      return `<div class="card">${vazio('star', 'Nenhuma pesquisa criada',
        'Crie a pesquisa, escolha as mensagens e a escala, e depois ligue-a a uma fila em Aplicações › Filas.',
        ctx.can('criar') ? '<button class="btn btn-primary" data-nova-pesquisa>Criar a primeira pesquisa</button>' : '')}</div>`;
    }

    const anuncio = id => this._anuncios.find(a => String(a.id) === String(id))?.nome;
    const mensagem = (rotulo, id, padrao) => `<div class="defrow" style="grid-template-columns:130px 1fr;padding:6px 0">
      <div class="dt small dim">${rotulo}</div>
      <div class="small">${id && anuncio(id) ? esc(anuncio(id)) : `<span class="muted">${padrao}</span>`}</div></div>`;

    return `<div class="grid g-2">${this._pesquisas.map(p => {
      const filas = this._filas.filter(f => String(f.pesquisa_id) === String(p.id));
      return `<div class="card">
        <div class="card-head">
          <div class="card-title">${esc(p.nome)}</div>
          ${Number(p.ativo) ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>' : '<span class="badge"><i class="dot"></i>Parada</span>'}
        </div>
        <div class="card-body" style="display:grid;gap:12px">
          ${p.descricao ? `<p class="small dim" style="margin:0">${esc(p.descricao)}</p>` : ''}
          <div class="row gap-8 wrap">
            <span class="badge badge-brand">${icon('star','ico ico-sm')}${esc(escalaPesquisa(p))}</span>
            ${p.numero ? `<span class="badge" data-tip="O atendente transfere o cliente para este número">${icon('phone','ico ico-sm')}transferir para <b class="mono">${esc(p.numero)}</b></span>` : ''}
            <span class="badge">${p.tentativas} tentativa(s) · ${p.segundos} s</span>
          </div>
          <div class="deflist">
            ${mensagem('Saudação', p.anuncio_saudacao_id, 'sem saudação — vai direto à pergunta')}
            ${mensagem('Mensagem das notas', p.anuncio_pergunta_id, 'sem mensagem — só um bipe')}
            ${mensagem('Agradecimento', p.anuncio_obrigado_id, 'agradecimento padrão do Asterisk')}
            ${mensagem('Tecla inválida', p.anuncio_invalida_id, 'aviso padrão do Asterisk')}
          </div>
          <div class="small">${filas.length
            ? `Nas filas: ${filas.map(f => `<span class="badge">${esc(f.numero)} — ${esc(f.nome)}</span>`).join(' ')}`
            : `<span class="muted">Em nenhuma fila. Escolha a pesquisa na aba "Pesquisa" da fila${p.numero ? ', ou transfira para o número dela' : ''}.</span>`}</div>
        </div>
        <div class="card-foot row gap-8">
          <button class="btn btn-ghost btn-sm" data-resultados-de="${p.id}">${icon('chart','ico ico-sm')} Resultados</button>
          <span class="grow"></span>
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm" data-editar-pesquisa="${p.id}">${icon('edit','ico ico-sm')} Editar</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm" data-excluir-pesquisa="${p.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </div>
      </div>`;
    }).join('')}</div>`;
  },

  // ----------------------------------------------------------- Resultados
  async htmlResultados(ctx) {
    if (!this._pesquisas.length) return this.htmlPesquisas(ctx);

    const f = this._f;
    // Abre na pesquisa que está em uso: a de uma fila, senão a primeira ativa.
    if (!this._pesquisas.some(p => String(p.id) === String(f.pesquisa))) {
      const naFila = this._pesquisas.find(p => Number(p.ativo)
        && this._filas.some(q => String(q.pesquisa_id) === String(p.id)));
      f.pesquisa = String((naFila || this._pesquisas.find(p => Number(p.ativo)) || this._pesquisas[0]).id);
    }
    if (!f.ate) f.ate = diaLocal();
    if (!f.de) { const d = new Date(); d.setDate(d.getDate() - 29); f.de = diaLocal(d); }

    let r, lista;
    try {
      const filtros = { pesquisa: f.pesquisa, de: f.de, ate: f.ate, fila: f.fila, ramal: f.ramal };
      [r, lista] = await Promise.all([
        Api.get('/pesquisas/resultados', filtros),
        Api.get('/pesquisas/respostas', { ...filtros, status: f.status, pagina: f.pagina, limite: 25 })
      ]);
    } catch (e) { return blocoErro(e); }
    this._r = r;

    const p = r.pesquisa, s = r.resumo, fx = r.faixas;
    const filtrosHtml = `<div class="card" style="margin-bottom:16px"><div class="card-body row gap-8 wrap" style="align-items:flex-end">
      <div class="field" style="min-width:220px"><label class="label">Pesquisa</label>
        <select class="select" data-f="pesquisa">${this._pesquisas.map(x =>
          `<option value="${x.id}" ${String(x.id) === String(f.pesquisa) ? 'selected' : ''}>${esc(x.nome)}${Number(x.ativo) ? '' : ' (parada)'}</option>`).join('')}</select></div>
      <div class="field"><label class="label">De</label><input class="input" type="date" data-f="de" value="${esc(f.de)}"></div>
      <div class="field"><label class="label">Até</label><input class="input" type="date" data-f="ate" value="${esc(f.ate)}"></div>
      <div class="field" style="min-width:180px"><label class="label">Fila</label>
        <select class="select" data-f="fila"><option value="">Todas</option>${this._filas.map(x =>
          `<option value="${esc(x.numero)}" ${x.numero === f.fila ? 'selected' : ''}>${esc(x.numero)} — ${esc(x.nome)}</option>`).join('')}</select></div>
      <div class="field" style="min-width:200px"><label class="label">Atendente</label>
        <select class="select" data-f="ramal"><option value="">Todos</option>${(r.por_atendente || []).map(a =>
          `<option value="${esc(a.atendente)}" ${a.atendente === f.ramal ? 'selected' : ''}>${esc(a.nome || a.atendente)}${a.ramal ? ` (${esc(a.ramal)})` : ''}</option>`).join('')}
          ${f.ramal && !(r.por_atendente || []).some(a => a.atendente === f.ramal) ? `<option value="${esc(f.ramal)}" selected>${esc(f.ramal)}</option>` : ''}</select></div>
      <span class="grow"></span>
      ${ctx.can('exportar') ? `<button class="btn btn-outline btn-sm" data-exportar-pesquisa>${icon('download','ico ico-sm')} Exportar respostas</button>` : ''}
    </div></div>`;

    if (!s.ofertadas) {
      return filtrosHtml + `<div class="card">${vazio('chart', 'Nenhuma resposta no período',
        `A pesquisa "${esc(p.nome)}" ainda não foi feita a ninguém entre ${esc(f.de.split('-').reverse().join('/'))} e ${esc(f.ate.split('-').reverse().join('/'))}. `
        + 'Confira se ela está numa fila (ou se o atendente transfere para o número dela) e se as configurações foram aplicadas.')}</div>`;
    }

    const kpi = (label, valor, rodape, ico, cor) => `<div class="card kpi">
      <div class="k-top"><span class="k-label">${label}</span>
        <span class="k-ico" style="background:color-mix(in srgb, ${cor} 14%, transparent);color:${cor}">${icon(ico)}</span></div>
      <div class="k-val" style="color:${cor}">${valor}</div>
      <div class="k-foot"><span>${rodape}</span></div></div>`;

    const kpis = `<div class="grid g-4" style="margin-bottom:16px">
      ${kpi('Índice de satisfação', s.satisfacao === null ? '—' : decimal(s.satisfacao),
            'de 0 (pior) a 100 (melhor)', 'star', corDoIndice(s.satisfacao, fx))}
      ${kpi('Satisfeitos', pct(s.satisfeitos_pct),
            `${pct(s.neutros_pct)} neutros · ${pct(s.insatisfeitos_pct)} insatisfeitos`, 'checkCirc', 'var(--ok)')}
      ${kpi('Respostas', num(s.respondidas),
            `de ${num(s.ofertadas)} pesquisas feitas · ${pct(s.taxa_resposta)} responderam`, 'users', 'var(--brand)')}
      ${kpi('Média da nota', decimal(s.media_nota), esc(escalaPesquisa(p))
            + (s.outra_escala ? ` · ${num(s.outra_escala)} em escala anterior` : ''), 'activity', 'var(--info)')}
    </div>`;

    const total = s.respondidas || 1;
    const faixa = (v, cor, rotulo) => v ? `<div style="width:${v}%;background:${cor}" data-tip="${rotulo}: ${pct(v)}"></div>` : '';
    const composicao = `<div class="pesq-faixa">${faixa(s.satisfeitos_pct, 'var(--ok)', 'Satisfeitos')}${faixa(s.neutros_pct, 'var(--warn)', 'Neutros')}${faixa(s.insatisfeitos_pct, 'var(--danger)', 'Insatisfeitos')}</div>
      <div class="legend" style="margin-top:8px">
        <span class="li"><i class="sw" style="background:var(--ok)"></i>Satisfeitos (índice ${fx.satisfeito} ou mais)</span>
        <span class="li"><i class="sw" style="background:var(--warn)"></i>Neutros</span>
        <span class="li"><i class="sw" style="background:var(--danger)"></i>Insatisfeitos (índice ${fx.insatisfeito} ou menos)</span>
      </div>`;

    const maiorNota = Math.max(1, ...r.por_nota.map(n => n.quantidade));
    const distribuicao = r.por_nota.map(n => `<div class="pesq-nota">
      <b class="mono">${n.nota}</b>
      <div class="pesq-trilho"><div style="width:${(100 * n.quantidade / maiorNota).toFixed(1)}%;background:${corDoIndice(n.satisfacao, fx)}"></div></div>
      <span class="num small">${num(n.quantidade)}</span>
      <span class="tiny muted num">${Math.round(100 * n.quantidade / total)}%</span>
    </div>`).join('');

    const naoCompletaram = `<div class="deflist">
      ${[['Não responderam', s.sem_resposta, 'ficaram em silêncio até acabarem as tentativas'],
         ['Tecla inválida', s.invalidas, 'digitaram fora da escala em todas as tentativas'],
         ['Desligaram no meio', s.desligaram, 'saíram antes de dar a nota']]
        .map(([k, v, h]) => `<div class="defrow" style="grid-template-columns:1fr auto;padding:8px 0">
          <div><b class="small">${k}</b><div class="tiny muted">${h}</div></div><div class="num"><b>${num(v)}</b></div></div>`).join('')}
    </div>`;

    const linhaIndice = v => `<span class="row gap-6" style="justify-content:flex-end"><span class="pesq-mini"><i style="width:${v ?? 0}%;background:${corDoIndice(v, fx)}"></i></span>
      <b class="num" style="color:${corDoIndice(v, fx)}">${decimal(v)}</b></span>`;

    const tabelaFilas = r.por_fila.length ? `<div class="table-wrap"><table class="table">
      <thead><tr><th>Fila</th><th class="num">Feitas</th><th class="num">Respostas</th><th class="num">Média</th><th class="num">Satisfeitos</th><th class="num">Índice</th></tr></thead>
      <tbody>${r.por_fila.map(x => `<tr>
        <td>${x.fila ? `<b class="mono">${esc(x.fila)}</b> ${esc(x.nome || '')}` : '<span class="muted">sem fila (transferência ou destino)</span>'}</td>
        <td class="num">${num(x.ofertadas)}</td><td class="num">${num(x.respondidas)}</td>
        <td class="num">${decimal(x.media_nota)}</td>
        <td class="num">${x.respondidas ? pct(Math.round(1000 * x.satisfeitos / x.respondidas) / 10) : '—'}</td>
        <td class="num">${linhaIndice(x.satisfacao)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="small muted">Nenhuma.</p>';

    const tabelaAtendentes = r.por_atendente.length ? `<div class="table-wrap"><table class="table">
      <thead><tr><th>Atendente</th><th class="num">Feitas</th><th class="num">Respostas</th><th class="num">Média</th><th class="num">Satisfeitos</th><th class="num">Índice</th></tr></thead>
      <tbody>${r.por_atendente.map(x => `<tr>
        <td><b>${esc(x.nome || x.atendente)}</b>${x.ramal ? ` <span class="tiny muted mono">ramal ${esc(x.ramal)}</span>` : ''}</td>
        <td class="num">${num(x.ofertadas)}</td><td class="num">${num(x.respondidas)}</td>
        <td class="num">${decimal(x.media_nota)}</td>
        <td class="num">${x.respondidas ? pct(Math.round(1000 * x.satisfeitos / x.respondidas) / 10) : '—'}</td>
        <td class="num">${linhaIndice(x.satisfacao)}</td></tr>`).join('')}</tbody></table></div>`
      : '<p class="small muted">As respostas deste período não têm atendente identificado.</p>';

    const tabelaDias = r.por_dia.length > 1 ? `<div class="card" style="margin-top:16px">
      <div class="card-head"><div class="card-title">Dia a dia</div></div>
      <div class="card-body"><div class="pesq-dias">${r.por_dia.map(d => `<div class="pesq-dia" data-tip="${esc(d.dia.split('-').reverse().join('/'))}: índice ${decimal(d.satisfacao)}, ${num(d.respondidas)} resposta(s)">
        <div class="pesq-coluna"><i style="height:${d.satisfacao ?? 0}%;background:${corDoIndice(d.satisfacao, fx)}"></i></div>
        <span class="tiny muted">${esc(d.dia.slice(8, 10))}/${esc(d.dia.slice(5, 7))}</span></div>`).join('')}</div>
        <p class="tiny muted" style="margin:8px 0 0">Altura = índice de satisfação do dia (0 a 100).</p></div></div>` : '';

    const statusOpcoes = [['', 'Todas as situações'], ...Object.entries(PESQ_STATUS).map(([k, [rot]]) => [k, rot])];
    const respostas = `<div class="card" style="margin-top:16px">
      <div class="card-head"><div class="card-title">Respostas</div>
        <select class="select" data-f="status" style="width:200px">${statusOpcoes.map(([v, rot]) =>
          `<option value="${v}" ${v === f.status ? 'selected' : ''}>${rot}</option>`).join('')}</select></div>
      ${lista.dados.length ? `<div class="table-wrap"><table class="table">
        <thead><tr><th>Quando</th><th>Quem ligou</th><th>Fila</th><th>Atendente</th><th>Situação</th><th class="num">Nota</th><th>Avaliação</th></tr></thead>
        <tbody>${lista.dados.map(x => `<tr>
          <td class="mono small">${dataHora(x.criado_em)}</td>
          <td class="mono">${esc(x.origem || '—')}${x.origem ? ` <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ligar de volta" data-ligar="${esc(x.origem)}" aria-label="Ligar">${icon('phone','ico ico-sm')}</button>` : ''}</td>
          <td class="small">${x.fila ? esc(x.fila) : '<span class="muted">—</span>'}</td>
          <td class="small">${esc(x.atendente || x.ramal || '—')}</td>
          <td><span class="badge ${(PESQ_STATUS[x.status] || [])[1] || ''}">${(PESQ_STATUS[x.status] || [x.status])[0]}</span></td>
          <td class="num">${x.nota === null ? '—' : `<b>${x.nota}</b>${x.nota_min !== null && (x.nota_min !== p.nota_min || x.nota_max !== p.nota_max || x.sentido !== p.sentido) ? ` <span class="tiny muted" data-tip="Respondida na escala ${esc(escalaPesquisa(x))}">*</span>` : ''}`}</td>
          <td>${x.avaliacao ? `<span class="badge ${PESQ_AVALIACAO[x.avaliacao][1]}">${PESQ_AVALIACAO[x.avaliacao][0]}</span>` : '<span class="muted">—</span>'}</td>
        </tr>`).join('')}</tbody></table></div>
        <div class="card-foot pager"><span class="small muted">${num(lista.total)} resposta(s) · página ${lista.pagina} de ${Math.max(1, lista.paginas)}</span>
          <div class="pages">
            <button class="btn btn-ghost btn-sm" data-pagina-pesq="${lista.pagina - 1}" ${lista.pagina <= 1 ? 'disabled' : ''}>${icon('chevronL','ico ico-sm')}</button>
            <button class="btn btn-ghost btn-sm" data-pagina-pesq="${lista.pagina + 1}" ${lista.pagina >= lista.paginas ? 'disabled' : ''}>${icon('chevronR','ico ico-sm')}</button>
          </div></div>`
        : `<div class="card-body"><p class="small muted" style="margin:0">Nenhuma resposta com estes filtros.</p></div>`}
    </div>`;

    return filtrosHtml + kpis + `
      <div class="grid g-2-1">
        <div class="card"><div class="card-head"><div class="card-title">Notas dadas</div>
            <span class="tiny muted">${esc(escalaPesquisa(p))}</span></div>
          <div class="card-body">${composicao}<div style="margin-top:16px;display:grid;gap:6px">${distribuicao}</div></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Não completaram</div></div>
          <div class="card-body">${naoCompletaram}</div></div>
      </div>
      <div class="grid g-2" style="margin-top:16px">
        <div class="card"><div class="card-head"><div class="card-title">Por fila</div></div><div class="card-body">${tabelaFilas}</div></div>
        <div class="card"><div class="card-head"><div class="card-title">Por atendente</div></div><div class="card-body">${tabelaAtendentes}</div></div>
      </div>
      ${tabelaDias}${respostas}`;
  },

  // -------------------------------------------------------------- Montagem
  mount(ctx) {
    document.querySelectorAll('[data-abas-pesquisa] [data-aba]').forEach(b => b.onclick = () => {
      this._aba = b.dataset.aba;
      App.route();
    });
    document.querySelectorAll('[data-nova-pesquisa]').forEach(b => b.onclick = () => this.formulario(null));
    document.querySelectorAll('[data-editar-pesquisa]').forEach(b => b.onclick = () =>
      this.formulario(this._pesquisas.find(p => String(p.id) === b.dataset.editarPesquisa)));
    document.querySelectorAll('[data-resultados-de]').forEach(b => b.onclick = () => {
      this._f = { ...this._f, pesquisa: b.dataset.resultadosDe, pagina: 1, ramal: '' };
      this._aba = 'resultados';
      App.route();
    });
    document.querySelectorAll('[data-excluir-pesquisa]').forEach(b => b.onclick = async () => {
      const p = this._pesquisas.find(x => String(x.id) === b.dataset.excluirPesquisa);
      if (!await Modal.confirm({ titulo: `Excluir a pesquisa ${p.nome}?`,
        texto: 'As respostas já dadas continuam no relatório, sem o nome da pesquisa. Para só parar de perguntar, desative-a.',
        ok: 'Excluir' })) return;
      try { await Api.delete(`/pesquisas/${p.id}`); toast('Pesquisa excluída. Aplique as configurações.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });

    // Filtros: qualquer mudança volta à primeira página.
    document.querySelectorAll('[data-f]').forEach(el => el.onchange = () => {
      this._f[el.dataset.f] = el.value;
      if (el.dataset.f === 'pesquisa') this._f.ramal = '';
      this._f.pagina = 1;
      App.route();
    });
    document.querySelectorAll('[data-pagina-pesq]').forEach(b => b.onclick = () => {
      this._f.pagina = Number(b.dataset.paginaPesq);
      App.route();
    });
    document.querySelectorAll('[data-ligar]').forEach(b => b.onclick = ev => {
      ev.stopPropagation();
      Softphone.discarPara(b.dataset.ligar);
    });
    document.querySelector('[data-exportar-pesquisa]')?.addEventListener('click', async ev => {
      const b = ev.currentTarget;
      const f = this._f;
      const q = new URLSearchParams({ pesquisa: f.pesquisa, de: f.de, ate: f.ate, fila: f.fila, ramal: f.ramal, status: f.status });
      b.disabled = true;
      try { await Api.salvarArquivo(`/pesquisas/respostas/csv?${q}`); } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
    });
  },

  // ------------------------------------------------------------ Formulário
  formulario(item) {
    const novo = !item;
    const p = item || { nota_min: 1, nota_max: 5, sentido: 'maior_melhor', tentativas: 2, segundos: 8, ativo: 1 };
    const anuncios = rotulo => [{ valor: '', rotulo },
      ...this._anuncios.filter(a => Number(a.ativo)).map(a => ({ valor: a.id, rotulo: a.nome }))];
    const faixa = (de, ate) => Array.from({ length: ate - de + 1 }, (_, i) => ({ valor: de + i, rotulo: String(de + i) }));

    const campos = [
      { campo: 'nome', label: 'Nome da pesquisa', obrigatorio: true, largura: 'full', placeholder: 'Satisfação com o atendimento' },
      { campo: 'descricao', label: 'Descrição', largura: 'full', placeholder: 'Só para você reconhecer' },
      { campo: 'numero', label: 'Número para transferir', mono: true, placeholder: '7100',
        padraoValido: /^([0-9]{2,10})?$/, mensagemPadrao: 'só dígitos, de 2 a 10',
        ajuda: 'O atendente transfere o cliente para este número no fim da conversa. Em branco, a pesquisa é feita só pela fila.' },
      { campo: 'ativo', label: 'Pesquisa ativa', tipo: 'switch', padrao: 1 },
      { tipo: 'nota', largura: 'full', texto: 'Mensagens: cada uma é um anúncio (Aplicações › Anúncios). Grave, envie o áudio, crie o anúncio e escolha aqui.' },
      { campo: 'anuncio_saudacao_id', label: 'Mensagem de saudação', tipo: 'select', largura: 'full',
        opcoes: anuncios('— sem saudação: vai direto à pergunta —'),
        ajuda: 'Ex.: "Obrigado por falar com a Telium. Antes de sair, avalie o nosso atendimento."' },
      { campo: 'anuncio_pergunta_id', label: 'Mensagem das notas', tipo: 'select', largura: 'full',
        opcoes: anuncios('— sem mensagem: o cliente só ouve um bipe —'),
        ajuda: 'Diga a escala: ex.: "Digite de 1 a 5, sendo 5 muito satisfeito". É depois dela que o cliente digita.' },
      { campo: 'anuncio_obrigado_id', label: 'Mensagem de agradecimento', tipo: 'select', largura: 'full',
        opcoes: anuncios('— agradecimento padrão do Asterisk —') },
      { campo: 'anuncio_invalida_id', label: 'Mensagem de tecla inválida', tipo: 'select', largura: 'full',
        opcoes: anuncios('— aviso padrão do Asterisk —'),
        ajuda: 'Toca quando o cliente digita fora da escala, antes de perguntar de novo.' },
      { tipo: 'nota', largura: 'full', texto: 'Escala: de 0 a 10. O cliente digita um dígito; para o 10, digita 1 e 0.' },
      { campo: 'nota_min', label: 'Nota mínima', tipo: 'select', opcoes: faixa(0, 9), padrao: 1 },
      { campo: 'nota_max', label: 'Nota máxima', tipo: 'select', opcoes: faixa(1, 10), padrao: 5 },
      { campo: 'sentido', label: 'Qual é a melhor nota?', tipo: 'select', largura: 'full', padrao: 'maior_melhor',
        opcoes: [{ valor: 'maior_melhor', rotulo: 'A maior nota é a melhor (ex.: 10 = muito satisfeito)' },
                 { valor: 'menor_melhor', rotulo: 'A menor nota é a melhor (ex.: 10 = muito insatisfeito)' }] },
      { campo: 'tentativas', label: 'Tentativas', tipo: 'select', opcoes: faixa(1, 5), padrao: 2,
        ajuda: 'Quantas vezes a pergunta se repete se o cliente não digita ou erra.' },
      { campo: 'segundos', label: 'Segundos para digitar', tipo: 'number', padrao: 8,
        padraoValido: /^([3-9]|[12][0-9]|30)$/, mensagemPadrao: 'de 3 a 30' }
    ];

    Drawer.open({
      titulo: novo ? 'Nova pesquisa' : `Editar ${p.nome}`,
      sub: 'Ligue a pesquisa a uma fila (aba "Pesquisa" da fila) para o cliente cair nela quando o atendente desligar.',
      wide: true,
      corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, p)).join('')}
        <div class="field full"><label class="label">Como cada nota conta</label>
          <div class="pesq-previa" data-previa-escala></div>
          <span class="hint">Verde conta como satisfeito, vermelho como insatisfeito. É o que o relatório usa.</span></div>
        <div class="field full"><p class="nota-form">Depois de salvar, aplique as configurações para o Asterisk passar a fazer a pesquisa.</p></div>
      </div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" data-ok>${novo ? 'Criar pesquisa' : 'Salvar'}</button>`,
      aoAbrir: dw => {
        // A prévia acompanha a escala enquanto ela é escolhida.
        const previa = () => {
          const escala = {
            nota_min: Number(dw.querySelector('[name="nota_min"]').value),
            nota_max: Number(dw.querySelector('[name="nota_max"]').value),
            sentido: dw.querySelector('[name="sentido"]').value
          };
          const alvo = dw.querySelector('[data-previa-escala]');
          if (escala.nota_min >= escala.nota_max) {
            alvo.innerHTML = '<span class="small" style="color:var(--danger)">A nota mínima precisa ser menor que a máxima.</span>';
            return;
          }
          alvo.innerHTML = faixa(escala.nota_min, escala.nota_max).map(({ valor }) => {
            const i = indiceDaNota(escala, valor);
            return `<span class="pesq-chip" style="border-color:${corDoIndice(i)};color:${corDoIndice(i)}" data-tip="índice ${i}">${valor}</span>`;
          }).join('');
        };
        ['nota_min', 'nota_max', 'sentido'].forEach(n => dw.querySelector(`[name="${n}"]`).addEventListener('change', previa));
        previa();

        dw.querySelector('[data-ok]').onclick = async ev => {
          if (!validarCampos(dw, campos.filter(c => c.campo))) return;
          const dados = {};
          campos.filter(c => c.campo).forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
          });
          // Anúncio "nenhum" e número em branco vão como nulo, não como texto vazio.
          ['anuncio_saudacao_id', 'anuncio_pergunta_id', 'anuncio_obrigado_id', 'anuncio_invalida_id', 'numero']
            .forEach(c => { if (dados[c] === '') dados[c] = null; });
          if (Number(dados.nota_min) >= Number(dados.nota_max)) {
            marcarErro(dw, 'nota_max', 'precisa ser maior que a mínima');
            return;
          }
          const botao = ev.currentTarget;
          botao.disabled = true;
          try {
            if (novo) await Api.post('/pesquisas', dados);
            else await Api.put(`/pesquisas/${p.id}`, dados);
            Drawer.close();
            toast('Pesquisa salva. Aplique as configurações para valer no Asterisk.', 'ok');
            this._aba = 'pesquisas';
            App.route();
          } catch (e) {
            botao.disabled = false;
            if (!(e.detalhe?.campo && marcarErro(dw, e.detalhe.campo, e.message))) toast(e.message, 'err');
          }
        };
      }
    });
  }
};
