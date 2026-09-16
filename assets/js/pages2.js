/* =========================================================
   Telium PABX — telas (parte 2)
   Rotas, URA, relatórios, tarifação, provisionamento e PCU.
   ========================================================= */

/* Opções de destino usadas por rotas e URA — carregadas do banco. */
async function opcoesDestino() {
  const vazio = () => ({ dados: [] });
  const [ramais, filas, uras, custom, grupos, anuncios, disa, condicoes, conferencias, paging] =
    await Promise.all([
      Api.get('/ramais', { limite: 500 }).catch(vazio),
      Api.get('/filas', { limite: 200 }).catch(vazio),
      Api.get('/ura', { limite: 100 }).catch(vazio),
      Api.get('/destinos-personalizados', { limite: 200 }).catch(vazio),
      Api.get('/grupos-toque', { limite: 200 }).catch(vazio),
      Api.get('/anuncios', { limite: 200 }).catch(vazio),
      Api.get('/disa', { limite: 100 }).catch(vazio),
      Api.get('/condicoes-horarias', { limite: 200 }).catch(vazio),
      Api.get('/conferencias', { limite: 200 }).catch(vazio),
      Api.get('/grupos-paging', { limite: 200 }).catch(vazio)
    ]);
  const ativos = l => (l.dados || []).filter(x => Number(x.ativo));
  return {
    ramais: ramais.dados, filas: filas.dados, uras: uras.dados,
    grupos: grupos.dados,
    disa: ativos(disa),
    condicoes: ativos(condicoes),
    conferencias: ativos(conferencias),
    paging: ativos(paging),
    // Anúncio, não gravação: a gravação é matéria-prima, o anúncio é o
    // que sabe o que fazer com ela.
    anuncios: ativos(anuncios),
    personalizados: ativos(custom)
  };
}

/**
 * Um seletor só para o destino, no lugar de "tipo" mais "valor".
 *
 * O valor do <option> é "tipo|valor" porque o banco guarda os dois
 * separados — quem lê de volta é lerDestino().
 */
function destinoSelect(prefixo, item, destinos, extras = {}) {
  const d = destinos || {};
  const tipoAtual = item?.[`${prefixo}_tipo`] || '';
  const valorAtual = item?.[`${prefixo}_valor`] ?? '';
  // "externo" é o único destino cujo valor é digitado: a opção no
  // seletor é sempre "externo|", e o número vive no campo ao lado.
  const atual = tipoAtual === 'externo' ? 'externo|' : `${tipoAtual}|${valorAtual}`;

  const grupo = (rotulo, itens) => itens.length
    ? `<optgroup label="${esc(rotulo)}">${itens.map(o =>
        `<option value="${esc(o.v)}" ${o.v === atual ? 'selected' : ''}>${esc(o.r)}</option>`).join('')}</optgroup>`
    : '';

  const corpo = [
    grupo('Ramais', (d.ramais || []).map(r => ({ v: `ramal|${r.numero}`, r: `${r.numero} — ${r.nome}` }))),
    grupo('Filas', (d.filas || []).map(f => ({ v: `fila|${f.numero}`, r: `${f.numero} — ${f.nome}` }))),
    grupo('URAs', (d.uras || []).map(u => ({ v: `ura|${u.id}`, r: u.nome }))),
    grupo('Grupos de toque', (d.grupos || []).map(g => ({ v: `grupo|${g.numero}`, r: `${g.numero} — ${g.nome}` }))),
    grupo('Correio de voz', (d.ramais || []).map(r => ({ v: `voicemail|${r.numero}`, r: `Caixa de ${r.numero} — ${r.nome}` }))),
    grupo('Anúncios', (d.anuncios || []).map(a => ({ v: `anuncio|${a.id}`, r: a.nome }))),
    grupo('Condições horárias', (d.condicoes || []).map(c =>
        ({ v: `condicao|${c.id}`, r: c.nome }))),
    grupo('Conferências', (d.conferencias || []).map(c =>
        ({ v: `conferencia|${c.numero}`, r: `${c.numero} — ${c.nome}` }))),
    grupo('DISA', (d.disa || []).map(x => ({ v: `disa|${x.id}`, r: x.nome }))),
    grupo('Megafonia', (d.paging || []).map(g =>
        ({ v: `paging|${g.numero}`, r: `${g.numero} — ${g.nome}` }))),
    grupo('Destinos personalizados', (d.personalizados || []).map(x =>
        ({ v: `personalizado|${x.id}`, r: `${x.nome} (${x.contexto},${x.extensao})` }))),
    // Número externo é o único destino que não sai de um cadastro: o
    // campo de texto ao lado só aparece quando esta opção é escolhida.
    grupo('Fora da central', [{ v: 'externo|', r: 'Número externo — transbordo' }]),
    // Três jeitos de encerrar, e quem liga ouve coisas diferentes.
    grupo('Encerrar', [
      { v: 'desligar|', r: 'Desligar a chamada' },
      { v: 'ocupado|', r: 'Tom de ocupado' },
      { v: 'congestionado|', r: 'Tom de congestionamento' }
    ])
  ].join('');

  const semEscolha = extras.rotuloVazio ?? '— escolha um destino —';
  const select = `<span class="destino-par">
    <select class="select" name="${prefixo}" data-destino>
      <option value="|" ${atual === '|' || atual.startsWith('undefined') ? 'selected' : ''}>${esc(semEscolha)}</option>
      ${corpo}
    </select>
    <input class="input mono" data-destino-externo type="text" inputmode="tel"
           placeholder="número com DDD" value="${tipoAtual === 'externo' ? esc(valorAtual) : ''}"
           ${tipoAtual === 'externo' ? '' : 'hidden'}>
  </span>`;

  // Dentro de uma tabela não cabe rótulo nem ajuda; só o seletor.
  if (extras.nu) return select;

  return `<div class="field${extras.largura === 'full' ? ' full' : ''}" data-campo="${prefixo}">
    <label class="label">${esc(extras.label || 'Destino')}${extras.obrigatorio ? ' *' : ''}</label>
    ${select}
    ${extras.ajuda ? `<span class="hint">${esc(extras.ajuda)}</span>` : ''}
  </div>`;
}

/** Opções de um <select> de anúncio — é o que os módulos escolhem. */
function opcoesAnuncio(anuncios, rotuloVazio = '— nenhum —') {
  return [{ valor: '', rotulo: rotuloVazio },
    ...(anuncios || []).filter(a => Number(a.ativo))
      .map(a => ({ valor: a.id, rotulo: a.nome }))];
}

/** Lê um destinoSelect de volta para {tipo, valor}. */
function lerDestino(el) {
  const [tipo, ...resto] = String(el?.value ?? '|').split('|');
  if (tipo === 'externo') {
    const campo = el?.parentElement?.querySelector('[data-destino-externo]');
    return { tipo, valor: (campo?.value || '').replace(/[^0-9*#+]/g, '') };
  }
  return { tipo, valor: resto.join('|') };
}

// O campo do número externo aparece e some com a escolha. Delegado no
// documento porque o seletor é montado dentro de telas que não têm um
// gancho próprio para ligar eventos.
document.addEventListener('change', ev => {
  const sel = ev.target.closest?.('[data-destino]');
  if (!sel) return;
  const campo = sel.parentElement?.querySelector('[data-destino-externo]');
  if (!campo) return;
  campo.hidden = !String(sel.value).startsWith('externo|');
  if (!campo.hidden) campo.focus();
});


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
      ajuda: 'Aceita padrão do dialplan, por exemplo _X. para qualquer número. '
           + 'Um asterisco sozinho vale como "qualquer DID" e é sempre avaliado por último.' },
    { campo: 'descricao', label: 'Descrição', placeholder: 'Comercial 0800' },
    { campo: 'destino', tipo: 'destino', label: 'Para onde vai a chamada', obrigatorio: true,
      largura: 'full', destinos: pagina._destinos,
      ajuda: 'Todo destino que a central sabe alcançar. "Número externo" passa pelas rotas de '
           + 'saída, então use o mesmo formato que um ramal discaria.' },
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
    pagina._pins = (await Api.get('/pin-sets', { limite: 200 }).catch(() => ({ dados: [] }))).dados;
  },

  campos: (r, ctx, pagina) => {
    const troncos = (pagina._troncos || []).map(t => ({ valor: t.id, rotulo: t.nome }));
    const pins = (pagina._pins || []).map(p => ({ valor: p.id, rotulo: p.nome }));
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
      { campo: 'pin_set_id', label: 'Pedir PIN', tipo: 'select', largura: 'full',
        opcoes: [{ valor: '', rotulo: 'não pedir senha nesta rota' }, ...pins],
        ajuda: pins.length
          ? 'A central pede a senha antes de completar a chamada. Cadastre os conjuntos em Configurações › Conjuntos de PIN.'
          : 'Nenhum conjunto cadastrado ainda — crie um em Configurações › Conjuntos de PIN.' },
      { campo: 'ativo', label: 'Rota ativa', tipo: 'switch', padrao: 1 }
    ];
  }
});

