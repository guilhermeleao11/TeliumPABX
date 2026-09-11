/* =========================================================
   Telium PABX — telas (parte 2)
   Rotas, URA, relatórios, tarifação, provisionamento e PCU.
   ========================================================= */

/* Opções de destino usadas por rotas e URA — carregadas do banco. */
async function opcoesDestino() {
  const [ramais, filas, uras] = await Promise.all([
    Api.get('/ramais', { limite: 500 }).catch(() => ({ dados: [] })),
    Api.get('/filas', { limite: 200 }).catch(() => ({ dados: [] })),
    Api.get('/ura', { limite: 100 }).catch(() => ({ dados: [] }))
  ]);
  return { ramais: ramais.dados, filas: filas.dados, uras: uras.dados };
}

function seletorDestino(prefixo, item, destinos) {
  const tipos = [
    { valor: 'ramal', rotulo: 'Ramal' }, { valor: 'fila', rotulo: 'Fila' },
    { valor: 'ura', rotulo: 'URA' }, { valor: 'voicemail', rotulo: 'Correio de voz' },
    { valor: 'anuncio', rotulo: 'Anúncio' }, { valor: 'desligar', rotulo: 'Desligar' }
  ];
  const valores = [
    ...destinos.ramais.map(r => ({ valor: r.numero, rotulo: `Ramal ${r.numero} — ${r.nome}`, tipo: 'ramal' })),
    ...destinos.filas.map(f => ({ valor: f.numero, rotulo: `Fila ${f.numero} — ${f.nome}`, tipo: 'fila' })),
    ...destinos.uras.map(u => ({ valor: String(u.id), rotulo: `URA — ${u.nome}`, tipo: 'ura' }))
  ];
  return [
    { campo: `${prefixo}_tipo`, label: 'Tipo de destino', tipo: 'select', opcoes: tipos },
    { campo: `${prefixo}_valor`, label: 'Destino', tipo: 'select',
      opcoes: valores.length ? valores : [{ valor: '', rotulo: 'cadastre ramais ou filas antes' }] }
  ];
}

