/* =========================================================
   Telium PABX — Telas (parte 2)
   Rotas, URA, gravações, tarifação, provisionamento,
   segurança, backup, multi-empresa, integrações e PCU.
   ========================================================= */

/* Barras horizontais simples (série única) */
function hbars(itens, unidade = '') {
  const max = Math.max(...itens.map(i => i.valor));
  return itens.map(i => `
    <div class="hbar" style="grid-template-columns:150px 1fr 92px">
      <span class="small truncate">${i.label}</span>
      <span class="track"><span class="fill" style="width:${(i.valor / max * 100).toFixed(1)}%"></span></span>
      <span class="val">${i.exibe ?? i.valor}${unidade}</span>
    </div>`).join('');
}

const moeda = v => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

/* ===================== Conectividade · Rotas de Entrada ===================== */
PAGES['conn.rotasentrada'] = {
  render(ctx) {
    return pageHead('Rotas de Entrada', 'Para onde vai cada número (DID) recebido das operadoras.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm" id="novaRotaIn">${icon('plus','ico ico-sm')} Nova rota</button>` : readOnlyNote(ctx)) + `
    <div class="card"><div class="table-wrap">
      <table class="table">
        <thead><tr><th style="width:60px">Ord.</th><th>DID / Número</th><th>Descrição</th><th>CID de origem</th>
                   <th>Condição horária</th><th>Destino</th><th></th></tr></thead>
        <tbody>${DEMO.rotasEntrada.map(r => `
          <tr>
            <td><span class="grab">${icon('list','ico ico-sm')}</span> <b class="num">${r.prio}</b></td>
            <td><b class="mono">${r.did}</b></td>
            <td class="dim">${r.desc}</td>
            <td class="small dim">${r.cid}</td>
            <td><span class="badge">${icon('clock','ico ico-sm')}${r.hora}</span></td>
            <td><span class="ura-dest">${icon('branch','ico ico-sm')}${r.destino}</span></td>
            <td class="col-actions"><span class="row-actions">
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" ${ctx.can('excluir') ? '' : 'disabled'}>${icon('trash','ico ico-sm')}</button>
            </span></td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div></div>`;
  },
  mount(ctx) {
    const b = document.getElementById('novaRotaIn');
    if (b) b.onclick = () => Drawer.open({
      titulo: 'Nova rota de entrada', sub: 'Direcione um DID para o destino desejado',
      corpo: `<div class="form-grid">
        <div class="field"><label class="label">Descrição</label><input class="input" placeholder="Ex.: Comercial 0800"></div>
        <div class="field"><label class="label">DID / Número</label><input class="input mono" placeholder="11 3255-8800"></div>
        <div class="field"><label class="label">CID de origem</label><input class="input" placeholder="qualquer"></div>
        <div class="field"><label class="label">Condição horária</label>
          <select class="select"><option>Comercial</option><option>24x7</option><option>Plantão</option></select></div>
        <div class="field full"><label class="label">Destino</label>
          <select class="select"><option>URA Principal</option><option>Fila 600 — Suporte Técnico</option>
            <option>Ramal 3001 — Recepção</option><option>Correio de voz</option></select></div>
        <div class="field full"><label class="label">Destino fora do horário</label>
          <select class="select"><option>Correio de voz da recepção</option><option>Anúncio + desligar</option></select></div>
        <div class="field full"><label class="check"><input type="checkbox" checked> <span>Gravar chamadas desta rota</span></label></div>
      </div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" id="okRota">Criar rota</button>`,
      aoAbrir: dw => dw.querySelector('#okRota').onclick = () => { Drawer.close(); toast('Rota de entrada criada.', 'ok'); }
    });
  }
};

/* ===================== Conectividade · Rotas de Saída ===================== */
PAGES['conn.rotassaida'] = {
  render(ctx) {
    return pageHead('Rotas de Saída', 'Ordem de precedência, padrões de discagem e tronco utilizado.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Nova rota</button>` : readOnlyNote(ctx)) + `
    <div class="card">
      <div class="card-head"><div><div class="card-title">Precedência</div>
        <div class="card-sub">A primeira rota cujo padrão casa com o número discado é usada. Arraste para reordenar.</div></div></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:70px">Ordem</th><th>Rota</th><th>Padrão</th><th>Prefixo removido</th>
                     <th>Tronco</th><th>Exige PIN</th><th></th></tr></thead>
          <tbody>${[...DEMO.rotasSaida].sort((a, b) => a.ordem - b.ordem).map(r => `
            <tr>
              <td><span class="grab">${icon('list','ico ico-sm')}</span> <b class="num">${r.ordem}</b></td>
              <td><b>${r.nome}</b></td>
              <td><span class="badge mono">${r.padrao}</span></td>
              <td class="mono dim">${r.prefixo || '—'}</td>
              <td><span class="ura-dest">${icon('network','ico ico-sm')}${r.tronco}</span></td>
              <td>${r.pin ? '<span class="badge badge-warn">Sim</span>' : '<span class="muted">—</span>'}</td>
              <td class="col-actions"><span class="row-actions">
                <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
              </span></td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <div class="card-foot small muted">
        Sintaxe de padrão: <span class="mono">X</span> = 0-9 · <span class="mono">Z</span> = 1-9 ·
        <span class="mono">N</span> = 2-9 · <span class="mono">[1-5]</span> = intervalo · <span class="mono">.</span> = um ou mais dígitos
      </div>
    </div>`;
  }
};

/* ===================== Aplicações · URA (construtor) ===================== */
PAGES['apps.ura'] = {
  render(ctx) {
    const u = DEMO.ura;
    return pageHead('URA — Atendimento Digital', 'Monte a árvore de opções que o cliente ouve ao ligar.',
      `<button class="btn btn-outline btn-sm" id="testarUra">${icon('play','ico ico-sm')} Testar URA</button>
       ${ctx.can('editar') ? `<button class="btn btn-primary btn-sm" id="salvarUra">${icon('check','ico ico-sm')} Salvar</button>` : readOnlyNote(ctx)}`) + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div><div class="card-title">Árvore de opções</div>
          <div class="card-sub">Arraste para reordenar · clique para editar o destino</div></div>
          ${ctx.can('criar') ? `<button class="btn btn-outline btn-sm" id="addOpc">${icon('plus','ico ico-sm')} Opção</button>` : ''}</div>
        <div class="card-body">
          <div class="ura-root">
            ${icon('speaker','ico ico-lg')}
            <div class="grow"><b>${u.nome}</b><small>Áudio: ${u.audio} · timeout ${u.timeout}s · ${u.tentativas} tentativas</small></div>
            <button class="btn btn-sm" style="background:rgba(255,255,255,.18);color:#fff">${icon('play','ico ico-sm')} Ouvir</button>
          </div>
          <div class="ura-tree">
            ${u.opcoes.map(o => `
              <div class="ura-node">
                <span class="grab">${icon('list','ico ico-sm')}</span>
                <span class="ura-key">${o.tecla}</span>
                <div class="grow"><b>${o.label}</b><div class="tiny muted">tecla ${o.tecla}</div></div>
                ${icon('chevronR','ico arrow')}
                <span class="ura-dest">${icon(o.tipo === 'fila' ? 'headset' : o.tipo === 'ramal' ? 'phone' : 'branch','ico ico-sm')}${o.destino}</span>
                <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
              </div>`).join('')}
          </div>
          <div class="grid g-2" style="margin-top:18px">
            <div class="ura-node" style="border-style:dashed">
              ${icon('clock','ico')}<div class="grow"><b>Sem resposta (timeout)</b>
              <div class="tiny muted">${u.semResposta}</div></div>
            </div>
            <div class="ura-node" style="border-style:dashed">
              ${icon('alert','ico')}<div class="grow"><b>Opção inválida</b>
              <div class="tiny muted">${u.invalida}</div></div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid" style="align-content:start">
        <div class="card">
          <div class="card-head"><div class="card-title">Parâmetros</div></div>
          <div class="card-body grid" style="gap:14px">
            <div class="field"><label class="label">Nome da URA</label><input class="input" value="${u.nome}" ${ctx.can('editar') ? '' : 'disabled'}></div>
            <div class="field"><label class="label">Áudio de saudação</label>
              <select class="select" ${ctx.can('editar') ? '' : 'disabled'}><option>${u.audio}</option><option>ura-ferias.wav</option><option>Texto em voz (TTS)</option></select></div>
            <div class="field"><label class="label">Tempo de espera por dígito</label><input class="input" type="number" value="${u.timeout}" ${ctx.can('editar') ? '' : 'disabled'}></div>
            <div class="field"><label class="check"><input type="checkbox" checked ${ctx.can('editar') ? '' : 'disabled'}> <span>Permitir discagem direta de ramal</span></label></div>
            <div class="field"><label class="check"><input type="checkbox" ${ctx.can('editar') ? '' : 'disabled'}> <span>Repetir menu ao expirar</span></label></div>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-title">Pré-visualização</div></div>
          <div class="card-body">
            <div class="code">Você ligou para a Telium.
Para ${u.opcoes[0].label}, tecle 1.
Para ${u.opcoes[1].label}, tecle 2.
Para ${u.opcoes[2].label}, tecle 3.
Para falar com um atendente, tecle 4.</div>
          </div>
        </div>
      </div>
    </div>`;
  },
  mount(ctx) {
    document.getElementById('testarUra')?.addEventListener('click', () => {
      Softphone.discarPara('*99', 'Teste da URA'); toast('Discando para o teste da URA (*99).', 'ok');
    });
    document.getElementById('salvarUra')?.addEventListener('click', () => toast('URA salva. Aplique o dialplan para publicar.', 'ok'));
    document.getElementById('addOpc')?.addEventListener('click', () => Drawer.open({
      titulo: 'Nova opção da URA',
      corpo: `<div class="form-grid">
        <div class="field"><label class="label">Tecla</label>
          <select class="select">${['5','6','7','8'].map(k => `<option>${k}</option>`).join('')}</select></div>
        <div class="field"><label class="label">Rótulo</label><input class="input" placeholder="Ex.: Segunda via de boleto"></div>
        <div class="field full"><label class="label">Destino</label>
          <select class="select"><option>Fila 600 — Suporte Técnico</option><option>Fila 601 — Comercial</option>
            <option>Ramal 3001 — Recepção</option><option>Anúncio</option><option>Outra URA</option></select></div>
      </div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" id="okOpc">Adicionar</button>`,
      aoAbrir: dw => dw.querySelector('#okOpc').onclick = () => { Drawer.close(); toast('Opção adicionada à URA.', 'ok'); }
    }));
  }
};

/* ===================== Relatórios · Gravações ===================== */
PAGES['rel.gravacoes'] = {
  render(ctx) {
    return pageHead('Gravações', 'Busque, ouça e exporte gravações — com trilha de auditoria de quem acessou.',
      ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar seleção</button>` : readOnlyNote(ctx)) + `
    <div class="card">
      <div class="toolbar">
        <input class="input" type="date" value="2026-09-10" style="width:158px">
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" id="gravQ" placeholder="Número, agente ou ID…"></div>
        <select class="select" style="width:160px"><option>Todas as filas</option>
          ${DEMO.filas.map(f => `<option>${f.num} — ${f.nome}</option>`).join('')}</select>
        <select class="select" style="width:150px"><option>Qualquer duração</option><option>&gt; 1 min</option><option>&gt; 5 min</option></select>
        <span class="grow"></span><span class="small muted" id="gravCount"></span>
      </div>
      <div class="card-body grid" style="gap:12px" id="gravList">
        ${DEMO.gravacoes.map(g => `
          <div class="card" style="padding:14px" data-busca="${esc((g.origem + ' ' + g.destino + ' ' + g.agente + ' ' + g.id).toLowerCase())}">
            <div class="row-between" style="margin-bottom:10px">
              <div class="row gap-10">
                <span class="avatar avatar-sm">${initials(g.agente)}</span>
                <div><b>${g.origem} → ${g.destino}</b>
                  <div class="tiny muted">${g.data} · ${g.agente} · ${g.dur} · ${g.tam} · <span class="mono">${g.id}</span></div></div>
              </div>
              <div class="row gap-6">${g.tags.map(t => `<span class="chip">${t}</span>`).join('')}
                ${g.fila !== '—' ? `<span class="badge badge-info">Fila ${g.fila}</span>` : ''}</div>
            </div>
            ${playerHTML(g.id, g.dur)}
          </div>`).join('')}
      </div>
    </div>`;
  },
  mount() {
    const q = document.getElementById('gravQ'), cnt = document.getElementById('gravCount');
    const cards = [...document.querySelectorAll('#gravList > .card')];
    const f = () => { const t = q.value.trim().toLowerCase(); let n = 0;
      cards.forEach(c => { const ok = !t || c.dataset.busca.includes(t); c.hidden = !ok; if (ok) n++; });
      cnt.textContent = `${n} gravações`; };
    q.addEventListener('input', f); f();
    document.querySelectorAll('.player .p-btn').forEach(b => b.onclick = () => {
      const on = b.dataset.on === '1'; b.dataset.on = on ? '0' : '1';
      b.innerHTML = icon(on ? 'play' : 'x', 'ico ico-sm');
      toast(on ? 'Reprodução pausada.' : 'Reproduzindo gravação (demo).');
    });
  }
};

/* ===================== Relatórios · Desempenho de Filas ===================== */
PAGES['rel.filas'] = {
  render(ctx) {
    const legend = DEMO.slaSemana.series.map(s =>
      `<span class="li"><i class="sw" style="background:${s.color}"></i>${s.label}</span>`).join('');
    return pageHead('Desempenho de Filas', 'Nível de serviço, abandono e tempo de espera por fila.',
      `<div class="segmented"><button class="on">7 dias</button><button>30 dias</button><button>Trimestre</button></div>
       ${ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar</button>` : ''}`) + `
    <div class="grid g-4" style="margin-bottom:16px">
      ${[['Chamadas na semana','2.647','phone','brand'],['SLA médio','91,4%','target','ok'],
         ['Abandono','4,0%','phoneOff','warn'],['TME médio','00:38','clock','info']].map(([l, v, i, t]) => `
        <div class="card kpi"><div class="k-top"><span class="k-label">${l}</span>
          <span class="k-ico" style="background:var(--${t}-soft);color:var(--${t})">${icon(i)}</span></div>
          <div class="k-val">${v}</div><div class="k-foot"><span>últimos 7 dias</span></div></div>`).join('')}
    </div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><div><div class="card-title">Atendimentos dentro e fora do SLA</div>
        <div class="card-sub">Meta: atender 90% em até 20 segundos</div></div>
        <div class="legend">${legend}</div></div>
      <div class="card-body"><div class="chart-wrap" id="chartSla"></div></div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">Por fila</div></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Fila</th><th>Recebidas</th><th>Atendidas</th><th>Abandonadas</th>
                   <th>TME</th><th>TMA</th><th>SLA</th></tr></thead>
        <tbody>${DEMO.filas.map(f => `
          <tr><td><b>${f.num}</b> · ${f.nome}</td>
            <td class="num">${(f.sla * 9).toFixed(0)}</td>
            <td class="num">${(f.sla * 9 * (1 - f.abandono / 100)).toFixed(0)}</td>
            <td class="num">${(f.sla * 9 * f.abandono / 100).toFixed(0)}</td>
            <td class="num">${f.tme}</td><td class="num">03:${20 + f.agentes}</td>
            <td><span class="badge badge-${f.sla >= 90 ? 'ok' : 'warn'}">${f.sla}%</span></td></tr>`).join('')}
        </tbody></table></div>
    </div>`;
  },
  mount() { stackedBarChart(document.getElementById('chartSla'),
    { ...DEMO.slaSemana, aria: 'Atendimentos dentro e fora do SLA por dia da semana' }); }
};

/* ===================== Relatórios · Agentes ===================== */
PAGES['rel.agentes'] = {
  render(ctx) {
    return pageHead('Produtividade de Agentes', 'Volume, tempo médio e aderência por atendente.',
      ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar</button>` : '') + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Ranking</div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Agente</th><th>Atendidas</th><th>TMA</th><th>Pausa</th><th>SLA</th><th>Avaliação</th></tr></thead>
          <tbody>${DEMO.agentes.map(a => `
            <tr><td><span class="row gap-8"><span class="avatar avatar-sm">${initials(a.nome)}</span>
                    <span><b>${a.nome}</b><div class="tiny muted mono">${a.ramal}</div></span></span></td>
              <td class="num">${a.atendidas}</td><td class="num">${a.tma}</td><td class="num">${a.pausa}</td>
              <td><span class="badge badge-${a.sla >= 90 ? 'ok' : a.sla >= 80 ? 'warn' : 'danger'}">${a.sla}%</span></td>
              <td><b class="num">${a.nota.toFixed(1)}</b> <span class="muted small">/5</span></td></tr>`).join('')}
          </tbody></table></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Chamadas atendidas</div></div>
        <div class="card-body">${hbars(DEMO.agentes.map(a => ({ label: a.nome, valor: a.atendidas })))}</div>
      </div>
    </div>`;
  }
};

/* ===================== Telium · Tarifação ===================== */
PAGES['telium.tarifacao'] = {
  render(ctx) {
    const t = DEMO.tarifacao;
    return pageHead('Tarifação e Custos', `Consumo de ${t.mes} por setor, destino e centro de custo.`,
      `<div class="segmented"><button class="on">${t.mes}</button><button>Agosto/2026</button></div>
       ${ctx.can('exportar') ? `<button class="btn btn-outline btn-sm">${icon('download','ico ico-sm')} Exportar rateio</button>` : ''}`) + `
    <div class="grid g-4" style="margin-bottom:16px">
      ${[['Custo total', moeda(t.total), 'creditCard', 'brand'],
         ['Minutos falados', t.minutos.toLocaleString('pt-BR'), 'clock', 'info'],
         ['Custo médio / min', moeda(t.total / t.minutos), 'target', 'ok'],
         ['Economia vs. mês anterior', t.economia + '%', 'arrowDown', 'ok']].map(([l, v, i, tn]) => `
        <div class="card kpi"><div class="k-top"><span class="k-label">${l}</span>
          <span class="k-ico" style="background:var(--${tn}-soft);color:var(--${tn})">${icon(i)}</span></div>
          <div class="k-val">${v}</div><div class="k-foot"><span>${t.mes}</span></div></div>`).join('')}
    </div>
    <div class="grid g-2" style="margin-bottom:16px">
      <div class="card">
        <div class="card-head"><div class="card-title">Custo por setor</div></div>
        <div class="card-body">${hbars(t.porSetor.map(s => ({ label: s.setor, valor: s.custo, exibe: moeda(s.custo) })))}</div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Custo por tipo de destino</div></div>
        <div class="card-body">${hbars(t.porDestino.map(d => ({ label: d.tipo, valor: d.custo, exibe: moeda(d.custo) })))}</div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><div class="card-title">Rateio por centro de custo</div>
        <span class="badge">${t.porSetor.length} setores</span></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Setor</th><th>Chamadas</th><th>Minutos</th><th>Custo</th><th>Custo/min</th><th>% do total</th></tr></thead>
        <tbody>${t.porSetor.map(s => `
          <tr><td><b>${s.setor}</b></td><td class="num">${s.chamadas}</td><td class="num">${s.min.toLocaleString('pt-BR')}</td>
            <td class="num"><b>${moeda(s.custo)}</b></td><td class="num dim">${moeda(s.custo / s.min)}</td>
            <td style="width:170px"><div class="track" style="height:7px;background:var(--surface-3);border-radius:6px;overflow:hidden">
              <div style="height:100%;width:${(s.custo / t.total * 100).toFixed(1)}%;background:var(--series-1)"></div></div>
              <div class="tiny muted num">${(s.custo / t.total * 100).toFixed(1)}%</div></td></tr>`).join('')}
        </tbody></table></div>
    </div>`;
  }
};

/* ===================== Telium · Integrações / API ===================== */
PAGES['telium.integracoes'] = {
  render(ctx) {
    return pageHead('Integrações e API', 'Webhooks, chaves de API e conectores para CRM e helpdesk.',
      ctx.can('criar') ? `<button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Nova integração</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Integrações ativas</div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Integração</th><th>Tipo</th><th>Eventos</th><th>Último disparo</th><th>Estado</th><th></th></tr></thead>
          <tbody>${DEMO.integracoes.map(i => `
            <tr><td><b>${i.nome}</b></td><td><span class="badge">${i.tipo}</span></td>
              <td class="small dim mono">${i.evento}</td><td class="small dim">${i.ult}</td>
              <td>${i.estado === 'ativo' ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>' : '<span class="badge"><i class="dot"></i>Inativo</span>'}</td>
              <td class="col-actions"><span class="row-actions">
                <button class="btn btn-ghost btn-sm btn-icon" data-tip="Testar">${icon('play','ico ico-sm')}</button>
                <button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" ${ctx.can('editar') ? '' : 'disabled'}>${icon('edit','ico ico-sm')}</button>
              </span></td></tr>`).join('')}
          </tbody></table></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Exemplo de webhook</div></div>
        <div class="card-body">
          <div class="code">POST /webhooks/telium
{
  "evento": "call.answered",
  "empresa": "matriz",
  "ramal": "2031",
  "origem": "11 98877-1234",
  "fila": "600",
  "inicio": "2026-09-10T14:36:55Z",
  "gravacao": "rec-8841"
}</div>
          <p class="small muted" style="margin-top:12px">Assinado com HMAC-SHA256 no cabeçalho
            <span class="mono">X-Telium-Signature</span>.</p>
        </div>
      </div>
    </div>`;
  }
};

/* ===================== Conectividade · Provisionamento ===================== */
PAGES['conn.provisionamento'] = {
  render(ctx) {
    return pageHead('Provisionamento de Telefones', 'Configuração automática de aparelhos por MAC address.',
      ctx.can('criar') ? `<button class="btn btn-outline btn-sm">${icon('upload','ico ico-sm')} Importar CSV</button>
        <button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Adicionar aparelho</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-4" style="margin-bottom:16px">
      ${[['Aparelhos', DEMO.dispositivos.length, 'phone', 'brand'],
         ['Provisionados', DEMO.dispositivos.filter(d => d.estado === 'provisionado').length, 'checkCirc', 'ok'],
         ['Pendentes', DEMO.dispositivos.filter(d => d.estado === 'pendente').length, 'clock', 'warn'],
         ['Com erro', DEMO.dispositivos.filter(d => d.estado === 'erro').length, 'alert', 'danger']].map(([l, v, i, t]) => `
        <div class="card kpi"><div class="k-top"><span class="k-label">${l}</span>
          <span class="k-ico" style="background:var(--${t}-soft);color:var(--${t})">${icon(i)}</span></div>
          <div class="k-val">${v}</div></div>`).join('')}
    </div>
    <div class="card"><div class="table-wrap"><table class="table">
      <thead><tr><th>MAC</th><th>Modelo</th><th>Ramal</th><th>Firmware</th><th>IP</th><th>Visto</th><th>Estado</th><th></th></tr></thead>
      <tbody>${DEMO.dispositivos.map(d => `
        <tr><td class="mono">${d.mac}</td><td><b>${d.modelo}</b></td><td class="mono">${d.ramal}</td>
          <td class="mono small dim">${d.fw}</td><td class="mono small dim">${d.ip}</td><td class="small dim">${d.visto}</td>
          <td>${d.estado === 'provisionado' ? '<span class="badge badge-ok"><i class="dot"></i>Provisionado</span>'
              : d.estado === 'pendente' ? '<span class="badge badge-warn"><i class="dot"></i>Pendente</span>'
              : '<span class="badge badge-danger"><i class="dot"></i>Erro</span>'}</td>
          <td class="col-actions"><span class="row-actions">
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Reprovisionar" ${ctx.can('reiniciar') ? '' : 'disabled'}>${icon('refresh','ico ico-sm')}</button>
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Reiniciar aparelho" ${ctx.can('reiniciar') ? '' : 'disabled'}>${icon('power','ico ico-sm')}</button>
          </span></td></tr>`).join('')}
      </tbody></table></div></div>`;
  }
};

/* ===================== Conectividade · Firewall / Segurança ===================== */
PAGES['conn.firewall'] = {
  render(ctx) {
    const f = DEMO.firewall;
    return pageHead('Firewall e Segurança SIP', 'Proteção contra fraude telefônica, brute force e varredura de ramais.',
      ctx.can('editar') ? `<button class="btn btn-outline btn-sm">${icon('plus','ico ico-sm')} Regra</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-4" style="margin-bottom:16px">
      ${[['Fail2ban', f.fail2ban ? 'Ativo' : 'Inativo', 'shield', f.fail2ban ? 'ok' : 'danger'],
         ['IPs bloqueados', f.ipsBloqueados, 'lock', 'warn'],
         ['Tentativas (24 h)', f.tentativas24h.toLocaleString('pt-BR'), 'alert', 'danger'],
         ['TLS / SRTP', 'Habilitado', 'key', 'ok']].map(([l, v, i, t]) => `
        <div class="card kpi"><div class="k-top"><span class="k-label">${l}</span>
          <span class="k-ico" style="background:var(--${t}-soft);color:var(--${t})">${icon(i)}</span></div>
          <div class="k-val" style="font-size:22px">${v}</div></div>`).join('')}
    </div>
    <div class="grid g-2">
      <div class="card">
        <div class="card-head"><div class="card-title">Bloqueios recentes</div>
          <button class="btn btn-ghost btn-sm">Ver todos</button></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>IP</th><th>País</th><th>Motivo</th><th>Tentativas</th><th>Quando</th><th></th></tr></thead>
          <tbody>${f.bloqueios.map(b => `
            <tr><td class="mono">${b.ip}</td><td><span class="badge">${b.pais}</span></td>
              <td class="small">${b.motivo}</td><td class="num">${b.tentativas}</td><td class="small dim">${b.quando}</td>
              <td class="col-actions"><button class="btn btn-ghost btn-sm" ${ctx.can('editar') ? '' : 'disabled'}>Liberar</button></td></tr>`).join('')}
          </tbody></table></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Portas e zonas</div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Porta</th><th>Serviço</th><th>Zona</th><th>Estado</th></tr></thead>
          <tbody>${f.portas.map(p => `
            <tr><td class="mono">${p.porta}</td><td>${p.servico}</td>
              <td><span class="badge ${p.zona === 'Confiável' ? 'badge-ok' : ''}">${p.zona}</span></td>
              <td><span class="badge badge-${p.estado === 'aberta' ? 'info' : 'warn'}">${p.estado}</span></td></tr>`).join('')}
          </tbody></table></div>
        <div class="card-foot small muted">Limite antifraude: bloquear ramal após 10 chamadas internacionais em 1 h.</div>
      </div>
    </div>`;
  }
};

/* ===================== Conectividade · WebRTC ===================== */
PAGES['conn.webrtc'] = {
  render(ctx) {
    return pageHead('WebRTC / Softphone', 'Telefone no navegador — sem instalar aplicativo.',
      `<button class="btn btn-primary btn-sm" id="abrirSp">${icon('headset','ico ico-sm')} Abrir softphone</button>`) + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Parâmetros do gateway</div></div>
        <div class="card-body"><div class="deflist">
          ${[['Endpoint WSS', 'wss://pbx.telium.net:8089/ws'],
             ['STUN / TURN', 'turn:turn.telium.net:3478 (autenticado)'],
             ['Codecs', 'OPUS, G.711a, G.722'],
             ['Criptografia', 'DTLS-SRTP obrigatório'],
             ['Ramais habilitados', '48 de 52']].map(([k, v]) => `
            <div class="defrow" style="grid-template-columns:180px 1fr;padding:11px 0">
              <div class="dt"><b>${k}</b></div><div class="mono small">${v}</div></div>`).join('')}
        </div></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Como funciona</div></div>
        <div class="card-body">
          <div class="timeline">
            <div class="tl-item ok"><b>1. Registro</b><small>O navegador registra o ramal via WebSocket seguro.</small></div>
            <div class="tl-item ok"><b>2. Sinalização</b><small>SIP sobre WSS negocia a chamada com o Asterisk.</small></div>
            <div class="tl-item ok"><b>3. Mídia</b><small>Áudio trafega em SRTP direto entre navegador e PABX.</small></div>
            <div class="tl-item"><b>4. Controle</b><small>Mudo, espera, transferência e DTMF pela própria interface.</small></div>
          </div>
        </div>
      </div>
    </div>`;
  },
  mount() { document.getElementById('abrirSp').onclick = () => Softphone.abrir(); }
};

/* ===================== Administrador · Backup ===================== */
PAGES['admin.backup'] = {
  render(ctx) {
    return pageHead('Backup e Restauração', 'Rotinas agendadas, destinos remotos e restauração pontual.',
      ctx.can('criar') ? `<button class="btn btn-outline btn-sm">${icon('upload','ico ico-sm')} Restaurar arquivo</button>
        <button class="btn btn-primary btn-sm" id="rodarBackup">${icon('database','ico ico-sm')} Executar agora</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Histórico</div></div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Arquivo</th><th>Tipo</th><th>Tamanho</th><th>Quando</th><th>Destino</th><th>Estado</th><th></th></tr></thead>
          <tbody>${DEMO.backups.map(b => `
            <tr><td class="mono small">${b.nome}</td><td><span class="badge">${b.tipo}</span></td>
              <td class="num">${b.tam}</td><td class="small dim">${b.quando}</td><td class="small dim">${b.destino}</td>
              <td>${b.estado === 'ok' ? '<span class="badge badge-ok">Concluído</span>' : '<span class="badge badge-danger">Falhou</span>'}</td>
              <td class="col-actions"><span class="row-actions">
                <button class="btn btn-ghost btn-sm btn-icon" data-tip="Baixar">${icon('download','ico ico-sm')}</button>
                <button class="btn btn-ghost btn-sm btn-icon" data-tip="Restaurar" ${ctx.can('reiniciar') ? '' : 'disabled'}>${icon('refresh','ico ico-sm')}</button>
              </span></td></tr>`).join('')}
          </tbody></table></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Agendamento</div></div>
        <div class="card-body"><div class="deflist">
          <div class="defrow"><div class="dt"><b>Backup diário</b><small>Todos os dias às 03:00</small></div>
            <div class="right"><label class="switch"><input type="checkbox" checked ${ctx.can('editar') ? '' : 'disabled'}><span class="track"></span></label></div></div>
          <div class="defrow"><div class="dt"><b>Enviar para S3</b><small>bucket telium-pbx-backups</small></div>
            <div class="right"><label class="switch"><input type="checkbox" checked ${ctx.can('editar') ? '' : 'disabled'}><span class="track"></span></label></div></div>
          <div class="defrow"><div class="dt"><b>Incluir gravações</b><small>Aumenta muito o tamanho do arquivo</small></div>
            <div class="right"><label class="switch"><input type="checkbox" ${ctx.can('editar') ? '' : 'disabled'}><span class="track"></span></label></div></div>
          <div class="defrow"><div class="dt"><b>Retenção</b><small>Backups mantidos antes do descarte</small></div>
            <div><select class="select" ${ctx.can('editar') ? '' : 'disabled'}><option>30 dias</option><option>90 dias</option><option>1 ano</option></select></div></div>
        </div></div>
      </div>
    </div>`;
  },
  mount() {
    document.getElementById('rodarBackup')?.addEventListener('click', async () => {
      const ok = await Modal.confirm({ titulo: 'Executar backup agora?',
        texto: 'A central continua operando normalmente. O processo leva cerca de 4 minutos.',
        ok: 'Executar', tone: 'brand', ico: 'database' });
      if (ok) toast('Backup iniciado — você será notificado ao concluir.', 'ok');
    });
  }
};

/* ===================== PCU · Contatos (click-to-call) ===================== */
PAGES['pcu.contatos'] = {
  render() {
    return pageHead('Meus Contatos', 'Agenda corporativa e pessoal — clique para ligar.',
      `<button class="btn btn-primary btn-sm">${icon('plus','ico ico-sm')} Novo contato</button>`) + `
    <div class="card">
      <div class="toolbar">
        <div class="input-icon search-mini">${icon('search','ico ico-sm')}
          <input class="input" id="ctQ" placeholder="Buscar contato…"></div>
        <div class="segmented" id="ctSeg">
          <button class="on" data-g="">Todos</button><button data-g="Interno">Interno</button>
          <button data-g="Clientes">Clientes</button><button data-g="Fornecedores">Fornecedores</button>
        </div>
      </div>
      <div class="card-body tight table-wrap"><table class="table" id="tabCt">
        <thead><tr><th>Contato</th><th>Número</th><th>Grupo</th><th></th></tr></thead>
        <tbody>${DEMO.contatos.map(c => `
          <tr data-busca="${esc((c.nome + ' ' + c.num).toLowerCase())}" data-g="${c.grupo}">
            <td><span class="row gap-10"><span class="avatar avatar-sm">${initials(c.nome)}</span>
              <span><b>${c.nome}</b>${c.fav ? ' <span class="badge badge-warn">favorito</span>' : ''}</span></span></td>
            <td class="mono">${c.num}</td><td><span class="badge">${c.grupo}</span></td>
            <td class="col-actions"><span class="row-actions">
              <button class="btn btn-primary btn-sm" data-call="${c.num}" data-nome="${esc(c.nome)}">${icon('phone','ico ico-sm')} Ligar</button>
            </span></td></tr>`).join('')}
        </tbody></table></div>
    </div>`;
  },
  mount() {
    const q = document.getElementById('ctQ');
    let grupo = '';
    const rows = [...document.querySelectorAll('#tabCt tbody tr')];
    const f = () => { const t = q.value.trim().toLowerCase();
      rows.forEach(r => r.hidden = (t && !r.dataset.busca.includes(t)) || (grupo && r.dataset.g !== grupo)); };
    q.addEventListener('input', f);
    document.querySelectorAll('#ctSeg button').forEach(b => b.onclick = () => {
      document.querySelectorAll('#ctSeg button').forEach(x => x.classList.remove('on'));
      b.classList.add('on'); grupo = b.dataset.g; f();
    });
    document.querySelectorAll('[data-call]').forEach(b =>
      b.onclick = () => Softphone.discarPara(b.dataset.call, b.dataset.nome));
  }
};

/* ===================== PCU · Perfil e segurança ===================== */
PAGES['pcu.perfil'] = {
  render(ctx) {
    const s = ctx.sess, r = Auth.role(s);
    return pageHead('Meu Perfil e Segurança', 'Dados da conta, senha, autenticação em dois fatores e sessões ativas.') + `
    <div class="grid g-2-1">
      <div class="grid" style="align-content:start">
        <div class="card">
          <div class="card-head"><div class="card-title">Dados da conta</div></div>
          <div class="card-body"><div class="form-grid">
            <div class="field"><label class="label">Nome</label><input class="input" value="${s.name}"></div>
            <div class="field"><label class="label">Usuário</label><input class="input mono" value="${s.user}" disabled></div>
            <div class="field"><label class="label">E-mail</label><input class="input" value="${s.email}"></div>
            <div class="field"><label class="label">Ramal</label><input class="input mono" value="${s.ramal}" disabled></div>
            <div class="field full"><label class="label">Perfil de acesso</label>
              <input class="input" value="${r.label} — ${r.desc}" disabled></div>
          </div></div>
          <div class="card-foot right"><button class="btn btn-primary btn-sm" id="salvarPerfil">Salvar</button></div>
        </div>

        <div class="card">
          <div class="card-head"><div class="card-title">Segurança</div></div>
          <div class="card-body"><div class="deflist">
            <div class="defrow"><div class="dt"><b>Senha</b><small>Alterada há 62 dias</small></div>
              <div class="right"><button class="btn btn-outline btn-sm" id="trocarSenha">Alterar senha</button></div></div>
            <div class="defrow"><div class="dt"><b>Autenticação em dois fatores (2FA)</b>
              <small>Código TOTP no aplicativo autenticador</small></div>
              <div class="right"><label class="switch"><input type="checkbox" id="tog2fa"><span class="track"></span></label></div></div>
            <div class="defrow"><div class="dt"><b>Encerrar sessão após inatividade</b><small>Recomendado para perfis administrativos</small></div>
              <div><select class="select"><option>15 minutos</option><option selected>30 minutos</option><option>2 horas</option><option>Nunca</option></select></div></div>
          </div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div class="card-title">Sessões ativas</div></div>
        <div class="card-body">
          <div class="timeline">
            <div class="tl-item ok"><b>Este dispositivo</b><small>Linux · Chrome · 10.0.0.14 · agora</small></div>
            <div class="tl-item"><b>Celular</b><small>Android · App Telium · 189.45.22.7 · há 3 h</small></div>
            <div class="tl-item warn"><b>Desktop escritório</b><small>Windows · Edge · 10.0.0.51 · ontem</small></div>
          </div>
          <button class="btn btn-outline btn-sm btn-block" style="margin-top:8px" id="encerrarTodas">
            ${icon('logout','ico ico-sm')} Encerrar as outras sessões</button>
        </div>
      </div>
    </div>`;
  },
  mount() {
    document.getElementById('salvarPerfil').onclick = () => toast('Perfil atualizado.', 'ok');
    document.getElementById('trocarSenha').onclick = () => Drawer.open({
      titulo: 'Alterar senha', sub: 'Mínimo de 10 caracteres, com número e símbolo',
      corpo: `<div class="grid" style="gap:16px">
        <div class="field"><label class="label">Senha atual</label><input class="input" type="password"></div>
        <div class="field"><label class="label">Nova senha</label><input class="input" type="password"></div>
        <div class="field"><label class="label">Confirmar nova senha</label><input class="input" type="password"></div>
      </div>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" id="okSenha">Alterar senha</button>`,
      aoAbrir: dw => dw.querySelector('#okSenha').onclick = () => { Drawer.close(); toast('Senha alterada.', 'ok'); }
    });
    document.getElementById('tog2fa').onchange = e => {
      if (!e.target.checked) { toast('2FA desativado.', 'warn'); return; }
      Drawer.open({
        titulo: 'Ativar 2FA', sub: 'Leia o QR Code no seu aplicativo autenticador',
        corpo: `<div class="center">
          <div style="width:170px;height:170px;margin:0 auto 16px;background:
            repeating-conic-gradient(var(--text) 0 25%, transparent 0 50%) 0 0/22px 22px;
            border:8px solid var(--surface);outline:1px solid var(--border);border-radius:8px"></div>
          <p class="small dim">Ou digite a chave manualmente:</p>
          <div class="code" style="margin-top:8px;text-align:center">JBSW Y3DP EHPK 3PXP</div>
          <div class="field" style="margin-top:18px;text-align:left">
            <label class="label">Código de 6 dígitos</label><input class="input mono" placeholder="000000" maxlength="6"></div>
        </div>`,
        rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
                 <button class="btn btn-primary" id="ok2fa">Ativar</button>`,
        aoAbrir: dw => dw.querySelector('#ok2fa').onclick = () => { Drawer.close(); toast('2FA ativado com sucesso.', 'ok'); }
      });
    };
    document.getElementById('encerrarTodas').onclick = async () => {
      const ok = await Modal.confirm({ titulo: 'Encerrar as outras sessões?',
        texto: 'Os demais dispositivos precisarão entrar novamente.', ok: 'Encerrar' });
      if (ok) toast('Outras sessões encerradas.', 'ok');
    };
  }
};

/* ===================== PCU · Correio de voz ===================== */
PAGES['pcu.correiovoz'] = {
  render(ctx) {
    const msgs = [
      { de: '11 98877-1234', nome: 'Cliente ACME', quando: 'Hoje, 11:24', dur: '00:38', novo: true },
      { de: '11 3011-2244',  nome: 'Fornecedor Prisma', quando: 'Hoje, 09:02', dur: '01:12', novo: true },
      { de: '1010',          nome: 'Marina Duarte', quando: 'Ontem, 17:40', dur: '00:22', novo: false }
    ];
    return pageHead('Meu Correio de Voz', `Ramal ${ctx.sess.ramal} · 2 mensagens novas`,
      `<button class="btn btn-outline btn-sm">${icon('settings','ico ico-sm')} Preferências</button>`) + `
    <div class="card"><div class="card-body grid" style="gap:12px">
      ${msgs.map(m => `
        <div class="card" style="padding:14px">
          <div class="row-between" style="margin-bottom:10px">
            <div class="row gap-10"><span class="avatar avatar-sm">${initials(m.nome)}</span>
              <div><b>${m.nome}</b> ${m.novo ? '<span class="badge badge-brand">nova</span>' : ''}
                <div class="tiny muted mono">${m.de} · ${m.quando} · ${m.dur}</div></div></div>
            <div class="row gap-4">
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ligar de volta" data-call="${m.de}">${icon('phone','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir">${icon('trash','ico ico-sm')}</button>
            </div>
          </div>
          ${playerHTML('vm-' + m.de, m.dur)}
        </div>`).join('')}
    </div></div>`;
  },
  mount() {
    document.querySelectorAll('[data-call]').forEach(b => b.onclick = () => Softphone.discarPara(b.dataset.call));
  }
};

/* ===================== Configurações · Dados da Empresa ===================== */
PAGES['cfg.empresa'] = {
  render(ctx) {
    const e = DEMO.empresa;
    return pageHead('Dados da Empresa', 'Identificação da central: aparece em relatórios, gravações e no portal do usuário.',
      ctx.can('editar') ? `<button class="btn btn-primary btn-sm" id="salvarEmp">${icon('check','ico ico-sm')} Salvar</button>` : readOnlyNote(ctx)) + `
    <div class="grid g-2-1">
      <div class="card">
        <div class="card-head"><div class="card-title">Identificação</div></div>
        <div class="card-body"><div class="form-grid">
          <div class="field"><label class="label">Nome fantasia</label><input class="input" value="${e.nome}" ${ctx.can('editar') ? '' : 'disabled'}></div>
          <div class="field"><label class="label">Razão social</label><input class="input" value="${e.razao}" ${ctx.can('editar') ? '' : 'disabled'}></div>
          <div class="field"><label class="label">CNPJ</label><input class="input mono" value="${e.cnpj}" ${ctx.can('editar') ? '' : 'disabled'}></div>
          <div class="field"><label class="label">Telefone principal</label><input class="input mono" value="${e.telefone}" ${ctx.can('editar') ? '' : 'disabled'}></div>
          <div class="field full"><label class="label">Endereço</label><input class="input" value="${e.endereco}" ${ctx.can('editar') ? '' : 'disabled'}></div>
          <div class="field"><label class="label">Fuso horário</label>
            <select class="select" ${ctx.can('editar') ? '' : 'disabled'}><option>${e.fuso}</option><option>America/Manaus</option><option>America/Belem</option></select></div>
          <div class="field"><label class="label">Idioma do sistema</label>
            <select class="select" ${ctx.can('editar') ? '' : 'disabled'}><option>${e.idioma}</option><option>English (US)</option><option>Español</option></select></div>
          <div class="field full"><label class="label">Logotipo do portal</label>
            <div class="row gap-12">
              <div class="sb-mark" id="empMark" style="width:44px;height:44px"></div>
              <button class="btn btn-outline btn-sm" ${ctx.can('editar') ? '' : 'disabled'}>${icon('upload','ico ico-sm')} Enviar imagem</button>
              <span class="small muted">PNG ou SVG, até 1 MB</span>
            </div></div>
        </div></div>
      </div>

      <div class="grid" style="align-content:start">
        <div class="card">
          <div class="card-head"><div class="card-title">Licenciamento</div></div>
          <div class="card-body grid" style="gap:14px">
            <div class="row-between"><span class="dim small">Plano</span><span class="badge badge-brand">${e.plano}</span></div>
            <div>
              <div class="row-between small" style="margin-bottom:6px">
                <span class="dim">Ramais</span><b class="num">${e.ramaisUsados} / ${e.ramaisContratados}</b></div>
              <div class="track" style="height:8px;background:var(--surface-3);border-radius:6px;overflow:hidden">
                <div style="height:100%;width:${(e.ramaisUsados / e.ramaisContratados * 100).toFixed(0)}%;background:var(--brand)"></div></div>
            </div>
            <div class="row-between"><span class="dim small">Troncos</span><b class="num">${e.troncos}</b></div>
            <div class="row-between"><span class="dim small">Validade</span><span class="small">31/12/2026</span></div>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-title">Aparência do portal</div></div>
          <div class="card-body"><div class="deflist">
            <div class="defrow" style="grid-template-columns:1fr auto"><div class="dt"><b>Tema padrão</b><small>Aplicado a novos usuários</small></div>
              <div><select class="select" style="width:130px" ${ctx.can('editar') ? '' : 'disabled'}><option>Claro</option><option>Escuro</option><option>Sistema</option></select></div></div>
            <div class="defrow" style="grid-template-columns:1fr auto"><div class="dt"><b>Exibir marca Telium</b><small>No rodapé do portal do usuário</small></div>
              <div><label class="switch"><input type="checkbox" checked ${ctx.can('editar') ? '' : 'disabled'}><span class="track"></span></label></div></div>
          </div></div>
        </div>
      </div>
    </div>`;
  },
  mount() {
    const m = document.getElementById('empMark'); if (m) m.innerHTML = TELIUM_MARK;
    document.getElementById('salvarEmp')?.addEventListener('click', () => toast('Dados da empresa salvos.', 'ok'));
  }
};