/* ------------------------- URA ------------------------- */
PAGES['apps.ura'] = {
  async render(ctx) {
    let uras, destinos, anuncios;
    try {
      [uras, destinos, anuncios] = await Promise.all([
        Api.get('/ura', { limite: 100 }),
        opcoesDestino(),
        Api.get('/anuncios', { limite: 200 }).catch(() => ({ dados: [] }))
      ]);
    } catch (e) { return pageHead('URA — Atendimento Digital', '') + blocoErro(e); }

    this._uras = uras.dados;
    this._destinos = destinos;
    this._anuncios = anuncios.dados;

    const cabecalho = pageHead('URA — Atendimento Digital',
      'A URA atende, toca a saudação e manda a chamada para onde o cliente escolher.',
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-nova-ura>${icon('plus','ico ico-sm')} Nova URA</button>`
        : readOnlyNote(ctx));

    if (!this._uras.length) {
      return cabecalho + `<div class="card">${vazio('branch', 'Nenhuma URA cadastrada',
        `A URA é o "digite 1 para vendas, 2 para suporte". Grave a saudação em
         Gravações do Sistema e monte as opções aqui.`,
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-nova-ura>Criar a primeira URA</button>' : '')}
      </div>`;
    }

    const opcoesPorUra = {};
    await Promise.all(this._uras.map(async u => {
      opcoesPorUra[u.id] = (await Api.get(`/ura/${u.id}/opcoes`).catch(() => ({ opcoes: [] }))).opcoes || [];
    }));
    this._opcoes = opcoesPorUra;

    return cabecalho + this._uras.map(u => {
      const opcoes = opcoesPorUra[u.id] || [];
      const anuncio = this._anuncios.find(a => String(a.id) === String(u.anuncio_id));

      return `<div class="card" style="margin-bottom:16px">
        <div class="card-head">
          <div class="row gap-8">
            <span class="k-ico" style="background:var(--brand-soft);color:var(--brand)">${icon('branch')}</span>
            <div><div class="card-title">${esc(u.nome)}
              ${Number(u.ativo) ? '' : '<span class="badge">parada</span>'}</div>
              <div class="card-sub">
                Toca ${anuncio ? esc(anuncio.nome) : '<span class="badge badge-warn">sem anúncio de saudação</span>'}
                · espera ${u.timeout_digito}s pelo dígito · ${u.tentativas} tentativas
                ${Number(u.discagem_direta) ? '· aceita discagem direta de ramal' : ''}</div></div>
          </div>
          <div class="row gap-6">
            ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-editar-ura="${u.id}">
              ${icon('edit','ico ico-sm')} Editar</button>` : ''}
            ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
              data-excluir-ura="${u.id}">${icon('trash','ico ico-sm')}</button>` : ''}
          </div>
        </div>
        <div class="card-body">
          <div class="ura-root">${icon('speaker','ico ico-lg')}
            <div class="grow"><b>${esc(u.nome)}</b>
              <small>Atende, toca a saudação e espera o cliente digitar</small></div>
          </div>
          ${opcoes.length ? `<div class="ura-tree">
            ${opcoes.map(o => `
              <div class="ura-node">
                <span class="ura-key">${esc(o.tecla)}</span>
                <div class="grow"><b>${esc(o.rotulo)}</b>
                  <div class="tiny muted">quem digita ${esc(o.tecla)}</div></div>
                ${icon('chevronR','ico arrow')}
                <span class="ura-dest">${icon('branch','ico ico-sm')}${esc(descreveDestino(o.destino_tipo, o.destino_valor, this._destinos))}</span>
              </div>`).join('')}
          </div>` : `<p class="small muted center" style="padding:20px">
              Nenhuma entrada configurada. Todo mundo cai no destino de tempo esgotado.</p>`}

          <div class="row gap-12 wrap" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
            <span class="tiny muted">Tempo esgotado →
              <b>${esc(descreveDestino(u.destino_timeout_tipo, u.destino_timeout_valor, this._destinos))}</b></span>
            <span class="tiny muted">Opção inválida →
              <b>${esc(descreveDestino(u.destino_invalido_tipo, u.destino_invalido_valor, this._destinos))}</b></span>
          </div>
        </div>
      </div>`;
    }).join('');
  },

  mount(ctx) {
    const pagina = this;

    const anunciosSelect = () => opcoesAnuncio(pagina._anuncios, '— escolha a saudação —');

    const linhaEntrada = (o = {}) => `
      <tr data-entrada>
        <td><input class="input mono" name="tecla" style="width:110px"
                   value="${esc(o.tecla || '')}" placeholder="dígitos"></td>
        <td><input class="input" name="rotulo" value="${esc(o.rotulo || '')}"
                   placeholder="para que serve"></td>
        <td>${destinoSelect('destino', o, pagina._destinos, { nu: true })}</td>
        <td class="col-actions">
          <button class="btn btn-ghost btn-sm btn-icon" data-tip="Tirar" data-tirar-entrada>
            ${icon('x','ico ico-sm')}</button></td>
      </tr>`;

    const formUra = item => {
      const novo = !item;
      const u = item || { timeout_digito: 8, tentativas: 3, discagem_direta: 1, ativo: 1 };
      const entradas = novo ? [] : (pagina._opcoes[u.id] || []);

      const campos = [
        { campo: 'nome', label: 'Nome da URA', obrigatorio: true, largura: 'full',
          placeholder: 'URA Principal', ajuda: 'Só para você reconhecer nas rotas e nos destinos.' },
        { campo: 'anuncio_id', label: 'Anúncio de saudação', obrigatorio: true, tipo: 'select',
          opcoes: anunciosSelect(), largura: 'full',
          ajuda: 'É o que o cliente ouve ao cair na URA. Os anúncios são montados em Aplicações › Anúncios, a partir dos áudios enviados.' },
        { campo: 'timeout_digito', label: 'Espera pelo dígito (s)', tipo: 'number', padrao: 8,
          ajuda: 'Quanto tempo a URA aguarda depois da saudação antes de considerar que ninguém digitou.' },
        { campo: 'tentativas', label: 'Tentativas', tipo: 'number', padrao: 3,
          ajuda: 'Quantas vezes a saudação se repete antes de mandar a chamada para o tempo esgotado.' },
        { campo: 'discagem_direta', label: 'Permitir discar o ramal direto', tipo: 'switch', padrao: 1,
          ajuda: 'Quem já sabe o número do ramal digita e vai direto, sem passar pelo menu.' },
        { campo: 'ativo', label: 'URA ativa', tipo: 'switch', padrao: 1 }
      ];

      Drawer.open({
        titulo: novo ? 'Nova URA' : `Editar ${u.nome}`,
        sub: 'A saudação, o tempo de espera e para onde vai cada tecla.',
        wide: true,
        corpo: `
          <div class="form-grid">${campos.map(c => campoHtml(c, u)).join('')}</div>

          <div class="secao-form">
            <div class="row-between">
              <div><b>Entradas da URA</b>
                <div class="tiny muted">O que o cliente digita e para onde a chamada vai.</div></div>
              <button class="btn btn-outline btn-sm" id="addEntrada">
                ${icon('plus','ico ico-sm')} Adicionar entrada</button>
            </div>
            <div class="table-wrap" style="margin-top:10px"><table class="table" id="tabelaEntradas">
              <thead><tr><th style="width:130px">Dígitos</th><th>Descrição</th>
                         <th style="width:38%">Destino</th><th></th></tr></thead>
              <tbody>${entradas.map(linhaEntrada).join('')}</tbody>
            </table></div>
            <p class="hint" style="margin-top:8px">
              Aceita o que o cliente aperta (1, 0, *, #) ou um padrão do dialplan começando com _,
              como <span class="mono">_2XX</span> para qualquer ramal da faixa 200.</p>
          </div>

          <div class="secao-form">
            <b>Se ninguém escolher nada</b>
            <div class="form-grid" style="margin-top:10px">
              ${destinoSelect('destino_timeout', u, pagina._destinos,
                  { label: 'Tempo esgotado', largura: 'full',
                    ajuda: 'Depois de repetir a saudação o número de tentativas acima.' })}
              ${destinoSelect('destino_invalido', u, pagina._destinos,
                  { label: 'Opção inválida', largura: 'full',
                    ajuda: 'Quando o cliente insiste numa tecla que não existe no menu.' })}
            </div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar URA' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          const corpo = dw.querySelector('#tabelaEntradas tbody');
          const ligarRemocao = () => dw.querySelectorAll('[data-tirar-entrada]').forEach(b =>
            b.onclick = () => b.closest('tr').remove());
          ligarRemocao();

          dw.querySelector('#addEntrada').onclick = () => {
            corpo.insertAdjacentHTML('beforeend', linhaEntrada());
            ligarRemocao();
            corpo.lastElementChild.querySelector('[name="tecla"]').focus();
          };

          if (!entradas.length) dw.querySelector('#addEntrada').click();

          dw.querySelector('[data-ok]').onclick = async ev => {
            if (!validarCampos(dw, campos)) return;

            const dados = {};
            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
            });
            ['destino_timeout', 'destino_invalido'].forEach(p => {
              const { tipo, valor } = lerDestino(dw.querySelector(`[name="${p}"]`));
              dados[`${p}_tipo`] = tipo;
              dados[`${p}_valor`] = valor;
            });

            const opcoes = [...corpo.querySelectorAll('tr[data-entrada]')].map((tr, i) => {
              const { tipo, valor } = lerDestino(tr.querySelector('[name="destino"]'));
              return {
                tecla: tr.querySelector('[name="tecla"]').value.trim(),
                rotulo: tr.querySelector('[name="rotulo"]').value.trim(),
                destino_tipo: tipo, destino_valor: valor, ordem: (i + 1) * 10
              };
            }).filter(o => o.tecla !== '' || o.destino_tipo !== '');

            // Erro de entrada é apontado na própria linha, não num toast solto.
            limparErros(dw);
            const problemas = [];
            opcoes.forEach((o, i) => {
              const tr = corpo.querySelectorAll('tr[data-entrada]')[i];
              if (o.tecla === '') {
                tr.querySelector('[name="tecla"]').classList.add('erro');
                problemas.push(`Entrada ${i + 1}: falta o dígito`);
              }
              if (o.destino_tipo === '') {
                tr.querySelector('[name="destino"]').classList.add('erro');
                problemas.push(`Entrada ${o.tecla || i + 1}: falta o destino`);
              }
            });
            if (problemas.length) { avisoFormulario(dw, problemas); return; }

            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              const r = novo ? await Api.post('/ura', dados) : await Api.put(`/ura/${u.id}`, dados);
              const id = novo ? r.id : u.id;
              await Api.put(`/ura/${id}/opcoes`, { opcoes });
              Drawer.close();
              toast('URA salva. Aplique as configurações para valer no Asterisk.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Criar URA' : 'Salvar';
              if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
              else toast(e.message, 'err');
            }
          };
        }
      });
    };

    document.querySelectorAll('[data-nova-ura]').forEach(b => b.onclick = () => formUra(null));
    document.querySelectorAll('[data-editar-ura]').forEach(b => b.onclick = () =>
      formUra(pagina._uras.find(u => String(u.id) === b.dataset.editarUra)));

    document.querySelectorAll('[data-excluir-ura]').forEach(b => b.onclick = async () => {
      const u = pagina._uras.find(x => String(x.id) === b.dataset.excluirUra);
      const ok = await Modal.confirm({
        titulo: `Excluir a URA ${u.nome}?`,
        texto: 'As entradas somem junto, e as rotas que apontavam para ela ficam sem destino.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/ura/${u.id}`); toast('URA excluída.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/** "ramal 1001 — Recepção", para mostrar um destino em texto. */
function descreveDestino(tipo, valor, destinos) {
  const d = destinos || {};
  if (!tipo) return 'não configurado';

  const achar = (lista, campo) => (lista || []).find(x => String(x[campo]) === String(valor));

  switch (tipo) {
    case 'ramal': {
      const r = achar(d.ramais, 'numero');
      return r ? `ramal ${r.numero} — ${r.nome}` : `ramal ${valor}`;
    }
    case 'fila': {
      const f = achar(d.filas, 'numero');
      return f ? `fila ${f.numero} — ${f.nome}` : `fila ${valor}`;
    }
    case 'ura': {
      const u = achar(d.uras, 'id');
      if (!u) return `URA ${valor}`;
      return /^ura\b/i.test(u.nome) ? u.nome : `URA ${u.nome}`;
    }
    case 'grupo': {
      const g = achar(d.grupos, 'numero');
      return g ? `grupo ${g.numero} — ${g.nome}` : `grupo de toque ${valor}`;
    }
    case 'personalizado': {
      const p = achar(d.personalizados, 'id');
      return p ? `destino ${p.nome}` : `destino personalizado ${valor}`;
    }
    case 'voicemail': return `correio de voz de ${valor}`;
    case 'anuncio': {
      const a = achar(d.anuncios, 'id');
      return a ? `anúncio ${a.nome}` : `anúncio ${valor}`;
    }
    case 'externo':   return `número externo ${valor}`;
    case 'desligar':  return 'desligar';
    default:          return `${tipo} ${valor}`;
  }
}

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
PAGES['apps.gravacao'] = {
  _f: { de: '', ate: '', direcao: '', q: '', pagina: 1 },

  async render(ctx) {
    let r;
    try { r = await Api.get('/gravacoes', this._f); }
    catch (e) { return pageHead('Gravação de Chamadas', '') + blocoErro(e); }
    this._d = r;

    const cabecalho = pageHead('Gravação de Chamadas',
      `Todas as gravações do sistema. Ouça, baixe uma ou leve várias num pacote.
       Nada é apagado por aqui — quem limpa o disco é a retenção do backup.`,
      `<span class="badge ${r.disco.pct_uso > 85 ? 'badge-danger' : ''}">
         ${r.disco.livre_gb} GB livres de ${r.disco.total_gb} GB</span>
       ${ctx.can('exportar') ? `<button class="btn btn-primary btn-sm" id="baixarSel" disabled>
         ${icon('download','ico ico-sm')} Baixar selecionadas</button>` : ''}`);

    const filtros = `
      <div class="card" style="padding:12px 14px;margin-bottom:16px">
        <div class="toolbar" style="padding:0;border:0">
          <input class="input" type="date" id="fDe" value="${esc(this._f.de)}" style="width:160px">
          <input class="input" type="date" id="fAte" value="${esc(this._f.ate)}" style="width:160px">
          <select class="select" id="fDirecao" style="width:150px">
            <option value="">Todos os sentidos</option>
            <option value="entrada" ${this._f.direcao === 'entrada' ? 'selected' : ''}>Entrada</option>
            <option value="saida" ${this._f.direcao === 'saida' ? 'selected' : ''}>Saída</option>
            <option value="interna" ${this._f.direcao === 'interna' ? 'selected' : ''}>Interna</option>
          </select>
          <div class="input-icon search-mini">${icon('search','ico ico-sm')}
            <input class="input" id="fQ" value="${esc(this._f.q)}" placeholder="Origem ou destino…">
          </div>
          <button class="btn btn-outline btn-sm" id="fAplicar">Filtrar</button>
          <button class="btn btn-ghost btn-sm" id="fLimpar">Limpar</button>
          <span class="grow"></span>
          <span class="small muted">${num(r.total)} gravaç${r.total === 1 ? 'ão' : 'ões'}</span>
        </div>
      </div>`;

    if (!r.dados.length) {
      return cabecalho + filtros + `<div class="card">${vazio('mic',
        'Nenhuma gravação neste filtro',
        `As gravações aparecem conforme as chamadas são gravadas. Ligue a gravação no ramal,
         na fila ou na rota de entrada, e aplique as configurações.`)}</div>`;
    }

    const linhas = r.dados.map(g => {
      const cam = String(g.arquivo);
      const sentido = {
        entrada: '<span class="badge badge-info">Entrada</span>',
        saida:   '<span class="badge badge-brand">Saída</span>',
        interna: '<span class="badge">Interna</span>'
      }[g.direcao] || (g.tags === 'conferencia'
        ? '<span class="badge badge-warn">Conferência</span>' : '<span class="muted">—</span>');

      return `<tr data-arquivo="${esc(cam)}">
        <td><label class="check"><input type="checkbox" data-sel ${g.existe ? '' : 'disabled'}></label></td>
        <td class="small">${dataHora(g.data)}</td>
        <td><b class="mono">${esc(g.origem || '—')}</b></td>
        <td><b class="mono">${esc(g.destino || '—')}</b></td>
        <td>${sentido}${g.fila ? ` <span class="badge">fila ${esc(g.fila)}</span>` : ''}</td>
        <td class="num">${duracao(g.duracao)}</td>
        <td class="num small dim">${g.existe ? tamanho(g.tamanho) : '—'}</td>
        <td class="col-actions"><span class="row-actions">
          ${g.existe ? `
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ouvir" data-ouvir>
              ${icon('play','ico ico-sm')}</button>
            ${ctx.can('exportar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Baixar" data-baixar>
              ${icon('download','ico ico-sm')}</button>` : ''}`
            : '<span class="badge badge-warn" data-tip="O registro existe, o arquivo não está mais no disco">sem arquivo</span>'}
        </span></td>
      </tr>`;
    }).join('');

    const paginacao = r.paginas > 1 ? `
      <div class="row-between" style="padding:12px 16px;border-top:1px solid var(--border)">
        <span class="small muted">Página ${r.pagina} de ${r.paginas}</span>
        <div class="row gap-6">
          <button class="btn btn-outline btn-sm" data-pagina="${r.pagina - 1}"
                  ${r.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
          <button class="btn btn-outline btn-sm" data-pagina="${r.pagina + 1}"
                  ${r.pagina >= r.paginas ? 'disabled' : ''}>Próxima</button>
        </div>
      </div>` : '';

    return cabecalho + filtros + `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr>
            <th style="width:40px"><label class="check"><input type="checkbox" id="selTodas"></label></th>
            <th>Data</th><th>Origem</th><th>Destino</th><th>Sentido</th>
            <th>Duração</th><th>Tamanho</th><th></th></tr></thead>
          <tbody>${linhas}</tbody>
        </table></div>
        ${paginacao}
      </div>`;
  },

  mount(ctx) {
    const pagina = this;

    const recarregar = () => { pagina._f.pagina = 1; App.route(); };
    document.getElementById('fAplicar')?.addEventListener('click', () => {
      pagina._f.de = document.getElementById('fDe').value;
      pagina._f.ate = document.getElementById('fAte').value;
      pagina._f.direcao = document.getElementById('fDirecao').value;
      pagina._f.q = document.getElementById('fQ').value.trim();
      recarregar();
    });
    document.getElementById('fLimpar')?.addEventListener('click', () => {
      pagina._f = { de: '', ate: '', direcao: '', q: '', pagina: 1 };
      App.route();
    });
    document.getElementById('fQ')?.addEventListener('keydown', e => {
      if (e.key === 'Enter') document.getElementById('fAplicar').click();
    });
    document.querySelectorAll('[data-pagina]').forEach(b => b.onclick = () => {
      pagina._f.pagina = Number(b.dataset.pagina);
      App.route();
    });

    // ---------- seleção ----------
    const marcados = () => [...document.querySelectorAll('[data-sel]:checked')]
      .map(c => c.closest('tr').dataset.arquivo);
    const botaoBaixar = document.getElementById('baixarSel');
    const revisar = () => {
      if (!botaoBaixar) return;
      const n = marcados().length;
      botaoBaixar.disabled = n === 0;
      botaoBaixar.innerHTML = n === 0
        ? `${icon('download','ico ico-sm')} Baixar selecionadas`
        : `${icon('download','ico ico-sm')} Baixar ${n} gravaç${n === 1 ? 'ão' : 'ões'}`;
    };
    document.querySelectorAll('[data-sel]').forEach(c => c.addEventListener('change', revisar));
    document.getElementById('selTodas')?.addEventListener('change', e => {
      document.querySelectorAll('[data-sel]:not(:disabled)').forEach(c => { c.checked = e.target.checked; });
      revisar();
    });
    revisar();

    botaoBaixar?.addEventListener('click', async () => {
      const caminhos = marcados();
      if (!caminhos.length) return;
      botaoBaixar.disabled = true;
      const antes = botaoBaixar.innerHTML;
      botaoBaixar.innerHTML = '<span class="spin"></span> Montando o pacote…';
      try {
        await Api.salvarArquivo('/gravacoes/pacote', { metodo: 'POST', corpo: { caminhos } });
        toast(`${caminhos.length} gravações num arquivo zip.`, 'ok');
      } catch (e) { toast(e.message, 'err'); }
      botaoBaixar.innerHTML = antes;
      revisar();
    });

    // ---------- baixar uma ----------
    document.querySelectorAll('[data-baixar]').forEach(b => b.onclick = async () => {
      const caminho = b.closest('tr').dataset.arquivo;
      b.disabled = true;
      try {
        await Api.salvarArquivo(`/gravacoes/arquivo?baixar=1&caminho=${encodeURIComponent(caminho)}`);
      } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
    });

    // ---------- ouvir ----------
    document.querySelectorAll('[data-ouvir]').forEach(b => b.onclick = async () => {
      const tr = b.closest('tr');
      const caminho = tr.dataset.arquivo;

      // Um clique no mesmo botão fecha o player, em vez de empilhar outro.
      const aberto = tr.nextElementSibling?.hasAttribute('data-player-linha');
      document.querySelectorAll('[data-player-linha]').forEach(l => {
        l.querySelector('audio')?.pause();
        l.remove();
      });
      if (aberto) return;

      b.disabled = true;
      b.innerHTML = '<span class="spin"></span>';
      try {
        const { blob } = await Api.baixar(`/gravacoes/arquivo?caminho=${encodeURIComponent(caminho)}`);
        const url = URL.createObjectURL(blob);
        tr.insertAdjacentHTML('afterend', `
          <tr data-player-linha><td colspan="8" style="background:var(--surface-2)">
            <div class="row gap-12">
              <audio controls autoplay preload="auto" src="${url}" style="flex:1;height:38px"></audio>
              <span class="tiny muted mono">${esc(caminho)}</span>
            </div>
          </td></tr>`);
        tr.nextElementSibling.querySelector('audio')
          .addEventListener('ended', () => setTimeout(() => URL.revokeObjectURL(url), 1000));
      } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
      b.innerHTML = icon('play', 'ico ico-sm');
    });
  }
};

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
const CATEGORIAS_CODIGO = [
  { chave: 'core',           rotulo: 'Núcleo',                    ico: 'grid' },
  { chave: 'informacoes',    rotulo: 'Serviços de Informação',    ico: 'info' },
  { chave: 'correio',        rotulo: 'Correio de Voz',            ico: 'voicemail' },
  { chave: 'desvio',         rotulo: 'Encaminhamento de Chamadas',ico: 'shuffle' },
  { chave: 'sigame',         rotulo: 'Siga-me',                   ico: 'route' },
  { chave: 'naoperturbe',    rotulo: 'Não Perturbe',              ico: 'phoneOff' },
  { chave: 'chamadaespera',  rotulo: 'Chamada em Espera',         ico: 'clock' },
  { chave: 'filas',          rotulo: 'Filas',                     ico: 'users' },
  { chave: 'estacionamento', rotulo: 'Estacionamento',            ico: 'package' },
  { chave: 'interfonia',     rotulo: 'Interfonia e Megafonia',    ico: 'speaker' },
  { chave: 'conferencia',    rotulo: 'Conferências',              ico: 'users' },
  { chave: 'agenda',         rotulo: 'Agenda de Contatos',        ico: 'book' },
  { chave: 'listanegra',     rotulo: 'Lista Negra',               ico: 'phoneOff' },
  { chave: 'allowlist',      rotulo: 'Allowlist',                 ico: 'checkCirc' },
  { chave: 'perdidas',       rotulo: 'Chamadas Perdidas',         ico: 'phoneIn' },
  { chave: 'despertar',      rotulo: 'Despertador',               ico: 'bell' },
  { chave: 'condicoes',      rotulo: 'Condições Horárias',        ico: 'calendar' },
  { chave: 'ditado',         rotulo: 'Ditado',                    ico: 'mic' },
  { chave: 'fax',            rotulo: 'Fax',                       ico: 'file' }
];

PAGES['admin.codigos'] = {
  async render(ctx) {
    let r;
    try { r = await Api.get('/codigos-recurso', { limite: 300 }); }
    catch (e) { return pageHead('Códigos de Recurso', '') + blocoErro(e); }
    this._itens = r.dados || [];

    const cabecalho = pageHead('Códigos de Recurso',
      `O que o usuário disca para usar cada facilidade. Todo código pode ser trocado;
       o que ele faz é fixo e já está no dialplan.`,
      `${ctx.can('criar') ? `<button class="btn btn-outline btn-sm" id="novoCodigo">
         ${icon('plus','ico ico-sm')} Código próprio</button>` : readOnlyNote(ctx)}`);

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('grid', 'Nenhum código de recurso',
        'O catálogo é criado na instalação. Rode o playbook do Ansible para recriá-lo.')}</div>`;
    }

    const ativos = this._itens.filter(c => Number(c.ativo)).length;
    const emChamada = this._itens.filter(c => c.tipo === 'featuremap').length;

    // Categorias conhecidas na ordem desenhada; o que sobrar vai ao final.
    const conhecidas = CATEGORIAS_CODIGO.map(c => c.chave);
    const extras = [...new Set(this._itens.map(c => c.categoria))].filter(c => !conhecidas.includes(c));
    const ordem = [...CATEGORIAS_CODIGO, ...extras.map(c => ({ chave: c, rotulo: c, ico: 'grid' }))];

    const blocos = ordem.map(cat => {
      const itens = this._itens
        .filter(c => c.categoria === cat.chave)
        .sort((a, b) => (a.ordem - b.ordem) || a.codigo.localeCompare(b.codigo));
      if (!itens.length) return '';

      return `<div class="card" style="margin-bottom:16px" data-categoria="${esc(cat.chave)}">
        <div class="card-head">
          <div class="row gap-8">
            <span class="k-ico" style="background:var(--brand-soft);color:var(--brand)">${icon(cat.ico)}</span>
            <div><div class="card-title">${esc(cat.rotulo)}</div>
              <div class="card-sub">${itens.length} ${itens.length === 1 ? 'código' : 'códigos'}</div></div>
          </div>
        </div>
        <div class="card-body tight">
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Descrição</th><th style="width:150px">Código</th>
                       <th style="width:120px">Onde vale</th><th style="width:190px">Ações</th></tr></thead>
            <tbody>${itens.map(c => `
              <tr data-id="${c.id}"
                  data-busca="${esc(`${c.codigo} ${c.nome} ${c.descricao || ''} ${c.chave}`.toLowerCase())}">
                <td><b>${esc(c.nome)}</b>
                  ${c.descricao ? `<div class="tiny muted">${esc(c.descricao)}</div>` : ''}</td>
                <td><b class="mono" style="font-size:15px">${esc(c.codigo)}</b>
                  ${c.argumento ? `<span class="tiny muted"> + ${esc(c.argumento)}</span>` : ''}</td>
                <td>${c.tipo === 'featuremap'
                  ? '<span class="badge badge-info" data-tip="Apertado durante a conversa">durante a chamada</span>'
                  : '<span class="badge">ao discar</span>'}</td>
                <td class="col-actions">
                  <span class="row gap-8">
                    ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-editar="${c.id}">
                      Personalizar</button>` : ''}
                    <label class="switch" data-tip="${Number(c.ativo) ? 'Habilitado' : 'Desabilitado'}">
                      <input type="checkbox" ${Number(c.ativo) ? 'checked' : ''}
                             ${ctx.can('editar') ? '' : 'disabled'} data-liga="${c.id}">
                      <span class="track"></span></label>
                  </span>
                </td>
              </tr>`).join('')}
            </tbody></table></div>
        </div>
      </div>`;
    }).join('');

    return cabecalho + `
      <div class="card" style="padding:12px 14px;margin-bottom:16px">
        <div class="toolbar" style="padding:0;border:0">
          <div class="input-icon search-mini">${icon('search','ico ico-sm')}
            <input class="input" id="buscaCodigo" placeholder="Buscar por nome, código ou facilidade…">
          </div>
          <span class="grow"></span>
          <span class="small muted">${ativos} de ${this._itens.length} habilitados ·
            ${emChamada} valem durante a chamada</span>
        </div>
      </div>
      ${blocos}
      <div id="semCodigo" hidden>${vazio('search', 'Nenhum código com esse termo',
        'Procure pelo que a facilidade faz — "desvio", "gravar", "fila".')}</div>`;
  },

  mount(ctx) {
    const itens = this._itens || [];
    const busca = document.getElementById('buscaCodigo');
    const linhas = [...document.querySelectorAll('tr[data-id]')];
    const cartoes = [...document.querySelectorAll('[data-categoria]')];
    const semCodigo = document.getElementById('semCodigo');

    const aplicar = () => {
      const t = (busca?.value || '').trim().toLowerCase();
      let achou = 0;
      linhas.forEach(l => {
        const ok = !t || l.dataset.busca.includes(t);
        l.hidden = !ok;
        if (ok) achou++;
      });
      // Categoria sem nenhuma linha visível some junto, senão fica um card vazio.
      cartoes.forEach(c => {
        c.hidden = ![...c.querySelectorAll('tr[data-id]')].some(l => !l.hidden);
      });
      if (semCodigo) semCodigo.hidden = achou > 0;
    };
    busca?.addEventListener('input', aplicar);

    document.querySelectorAll('[data-liga]').forEach(sw => sw.onchange = async () => {
      const c = itens.find(x => String(x.id) === sw.dataset.liga);
      try {
        await Api.put(`/codigos-recurso/${c.id}`, { ativo: sw.checked ? 1 : 0 });
        c.ativo = sw.checked ? 1 : 0;
        toast(sw.checked
          ? `${c.codigo} habilitado. Aplique as configurações para valer no Asterisk.`
          : `${c.codigo} desabilitado. Aplique as configurações para valer no Asterisk.`, 'ok');
      } catch (e) {
        sw.checked = !sw.checked;
        toast(e.message, 'err');
      }
    });

    const formulario = item => {
      const novo = !item;
      const c = item || { tipo: 'dialplan', ativo: 1, categoria: 'core', ordem: 100 };
      const campos = [
        { campo: 'codigo', label: 'Código discado', obrigatorio: true, mono: true, placeholder: '*8',
          padraoValido: /^[*#0-9]{1,12}$/,
          mensagemPadrao: 'use só dígitos, * e #',
          ajuda: 'Comece com * ou # para não colidir com número de ramal.' },
        { campo: 'nome', label: 'Facilidade', obrigatorio: true, largura: 'full',
          somenteLeitura: !novo },
        ...(novo ? [
          { campo: 'chave', label: 'Chave interna', obrigatorio: true, mono: true,
            padraoValido: /^[a-z0-9_]+$/, mensagemPadrao: 'letras minúsculas, números e sublinhado',
            ajuda: 'O gerador só sabe montar o dialplan das chaves que ele conhece. Uma chave nova entra no catálogo, mas o contexto dela você escreve em extensions_custom.conf.' },
          { campo: 'categoria', label: 'Categoria', tipo: 'select',
            opcoes: CATEGORIAS_CODIGO.map(x => ({ valor: x.chave, rotulo: x.rotulo })) },
          { campo: 'tipo', label: 'Onde vale', tipo: 'select',
            opcoes: [{ valor: 'dialplan', rotulo: 'Ao discar' },
                     { valor: 'featuremap', rotulo: 'Apertado durante a chamada' }] }
        ] : []),
        { campo: 'argumento', label: 'O que se disca depois', placeholder: 'ramal, destino, fila…',
          somenteLeitura: !novo,
          ajuda: 'Em branco quando o código é discado sozinho.' },
        { campo: 'descricao', label: 'O que ele faz', tipo: 'textarea', largura: 'full',
          somenteLeitura: !novo },
        { campo: 'ativo', label: 'Habilitado', tipo: 'switch', padrao: 1 }
      ];

      Drawer.open({
        titulo: novo ? 'Novo código de recurso' : `${c.codigo} — ${c.nome}`,
        sub: novo
          ? 'Para uma facilidade escrita por você no extensions_custom.conf.'
          : `Só o código discado e o estado mudam aqui — o que a facilidade faz é fixo
             (chave <span class="mono">${esc(c.chave)}</span>).`,
        corpo: `<div class="form-grid">${campos.map(x => campoHtml(x, c)).join('')}</div>
          ${novo ? '' : `<p class="hint" style="margin-top:14px">
            Depois de salvar, use Aplicar configurações para o Asterisk assumir o código novo.</p>`}`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Salvar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          if (!validarCampos(dw, campos)) return;

          const dados = {};
          campos.forEach(x => {
            const el = dw.querySelector(`[name="${x.campo}"]`);
            if (!el || (x.somenteLeitura && !novo)) return;
            dados[x.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
          });

          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Salvando…';
          try {
            if (novo) await Api.post('/codigos-recurso', dados);
            else await Api.put(`/codigos-recurso/${c.id}`, dados);
            Drawer.close();
            toast('Salvo. Aplique as configurações para valer no Asterisk.', 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Salvar';
            if (e.status === 409) marcarErro(dw, 'codigo',
              'Já existe outro código igual a este. Escolha um diferente.');
            else if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    };

    document.getElementById('novoCodigo')?.addEventListener('click', () => formulario(null));
    document.querySelectorAll('[data-editar]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editar)));
  }
};

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
    pagina._anuncios = (await Api.get('/anuncios', { limite: 200 }).catch(() => ({ dados: [] }))).dados;
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
    { campo: 'anuncio_id', label: 'Anúncio (se escolher tocar um)', tipo: 'select',
      opcoes: opcoesAnuncio(pagina._anuncios, 'padrão do sistema'),
      ajuda: (pagina._anuncios || []).length
        ? 'O padrão do sistema é a mensagem de número fora de serviço do Asterisk.'
        : 'Nenhum anúncio criado ainda — vai tocar a mensagem de número fora de serviço do Asterisk. Crie os seus em Aplicações › Anúncios.' },
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

/* ------------------------- Administrador · Certificados ------------------------- */

/** Como o prazo de validade aparece: o que vence logo tem que saltar aos olhos. */
function validadeBadge(dias, validoAte) {
  if (dias === null || dias === undefined) return '<span class="badge">sem data</span>';
  const quando = validoAte ? ` <span class="dim">(${dataHora(validoAte).slice(0, 10)})</span>` : '';
  if (dias < 0)  return `<span class="badge badge-danger">Vencido há ${Math.abs(dias)} d</span>${quando}`;
  if (dias <= 15) return `<span class="badge badge-danger">Vence em ${dias} d</span>${quando}`;
  if (dias <= 45) return `<span class="badge badge-warn">Vence em ${dias} d</span>${quando}`;
  return `<span class="badge badge-ok">${dias} dias</span>${quando}`;
}

const ESTADO_SERVICO = {
  fabrica:  '<span class="badge">Autoassinado de fábrica</span>',
  aplicado: '<span class="badge badge-ok"><i class="dot"></i>Aplicado</span>',
  pendente: '<span class="badge badge-info"><i class="dot dot-pulse"></i>Aplicando…</span>',
  falha:    '<span class="badge badge-danger">Falhou</span>'
};

const ICO_SERVICO = { web: 'globe', asterisk: 'phone', janus: 'wifi' };

PAGES['admin.certificados'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/certificados'); }
    catch (e) { return pageHead('Certificados', '') + blocoErro(e); }
    this._d = d;

    const prontos = d.dados.filter(c => c.estado === 'pronto');

    const acoes = ctx.can('criar')
      ? `<button class="btn btn-outline btn-sm" id="novoCsr">${icon('file','ico ico-sm')} Novo pedido</button>
         <button class="btn btn-outline btn-sm" id="novoAuto">${icon('key','ico ico-sm')} Autoassinado</button>
         <button class="btn btn-primary btn-sm" id="enviarCert">${icon('upload','ico ico-sm')} Enviar certificado</button>`
      : readOnlyNote(ctx);

    const cabecalho = pageHead('Certificados',
      'O par TLS que cada serviço apresenta. Envie um certificado e escolha onde ele vale.',
      `${d.aplicando ? '<span class="badge badge-info"><i class="dot dot-pulse"></i>Aplicando</span>' : ''} ${acoes}`);

    if (!d.openssl) {
      return cabecalho + `<div class="card"><div class="card-body">
        ${vazio('alert', 'O servidor está sem a extensão openssl do PHP',
                'Sem ela a API não consegue ler nem gerar certificados. Instale php8.4-openssl e recarregue o PHP-FPM.')}
      </div></div>`;
    }

    // ---------- serviços ----------
    const cartoes = d.servicos.map(s => {
      const opcoes = [
        `<option value="fabrica" ${s.certificado_id ? '' : 'selected'}>Certificado de fábrica (autoassinado)</option>`,
        ...prontos.map(c => `<option value="${c.id}" ${String(c.id) === String(s.certificado_id) ? 'selected' : ''}>
             ${esc(c.nome)}${c.cn && c.cn !== c.nome ? ` — ${esc(c.cn)}` : ''}</option>`)
      ].join('');

      return `<div class="card" style="padding:16px" data-servico-card="${s.servico}">
        <div class="row-between" style="margin-bottom:12px">
          <div class="row gap-8">
            <span class="k-ico" style="background:var(--brand-soft);color:var(--brand)">
              ${icon(ICO_SERVICO[s.servico] || 'lock')}</span>
            <div><b>${esc(s.rotulo)}</b>
              <div class="tiny muted mono">${esc(s.destino)}</div></div>
          </div>
          ${ESTADO_SERVICO[s.estado] || ''}
        </div>

        <div class="field" style="margin-bottom:10px">
          <label class="label">Certificado em uso</label>
          <select class="select" data-servico="${s.servico}" data-atual="${s.certificado_id || 'fabrica'}"
                  ${ctx.can('reiniciar') ? '' : 'disabled'}>${opcoes}</select>
        </div>

        <div class="tiny muted">
          ${s.certificado_id
            ? `${esc(s.cn || s.certificado_nome)} · ${validadeBadge(s.dias, s.valido_ate)}`
            : 'Gerado na instalação. Funciona, mas o navegador e os telefones avisam que não confiam nele.'}
        </div>
        ${s.estado === 'falha' && s.saida
          ? `<div class="aviso-form" style="margin:10px 0 0">${icon('alert','ico')}
               <div><b>A última tentativa falhou</b><div class="tiny">${esc(s.saida)}</div>
               <div class="tiny muted">O certificado anterior continua no ar.</div></div></div>`
          : ''}
      </div>`;
    }).join('');

    // ---------- cofre ----------
    const linhas = d.dados.map(c => {
      const aguardando = c.estado === 'aguardando_assinatura';
      const usos = (c.servicos || []).map(s =>
        `<span class="chip">${esc({ web: 'web', asterisk: 'SIP TLS', janus: 'Janus' }[s] || s)}</span>`).join(' ');

      return `<tr>
        <td><b>${esc(c.nome)}</b>
          ${c.descricao ? `<div class="tiny muted">${esc(c.descricao)}</div>` : ''}
          <div class="tiny muted">${esc({ upload: 'enviado', autoassinado: 'gerado aqui', csr: 'pedido gerado aqui' }[c.origem] || c.origem)}
            ${c.enviado_por_nome ? ` por ${esc(c.enviado_por_nome)}` : ''}</div></td>
        <td>
          ${c.cn ? `<span class="mono small">${esc(c.cn)}</span>` : '<span class="muted">—</span>'}
          ${c.san ? `<div class="tiny muted">${esc(String(c.san).split('\n').slice(0, 3).join(' · '))}</div>` : ''}
        </td>
        <td class="small dim">${aguardando ? '—'
          : Number(c.autoassinado) ? 'autoassinado'
          : esc(String(c.emissor || '').replace(/^.*?CN=/, '') || c.emissor || '—')}
          ${Number(c.tem_cadeia) ? '<span class="badge badge-brand">com cadeia</span>' : ''}</td>
        <td class="small dim">${esc(c.algoritmo || '—')}</td>
        <td>${aguardando
          ? '<span class="badge badge-warn">Aguardando assinatura</span>'
          : validadeBadge(c.dias, c.valido_ate)}</td>
        <td>${usos || '<span class="tiny muted">não usado</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          <button class="btn btn-ghost btn-sm btn-icon" data-tip="${aguardando ? 'Ver o pedido' : 'Ver o certificado'}"
                  data-pem="${c.id}">${icon('eye','ico ico-sm')}</button>
          ${aguardando && ctx.can('editar')
            ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Concluir com o certificado emitido"
                       data-assinar="${c.id}">${icon('checkCirc','ico ico-sm')}</button>` : ''}
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Renomear"
                       data-editar-cert="${c.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
                       data-excluir-cert="${c.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td>
      </tr>`;
    }).join('');

    const cofre = d.dados.length ? `
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Nome</th><th>Nome no certificado</th><th>Emissor</th><th>Chave</th>
                   <th>Validade</th><th>Em uso</th><th></th></tr></thead>
        <tbody>${linhas}</tbody></table></div>`
      : vazio('lock', 'Nenhum certificado no cofre',
              `Envie o par que a sua autoridade emitiu, ou gere um pedido aqui e mande assinar.
               Enquanto isso os serviços seguem com o autoassinado da instalação.`,
              ctx.can('criar') ? '<button class="btn btn-primary btn-sm" id="enviarCert">Enviar o primeiro</button>' : '');

    return cabecalho + `
      ${d.cofre_ok ? '' : `<div class="aviso-form">${icon('alert','ico')}
        <div><b>O cofre de certificados não está gravável</b>
          <div class="tiny">A API não consegue escrever em <span class="mono">${esc(d.cofre)}</span>.
            Rode o playbook do Ansible para criar o diretório com o dono certo.</div></div></div>`}

      <div class="grid g-3" style="margin-bottom:16px">${cartoes}</div>

      ${ctx.can('reiniciar') ? `<div class="card" style="padding:14px 18px;margin-bottom:16px">
        <div class="row-between wrap gap-12">
          <div class="tiny muted" id="resumoMudancas">
            Troque o certificado de um serviço acima e aplique. Se algo der errado,
            o sistema devolve sozinho o certificado anterior.
          </div>
          <button class="btn btn-primary btn-sm" id="aplicarCerts" disabled>
            ${icon('refresh','ico ico-sm')} Aplicar nos serviços</button>
        </div>
      </div>` : ''}

      <div class="card">
        <div class="card-head"><div><div class="card-title">Cofre de certificados</div>
          <div class="card-sub">Os arquivos ficam em <span class="mono">${esc(d.cofre)}</span>,
            legíveis só pela API. A chave privada nunca é devolvida pelo navegador.</div></div></div>
        <div class="card-body tight">${cofre}</div>
      </div>`;
  },

  mount(ctx) {
    const d = this._d || { dados: [], servicos: [] };

    // ---------- aplicar nos serviços ----------
    const selects = [...document.querySelectorAll('[data-servico]')];
    const botaoAplicar = document.getElementById('aplicarCerts');
    const resumo = document.getElementById('resumoMudancas');

    const mudancas = () => selects.filter(s => s.value !== s.dataset.atual);

    const revisar = () => {
      if (!botaoAplicar) return;
      const m = mudancas();
      botaoAplicar.disabled = m.length === 0;
      if (!resumo) return;
      resumo.innerHTML = m.length === 0
        ? `Troque o certificado de um serviço acima e aplique. Se algo der errado,
           o sistema devolve sozinho o certificado anterior.`
        : `<b>${m.length === 1 ? '1 serviço' : `${m.length} serviços`} para aplicar:</b> `
          + m.map(s => esc(s.closest('[data-servico-card]').querySelector('b').textContent)).join(', ')
          + '. O nginx é validado antes de recarregar; o Janus reinicia.';
    };

    selects.forEach(s => s.addEventListener('change', revisar));
    revisar();

    botaoAplicar?.addEventListener('click', async ev => {
      const m = mudancas();
      if (!m.length) return;

      const mexeNaWeb = m.some(s => s.dataset.servico === 'web');
      const ok = await Modal.confirm({
        titulo: 'Aplicar nos serviços?',
        texto: mexeNaWeb
          ? `A interface web vai recarregar o nginx com o novo certificado. Se ele for recusado,
             o anterior volta sozinho e nada sai do ar — mas o navegador pode pedir para você
             aceitar o certificado novo.`
          : 'Os serviços escolhidos vão recarregar para assumir o novo certificado.',
        ok: 'Aplicar'
      });
      if (!ok) return;

      const servicos = {};
      m.forEach(s => { servicos[s.dataset.servico] = s.value === 'fabrica' ? null : Number(s.value); });

      const botao = ev.currentTarget;
      botao.disabled = true;
      botao.innerHTML = '<span class="spin"></span> Aplicando…';
      try {
        const r = await Api.post('/certificados/aplicar', { servicos });
        toast(r.detalhe || 'Aplicando.', r.aplicado ? 'ok' : 'warn');
        setTimeout(() => App.route(), 4000);
      } catch (e) {
        botao.disabled = false;
        botao.textContent = 'Aplicar nos serviços';
        toast(e.message, 'err');
      }
    });

    // ---------- enviar certificado ----------
    const camposIdentificacao = [
      { campo: 'nome', label: 'Nome no console', placeholder: 'Certificado do PABX',
        ajuda: 'Só para você reconhecer na lista. Em branco, usamos o nome do certificado.' },
      { campo: 'descricao', label: 'Observação', largura: 'full' }
    ];

    document.querySelectorAll('#enviarCert').forEach(b => b.onclick = () => {
      Drawer.open({
        titulo: 'Enviar certificado',
        sub: 'Envie os arquivos que a autoridade entregou, ou cole o conteúdo em PEM.',
        corpo: `
          <div class="grid" style="gap:16px">
            <div class="field full" data-campo="certificado">
              <label class="label">Certificado *</label>
              <input class="input" type="file" name="arquivo_cert" accept=".crt,.pem,.cer,.txt">
              <span class="hint">Arquivo .crt, .pem ou .cer. Se ele já vier com a cadeia junto, tudo bem.</span>
              <textarea class="textarea mono" name="certificado" rows="3"
                        placeholder="…ou cole aqui: -----BEGIN CERTIFICATE-----"></textarea>
            </div>
            <div class="field full" data-campo="chave">
              <label class="label">Chave privada *</label>
              <input class="input" type="file" name="arquivo_chave" accept=".key,.pem,.txt">
              <span class="hint">O arquivo .key do mesmo pedido. Ele fica só no servidor e nunca volta pela tela.</span>
              <textarea class="textarea mono" name="chave" rows="3"
                        placeholder="…ou cole aqui: -----BEGIN PRIVATE KEY-----"></textarea>
            </div>
            <div class="field full" data-campo="cadeia">
              <label class="label">Cadeia intermediária</label>
              <input class="input" type="file" name="arquivo_cadeia" accept=".crt,.pem,.cer,.txt">
              <span class="hint">Opcional. Sem ela, alguns navegadores e telefones recusam o certificado.</span>
            </div>
            ${campoHtml({ campo: 'senha_chave', label: 'Senha da chave privada', tipo: 'password',
                          ajuda: 'Só se a chave estiver protegida por senha.' }, {})}
            ${camposIdentificacao.map(c => campoHtml(c, {})).join('')}
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Enviar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          limparErros(dw);

          const arq = n => dw.querySelector(`[name="${n}"]`)?.files?.[0] || null;
          const txt = n => (dw.querySelector(`[name="${n}"]`)?.value || '').trim();

          const problemas = [];
          if (!arq('arquivo_cert') && !txt('certificado')) {
            marcarErro(dw, 'certificado', 'Envie o arquivo ou cole o certificado em PEM.');
            problemas.push('Certificado');
          }
          if (!arq('arquivo_chave') && !txt('chave')) {
            marcarErro(dw, 'chave', 'Envie o arquivo .key ou cole a chave em PEM.');
            problemas.push('Chave privada');
          }
          if (problemas.length) { avisoFormulario(dw, problemas); return; }

          const fd = new FormData();
          if (arq('arquivo_cert'))   fd.append('certificado', arq('arquivo_cert'));
          else                       fd.append('certificado', txt('certificado'));
          if (arq('arquivo_chave'))  fd.append('chave', arq('arquivo_chave'));
          else                       fd.append('chave', txt('chave'));
          if (arq('arquivo_cadeia')) fd.append('cadeia', arq('arquivo_cadeia'));
          fd.append('senha_chave', txt('senha_chave'));
          fd.append('nome', txt('nome'));
          fd.append('descricao', txt('descricao'));

          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Conferindo…';
          try {
            const r = await Api.upload('/certificados', fd);
            Drawer.close();
            toast(`Certificado de ${r.cn || 'sem CN'} guardado — ${r.dias} dias de validade.`, 'ok');
            (r.avisos || []).forEach(a => toast(a, 'warn'));
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Enviar';
            if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    });

    // ---------- gerar autoassinado / pedido ----------
    const camposNome = padrao => [
      { campo: 'cn', label: 'Nome principal', obrigatorio: true, mono: true, largura: 'full',
        padrao, placeholder: 'pabx.suaempresa.com.br',
        ajuda: 'O endereço pelo qual o PABX é acessado. É o que o navegador confere.' },
      { campo: 'san', label: 'Outros nomes e IPs', tipo: 'textarea', largura: 'full',
        placeholder: 'www.suaempresa.com.br\n192.168.1.10',
        ajuda: 'Um por linha. Todo endereço usado para chegar ao PABX precisa estar aqui.' },
      { campo: 'organizacao', label: 'Organização', placeholder: 'Sua Empresa Ltda' },
      { campo: 'unidade', label: 'Setor', placeholder: 'TI' },
      { campo: 'cidade', label: 'Cidade', placeholder: 'São Paulo' },
      { campo: 'estado', label: 'Estado (UF)', placeholder: 'SP' },
      { campo: 'pais', label: 'País', padrao: 'BR' },
      { campo: 'nome', label: 'Nome no console', largura: 'full',
        ajuda: 'Em branco, usamos o nome principal.' }
    ];

    const colher = (dw, campos) => {
      const dados = {};
      campos.forEach(c => { dados[c.campo] = (dw.querySelector(`[name="${c.campo}"]`)?.value || '').trim(); });
      return dados;
    };

    document.getElementById('novoAuto')?.addEventListener('click', () => {
      const campos = [
        ...camposNome(location.hostname),
        { campo: 'dias', label: 'Validade em dias', tipo: 'number', padrao: 825,
          ajuda: 'Navegadores rejeitam certificados públicos acima de 398 dias; para uso interno isso não vale.' }
      ];
      Drawer.open({
        titulo: 'Gerar certificado autoassinado',
        sub: 'Serve para rede interna e laboratório. Os navegadores vão pedir para confiar nele.',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, {})).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Gerar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          if (!validarCampos(dw, campos)) return;
          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Gerando…';
          try {
            const r = await Api.post('/certificados/autoassinado', colher(dw, campos));
            Drawer.close();
            toast(`Certificado gerado para ${r.cn} — ${r.dias} dias.`, 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Gerar';
            if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    });

    document.getElementById('novoCsr')?.addEventListener('click', () => {
      const campos = camposNome(location.hostname);
      Drawer.open({
        titulo: 'Novo pedido de certificado',
        sub: 'Geramos a chave privada aqui e devolvemos o pedido (CSR) para você mandar à autoridade.',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, {})).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Gerar pedido</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          if (!validarCampos(dw, campos)) return;
          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Gerando…';
          try {
            const r = await Api.post('/certificados/csr', colher(dw, campos));
            Drawer.close();
            mostrarPem('Pedido de assinatura (CSR)',
              `Mande este texto para a autoridade certificadora. A chave privada ficou guardada
               no servidor; quando o certificado voltar, use o botão de concluir na lista.`, r.csr);
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Gerar pedido';
            if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    });

    // ---------- ver PEM ----------
    function mostrarPem(titulo, texto, pem) {
      Drawer.open({
        titulo,
        sub: texto,
        corpo: `<textarea class="textarea mono" id="pemTexto" rows="18" readonly
                          style="font-size:11.5px;line-height:1.45">${esc(pem)}</textarea>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Fechar</button>
                 <button class="btn btn-primary" id="copiarPem">Copiar</button>`,
        aoAbrir: dw => dw.querySelector('#copiarPem').onclick = async () => {
          const area = dw.querySelector('#pemTexto');
          try { await navigator.clipboard.writeText(area.value); toast('Copiado.', 'ok'); }
          catch { area.select(); document.execCommand('copy'); toast('Copiado.', 'ok'); }
        }
      });
    }

    document.querySelectorAll('[data-pem]').forEach(b => b.onclick = async () => {
      const c = d.dados.find(x => String(x.id) === b.dataset.pem);
      try {
        const r = await Api.get(`/certificados/${c.id}/pem`);
        mostrarPem(r.tipo === 'csr' ? `Pedido — ${c.nome}` : `Certificado — ${c.nome}`,
                   r.tipo === 'csr'
                     ? 'Mande este texto para a autoridade certificadora.'
                     : 'Esta é a parte pública. A chave privada não sai do servidor.',
                   r.pem);
      } catch (e) { toast(e.message, 'err'); }
    });

    // ---------- concluir um pedido ----------
    document.querySelectorAll('[data-assinar]').forEach(b => b.onclick = () => {
      const c = d.dados.find(x => String(x.id) === b.dataset.assinar);
      Drawer.open({
        titulo: `Concluir ${c.nome}`,
        sub: 'Cole ou envie o certificado que a autoridade emitiu para este pedido.',
        corpo: `
          <div class="grid" style="gap:16px">
            <div class="field full" data-campo="certificado">
              <label class="label">Certificado emitido *</label>
              <input class="input" type="file" name="arquivo_cert" accept=".crt,.pem,.cer,.txt">
              <textarea class="textarea mono" name="certificado" rows="5"
                        placeholder="-----BEGIN CERTIFICATE-----"></textarea>
              <span class="hint">Conferimos se ele bate com a chave privada guardada aqui.</span>
            </div>
            <div class="field full" data-campo="cadeia">
              <label class="label">Cadeia intermediária</label>
              <input class="input" type="file" name="arquivo_cadeia" accept=".crt,.pem,.cer,.txt">
              <span class="hint">Opcional, mas quase sempre necessária para os telefones confiarem.</span>
            </div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Concluir</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          limparErros(dw);
          const arqCert = dw.querySelector('[name="arquivo_cert"]').files[0];
          const colado = dw.querySelector('[name="certificado"]').value.trim();

          if (!arqCert && !colado) {
            marcarErro(dw, 'certificado', 'Envie o arquivo ou cole o certificado em PEM.');
            avisoFormulario(dw, ['Certificado emitido']);
            return;
          }

          const fd = new FormData();
          fd.append('certificado', arqCert || colado);
          const cadeia = dw.querySelector('[name="arquivo_cadeia"]').files[0];
          if (cadeia) fd.append('cadeia', cadeia);

          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Conferindo…';
          try {
            await Api.upload(`/certificados/${c.id}/assinar`, fd);
            Drawer.close();
            toast('Certificado concluído. Agora é só escolher em qual serviço ele vale.', 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Concluir';
            if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    });

    // ---------- renomear ----------
    document.querySelectorAll('[data-editar-cert]').forEach(b => b.onclick = () => {
      const c = d.dados.find(x => String(x.id) === b.dataset.editarCert);
      const campos = [
        { campo: 'nome', label: 'Nome no console', obrigatorio: true, largura: 'full' },
        { campo: 'descricao', label: 'Observação', tipo: 'textarea', largura: 'full' }
      ];
      Drawer.open({
        titulo: `Renomear ${c.nome}`,
        sub: 'Muda só como ele aparece aqui — o certificado em si não é tocado.',
        corpo: `<div class="form-grid">${campos.map(x => campoHtml(x, c)).join('')}</div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>Salvar</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async () => {
          if (!validarCampos(dw, campos)) return;
          try {
            await Api.put(`/certificados/${c.id}`, {
              nome: dw.querySelector('[name="nome"]').value.trim(),
              descricao: dw.querySelector('[name="descricao"]').value.trim()
            });
            Drawer.close(); toast('Atualizado.', 'ok'); App.route();
          } catch (e) { toast(e.message, 'err'); }
        }
      });
    });

    // ---------- excluir ----------
    document.querySelectorAll('[data-excluir-cert]').forEach(b => b.onclick = async () => {
      const c = d.dados.find(x => String(x.id) === b.dataset.excluirCert);
      const ok = await Modal.confirm({
        titulo: `Excluir ${c.nome}?`,
        texto: `O certificado e a chave privada são apagados do servidor e não há como recuperá-los.
                Se algum serviço ainda estiver usando este par, a exclusão é recusada.`,
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/certificados/${c.id}`); toast('Certificado excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Estacionamento ------------------------- */
PAGES['apps.estacionamento'] = {
  async render(ctx) {
    let r, destinos;
    try {
      [r, destinos] = await Promise.all([
        Api.get('/estacionamentos', { limite: 100 }),
        opcoesDestino()
      ]);
    } catch (e) { return pageHead('Estacionamento de Chamadas', '') + blocoErro(e); }

    this._itens = r.dados || [];
    this._destinos = destinos;

    const cabecalho = pageHead('Estacionamento de Chamadas',
      `Transferir a chamada para o número do lote a deixa numa vaga, e o PABX fala qual.
       Qualquer ramal retoma discando o número da vaga.`,
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-novo-lote>${icon('plus','ico ico-sm')} Novo lote</button>`
        : readOnlyNote(ctx));

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('package', 'Nenhum lote de estacionamento',
        'O lote padrão é criado na instalação. Rode o playbook do Ansible para recriá-lo.',
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-novo-lote>Criar um lote</button>' : '')}
      </div>`;
    }

    return cabecalho + `<div class="agenda-grid larga">${this._itens.map(l => `
      <div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:10px">
          <div class="row gap-10">
            <span class="k-ico" style="background:var(--brand-soft);color:var(--brand)">${icon('package')}</span>
            <div><b>${esc(l.nome)}</b>
              ${Number(l.padrao) ? ' <span class="badge badge-brand">padrão</span>' : ''}
              ${l.descricao ? `<div class="tiny muted">${esc(l.descricao)}</div>` : ''}</div>
          </div>
          ${Number(l.ativo)
            ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
            : '<span class="badge">Parado</span>'}
        </div>

        <div class="contato-dados" style="margin-bottom:10px">
          <div class="row gap-6 small">${icon('phone','ico ico-sm')}
            <span>Transfira para <b class="mono">${esc(l.numero_estacionar)}</b> para estacionar</span></div>
          <div class="row gap-6 small">${icon('grid','ico ico-sm')}
            <span>Vagas <b class="mono">${esc(l.vaga_inicio)}</b> a <b class="mono">${esc(l.vaga_fim)}</b></span></div>
          <div class="row gap-6 small">${icon('clock','ico ico-sm')}
            <span>Espera ${l.tempo_segundos}s e ${Number(l.volta_para_origem)
              ? 'volta a tocar para quem estacionou'
              : `vai para <b>${esc(descreveDestino(l.destino_tipo, l.destino_valor, this._destinos))}</b>`}</span></div>
        </div>

        <div class="contato-acoes">
          ${Number(l.padrao) ? '<span class="tiny muted">Os códigos *85 e *86 usam este lote.</span>' : ''}
          <span class="grow"></span>
          ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-editar-lote="${l.id}">
            ${icon('edit','ico ico-sm')} Editar</button>` : ''}
          ${ctx.can('excluir') && !Number(l.padrao) ? `<button class="btn btn-ghost btn-sm btn-icon"
            data-tip="Excluir" data-excluir-lote="${l.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </div>
      </div>`).join('')}</div>`;
  },

  mount(ctx) {
    const pagina = this;
    const itens = this._itens || [];

    const formulario = item => {
      const novo = !item;
      const l = item || { tempo_segundos: 45, tempo_volta: 30, volta_para_origem: 1,
                          avisar_vaga: 1, primeira_vaga_livre: 1, musica_espera: 'default', ativo: 1 };
      const campos = [
        { campo: 'nome', label: 'Nome do lote', obrigatorio: true, mono: true,
          somenteLeitura: !novo && Number(l.padrao) === 1,
          padraoValido: /^[a-z0-9_-]{2,30}$/i, mensagemPadrao: 'letras, números, hífen e sublinhado',
          ajuda: 'Vira o nome do lote no Asterisk.' },
        { campo: 'descricao', label: 'Descrição', largura: 'full' },
        { campo: 'numero_estacionar', label: 'Número para estacionar', obrigatorio: true, mono: true,
          placeholder: '70',
          ajuda: 'Transferir a chamada para este número a põe numa vaga.' },
        { campo: 'vaga_inicio', label: 'Primeira vaga', obrigatorio: true, mono: true, placeholder: '71' },
        { campo: 'vaga_fim', label: 'Última vaga', obrigatorio: true, mono: true, placeholder: '80',
          ajuda: 'Quem retoma disca o número da vaga que o PABX falou.' },
        { campo: 'tempo_segundos', label: 'Tempo máximo na vaga (s)', tipo: 'number', padrao: 45 },
        { campo: 'musica_espera', label: 'Música na vaga', padrao: 'default' },
        { campo: 'avisar_vaga', label: 'Falar o número da vaga a quem estacionou', tipo: 'switch',
          padrao: 1, largura: 'full' },
        { campo: 'primeira_vaga_livre', label: 'Usar sempre a primeira vaga livre', tipo: 'switch',
          padrao: 1, largura: 'full',
          ajuda: 'Desligado, o PABX segue para a vaga seguinte a cada chamada, o que evita repetir número logo depois de alguém retomar.' },
        { campo: 'volta_para_origem', label: 'Estourado o tempo, voltar para quem estacionou',
          tipo: 'switch', padrao: 1, largura: 'full' },
        { campo: 'tempo_volta', label: 'Tempo de toque na volta (s)', tipo: 'number', padrao: 30 },
        { campo: 'ativo', label: 'Lote ativo', tipo: 'switch', padrao: 1 }
      ];

      Drawer.open({
        titulo: novo ? 'Novo lote de estacionamento' : `Lote ${l.nome}`,
        sub: 'Estacionar é deixar a chamada esperando numa vaga para outra pessoa assumir.',
        wide: true,
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, l)).join('')}</div>
          <div class="secao-form" data-destino-bloco>
            <b>Se ninguém retomar a chamada</b>
            <div class="form-grid" style="margin-top:10px">
              ${destinoSelect('destino', l, pagina._destinos,
                  { label: 'Para onde ela vai', largura: 'full',
                    rotuloVazio: '— desligar —',
                    ajuda: 'Só vale quando a chamada não volta para quem estacionou.' })}
            </div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar lote' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          // O destino só importa quando a chamada não volta para a origem.
          const volta = dw.querySelector('[name="volta_para_origem"]');
          const bloco = dw.querySelector('[data-destino-bloco]');
          const revisar = () => { bloco.style.opacity = volta.checked ? '.45' : ''; };
          volta.addEventListener('change', revisar);
          revisar();

          dw.querySelector('[data-ok]').onclick = async ev => {
            if (!validarCampos(dw, campos)) return;

            const dados = {};
            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (el && !el.disabled) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
            });
            const { tipo, valor } = lerDestino(dw.querySelector('[name="destino"]'));
            dados.destino_tipo = tipo;
            dados.destino_valor = valor;

            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              if (novo) await Api.post('/estacionamentos', dados);
              else await Api.put(`/estacionamentos/${l.id}`, dados);
              Drawer.close();
              toast('Lote salvo. Aplique as configurações.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Criar lote' : 'Salvar';
              if (e.status === 409) marcarErro(dw, 'nome', 'Já existe um lote com este nome.');
              else if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
              else toast(e.message, 'err');
            }
          };
        }
      });
    };

    document.querySelectorAll('[data-novo-lote]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar-lote]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editarLote)));
    document.querySelectorAll('[data-excluir-lote]').forEach(b => b.onclick = async () => {
      const l = itens.find(x => String(x.id) === b.dataset.excluirLote);
      const ok = await Modal.confirm({
        titulo: `Excluir o lote ${l.nome}?`,
        texto: 'O número de estacionar deixa de atender.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/estacionamentos/${l.id}`); toast('Lote excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Chamadas de Despertar ------------------------- */
PAGES['apps.despertar'] = {
  async render(ctx) {
    let r, ramais, anuncios;
    try {
      [r, ramais, anuncios] = await Promise.all([
        Api.get('/despertadores', { limite: 300 }),
        Api.get('/ramais', { limite: 500 }).catch(() => ({ dados: [] })),
        Api.get('/anuncios', { limite: 200 }).catch(() => ({ dados: [] }))
      ]);
    } catch (e) { return pageHead('Chamadas de Despertar', '') + blocoErro(e); }

    this._itens = r.dados || [];
    this._ramais = (ramais.dados || []).filter(x => Number(x.ativo));
    this._anuncios = anuncios.dados || [];

    const cabecalho = pageHead('Chamadas de Despertar',
      `O PABX liga para o ramal na hora marcada e toca um anúncio. Serve de despertador,
       de lembrete de reunião e de alarme. O usuário também marca o dele pelo telefone, com <span class="mono">*68</span>.`,
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-nova-chamada>${icon('plus','ico ico-sm')} Nova chamada</button>`
        : readOnlyNote(ctx));

    if (!this._ramais.length) {
      return cabecalho + `<div class="card">${vazio('phone', 'Nenhum ramal ativo',
        'Cadastre um ramal em Conectividade › Ramais para poder ligar para ele.')}</div>`;
    }

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('clock', 'Nenhuma chamada programada',
        'Programe uma para daqui a pouco, todo dia no mesmo horário, ou em dias escolhidos da semana.',
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-nova-chamada>Programar a primeira</button>' : '')}
      </div>`;
    }

    const quando = d => {
      const dias = ['dom','seg','ter','qua','qui','sex','sáb'];
      if (d.repeticao === 'uma_vez') {
        return d.quando ? `uma vez, em ${dataHora(d.quando)}` : 'uma vez, sem data';
      }
      const h = String(d.hora || '').slice(0, 5);
      if (d.repeticao === 'diario') return `todo dia às ${h}`;
      const lista = String(d.dias_semana || '').split(',').filter(x => x !== '')
        .map(x => dias[Number(x)]).join(', ');
      return lista ? `${lista} às ${h}` : `às ${h}, sem dia escolhido`;
    };

    const linhas = this._itens.map(d => {
      const r2 = this._ramais.find(x => x.numero === d.ramal);
      const a = this._anuncios.find(x => String(x.id) === String(d.anuncio_id));
      return `<tr data-id="${d.id}" data-busca="${esc(`${d.nome} ${d.ramal}`.toLowerCase())}">
        <td><b>${esc(d.nome)}</b></td>
        <td><b class="mono">${esc(d.ramal)}</b>
          ${r2 ? `<div class="tiny muted">${esc(r2.nome)}</div>`
               : '<div class="tiny"><span class="badge badge-warn">ramal não existe</span></div>'}</td>
        <td>${esc(quando(d))}</td>
        <td>${a ? esc(a.nome) : '<span class="tiny muted">hora falada pelo PABX</span>'}</td>
        <td class="small dim">${d.ultimo_em ? dataHora(d.ultimo_em) : '—'}</td>
        <td>${Number(d.ativo)
          ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>'
          : '<span class="badge">Parada</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar"
            data-editar-desp="${d.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
            data-excluir-desp="${d.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td>
      </tr>`;
    }).join('');

    return cabecalho + `
      <div class="card">
        <div class="toolbar">
          <div class="input-icon search-mini">${icon('search','ico ico-sm')}
            <input class="input" data-filtro placeholder="Buscar por nome ou ramal…">
          </div>
          <span class="grow"></span>
          <span class="small muted" data-contador></span>
        </div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Chamada</th><th>Ramal</th><th>Quando</th><th>Toca</th>
                     <th>Última vez</th><th>Estado</th><th></th></tr></thead>
          <tbody>${linhas}</tbody>
        </table></div>
      </div>`;
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
      if (contador) contador.textContent = `${n} de ${linhas.length} chamadas`;
    };
    filtro?.addEventListener('input', aplicar);
    aplicar();

    const formulario = item => {
      const novo = !item;
      const d = item || { repeticao: 'uma_vez', tentativas: 3, intervalo: 120, ativo: 1 };
      const campos = [
        { campo: 'nome', label: 'Nome', obrigatorio: true, largura: 'full',
          placeholder: 'Acordar o plantão' },
        { campo: 'ramal', label: 'Ligar para o ramal', obrigatorio: true, tipo: 'select', largura: 'full',
          opcoes: [{ valor: '', rotulo: '— escolha o ramal —' },
                   ...pagina._ramais.map(r => ({ valor: r.numero, rotulo: `${r.numero} — ${r.nome}` }))] },
        { campo: 'anuncio_id', label: 'Anúncio a tocar', tipo: 'select', largura: 'full',
          opcoes: opcoesAnuncio(pagina._anuncios, '— só falar a hora —'),
          ajuda: 'Sem anúncio, o PABX diz a hora e desliga.' },
        { campo: 'repeticao', label: 'Repetição', tipo: 'select', largura: 'full',
          opcoes: [{ valor: 'uma_vez', rotulo: 'Uma vez, em data e hora' },
                   { valor: 'diario', rotulo: 'Todo dia, no mesmo horário' },
                   { valor: 'dias_semana', rotulo: 'Em dias escolhidos da semana' }] },
        { campo: 'quando', label: 'Data e hora', tipo: 'datetime-local', largura: 'full' },
        { campo: 'hora', label: 'Horário', tipo: 'time' },
        { campo: 'ativo', label: 'Chamada ativa', tipo: 'switch', padrao: 1 }
      ];

      const dias = [['0','domingo'],['1','segunda'],['2','terça'],['3','quarta'],
                    ['4','quinta'],['5','sexta'],['6','sábado']];
      const marcados = String(d.dias_semana || '').split(',');

      Drawer.open({
        titulo: novo ? 'Nova chamada de despertar' : `Editar ${d.nome}`,
        sub: 'Uma chamada de uma vez só se desliga sozinha depois de tocar.',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, {
            ...d,
            quando: d.quando ? String(d.quando).replace(' ', 'T').slice(0, 16) : '',
            hora: d.hora ? String(d.hora).slice(0, 5) : ''
          })).join('')}</div>
          <div class="field full" data-campo="dias_semana" id="blocoDias">
            <label class="label">Dias da semana</label>
            <div class="row wrap gap-12">
              ${dias.map(([v, r]) => `<label class="check">
                <input type="checkbox" name="dia" value="${v}" ${marcados.includes(v) ? 'checked' : ''}>
                ${r}</label>`).join('')}
            </div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Programar' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          // Cada repetição pede campos diferentes; mostrar todos confunde.
          const rep = dw.querySelector('[name="repeticao"]');
          const campoQuando = dw.querySelector('[data-campo="quando"]');
          const campoHora = dw.querySelector('[data-campo="hora"]');
          const blocoDias = dw.querySelector('#blocoDias');
          const revisar = () => {
            const v = rep.value;
            campoQuando.hidden = v !== 'uma_vez';
            campoHora.hidden = v === 'uma_vez';
            blocoDias.hidden = v !== 'dias_semana';
          };
          rep.addEventListener('change', revisar);
          revisar();

          dw.querySelector('[data-ok]').onclick = async ev => {
            const usados = campos.filter(c =>
              !(c.campo === 'quando' && rep.value !== 'uma_vez')
              && !(c.campo === 'hora' && rep.value === 'uma_vez'));
            if (!validarCampos(dw, usados)) return;

            const dados = {};
            campos.forEach(c => {
              const el = dw.querySelector(`[name="${c.campo}"]`);
              if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
            });
            dados.quando = rep.value === 'uma_vez' ? dados.quando.replace('T', ' ') : '';
            dados.hora = rep.value === 'uma_vez' ? '' : dados.hora;
            dados.dias_semana = rep.value === 'dias_semana'
              ? [...dw.querySelectorAll('[name="dia"]:checked')].map(x => x.value).join(',')
              : '';

            const faltam = [];
            if (rep.value === 'uma_vez' && !dados.quando) faltam.push('Data e hora');
            if (rep.value !== 'uma_vez' && !dados.hora) faltam.push('Horário');
            if (rep.value === 'dias_semana' && !dados.dias_semana) faltam.push('Ao menos um dia da semana');
            if (faltam.length) { avisoFormulario(dw, faltam); return; }

            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              if (novo) await Api.post('/despertadores', dados);
              else await Api.put(`/despertadores/${d.id}`, dados);
              Drawer.close();
              toast('Chamada programada.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Programar' : 'Salvar';
              if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
              else toast(e.message, 'err');
            }
          };
        }
      });
    };

    document.querySelectorAll('[data-nova-chamada]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar-desp]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editarDesp)));
    document.querySelectorAll('[data-excluir-desp]').forEach(b => b.onclick = async () => {
      const d = itens.find(x => String(x.id) === b.dataset.excluirDesp);
      const ok = await Modal.confirm({
        titulo: `Excluir ${d.nome}?`, texto: 'O PABX deixa de ligar.', ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/despertadores/${d.id}`); toast('Excluída.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Siga-me ------------------------- */

/**
 * Siga-me e correio de voz são a mesma coisa por dentro: uma lista de
 * ramais em que se edita um punhado de colunas. O que muda é quais.
 */
function paginaServicoDoRamal(cfg) {
  return {
    async render(ctx) {
      let r;
      try { r = await Api.get('/ramais', { limite: 500 }); }
      catch (e) { return pageHead(cfg.titulo, cfg.sub) + blocoErro(e); }
      this._itens = (r.dados || []).filter(x => Number(x.ativo));

      const cabecalho = pageHead(cfg.titulo, cfg.sub,
        `<span class="badge">${cfg.contar(this._itens)}</span>${readOnlyNote(ctx)}`);

      if (!this._itens.length) {
        return cabecalho + `<div class="card">${vazio('phone', 'Nenhum ramal ativo',
          'Cadastre os ramais em Conectividade › Ramais e eles aparecem aqui.')}</div>`;
      }

      const linhas = this._itens.map(x => `
        <tr data-id="${x.id}" data-busca="${esc(`${x.numero} ${x.nome} ${x.setor || ''}`.toLowerCase())}"
            data-estado="${cfg.ligado(x) ? '1' : '0'}">
          <td><span class="row gap-10"><span class="avatar avatar-sm">${initials(x.nome)}</span>
            <span><b class="mono">${esc(x.numero)}</b> ${esc(x.nome)}
              ${x.setor ? `<div class="tiny muted">${esc(x.setor)}</div>` : ''}</span></span></td>
          ${cfg.colunas(x).map(c => `<td>${c}</td>`).join('')}
          <td class="col-actions"><span class="row-actions">
            ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-editar="${x.id}">
              ${icon('edit','ico ico-sm')} Configurar</button>` : ''}
          </span></td>
        </tr>`).join('');

      return cabecalho + `
        <div class="card">
          <div class="toolbar">
            <div class="input-icon search-mini">${icon('search','ico ico-sm')}
              <input class="input" data-filtro placeholder="Buscar por ramal, nome ou setor…">
            </div>
            <select class="select" data-estado-filtro style="width:200px">
              <option value="">Todos os ramais</option>
              <option value="1">${esc(cfg.rotuloLigado)}</option>
              <option value="0">${esc(cfg.rotuloDesligado)}</option>
            </select>
            <span class="grow"></span>
            <span class="small muted" data-contador></span>
          </div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Ramal</th>${cfg.cabecalhos.map(h => `<th>${h}</th>`).join('')}<th></th></tr></thead>
            <tbody>${linhas}</tbody>
          </table></div>
        </div>`;
    },

    mount(ctx) {
      const pagina = this;
      const itens = this._itens || [];

      const filtro = document.querySelector('[data-filtro]');
      const estado = document.querySelector('[data-estado-filtro]');
      const contador = document.querySelector('[data-contador]');
      const linhas = [...document.querySelectorAll('tr[data-id]')];
      const aplicar = () => {
        const t = (filtro?.value || '').trim().toLowerCase();
        let n = 0;
        linhas.forEach(l => {
          const ok = (!t || l.dataset.busca.includes(t))
                  && (!estado?.value || l.dataset.estado === estado.value);
          l.hidden = !ok;
          if (ok) n++;
        });
        if (contador) contador.textContent = `${n} de ${linhas.length} ramais`;
      };
      filtro?.addEventListener('input', aplicar);
      estado?.addEventListener('change', aplicar);
      aplicar();

      document.querySelectorAll('[data-editar]').forEach(b => b.onclick = () => {
        const x = itens.find(i => String(i.id) === b.dataset.editar);
        const campos = cfg.campos(x, pagina);

        Drawer.open({
          titulo: `${cfg.tituloForm} — ramal ${x.numero}`,
          sub: esc(x.nome),
          corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, x)).join('')}</div>`,
          rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                   <button class="btn btn-primary" data-ok>Salvar</button>`,
          aoAbrir: dw => {
            if (cfg.aoAbrir) cfg.aoAbrir(dw, x);
            dw.querySelector('[data-ok]').onclick = async ev => {
              if (!validarCampos(dw, campos)) return;

              const dados = {};
              campos.forEach(c => {
                const el = dw.querySelector(`[name="${c.campo}"]`);
                if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
              });

              const erro = cfg.conferir ? cfg.conferir(dados) : null;
              if (erro) {
                marcarErro(dw, erro.campo, erro.mensagem);
                avisoFormulario(dw, [erro.mensagem]);
                return;
              }

              const botao = ev.currentTarget;
              botao.disabled = true;
              botao.innerHTML = '<span class="spin"></span> Salvando…';
              try {
                await Api.put(`/ramais/${x.id}`, dados);
                Drawer.close();
                toast('Salvo. Aplique as configurações para valer no Asterisk.', 'ok');
                App.route();
              } catch (e) {
                botao.disabled = false;
                botao.textContent = 'Salvar';
                if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
                else toast(e.message, 'err');
              }
            };
          }
        });
      });
    }
  };
}

PAGES['apps.sigame'] = paginaServicoDoRamal({
  titulo: 'Siga-me',
  sub: `Para onde as chamadas do ramal vão além dele. O destino pode ser outro ramal ou um
        número externo, e o próprio usuário liga e desliga pelo telefone com *21, *22 e *23.`,
  tituloForm: 'Siga-me',
  rotuloLigado: 'Com siga-me ligado',
  rotuloDesligado: 'Sem siga-me',
  cabecalhos: ['Destino', 'Como toca', 'Estado'],
  ligado: x => Number(x.siga_me_ativo) === 1 && x.siga_me,
  contar: itens => {
    const n = itens.filter(x => Number(x.siga_me_ativo) === 1 && x.siga_me).length;
    return `${n} com siga-me ligado`;
  },
  colunas: x => [
    x.siga_me
      ? `<b class="mono">${esc(x.siga_me)}</b>
         <div class="tiny muted">${String(x.siga_me).length > 6 ? 'número externo' : 'ramal ou interno'}</div>`
      : '<span class="muted">—</span>',
    x.siga_me
      ? (x.siga_me_modo === 'depois'
          ? `<span class="badge">depois de ${x.tempo_toque}s sem atender</span>`
          : '<span class="badge">junto com o ramal</span>')
      : '<span class="muted">—</span>',
    Number(x.siga_me_ativo) && x.siga_me
      ? '<span class="badge badge-ok"><i class="dot"></i>Ligado</span>'
      : '<span class="badge">Desligado</span>'
  ],
  campos: () => [
    { campo: 'siga_me', label: 'Destino', largura: 'full', mono: true,
      placeholder: '1002 ou 11988887777',
      ajuda: 'Outro ramal, ou um número externo com DDD. O destino fica guardado mesmo desligado.' },
    { campo: 'siga_me_ativo', label: 'Siga-me ligado', tipo: 'switch',
      ajuda: 'O usuário também liga e desliga pelo telefone, com *21 e *22.' },
    { campo: 'siga_me_modo', label: 'Como tocar', tipo: 'select', largura: 'full',
      opcoes: [{ valor: 'junto', rotulo: 'Junto com o ramal, ao mesmo tempo' },
               { valor: 'depois', rotulo: 'Só depois que o ramal não atender' }] },
    { campo: 'tempo_toque', label: 'Tempo de toque do ramal (s)', tipo: 'number', padrao: 20,
      ajuda: 'Também é o tempo que o ramal toca antes de o siga-me entrar, no modo "depois".' }
  ],
  conferir: d => (Number(d.siga_me_ativo) === 1 && !d.siga_me)
    ? { campo: 'siga_me', mensagem: 'Informe o destino antes de ligar o siga-me.' }
    : null
});

PAGES['apps.correiovoz'] = paginaServicoDoRamal({
  titulo: 'Correio de Voz',
  sub: `A caixa postal de cada ramal: senha, envio por e-mail e limites. Quem não atende cai
        aqui, e o próprio usuário ouve os recados discando *97.`,
  tituloForm: 'Caixa postal',
  rotuloLigado: 'Com caixa postal',
  rotuloDesligado: 'Sem caixa postal',
  cabecalhos: ['Caixa', 'E-mail', 'Limites', 'Estado'],
  ligado: x => Number(x.voicemail) === 1,
  contar: itens => `${itens.filter(x => Number(x.voicemail) === 1).length} com caixa postal`,
  colunas: x => [
    Number(x.voicemail)
      ? `<span class="mono">${esc(x.numero)}@telium</span>`
      : '<span class="muted">—</span>',
    Number(x.voicemail)
      ? (x.email
          ? `${esc(x.email)}${Number(x.vm_email) ? ' <span class="badge badge-ok">anexa o áudio</span>' : ''}`
          : '<span class="badge badge-warn">sem e-mail cadastrado</span>')
      : '<span class="muted">—</span>',
    Number(x.voicemail)
      ? `<span class="tiny dim">${x.vm_max_mensagens ?? 100} recados · ${x.vm_max_segundos ?? 180}s cada</span>`
      : '<span class="muted">—</span>',
    Number(x.voicemail)
      ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>'
      : '<span class="badge">Desativada</span>'
  ],
  campos: () => [
    { campo: 'voicemail', label: 'Caixa postal ativa', tipo: 'switch', padrao: 1, largura: 'full',
      ajuda: 'Desligada, quem não for atendido ouve ocupado em vez de deixar recado.' },
    { campo: 'vm_senha', label: 'Senha da caixa', mono: true, tipo: 'password',
      padraoValido: /^[0-9]{0,10}$/, mensagemPadrao: 'só dígitos',
      ajuda: 'Em branco, a senha é o próprio número do ramal. É o que se digita no *97.' },
    { campo: 'email', label: 'E-mail para aviso', tipo: 'email', largura: 'full',
      ajuda: 'É o mesmo e-mail do cadastro do ramal.' },
    { campo: 'vm_email', label: 'Anexar o áudio no e-mail', tipo: 'switch', padrao: 1 },
    { campo: 'vm_apagar', label: 'Apagar o recado depois de enviar', tipo: 'switch',
      ajuda: 'Ligado, o recado só existe no e-mail — não fica para ouvir pelo telefone.' },
    { campo: 'vm_max_mensagens', label: 'Máximo de recados guardados', tipo: 'number', padrao: 100 },
    { campo: 'vm_max_segundos', label: 'Duração máxima de cada recado (s)', tipo: 'number', padrao: 180 },
    { campo: 'vm_dizer_hora', label: 'Falar a data e a hora do recado', tipo: 'switch', padrao: 1 },
    { campo: 'vm_dizer_origem', label: 'Falar o número de quem ligou', tipo: 'switch', padrao: 1 }
  ],
  conferir: d => (Number(d.voicemail) === 1 && Number(d.vm_email) === 1 && !d.email)
    ? { campo: 'email', mensagem: 'Para enviar por e-mail, cadastre o endereço do usuário.' }
    : null
});

/* ------------------------- Grupos de Horário ------------------------- */
const DIAS_SEMANA = [
  { valor: '', rotulo: '—' }, { valor: 0, rotulo: 'domingo' }, { valor: 1, rotulo: 'segunda' },
  { valor: 2, rotulo: 'terça' }, { valor: 3, rotulo: 'quarta' }, { valor: 4, rotulo: 'quinta' },
  { valor: 5, rotulo: 'sexta' }, { valor: 6, rotulo: 'sábado' }
];
const MESES = [{ valor: '', rotulo: '—' },
  ...['janeiro','fevereiro','março','abril','maio','junho','julho','agosto',
      'setembro','outubro','novembro','dezembro'].map((m, i) => ({ valor: i + 1, rotulo: m }))];
const DIAS_MES = [{ valor: '', rotulo: '—' },
  ...Array.from({ length: 31 }, (_, i) => ({ valor: i + 1, rotulo: String(i + 1) }))];

/** "seg a sex, 08:00 às 18:00" — a faixa em português, para a lista. */
function descreveFaixa(f) {
  const curto = ['dom','seg','ter','qua','qui','sex','sáb'];
  const mes = ['', 'jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
  const par = (de, ate, mapa) => {
    if (de === null || de === undefined || de === '') return null;
    const a = mapa ? mapa[de] : de;
    const b = (ate === null || ate === undefined || ate === '') ? a : (mapa ? mapa[ate] : ate);
    return a === b ? `${a}` : `${a} a ${b}`;
  };

  const partes = [];
  const semana = par(f.dia_semana_inicio, f.dia_semana_fim, curto);
  const dia = par(f.dia_mes_inicio, f.dia_mes_fim, null);
  const m = par(f.mes_inicio, f.mes_fim, mes);

  if (semana) partes.push(semana);
  if (dia) partes.push(`dia ${dia}`);
  if (m) partes.push(m);
  if (!partes.length) partes.push('todos os dias');

  const hora = (f.hora_inicio && f.hora_fim)
    ? `${String(f.hora_inicio).slice(0, 5)} às ${String(f.hora_fim).slice(0, 5)}`
    : 'o dia inteiro';

  return `${partes.join(', ')} · ${hora}`;
}

PAGES['apps.grupohorario'] = {
  async render(ctx) {
    let r;
    try { r = await Api.get('/grupos-horario', { limite: 200 }); }
    catch (e) { return pageHead('Grupos de Horário', '') + blocoErro(e); }
    this._itens = r.dados || [];

    const cabecalho = pageHead('Grupos de Horário',
      `As faixas de tempo que uma condição horária consulta: hora, dia da semana,
       dia do mês e mês. Um grupo pode ter várias faixas.`,
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-novo-grupo>${icon('plus','ico ico-sm')} Novo grupo</button>`
        : readOnlyNote(ctx));

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('calendar', 'Nenhum grupo de horário',
        `Crie um com o horário comercial, por exemplo, e depois use-o numa condição horária
         para mandar a chamada para a URA durante o expediente e para o recado fora dele.`,
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-novo-grupo>Criar o primeiro</button>' : '')}
      </div>`;
    }

    const faixas = {};
    await Promise.all(this._itens.map(async g => {
      faixas[g.id] = (await Api.get(`/grupos-horario/${g.id}/faixas`).catch(() => ({ faixas: [] }))).faixas || [];
    }));
    this._faixas = faixas;

    return cabecalho + `<div class="agenda-grid larga">${this._itens.map(g => `
      <div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:10px">
          <div><b>${esc(g.nome)}</b>
            ${g.descricao ? `<div class="tiny muted">${esc(g.descricao)}</div>` : ''}</div>
          <span class="badge">${(faixas[g.id] || []).length} faixa(s)</span>
        </div>
        <div class="contato-dados" style="margin-bottom:10px">
          ${(faixas[g.id] || []).length
            ? faixas[g.id].map(f => `<div class="row gap-6 small">${icon('clock','ico ico-sm')}
                <span>${esc(descreveFaixa(f))}</span></div>`).join('')
            : '<span class="tiny muted">Sem faixas: uma condição com este grupo nunca estará dentro.</span>'}
        </div>
        <div class="contato-acoes">
          <span class="grow"></span>
          ${ctx.can('editar') ? `<button class="btn btn-outline btn-sm" data-editar-grupo="${g.id}">
            ${icon('edit','ico ico-sm')} Editar</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
            data-excluir-grupo="${g.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </div>
      </div>`).join('')}</div>`;
  },

  mount(ctx) {
    const pagina = this;
    const itens = this._itens || [];

    const sel = (nome, opcoes, v) => `<select class="select" name="${nome}">
      ${opcoes.map(o => `<option value="${esc(o.valor)}"
        ${String(o.valor) === String(v ?? '') ? 'selected' : ''}>${esc(o.rotulo)}</option>`).join('')}
    </select>`;

    const linhaFaixa = (f = {}) => `
      <tr data-faixa>
        <td><div class="row gap-6">
          <input class="input" type="time" name="hora_inicio" style="width:120px"
                 value="${esc(String(f.hora_inicio || '').slice(0, 5))}">
          <span class="tiny muted">às</span>
          <input class="input" type="time" name="hora_fim" style="width:120px"
                 value="${esc(String(f.hora_fim || '').slice(0, 5))}">
        </div><span class="hint">Em branco: o dia inteiro.</span></td>
        <td><div class="row gap-6">
          ${sel('dia_semana_inicio', DIAS_SEMANA, f.dia_semana_inicio)}
          ${sel('dia_semana_fim', DIAS_SEMANA, f.dia_semana_fim)}
        </div></td>
        <td><div class="row gap-6">
          ${sel('dia_mes_inicio', DIAS_MES, f.dia_mes_inicio)}
          ${sel('dia_mes_fim', DIAS_MES, f.dia_mes_fim)}
        </div></td>
        <td><div class="row gap-6">
          ${sel('mes_inicio', MESES, f.mes_inicio)}
          ${sel('mes_fim', MESES, f.mes_fim)}
        </div></td>
        <td class="col-actions"><button class="btn btn-ghost btn-sm btn-icon" data-tip="Tirar"
          data-tirar-faixa>${icon('x','ico ico-sm')}</button></td>
      </tr>`;

    const formulario = item => {
      const novo = !item;
      const g = item || {};
      const faixas = novo ? [] : (pagina._faixas[g.id] || []);
      const campos = [
        { campo: 'nome', label: 'Descrição', obrigatorio: true, largura: 'full',
          placeholder: 'Horário comercial' },
        { campo: 'descricao', label: 'Observação', largura: 'full' }
      ];

      Drawer.open({
        titulo: novo ? 'Novo grupo de horário' : `Editar ${g.nome}`,
        sub: 'Cada faixa vale por si: basta uma casar para a condição estar dentro do horário.',
        wide: true,
        corpo: `
          <div class="form-grid">${campos.map(c => campoHtml(c, g)).join('')}</div>
          <div class="secao-form">
            <div class="row-between">
              <div><b>Horários</b>
                <div class="tiny muted">Deixe em branco o que não importa naquela faixa.</div></div>
              <button class="btn btn-outline btn-sm" id="addFaixa">
                ${icon('plus','ico ico-sm')} Adicionar horário</button>
            </div>
            <div class="table-wrap" style="margin-top:10px"><table class="table" id="tabelaFaixas">
              <thead><tr><th style="width:290px">Hora</th><th>Dia da semana</th>
                         <th>Dia do mês</th><th>Mês</th><th></th></tr></thead>
              <tbody>${faixas.map(linhaFaixa).join('')}</tbody>
            </table></div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar grupo' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          const corpo = dw.querySelector('#tabelaFaixas tbody');
          const ligar = () => dw.querySelectorAll('[data-tirar-faixa]').forEach(b =>
            b.onclick = () => b.closest('tr').remove());
          ligar();

          dw.querySelector('#addFaixa').onclick = () => {
            corpo.insertAdjacentHTML('beforeend', linhaFaixa());
            ligar();
          };
          if (!faixas.length) dw.querySelector('#addFaixa').click();

          dw.querySelector('[data-ok]').onclick = async ev => {
            if (!validarCampos(dw, campos)) return;

            const dados = {};
            campos.forEach(c => { dados[c.campo] = dw.querySelector(`[name="${c.campo}"]`).value.trim(); });

            const lista = [...corpo.querySelectorAll('tr[data-faixa]')].map(tr => {
              const v = n => tr.querySelector(`[name="${n}"]`).value;
              return {
                hora_inicio: v('hora_inicio'), hora_fim: v('hora_fim'),
                dia_semana_inicio: v('dia_semana_inicio'), dia_semana_fim: v('dia_semana_fim'),
                dia_mes_inicio: v('dia_mes_inicio'), dia_mes_fim: v('dia_mes_fim'),
                mes_inicio: v('mes_inicio'), mes_fim: v('mes_fim')
              };
            });

            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              const r = novo ? await Api.post('/grupos-horario', dados)
                             : await Api.put(`/grupos-horario/${g.id}`, dados);
              await Api.put(`/grupos-horario/${novo ? r.id : g.id}/faixas`, { faixas: lista });
              Drawer.close();
              toast('Grupo salvo. Aplique as configurações.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Criar grupo' : 'Salvar';
              toast(e.message, 'err');
            }
          };
        }
      });
    };

    document.querySelectorAll('[data-novo-grupo]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar-grupo]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editarGrupo)));
    document.querySelectorAll('[data-excluir-grupo]').forEach(b => b.onclick = async () => {
      const g = itens.find(x => String(x.id) === b.dataset.excluirGrupo);
      const ok = await Modal.confirm({
        titulo: `Excluir o grupo ${g.nome}?`,
        texto: 'As faixas somem junto. Condições horárias que usam este grupo ficam sem referência.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/grupos-horario/${g.id}`); toast('Grupo excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Condições Horárias ------------------------- */
PAGES['apps.condicoes'] = {
  async render(ctx) {
    let r, grupos, destinos;
    try {
      [r, grupos, destinos] = await Promise.all([
        Api.get('/condicoes-horarias', { limite: 200 }),
        Api.get('/grupos-horario', { limite: 200 }).catch(() => ({ dados: [] })),
        opcoesDestino()
      ]);
    } catch (e) { return pageHead('Condições Horárias', '') + blocoErro(e); }

    this._itens = r.dados || [];
    this._grupos = grupos.dados || [];
    this._destinos = destinos;

    const cabecalho = pageHead('Condições Horárias',
      `Se estiver dentro do grupo de horário, a chamada vai para um destino; fora dele,
       para outro. O código <span class="mono">*27</span> força aberto ou fechado sem mexer aqui.`,
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-nova-cond>${icon('plus','ico ico-sm')} Nova condição</button>`
        : readOnlyNote(ctx));

    if (!this._grupos.length) {
      return cabecalho + `<div class="card">${vazio('calendar', 'Crie um grupo de horário antes',
        `A condição pergunta "estamos dentro deste grupo?". Monte as faixas em
         Aplicações › Grupos de Horário e volte aqui.`)}</div>`;
    }

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('clock', 'Nenhuma condição horária',
        'Use uma na rota de entrada para atender diferente dentro e fora do expediente.',
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-nova-cond>Criar a primeira</button>' : '')}
      </div>`;
    }

    // O estado de agora vem do servidor: é o relógio dele que vale.
    const estados = {};
    await Promise.all(this._itens.map(async c => {
      estados[c.id] = await Api.get(`/condicoes-horarias/${c.id}/agora`).catch(() => null);
    }));
    this._estados = estados;

    return cabecalho + `<div class="agenda-grid larga">${this._itens.map(c => {
      const g = this._grupos.find(x => String(x.id) === String(c.grupo_horario_id));
      const e = estados[c.id];
      const agora = !e ? '<span class="badge">estado desconhecido</span>'
        : e.forcado
          ? `<span class="badge badge-warn">forçado ${esc(e.forcado)}</span>`
          : e.dentro
            ? '<span class="badge badge-ok"><i class="dot"></i>Dentro do horário</span>'
            : '<span class="badge badge-info">Fora do horário</span>';

      return `<div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:10px">
          <div><b>${esc(c.nome)}</b>
            ${c.descricao ? `<div class="tiny muted">${esc(c.descricao)}</div>` : ''}
            <div class="tiny muted">Grupo ${esc(g?.nome || 'removido')}${e ? ` · ${e.faixas} faixa(s)` : ''}</div></div>
          ${agora}
        </div>

        <div class="contato-dados" style="margin-bottom:10px">
          <div class="row gap-6 small">${icon('checkCirc','ico ico-sm')}
            <span>Dentro → <b>${esc(descreveDestino(c.destino_dentro_tipo, c.destino_dentro_valor, this._destinos))}</b></span></div>
          <div class="row gap-6 small">${icon('phoneOff','ico ico-sm')}
            <span>Fora → <b>${esc(descreveDestino(c.destino_fora_tipo, c.destino_fora_valor, this._destinos))}</b></span></div>
        </div>

        <div class="contato-acoes">
          ${ctx.can('editar') ? `<div class="segmented" data-forcar="${c.id}">
            <button data-modo="auto" class="${e && !e.forcado ? 'on' : ''}">Pelo relógio</button>
            <button data-modo="aberto" class="${e?.forcado === 'aberto' ? 'on' : ''}">Forçar aberto</button>
            <button data-modo="fechado" class="${e?.forcado === 'fechado' ? 'on' : ''}">Forçar fechado</button>
          </div>` : ''}
          <span class="grow"></span>
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar"
            data-editar-cond="${c.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
            data-excluir-cond="${c.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </div>
      </div>`;
    }).join('')}</div>`;
  },

  mount(ctx) {
    const pagina = this;
    const itens = this._itens || [];

    const formulario = item => {
      const novo = !item;
      const c = item || { ativo: 1 };
      const campos = [
        { campo: 'nome', label: 'Nome', obrigatorio: true, largura: 'full', placeholder: 'Expediente' },
        { campo: 'descricao', label: 'Observação', largura: 'full' },
        { campo: 'grupo_horario_id', label: 'Grupo de horário', obrigatorio: true, tipo: 'select',
          largura: 'full',
          opcoes: [{ valor: '', rotulo: '— escolha o grupo —' },
                   ...pagina._grupos.map(g => ({ valor: g.id, rotulo: g.nome }))],
          ajuda: 'As faixas deste grupo é que dizem se estamos dentro ou fora.' },
        { campo: 'ativo', label: 'Condição ativa', tipo: 'switch', padrao: 1 }
      ];

      Drawer.open({
        titulo: novo ? 'Nova condição horária' : `Editar ${c.nome}`,
        sub: 'Aponte uma rota de entrada ou uma URA para esta condição e ela decide o resto.',
        wide: true,
        corpo: `<div class="form-grid">${campos.map(x => campoHtml(x, c)).join('')}</div>
          <div class="secao-form">
            <b>Para onde a chamada vai</b>
            <div class="form-grid" style="margin-top:10px">
              ${destinoSelect('destino_dentro', c, pagina._destinos,
                  { label: 'Dentro do horário', largura: 'full',
                    ajuda: 'Quando alguma faixa do grupo cobre o momento da chamada.' })}
              ${destinoSelect('destino_fora', c, pagina._destinos,
                  { label: 'Fora do horário', largura: 'full',
                    ajuda: 'Fim de semana, madrugada, feriado — o que o grupo não cobre.' })}
            </div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar condição' : 'Salvar'}</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          if (!validarCampos(dw, campos)) return;

          const dados = {};
          campos.forEach(x => {
            const el = dw.querySelector(`[name="${x.campo}"]`);
            if (el) dados[x.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
          });
          ['destino_dentro', 'destino_fora'].forEach(pref => {
            const { tipo, valor } = lerDestino(dw.querySelector(`[name="${pref}"]`));
            dados[`${pref}_tipo`] = tipo;
            dados[`${pref}_valor`] = valor;
          });

          if (!dados.destino_dentro_tipo || !dados.destino_fora_tipo) {
            const faltam = [];
            if (!dados.destino_dentro_tipo) { marcarErro(dw, 'destino_dentro', 'Escolha o destino.'); faltam.push('Dentro do horário'); }
            if (!dados.destino_fora_tipo) { marcarErro(dw, 'destino_fora', 'Escolha o destino.'); faltam.push('Fora do horário'); }
            avisoFormulario(dw, faltam);
            return;
          }

          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Salvando…';
          try {
            if (novo) await Api.post('/condicoes-horarias', dados);
            else await Api.put(`/condicoes-horarias/${c.id}`, dados);
            Drawer.close();
            toast('Condição salva. Aplique as configurações.', 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = novo ? 'Criar condição' : 'Salvar';
            if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    };

    document.querySelectorAll('[data-nova-cond]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar-cond]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editarCond)));

    document.querySelectorAll('[data-excluir-cond]').forEach(b => b.onclick = async () => {
      const c = itens.find(x => String(x.id) === b.dataset.excluirCond);
      const ok = await Modal.confirm({
        titulo: `Excluir a condição ${c.nome}?`,
        texto: 'As rotas que apontavam para ela ficam sem destino.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/condicoes-horarias/${c.id}`); toast('Condição excluída.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });

    document.querySelectorAll('[data-forcar]').forEach(grupo => {
      grupo.querySelectorAll('[data-modo]').forEach(b => b.onclick = async () => {
        const id = grupo.dataset.forcar;
        try {
          await Api.post(`/condicoes-horarias/${id}/forcar`, { modo: b.dataset.modo });
          toast(b.dataset.modo === 'auto'
            ? 'Voltou a seguir o relógio.'
            : `Forçado ${b.dataset.modo} até alguém desfazer.`, 'ok');
          App.route();
        } catch (e) { toast(e.message, 'err'); }
      });
    });
  }
};

/* ------------------------- Anúncios ------------------------- */
PAGES['apps.anuncios'] = {
  async render(ctx) {
    let r, audios, destinos;
    try {
      [r, audios, destinos] = await Promise.all([
        Api.get('/anuncios', { limite: 200 }),
        Api.get('/audios').catch(() => ({ dados: [] })),
        opcoesDestino()
      ]);
    } catch (e) { return pageHead('Anúncios', '') + blocoErro(e); }

    this._itens = r.dados || [];
    this._audios = audios.dados || [];
    this._destinos = destinos;

    const cabecalho = pageHead('Anúncios',
      `Uma gravação mais o que fazer com ela. É o anúncio que a URA, as filas, as
       conferências e as rotas apontam — nenhum módulo usa o arquivo direto.`,
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-novo-anuncio>${icon('plus','ico ico-sm')} Novo anúncio</button>`
        : readOnlyNote(ctx));

    if (!this._audios.length) {
      return cabecalho + `<div class="card">${vazio('mic', 'Nenhum áudio enviado ainda',
        `O anúncio é montado a partir de um áudio. Envie o primeiro em
         Administrador › Gravações do Sistema e volte aqui.`)}</div>`;
    }

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('speaker', 'Nenhum anúncio criado',
        `Escolha uma das gravações enviadas, diga se o cliente pode pular, se alguma tecla
         repete e para onde a chamada vai depois de tocar.`,
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-novo-anuncio>Criar o primeiro</button>' : '')}
      </div>`;
    }

    const linhas = this._itens.map(a => {
      const audio = this._audios.find(x => String(x.id) === String(a.audio_id));
      return `<tr data-id="${a.id}" data-busca="${esc(`${a.nome} ${a.descricao || ''}`.toLowerCase())}">
        <td><b>${esc(a.nome)}</b>${a.descricao ? `<div class="tiny muted">${esc(a.descricao)}</div>` : ''}</td>
        <td>${audio ? `${esc(audio.nome)}<div class="tiny muted mono">${esc(audio.arquivo)}</div>`
                    : '<span class="badge badge-danger">gravação removida</span>'}</td>
        <td>${[
          Number(a.permitir_pular) && '<span class="badge">pode pular</span>',
          a.repetir_tecla && `<span class="badge">repete no ${esc(a.repetir_tecla)}</span>`,
          Number(a.retornar_ura) && '<span class="badge">volta à URA</span>',
          Number(a.nao_responder) && '<span class="badge">não atende</span>'
        ].filter(Boolean).join(' ') || '<span class="muted">—</span>'}</td>
        <td class="small">${esc(descreveDestino(a.destino_tipo, a.destino_valor, this._destinos))}</td>
        <td>${Number(a.ativo)
          ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>'
          : '<span class="badge">Parado</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar"
            data-editar-anuncio="${a.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
            data-excluir-anuncio="${a.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td>
      </tr>`;
    }).join('');

    return cabecalho + `
      <div class="card">
        <div class="toolbar">
          <div class="input-icon search-mini">${icon('search','ico ico-sm')}
            <input class="input" data-filtro placeholder="Buscar por nome…">
          </div>
          <span class="grow"></span>
          <span class="small muted" data-contador></span>
        </div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Anúncio</th><th>Gravação</th><th>Comportamento</th>
                     <th>Destino após tocar</th><th>Estado</th><th></th></tr></thead>
          <tbody>${linhas}</tbody>
        </table></div>
      </div>`;
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
      if (contador) contador.textContent = `${n} de ${linhas.length} anúncios`;
    };
    filtro?.addEventListener('input', aplicar);
    aplicar();

    const formulario = item => {
      const novo = !item;
      const a = item || { ativo: 1 };
      const campos = [
        { campo: 'nome', label: 'Descrição', obrigatorio: true, largura: 'full',
          placeholder: 'Aviso de horário de atendimento',
          ajuda: 'É como o anúncio aparece nas listas de destino.' },
        { campo: 'audio_id', label: 'Gravação', obrigatorio: true, tipo: 'select', largura: 'full',
          opcoes: [{ valor: '', rotulo: '— escolha a gravação —' },
                   ...(pagina._audios || []).map(x =>
                     ({ valor: x.id, rotulo: `${x.nome} (${x.arquivo})` }))],
          ajuda: 'Os arquivos vêm de Administrador › Gravações do Sistema.' },
        { campo: 'repetir_tecla', label: 'Repetir', tipo: 'select',
          opcoes: [{ valor: '', rotulo: 'Desabilitar' },
                   ...['0','1','2','3','4','5','6','7','8','9','*','#']
                     .map(t => ({ valor: t, rotulo: `Tecla ${t}` }))],
          ajuda: 'A tecla que o cliente aperta para ouvir de novo.' },
        { campo: 'permitir_pular', label: 'Permitir pular', tipo: 'switch',
          ajuda: 'Qualquer tecla interrompe o anúncio e segue para o destino.' },
        { campo: 'retornar_ura', label: 'Retornar para a URA', tipo: 'switch',
          ajuda: 'Se a chamada veio de uma URA, volta para ela depois de tocar, em vez de ir ao destino.' },
        { campo: 'nao_responder', label: 'Não atender o canal', tipo: 'switch',
          ajuda: 'Toca sem atender. A operadora não tarifa, mas alguns aparelhos não reproduzem o áudio.' },
        { campo: 'ativo', label: 'Anúncio ativo', tipo: 'switch', padrao: 1 }
      ];

      Drawer.open({
        titulo: novo ? 'Novo anúncio' : `Editar ${a.nome}`,
        sub: 'Toca a gravação e manda a chamada para onde você escolher.',
        corpo: `<div class="form-grid">${campos.map(c => campoHtml(c, a)).join('')}</div>
          <div class="secao-form">
            <b>Destino após reprodução</b>
            <div class="form-grid" style="margin-top:10px">
              ${destinoSelect('destino', a, pagina._destinos,
                  { label: 'Para onde a chamada vai', largura: 'full',
                    rotuloVazio: '— desligar depois de tocar —',
                    ajuda: 'Com "retornar para a URA" ligado, isto só vale quando a chamada não veio de uma URA.' })}
            </div>
          </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar anúncio' : 'Salvar'}</button>`,
        aoAbrir: dw => dw.querySelector('[data-ok]').onclick = async ev => {
          if (!validarCampos(dw, campos)) return;

          const dados = {};
          campos.forEach(c => {
            const el = dw.querySelector(`[name="${c.campo}"]`);
            if (el) dados[c.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
          });
          const { tipo, valor } = lerDestino(dw.querySelector('[name="destino"]'));
          dados.destino_tipo = tipo;
          dados.destino_valor = valor;

          // Um anúncio que aponta para si mesmo prende a chamada num laço.
          if (!novo && tipo === 'anuncio' && String(valor) === String(a.id)) {
            marcarErro(dw, 'destino', 'O anúncio não pode ter ele mesmo como destino.');
            avisoFormulario(dw, ['Destino aponta para o próprio anúncio']);
            return;
          }

          const botao = ev.currentTarget;
          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> Salvando…';
          try {
            if (novo) await Api.post('/anuncios', dados);
            else await Api.put(`/anuncios/${a.id}`, dados);
            Drawer.close();
            toast('Anúncio salvo. Aplique as configurações para valer no Asterisk.', 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = novo ? 'Criar anúncio' : 'Salvar';
            if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
            else toast(e.message, 'err');
          }
        }
      });
    };

    document.querySelectorAll('[data-novo-anuncio]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar-anuncio]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editarAnuncio)));

    document.querySelectorAll('[data-excluir-anuncio]').forEach(b => b.onclick = async () => {
      const a = itens.find(x => String(x.id) === b.dataset.excluirAnuncio);
      const ok = await Modal.confirm({
        titulo: `Excluir o anúncio ${a.nome}?`,
        texto: `A gravação continua em Gravações do Sistema. Quem apontava para este anúncio
                — URA, fila, rota — fica sem o áudio.`,
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/anuncios/${a.id}`); toast('Anúncio excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* ------------------------- Conferências ------------------------- */
PAGES['apps.conferencias'] = {
  async render(ctx) {
    let r, anuncios;
    try {
      [r, anuncios] = await Promise.all([
        Api.get('/conferencias', { limite: 200 }),
        Api.get('/anuncios', { limite: 200 }).catch(() => ({ dados: [] }))
      ]);
    } catch (e) { return pageHead('Conferências', '') + blocoErro(e); }

    this._itens = r.dados || [];
    this._anuncios = anuncios.dados || [];

    const cabecalho = pageHead('Conferências',
      'Salas fixas em que várias pessoas conversam ao mesmo tempo. Basta discar o número da sala.',
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" data-nova-sala>${icon('plus','ico ico-sm')} Nova sala</button>`
        : readOnlyNote(ctx));

    if (!this._itens.length) {
      return cabecalho + `<div class="card">${vazio('users', 'Nenhuma sala de conferência',
        `Crie uma sala com número próprio. Quem discar esse número entra na conversa —
         com PIN, se você quiser controlar quem participa.`,
        ctx.can('criar') ? '<button class="btn btn-primary btn-sm" data-nova-sala>Criar a primeira sala</button>' : '')}
      </div>`;
    }

    const cartoes = this._itens.map(c => {
      const anuncio = this._anuncios.find(a => String(a.id) === String(c.anuncio_entrada_id));
      const sinais = [
        c.pin && '<span class="badge">PIN</span>',
        c.pin_admin && '<span class="badge badge-brand">PIN de admin</span>',
        Number(c.gravar) && '<span class="badge badge-info">grava</span>',
        Number(c.esperar_admin) && '<span class="badge">espera o admin</span>',
        Number(c.silenciar_ao_entrar) && '<span class="badge">entra mudo</span>'
      ].filter(Boolean).join(' ');

      return `<div class="card" style="padding:16px" data-sala="${c.id}">
        <div class="row-between" style="margin-bottom:10px">
          <div class="row gap-10">
            <span class="k-ico" style="background:var(--brand-soft);color:var(--brand)">${icon('users')}</span>
            <div><b class="mono" style="font-size:16px">${esc(c.numero)}</b>
              <div><b>${esc(c.nome)}</b></div>
              ${c.descricao ? `<div class="tiny muted">${esc(c.descricao)}</div>` : ''}</div>
          </div>
          ${Number(c.ativo)
            ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>'
            : '<span class="badge">Parada</span>'}
        </div>

        <div class="contato-dados" style="margin-bottom:10px">
          <div class="row gap-6 small">${icon('users','ico ico-sm')}
            <span>${Number(c.max_usuarios) > 0
              ? `até ${c.max_usuarios} pessoas` : 'sem limite de pessoas'}</span></div>
          ${anuncio ? `<div class="row gap-6 small">${icon('speaker','ico ico-sm')}
            <span>${esc(anuncio.nome)}</span></div>` : ''}
        </div>

        ${sinais ? `<div class="row gap-4 wrap" style="margin-bottom:10px">${sinais}</div>` : ''}

        <div class="contato-acoes">
          <button class="btn btn-outline btn-sm" data-quem="${c.id}">
            ${icon('activity','ico ico-sm')} Quem está na sala</button>
          <span class="grow"></span>
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar"
            data-editar-sala="${c.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir"
            data-excluir-sala="${c.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </div>
      </div>`;
    }).join('');

    return cabecalho + `<div class="agenda-grid">${cartoes}</div>`;
  },

  mount(ctx) {
    const pagina = this;
    const itens = this._itens || [];

    const anunciosSelect = () => opcoesAnuncio(pagina._anuncios);

    const formulario = item => {
      const novo = !item;
      const c = item || { max_usuarios: 0, anunciar_entrada_saida: 1, anunciar_quantidade: 1,
                          musica_sozinho: 1, ativo: 1 };
      const campos = [
        { aba: 'Sala', campo: 'numero', label: 'Número da sala', obrigatorio: true, mono: true,
          placeholder: '5000', padraoValido: /^[0-9*#]{2,20}$/,
          mensagemPadrao: 'use dígitos, * ou #',
          ajuda: 'É o que se disca para entrar na conferência.' },
        { aba: 'Sala', campo: 'nome', label: 'Nome da sala', obrigatorio: true,
          placeholder: 'Reunião de Diretoria' },
        { aba: 'Sala', campo: 'descricao', label: 'Descrição', largura: 'full' },
        { aba: 'Sala', campo: 'max_usuarios', label: 'Limite de usuários', tipo: 'number', padrao: 0,
          ajuda: '0 deixa sem limite. Chegando ao limite, quem discar ouve que a sala está cheia.' },
        { aba: 'Sala', campo: 'gravar', label: 'Gravar a conferência', tipo: 'switch',
          ajuda: 'O áudio da sala inteira vira um arquivo, que aparece em Gravação de Chamadas.' },
        { aba: 'Sala', campo: 'ativo', label: 'Sala ativa', tipo: 'switch', padrao: 1 },

        { aba: 'Entrada', campo: 'pin', label: 'PIN para entrar', mono: true, tipo: 'password',
          limpavel: true,
          padraoValido: /^[0-9]{0,16}$/, mensagemPadrao: 'só dígitos',
          ajuda: 'Sem PIN, qualquer um que disque o número entra na reunião. '
               + 'Ao editar, em branco mantém o atual.' },
        { aba: 'Entrada', campo: 'pin_admin', label: 'PIN de administrador', mono: true,
          tipo: 'password', limpavel: true,
          padraoValido: /^[0-9]{0,16}$/, mensagemPadrao: 'só dígitos',
          ajuda: 'Quem entra com este PIN pode trancar a sala (tecla 2) e tirar o último que '
               + 'entrou (tecla 3). Ao editar, em branco mantém o atual.' },
        { aba: 'Entrada', campo: 'anuncio_entrada_id', label: 'Mensagem de anúncio de entrada',
          tipo: 'select', opcoes: anunciosSelect(), largura: 'full',
          ajuda: 'Tocada para quem entra, antes de cair na sala. Os anúncios são montados em Aplicações › Anúncios.' },
        { aba: 'Entrada', campo: 'esperar_admin', label: 'Só começar quando o administrador entrar',
          tipo: 'switch', largura: 'full',
          ajuda: 'Quem chegar antes ouve música de espera. Quando o administrador sai, a sala encerra.' },
        { aba: 'Entrada', campo: 'silenciar_ao_entrar', label: 'Entrar com o microfone mudo',
          tipo: 'switch', ajuda: 'Cada um se libera com a tecla 1. Útil em sala grande.' },

        { aba: 'Na sala', campo: 'anunciar_entrada_saida', label: 'Anunciar quem entra e quem sai',
          tipo: 'switch', padrao: 1, largura: 'full',
          ajuda: 'O Asterisk pede o nome de quem entra e toca para a sala na entrada e na saída.' },
        { aba: 'Na sala', campo: 'anunciar_quantidade', label: 'Dizer quantas pessoas já estão na sala',
          tipo: 'switch', padrao: 1 },
        { aba: 'Na sala', campo: 'musica_sozinho', label: 'Música em espera enquanto está sozinho',
          tipo: 'switch', padrao: 1 }
      ];

      const abas = [...new Set(campos.map(x => x.aba))];
      const corpoAba = aba => `<div class="form-grid">${campos
        .filter(x => x.aba === aba).map(x => campoHtml(x, c)).join('')}</div>`;

      Drawer.open({
        titulo: novo ? 'Nova sala de conferência' : `Sala ${c.numero} — ${c.nome}`,
        sub: 'Dentro da sala: 1 silencia e libera o microfone, 0 diz quantas pessoas estão, * lê o menu.',
        wide: true,
        corpo: `<div class="tabs" data-abas>${abas.map((a, i) =>
            `<button class="tab ${i === 0 ? 'on' : ''}" data-aba="${esc(a)}">${esc(a)}</button>`).join('')}</div>
          ${abas.map((a, i) => `<div data-painel="${esc(a)}" ${i ? 'hidden' : ''}>${corpoAba(a)}</div>`).join('')}`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" data-ok>${novo ? 'Criar sala' : 'Salvar'}</button>`,
        aoAbrir: dw => {
          dw.querySelectorAll('[data-aba]').forEach(t => t.onclick = () => {
            dw.querySelectorAll('[data-aba]').forEach(x => x.classList.remove('on'));
            t.classList.add('on');
            dw.querySelectorAll('[data-painel]').forEach(p => p.hidden = p.dataset.painel !== t.dataset.aba);
          });

          dw.querySelector('[data-ok]').onclick = async ev => {
            if (!validarCampos(dw, campos)) return;

            const dados = {};
            campos.forEach(x => {
              const el = dw.querySelector(`[name="${x.campo}"]`);
              if (el) dados[x.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
            });

            // Dois PINs iguais dariam sempre o perfil de admin.
            if (dados.pin && dados.pin === dados.pin_admin) {
              marcarErro(dw, 'pin_admin', 'O PIN de administrador tem de ser diferente do PIN comum.');
              avisoFormulario(dw, ['PIN de administrador igual ao PIN comum']);
              return;
            }

            const botao = ev.currentTarget;
            botao.disabled = true;
            botao.innerHTML = '<span class="spin"></span> Salvando…';
            try {
              if (novo) await Api.post('/conferencias', dados);
              else await Api.put(`/conferencias/${c.id}`, dados);
              Drawer.close();
              toast('Sala salva. Aplique as configurações para valer no Asterisk.', 'ok');
              App.route();
            } catch (e) {
              botao.disabled = false;
              botao.textContent = novo ? 'Criar sala' : 'Salvar';
              if (e.status === 409) marcarErro(dw, 'numero', 'Já existe uma sala com este número.');
              else if (e.detalhe?.campo) marcarErro(dw, e.detalhe.campo, e.message);
              else toast(e.message, 'err');
            }
          };
        }
      });
    };

    document.querySelectorAll('[data-nova-sala]').forEach(b => b.onclick = () => formulario(null));
    document.querySelectorAll('[data-editar-sala]').forEach(b => b.onclick = () =>
      formulario(itens.find(x => String(x.id) === b.dataset.editarSala)));

    document.querySelectorAll('[data-excluir-sala]').forEach(b => b.onclick = async () => {
      const c = itens.find(x => String(x.id) === b.dataset.excluirSala);
      const ok = await Modal.confirm({
        titulo: `Excluir a sala ${c.numero}?`,
        texto: 'O número deixa de atender. As gravações já feitas continuam guardadas.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try { await Api.delete(`/conferencias/${c.id}`); toast('Sala excluída.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });

    document.querySelectorAll('[data-quem]').forEach(b => b.onclick = async () => {
      const c = itens.find(x => String(x.id) === b.dataset.quem);
      let d;
      try { d = await Api.get(`/conferencias/${c.id}/situacao`); }
      catch (e) { toast(e.message, 'err'); return; }

      Drawer.open({
        titulo: `Sala ${c.numero} agora`,
        sub: d.disponivel ? `${d.participantes.length} pessoa(s) na sala` : d.detalhe,
        corpo: !d.disponivel
          ? vazio('alert', 'Asterisk fora do ar', esc(d.detalhe))
          : (d.participantes.length
            ? `<div class="table-wrap"><table class="table">
                <thead><tr><th>Canal</th><th>Quem</th><th>Papel</th><th>Microfone</th></tr></thead>
                <tbody>${d.participantes.map(p => `<tr>
                  <td class="mono small">${esc(p.canal)}</td>
                  <td>${esc(p.nome || '—')}</td>
                  <td>${p.admin ? '<span class="badge badge-brand">administra</span>'
                                : '<span class="badge">participante</span>'}</td>
                  <td>${p.mudo ? '<span class="badge badge-warn">mudo</span>'
                               : '<span class="badge badge-ok">aberto</span>'}</td>
                </tr>`).join('')}</tbody></table></div>`
            : vazio('users', 'Sala vazia', 'Ninguém entrou nesta sala neste momento.'))
          + `<details style="margin-top:16px"><summary class="small muted">Saída bruta do Asterisk</summary>
               <pre class="mono tiny" style="white-space:pre-wrap;margin-top:8px">${esc(d.saida || '')}</pre></details>`,
        rodape: '<button class="btn btn-outline" data-drawer-close>Fechar</button>'
      });
    });
  }
};

/* ------------------------- Administrador · Destinos Personalizados ------------------------- */
PAGES['admin.destinos'] = paginaCrud({
  recurso: 'destinos-personalizados',
  titulo: 'Destinos Personalizados',
  sub: `Saídas para contextos que você escreveu à mão em
        <span class="mono">extensions_custom.conf</span>. Depois de cadastrados, aparecem
        como destino nas rotas de entrada, nas URAs e nas condições de horário.`,
  ico: 'branch',
  plural: 'destinos',
  rotuloNovo: 'Novo destino',
  tituloNovo: 'Novo destino personalizado',
  vazioTitulo: 'Nenhum destino personalizado',
  vazioTexto: `Use quando o PABX precisar saltar para um trecho de dialplan seu — uma
               integração, um roteamento fora do padrão, um AGI. Escreva o contexto em
               <span class="mono">/etc/asterisk/telium/extensions_custom.conf</span> e aponte para ele aqui.`,
  placeholderBusca: 'Buscar por nome ou contexto…',
  textoBusca: d => `${d.nome} ${d.contexto} ${d.descricao || ''}`,

  // Apontar para um contexto que não existe é o erro clássico aqui, e ele
  // só apareceria na primeira chamada perdida. Conferimos antes.
  aoCarregar: async pagina => {
    pagina._ctx = await Api.get('/destinos/contextos').catch(() => ({ disponivel: false, destinos: [] }));
    const estado = {};
    (pagina._ctx.destinos || []).forEach(d => { estado[d.id] = d.contexto_existe; });
    pagina._estado = estado;
  },

  colunas: [
    { label: 'Nome', render: d => `<b>${esc(d.nome)}</b>${d.descricao
        ? `<div class="tiny muted">${esc(d.descricao)}</div>` : ''}` },
    { label: 'Salta para', render: d =>
        `<span class="mono small">${esc(d.contexto)},${esc(d.extensao)},${esc(d.prioridade)}</span>` },
    { label: 'Contexto no dialplan', render: (d, ctx, pagina) => {
        const existe = (PAGES['admin.destinos']._estado || {})[d.id];
        if (existe === undefined || existe === null) return '<span class="badge">não verificado</span>';
        return existe
          ? '<span class="badge badge-ok"><i class="dot"></i>Existe</span>'
          : '<span class="badge badge-danger">Não existe</span>';
      } },
    { label: 'Estado', render: d => Number(d.ativo)
        ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge">Desativado</span>' }
  ],

  acoesExtra: () => {
    const c = PAGES['admin.destinos']._ctx || {};
    if (c.disponivel === false) {
      return '<span class="badge badge-warn">Asterisk fora — contextos não conferidos</span> ';
    }
    const faltando = (c.destinos || []).filter(d => d.contexto_existe === false).length;
    return faltando
      ? `<span class="badge badge-danger">${faltando} destino${faltando > 1 ? 's' : ''} apontando para contexto inexistente</span> `
      : '';
  },

  campos: () => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, largura: 'full',
      placeholder: 'Integração com o ERP',
      ajuda: 'É como este destino aparece nas listas de rota e de URA.' },
    { campo: 'contexto', label: 'Contexto', obrigatorio: true, mono: true,
      placeholder: 'meu-contexto',
      padraoValido: /^[A-Za-z0-9_-]+$/,
      mensagemPadrao: 'use apenas letras, números, hífen e sublinhado',
      ajuda: 'O nome entre colchetes no seu extensions_custom.conf.' },
    { campo: 'extensao', label: 'Extensão', obrigatorio: true, mono: true, padrao: 's',
      ajuda: 'Quase sempre "s". É o rótulo da linha exten =>.' },
    { campo: 'prioridade', label: 'Prioridade', obrigatorio: true, mono: true, padrao: '1',
      ajuda: 'Normalmente 1, ou um rótulo como "inicio".' },
    { campo: 'descricao', label: 'Para que serve', tipo: 'textarea', largura: 'full',
      ajuda: 'Quem mexer nisso daqui a um ano vai agradecer.' },
    { campo: 'ativo', label: 'Ativo', tipo: 'switch', padrao: 1,
      ajuda: 'Desativado, some das listas de destino e o dialplan deixa de gerá-lo.' }
  ],

  tituloExcluir: d => `Excluir ${d.nome}?`,
  textoExcluir: () => `O contexto no extensions_custom.conf não é tocado — some só o atalho
                       para ele. Rotas que apontavam para este destino ficam sem saída.`
});

/* ------------------------- Agenda de contatos ------------------------- */

/** Os campos da ficha, agrupados como aparecem na gaveta. */
function camposContato(administra) {
  return [
    { aba: 'Pessoa', campo: 'nome', label: 'Nome completo', obrigatorio: true, largura: 'full' },
    { aba: 'Pessoa', campo: 'empresa', label: 'Empresa' },
    { aba: 'Pessoa', campo: 'cargo', label: 'Cargo' },
    { aba: 'Pessoa', campo: 'departamento', label: 'Setor' },
    { aba: 'Pessoa', campo: 'grupo', label: 'Grupo na agenda', placeholder: 'Clientes, Fornecedores…',
      ajuda: 'Serve para filtrar a lista depois.' },
    { aba: 'Pessoa', campo: 'aniversario', label: 'Aniversário', tipo: 'date' },
    { aba: 'Pessoa', campo: 'favorito', label: 'Favorito', tipo: 'switch',
      ajuda: 'Favoritos vêm primeiro na lista.' },
    { aba: 'Pessoa', campo: 'ativo', label: 'Ativo', tipo: 'switch', padrao: 1 },

    { aba: 'Telefones e e-mail', campo: 'numero', label: 'Telefone principal', mono: true,
      ajuda: 'É o número que o botão Ligar usa.' },
    { aba: 'Telefones e e-mail', campo: 'celular', label: 'Celular', mono: true },
    { aba: 'Telefones e e-mail', campo: 'telefone', label: 'Telefone fixo', mono: true },
    { aba: 'Telefones e e-mail', campo: 'ramal_interno', label: 'Ramal interno', mono: true,
      ajuda: 'Se esta pessoa também tem ramal neste PABX.' },
    { aba: 'Telefones e e-mail', campo: 'discagem_rapida', label: 'Discagem rápida', mono: true,
      placeholder: '21',
      ajuda: 'Código curto para ligar deste contato pelo telefone, discando *0 e este número.' },
    { aba: 'Telefones e e-mail', campo: 'email', label: 'E-mail', tipo: 'email', largura: 'full' },
    { aba: 'Telefones e e-mail', campo: 'email_alt', label: 'E-mail alternativo', tipo: 'email', largura: 'full' },

    { aba: 'Endereço', campo: 'endereco', label: 'Logradouro e número', largura: 'full' },
    { aba: 'Endereço', campo: 'complemento', label: 'Complemento' },
    { aba: 'Endereço', campo: 'bairro', label: 'Bairro' },
    { aba: 'Endereço', campo: 'cidade', label: 'Cidade' },
    { aba: 'Endereço', campo: 'uf', label: 'UF', placeholder: 'SP' },
    { aba: 'Endereço', campo: 'cep', label: 'CEP', mono: true, placeholder: '00000-000' },
    { aba: 'Endereço', campo: 'pais', label: 'País', padrao: 'Brasil' },

    { aba: 'Mais', campo: 'site', label: 'Site', largura: 'full', placeholder: 'https://…' },
    { aba: 'Mais', campo: 'links', label: 'Outros links', tipo: 'textarea', largura: 'full',
      placeholder: 'LinkedIn | https://linkedin.com/in/fulano\nWhatsApp | https://wa.me/5511999999999',
      ajuda: 'Um por linha, no formato rótulo | endereço. Só o endereço também serve.' },
    { aba: 'Mais', campo: 'notas', label: 'Anotações', tipo: 'textarea', largura: 'full' },
    ...(administra ? [{ aba: 'Mais', campo: 'escopo', label: 'Agenda', tipo: 'select',
      opcoes: [{ valor: 'corporativo', rotulo: 'Da empresa — todo mundo vê' },
               { valor: 'pessoal', rotulo: 'Pessoal — só de quem criou' }],
      ajuda: 'A agenda da empresa é visível para todos os usuários do console.' }] : [])
  ];
}

/** "LinkedIn | https://…" → { rotulo, url }; uma URL sozinha também vale. */
function lerLinks(texto) {
  return String(texto || '').split('\n').map(l => l.trim()).filter(Boolean).map(l => {
    const [a, b] = l.split('|').map(x => (x || '').trim());
    const url = b || a;
    return { rotulo: b ? a : url.replace(/^https?:\/\//, '').split('/')[0], url };
  }).filter(x => /^https?:\/\//i.test(x.url));
}

function telefonesDo(c) {
  return [
    { rotulo: 'Principal', valor: c.numero },
    { rotulo: 'Celular', valor: c.celular },
    { rotulo: 'Fixo', valor: c.telefone },
    { rotulo: 'Ramal', valor: c.ramal_interno }
  ].filter(t => t.valor);
}

function fotoContato(c, classe = '') {
  return c.foto_url
    ? `<img class="foto-contato ${classe}" src="${esc(c.foto_url)}" alt="">`
    : `<span class="avatar ${classe === 'grande' ? 'avatar-lg' : ''}">${initials(c.nome)}</span>`;
}

/**
 * A mesma agenda serve os dois módulos: o gerenciador (admin.contatos)
 * abre na agenda da empresa, o "Meus Contatos" (pcu.contatos) na pessoal.
 */
function paginaAgenda(cfg) {
  return {
    _escopo: cfg.escopoPadrao,

    async render(ctx) {
      let d;
      try { d = await Api.get('/contatos', { escopo: this._escopo }); }
      catch (e) { return pageHead(cfg.titulo, cfg.sub) + blocoErro(e); }
      this._d = d;

      const abas = [
        { valor: cfg.escopoPadrao === 'pessoal' ? 'pessoal' : 'corporativo',
          rotulo: cfg.escopoPadrao === 'pessoal' ? 'Meus contatos' : 'Da empresa' },
        { valor: cfg.escopoPadrao === 'pessoal' ? 'corporativo' : 'pessoal',
          rotulo: cfg.escopoPadrao === 'pessoal' ? 'Da empresa' : 'Meus contatos' },
        { valor: '', rotulo: 'Todos' }
      ];

      const cabecalho = pageHead(cfg.titulo, cfg.sub,
        `${ctx.can('criar')
          ? `<button class="btn btn-primary btn-sm" id="novoContato">
              ${icon('plus','ico ico-sm')} Novo contato</button>`
          : readOnlyNote(ctx)}`);

      const barra = `
        <div class="card" style="padding:12px 14px;margin-bottom:16px">
          <div class="toolbar" style="padding:0;border:0">
            <div class="segmented">
              ${abas.map(a => `<button data-escopo="${a.valor}"
                 class="${this._escopo === a.valor ? 'on' : ''}">${a.rotulo}</button>`).join('')}
            </div>
            <div class="input-icon search-mini">${icon('search','ico ico-sm')}
              <input class="input" id="buscaContato" placeholder="Nome, empresa, cargo, número ou e-mail…">
            </div>
            ${d.grupos.length ? `<select class="select" id="filtroGrupo" style="max-width:190px">
              <option value="">Todos os grupos</option>
              ${d.grupos.map(g => `<option value="${esc(g)}">${esc(g)}</option>`).join('')}
            </select>` : ''}
            <span class="grow"></span>
            <label class="row gap-6 small muted" style="cursor:pointer">
              <input type="checkbox" id="soFavoritos"> só favoritos</label>
            <span class="small muted" id="contadorContatos"></span>
          </div>
        </div>`;

      if (!d.dados.length) {
        return cabecalho + barra + `<div class="card">${vazio('book',
          this._escopo === 'pessoal' ? 'Sua agenda pessoal está vazia' : 'Nenhum contato cadastrado',
          this._escopo === 'pessoal'
            ? 'Contatos pessoais só aparecem para você. Os da empresa ficam na outra aba.'
            : `Cadastre clientes, fornecedores e parceiros com telefone, e-mail, endereço e foto.
               Eles aparecem na busca rápida e no softphone.`,
          ctx.can('criar') ? '<button class="btn btn-primary btn-sm" id="novoContato">Cadastrar o primeiro</button>' : '')}
        </div>`;
      }

      const cartoes = d.dados.map(c => {
        const tels = telefonesDo(c);
        return `<div class="card contato-card" data-contato="${c.id}"
                     data-busca="${esc([c.nome, c.empresa, c.cargo, c.numero, c.celular, c.telefone,
                                        c.email].filter(Boolean).join(' ').toLowerCase())}"
                     data-grupo="${esc(c.grupo || '')}" data-fav="${Number(c.favorito) ? 1 : 0}">
          <div class="contato-topo">
            ${fotoContato(c)}
            <div class="grow">
              <div class="row gap-6">
                <b class="elipse">${esc(c.nome)}</b>
                ${Number(c.favorito) ? icon('star', 'ico ico-sm fav') : ''}
                ${Number(c.ativo) ? '' : '<span class="badge">inativo</span>'}
              </div>
              <div class="tiny muted">${esc([c.cargo, c.empresa].filter(Boolean).join(' · ') || '—')}</div>
            </div>
            <span class="badge ${c.escopo === 'corporativo' ? 'badge-brand' : ''}">
              ${c.escopo === 'corporativo' ? 'empresa' : 'pessoal'}</span>
          </div>

          <div class="contato-dados">
            ${tels.slice(0, 2).map(t => `<div class="row gap-6 small">
              ${icon('phone','ico ico-sm')}<span class="mono">${esc(t.valor)}</span>
              <span class="tiny muted">${esc(t.rotulo.toLowerCase())}</span></div>`).join('')}
            ${c.email ? `<div class="row gap-6 small">${icon('mail','ico ico-sm')}
              <a href="mailto:${esc(c.email)}" class="elipse">${esc(c.email)}</a></div>` : ''}
            ${c.cidade ? `<div class="row gap-6 small">${icon('globe','ico ico-sm')}
              <span class="dim">${esc([c.cidade, c.uf].filter(Boolean).join(' / '))}</span></div>` : ''}
          </div>

          <div class="contato-acoes">
            ${tels.length ? `<button class="btn btn-primary btn-sm" data-ligar="${esc(tels[0].valor)}"
                     data-nome="${esc(c.nome)}">${icon('phone','ico ico-sm')} Ligar</button>` : ''}
            <button class="btn btn-outline btn-sm" data-ver="${c.id}">Ver ficha</button>
            <span class="grow"></span>
            ${c.editavel && ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon"
                     data-tip="Editar" data-editar-contato="${c.id}">${icon('edit','ico ico-sm')}</button>` : ''}
            ${c.editavel && ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon"
                     data-tip="Excluir" data-excluir-contato="${c.id}">${icon('trash','ico ico-sm')}</button>` : ''}
          </div>
        </div>`;
      }).join('');

      return cabecalho + barra + `<div class="agenda-grid">${cartoes}</div>
        <div id="semResultado" hidden>${vazio('search', 'Nenhum contato com esse filtro',
          'Tente outro termo, ou limpe o grupo e os favoritos.')}</div>`;
    },

    mount(ctx) {
      const d = this._d || { dados: [] };
      const pagina = this;

      // ---------- filtros ----------
      const busca = document.getElementById('buscaContato');
      const grupo = document.getElementById('filtroGrupo');
      const favs = document.getElementById('soFavoritos');
      const contador = document.getElementById('contadorContatos');
      const cartoes = [...document.querySelectorAll('[data-contato]')];
      const semResultado = document.getElementById('semResultado');

      const aplicar = () => {
        const t = (busca?.value || '').trim().toLowerCase();
        let n = 0;
        cartoes.forEach(el => {
          const ok = (!t || el.dataset.busca.includes(t))
            && (!grupo?.value || el.dataset.grupo === grupo.value)
            && (!favs?.checked || el.dataset.fav === '1');
          el.hidden = !ok;
          if (ok) n++;
        });
        if (contador) contador.textContent = `${n} de ${cartoes.length} contatos`;
        if (semResultado) semResultado.hidden = n > 0 || cartoes.length === 0;
      };
      busca?.addEventListener('input', aplicar);
      grupo?.addEventListener('change', aplicar);
      favs?.addEventListener('change', aplicar);
      aplicar();

      document.querySelectorAll('[data-escopo]').forEach(b => b.onclick = () => {
        pagina._escopo = b.dataset.escopo;
        App.route();
      });

      document.querySelectorAll('[data-ligar]').forEach(b =>
        b.onclick = ev => { ev.stopPropagation(); Softphone.discarPara(b.dataset.ligar, b.dataset.nome); });

      // ---------- ficha ----------
      const verFicha = c => {
        const tels = telefonesDo(c);
        const links = lerLinks(c.links);
        const endereco = [c.endereco, c.complemento, c.bairro,
                          [c.cidade, c.uf].filter(Boolean).join(' / '), c.cep, c.pais]
                          .filter(Boolean).join(' · ');
        const bloco = (titulo, conteudo) => conteudo
          ? `<div class="ficha-bloco"><div class="label">${titulo}</div>${conteudo}</div>` : '';

        Drawer.open({
          titulo: c.nome,
          sub: [c.cargo, c.empresa].filter(Boolean).join(' · ') || 'Contato',
          corpo: `
            <div class="ficha-topo">${fotoContato(c, 'grande')}
              <div><b style="font-size:16px">${esc(c.nome)}</b>
                <div class="small dim">${esc([c.cargo, c.departamento, c.empresa].filter(Boolean).join(' · ') || '—')}</div>
                <div class="row gap-6 wrap" style="margin-top:6px">
                  <span class="badge ${c.escopo === 'corporativo' ? 'badge-brand' : ''}">
                    ${c.escopo === 'corporativo' ? 'agenda da empresa' : 'agenda pessoal'}</span>
                  ${c.grupo ? `<span class="badge">${esc(c.grupo)}</span>` : ''}
                  ${Number(c.favorito) ? '<span class="badge badge-warn">favorito</span>' : ''}
                </div>
              </div>
            </div>

            ${bloco('Telefones', tels.map(t => `<div class="row-between ficha-linha">
                <span><span class="mono">${esc(t.valor)}</span>
                  <span class="tiny muted">${esc(t.rotulo.toLowerCase())}</span></span>
                <button class="btn btn-outline btn-sm" data-ficha-ligar="${esc(t.valor)}">
                  ${icon('phone','ico ico-sm')} Ligar</button></div>`).join(''))}

            ${bloco('Discagem rápida', c.discagem_rapida
              ? `<div class="ficha-linha">Disque <b class="mono">*0${esc(c.discagem_rapida)}</b>
                   de qualquer ramal para ligar para este contato.</div>` : '')}

            ${bloco('E-mail', [c.email, c.email_alt].filter(Boolean).map(e =>
              `<div class="ficha-linha"><a href="mailto:${esc(e)}">${esc(e)}</a></div>`).join(''))}

            ${bloco('Endereço', endereco ? `<div class="ficha-linha">${esc(endereco)}</div>` : '')}

            ${bloco('Links', [
              ...(c.site ? [{ rotulo: 'Site', url: c.site }] : []), ...links
            ].map(l => `<div class="ficha-linha">
                <a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${esc(l.rotulo)}</a>
              </div>`).join(''))}

            ${bloco('Aniversário', c.aniversario
              ? `<div class="ficha-linha">${esc(String(c.aniversario).split('-').reverse().join('/'))}</div>` : '')}

            ${bloco('Anotações', c.notas ? `<div class="ficha-linha">${esc(c.notas)}</div>` : '')}

            ${c.escopo === 'pessoal' && c.dono_nome && !c.meu
              ? `<div class="ficha-bloco"><span class="tiny muted">Agenda pessoal de ${esc(c.dono_nome)}</span></div>`
              : ''}`,
          rodape: `<button class="btn btn-outline" data-drawer-close>Fechar</button>
                   ${c.editavel && ctx.can('editar')
                     ? '<button class="btn btn-primary" id="fichaEditar">Editar</button>' : ''}`,
          aoAbrir: dw => {
            dw.querySelectorAll('[data-ficha-ligar]').forEach(b =>
              b.onclick = () => { Drawer.close(); Softphone.discarPara(b.dataset.fichaLigar, c.nome); });
            const editar = dw.querySelector('#fichaEditar');
            if (editar) editar.onclick = () => formulario(c);
          }
        });
      };

      document.querySelectorAll('[data-ver]').forEach(b => b.onclick = () =>
        verFicha(d.dados.find(x => String(x.id) === b.dataset.ver)));

      // ---------- formulário ----------
      const formulario = item => {
        const novo = !item;
        const c = item || { ativo: 1, escopo: cfg.escopoPadrao };
        const campos = camposContato(d.administra);
        const abas = [...new Set(campos.map(x => x.aba))];
        const corpoAba = aba => `<div class="form-grid">${campos
          .filter(x => x.aba === aba).map(x => campoHtml(x, c)).join('')}</div>`;

        Drawer.open({
          titulo: novo ? 'Novo contato' : `Editar ${c.nome}`,
          sub: novo
            ? 'Só o nome e uma forma de contato são obrigatórios — o resto você completa quando quiser.'
            : 'A foto é salva na hora; os demais campos, ao clicar em Salvar.',
          wide: true,
          corpo: `
            ${novo ? '' : `<div class="foto-editor">
              <div id="fotoPreview">${fotoContato(c, 'grande')}</div>
              <div class="grow">
                <div class="label">Foto</div>
                <input class="input" type="file" id="arquivoFoto" accept="image/jpeg,image/png,image/webp,image/gif">
                <span class="hint">JPG, PNG, WebP ou GIF, até 4 MB. É reduzida para 512 px no servidor.</span>
              </div>
              ${c.foto_url ? '<button class="btn btn-outline btn-sm" id="tirarFoto">Remover</button>' : ''}
            </div>`}
            <div class="tabs" data-abas>${abas.map((a, i) =>
              `<button class="tab ${i === 0 ? 'on' : ''}" data-aba="${esc(a)}">${esc(a)}</button>`).join('')}</div>
            ${abas.map((a, i) => `<div data-painel="${esc(a)}" ${i ? 'hidden' : ''}>${corpoAba(a)}</div>`).join('')}
            ${novo ? '<p class="hint" style="margin-top:14px">A foto pode ser enviada depois de salvar.</p>' : ''}`,
          rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                   <button class="btn btn-primary" data-ok>${novo ? 'Cadastrar' : 'Salvar'}</button>`,
          aoAbrir: dw => {
            dw.querySelectorAll('[data-aba]').forEach(t => t.onclick = () => {
              dw.querySelectorAll('[data-aba]').forEach(x => x.classList.remove('on'));
              t.classList.add('on');
              dw.querySelectorAll('[data-painel]').forEach(p => p.hidden = p.dataset.painel !== t.dataset.aba);
            });

            // foto: envia na hora, para o usuário ver o resultado
            dw.querySelector('#arquivoFoto')?.addEventListener('change', async ev => {
              const arquivo = ev.target.files[0];
              if (!arquivo) return;
              const fd = new FormData();
              fd.append('foto', arquivo);
              try {
                const r = await Api.upload(`/contatos/${c.id}/foto`, fd);
                dw.querySelector('#fotoPreview').innerHTML =
                  `<img class="foto-contato grande" src="${esc(r.foto_url)}?t=${Date.now()}" alt="">`;
                toast('Foto atualizada.', 'ok');
              } catch (e) { toast(e.message, 'err'); }
            });

            dw.querySelector('#tirarFoto')?.addEventListener('click', async () => {
              try {
                await Api.delete(`/contatos/${c.id}/foto`);
                dw.querySelector('#fotoPreview').innerHTML = `<span class="avatar avatar-lg">${initials(c.nome)}</span>`;
                toast('Foto removida.', 'ok');
              } catch (e) { toast(e.message, 'err'); }
            });

            dw.querySelector('[data-ok]').onclick = async ev => {
              // O aviso de obrigatório é o do servidor para "um meio de contato";
              // aqui só conferimos o que dá para conferir na tela.
              if (!validarCampos(dw, campos)) return;

              const dados = {};
              campos.forEach(x => {
                const el = dw.querySelector(`[name="${x.campo}"]`);
                if (!el) return;
                dados[x.campo] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
              });
              if (novo && !d.administra) dados.escopo = cfg.escopoPadrao;

              const botao = ev.currentTarget;
              botao.disabled = true;
              botao.innerHTML = '<span class="spin"></span> Salvando…';
              try {
                if (novo) await Api.post('/contatos', dados);
                else await Api.put(`/contatos/${c.id}`, dados);
                Drawer.close();
                toast(novo ? 'Contato cadastrado.' : 'Contato atualizado.', 'ok');
                App.route();
              } catch (e) {
                botao.disabled = false;
                botao.textContent = novo ? 'Cadastrar' : 'Salvar';
                if (e.detalhe?.campo) {
                  // O campo pode estar numa aba fechada; abrimos a aba dele.
                  const alvo = campos.find(x => x.campo === e.detalhe.campo);
                  if (alvo) dw.querySelector(`[data-aba="${alvo.aba}"]`)?.click();
                  marcarErro(dw, e.detalhe.campo, e.message);
                  avisoFormulario(dw, [`${alvo?.label || e.detalhe.campo}: ${e.message}`]);
                } else {
                  toast(e.message, 'err');
                }
              }
            };
          }
        });
      };

      document.querySelectorAll('#novoContato').forEach(b => b.onclick = () => formulario(null));
      document.querySelectorAll('[data-editar-contato]').forEach(b => b.onclick = () =>
        formulario(d.dados.find(x => String(x.id) === b.dataset.editarContato)));

      document.querySelectorAll('[data-excluir-contato]').forEach(b => b.onclick = async () => {
        const c = d.dados.find(x => String(x.id) === b.dataset.excluirContato);
        const ok = await Modal.confirm({
          titulo: `Excluir ${c.nome}?`,
          texto: c.escopo === 'corporativo'
            ? 'O contato some da agenda da empresa para todos os usuários.'
            : 'O contato some da sua agenda pessoal.',
          ok: 'Excluir'
        });
        if (!ok) return;
        try { await Api.delete(`/contatos/${c.id}`); toast('Contato excluído.', 'ok'); App.route(); }
        catch (e) { toast(e.message, 'err'); }
      });
    }
  };
}

PAGES['admin.contatos'] = paginaAgenda({
  escopoPadrao: 'corporativo',
  titulo: 'Gerenciador de Contatos',
  sub: `A agenda da empresa, visível para todo mundo, e as agendas pessoais dos usuários.
        Nome, empresa, cargo, telefones, e-mails, endereço, links e foto.`
});

PAGES['pcu.contatos'] = paginaAgenda({
  escopoPadrao: 'pessoal',
  titulo: 'Meus Contatos',
  sub: 'Sua agenda pessoal, mais a agenda da empresa. Clique em Ligar para discar pelo softphone.'
});

/* ------------------------- Configurações · Conjuntos de PIN ------------------------- */
PAGES['cfg.pinsets'] = paginaCrud({
  recurso: 'pin-sets',
  titulo: 'Conjuntos de PIN',
  sub: `Senhas que a central pede antes de completar uma chamada. Depois de criados,
        aparecem nas rotas de saída — é assim que se controla quem liga para
        interurbano e internacional sem trancar o ramal inteiro.`,
  ico: 'lock',
  plural: 'conjuntos',
  rotuloNovo: 'Novo conjunto',
  tituloNovo: 'Novo conjunto de PIN',
  tituloEditar: p => `Conjunto ${p.nome}`,
  tituloExcluir: p => `Excluir o conjunto ${p.nome}?`,
  vazioTitulo: 'Nenhum conjunto de PIN',
  vazioTexto: `Crie um conjunto, liste os PINs e escolha-o numa rota de saída. Quem discar
               por essa rota vai ouvir o pedido de senha antes de a chamada sair.`,
  placeholderBusca: 'Buscar por nome…',
  textoBusca: p => p.nome,

  colunas: [
    { label: 'Nome', render: p => `<b>${esc(p.nome)}</b>` },
    { label: 'Quantos PINs', render: p => {
        const n = String(p.pins || '').split(/[\s,;]+/).filter(Boolean).length;
        return `<span class="num">${n}</span>`;
      } },
    { label: 'No relatório', render: p => Number(p.no_cdr)
        ? '<span class="muted">não identifica quem discou</span>'
        : '<span class="badge badge-brand">PIN vai para o CDR</span>' }
  ],

  campos: () => [
    { campo: 'nome', label: 'Nome do conjunto', obrigatorio: true, placeholder: 'Diretoria',
      ajuda: 'É o nome que aparece na hora de escolher o conjunto numa rota de saída.' },
    { campo: 'pins', label: 'PINs', tipo: 'textarea', largura: 'full',
      placeholder: '4721\n8890\n1234',
      padraoValido: /^[0-9\s,;]*$/,
      mensagemPadrao: 'Só dígitos, um PIN por linha.',
      ajuda: 'Um por linha. Só dígitos — letra ou símbolo faz a central recusar o PIN certo. '
           + 'Ao editar, em branco mantém os que já estão gravados.' },
    { campo: 'no_cdr', label: 'Não identificar quem discou', tipo: 'switch', padrao: 1,
      largura: 'full',
      ajuda: 'Desligue para o PIN digitado ir para o relatório de chamadas. '
           + 'É o que permite cobrar a ligação de um centro de custo — e também '
           + 'o que torna possível saber quem ligou.' }
  ]
});

/* ------------------------- Configurações · Música em espera ------------------------- */
PAGES['cfg.musica'] = {
  async render(ctx) {
    let audios, filas;
    try {
      [audios, filas] = await Promise.all([
        Api.get('/audios', { categoria: 'espera', limite: 200 }),
        Api.get('/filas', { limite: 200 }).catch(() => ({ dados: [] }))
      ]);
    } catch (e) { return pageHead('Música em Espera', '') + blocoErro(e); }

    // Quem usa cada música: trocar uma que está no ar sem saber é o
    // tipo de coisa que só aparece no primeiro cliente reclamando.
    const uso = {};
    (filas.dados || []).forEach(f => {
      (uso[f.musica_espera || 'default'] ||= []).push(`fila ${f.numero}`);
    });

    const linhas = (audios.dados || []).map(a => `
      <tr data-arquivo="${esc(a.arquivo)}" data-id="${a.id}">
        <td><b>${esc(a.nome)}</b><div class="tiny muted mono">${esc(a.arquivo)}</div></td>
        <td class="num">${a.duracao ? duracao(a.duracao) : '—'}</td>
        <td class="small dim">${esc((a.formatos || '').split(',').join(' · ') || '—')}</td>
        <td>${(uso[a.arquivo] || []).length
          ? (uso[a.arquivo]).map(u => `<span class="badge badge-brand">${esc(u)}</span>`).join(' ')
          : '<span class="muted small">nenhuma fila usa</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ouvir" data-ouvir>
            ${icon('play','ico ico-sm')}</button>
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir
            data-id="${a.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td>
      </tr>`).join('');

    return pageHead('Música em Espera',
      `O que o cliente ouve enquanto espera na fila ou fica em espera numa chamada.`,
      ctx.can('criar')
        ? `<button class="btn btn-primary btn-sm" id="mohNovo">
             ${icon('upload','ico ico-sm')} Enviar música</button>`
        : '') + `
      <div class="card" style="padding:14px;margin-bottom:14px">
        <div class="row gap-12" style="align-items:flex-start">
          ${icon('info','ico')}
          <div class="small muted">
            <b>A classe "Padrão do sistema"</b> usa os sons que vêm com o Asterisk e está sempre
            disponível. Cada música enviada aqui vira uma opção na hora de montar a fila.
            Um arquivo só já serve — a central o repete enquanto durar a espera.
          </div>
        </div>
      </div>

      <div class="card">
        ${linhas ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Música</th><th class="num">Duração</th><th>Formatos</th>
            <th>Em uso</th><th class="col-actions"></th></tr></thead>
          <tbody>${linhas}</tbody></table></div>`
        : vazio('music', 'Nenhuma música enviada',
            'As filas usam a música padrão do Asterisk. Envie um arquivo para ter a sua.',
            ctx.can('criar')
              ? '<button class="btn btn-primary btn-sm" id="mohNovo2">Enviar música</button>' : '')}
      </div>`;
  },

  mount(ctx) {
    const abrir = () => this.enviar();
    document.getElementById('mohNovo')?.addEventListener('click', abrir);
    document.getElementById('mohNovo2')?.addEventListener('click', abrir);

    document.querySelectorAll('[data-ouvir]').forEach(b => b.addEventListener('click', async () => {
      const tr = b.closest('tr');
      const aberto = tr.nextElementSibling?.hasAttribute('data-player-moh');
      document.querySelectorAll('[data-player-moh]').forEach(l => {
        l.querySelector('audio')?.pause(); l.remove();
      });
      if (aberto) return;

      // O arquivo não é servido direto pela web: vem pela API, que
      // aplica a permissão do módulo. Por isso o blob.
      b.disabled = true;
      b.innerHTML = '<span class="spin"></span>';
      try {
        const { blob } = await Api.baixar(`/audios/${tr.dataset.id}/ouvir`);
        const url = URL.createObjectURL(blob);
        tr.insertAdjacentHTML('afterend', `
          <tr data-player-moh><td colspan="5" style="background:var(--surface-2)">
            <audio controls autoplay preload="auto" style="width:100%;height:38px" src="${url}"></audio>
          </td></tr>`);
        tr.nextElementSibling.querySelector('audio')
          .addEventListener('ended', () => setTimeout(() => URL.revokeObjectURL(url), 1000));
      } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
      b.innerHTML = icon('play', 'ico ico-sm');
    }));

    document.querySelectorAll('[data-excluir]').forEach(b => b.addEventListener('click', async () => {
      const ok = await Modal.confirm({
        titulo: 'Excluir esta música?',
        texto: 'As filas que a usam voltam para a música padrão do sistema na próxima aplicação.',
        ok: 'Excluir'
      });
      if (!ok) return;
      try {
        await Api.delete(`/audios/${b.dataset.id}`);
        toast('Música excluída', 'ok');
        App.route();
      } catch (e) { toast(e.message, 'err'); }
    }));
  },

  enviar() {
    Drawer.open({
      titulo: 'Enviar música em espera',
      sub: 'WAV, MP3 ou GSM. A central converte para o formato que o Asterisk toca.',
      corpo: `<form id="fMoh" class="form-grid">
        ${campoHtml({ campo: 'nome', label: 'Nome', obrigatorio: true,
          placeholder: 'Jazz da recepção', largura: 'full' }, {})}
        <div class="field full">
          <label class="label">Arquivo de áudio *</label>
          <input class="input" type="file" name="arquivo" accept="audio/*" required>
          <span class="hint">Até 20 MB. Um arquivo só basta: a central o repete.</span>
        </div>
      </form>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" data-enviar>Enviar</button>`,
      aoAbrir: dw => {
        dw.querySelector('[data-enviar]').onclick = async ev => {
          const botao = ev.currentTarget;
          const form = dw.querySelector('#fMoh');
          const arquivo = form.querySelector('[name=arquivo]').files[0];
          const nome = form.querySelector('[name=nome]').value.trim();

          if (!nome || !arquivo) { toast('Informe o nome e escolha o arquivo', 'err'); return; }

          botao.disabled = true;
          botao.innerHTML = '<span class="spin"></span> enviando…';
          const fd = new FormData();
          fd.append('arquivo', arquivo);
          fd.append('nome', nome);
          fd.append('categoria', 'espera');
          try {
            await Api.upload('/audios', fd);
            Drawer.close();
            toast('Música enviada. Aplique as configurações para as filas passarem a usá-la.', 'ok');
            App.route();
          } catch (e) {
            botao.disabled = false;
            botao.textContent = 'Enviar';
            toast(e.message, 'err');
          }
        };
      }
    });
  }
};

/* ------------------------- Aplicações · Megafonia e Interfonia ------------------------- */
PAGES['apps.paging'] = paginaCrud({
  recurso: 'grupos-paging',
  titulo: 'Megafonia e Interfonia',
  sub: `Um número que abre o viva-voz de vários aparelhos ao mesmo tempo — o
        "atenção, loja" do balcão, ou o interfone entre duas salas.`,
  ico: 'volume',
  plural: 'grupos',
  rotuloNovo: 'Novo grupo',
  tituloNovo: 'Novo grupo de megafonia',
  tituloEditar: g => `Grupo ${g.numero}`,
  tituloExcluir: g => `Excluir o grupo ${g.numero}?`,
  vazioTitulo: 'Nenhum grupo de megafonia',
  vazioTexto: `Crie um grupo, escolha os aparelhos e disque o número dele: todos abrem o
               viva-voz e ouvem quem chamou. Para falar com um ramal só, o código
               <span class="mono">*81</span> mais o número já funciona.`,
  placeholderBusca: 'Buscar por número ou nome…',
  textoBusca: g => `${g.numero} ${g.nome}`,

  colunas: [
    { label: 'Número', render: g => `<b class="mono">${esc(g.numero)}</b>` },
    { label: 'Grupo', render: g => `<b>${esc(g.nome)}</b>` },
    { label: 'Aparelhos', render: g => {
        const r = String(g.ramais || '').split('-').filter(Boolean);
        return r.length
          ? r.map(x => `<span class="badge mono">${esc(x)}</span>`).join(' ')
          : '<span class="muted">nenhum</span>';
      } },
    { label: 'Sentido', render: g => Number(g.duplex)
        ? '<span class="badge badge-brand">todos falam</span>'
        : '<span class="badge">só quem chamou fala</span>' },
    { label: 'Estado', render: g => Number(g.ativo)
        ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge">Desativado</span>' }
  ],

  aoCarregar: async pagina => {
    pagina._ramais = (await Api.get('/ramais', { limite: 500 }).catch(() => ({ dados: [] }))).dados;
    pagina._anuncios = (await Api.get('/anuncios', { limite: 200 }).catch(() => ({ dados: [] }))).dados;
  },

  campos: (g, ctx, pagina) => [
    { campo: 'numero', label: 'Número do grupo', obrigatorio: true, mono: true, placeholder: '700',
      padraoValido: /^[0-9*#]{2,10}$/, mensagemPadrao: 'Só dígitos, * ou #.',
      ajuda: 'É o que se disca para falar com o grupo.' },
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Loja' },
    { campo: 'ramais', label: 'Aparelhos do grupo', obrigatorio: true, mono: true,
      largura: 'full', placeholder: '1001-1002-1003',
      ajuda: (pagina._ramais || []).length
        ? 'Números separados por hífen. Cadastrados: '
          + (pagina._ramais || []).map(r => r.numero).join(', ')
        : 'Números separados por hífen.' },
    { campo: 'duplex', label: 'Todos podem falar (interfonia)', tipo: 'switch', largura: 'full',
      ajuda: 'Desligado, os aparelhos só ouvem. Ligado, vira conversa de mão dupla — '
           + 'útil entre duas salas, confuso com dez aparelhos.' },
    { campo: 'forcar', label: 'Interromper quem está em chamada', tipo: 'switch', largura: 'full',
      ajuda: 'Ignora quem recusou megafonia pelo código *82. Use com parcimônia.' },
    { campo: 'anuncio_id', label: 'Tocar antes de abrir o som', tipo: 'select', largura: 'full',
      opcoes: [{ valor: '', rotulo: 'nada — abre direto' },
               ...(pagina._anuncios || []).map(a => ({ valor: a.id, rotulo: a.nome }))],
      ajuda: 'Um bipe ou aviso curto evita o susto de o aparelho abrir som do nada.' },
    { campo: 'duracao_max', label: 'Tempo máximo (segundos)', tipo: 'number', padrao: 60 },
    { campo: 'ativo', label: 'Grupo ativo', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Aplicações · DISA ------------------------- */
PAGES['apps.disa'] = paginaCrud({
  recurso: 'disa',
  titulo: 'DISA — Discagem Direta',
  sub: `Ligar de fora, ouvir o tom da central e discar como se estivesse na mesa.
        Depois de criada, aponte uma rota de entrada ou uma opção de URA para ela.`,
  ico: 'key',
  plural: 'DISAs',
  rotuloNovo: 'Nova DISA',
  tituloNovo: 'Nova DISA',
  tituloEditar: d => `DISA ${d.nome}`,
  tituloExcluir: d => `Excluir a DISA ${d.nome}?`,
  vazioTitulo: 'Nenhuma DISA',
  vazioTexto: `Serve para quem está na rua discar pela central — e é, junto com o correio de
               voz sem senha, o caminho mais explorado por fraude de tarifação. Se criar uma,
               use senha longa e o contexto mais restrito que resolver.`,
  placeholderBusca: 'Buscar por nome…',
  textoBusca: d => d.nome,

  colunas: [
    { label: 'Nome', render: d => `<b>${esc(d.nome)}</b>` },
    { label: 'Até onde disca', render: d => ({
        'telium-ramais': '<span class="badge badge-ok">só ramais internos</span>',
        'telium-bloqueado': '<span class="badge">nenhuma saída</span>',
        'interno': '<span class="badge badge-warn">ramais e rotas de saída</span>'
      }[d.contexto] || `<span class="badge mono">${esc(d.contexto)}</span>`) },
    { label: 'CID de saída', render: d => d.cid_saida
        ? `<span class="mono">${esc(d.cid_saida)}</span>`
        : '<span class="muted small">o do tronco</span>' },
    { label: 'Estado', render: d => Number(d.ativo)
        ? '<span class="badge badge-ok">Ativa</span>' : '<span class="badge">Desativada</span>' }
  ],

  campos: () => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Diretoria em viagem' },
    { campo: 'senha', label: 'Senha', mono: true, tipo: 'password',
      padraoValido: /^[0-9]{0,20}$/, mensagemPadrao: 'Só dígitos.',
      placeholder: 'mínimo 6 dígitos',
      ajuda: 'De 6 a 20 dígitos. É o que separa a sua central de quem discar o número por acaso — '
           + 'não use 1234 nem o número do ramal. Ao editar, em branco mantém a atual.' },
    { campo: 'contexto', label: 'Até onde quem entrou pode discar', tipo: 'select', largura: 'full',
      opcoes: [
        { valor: 'telium-ramais', rotulo: 'Só ramais internos — mais seguro' },
        { valor: 'interno', rotulo: 'Ramais e rotas de saída permitidas' },
        { valor: 'telium-bloqueado', rotulo: 'Nenhuma saída (para desativar sem excluir)' }
      ], padrao: 'telium-ramais',
      ajuda: 'Liberar as rotas de saída é o que torna a DISA útil para quem viaja — e o que '
           + 'torna o estrago grande se a senha vazar.' },
    { campo: 'cid_saida', label: 'Número de saída', mono: true, placeholder: '1140041000',
      ajuda: 'O que a operadora recebe nas chamadas feitas por aqui. Em branco, vale o do tronco.' },
    { campo: 'tempo_digito', label: 'Espera por dígito (segundos)', tipo: 'number', padrao: 10 },
    { campo: 'responder', label: 'Atender a chamada antes de pedir a senha', tipo: 'switch',
      padrao: 1, ajuda: 'Desligado, a operadora não tarifa enquanto ninguém digita.' },
    { campo: 'ativo', label: 'DISA ativa', tipo: 'switch', padrao: 1 }
  ]
});

/* ------------------------- Relatórios · Por ramal ------------------------- */
PAGES['rel.ramais'] = {
  _f: { de: '', ate: '' },

  async render(ctx) {
    let d;
    try { d = await Api.get('/relatorios/ramais', this._f); }
    catch (e) { return pageHead('Relatório por Ramal', '') + blocoErro(e); }

    const linhas = d.dados.filter(r => r.total > 0);
    const topo = Math.max(1, ...linhas.map(r => Number(r.segundos)));

    const corpo = linhas.length ? linhas.map(r => `
      <tr>
        <td><b class="mono">${esc(r.numero)}</b>
          <div class="tiny muted">${esc(r.nome || '')}${r.setor ? ` · ${esc(r.setor)}` : ''}</div></td>
        <td class="num">${num(r.feitas)}</td>
        <td class="num">${num(r.recebidas)}</td>
        <td class="num">${Number(r.perdidas) > 0
          ? `<span class="badge badge-warn">${num(r.perdidas)}</span>` : '—'}</td>
        <td class="num">${duracao(r.segundos)}</td>
        <td style="width:180px">
          <div class="barra-uso"><span style="width:${Math.round(r.segundos / topo * 100)}%"></span></div>
        </td>
      </tr>`).join('') : '';

    return pageHead('Relatório por Ramal',
      'Quanto cada ramal falou no período — e quantas chamadas deixou de atender.') + `
      ${filtroPeriodo(this._f)}
      <div class="card">
        ${corpo ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Ramal</th><th class="num">Feitas</th><th class="num">Recebidas</th>
            <th class="num">Não atendidas</th><th class="num">Tempo falado</th><th></th></tr></thead>
          <tbody>${corpo}</tbody></table></div>`
        : vazio('phone', 'Nenhuma chamada no período',
            'Quando houver movimento, cada ramal aparece aqui com o que fez e o que recebeu.')}
      </div>`;
  },

  mount() { ligarFiltroPeriodo(this._f); }
};

/* ------------------------- Relatórios · Por tronco ------------------------- */
PAGES['rel.troncos'] = {
  _f: { de: '', ate: '' },

  async render(ctx) {
    let d;
    try { d = await Api.get('/relatorios/troncos', this._f); }
    catch (e) { return pageHead('Relatório por Tronco', '') + blocoErro(e); }

    const corpo = (d.dados || []).map(t => {
      // ASR baixo é o número que se leva para a operadora quando a
      // reclamação é "a linha não completa".
      const asr = t.asr === null ? null : Number(t.asr);
      const tomAsr = asr === null ? '' : asr >= 60 ? 'badge-ok' : asr >= 40 ? 'badge-warn' : 'badge-danger';
      return `<tr>
        <td><b>${esc(t.nome)}</b><div class="tiny muted mono">${esc(t.host || '')}</div></td>
        <td>${Number(t.ativo) ? '<span class="badge badge-ok">Ativo</span>'
                              : '<span class="badge badge-warn">Desativado</span>'}</td>
        <td class="num">${num(t.chamadas)}</td>
        <td class="num">${num(t.atendidas)}</td>
        <td class="num">${num(t.ocupadas)}</td>
        <td class="num">${num(Number(t.falhas) + Number(t.sem_resposta))}</td>
        <td>${asr === null ? '<span class="muted">—</span>'
          : `<span class="badge ${tomAsr}">${asr}%</span>`}</td>
        <td class="num">${duracao(t.acd)}</td>
        <td class="num">${duracao(t.segundos)}</td>
      </tr>`;
    }).join('');

    return pageHead('Relatório por Tronco',
      'Volume e qualidade de cada operadora. Use ao cobrar quando a linha não completa.') + `
      ${filtroPeriodo(this._f)}
      <div class="card" style="padding:14px;margin-bottom:14px">
        <div class="row gap-12" style="align-items:flex-start">
          ${icon('info','ico')}
          <div class="small muted">
            <b>Completamento</b> é quanto das chamadas o tronco levou até alguém atender.
            Abaixo de 40% costuma ser problema da operadora ou da rota, não do seu PABX.
            <b>Média</b> é quanto durou a chamada atendida — uma média muito curta com
            completamento alto costuma ser áudio ruim, com o cliente desligando.
          </div>
        </div>
      </div>
      <div class="card">
        ${corpo ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Tronco</th><th>Estado</th><th class="num">Chamadas</th>
            <th class="num">Atendidas</th><th class="num">Ocupado</th><th class="num">Falha</th>
            <th>Completamento</th><th class="num">Média</th><th class="num">Total falado</th></tr></thead>
          <tbody>${corpo}</tbody></table></div>`
        : vazio('server', 'Nenhum tronco cadastrado',
            'Cadastre um tronco em Conectividade › Troncos para começar a medir.')}
      </div>`;
  },

  mount() { ligarFiltroPeriodo(this._f); }
};

/* ------------------------- Relatórios · Eventos da chamada (CEL) ------------------------- */
PAGES['rel.cel'] = {
  _f: { de: '', ate: '', linkedid: '' },

  async render(ctx) {
    let d;
    try { d = await Api.get('/relatorios/eventos', this._f); }
    catch (e) { return pageHead('Eventos da Chamada', '') + blocoErro(e); }

    if (d.chamada) {
      const passos = (d.dados || []).map(e => `
        <tr>
          <td class="small mono">${esc(String(e.eventtime).slice(11, 23))}</td>
          <td><span class="badge">${esc(e.eventtype)}</span></td>
          <td class="mono small">${esc(e.channame || '—')}</td>
          <td class="mono">${esc(e.cid_num || '—')}</td>
          <td class="mono">${esc(e.exten || '—')}</td>
          <td class="small dim">${esc(e.appname || '')} ${esc((e.appdata || '').slice(0, 60))}</td>
        </tr>`).join('');

      return pageHead(`Chamada ${esc(d.chamada)}`,
        'Cada passo que a central registrou, na ordem em que aconteceu.',
        `<button class="btn btn-outline btn-sm" id="celVoltar">
           ${icon('chevronL','ico ico-sm')} Voltar à lista</button>`) + `
        <div class="card">
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Hora</th><th>Evento</th><th>Canal</th><th>Origem</th>
              <th>Destino</th><th>Aplicação</th></tr></thead>
            <tbody>${passos}</tbody></table></div>
        </div>`;
    }

    const linhas = (d.dados || []).map(c => `
      <tr data-chamada="${esc(c.linkedid)}" style="cursor:pointer">
        <td class="small">${dataHora(c.inicio)}</td>
        <td class="mono">${esc(c.origem || '—')}</td>
        <td class="mono">${esc(c.destino || '—')}</td>
        <td class="num">${num(c.eventos)}</td>
        <td class="mono small dim">${esc(c.linkedid)}</td>
      </tr>`).join('');

    return pageHead('Eventos da Chamada',
      `O relatório de chamadas diz que a ligação durou doze segundos. Este diz por quê.`) + `
      ${filtroPeriodo(this._f)}
      <div class="card">
        ${linhas ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Início</th><th>Origem</th><th>Destino</th>
            <th class="num">Passos</th><th>Identificador</th></tr></thead>
          <tbody>${linhas}</tbody></table></div>`
        : vazio('activity', 'Nenhum evento no período',
            'A central registra cada passo das chamadas aqui — atendeu, transferiu, desligou.')}
      </div>`;
  },

  mount() {
    ligarFiltroPeriodo(this._f);
    document.getElementById('celVoltar')?.addEventListener('click', () => {
      this._f.linkedid = ''; App.route();
    });
    document.querySelectorAll('[data-chamada]').forEach(tr =>
      tr.addEventListener('click', () => {
        this._f.linkedid = tr.dataset.chamada; App.route();
      }));
  }
};

/** Filtro de período comum aos relatórios. */
function filtroPeriodo(f) {
  return `<div class="card" style="padding:14px;margin-bottom:14px">
    <div class="row gap-8 wrap">
      <label class="small muted" style="align-self:center">Período</label>
      <input class="input" type="date" id="relDe" value="${esc(f.de)}" style="max-width:170px">
      <input class="input" type="date" id="relAte" value="${esc(f.ate)}" style="max-width:170px">
      <button class="btn btn-outline btn-sm" id="relLimpar">Tudo</button>
      <span class="grow"></span>
      <span class="small muted">${f.de || f.ate
        ? `${f.de || 'início'} até ${f.ate || 'hoje'}` : 'todo o histórico'}</span>
    </div>
  </div>`;
}

function ligarFiltroPeriodo(f) {
  document.getElementById('relDe')?.addEventListener('change', e => { f.de = e.target.value; App.route(); });
  document.getElementById('relAte')?.addEventListener('change', e => { f.ate = e.target.value; App.route(); });
  document.getElementById('relLimpar')?.addEventListener('click', () => {
    f.de = ''; f.ate = ''; App.route();
  });
}

/* ------------------------- Configurações · Notificações e E-mail ------------------------- */
PAGES['cfg.notificacoes'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/email'); }
    catch (e) { return pageHead('Notificações e E-mail', '') + blocoErro(e); }

    const c = d.config || {};
    const ligado = Number(c.ativo) === 1;
    const testado = c.testado_em
      ? (Number(c.teste_ok)
          ? `<span class="badge badge-ok">último teste entregue · ${dataHora(c.testado_em)}</span>`
          : `<span class="badge badge-danger">último teste falhou · ${dataHora(c.testado_em)}</span>`)
      : '<span class="badge">nunca testado</span>';

    return pageHead('Notificações e E-mail',
      'Por onde a central manda a cópia do correio de voz e os avisos do sistema.') + `
      <div class="grid g-2">
        <form class="card" id="fSmtp" style="padding:18px">
          <div class="row-between" style="margin-bottom:14px">
            <b>Servidor de saída</b>
            ${ligado ? '<span class="badge badge-ok">ligado</span>' : '<span class="badge">desligado</span>'}
          </div>

          ${campoHtml({ campo: 'ativo', label: 'Enviar e-mail por este servidor', tipo: 'switch',
            largura: 'full' }, c)}
          ${campoHtml({ campo: 'servidor', label: 'Servidor', mono: true,
            placeholder: 'smtp.gmail.com', largura: 'full',
            ajuda: 'O relay do seu provedor. A central não roda servidor de e-mail próprio — '
                 + 'seria caixa de entrada bloqueada por reputação de IP em poucos dias.' }, c)}
          ${campoHtml({ campo: 'porta', label: 'Porta', tipo: 'number', padrao: 587 }, c)}
          ${campoHtml({ campo: 'seguranca', label: 'Segurança', tipo: 'select',
            opcoes: [{ valor: 'starttls', rotulo: 'STARTTLS (587) — o mais comum' },
                     { valor: 'tls', rotulo: 'TLS direto (465)' },
                     { valor: 'nenhuma', rotulo: 'Nenhuma (25, rede interna)' }],
            padrao: 'starttls' }, c)}
          ${campoHtml({ campo: 'usuario', label: 'Usuário', largura: 'full',
            placeholder: 'pabx@suaempresa.com.br',
            ajuda: 'Em branco, a central não autentica — só serve em relay interno.' }, c)}
          ${campoHtml({ campo: 'senha', label: 'Senha', tipo: 'password', largura: 'full',
            placeholder: d.tem_senha ? 'deixe em branco para manter a atual' : '',
            ajuda: 'No Gmail e no Microsoft 365 é uma senha de aplicativo, não a sua senha de entrada.' }, c)}
          ${campoHtml({ campo: 'remetente', label: 'Remetente (De:)', largura: 'full',
            placeholder: 'pabx@suaempresa.com.br',
            ajuda: 'Muitos provedores recusam a mensagem quando o remetente não é do domínio autenticado.' }, c)}
          ${campoHtml({ campo: 'nome_remetente', label: 'Nome do remetente', largura: 'full',
            placeholder: 'Central Telefônica' }, c)}

          <div class="row gap-8" style="margin-top:16px">
            <button class="btn btn-primary btn-sm" type="submit">
              ${icon('check','ico ico-sm')} Salvar</button>
            <span class="small muted" id="smtpEstado"></span>
          </div>
        </form>

        <div class="card" style="padding:18px">
          <div class="row-between" style="margin-bottom:12px">
            <b>Conferir</b>${testado}
          </div>
          <p class="small muted" style="margin-bottom:14px">
            Mande uma mensagem de verdade agora. Sem isso, "configurei o e-mail" só vira
            verdade na primeira vez que alguém deixa um recado — e quem descobre que não
            funciona é o cliente.</p>

          <form id="fTeste" class="row gap-8" style="align-items:flex-end">
            <div class="field grow" style="margin:0">
              <label class="label">Mandar para</label>
              <input class="input" name="para" type="email" placeholder="voce@suaempresa.com.br"
                     ${ligado ? '' : 'disabled'}>
            </div>
            <button class="btn btn-outline btn-sm" type="submit" ${ligado ? '' : 'disabled'}>
              ${icon('mail','ico ico-sm')} Enviar teste</button>
          </form>
          ${ligado ? '' : '<p class="tiny muted" style="margin-top:8px">Ligue e salve antes de testar.</p>'}
          <div id="testeSaida" style="margin-top:12px"></div>

          ${c.teste_saida && !Number(c.teste_ok)
            ? `<pre class="detalhe" style="margin-top:12px">${esc(c.teste_saida)}</pre>` : ''}

          <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
            <b class="small">Quem usa isto</b>
            <ul class="small muted lista-ajuda" style="margin-top:8px">
              <li>A cópia do recado do correio de voz, quando o ramal pede.</li>
              <li>O ditado enviado pelo código <span class="mono">*35</span>.</li>
              <li>Os avisos de falha de backup e de validade de certificado.</li>
            </ul>
          </div>
        </div>
      </div>`;
  },

  mount() {
    document.getElementById('fSmtp')?.addEventListener('submit', async ev => {
      ev.preventDefault();
      const estado = document.getElementById('smtpEstado');
      estado.textContent = 'salvando…';
      try {
        const r = await Api.put('/email', lerFormulario(ev.currentTarget));
        estado.textContent = '';
        toast(r.detalhe, r.publicado ? 'ok' : 'warn');
        App.route();
      } catch (e) { estado.textContent = ''; toast(e.message, 'err'); }
    });

    document.getElementById('fTeste')?.addEventListener('submit', async ev => {
      ev.preventDefault();
      const botao = ev.currentTarget.querySelector('button');
      const saida = document.getElementById('testeSaida');
      botao.disabled = true;
      botao.innerHTML = '<span class="spin"></span> enviando…';
      saida.innerHTML = '';
      try {
        const r = await Api.post('/email/testar', lerFormulario(ev.currentTarget));
        saida.innerHTML = `<div class="aviso ok">${icon('checkCirc','ico ico-sm')} ${esc(r.detalhe)}</div>`;
      } catch (e) {
        saida.innerHTML = `<div class="aviso erro">${icon('alert','ico ico-sm')} ${esc(e.message)}</div>`;
      }
      botao.disabled = false;
      botao.innerHTML = `${icon('mail','ico ico-sm')} Enviar teste`;
    });
  }
};

/* ---------- Telas de diagnóstico: leem o Asterisk, não mexem nele ---------- */

/** Bloco de saída de comando, com aviso quando a central não responde. */
function blocoCli(titulo, bloco) {
  if (!bloco?.disponivel) {
    return `<div class="card" style="padding:18px">
      <b>${esc(titulo)}</b>
      <p class="small muted" style="margin-top:6px">
        A central não respondeu. Isso costuma ser o Asterisk parado ou o AMI sem autenticar.</p>
    </div>`;
  }
  return `<div class="card">
    <div class="card-head"><b>${esc(titulo)}</b></div>
    <pre class="saida-cli">${esc(bloco.texto || '(vazio)')}</pre>
  </div>`;
}

/* ------------------------- Conectividade · DIDs ------------------------- */
PAGES['conn.did'] = {
  async render() {
    let d;
    try { d = await Api.get('/diagnostico/dids'); }
    catch (e) { return pageHead('DIDs / Numeração', '') + blocoErro(e); }

    const linhas = (d.dados || []).map(x => `<tr class="${x.conflito ? 'linha-alerta' : ''}">
      <td><b class="mono">${esc(x.did)}</b></td>
      <td>${esc(x.descricao || '—')}</td>
      <td><span class="ura-dest">${icon('branch','ico ico-sm')}${esc(x.destino)}</span></td>
      <td>${x.origem === 'rota'
        ? '<span class="badge">rota de entrada</span>'
        : '<span class="badge badge-info">DID do ramal</span>'}</td>
      <td>${x.conflito
        ? '<span class="badge badge-warn" data-tip="A rota de entrada vence; este DID do ramal não tem efeito">duplicado</span>'
        : (Number(x.ativo) ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge">Desativado</span>')}</td>
    </tr>`).join('');

    const duplicados = (d.dados || []).filter(x => x.conflito).length;

    return pageHead('DIDs / Numeração',
      'Todos os números que chegam à central, venham de rota de entrada ou do cadastro do ramal.') + `
      ${duplicados ? `<div class="aviso erro" style="margin-bottom:14px">
        ${icon('alert','ico ico-sm')}
        <div>${duplicados} número${duplicados > 1 ? 's aparecem' : ' aparece'} nos dois lugares.
          A rota de entrada é quem vale; o DID no cadastro do ramal fica sem efeito.</div>
      </div>` : ''}
      <div class="card">
        ${linhas ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Número</th><th>Descrição</th><th>Vai para</th>
            <th>Cadastrado em</th><th>Estado</th></tr></thead>
          <tbody>${linhas}</tbody></table></div>`
        : vazio('phoneIn', 'Nenhum DID cadastrado',
            'Crie uma rota de entrada, ou preencha o DID no cadastro de um ramal para o número '
          + 'cair direto nele.')}
      </div>`;
  }
};

/* ------------------------- Conectividade · Rede ------------------------- */
PAGES['conn.rede'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/diagnostico/rede'); }
    catch (e) { return pageHead('Configurações de Rede', '') + blocoErro(e); }

    const trans = (d.transportes || []).map(t => `<tr>
      <td class="mono">${esc(t.id)}</td>
      <td><span class="badge">${esc(t.tipo)}</span></td>
      <td class="mono">${esc(t.endereco)}</td>
    </tr>`).join('');

    const nat = d.nat || {};
    const editavel = ctx.can('editar');

    return pageHead('Configurações de Rede',
      'Onde a central escuta e por onde a voz trafega. Lido do Asterisk, não do cadastro.') + `
      ${nat.sdp?.problema ? `<div class="card" style="margin-bottom:16px;padding:18px;border-left:3px solid var(--danger)">
        <span class="badge badge-danger">a chamada conecta e ninguém ouve</span>
        <p class="small" style="margin:10px 0 0">
          Esta máquina sai pela internet com o endereço
          <b class="mono">${esc(nat.sdp.local)}</b>, que é de rede interna, e não há endereço
          público configurado abaixo. Todo convite SIP que a central manda leva esse endereço no
          SDP: a outra ponta devolve o áudio para um lugar que não existe na internet, e a
          chamada completa sem som nenhum.
        </p>
        <p class="small muted" style="margin:8px 0 0">
          Preencha o endereço público abaixo — o botão <b>Descobrir</b> pergunta a um servidor
          de fora — e aplique as configurações.
        </p>
      </div>` : ''}
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><b>Central atrás de NAT</b></div>
        <div style="padding:18px">
          <p class="small muted" style="margin:0 0 14px">
            Se a central tem um IP de rede interna e sai para a internet por outro,
            é preciso dizer qual é o de fora. Sem isso o convite SIP anuncia o endereço
            interno, a operadora responde para um endereço que não existe na internet,
            e o tronco nunca registra.
          </p>
          <form data-form-nat>
            <div class="grid g-2">
              <div class="field" data-campo="ip_publico">
                <label class="label">Endereço público</label>
                <div class="row gap-8">
                  <input class="input mono" name="ip_publico" placeholder="deixe vazio se o IP já é público"
                         value="${esc(nat.ip_publico || '')}" ${editavel ? '' : 'disabled'}>
                  ${editavel ? `<button type="button" class="btn btn-outline" data-descobrir
                     ${nat.tem_stun ? '' : 'disabled'}
                     data-tip="${nat.tem_stun ? 'Pergunta a um servidor STUN'
                                              : 'Nenhum servidor STUN configurado'}">Descobrir</button>` : ''}
                </div>
                <span class="hint">Em rede com IP público direto, deixe em branco.</span>
              </div>
              <div class="field" data-campo="redes_locais">
                <label class="label">Faixas da rede interna</label>
                <input class="input mono" name="redes_locais" placeholder="10.0.0.0/8, 192.168.0.0/16"
                       value="${esc(nat.redes_locais || '')}" ${editavel ? '' : 'disabled'}>
                <span class="hint">Para quem está nestas faixas, a central usa o endereço interno.</span>
              </div>
            </div>
            ${editavel ? `<div class="row gap-8" style="margin-top:14px;align-items:center">
              <button type="button" class="btn btn-primary" data-salvar-nat>Salvar</button>
              <span class="small muted" data-aviso-nat>Depois de salvar, aplique as configurações.</span>
            </div>` : readOnlyNote(ctx)}
          </form>
        </div>
      </div>
      <div class="grid g-2">
        <div class="card">
          <div class="card-head"><b>Transportes SIP</b></div>
          ${trans ? `<div class="table-wrap"><table class="table">
            <thead><tr><th>Transporte</th><th>Tipo</th><th>Escuta em</th></tr></thead>
            <tbody>${trans}</tbody></table></div>`
          : `<div style="padding:18px" class="small muted">
              A central não respondeu ou não tem transporte carregado.</div>`}
          <div style="padding:14px 16px;border-top:1px solid var(--border)" class="small muted">
            O <span class="mono">transport-wss</span> fica preso em 127.0.0.1 de propósito:
            quem atende o navegador é o nginx, que já termina TLS com o certificado do console.
          </div>
        </div>
        ${blocoCli('Faixa de portas de voz (RTP)', d.rtp)}
      </div>
      ${d.stun_rtp?.ativo ? `<div class="card" style="margin-top:16px;padding:18px">
        <span class="badge badge-warn">STUN ativo no RTP</span>
        <p class="small muted" style="margin:10px 0 0">
          A central está configurada para perguntar o próprio endereço a
          <span class="mono">${esc(d.stun_rtp.endereco || '—')}</span> antes de montar cada chamada.
          Essa consulta é bloqueante: se o endereço não responder, cada chamada atrasa nove
          segundos antes de começar a discar, e o Asterisk registra isso apenas como aviso no log.
          O endereço público do áudio já vem do campo acima, sem consultar ninguém —
          tire o <span class="mono">stunaddr</span> do <span class="mono">rtp.conf</span>
          a menos que você tenha um motivo para mantê-lo.
        </p>
      </div>` : ''}
      <div style="margin-top:16px">${blocoCli('Servidor HTTP do Asterisk', d.http)}</div>`;
  },

  mount() {
    const form = document.querySelector('[data-form-nat]');
    if (!form) return;

    const aviso = form.querySelector('[data-aviso-nat]');
    const diga = (texto, classe = 'muted') => {
      if (aviso) { aviso.className = 'small ' + classe; aviso.textContent = texto; }
    };

    form.querySelector('[data-descobrir]')?.addEventListener('click', async ev => {
      const b = ev.currentTarget;
      b.disabled = true; b.textContent = 'Perguntando…';
      try {
        const r = await Api.get('/diagnostico/rede/descobrir');
        if (r.ip) {
          form.querySelector('[name=ip_publico]').value = r.ip;
          diga('Encontrado ' + r.ip + '. Confira e salve.');
        } else {
          diga(r.motivo || 'Não foi possível descobrir.', 'danger');
        }
      } catch (e) {
        diga(e.message || 'Não foi possível descobrir.', 'danger');
      } finally {
        b.disabled = false; b.textContent = 'Descobrir';
      }
    });

    form.querySelector('[data-salvar-nat]')?.addEventListener('click', async ev => {
      const b = ev.currentTarget;
      b.disabled = true; b.textContent = 'Salvando…';
      form.querySelectorAll('.field').forEach(f => f.classList.remove('erro'));
      try {
        await Api.put('/diagnostico/rede', {
          ip_publico: form.querySelector('[name=ip_publico]').value.trim(),
          redes_locais: form.querySelector('[name=redes_locais]').value.trim()
        });
        toast('Rede salva. Aplique as configurações para valer na central.', 'ok');
        diga('Salvo. Falta aplicar as configurações.', 'warn');
      } catch (e) {
        if (e.detalhe?.campo) {
          form.querySelector(`[data-campo="${e.detalhe.campo}"]`)?.classList.add('erro');
        }
        toast(e.message || 'Não foi possível salvar.', 'err');
      } finally {
        b.disabled = false; b.textContent = 'Salvar';
      }
    });
  }
};

/* ------------------------- Conectividade · WebRTC ------------------------- */
PAGES['conn.webrtc'] = {
  async render() {
    let d;
    try { d = await Api.get('/diagnostico/webrtc'); }
    catch (e) { return pageHead('WebRTC / Softphone', '') + blocoErro(e); }

    const item = (ok, titulo, texto) => `
      <div class="row gap-12" style="align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--border)">
        <span style="color:var(--${ok ? 'ok' : 'danger'});flex:none">
          ${icon(ok ? 'checkCirc' : 'alert','ico')}</span>
        <div><b>${esc(titulo)}</b><div class="small muted">${texto}</div></div>
      </div>`;

    const incompativeis = (d.ramais || []).filter(r => r.aparelho_incompativel);
    const ramais = (d.ramais || []).map(r => `<tr>
      <td><b class="mono">${esc(r.numero)}</b> <span class="dim">${esc(r.nome || '')}</span></td>
      <td>${r.aparelho_incompativel
        ? `<span class="badge badge-danger" data-tip="Registrado por ${esc(r.transporte_contato)}">
             aparelho incompatível</span>`
        : r.registrado
          ? '<span class="badge badge-ok"><i class="dot dot-pulse"></i>registrado</span>'
          : '<span class="badge">não registrado</span>'}</td>
      <td>${Number(r.dtls) ? '<span class="badge badge-ok">sim</span>' : '<span class="badge badge-danger">não</span>'}</td>
      <td>${Number(r.ice) ? '<span class="badge badge-ok">sim</span>' : '<span class="badge badge-danger">não</span>'}</td>
      <td>${Number(r.avpf) ? '<span class="badge badge-ok">sim</span>' : '<span class="badge badge-danger">não</span>'}</td>
      <td>${Number(r.rtcp_mux) ? '<span class="badge badge-ok">sim</span>' : '<span class="badge badge-danger">não</span>'}</td>
    </tr>`).join('');

    const iceRuins = (d.servidores_ice || []).filter(s => !s.ok);
    const iceCorrigidos = (d.servidores_ice || []).filter(s => s.ok && s.corrigido);
    const avisoCorrigido = iceCorrigidos.length ? `
      <div class="card" style="margin-bottom:16px;padding:18px;border-left:3px solid var(--warn)">
        <span class="badge badge-warn">endereço do TURN corrigido pelo console</span>
        <p class="small" style="margin:10px 0 0">
          O instalador deixou um endereço que o navegador não alcança, e o console o trocou pelo
          endereço público ao entregar a lista. Funciona, mas o certo é acertar
          <span class="mono">turn_dominio</span> no instalador.
        </p>
        <ul class="simples small" style="margin:10px 0 0">
          ${iceCorrigidos.map(s => `<li><span class="mono">${esc(s.configurado)}</span> →
            <span class="mono">${esc(s.servidor)}</span></li>`).join('')}
        </ul>
      </div>` : '';

    const alertaIce = iceRuins.length ? `
      <div class="card" style="margin-bottom:16px;padding:18px;border-left:3px solid var(--danger)">
        <span class="badge badge-danger">o navegador não alcança o servidor de ICE</span>
        <p class="small" style="margin:10px 0 0">
          Sem alcançar STUN e TURN, o navegador só oferece o endereço da rede local dele.
          A chamada conecta, ninguém ouve, e a única mensagem é a dele mesmo:
          <i>“ICE failed, your TURN server appears to be broken”</i>.
        </p>
        <ul class="simples small" style="margin:10px 0 0">
          ${iceRuins.map(s => `<li><span class="mono">${esc(s.servidor)}</span> — ${esc(s.motivo)}</li>`).join('')}
        </ul>
        <ul class="simples small" style="margin:0">
        </ul>
        <p class="small muted" style="margin:10px 0 0">
          O endereço do TURN sai de <span class="mono">turn_dominio</span>, que por padrão é o
          nome do servidor. Ele precisa ser um nome ou IP que a máquina de quem usa o console
          resolva e alcance.
        </p>
      </div>` : '';

    const alerta = incompativeis.length ? `
      <div class="card" style="margin-bottom:16px;padding:18px;border-left:3px solid var(--danger)">
        <span class="badge badge-danger">chamada conecta e fica muda</span>
        <p class="small" style="margin:10px 0 0">
          ${incompativeis.map(r => `O ramal <b class="mono">${esc(r.numero)}</b> está marcado como
            WebRTC e registrou por <b>${esc(r.transporte_contato)}</b>.`).join(' ')}
          Ramal WebRTC exige DTLS, ICE e AVPF, que um softphone comum não faz: o Asterisk aceita a
          chamada, o ICE nunca fecha e todo pacote de áudio que ele tenta enviar sai com zero byte.
          As duas pontas mandam som e ninguém ouve nada.
        </p>
        <p class="small muted" style="margin:8px 0 0">
          Um ramal serve a um tipo de aparelho só: o que exige DTLS e ICE não atende softphone
          comum, e o contrário também não. Para a mesma pessoa usar os dois, crie
          <b>dois ramais</b> — um sem WebRTC para o softphone, outro com — e ligue o
          <b>Siga-me no modo “junto”</b> do ramal principal para o do navegador.
          Discar o número principal passa a tocar nos dois, e quem atender primeiro fica.
        </p>
      </div>` : '';

    return pageHead('WebRTC / Softphone',
      'O caminho que o telefone do navegador percorre. Quando ele falha, é um destes.') + alertaIce + avisoCorrigido + alerta + `
      <div class="grid g-2">
        <div class="card" style="padding:18px">
          <b>Caminho da chamada</b>
          <div style="margin-top:8px">
            ${item(d.transporte_wss, 'Transporte WSS carregado',
              'O Asterisk aceita SIP sobre WebSocket.')}
            ${item(d.http_ligado && d.websocket, 'WebSocket publicado',
              'O Asterisk responde em <span class="mono">/asterisk/ws</span>, '
              + 'que o nginx expõe como <span class="mono">/ws</span>.')}
            ${item(!!d.stun || d.turn.length, 'Servidor de ICE configurado',
              d.stun ? `STUN: <span class="mono">${esc(d.stun)}</span>`
                     : 'Sem STUN. Fora da rede local, o áudio some de um lado só.')}
            ${item(d.turn.length > 0, 'TURN disponível',
              d.turn.length
                ? (d.turn_proprio
                    ? 'TURN próprio, com credencial que vence — o certo.'
                    : 'TURN de terceiros, com credencial fixa.')
                : 'Sem TURN. Em rede que bloqueia UDP a chamada conecta e fica muda.')}
          </div>
        </div>

        <div class="card" style="padding:18px">
          <b>Como o navegador se conecta</b>
          <ul class="small muted lista-ajuda" style="margin-top:10px">
            <li>O endereço sai da própria página: quem entra pelo IP usa o IP, quem entra pelo
                nome usa o nome — e o certificado é o mesmo que o navegador já aceitou.</li>
            <li>Para o ramal aparecer no discador, ele precisa estar marcado como WebRTC
                <b>e</b> vinculado ao usuário em Gerenciador de Usuários.</li>
            <li>O navegador só libera o microfone em página segura (HTTPS) ou em
                <span class="mono">localhost</span>.</li>
            <li>Se a chamada conecta e não tem áudio, é ICE: o console avisa na tela da
                chamada, e o caminho é ligar o TURN.</li>
          </ul>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <div class="card-head row-between">
          <b>Ramais com WebRTC</b>
          <span class="small muted">${(d.ramais || []).length} de ${d.ramais_total} ramais</span>
        </div>
        ${ramais ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Ramal</th><th>Agora</th><th>DTLS</th><th>ICE</th>
            <th>AVPF</th><th>rtcp-mux</th></tr></thead>
          <tbody>${ramais}</tbody></table></div>
          <div style="padding:12px 16px;border-top:1px solid var(--border)" class="small muted">
            As quatro colunas da direita precisam estar todas em "sim". Faltando qualquer uma,
            a chamada conecta e fica muda — o navegador exige as quatro.
          </div>`
        : `<div style="padding:20px">${vazio('headset', 'Nenhum ramal com WebRTC',
            'Marque "Softphone do navegador (WebRTC)" no cadastro de um ramal para usar o discador.')}</div>`}
      </div>`;
  }
};

/* ------------------------- Conectividade · Firewall e segurança ------------------------- */
PAGES['conn.firewall'] = {
  async render() {
    let d;
    try { d = await Api.get('/diagnostico/seguranca'); }
    catch (e) { return pageHead('Firewall e Segurança', '') + blocoErro(e); }

    const origens = (d.origens || []).map(o => `<tr>
      <td class="mono"><b>${esc(o.ip)}</b></td>
      <td class="num">${Number(o.tentativas) > 20
        ? `<span class="badge badge-danger">${num(o.tentativas)}</span>`
        : num(o.tentativas)}</td>
      <td class="small dim">${esc(o.ultima)}</td>
    </tr>`).join('');

    const web = (d.login_web || []).map(o => `<tr>
      <td class="mono"><b>${esc(o.ip || '—')}</b></td>
      <td class="num">${num(o.tentativas)}</td>
      <td class="small dim">${dataHora(o.ultima)}</td>
    </tr>`).join('');

    const eventos = (d.eventos || []).slice(0, 60).map(e => `<tr>
      <td class="small">${esc(e.quando)}</td>
      <td><span class="badge badge-warn">${esc(e.tipo)}</span></td>
      <td class="mono">${esc(e.conta || '—')}</td>
      <td class="mono">${esc(e.ip || '—')}</td>
    </tr>`).join('');

    return pageHead('Firewall e Segurança',
      'Quem está tentando entrar na central. É aqui que uma varredura aparece, '
      + 'dias antes de virar conta de telefone.') + `
      ${d.disponivel ? '' : `<div class="aviso" style="margin-bottom:14px">
        ${icon('info','ico ico-sm')}
        <div>O console não consegue ler <span class="mono">${esc(d.arquivo)}</span>.
          O registro de segurança do Asterisk precisa estar ligado no
          <span class="mono">logger.conf</span> e legível para o usuário da API.</div>
      </div>`}

      <div class="grid g-2">
        <div class="card">
          <div class="card-head"><b>Origens com falha de autenticação SIP</b></div>
          ${origens ? `<div class="table-wrap"><table class="table">
            <thead><tr><th>Origem</th><th class="num">Tentativas</th><th>Última</th></tr></thead>
            <tbody>${origens}</tbody></table></div>`
          : `<div style="padding:20px" class="small muted">
              Nenhuma falha registrada. É o que se espera numa central que ainda não foi achada
              por varredura — e o motivo de valer a pena olhar aqui de vez em quando.</div>`}
        </div>

        <div class="card">
          <div class="card-head"><b>Falhas de entrada no console (7 dias)</b></div>
          ${web ? `<div class="table-wrap"><table class="table">
            <thead><tr><th>Origem</th><th class="num">Tentativas</th><th>Última</th></tr></thead>
            <tbody>${web}</tbody></table></div>`
          : `<div style="padding:20px" class="small muted">Nenhuma tentativa falha no período.</div>`}
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <div class="card-head"><b>O que a central bloqueou</b></div>
        ${eventos ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Quando</th><th>Evento</th><th>Conta tentada</th><th>Origem</th></tr></thead>
          <tbody>${eventos}</tbody></table></div>`
        : `<div style="padding:20px" class="small muted">Nada registrado.</div>`}
      </div>

      <div style="margin-top:16px">${blocoCli('Restrição de rede dos ramais', d.acl)}</div>`;
  }
};

/* ------------------------- Configurações · SIP / PJSIP ------------------------- */
PAGES['cfg.sip'] = {
  async render() {
    let d;
    try { d = await Api.get('/diagnostico/sip'); }
    catch (e) { return pageHead('SIP / PJSIP', '') + blocoErro(e); }

    return pageHead('SIP / PJSIP',
      'Como o PJSIP está agora, lido da própria central. Para mudar, use os cadastros — '
      + 'os arquivos são gerados a partir deles.') + `
      <div class="grid g-2">
        ${blocoCli('Transportes', d.transportes)}
        ${blocoCli('Registros nos provedores', d.registros)}
      </div>
      <div style="margin-top:16px">${blocoCli('Ramais e troncos', d.endpoints)}</div>
      <div style="margin-top:16px">${blocoCli('Ajustes globais', d.globais)}</div>
      <div style="margin-top:16px">${blocoCli('Custo de conversão entre codecs', d.codecs)}</div>`;
  }
};

/** Formulário de uma tabela de linha única (parâmetros gerais). */
function paginaAjustes(cfg) {
  return {
    async render(ctx) {
      let d;
      try { d = await Api.get(`/${cfg.recurso}/1`); }
      catch (e) { return pageHead(cfg.titulo, cfg.sub) + blocoErro(e); }

      const campos = cfg.campos(d, ctx);
      const abas = [...new Set(campos.map(c => c.grupo || 'Geral'))];

      return pageHead(cfg.titulo, cfg.sub) + `
        <form id="fAjustes">
          ${abas.map(a => `
            <div class="card" style="padding:18px;margin-bottom:14px">
              ${abas.length > 1 ? `<b>${esc(a)}</b><div style="height:10px"></div>` : ''}
              <div class="form-grid">
                ${campos.filter(c => (c.grupo || 'Geral') === a)
                        .map(c => campoHtml(c, d)).join('')}
              </div>
            </div>`).join('')}
          ${ctx.can('editar') ? `<div class="row gap-8">
            <button class="btn btn-primary btn-sm" type="submit">
              ${icon('check','ico ico-sm')} Salvar</button>
            <span class="small muted" id="ajEstado"></span>
          </div>` : readOnlyNote(ctx)}
        </form>
        ${cfg.rodape ? cfg.rodape(d) : ''}`;
    },

    mount() {
      document.getElementById('fAjustes')?.addEventListener('submit', async ev => {
        ev.preventDefault();
        const estado = document.getElementById('ajEstado');
        estado.textContent = 'salvando…';
        try {
          await Api.put(`/${cfg.recurso}/1`, lerFormulario(ev.currentTarget));
          estado.textContent = '';
          toast('Salvo. Aplique as configurações para a central passar a usar.', 'ok');
          App.route();
        } catch (e) { estado.textContent = ''; toast(e.message, 'err'); }
      });
    }
  };
}

/* ------------------------- Configurações · Correio de voz ------------------------- */
PAGES['cfg.correiovoz'] = paginaAjustes({
  recurso: 'voicemail-geral',
  titulo: 'Correio de Voz',
  sub: 'Como as caixas se comportam. Quem tem correio e qual a senha fica no cadastro do ramal.',
  campos: () => [
    { grupo: 'Gravação', campo: 'max_segundos', label: 'Duração máxima do recado (s)',
      tipo: 'number', padrao: 180,
      ajuda: 'Passando disso a central corta. 180 s costuma bastar; muito mais vira recado que ninguém ouve.' },
    { grupo: 'Gravação', campo: 'min_segundos', label: 'Duração mínima (s)', tipo: 'number', padrao: 2,
      ajuda: 'Recado mais curto que isto é descartado — é quase sempre engano ou silêncio.' },
    { grupo: 'Gravação', campo: 'max_mensagens', label: 'Máximo de recados por caixa',
      tipo: 'number', padrao: 100 },
    { grupo: 'Gravação', campo: 'formato', label: 'Formatos gravados', mono: true,
      padrao: 'wav49|gsm|wav',
      ajuda: 'Separados por barra vertical. O wav49 é o que abre no Windows sem instalar nada.' },
    { grupo: 'Gravação', campo: 'max_tentativas', label: 'Tentativas de senha', tipo: 'number', padrao: 3 },

    { grupo: 'O que a pessoa ouve', campo: 'dizer_origem',
      label: 'Falar de quem é o recado', tipo: 'switch', padrao: 1 },
    { grupo: 'O que a pessoa ouve', campo: 'dizer_hora',
      label: 'Falar a duração do recado', tipo: 'switch', padrao: 1 },

    { grupo: 'E-mail', campo: 'anexar', label: 'Anexar a gravação no e-mail',
      tipo: 'switch', padrao: 1, largura: 'full' },
    { grupo: 'E-mail', campo: 'apagar_apos_email',
      label: 'Apagar da caixa depois de enviar por e-mail', tipo: 'switch', largura: 'full',
      ajuda: 'Ligado, o recado só existe no e-mail. Quem depende do telefone para ouvir perde o acesso.' },
    { grupo: 'E-mail', campo: 'assunto', label: 'Assunto', largura: 'full',
      ajuda: 'Aceita ${VM_CALLERID}, ${VM_NAME}, ${VM_DATE} e ${VM_DUR}.' },
    { grupo: 'E-mail', campo: 'corpo', label: 'Corpo da mensagem', tipo: 'textarea', largura: 'full',
      ajuda: 'Em branco, a central usa um texto padrão com o nome da empresa.' }
  ],
  rodape: () => `<div class="card" style="padding:14px">
    <div class="row gap-12" style="align-items:flex-start">
      ${icon('info','ico')}
      <div class="small muted">
        O envio por e-mail depende de <b>Configurações › Notificações e E-mail</b> estar ligado
        e testado. Sem isso, o ramal pode pedir a cópia e nada chega.
      </div>
    </div>
  </div>`
});

/* ------------------------- Configurações · Fax ------------------------- */
PAGES['cfg.fax'] = paginaAjustes({
  recurso: 'fax-geral',
  titulo: 'Fax',
  sub: 'Recepção de fax pela própria central, sem aparelho. O código *666 recebe no ramal.',
  campos: () => [
    { campo: 'ativo', label: 'Receber fax nesta central', tipo: 'switch', padrao: 1, largura: 'full' },
    { campo: 'email_destino', label: 'Mandar o fax recebido para', largura: 'full',
      placeholder: 'recepcao@suaempresa.com.br',
      ajuda: 'Em branco, o arquivo fica só no servidor, em /var/spool/asterisk/fax.' },
    { campo: 'cabecalho', label: 'Cabeçalho impresso', largura: 'full',
      placeholder: 'Minha Empresa Ltda',
      ajuda: 'Texto que aparece no topo da página no aparelho do outro lado.' },
    { campo: 'ecm', label: 'Correção de erro (ECM)', tipo: 'switch', padrao: 1,
      ajuda: 'Melhora a recepção em linha ruim. Desligue só se o outro lado não completar.' },
    { campo: 'minimo_bits', label: 'Velocidade mínima', tipo: 'number', padrao: 4800 },
    { campo: 'maximo_bits', label: 'Velocidade máxima', tipo: 'number', padrao: 14400 }
  ],
  rodape: () => `<div class="card" style="padding:14px">
    <div class="row gap-12" style="align-items:flex-start">
      ${icon('alert','ico')}
      <div class="small muted">
        Fax sobre VoIP é frágil por natureza: o áudio comprimido que economiza banda numa
        conversa destrói o sinal do fax. Funciona bem com T.38 no tronco e codec G.711; com
        celular ou com compressão agressiva, falha de forma intermitente.
      </div>
    </div>
  </div>`
});

/* ------------------------- Telium · Suporte ------------------------- */
PAGES['telium.suporte'] = {
  async render() {
    const [saude, estado] = await Promise.all([
      Api.get('/health').catch(e => e.detalhe || null),
      Api.get('/config/estado').catch(() => null)
    ]);

    const check = (nome, c) => `<div class="row-between" style="padding:10px 0;border-bottom:1px solid var(--border)">
      <span>${esc(nome)}</span>
      <span>${c?.ok
        ? `<span class="badge badge-ok">${esc(c.detalhe || 'ok')}</span>`
        : `<span class="badge badge-danger">${esc(c?.detalhe || 'sem resposta')}</span>`}</span>
    </div>`;

    return pageHead('Suporte',
      'O que informar ao abrir um chamado — e o que conferir antes.') + `
      <div class="grid g-2">
        <div class="card" style="padding:18px">
          <b>Esta instalação</b>
          <div style="margin-top:10px">
            ${Object.entries(saude?.checagens || {}).map(([n, c]) => check(n, c)).join('')
              || '<p class="small muted">Não foi possível ler o estado dos serviços.</p>'}
            <div class="row-between" style="padding:10px 0">
              <span>Configuração pendente</span>
              <span>${estado?.pendente
                ? '<span class="badge badge-warn">sim — aplique antes de testar</span>'
                : '<span class="badge badge-ok">não</span>'}</span>
            </div>
          </div>
          <p class="small muted" style="margin-top:12px">
            Versão <b>${esc(saude?.versao || '—')}</b>. Cite-a no chamado: a resposta muda
            conforme a versão instalada.</p>
        </div>

        <div class="card" style="padding:18px">
          <b>Antes de abrir o chamado</b>
          <ul class="small muted lista-ajuda" style="margin-top:10px">
            <li>Anote <b>o que foi discado</b>, de qual ramal, e a hora com minuto.
                Com isso, <b>Relatórios › Eventos da Chamada</b> mostra cada passo.</li>
            <li>Se for áudio, diga se falta nos dois lados ou em um só — são problemas
                diferentes, e um só costuma ser NAT ou falta de TURN.</li>
            <li>Se for chamada que não completa, veja <b>Relatórios › Ocupação de Troncos</b>:
                completamento baixo é a operadora, não a central.</li>
            <li>Se for registro de aparelho, <b>Conectividade › Firewall</b> mostra se a
                tentativa chegou e foi recusada, ou se nem chegou.</li>
          </ul>
        </div>
      </div>

      <div class="card" style="margin-top:16px;padding:18px">
        <b>Comandos que respondem rápido no servidor</b>
        <pre class="saida-cli" style="border-radius:10px;margin-top:10px">sudo asterisk -rx "core show channels"     # o que está em curso agora
sudo asterisk -rx "pjsip show contacts"    # quais aparelhos estão registrados
sudo asterisk -rx "pjsip show registrations"  # se o tronco está registrado na operadora
sudo tail -f /var/log/asterisk/full        # o que a central está fazendo, ao vivo
cd /opt/telium/api && php bin/telium testar   # confere a instalação inteira</pre>
      </div>`;
  }
};

/* ------------------------- Configurações · Arquivos e Aplicação ------------------------- */
PAGES['cfg.avancadas'] = {
  async render(ctx) {
    let d, estado;
    try { [d, estado] = await Promise.all([Api.get('/config/arquivos'), Api.get('/config/estado')]); }
    catch (e) { return pageHead('Arquivos e Aplicação', '') + blocoErro(e); }

    const rotulo = { criado: 'badge-brand', atualizado: 'badge-warn', inalterado: '' };
    const mudariam = d.gerados.filter(g => g.estado !== 'inalterado');

    const gerados = d.gerados.map(g => `<tr>
      <td class="mono small">${esc(g.arquivo)}</td>
      <td><span class="badge ${rotulo[g.estado] || ''}">${esc(g.estado)}</span></td>
      <td class="num small dim">${g.bytes ? tamanho(g.bytes) : '—'}</td>
      <td class="small dim">${g.mudado_em ? dataHora(g.mudado_em) : '—'}</td>
    </tr>`).join('');

    const custom = d.personalizados.map(c => `<tr>
      <td class="mono small">${esc(c.arquivo)}</td>
      <td>${c.tem_conteudo
        ? '<span class="badge badge-brand">tem personalização</span>'
        : '<span class="muted small">vazio</span>'}</td>
      <td class="num small dim">${tamanho(c.bytes)}</td>
      <td class="small dim">${dataHora(c.mudado_em)}</td>
    </tr>`).join('');

    const hist = (d.historico || []).map(h => `<tr>
      <td class="small">${dataHora(h.criado_em)}</td>
      <td>${Number(h.sucesso)
        ? '<span class="badge badge-ok">aplicada</span>'
        : '<span class="badge badge-danger">falhou</span>'}</td>
      <td class="small dim">${esc(Object.keys(JSON.parse(h.reloads || '{}')).join(' · ') || '—')}</td>
    </tr>`).join('');

    return pageHead('Arquivos e Aplicação',
      'O que a central escreve a partir do cadastro, e o que fica reservado para você.',
      ctx.can('reiniciar')
        ? `<button class="btn btn-primary btn-sm" id="aplicarAgora">
             ${icon('check','ico ico-sm')} Aplicar agora</button>` : '') + `
      ${d.permissao ? `<div class="aviso erro" style="margin-bottom:14px">
        ${icon('alert','ico ico-sm')}<div>${esc(d.permissao)}</div></div>` : ''}

      <div class="card" style="padding:14px;margin-bottom:14px">
        <div class="row gap-12" style="align-items:flex-start">
          ${icon('info','ico')}
          <div class="small muted">
            Os arquivos da primeira lista são <b>reescritos a cada aplicação</b> — editá-los à mão
            no servidor é trabalho perdido. Para acrescentar dialplan seu, use os
            <span class="mono">*_custom.conf</span> da segunda lista: o gerador nunca os toca.
            Tudo fica em <span class="mono">${esc(d.diretorio)}</span>.
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head row-between">
          <b>Gerados a partir do cadastro</b>
          <span class="small ${mudariam.length ? 'badge badge-warn' : 'muted'}">
            ${mudariam.length ? `${mudariam.length} mudaria${mudariam.length > 1 ? 'm' : ''} se aplicar agora`
                              : 'tudo aplicado'}</span>
        </div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Arquivo</th><th>Se aplicar agora</th>
            <th class="num">Tamanho</th><th>Escrito em</th></tr></thead>
          <tbody>${gerados || '<tr><td colspan="4" class="small muted">Nada gerado ainda.</td></tr>'}</tbody>
        </table></div>
      </div>

      <div class="grid g-2">
        <div class="card">
          <div class="card-head"><b>Reservados para personalização</b></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Arquivo</th><th>Conteúdo</th><th class="num">Tamanho</th><th>Alterado</th></tr></thead>
            <tbody>${custom || '<tr><td colspan="4" class="small muted">Nenhum.</td></tr>'}</tbody>
          </table></div>
        </div>

        <div class="card">
          <div class="card-head"><b>Últimas aplicações</b></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Quando</th><th>Resultado</th><th>Recargas</th></tr></thead>
            <tbody>${hist || '<tr><td colspan="3" class="small muted">Nenhuma ainda.</td></tr>'}</tbody>
          </table></div>
        </div>
      </div>`;
  },

  mount() {
    document.getElementById('aplicarAgora')?.addEventListener('click', ev =>
      App.aplicarConfig(ev.currentTarget));
  }
};