/* ------------------------- Rotas de entrada ------------------------- */
PAGES['conn.rotasentrada'] = paginaCrud({
  recurso: 'rotas-entrada',
  titulo: 'Rotas de Entrada',
  sub: 'Para onde vai cada número recebido das operadoras.',
  ico: 'arrowDown',
  plural: 'rotas',
  rotuloNovo: 'Nova rota',
  tituloNovo: 'Nova rota de entrada',
  vazioTitulo: 'Nenhuma rota de entrada',
  vazioTexto: 'Sem rota, as chamadas que chegam pelos troncos não têm destino definido.',
  placeholderBusca: 'Buscar por DID ou descrição…',
  textoBusca: r => `${r.did} ${r.descricao || ''}`,
  tituloEditar: r => `Rota ${r.did}`,
  tituloExcluir: r => `Excluir a rota ${r.did}?`,

  colunas: [
    { label: 'Ordem', render: r => `<b class="num">${r.ordem}</b>` },
    { label: 'DID', render: r => `<b class="mono">${esc(r.did)}</b>` },
    { label: 'Descrição', render: r => `<span class="dim">${esc(r.descricao || '—')}</span>` },
    { label: 'Destino', render: r => `<span class="ura-dest">${icon('branch','ico ico-sm')}${esc(r.destino_tipo)} ${esc(r.destino_valor)}</span>` },
    { label: 'Gravar', render: r => Number(r.gravar) ? '<span class="badge badge-brand">Sim</span>' : '<span class="muted">—</span>' }
  ],

  aoCarregar: async (pagina) => { pagina._destinos = await opcoesDestino(); },

  campos: (r, ctx, pagina) => [
    { campo: 'did', label: 'DID / Número', obrigatorio: true, mono: true, placeholder: '1133255800',
      ajuda: 'Aceita padrão do dialplan, por exemplo _X. para qualquer número.' },
    { campo: 'descricao', label: 'Descrição', placeholder: 'Comercial 0800' },
    ...seletorDestino('destino', r, pagina._destinos || { ramais: [], filas: [], uras: [] }),
    { campo: 'ordem', label: 'Ordem de avaliação', tipo: 'number', padrao: 10 },
    { campo: 'gravar', label: 'Gravar chamadas desta rota', tipo: 'switch' },
    { campo: 'ativo', label: 'Rota ativa', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Rotas de saída ------------------------- */
PAGES['conn.rotassaida'] = paginaCrud({
  recurso: 'rotas-saida',
  titulo: 'Rotas de Saída',
  sub: 'Precedência, padrões de discagem e tronco utilizado.',
  ico: 'arrowUp',
  plural: 'rotas',
  rotuloNovo: 'Nova rota',
  tituloNovo: 'Nova rota de saída',
  vazioTitulo: 'Nenhuma rota de saída',
  vazioTexto: 'Sem rota de saída os ramais só fazem chamadas internas.',
  placeholderBusca: 'Buscar por nome ou padrão…',
  textoBusca: r => `${r.nome} ${r.padrao}`,
  tituloEditar: r => `Rota ${r.nome}`,
  tituloExcluir: r => `Excluir a rota ${r.nome}?`,

  colunas: [
    { label: 'Ordem', render: r => `<span class="grab">${icon('list','ico ico-sm')}</span> <b class="num">${r.ordem}</b>` },
    { label: 'Rota', render: r => `<b>${esc(r.nome)}</b>` },
    { label: 'Padrão', render: r => `<span class="badge mono">${esc(r.padrao)}</span>` },
    { label: 'Classe', render: r => `<span class="badge">${esc(r.classe)}</span>` },
    { label: 'Remove prefixo', render: r => `<span class="mono dim">${esc(r.prefixo_remover || '—')}</span>` }
  ],

  aoCarregar: async (pagina) => {
    pagina._troncos = (await Api.get('/troncos', { limite: 200 }).catch(() => ({ dados: [] }))).dados;
  },

  campos: (r, ctx, pagina) => {
    const troncos = (pagina._troncos || []).map(t => ({ valor: t.id, rotulo: t.nome }));
    return [
      { campo: 'nome', label: 'Nome da rota', obrigatorio: true, placeholder: 'Celular' },
      { campo: 'ordem', label: 'Ordem de precedência', tipo: 'number', padrao: 10,
        ajuda: 'A primeira rota cujo padrão casar é a usada.' },
      { campo: 'padrao', label: 'Padrão de discagem', obrigatorio: true, mono: true, placeholder: '_09XXXXXXXX',
        ajuda: 'X = 0-9 · Z = 1-9 · N = 2-9 · [1-5] = intervalo · . = um ou mais dígitos' },
      { campo: 'classe', label: 'Classe', tipo: 'select',
        opcoes: ['local','celular','ddd','ddi','emergencia','especial'], padrao: 'local',
        ajuda: 'Usada para checar a permissão de discagem do ramal.' },
      { campo: 'tronco_id', label: 'Tronco', tipo: 'select',
        opcoes: troncos.length ? troncos : [{ valor: '', rotulo: 'cadastre um tronco antes' }] },
      { campo: 'tronco_falha_id', label: 'Tronco reserva', tipo: 'select',
        opcoes: [{ valor: '', rotulo: 'nenhum' }, ...troncos] },
      { campo: 'prefixo_remover', label: 'Prefixo a remover', mono: true, placeholder: '0' },
      { campo: 'prefixo_adicionar', label: 'Prefixo a adicionar', mono: true },
      { campo: 'ativo', label: 'Rota ativa', tipo: 'switch', padrao: 1 }
    ];
  }
});

/* ------------------------- URA ------------------------- */
PAGES['apps.ura'] = {
  async render(ctx) {
    let uras, destinos;
    try {
      uras = (await Api.get('/ura')).dados;
      destinos = await opcoesDestino();
    } catch (e) { return pageHead('URA — Atendimento Digital', '') + blocoErro(e); }

    this._uras = uras;
    this._destinos = destinos;

    const cabecalho = pageHead('URA — Atendimento Digital',
      'Árvore de opções que o cliente ouve ao ligar.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm" data-nova-ura>${icon('plus','ico ico-sm')} Nova URA</button>` : readOnlyNote(ctx));

    if (!uras.length) {
      return cabecalho + `<div class="card">${vazio('branch', 'Nenhuma URA cadastrada',
        'A URA atende a chamada e oferece um menu de opções ao cliente.',
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-nova-ura>Criar a primeira URA</button>' : '')}</div>`;
    }

    const opcoesPorUra = {};
    await Promise.all(uras.map(async u => {
      opcoesPorUra[u.id] = (await Api.get('/ura-opcoes', { ura_id: u.id }).catch(() => ({ dados: [] }))).dados;
    }));
    this._opcoes = opcoesPorUra;

    return cabecalho + uras.map(u => `
      <div class="card" style="margin-bottom:16px">
        <div class="card-head">
          <div><div class="card-title">${esc(u.nome)}</div>
               <div class="card-sub">Áudio ${esc(u.audio)} · espera ${u.timeout_digito}s · ${u.tentativas} tentativas</div></div>
          <div class="row gap-6">
            ${ctx.can('criar') ? `<button class="btn btn-outline btn-sm" data-nova-opcao="${u.id}">${icon('plus','ico ico-sm')} Opção</button>` : ''}
            ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar URA" data-editar-ura="${u.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          </div>
        </div>
        <div class="card-body">
          <div class="ura-root">${icon('speaker','ico ico-lg')}
            <div class="grow"><b>${esc(u.nome)}</b><small>Toca ${esc(u.audio)} e aguarda o dígito</small></div>
          </div>
          ${(opcoesPorUra[u.id] || []).length ? `<div class="ura-tree">
            ${opcoesPorUra[u.id].map(o => `
              <div class="ura-node">
                <span class="ura-key">${esc(o.tecla)}</span>
                <div class="grow"><b>${esc(o.rotulo)}</b><div class="tiny muted">tecla ${esc(o.tecla)}</div></div>
                ${icon('chevronR','ico arrow')}
                <span class="ura-dest">${icon('branch','ico ico-sm')}${esc(o.destino_tipo)} ${esc(o.destino_valor)}</span>
                ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Remover" data-excluir-opcao="${o.id}">${icon('trash','ico ico-sm')}</button>` : ''}
              </div>`).join('')}
          </div>` : `<p class="small muted center" style="padding:20px">
              Nenhuma opção configurada. A URA vai direto para o destino de tempo esgotado.</p>`}
        </div>
      </div>`).join('');
  },

  mount(ctx) {
    const camposUra = (u = {}) => [
      { campo: 'nome', label: 'Nome da URA', obrigatorio: true, placeholder: 'URA Principal' },
      { campo: 'audio', label: 'Áudio de saudação', obrigatorio: true, mono: true, placeholder: 'ura-principal',
        ajuda: 'Nome do arquivo em /var/lib/asterisk/sounds, sem extensão.' },
      { campo: 'timeout_digito', label: 'Espera por dígito (s)', tipo: 'number', padrao: 8 },
      { campo: 'tentativas', label: 'Tentativas', tipo: 'number', padrao: 3 },
      { campo: 'discagem_direta', label: 'Permitir discagem direta de ramal', tipo: 'switch', padrao: 1 },
      ...seletorDestino('destino_timeout', u, this._destinos),
      { campo: 'ativo', label: 'URA ativa', tipo: 'switch', padrao: 1 }
    ];

    const salvar = async (caminho, dados, metodo = 'post') => {
      try {
        await Api[metodo](caminho, dados);
        Drawer.close(); toast('URA atualizada.', 'ok'); App.route();
      } catch (e) { toast(e.message, 'err'); }
    };

    const formUra = (u) => {
      const campos = camposUra(u || {});
      Drawer.open({
        titulo: u ? `Editar ${u.nome}` : 'Nova URA',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, u || {})).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${u ? 'Salvar' : 'Criar URA'}</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = () => {
          const dados = {};
          campos.forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
          });
          salvar(u ? `/ura/${u.id}` : '/ura', dados, u ? 'put' : 'post');
        }
      });
    };

    const formOpcao = (uraId) => {
      const campos = [
        { campo: 'tecla', label: 'Tecla', obrigatorio: true, mono: true, placeholder: '1' },
        { campo: 'rotulo', label: 'Rótulo', obrigatorio: true, placeholder: 'Suporte Técnico' },
        ...seletorDestino('destino', {}, this._destinos),
        { campo: 'ordem', label: 'Ordem', tipo: 'number', padrao: 10 }
      ];
      Drawer.open({
        titulo: 'Nova opção da URA',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, {})).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Adicionar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = () => {
          const dados = { ura_id: uraId };
          campos.forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.value;
          });
          salvar('/ura-opcoes', dados);
        }
      });
    };

    document.querySelectorAll('[data-nova-ura]').forEach(b => b.onclick = () => formUra(null));
    document.querySelectorAll('[data-editar-ura]').forEach(b =>
      b.onclick = () => formUra(this._uras.find(u => String(u.id) === b.dataset.editarUra)));
    document.querySelectorAll('[data-nova-opcao]').forEach(b =>
      b.onclick = () => formOpcao(Number(b.dataset.novaOpcao)));
    document.querySelectorAll('[data-excluir-opcao]').forEach(b => b.onclick = async () => {
      const ok = await Modal.confirm({ titulo: 'Remover esta opção?', texto: 'A tecla deixa de existir na URA.', ok: 'Remover' });
      if (!ok) return;
      try { await Api.delete(`/ura-opcoes/${b.dataset.excluirOpcao}`); toast('Opção removida.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Grupos de toque ------------------------- */
PAGES['apps.grupostoque'] = paginaCrud({
  recurso: 'grupos-toque',
  titulo: 'Grupos de Toque',
  sub: 'Um número que toca em vários ramais ao mesmo tempo.',
  ico: 'users',
  plural: 'grupos',
  rotuloNovo: 'Novo grupo',
  tituloNovo: 'Novo grupo de toque',
  vazioTitulo: 'Nenhum grupo de toque',
  vazioTexto: 'Grupos fazem um número tocar em vários ramais de uma vez.',
  placeholderBusca: 'Buscar…',
  textoBusca: g => `${g.numero} ${g.nome}`,
  tituloEditar: g => `Grupo ${g.numero}`,
  colunas: [
    { label: 'Número', render: g => `<b class="mono">${esc(g.numero)}</b>` },
    { label: 'Nome', render: g => esc(g.nome) },
    { label: 'Ramais', render: g => `<span class="mono small">${esc(g.ramais)}</span>` },
    { label: 'Toque', render: g => `<span class="num">${g.tempo_toque}s</span>` }
  ],
  campos: () => [
    { campo: 'numero', label: 'Número do grupo', obrigatorio: true, mono: true, placeholder: '700' },
    { campo: 'nome', label: 'Nome', obrigatorio: true },
    { campo: 'ramais', label: 'Ramais', obrigatorio: true, mono: true, placeholder: '1000-1001-1002',
      ajuda: 'Separe os ramais por hífen.', largura: 'full' },
    { campo: 'tempo_toque', label: 'Tempo de toque (s)', tipo: 'number', padrao: 20 },
    { campo: 'ativo', label: 'Grupo ativo', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Relatórios · Gravações ------------------------- */
PAGES['rel.gravacoes'] = {
  async render(ctx) {
    let r;
    try { r = await Api.get('/gravacoes'); }
    catch (e) { return pageHead('Gravações', '') + blocoErro(e); }

    const cabecalho = pageHead('Gravações',
      'Busque e ouça gravações. Cada acesso fica registrado na auditoria.',
      ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar seleção</button>` : '');

    if (!r.dados.length) {
      return cabecalho + `<div class="card">${vazio('mic', 'Nenhuma gravação disponível',
        'As gravações aparecem aqui conforme as chamadas forem gravadas. Ative a gravação no ramal ou na fila.')}</div>`;
    }

    return cabecalho + `<div class="card"><div class="card-body grid" style="gap:12px">
      ${r.dados.map(g => `
        <div class="card" style="padding:14px">
          <div class="row-between" style="margin-bottom:10px">
            <div class="row gap-10"><span class="avatar avatar-sm">${initials(g.agente || g.ramal)}</span>
              <div><b>${esc(g.origem)} → ${esc(g.destino)}</b>
                <div class="tiny muted">${dataHora(g.inicio)} · ${esc(g.agente || '—')} · ${duracao(g.duracao)}</div></div></div>
            ${g.fila ? `<span class="badge badge-info">Fila ${esc(g.fila)}</span>` : ''}
          </div>
          ${playerHTML(g.id, duracao(g.duracao))}
        </div>`).join('')}
    </div></div>`;
  }
};

/* ------------------------- Relatórios · Filas ------------------------- */
PAGES['rel.filas'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/relatorios/filas', { dias: this._dias || 7 }); }
    catch (e) { return pageHead('Desempenho de Filas', '') + blocoErro(e); }
    this._d = d;

    const cabecalho = pageHead('Desempenho de Filas',
      `Nível de serviço e abandono nos últimos ${d.dias} dias.`,
      `<div class="segmented" id="periodo">
         ${[7, 30, 90].map(n => `<button class="${d.dias === n ? 'on' : ''}" data-dias="${n}">${n} dias</button>`).join('')}
       </div>`);

    if (!d.filas.length) {
      return cabecalho + `<div class="card">${vazio('headset', 'Nenhuma fila cadastrada',
        'Cadastre filas para acompanhar o nível de serviço.',
        '<a class="btn btn-primary btn-sm" href="#/apps.filas">Ir para filas</a>')}</div>`;
    }

    const legenda = (d.serie.series || []).map(s =>
      `<span class="li"><i class="sw" style="background:${s.color}"></i>${s.label}</span>`).join('');

    return cabecalho + `
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><div><div class="card-title">Atendidas e não atendidas por dia</div>
        <div class="card-sub">Somente chamadas que passaram por fila</div></div>
        <div class="legend">${legenda}</div></div>
      <div class="card-body"><div class="chart-wrap" id="chartSla"></div></div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">Por fila</div></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Fila</th><th>Agentes</th><th>Recebidas</th><th>Atendidas</th>
                   <th>Abandonadas</th><th>TMA</th><th>Atendimento</th></tr></thead>
        <tbody>${d.filas.map(f => `
          <tr><td><b class="mono">${esc(f.numero)}</b> ${esc(f.nome)}</td>
            <td class="num">${f.agentes}</td>
            <td class="num">${num(f.recebidas)}</td>
            <td class="num">${num(f.atendidas)}</td>
            <td class="num">${num(f.abandonadas)}</td>
            <td class="num">${f.tma ? duracao(f.tma) : '—'}</td>
            <td>${f.sla === null ? '<span class="muted">sem chamadas</span>'
                 : `<span class="badge badge-${f.sla >= 90 ? 'ok' : f.sla >= 75 ? 'warn' : 'danger'}">${f.sla}%</span>`}</td>
          </tr>`).join('')}
        </tbody></table></div>
    </div>`;
  },
  mount() {
    const el = document.getElementById('chartSla');
    if (el && this._d) stackedBarChart(el, { ...this._d.serie, aria: 'Chamadas por dia' });
    document.querySelectorAll('#periodo [data-dias]').forEach(b => b.onclick = () => {
      this._dias = Number(b.dataset.dias); App.route();
    });
  }
};

/* ------------------------- Relatórios · Agentes ------------------------- */
PAGES['rel.agentes'] = {
  async render() {
    let d;
    try { d = await Api.get('/relatorios/agentes', { dias: 7 }); }
    catch (e) { return pageHead('Produtividade de Agentes', '') + blocoErro(e); }

    const cabecalho = pageHead('Produtividade de Agentes',
      `Volume e tempo médio por ramal nos últimos ${d.dias} dias.`);

    if (!d.dados.length) {
      return cabecalho + `<div class="card">${vazio('users', 'Nenhum ramal cadastrado',
        'Cadastre ramais para acompanhar a produtividade.',
        '<a class="btn btn-primary btn-sm" href="#/conn.ramais">Ir para ramais</a>')}</div>`;
    }

    return cabecalho + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Ranking</div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Agente</th><th>Ramal</th><th>Atendidas</th><th>TMA</th></tr></thead>
          <tbody>${d.dados.map(a => `
            <tr><td><span class="row gap-8"><span class="avatar avatar-sm">${initials(a.nome)}</span>${esc(a.nome)}</span></td>
              <td class="mono">${esc(a.ramal)}</td>
              <td class="num">${num(a.atendidas)}</td>
              <td class="num">${a.tma ? duracao(Math.round(a.tma)) : '—'}</td></tr>`).join('')}
          </tbody></table></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Chamadas atendidas</div></div>
        <div class="card-body">${hbars(d.dados.map(a => ({ label: a.nome, valor: Number(a.atendidas) })))}</div>
      </div>
    </div>`;
  }
};

/* ------------------------- Telium · Tarifação ------------------------- */
PAGES['telium.tarifacao'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/relatorios/tarifacao', { mes: this._mes || '' }); }
    catch (e) { return pageHead('Tarifação e Custos', '') + blocoErro(e); }

    const cabecalho = pageHead('Tarifação e Custos',
      `Consumo de chamadas saintes em ${d.mes}.`,
      `<input class="input" type="month" id="mesTarifa" value="${d.mes}" style="width:170px">`);

    const semCusto = !Number(d.totais?.chamadas);

    return cabecalho + `
    <div class="grid g-4" style="margin-bottom:16px">
      ${[['Custo total', semCusto ? '—' : moeda(d.totais.custo), 'creditCard', 'brand'],
         ['Minutos falados', semCusto ? '—' : num(d.totais.minutos), 'clock', 'info'],
         ['Chamadas', semCusto ? '0' : num(d.totais.chamadas), 'phone', 'ok'],
         ['Custo médio / min', semCusto || !Number(d.totais.minutos) ? '—'
            : moeda(d.totais.custo / d.totais.minutos), 'target', 'warn']].map(([l, v, i, t]) => `
        <div class="card kpi"><div class="k-top"><span class="k-label">${l}</span>
          <span class="k-ico" style="background:var(--${t}-soft);color:var(--${t})">${icon(i)}</span></div>
          <div class="k-val">${v}</div><div class="k-foot"><span>${d.mes}</span></div></div>`).join('')}
    </div>

    ${semCusto ? `<div class="card">${vazio('creditCard', 'Sem chamadas tarifadas no mês',
        'O custo é calculado sobre as chamadas saintes registradas no CDR, usando a tabela de tarifas.')}</div>`
      : `<div class="card" style="margin-bottom:16px">
        <div class="card-head"><div class="card-title">Custo por setor</div></div>
        <div class="card-body">${hbars(d.porSetor.map(s => ({ label: s.setor, valor: Number(s.custo) })), moeda)}</div>
      </div>`}

    <div class="card">
      <div class="card-head"><div><div class="card-title">Tabela de tarifas</div>
        <div class="card-sub">Custo por minuto aplicado a cada padrão de destino</div></div>
        <a class="btn btn-outline btn-sm" href="#/telium.tarifacao">${icon('edit','ico ico-sm')} Gerenciar</a></div>
      ${d.tarifas.length ? `<div class="table-wrap"><table class="table">
        <thead><tr><th>Nome</th><th>Padrão</th><th>Custo/min</th><th>Taxa fixa</th><th>Incremento</th></tr></thead>
        <tbody>${d.tarifas.map(t => `
          <tr><td><b>${esc(t.nome)}</b></td><td><span class="badge mono">${esc(t.padrao)}</span></td>
            <td class="num">${moeda(t.custo_minuto)}</td><td class="num">${moeda(t.taxa_fixa)}</td>
            <td class="num">${t.incremento_seg}s</td></tr>`).join('')}
        </tbody></table></div>`
        : vazio('creditCard', 'Nenhuma tarifa cadastrada',
                'Sem tarifas o custo das chamadas não é calculado.')}
    </div>`;
  },
  mount() {
    document.getElementById('mesTarifa')?.addEventListener('change', e => {
      this._mes = e.target.value; App.route();
    });
  }
};

/* ------------------------- Provisionamento ------------------------- */
PAGES['conn.provisionamento'] = paginaCrud({
  recurso: 'dispositivos',
  titulo: 'Provisionamento de Telefones',
  sub: 'Configuração automática de aparelhos por MAC address.',
  ico: 'phone',
  plural: 'aparelhos',
  rotuloNovo: 'Adicionar aparelho',
  tituloNovo: 'Adicionar aparelho',
  vazioTitulo: 'Nenhum aparelho cadastrado',
  vazioTexto: 'Cadastre o MAC do telefone para ele receber a configuração automaticamente.',
  placeholderBusca: 'Buscar por MAC, modelo ou IP…',
  textoBusca: d => `${d.mac} ${d.modelo || ''} ${d.ip || ''}`,
  tituloEditar: d => `Aparelho ${d.mac}`,
  colunas: [
    { label: 'MAC', render: d => `<span class="mono">${esc(d.mac)}</span>` },
    { label: 'Modelo', render: d => `<b>${esc(d.modelo || '—')}</b>` },
    { label: 'Ramal', render: d => `<span class="mono">${esc(d.ramal_id || '—')}</span>` },
    { label: 'Firmware', render: d => `<span class="mono small dim">${esc(d.firmware || '—')}</span>` },
    { label: 'IP', render: d => `<span class="mono small dim">${esc(d.ip || '—')}</span>` },
    { label: 'Estado', render: d => d.estado === 'provisionado'
        ? '<span class="badge badge-ok"><i class="dot"></i>Provisionado</span>'
        : d.estado === 'pendente' ? '<span class="badge badge-warn"><i class="dot"></i>Pendente</span>'
        : '<span class="badge badge-danger"><i class="dot"></i>Erro</span>' }
  ],
  aoCarregar: async (pagina) => {
    pagina._ramais = (await Api.get('/ramais', { limite: 500 }).catch(() => ({ dados: [] }))).dados;
  },
  campos: (d, ctx, pagina) => [
    { campo: 'mac', label: 'MAC address', obrigatorio: true, mono: true, placeholder: '00:11:22:33:44:55' },
    { campo: 'modelo', label: 'Modelo', placeholder: 'Yealink T31' },
    { campo: 'fabricante', label: 'Fabricante', placeholder: 'Yealink' },
    { campo: 'ramal_id', label: 'Ramal', tipo: 'select',
      opcoes: [{ valor: '', rotulo: 'nenhum' },
               ...(pagina._ramais || []).map(r => ({ valor: r.id, rotulo: `${r.numero} — ${r.nome}` }))] },
    { campo: 'estado', label: 'Estado', tipo: 'select',
      opcoes: ['pendente','provisionado','erro'], padrao: 'pendente' }
  ]
});

/* ------------------------- Integrações ------------------------- */
PAGES['telium.integracoes'] = paginaCrud({
  recurso: 'integracoes',
  titulo: 'Integrações e API',
  sub: 'Webhooks, chaves de API e conectores.',
  ico: 'layers',
  plural: 'integrações',
  rotuloNovo: 'Nova integração',
  tituloNovo: 'Nova integração',
  vazioTitulo: 'Nenhuma integração configurada',
  vazioTexto: 'Webhooks avisam outros sistemas quando uma chamada começa ou termina.',
  placeholderBusca: 'Buscar…',
  textoBusca: i => i.nome,
  tituloEditar: i => esc(i.nome),
  colunas: [
    { label: 'Integração', render: i => `<b>${esc(i.nome)}</b>` },
    { label: 'Tipo', render: i => `<span class="badge">${esc(i.tipo)}</span>` },
    { label: 'Eventos', render: i => `<span class="mono small dim">${esc(i.eventos || '—')}</span>` },
    { label: 'Último disparo', render: i => `<span class="small dim">${i.ultimo_disparo ? dataHora(i.ultimo_disparo) : 'nunca'}</span>` },
    { label: 'Estado', render: i => Number(i.ativo)
        ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>'
        : '<span class="badge"><i class="dot"></i>Inativa</span>' }
  ],
  campos: () => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Webhook do CRM' },
    { campo: 'tipo', label: 'Tipo', tipo: 'select',
      opcoes: [{valor:'webhook',rotulo:'Webhook'},{valor:'api_key',rotulo:'Chave de API'},
               {valor:'syslog',rotulo:'Syslog'},{valor:'app',rotulo:'Aplicativo'}] },
    { campo: 'url', label: 'URL de destino', mono: true, placeholder: 'https://…', largura: 'full' },
    { campo: 'eventos', label: 'Eventos', mono: true, placeholder: 'call.answered, call.ended', largura: 'full' },
    { campo: 'segredo', label: 'Segredo (HMAC)', mono: true, placeholder: 'deixe em branco para manter' },
    { campo: 'ativo', label: 'Integração ativa', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Contatos ------------------------- */
PAGES['pcu.contatos'] = paginaCrud({
  recurso: 'contatos',
  titulo: 'Contatos',
  sub: 'Agenda corporativa. Clique para ligar.',
  ico: 'book',
  plural: 'contatos',
  rotuloNovo: 'Novo contato',
  tituloNovo: 'Novo contato',
  vazioTitulo: 'Agenda vazia',
  vazioTexto: 'Cadastre os contatos que a equipe mais usa para ligar com um clique.',
  placeholderBusca: 'Buscar por nome ou número…',
  textoBusca: c => `${c.nome} ${c.numero}`,
  tituloEditar: c => esc(c.nome),
  colunas: [
    { label: 'Contato', render: c => `<span class="row gap-10"><span class="avatar avatar-sm">${initials(c.nome)}</span>
        <span><b>${esc(c.nome)}</b>${Number(c.favorito) ? ' <span class="badge badge-warn">favorito</span>' : ''}</span></span>` },
    { label: 'Número', render: c => `<span class="mono">${esc(c.numero)}</span>` },
    { label: 'Grupo', render: c => c.grupo ? `<span class="badge">${esc(c.grupo)}</span>` : '<span class="muted">—</span>' }
  ],
  acoesLinha: c => `<button class="btn btn-primary btn-sm" data-ligar="${esc(c.numero)}" data-nome="${esc(c.nome)}">
      ${icon('phone','ico ico-sm')} Ligar</button>`,
  campos: () => [
    { campo: 'nome', label: 'Nome', obrigatorio: true },
    { campo: 'numero', label: 'Número', obrigatorio: true, mono: true },
    { campo: 'grupo', label: 'Grupo', placeholder: 'Clientes' },
    { campo: 'favorito', label: 'Favorito', tipo: 'switch' }
  ],
  aoMontar: () => {
    document.querySelectorAll('[data-ligar]').forEach(b =>
      b.onclick = () => Softphone.discarPara(b.dataset.ligar, b.dataset.nome));
  }
});

/* ------------------------- Tarifas (cadastro) ------------------------- */
PAGES['cfg.tarifas'] = paginaCrud({
  recurso: 'tarifas',
  titulo: 'Tabela de Tarifas',
  sub: 'Custo por minuto aplicado a cada padrão de destino.',
  ico: 'creditCard',
  plural: 'tarifas',
  rotuloNovo: 'Nova tarifa',
  vazioTitulo: 'Nenhuma tarifa cadastrada',
  vazioTexto: 'Sem tarifas o custo das chamadas não é calculado.',
  placeholderBusca: 'Buscar…',
  textoBusca: t => `${t.nome} ${t.padrao}`,
  colunas: [
    { label: 'Nome', render: t => `<b>${esc(t.nome)}</b>` },
    { label: 'Padrão', render: t => `<span class="badge mono">${esc(t.padrao)}</span>` },
    { label: 'Custo/min', render: t => `<span class="num">${moeda(t.custo_minuto)}</span>` },
    { label: 'Taxa fixa', render: t => `<span class="num">${moeda(t.taxa_fixa)}</span>` }
  ],
  campos: () => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Celular' },
    { campo: 'padrao', label: 'Padrão de destino', obrigatorio: true, mono: true, placeholder: '_09XXXXXXXX' },
    { campo: 'custo_minuto', label: 'Custo por minuto', tipo: 'number', padrao: 0 },
    { campo: 'taxa_fixa', label: 'Taxa fixa', tipo: 'number', padrao: 0 },
    { campo: 'incremento_seg', label: 'Incremento (s)', tipo: 'number', padrao: 6 },
    { campo: 'ativo', label: 'Tarifa ativa', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Configurações · Empresa ------------------------- */
PAGES['cfg.empresa'] = {
  async render(ctx) {
    let e;
    try { e = await Api.get('/empresa'); }
    catch (err) { return pageHead('Dados da Empresa', '') + blocoErro(err); }
    this._e = e;

    const campos = [
      { campo: 'nome', label: 'Nome fantasia', obrigatorio: true },
      { campo: 'razao_social', label: 'Razão social' },
      { campo: 'cnpj', label: 'CNPJ', mono: true },
      { campo: 'telefone', label: 'Telefone principal', mono: true },
      { campo: 'endereco', label: 'Endereço', largura: 'full' },
      { campo: 'fuso', label: 'Fuso horário', tipo: 'select',
        opcoes: ['America/Sao_Paulo','America/Manaus','America/Belem','America/Cuiaba'] },
      { campo: 'idioma', label: 'Idioma', tipo: 'select', opcoes: ['pt_BR','en_US','es_ES'] },
      { campo: 'plano', label: 'Plano' },
      { campo: 'ramais_contratados', label: 'Ramais contratados', tipo: 'number' }
    ];

    const uso = e.ramais_contratados > 0
      ? Math.round(e.ramais_usados / e.ramais_contratados * 100) : 0;

    return pageHead('Dados da Empresa',
      'Identificação da central: aparece em relatórios e no portal do usuário.',
      ctx.can('editar') ? `<button class="btn btn-primary btn-sm" id="salvarEmp">${icon('check','ico ico-sm')} Salvar</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Identificação</div></div>
        <div class="card-body"><div class="form-grid">
          ${campos.map(c => campoHtml({ ...c, somenteLeitura: !ctx.can('editar') }, e)).join('')}
        </div></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Licenciamento</div></div>
        <div class="card-body grid" style="gap:14px">
          <div class="row-between"><span class="dim small">Plano</span>
            <span class="badge badge-brand">${esc(e.plano || '—')}</span></div>
          ${medidor('Ramais em uso', uso, uso > 90 ? 'danger' : 'brand',
                    `${e.ramais_usados} / ${e.ramais_contratados}`)}
          <div class="row-between"><span class="dim small">Troncos ativos</span>
            <b class="num">${e.troncos}</b></div>
        </div>
      </div>
    </div>`;
  },
  mount(ctx) {
    document.getElementById('salvarEmp')?.addEventListener('click', async ev => {
      const dados = {};
      document.querySelectorAll('.form-grid [name]').forEach(el => { dados[el.name] = el.value; });
      ev.currentTarget.disabled = true;
      try { await Api.put('/empresa', dados); toast('Dados da empresa salvos.', 'ok'); App.route(); }
      catch (e) { ev.currentTarget.disabled = false; toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Registro de atividades ------------------------- */
PAGES['rel.logs'] = {
  async render() {
    let r;
    try { r = await Api.get('/auditoria'); }
    catch (e) { return pageHead('Registro de Atividades', '') + blocoErro(e); }

    const cabecalho = pageHead('Registro de Atividades',
      'Trilha de auditoria: quem fez o quê, quando e de onde.');

    if (!r.dados.length) {
      return cabecalho + `<div class="card">${vazio('file', 'Nenhuma atividade registrada',
        'Toda alteração feita pelo console é registrada aqui.')}</div>`;
    }

    return cabecalho + `<div class="card"><div class="table-wrap"><table class="table">
      <thead><tr><th>Quando</th><th>Usuário</th><th>Ação</th><th>Módulo</th><th>Objeto</th><th>IP</th></tr></thead>
      <tbody>${r.dados.map(a => `
        <tr><td class="mono small">${dataHora(a.criado_em)}</td>
          <td>${esc(a.usuario_nome || 'sistema')}</td>
          <td><span class="badge">${esc(a.acao)}</span></td>
          <td class="mono small dim">${esc(a.modulo)}</td>
          <td class="small">${esc(a.objeto || '—')}</td>
          <td class="mono small dim">${esc(a.ip || '—')}</td></tr>`).join('')}
      </tbody></table></div></div>`;
  }
};

/* ------------------------- Administrador · CLI do Asterisk ------------------------- */
PAGES['admin.cli'] = {
  _historico: [],

  async render(ctx) {
    let d;
    try { d = await Api.get('/cli/sugestoes'); }
    catch (e) { return pageHead('CLI Asterisk', '') + blocoErro(e); }

    this._podeEscrever = d.pode_escrever;

    const chips = Object.entries(d.sugestoes).map(([cmd, desc]) =>
      `<button class="chip" data-cmd="${esc(cmd)}" title="${esc(desc)}"
               style="cursor:pointer;height:26px">${esc(cmd)}</button>`).join('');

    return pageHead('CLI Asterisk',
      'Roda comandos no console do Asterisk pelo AMI e mostra a saída aqui.',
      d.pode_escrever
        ? `<span class="badge badge-warn">${icon('alert','ico ico-sm')} Pode alterar estado</span>`
        : `<span class="badge">${icon('eye','ico ico-sm')} Só leitura</span>`) + `

    <div class="card" style="margin-bottom:16px">
      <div class="card-body">
        <div class="row gap-8" style="margin-bottom:12px">
          <div class="input-icon grow">${icon('terminal','ico ico-sm')}
            <input class="input mono" id="cliCmd" placeholder="pjsip show endpoints"
                   autocomplete="off" spellcheck="false"></div>
          <button class="btn btn-primary" id="cliRun">${icon('play','ico ico-sm')} Executar</button>
        </div>
        <div class="row wrap gap-6">${chips}</div>
        <p class="hint" style="margin-top:10px">
          Comandos de leitura (<span class="mono">show</span>, <span class="mono">list</span>)
          rodam livremente. Qualquer coisa que altere estado exige a ação
          "aplicar configurações"${d.pode_escrever ? ' — que seu perfil tem' : ', que seu perfil não tem'}.
          Sequências de escape para o shell são recusadas, e todo comando fica na auditoria.
        </p>
      </div>
    </div>

    <div id="cliSaida"></div>`;
  },

  mount(ctx) {
    const entrada = document.getElementById('cliCmd');
    const saida = document.getElementById('cliSaida');

    const executar = async () => {
      const comando = entrada.value.trim();
      if (!comando) return;

      const botao = document.getElementById('cliRun');
      botao.disabled = true;
      botao.innerHTML = `<span class="spin"></span> Executando…`;

      let bloco;
      try {
        const r = await Api.post('/cli', { comando });
        bloco = `<div class="card" style="margin-bottom:12px">
          <div class="card-head">
            <div class="card-title mono">${esc(r.comando)}</div>
            <div class="row gap-8">
              ${r.leitura ? '<span class="badge">leitura</span>'
                          : '<span class="badge badge-warn">alterou estado</span>'}
              <span class="tiny muted">${new Date().toLocaleTimeString('pt-BR')}</span>
            </div>
          </div>
          <div class="card-body"><div class="code">${esc(r.saida) || '(sem saída)'}</div></div>
        </div>`;
      } catch (e) {
        bloco = `<div class="card" style="margin-bottom:12px;border-color:var(--danger)">
          <div class="card-head"><div class="card-title mono">${esc(comando)}</div>
            <span class="badge badge-danger">recusado</span></div>
          <div class="card-body"><p class="small" style="color:var(--danger)">${esc(e.message)}</p></div>
        </div>`;
      }

      saida.insertAdjacentHTML('afterbegin', bloco);
      botao.disabled = false;
      botao.innerHTML = `${icon('play','ico ico-sm')} Executar`;
      entrada.select();
    };

    document.getElementById('cliRun').onclick = executar;
    entrada.addEventListener('keydown', e => { if (e.key === 'Enter') executar(); });
    document.querySelectorAll('[data-cmd]').forEach(b => b.onclick = () => {
      entrada.value = b.dataset.cmd;
      executar();
    });
    entrada.focus();
  }
};

/* ------------------------- Administrador · Backup e Restauração ------------------------- */
PAGES['admin.backup'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/backup'); }
    catch (e) { return pageHead('Backup e Restauração', '') + blocoErro(e); }
    this._d = d;

    const rotinas = d.rotinas.map(r => {
      const quando = r.periodicidade === 'diaria' ? `todo dia às ${String(r.hora).slice(0,5)}`
        : r.periodicidade === 'semanal' ? `${['domingo','segunda','terça','quarta','quinta','sexta','sábado'][r.dia_semana ?? 0]} às ${String(r.hora).slice(0,5)}`
        : r.periodicidade === 'mensal' ? `dia ${r.dia_mes} às ${String(r.hora).slice(0,5)}`
        : 'somente manual';
      const inclui = [
        Number(r.inclui_banco) && 'banco', Number(r.inclui_config) && 'configuração',
        Number(r.inclui_audios) && 'áudios', Number(r.inclui_gravacoes) && 'gravações'
      ].filter(Boolean);

      return `<div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:10px">
          <div><b>${esc(r.nome)}</b><div class="tiny muted">${esc(quando)}</div></div>
          <label class="switch"><input type="checkbox" ${Number(r.ativo) ? 'checked' : ''}
                 ${ctx.can('editar') ? '' : 'disabled'} data-rotina-ativa="${r.id}">
            <span class="track"></span></label>
        </div>
        <div class="row wrap gap-4" style="margin-bottom:10px">
          ${inclui.map(i => `<span class="chip">${i}</span>`).join('') || '<span class="chip">nada selecionado</span>'}
        </div>
        <div class="row-between">
          <span class="tiny muted">mantém ${r.retencao} arquivos</span>
          ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-editar-rotina="${r.id}">
            ${icon('edit','ico ico-sm')} Ajustar</button>` : ''}
        </div>
      </div>`;
    }).join('');

    const historico = d.historico.length ? `
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Arquivo</th><th>Origem</th><th>Conteúdo</th><th>Tamanho</th>
                   <th>Quando</th><th>Estado</th><th></th></tr></thead>
        <tbody>${d.historico.map(b => {
          const inclui = [
            Number(b.inclui_banco) && 'banco', Number(b.inclui_config) && 'config',
            Number(b.inclui_audios) && 'áudios', Number(b.inclui_gravacoes) && 'gravações'
          ].filter(Boolean).join(', ');
          const estado = {
            concluido: '<span class="badge badge-ok">Concluído</span>',
            falha: '<span class="badge badge-danger">Falhou</span>',
            executando: '<span class="badge badge-info"><i class="dot dot-pulse"></i>Executando</span>',
            pendente: '<span class="badge badge-warn">Na fila</span>'
          }[b.estado] || b.estado;

          return `<tr>
            <td class="mono small">${b.arquivo ? esc(b.arquivo) : '<span class="muted">removido pela retenção</span>'}</td>
            <td><span class="badge">${esc(b.origem)}</span></td>
            <td class="small dim">${esc(inclui)}</td>
            <td class="num">${tamanho(b.tamanho)}</td>
            <td class="small dim">${dataHora(b.concluido_em || b.criado_em)}</td>
            <td>${estado}</td>
            <td class="col-actions"><span class="row-actions">
              ${b.saida ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Ver detalhes" data-saida="${b.id}">${icon('file','ico ico-sm')}</button>` : ''}
              ${b.arquivo && b.estado === 'concluido' && ctx.can('reiniciar')
                ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Restaurar" data-restaurar="${b.id}">${icon('refresh','ico ico-sm')}</button>` : ''}
              ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir-bkp="${b.id}">${icon('trash','ico ico-sm')}</button>` : ''}
            </span></td>
          </tr>`;
        }).join('')}
        </tbody></table></div>`
      : vazio('database', 'Nenhum backup ainda',
              'Execute o primeiro agora ou ative uma rotina para o sistema cuidar disso sozinho.');

    return pageHead('Backup e Restauração',
      `Os arquivos ficam em <span class="mono">${esc(d.destino)}</span> no próprio servidor.`,
      `${d.executando ? '<span class="badge badge-info"><i class="dot dot-pulse"></i>Backup em andamento</span>' : ''}
       ${ctx.can('criar') ? `<button class="btn btn-primary btn-sm" id="backupAgora">
         ${icon('database','ico ico-sm')} Fazer backup agora</button>` : readOnlyNote(ctx)}`) + `

    <div class="grid g-4" style="margin-bottom:16px">
      <div class="card kpi">
        <div class="k-top"><span class="k-label">Espaço livre</span>
          <span class="k-ico" style="background:var(--${d.disco.pct_uso > 85 ? 'danger' : 'ok'}-soft);color:var(--${d.disco.pct_uso > 85 ? 'danger' : 'ok'})">${icon('hardDrive')}</span></div>
        <div class="k-val">${d.disco.livre_gb} GB</div>
        <div class="k-foot"><span>de ${d.disco.total_gb} GB · ${d.disco.pct_uso}% em uso</span></div>
      </div>
      ${rotinas}
    </div>

    <div class="card">
      <div class="card-head"><div><div class="card-title">Histórico</div>
        <div class="card-sub">A retenção apaga os mais antigos automaticamente.</div></div></div>
      <div class="card-body tight">${historico}</div>
    </div>`;
  },

  mount(ctx) {
    const opcoesConteudo = (v = {}) => [
      { campo: 'inclui_banco', label: 'Banco de dados', tipo: 'switch', padrao: v.inclui_banco ?? 1,
        ajuda: 'Ramais, filas, rotas, usuários, CDR — tudo o que o console guarda.' },
      { campo: 'inclui_config', label: 'Configuração', tipo: 'switch', padrao: v.inclui_config ?? 1,
        ajuda: 'Arquivos do Asterisk, do Janus e do Telium.' },
      { campo: 'inclui_audios', label: 'Áudios do sistema', tipo: 'switch', padrao: v.inclui_audios ?? 1,
        ajuda: 'Saudações de URA, anúncios e música em espera.' },
      { campo: 'inclui_gravacoes', label: 'Gravações de chamadas', tipo: 'switch', padrao: v.inclui_gravacoes ?? 0,
        ajuda: 'Pode ficar muito grande. Deixe desligado se o disco for apertado.' }
    ];

    document.getElementById('backupAgora')?.addEventListener('click', () => {
      const campos = [
        ...opcoesConteudo(),
        { campo: 'retencao', label: 'Quantos arquivos manter', tipo: 'number', padrao: 5,
          ajuda: 'Ao passar disso, os mais antigos são apagados.' }
      ];
      Drawer.open({
        titulo: 'Fazer backup agora',
        sub: 'O backup roda em segundo plano; você pode sair desta tela.',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, {})).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Iniciar backup</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          const dados = {};
          campos.forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
          });
          ev.currentTarget.disabled = true;
          try {
            const r = await Api.post('/backup/executar', dados);
            Drawer.close();
            toast(r.detalhe, r.disparado ? 'ok' : 'warn');
            setTimeout(() => App.route(), 1500);
          } catch (e) { ev.currentTarget.disabled = false; toast(e.message, 'err'); }
        }
      });
    });

    document.querySelectorAll('[data-editar-rotina]').forEach(b => b.onclick = () => {
      const r = this._d.rotinas.find(x => String(x.id) === b.dataset.editarRotina);
      const campos = [
        { campo: 'nome', label: 'Nome da rotina', obrigatorio: true },
        { campo: 'periodicidade', label: 'Quando executar', tipo: 'select',
          opcoes: [{valor:'diaria',rotulo:'Todo dia'},{valor:'semanal',rotulo:'Uma vez por semana'},
                   {valor:'mensal',rotulo:'Uma vez por mês'},{valor:'manual',rotulo:'Só quando eu mandar'}] },
        { campo: 'hora', label: 'Horário', tipo: 'time' },
        { campo: 'dia_semana', label: 'Dia da semana (se semanal)', tipo: 'select',
          opcoes: [{valor:'',rotulo:'—'},
            ...['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado']
              .map((d,i) => ({ valor: i, rotulo: d }))] },
        { campo: 'dia_mes', label: 'Dia do mês (se mensal)', tipo: 'number' },
        ...opcoesConteudo(r),
        { campo: 'retencao', label: 'Quantos arquivos manter', tipo: 'number' },
        { campo: 'ativo', label: 'Rotina ativa', tipo: 'switch' }
      ];
      Drawer.open({
        titulo: `Ajustar ${r.nome}`,
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, r)).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Salvar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          const dados = {};
          campos.forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : (el.value || null);
          });
          ev.currentTarget.disabled = true;
          try {
            await Api.put(`/backup-rotinas/${r.id}`, dados);
            Drawer.close(); toast('Rotina atualizada.', 'ok'); App.route();
          } catch (e) { ev.currentTarget.disabled = false; toast(e.message, 'err'); }
        }
      });
    });

    document.querySelectorAll('[data-rotina-ativa]').forEach(c => c.onchange = async () => {
      try {
        await Api.put(`/backup-rotinas/${c.dataset.rotinaAtiva}`, { ativo: c.checked ? 1 : 0 });
        toast(c.checked ? 'Rotina ativada.' : 'Rotina desativada.', 'ok');
      } catch (e) { c.checked = !c.checked; toast(e.message, 'err'); }
    });

    document.querySelectorAll('[data-saida]').forEach(b => b.onclick = () => {
      const bk = this._d.historico.find(x => String(x.id) === b.dataset.saida);
      Drawer.open({
        titulo: bk.arquivo || `Backup #${bk.id}`,
        sub: dataHora(bk.concluido_em || bk.criado_em),
        corpo: `<div class="code">${esc(bk.saida || '(sem detalhes)')}</div>`
      });
    });

    document.querySelectorAll('[data-restaurar]').forEach(b => b.onclick = async () => {
      const bk = this._d.historico.find(x => String(x.id) === b.dataset.restaurar);
      const ok = await Modal.confirm({
        titulo: 'Restaurar este backup?',
        texto: `O conteúdo atual será substituído pelo de ${bk.arquivo}. O Asterisk é parado durante `
             + 'a restauração e volta em seguida. As chamadas em andamento caem.',
        ok: 'Restaurar mesmo assim'
      });
      if (!ok) return;
      try {
        const r = await Api.post(`/backup/${bk.id}/restaurar`);
        toast(r.detalhe || 'Restauração iniciada.', 'warn');
      } catch (e) { toast(e.message, 'err'); }
    });

    document.querySelectorAll('[data-excluir-bkp]').forEach(b => b.onclick = async () => {
      const ok = await Modal.confirm({ titulo: 'Excluir este backup?',
        texto: 'O arquivo é apagado do servidor.', ok: 'Excluir' });
      if (!ok) return;
      try { await Api.delete(`/backup/${b.dataset.excluirBkp}`); toast('Backup excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Administrador · Gravações do Sistema ------------------------- */
const CATEGORIAS_AUDIO = [
  { valor: 'ura',     rotulo: 'Saudação de URA' },
  { valor: 'anuncio', rotulo: 'Anúncio' },
  { valor: 'espera',  rotulo: 'Música em espera' },
  { valor: 'fila',    rotulo: 'Mensagem de fila' },
  { valor: 'sistema', rotulo: 'Aviso do sistema' }
];

PAGES['admin.gravacoes'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/audios'); }
    catch (e) { return pageHead('Gravações do Sistema', '') + blocoErro(e); }
    this._d = d;

    const cabecalho = pageHead('Gravações do Sistema',
      'Áudios usados por URAs, filas e anúncios. O envio é convertido para os formatos que o Asterisk toca.',
      `${d.conversor ? '' : '<span class="badge badge-warn">sox ausente no servidor</span>'}
       ${ctx.can('criar') ? `<button class="btn btn-primary btn-sm" id="enviarAudio">
         ${icon('upload','ico ico-sm')} Enviar áudio</button>` : readOnlyNote(ctx)}`);

    if (!d.dados.length) {
      return cabecalho + `<div class="card">${vazio('mic', 'Nenhum áudio enviado',
        `Envie as saudações e anúncios que a URA e as filas vão tocar. Eles ficam em
         <span class="mono">${esc(d.diretorio)}</span>.`,
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" id="enviarAudio">Enviar o primeiro</button>' : '')}
      </div>`;
    }

    const porCategoria = {};
    d.dados.forEach(a => { (porCategoria[a.categoria] ??= []).push(a); });

    return cabecalho + Object.entries(porCategoria).map(([cat, itens]) => {
      const rotulo = CATEGORIAS_AUDIO.find(c => c.valor === cat)?.rotulo || cat;
      return `<div class="card" style="margin-bottom:16px">
        <div class="card-head"><div class="card-title">${esc(rotulo)}</div>
          <span class="badge">${itens.length}</span></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Nome</th><th>Arquivo</th><th>Duração</th><th>Formatos</th>
                     <th>Enviado por</th><th></th></tr></thead>
          <tbody>${itens.map(a => `
            <tr>
              <td><b>${esc(a.nome)}</b>${a.descricao ? `<div class="tiny muted">${esc(a.descricao)}</div>` : ''}</td>
              <td class="mono small">${esc(a.arquivo)}</td>
              <td class="num">${a.duracao ? duracao(a.duracao) : '—'}</td>
              <td>${String(a.formatos).split(',').map(f => `<span class="badge">${esc(f)}</span>`).join(' ')}</td>
              <td class="small dim">${esc(a.enviado_por_nome || '—')}</td>
              <td class="col-actions"><span class="row-actions">
                ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-editar-audio="${a.id}">${icon('edit','ico ico-sm')}</button>` : ''}
                ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir-audio="${a.id}">${icon('trash','ico ico-sm')}</button>` : ''}
              </span></td>
            </tr>`).join('')}
          </tbody></table></div>
      </div>`;
    }).join('');
  },

  mount(ctx) {
    document.querySelectorAll('#enviarAudio').forEach(b => b.onclick = () => {
      Drawer.open({
        titulo: 'Enviar áudio',
        sub: 'Aceita wav, mp3, ogg, gsm e outros. O servidor converte para WAV 8 kHz e GSM.',
        corpo: `
          <div class="grid" style="gap:16px">
            <div class="field">
              <label class="label">Arquivo *</label>
              <input class="input" type="file" name="arquivo"
                     accept=".wav,.mp3,.ogg,.gsm,.flac,.m4a,.g722,audio/*">
              <span class="hint">Até 20 MB.</span>
            </div>
            ${campoHtml({ campo:'nome', label:'Nome', obrigatorio:true,
                          placeholder:'Saudação principal' }, {})}
            ${campoHtml({ campo:'nome_arquivo', label:'Nome do arquivo no Asterisk', mono:true,
                          placeholder:'gerado a partir do nome',
                          ajuda:'É o que você seleciona na URA e nas filas.' }, {})}
            ${campoHtml({ campo:'categoria', label:'Categoria', tipo:'select',
                          opcoes: CATEGORIAS_AUDIO }, {})}
            ${campoHtml({ campo:'descricao', label:'Descrição', tipo:'textarea' }, {})}
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Enviar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          const arquivo = dw.querySelector('[name="arquivo"]').files[0];
          const nome = dw.querySelector('[name="nome"]').value.trim();

          if (!arquivo) { toast('Escolha um arquivo de áudio.', 'warn'); return; }
          if (!nome) { toast('Informe o nome do áudio.', 'warn'); return; }

          const fd = new FormData();
          fd.append('arquivo', arquivo);                              // o binário
          fd.append('nome', nome);
          fd.append('nome_arquivo', dw.querySelector('[name="nome_arquivo"]').value.trim());
          fd.append('categoria', dw.querySelector('[name="categoria"]').value);
          fd.append('descricao', dw.querySelector('[name="descricao"]').value);

          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = `<span class="spin"></span> Enviando…`;
          try {
            const r = await Api.upload('/audios', fd);
            Drawer.close();
            toast(`Áudio enviado como ${r.arquivo} (${r.formatos.join(', ')}).`, 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Enviar';
            toast(e.message, 'err');
          }
        }
      });
    });

    document.querySelectorAll('[data-editar-audio]').forEach(b => b.onclick = () => {
      const a = this._d.dados.find(x => String(x.id) === b.dataset.editarAudio);
      const campos = [
        { campo: 'nome', label: 'Nome', obrigatorio: true },
        { campo: 'categoria', label: 'Categoria', tipo: 'select', opcoes: CATEGORIAS_AUDIO },
        { campo: 'descricao', label: 'Descrição', tipo: 'textarea', largura: 'full' }
      ];
      Drawer.open({
        titulo: `Editar ${a.nome}`,
        sub: `Arquivo <span class="mono">${esc(a.arquivo)}</span> — para trocar o áudio, envie outro e exclua este.`,
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, a)).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Salvar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async () => {
          const dados = {};
          campos.forEach(c => { dados[c.campo] = dw.querySelector(`[name="${c.campo}"]`).value; });
          try { await Api.put(`/audios/${a.id}`, dados); Drawer.close(); toast('Áudio atualizado.', 'ok'); App.route(); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
    });

    document.querySelectorAll('[data-excluir-audio]').forEach(b => b.onclick = async () => {
      const a = this._d.dados.find(x => String(x.id) === b.dataset.excluirAudio);
      const ok = await Modal.confirm({
        titulo: `Excluir ${a.nome}?`,
        texto: 'Os arquivos de áudio são apagados do servidor. Se algo ainda usa este som, a exclusão é recusada.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/audios/${a.id}`); toast('Áudio excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Administrador · Códigos de Recurso ------------------------- */
PAGES['admin.codigos'] = paginaCrud({
  recurso: 'codigos-recurso',
  titulo: 'Códigos de Recurso',
  sub: 'O que o usuário disca para usar cada facilidade. Pode trocar qualquer código.',
  ico: 'grid',
  plural: 'códigos',
  somenteLeitura: false,
  rotuloNovo: 'Novo código',
  vazioTitulo: 'Nenhum código de recurso',
  vazioTexto: 'Os códigos padrão são criados na instalação.',
  placeholderBusca: 'Buscar por nome ou código…',
  textoBusca: c => `${c.codigo} ${c.nome} ${c.descricao || ''}`,
  tituloEditar: c => `${c.codigo} — ${c.nome}`,
  tituloExcluir: c => `Excluir o código ${c.codigo}?`,
  textoExcluir: () => 'A facilidade deixa de atender nesse número depois de aplicar as configurações.',

  colunas: [
    { label: 'Código', render: c => `<b class="mono" style="font-size:15px">${esc(c.codigo)}</b>` },
    { label: 'Facilidade', render: c => `<b>${esc(c.nome)}</b>${c.descricao ? `<div class="tiny muted">${esc(c.descricao)}</div>` : ''}` },
    { label: 'Categoria', render: c => `<span class="badge">${esc(c.categoria)}</span>` },
    { label: 'Chave', render: c => `<span class="mono tiny muted">${esc(c.chave)}</span>` },
    { label: 'Estado', render: c => Number(c.ativo)
        ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
        : '<span class="badge"><i class="dot"></i>Desligado</span>' }
  ],

  filtrosExtra: itens => {
    const cats = [...new Set(itens.map(i => i.categoria))].sort();
    return `<select class="select" data-filtro-campo="categoria" style="width:170px">
      <option value="">Todas as categorias</option>
      ${cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('')}
    </select>`;
  },

  campos: (c) => [
    { campo: 'codigo', label: 'Código discado', obrigatorio: true, mono: true, placeholder: '*8',
      ajuda: 'Comece com * ou # para não colidir com número de ramal.' },
    { campo: 'nome', label: 'Nome da facilidade', obrigatorio: true },
    { campo: 'categoria', label: 'Categoria', tipo: 'select',
      opcoes: ['atendimento','correio','desvio','diagnostico','gravacao','geral'] },
    { campo: 'ativo', label: 'Código ativo', tipo: 'switch', padrao: 1 },
    { campo: 'descricao', label: 'Descrição', tipo: 'textarea', largura: 'full' }
  ]
});

/* ------------------------- Administrador · Lista Negra ------------------------- */
PAGES['admin.listanegra'] = paginaCrud({
  recurso: 'lista-negra',
  titulo: 'Lista Negra',
  sub: 'Números barrados na entrada. A verificação acontece antes de qualquer rota.',
  ico: 'phoneOff',
  plural: 'números',
  rotuloNovo: 'Bloquear número',
  tituloNovo: 'Bloquear número',
  vazioTitulo: 'Nenhum número bloqueado',
  vazioTexto: 'Bloqueie telemarketing e trotes. Aceita padrão do dialplan, como _115555X. para uma faixa inteira.',
  placeholderBusca: 'Buscar por número ou descrição…',
  textoBusca: n => `${n.numero} ${n.descricao || ''}`,
  tituloEditar: n => `Bloqueio de ${n.numero}`,
  tituloExcluir: n => `Liberar ${n.numero}?`,
  textoExcluir: () => 'O número volta a conseguir ligar para a central.',

  colunas: [
    { label: 'Número', render: n => `<b class="mono">${esc(n.numero)}</b>` },
    { label: 'Descrição', render: n => `<span class="dim">${esc(n.descricao || '—')}</span>` },
    { label: 'Tratamento', render: n => {
        const t = { desligar: ['danger','Desliga na hora'], ocupado: ['warn','Sinal de ocupado'],
                    anuncio: ['info','Toca anúncio'], silencio: ['','Silêncio'] }[n.tratamento] || ['', n.tratamento];
        return `<span class="badge badge-${t[0]}">${t[1]}</span>`;
      } },
    { label: 'Bloqueios', render: n => `<span class="num">${num(n.bloqueios)}</span>` },
    { label: 'Estado', render: n => Number(n.ativo)
        ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
        : '<span class="badge"><i class="dot"></i>Pausado</span>' }
  ],

  aoCarregar: async (pagina) => {
    pagina._audios = (await Api.get('/audios', { categoria: 'anuncio' }).catch(() => ({ dados: [] }))).dados;
  },

  campos: (n, ctx, pagina) => [
    { campo: 'numero', label: 'Número ou padrão', obrigatorio: true, mono: true,
      placeholder: '1199998888', largura: 'full',
      ajuda: 'Número exato, ou padrão do dialplan: _115555X. barra a faixa 115555 0-9 e mais dígitos.' },
    { campo: 'descricao', label: 'Motivo', placeholder: 'Telemarketing insistente', largura: 'full' },
    { campo: 'tratamento', label: 'O que fazer com a chamada', tipo: 'select',
      opcoes: [{valor:'desligar',rotulo:'Desligar na hora'},
               {valor:'ocupado',rotulo:'Sinal de ocupado'},
               {valor:'anuncio',rotulo:'Tocar um anúncio e desligar'},
               {valor:'silencio',rotulo:'Atender e ficar em silêncio'}] },
    { campo: 'audio_id', label: 'Anúncio (se escolher tocar um)', tipo: 'select',
      opcoes: [{ valor: '', rotulo: 'padrão do sistema' },
               ...(pagina._audios || []).map(a => ({ valor: a.id, rotulo: a.nome }))] },
    { campo: 'ativo', label: 'Bloqueio ativo', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Administrador · Allowlist ------------------------- */
PAGES['admin.allowlist'] = paginaCrud({
  recurso: 'lista-permitida',
  titulo: 'Allowlist',
  sub: 'Números que nunca são barrados, mesmo que casem com algum padrão da lista negra.',
  ico: 'checkCirc',
  plural: 'números',
  rotuloNovo: 'Liberar número',
  vazioTitulo: 'Nenhuma exceção cadastrada',
  vazioTexto: 'Use quando um padrão da lista negra for amplo demais e pegar um número que você quer atender.',
  placeholderBusca: 'Buscar…',
  textoBusca: n => `${n.numero} ${n.descricao || ''}`,
  colunas: [
    { label: 'Número', render: n => `<b class="mono">${esc(n.numero)}</b>` },
    { label: 'Descrição', render: n => `<span class="dim">${esc(n.descricao || '—')}</span>` },
    { label: 'Estado', render: n => Number(n.ativo)
        ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>' : '<span class="badge">Pausado</span>' }
  ],
  campos: () => [
    { campo: 'numero', label: 'Número ou padrão', obrigatorio: true, mono: true, largura: 'full' },
    { campo: 'descricao', label: 'Motivo', largura: 'full' },
    { campo: 'ativo', label: 'Ativo', tipo: 'switch', padrao: 1 }
  ]
});
