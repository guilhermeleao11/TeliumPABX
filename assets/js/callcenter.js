/* =========================================================
   Telium PABX — Call Center
   Painel do agente, monitor do supervisor, cadastros, retornos,
   relatório e configuração do módulo.

   Quem está logado, pausado ou falando é o Asterisk que sabe. As
   telas ao vivo recebem a foto dele pelo serviço de tempo real
   (/api/cc/eventos); se o fluxo não abrir, perguntam a cada 3 s.
   ========================================================= */

/* ------------------------- Conexão ao vivo ------------------------- */
const CCVivo = {
  _c: null,

  /**
   * Abre o fluxo e chama `aoReceber(foto)` a cada mudança. Some sozinho
   * quando a rota muda — nenhuma tela precisa lembrar de desligar.
   */
  conectar(rota, aoReceber, aoEstado = () => {}, visao = '') {
    this.desconectar();
    const c = { ativo: true, rota, visao, ctrl: new AbortController(), timer: null, aoReceber, aoEstado };
    this._c = c;
    this._fluxo(c);
  },

  desconectar() {
    if (!this._c) return;
    this._c.ativo = false;
    this._c.ctrl.abort();
    clearTimeout(this._c.timer);
    this._c = null;
  },

  _vivo(c) {
    if (c.ativo && rotaAtual() !== c.rota) this.desconectar();
    return c.ativo;
  },

  _entregar(c, foto) {
    if (!this._vivo(c)) return;
    // O relógio do navegador pode estar adiantado ou atrasado: tudo que
    // é "há quanto tempo" se conta pela diferença para o do servidor.
    foto._delta = Date.now() / 1000 - foto.agora;
    c.aoReceber(foto);
  },

  async _fluxo(c) {
    try {
      const cab = { Accept: 'text/event-stream' };
      if (Api.token) cab.Authorization = `Bearer ${Api.token}`;
      const r = await fetch('/api/cc/eventos' + (c.visao ? `?visao=${c.visao}` : ''), { headers: cab, signal: c.ctrl.signal, credentials: 'same-origin' });
      if (!r.ok || !r.body || !(r.headers.get('content-type') || '').includes('event-stream')) throw new Error('sem fluxo');

      c.aoEstado('vivo');
      const leitor = r.body.getReader();
      const dec = new TextDecoder();
      let buf = '';
      while (this._vivo(c)) {
        const { value, done } = await leitor.read();
        if (done) break;
        buf += dec.decode(value, { stream: true });
        let i;
        while ((i = buf.indexOf('\n\n')) >= 0) {
          const bloco = buf.slice(0, i);
          buf = buf.slice(i + 2);
          const linha = bloco.split('\n').find(l => l.startsWith('data: '));
          if (linha) {
            try { this._entregar(c, JSON.parse(linha.slice(6))); } catch { /* foto quebrada: espera a próxima */ }
          }
        }
      }
    } catch { /* cai para a consulta */ }

    if (this._vivo(c)) this._consultar(c, 0);
  },

  /** Sem fluxo: pergunta a cada 3 s e, de minuto em minuto, tenta o fluxo de novo. */
  async _consultar(c, vezes) {
    if (!this._vivo(c)) return;
    c.aoEstado('consulta');
    try { this._entregar(c, await Api.get('/cc/estado', c.visao ? { visao: c.visao } : null)); }
    catch (e) { c.aoEstado('erro', e); }
    if (!this._vivo(c)) return;
    c.timer = setTimeout(() => vezes >= 20 ? this._fluxo(c) : this._consultar(c, vezes + 1), 3000);
  }
};

/* ------------------------- Utilidades ------------------------- */
/** A rota aberta agora, exata: "cc.agente" não pode casar com "cc.agentes". */
function rotaAtual() {
  return location.hash.replace(/^#\/?/, '').split('?')[0];
}

/** AAAA-MM-DD no fuso do navegador. O dia em UTC, depois das 21 h no
 *  Brasil, já é amanhã — e esvaziava o relatório de "hoje". */
function diaLocal(d = new Date()) {
  const dd = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${dd(d.getMonth() + 1)}-${dd(d.getDate())}`;
}

const CC_ESTADOS = {
  deslogado:    { rotulo: 'Fora do atendimento', tone: '',       ico: 'power' },
  livre:        { rotulo: 'Disponível',          tone: 'ok',     ico: 'checkCirc' },
  falando:      { rotulo: 'Em atendimento',      tone: 'info',   ico: 'phone' },
  tocando:      { rotulo: 'Tocando',             tone: 'info',   ico: 'bell' },
  pausa:        { rotulo: 'Em pausa',            tone: 'warn',   ico: 'clock' },
  tabulando:    { rotulo: 'Pós-atendimento',     tone: 'brand',  ico: 'edit' },
  indisponivel: { rotulo: 'Ramal indisponível',  tone: 'danger', ico: 'alert' }
};

/** O estado que se mostra, a partir do que o Asterisk diz. */
function ccEstado(a) {
  if (!a) return 'deslogado';
  if (a.em_chamada || a.status === 'falando' || a.status === 'ocupado' || a.status === 'espera') return 'falando';
  if (a.status === 'tocando') return 'tocando';
  if (a.pausado) return a.motivo === 'Pós-atendimento' ? 'tabulando' : 'pausa';
  if (a.status === 'indisponivel' || a.status === 'invalido') return 'indisponivel';
  return 'livre';
}

function ccBadge(estado, extra = '') {
  const e = CC_ESTADOS[estado];
  return `<span class="badge ${e.tone ? 'badge-' + e.tone : ''}"><i class="dot ${estado === 'falando' || estado === 'tocando' ? 'dot-pulse' : ''}"></i>${e.rotulo}${extra ? ' · ' + esc(extra) : ''}</span>`;
}

/** mm:ss ou h:mm:ss. */
function ccTempo(seg) {
  seg = Math.max(0, Math.round(seg || 0));
  const h = Math.floor(seg / 3600), m = Math.floor(seg % 3600 / 60), s = seg % 60;
  const dd = n => String(n).padStart(2, '0');
  return h ? `${h}:${dd(m)}:${dd(s)}` : `${dd(m)}:${dd(s)}`;
}

/** Segundos desde um instante do servidor, com o relógio do navegador corrigido. */
function ccDesde(foto, epoch) {
  return epoch ? Date.now() / 1000 - foto._delta - epoch : 0;
}

function ccIndicadorVivo(estado) {
  return estado === 'vivo'
    ? '<span class="badge badge-ok"><i class="dot dot-pulse"></i>Ao vivo</span>'
    : estado === 'consulta'
      ? '<span class="badge badge-warn" title="O serviço de tempo real não respondeu; a tela pergunta ao Asterisk a cada 3 segundos."><i class="dot"></i>Atualizando a cada 3 s</span>'
      : '<span class="badge badge-danger"><i class="dot"></i>Sem comunicação com a central</span>';
}

function ccSla(h) {
  return h && h.recebidas ? Math.round(h.no_sla / h.recebidas * 1000) / 10 : null;
}

function ccKpi(label, valor, rodape, tone, ico) {
  return `<div class="card kpi">
    <div class="k-top"><span class="k-label">${label}</span>
      <span class="k-ico" style="background:var(--${tone}-soft);color:var(--${tone})">${icon(ico)}</span></div>
    <div class="k-val">${valor}</div>
    <div class="k-foot"><span>${rodape}</span></div>
  </div>`;
}

/* =================================================================
   Meu Atendimento — o painel do agente
   ================================================================= */
PAGES['cc.agente'] = {
  async render() {
    this._chave = null;
    let eu;
    try { eu = await Api.get('/cc/eu'); }
    catch (e) { return pageHead('Meu Atendimento', '') + blocoErro(e); }
    this._eu = eu;
    this._foto = null;

    if (!eu.agente) {
      return pageHead('Meu Atendimento', 'O painel de quem atende no call center.') + `
        <div class="card">${vazio('headset', 'Sua conta não é de agente',
          'Peça ao supervisor para cadastrar você em Call Center → Agentes, com as filas que você atende.')}</div>`;
    }

    const a = eu.agente;
    const cod = Object.fromEntries((eu.codigos || []).map(c => [c.chave, c]));
    const pausasTel = eu.motivos.filter(m => m.codigo);

    return pageHead('Meu Atendimento',
      `${esc(a.nome)} · matrícula <span class="mono">${esc(a.matricula)}</span>`,
      '<span id="ccVivo"></span>') + `
      <div class="grid g-2-1" style="margin-bottom:16px">
        <div class="card" id="ccStatus"><div class="card-body">${skeletonLinha()}</div></div>
        <div class="card">
          <div class="card-head"><div class="card-title">Hoje</div></div>
          <div class="card-body" id="ccHoje"></div>
        </div>
      </div>
      <div id="ccTabular"></div>
      <div class="grid g-2" style="margin-bottom:16px">
        <div class="card">
          <div class="card-head"><div><div class="card-title">Minhas filas</div>
            <div class="card-sub">O nível 0 recebe primeiro.</div></div></div>
          <div class="card-body tight" id="ccFilas"></div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-title">Pelo telefone</div></div>
          <div class="card-body small">
            <div class="deflist">
              ${cod.cc_login?.ativo ? `<div class="defrow"><span class="mono">${esc(cod.cc_login.codigo)}</span>
                <span>Entrar — digite ${a.tem_pin ? `<b>${esc(a.matricula)}*</b> e o seu PIN` : `<b>${esc(a.matricula)}</b>`} e #</span></div>` : ''}
              ${pausasTel.map(m => `<div class="defrow"><span class="mono">${esc(m.codigo)}</span><span>Pausa: ${esc(m.nome)}</span></div>`).join('')}
              ${cod.cc_volta?.ativo ? `<div class="defrow"><span class="mono">${esc(cod.cc_volta.codigo)}</span><span>Voltar da pausa</span></div>` : ''}
              ${cod.cc_logout?.ativo ? `<div class="defrow"><span class="mono">${esc(cod.cc_logout.codigo)}</span><span>Sair</span></div>` : ''}
            </div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Meus atendimentos de hoje</div></div>
        <div class="card-body tight" id="ccRecentes">${this.recentes()}</div>
      </div>`;
  },

  mount() {
    if (!this._eu?.agente) return;
    this.pintarTabular();
    this.pintar();
    // Um clique só para a lista, que é redesenhada a cada atendimento.
    document.getElementById('ccRecentes')?.addEventListener('click', ev => {
      const b = ev.target.closest('[data-qualificar]');
      if (b) this.qualificar(Number(b.dataset.qualificar));
    });

    CCVivo.conectar('cc.agente', foto => {
      const antes = this._foto?.agentes?.[0];
      this._foto = foto;
      const eu = foto.agentes[0];
      // Atendeu, desligou ou caiu no pós-atendimento: a lista e o que
      // há para tabular mudaram no banco. Busca de novo, sem piscar.
      if ((eu?.atendidas ?? 0) !== (antes?.atendidas ?? 0) || (eu?.em_chamada && !antes?.em_chamada)
          || (eu?.motivo !== antes?.motivo)) {
        this.recarregarEu();
      }
      this.pintar();
    }, estado => { const el = document.getElementById('ccVivo'); if (el) el.innerHTML = ccIndicadorVivo(estado); },
    'agente');

    clearInterval(this._relogio);
    this._relogio = setInterval(() => {
      if (rotaAtual() !== 'cc.agente') { clearInterval(this._relogio); return; }
      this.pintarRelogio();
    }, 1000);
  },

  async recarregarEu() {
    try {
      this._eu = await Api.get('/cc/eu');
      this.pintar();
      this.pintarTabular();
      const r = document.getElementById('ccRecentes');
      if (r) r.innerHTML = this.recentes();
    } catch { /* a próxima foto tenta de novo */ }
  },

  eu() { return this._foto?.agentes?.[0] || null; },

  pintar() {
    const alvo = document.getElementById('ccStatus');
    if (!alvo) return;
    const a = this.eu();
    const estado = this._foto ? ccEstado(a) : null;
    const eu = this._eu;

    // O cartão só é refeito quando o que ele mostra muda. Refeito a cada
    // foto (a cada 3 s, na consulta), apagava o ramal que a pessoa estava
    // digitando no "Entrar" e fechava o que ela tinha aberto.
    const chave = [estado, a?.motivo, a?.ramal, a?.pausado, eu.atual?.id, (eu.pendentes || []).length].join('|');
    if (this._chave === chave && alvo.dataset.pintado) {
      this.pintarRelogio();
      this.pintarHoje();
      this.pintarFilas();
      return;
    }
    this._chave = chave;
    alvo.dataset.pintado = this._foto ? '1' : '';

    if (!this._foto) {
      alvo.innerHTML = `<div class="card-body">${skeletonLinha()}</div>`;
    } else if (!a) {
      alvo.innerHTML = `<div class="card-body">
        <div style="margin-bottom:14px">${ccBadge('deslogado')}</div>
        <form class="row gap-8" id="ccEntrar" style="align-items:flex-end;flex-wrap:wrap">
          <div class="field" style="margin:0"><label class="label">Ramal em que você está</label>
            <input class="input mono" name="ramal" inputmode="numeric" style="width:160px"
                   value="${esc(eu.agente.ramal_sugerido || '')}" required></div>
          <button class="btn btn-primary" type="submit">${icon('power','ico ico-sm')} Entrar no atendimento</button>
        </form>
        ${eu.filas.length ? '' : `<p class="small" style="margin:12px 0 0;color:var(--danger)">Você não está em
          nenhuma fila de call center ativa. Peça ao supervisor para incluir você.</p>`}
      </div>`;
      document.getElementById('ccEntrar').onsubmit = ev => {
        ev.preventDefault();
        this.acao('/cc/eu/entrar', { ramal: lerFormulario(ev.currentTarget).ramal }, 'Você está no atendimento.');
      };
    } else {
      const podeVoltar = a.pausado;
      // Quem está na linha: é a primeira coisa que o atendente precisa ver.
      const at = this._eu.atual;
      const naLinha = estado === 'falando' && at ? `
        <div style="margin:0 0 16px;padding:14px 16px;border-radius:12px;background:var(--info-soft)">
          <div class="tiny muted" style="margin-bottom:4px">Na linha · fila ${esc(at.fila_nome || at.fila)} · esperou ${ccTempo(at.espera_seg)}</div>
          <div style="font-size:22px;font-weight:700" class="mono">${esc(at.numero || 'número não identificado')}</div>
          ${at.contato ? `<div class="small"><b>${esc(at.contato.nome)}</b>${at.contato.empresa ? ' · ' + esc(at.contato.empresa) : ''}</div>`
            : at.nome ? `<div class="small">${esc(at.nome)}</div>` : '<div class="small muted">Não está na agenda de contatos.</div>'}
        </div>` : '';
      alvo.innerHTML = `<div class="card-body">${naLinha}
        <div class="row-between" style="align-items:flex-start;gap:12px;flex-wrap:wrap">
          <div>
            <div style="margin-bottom:10px">${ccBadge(estado, estado === 'pausa' ? a.motivo : '')}</div>
            <div class="k-val num" id="ccRelogio" style="font-size:34px">--:--</div>
            <div class="small muted" id="ccRelogioSub" style="margin-top:6px"></div>
          </div>
          <div class="small muted right">Ramal <b class="mono">${esc(a.ramal)}</b><br>
            Logado desde ${a.logado_desde ? new Date((a.logado_desde + this._foto._delta) * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '—'}</div>
        </div>
        <div class="row gap-8" style="margin-top:16px;flex-wrap:wrap">
          ${podeVoltar && (this._eu.pendentes || []).length
            ? `<span class="small" style="align-self:center">${icon('edit','ico ico-sm')} Qualifique ${this._eu.pendentes.length > 1 ? 'os atendimentos' : 'o atendimento'} abaixo para voltar a receber chamadas.</span>`
            : podeVoltar
            ? `<button class="btn btn-primary" id="ccVolta">${icon('play','ico ico-sm')} Voltar a atender</button>`
            : `<div class="dd" id="ccPausaDd">
                 <button class="btn btn-outline" id="ccPausaBtn">${icon('clock','ico ico-sm')} Pausar ${icon('chevronD','ico ico-sm')}</button>
               </div>`}
          <button class="btn btn-ghost" id="ccSair">${icon('logout','ico ico-sm')} Sair do atendimento</button>
        </div>
      </div>`;

      document.getElementById('ccVolta')?.addEventListener('click', () =>
        this.acao('/cc/eu/volta', {}, 'Você voltou a receber chamadas.'));
      document.getElementById('ccSair').onclick = async () => {
        const ok = await Modal.confirm({ titulo: 'Sair do atendimento?', texto: 'Você deixa de receber chamadas de todas as suas filas.',
                                         ok: 'Sair', tone: 'warn', ico: 'logout' });
        if (ok) this.acao('/cc/eu/sair', {}, 'Você saiu do atendimento.');
      };
      document.getElementById('ccPausaBtn')?.addEventListener('click', () => this.menuPausa());
    }

    this.pintarRelogio();
    this.pintarHoje();
    this.pintarFilas();
  },

  menuPausa() {
    const botao = document.getElementById('ccPausaBtn');
    Drawer.open({
      titulo: 'Pausar',
      sub: 'Você para de receber chamadas em todas as filas até voltar.',
      corpo: `<div class="grid" style="gap:8px">${this._eu.motivos.map(m => `
        <button class="btn btn-outline btn-block" style="justify-content:space-between" data-motivo="${m.id}">
          <span>${esc(m.nome)}</span>
          <span class="small muted">${m.limite_minutos ? `até ${m.limite_minutos} min` : ''}${m.produtiva ? ' · produtiva' : ''}</span>
        </button>`).join('') || '<p class="small muted">Nenhum motivo de pausa cadastrado.</p>'}</div>`,
      aoAbrir: dw => dw.querySelectorAll('[data-motivo]').forEach(b => b.onclick = () => {
        Drawer.close();
        this.acao('/cc/eu/pausa', { motivo_id: Number(b.dataset.motivo) }, 'Pausa registrada.');
      })
    });
    botao?.blur();
  },

  pintarRelogio() {
    const el = document.getElementById('ccRelogio');
    const sub = document.getElementById('ccRelogioSub');
    const a = this.eu();
    if (!el || !a || !this._foto) return;

    const estado = ccEstado(a);
    let seg = 0, texto = '';
    if (a.pausado && a.pausa_desde) {
      seg = ccDesde(this._foto, a.pausa_desde);
      const limite = a.pausa_limite;
      texto = limite ? (seg > limite ? `<b style="color:var(--danger)">Passou ${ccTempo(seg - limite)} do limite de ${ccTempo(limite)}</b>`
                                     : `Limite: ${ccTempo(limite)}`) : 'Em pausa';
    } else if (estado === 'livre' && a.ultima) {
      seg = ccDesde(this._foto, a.ultima);
      texto = 'desde o último atendimento';
    } else if (estado === 'falando' && this._eu.atual?.desde) {
      seg = ccDesde(this._foto, Number(this._eu.atual.desde));
      texto = 'de conversa';
    } else if (a.logado_desde) {
      seg = ccDesde(this._foto, a.logado_desde);
      texto = 'logado';
    }
    el.textContent = ccTempo(seg);
    if (sub) sub.innerHTML = texto;
  },

  pintarHoje() {
    const el = document.getElementById('ccHoje');
    if (!el) return;
    const h = this.eu()?.hoje || { atendidas: 0, tma: 0, nao_atendeu: 0 };
    el.innerHTML = `<div class="grid" style="grid-template-columns:repeat(3,1fr);gap:10px">
      <div><div class="tiny muted">Atendidas</div><b class="num" style="font-size:22px">${num(h.atendidas)}</b></div>
      <div><div class="tiny muted">Tempo médio</div><b class="num" style="font-size:22px">${ccTempo(h.tma)}</b></div>
      <div><div class="tiny muted">Não atendeu</div><b class="num" style="font-size:22px">${num(h.nao_atendeu)}</b></div>
    </div>`;
  },

  pintarFilas() {
    const el = document.getElementById('ccFilas');
    if (!el) return;
    const vivas = Object.fromEntries((this._foto?.filas || []).map(f => [String(f.numero), f]));
    const nivel = this.eu()?.filas || {};
    el.innerHTML = this._eu.filas.length ? `<div class="table-wrap"><table class="table">
      <thead><tr><th>Fila</th><th>Nível</th><th>Esperando</th><th>Maior espera</th></tr></thead>
      <tbody>${this._eu.filas.map(f => {
        const v = vivas[f.numero];
        const espera = v?.maior_espera || 0;
        return `<tr><td><b>${esc(f.nome)}</b> <span class="tiny muted mono">${esc(f.numero)}</span></td>
          <td class="num">${nivel[f.numero] ?? f.penalidade}</td>
          <td class="num"><b>${v ? v.aguardando : '—'}</b></td>
          <td class="num" style="${v && espera > v.sla_segundos ? 'color:var(--danger);font-weight:650' : ''}">${v ? ccTempo(espera) : '—'}</td></tr>`;
      }).join('')}</tbody></table></div>`
      : vazio('headset', 'Nenhuma fila', 'Você ainda não foi incluído em nenhuma fila de call center.');
  },

  pintarTabular() {
    const el = document.getElementById('ccTabular');
    if (!el) return;
    const p = this._eu.pendentes || [];
    if (!p.length) { el.innerHTML = ''; return; }

    const opcoes = fila => this._eu.tabulacoes.filter(t => !t.fila || t.fila === fila);
    el.innerHTML = `<div class="card" style="margin-bottom:16px;border-color:var(--brand)">
      <div class="card-head"><div><div class="card-title">O que foi resolvido?</div>
        <div class="card-sub">Você volta a receber chamadas quando qualificar ${p.length > 1 ? `os ${p.length} atendimentos` : 'o atendimento'}.</div></div></div>
      <div class="card-body">${p.map(at => `
        <form class="row gap-8" data-tabular="${at.id}" style="align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
          <div style="min-width:180px"><b class="mono">${esc(at.numero || 'sem número')}</b>
            <div class="tiny muted">${at.contato ? `<b>${esc(at.contato.nome)}</b>${at.contato.empresa ? ' · ' + esc(at.contato.empresa) : ''}` : esc(at.nome || '')} · fila ${esc(at.fila)} · ${dataHora(at.atendido_em)}</div></div>
          <div class="field grow" style="margin:0;min-width:200px"><label class="label">Tabulação</label>
            <select class="select" name="tabulacao_id" required>
              <option value="">Escolha…</option>
              ${opcoes(at.fila).map(t => `<option value="${t.id}">${t.grupo ? esc(t.grupo) + ' — ' : ''}${esc(t.nome)}</option>`).join('')}
            </select></div>
          <div class="field grow" style="margin:0;min-width:200px"><label class="label">Observação</label>
            <input class="input" name="observacao" maxlength="1000" placeholder="opcional"></div>
          <button class="btn btn-primary" type="submit">${icon('check','ico ico-sm')} Tabular</button>
        </form>`).join('')}
        ${this._eu.tabulacoes.length ? '' : `<p class="small" style="color:var(--danger);margin:0">Nenhuma tabulação cadastrada
          para estas filas. Peça ao supervisor para cadastrar em Call Center → Tabulações.</p>`}
      </div></div>`;

    el.querySelectorAll('[data-tabular]').forEach(f => f.onsubmit = async ev => {
      ev.preventDefault();
      const d = lerFormulario(f);
      try {
        const r = await Api.post(`/cc/atendimentos/${f.dataset.tabular}/tabular`,
                                 { tabulacao_id: Number(d.tabulacao_id), observacao: d.observacao });
        toast(r.voltou ? 'Tabulado. Você voltou a receber chamadas.' : 'Tabulado.', 'ok');
        this.recarregarEu();
      } catch (e) { toast(e.message, 'err'); }
    });
  },

  recentes() {
    const r = this._eu?.recentes || [];
    return r.length ? `<div class="table-wrap"><table class="table">
      <thead><tr><th>Hora</th><th>Quem ligou</th><th>Fila</th><th>Espera</th><th>Qualificação</th><th></th></tr></thead>
      <tbody>${r.map(a => `<tr>
        <td class="small">${esc((a.atendido_em || '').slice(11, 16))}</td>
        <td><b class="mono">${esc(a.numero || '—')}</b>
          <div class="tiny muted">${a.contato ? esc(a.contato.nome) + (a.contato.empresa ? ' · ' + esc(a.contato.empresa) : '') : esc(a.nome || '')}</div></td>
        <td class="mono small">${esc(a.fila)}</td>
        <td class="num">${ccTempo(a.espera_seg)}</td>
        <td class="small">${a.tabulacao ? esc(a.tabulacao) : '<span class="muted">—</span>'}${a.observacao ? `<div class="tiny muted">${esc(a.observacao)}</div>` : ''}</td>
        <td class="col-actions"><button class="btn btn-ghost btn-sm" data-qualificar="${a.id}">
          ${icon(a.tabulacao ? 'edit' : 'check','ico ico-sm')} ${a.tabulacao ? 'Alterar' : 'Qualificar'}</button></td>
      </tr>`).join('')}</tbody></table></div>`
      : vazio('phone', 'Nenhum atendimento hoje', 'As chamadas que você atender aparecem aqui.');
  },

  /** Qualificar (tabular) qualquer atendimento do dia — obrigatório ou não. */
  qualificar(id) {
    const at = (this._eu.recentes || []).find(x => x.id === id);
    if (!at) return;
    const opcoes = this._eu.tabulacoes.filter(t => !t.fila || t.fila === at.fila);
    Drawer.open({
      titulo: 'Qualificar a chamada',
      sub: `${at.numero || 'sem número'} · fila ${at.fila} · ${(at.atendido_em || '').slice(11, 16)}`,
      corpo: opcoes.length ? `<form id="fQualificar"><div class="form-grid">
        <div class="field full"><label class="label">O que foi resolvido *</label>
          <select class="select" name="tabulacao_id" required>
            <option value="">Escolha…</option>
            ${opcoes.map(t => `<option value="${t.id}" ${Number(at.tabulacao_id) === Number(t.id) ? 'selected' : ''}>${t.grupo ? esc(t.grupo) + ' — ' : ''}${esc(t.nome)}</option>`).join('')}
          </select></div>
        <div class="field full"><label class="label">Observação</label>
          <textarea class="textarea" name="observacao" maxlength="1000" rows="4">${esc(at.observacao || '')}</textarea></div>
      </div></form>`
        : '<p class="small muted">Nenhuma qualificação cadastrada para esta fila. Peça ao supervisor para cadastrar em Call Center → Tabulações.</p>',
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               ${opcoes.length ? `<button class="btn btn-primary" id="salvarQualif">${icon('check','ico ico-sm')} Salvar</button>` : ''}`,
      aoAbrir: dw => {
        const b = dw.querySelector('#salvarQualif');
        if (!b) return;
        b.onclick = async () => {
          const d = lerFormulario(dw.querySelector('#fQualificar'));
          if (!d.tabulacao_id) { toast('Escolha o que foi resolvido.', 'warn'); return; }
          try {
            const r = await Api.post(`/cc/atendimentos/${id}/tabular`, { tabulacao_id: Number(d.tabulacao_id), observacao: d.observacao });
            Drawer.close();
            toast(r.voltou ? 'Qualificada. Você voltou a receber chamadas.' : 'Chamada qualificada.', 'ok');
            this.recarregarEu();
          } catch (e) { toast(e.message, 'err'); }
        };
      }
    });
  },

  async acao(caminho, corpo, sucesso) {
    try {
      await Api.post(caminho, corpo);
      toast(sucesso, 'ok');
    } catch (e) { toast(e.message, 'err'); }
  }
};

function skeletonLinha() {
  return '<div class="sk sk-line" style="width:60%"></div><div class="sk sk-line" style="width:40%;margin-top:10px"></div>';
}

/* =================================================================
   Monitor ao Vivo — o supervisor
   ================================================================= */
PAGES['cc.supervisor'] = {
  async render(ctx) {
    this._ctx = ctx;
    this._foto = null;
    try {
      const [motivos, agentes] = await Promise.all([
        Api.get('/cc-pausas', { limite: 200 }).then(r => r.dados).catch(() => []),
        Api.get('/cc/agentes').then(r => r.dados).catch(() => [])
      ]);
      this._motivos = motivos.filter(m => Number(m.ativo));
      this._cadastro = agentes;
    } catch (e) { return pageHead('Monitor ao Vivo', '') + blocoErro(e); }

    return pageHead('Monitor ao Vivo', 'Filas e agentes do call center, no instante em que mudam.',
      `<span id="ccVivo"></span>${Auth.can('dash.temporeal') ? `<button class="btn btn-outline btn-sm" id="ccTv">${icon('target','ico ico-sm')} Modo TV</button>` : ''}`) + `
      <div class="row gap-8" id="ccSelFilas" style="flex-wrap:wrap;align-items:center;margin-bottom:12px" hidden></div>
      <div class="grid g-4" id="ccKpis" style="margin-bottom:16px"></div>
      <div class="grid g-3" id="ccFilasCards" style="margin-bottom:16px"></div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><div class="card-title">Agentes</div>
          <div class="segmented" id="ccFiltro">
            <button class="on" data-f="">Todos</button><button data-f="livre">Disponíveis</button>
            <button data-f="falando">Em atendimento</button><button data-f="pausa">Em pausa</button>
          </div></div>
        <div class="card-body tight" id="ccAgentes"></div>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Quem está esperando</div></div>
        <div class="card-body tight" id="ccEsperando"></div>
      </div>`;
  },

  mount() {
    this._filtro = '';
    // As filas que o supervisor quer ver. Vazio é todas. Fica guardado no
    // navegador: quem cuida de uma fila só não precisa escolher toda vez.
    try { this._selFilas = new Set(JSON.parse(localStorage.getItem('telium.cc.monitor.filas') || '[]')); }
    catch { this._selFilas = new Set(); }
    document.getElementById('ccSelFilas')?.addEventListener('change', ev => {
      const c = ev.target.closest('[data-sel-fila]');
      if (!c) return;
      if (c.dataset.selFila === '') this._selFilas.clear();
      else if (c.checked) this._selFilas.add(c.dataset.selFila);
      else this._selFilas.delete(c.dataset.selFila);
      try { localStorage.setItem('telium.cc.monitor.filas', JSON.stringify([...this._selFilas])); } catch { /* sem armazenamento */ }
      this._seletorFilas = null;
      this.pintar();
    });
    document.getElementById('ccTv')?.addEventListener('click', () => TV.entrar());
    document.getElementById('ccFiltro')?.addEventListener('click', ev => {
      const b = ev.target.closest('[data-f]');
      if (!b) return;
      this._filtro = b.dataset.f;
      document.querySelectorAll('#ccFiltro button').forEach(x => x.classList.toggle('on', x === b));
      this.pintarAgentes();
    });

    // Um clique só nas ações, para a tabela poder ser redesenhada a cada foto.
    document.getElementById('ccAgentes')?.addEventListener('click', ev => {
      const b = ev.target.closest('[data-cmd]');
      if (b) this.comando(Number(b.dataset.agente), b.dataset.cmd);
    });

    CCVivo.conectar('cc.supervisor', foto => { this._foto = foto; this.pintar(); },
      estado => { const el = document.getElementById('ccVivo'); if (el) el.innerHTML = ccIndicadorVivo(estado); });

    // A cada segundo só os relógios andam. Refazer as tabelas inteiras
    // perdia o clique de quem estava apertando "Escutar" ou "Pausar"
    // bem na virada do segundo, e fazia as dicas piscarem.
    clearInterval(this._relogio);
    this._relogio = setInterval(() => {
      if (rotaAtual() !== 'cc.supervisor') { clearInterval(this._relogio); return; }
      if (this._foto) { this.pintarKpis(); this.pintarFilas(); this.atualizarTempos(); }
    }, 1000);
  },

  pintar() {
    this.pintarSeletor();
    this.pintarKpis();
    this.pintarFilas();
    this.pintarAgentes();
    this.pintarEsperando();
  },

  /** As filas escolhidas no seletor (todas, se nenhuma). */
  filasVisiveis() {
    const sel = this._selFilas;
    const todas = this._foto.filas;
    // Uma fila escolhida que deixou de existir não pode esconder tudo.
    const valem = todas.filter(f => sel.has(f.numero));
    return sel.size && valem.length ? valem : todas;
  },

  /** O agente está em alguma das filas escolhidas? */
  naSelecao(a) {
    const vis = this.filasVisiveis();
    if (vis.length === this._foto.filas.length) return true;
    return vis.some(f => Object.prototype.hasOwnProperty.call(a.filas || {}, f.numero));
  },

  /** Só aparece com mais de uma fila: com uma só, não há o que escolher. */
  pintarSeletor() {
    const el = document.getElementById('ccSelFilas');
    if (!el) return;
    const filas = this._foto.filas;
    const vis = new Set(this.filasVisiveis().map(f => f.numero));
    const todas = vis.size === filas.length;
    const chave = filas.map(f => f.numero + (todas ? '' : vis.has(f.numero) ? '+' : '-')).join(',');
    if (chave === this._seletorFilas) return;
    this._seletorFilas = chave;
    el.hidden = filas.length < 2;
    const chip = (valor, rotulo, marcado, dica = '') => `<label class="chip-check" ${dica ? `title="${esc(dica)}"` : ''}>
      <input type="checkbox" data-sel-fila="${esc(valor)}" ${marcado ? 'checked' : ''}>
      <span style="text-transform:none">${rotulo}</span></label>`;
    el.innerHTML = `<span class="small muted">Filas:</span>`
      + chip('', 'Todas', todas)
      + filas.map(f => chip(f.numero, `${esc(f.nome)} <span class="mono" style="opacity:.7;margin-left:4px">${esc(f.numero)}</span>`,
                            !todas && vis.has(f.numero), `Fila ${f.numero}`)).join('');
  },

  pintarKpis() {
    const el = document.getElementById('ccKpis');
    if (!el) return;
    const f = this.filasVisiveis(), ag = this._foto.agentes.filter(a => this.naSelecao(a));
    const esperando = f.reduce((s, x) => s + x.aguardando, 0);
    const maior = Math.max(0, ...f.map(x => x.maior_espera));
    const conta = e => ag.filter(a => ccEstado(a) === e).length;
    const hoje = f.reduce((s, x) => ({ recebidas: s.recebidas + x.hoje.recebidas, no_sla: s.no_sla + x.hoje.no_sla,
                                       abandonadas: s.abandonadas + x.hoje.abandonadas }), { recebidas: 0, no_sla: 0, abandonadas: 0 });
    const sla = ccSla(hoje);

    el.innerHTML = [
      ccKpi('Esperando agora', num(esperando), maior ? `maior espera ${ccTempo(maior)}` : 'ninguém na espera',
            esperando ? 'warn' : 'ok', 'clock'),
      ccKpi('Disponíveis', num(conta('livre')), `${num(ag.length)} logados`, 'ok', 'checkCirc'),
      ccKpi('Em atendimento', num(conta('falando') + conta('tocando')),
            `${num(conta('pausa') + conta('tabulando'))} em pausa`, 'info', 'phone'),
      ccKpi('SLA hoje', sla === null ? '—' : `${String(sla).replace('.', ',')}%`,
            `${num(hoje.recebidas)} recebidas · ${num(hoje.abandonadas)} abandonos`,
            sla === null ? 'brand' : sla >= 80 ? 'ok' : sla >= 60 ? 'warn' : 'danger', 'target')
    ].join('');
  },

  pintarFilas() {
    const el = document.getElementById('ccFilasCards');
    if (!el) return;
    const ag = this._foto.agentes;
    el.innerHTML = this.filasVisiveis().map(f => {
      const nela = ag.filter(a => a.filas && Object.prototype.hasOwnProperty.call(a.filas, f.numero));
      const livres = nela.filter(a => ccEstado(a) === 'livre').length;
      const sla = ccSla(f.hoje);
      const estourou = f.maior_espera > f.sla_segundos;
      return `<div class="card" style="padding:16px">
        <div class="row-between" style="margin-bottom:12px">
          <div><b>${esc(f.nome)}</b><div class="tiny muted">Fila ${esc(f.numero)} · meta ${f.sla_segundos}s</div></div>
          <span class="badge ${f.aguardando ? (estourou ? 'badge-danger' : 'badge-warn') : 'badge-ok'}">
            <i class="dot ${f.aguardando ? 'dot-pulse' : ''}"></i>${f.aguardando} esperando</span>
        </div>
        <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:8px">
          <div><div class="tiny muted">Maior espera</div><b class="num" style="${estourou ? 'color:var(--danger)' : ''}">${ccTempo(f.maior_espera)}</b></div>
          <div><div class="tiny muted">Livres</div><b class="num">${livres}/${nela.length}</b></div>
          <div><div class="tiny muted">Atendidas</div><b class="num">${num(f.hoje.atendidas)}</b></div>
          <div><div class="tiny muted">SLA</div><b class="num">${sla === null ? '—' : String(sla).replace('.', ',') + '%'}</b></div>
        </div>
      </div>`;
    }).join('') || `<div class="card span-2">${vazio('headset', 'Nenhuma fila de call center',
      'Marque uma fila como "call center" em Aplicações → Filas de Atendimento.',
      '<a class="btn btn-primary btn-sm" href="#/apps.filas">Ir para filas</a>')}</div>`;
  },

  pintarAgentes() {
    const el = document.getElementById('ccAgentes');
    if (!el || !this._foto) return;
    const pode = this._ctx.can('editar');
    const logados = Object.fromEntries(this._foto.agentes.map(a => [a.id, a]));

    // Os cadastrados que não estão logados também aparecem: o supervisor
    // quer saber quem falta, não só quem está.
    const linhas = [
      ...this._foto.agentes,
      ...(this._cadastro || []).filter(c => Number(c.ativo) && !logados[c.id])
          .map(c => ({ id: c.id, nome: c.nome, matricula: c.matricula, deslogado: true,
                       filas: Object.fromEntries((c.filas || []).map(f => [f.numero, f.penalidade])) }))
    ].filter(a => this.naSelecao(a)).filter(a => {
      const e = a.deslogado ? 'deslogado' : ccEstado(a);
      return !this._filtro || e === this._filtro || (this._filtro === 'pausa' && e === 'tabulando')
             || (this._filtro === 'falando' && e === 'tocando');
    }).sort((x, y) => (x.deslogado ? 1 : 0) - (y.deslogado ? 1 : 0) || String(x.nome).localeCompare(String(y.nome)));

    if (!linhas.length) {
      el.innerHTML = vazio('users', this._filtro ? 'Ninguém neste estado' : 'Nenhum agente',
        this._filtro ? 'Troque o filtro para ver os outros.' : 'Cadastre agentes em Call Center → Agentes.');
      return;
    }

    el.innerHTML = `<div class="table-wrap"><table class="table">
      <thead><tr><th>Agente</th><th>Ramal</th><th>Estado</th><th>Há quanto tempo</th><th>Filas (nível)</th>
                 <th>Hoje</th><th>TMA</th><th></th></tr></thead>
      <tbody>${linhas.map(a => {
        const e = a.deslogado ? 'deslogado' : ccEstado(a);
        let tempo = '—', alerta = false, desde = 0;
        if (!a.deslogado && a.pausado && a.pausa_desde) {
          desde = a.pausa_desde;
          const seg = ccDesde(this._foto, a.pausa_desde);
          tempo = ccTempo(seg);
          alerta = a.pausa_limite && seg > a.pausa_limite;
        } else if (!a.deslogado && e === 'livre' && a.ultima) {
          desde = a.ultima;
          tempo = ccTempo(ccDesde(this._foto, a.ultima));
        }
        const filas = Object.entries(a.filas || {}).map(([f, n]) => `<span class="badge mono">${esc(f)}${n ? ` · ${n}` : ''}</span>`).join(' ');
        return `<tr>
          <td><b>${esc(a.nome || 'Agente ' + a.id)}</b><div class="tiny muted mono">${esc(a.matricula || '')}</div></td>
          <td class="mono">${esc(a.ramal || '—')}</td>
          <td>${ccBadge(e, e === 'pausa' ? a.motivo : '')}</td>
          <td class="num" data-desde="${desde || ''}" data-limite="${a.pausado && a.pausa_limite ? a.pausa_limite : ''}"
              style="${alerta ? 'color:var(--danger);font-weight:700' : ''}">${tempo}${alerta ? ' ' + icon('alert','ico ico-sm') : ''}</td>
          <td>${filas || '<span class="muted small">—</span>'}</td>
          <td class="num">${a.hoje ? num(a.hoje.atendidas) : '—'}</td>
          <td class="num">${a.hoje ? ccTempo(a.hoje.tma) : '—'}</td>
          <td class="col-actions"><span class="row-actions">${pode && !a.deslogado ? `
            ${e === 'falando' ? `
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Escutar" data-cmd="escutar" data-agente="${a.id}">${icon('headset','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Sussurrar (só o agente ouve)" data-cmd="sussurrar" data-agente="${a.id}">${icon('mic','ico ico-sm')}</button>
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Intervir (os dois ouvem)" data-cmd="intervir" data-agente="${a.id}">${icon('users','ico ico-sm')}</button>` : ''}
            ${a.pausado
              ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Tirar da pausa" data-cmd="voltar" data-agente="${a.id}">${icon('play','ico ico-sm')}</button>`
              : `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Pausar" data-cmd="pausar" data-agente="${a.id}">${icon('clock','ico ico-sm')}</button>`}
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Tirar do atendimento" data-cmd="sair" data-agente="${a.id}">${icon('logout','ico ico-sm')}</button>` : ''}
          </span></td></tr>`;
      }).join('')}</tbody></table></div>`;
  },

  /** O segundo que passou, sem refazer as tabelas (ver mount). */
  atualizarTempos() {
    document.querySelectorAll('#ccAgentes [data-desde]').forEach(td => {
      const desde = Number(td.dataset.desde);
      if (!desde) return;
      const seg = ccDesde(this._foto, desde);
      const limite = Number(td.dataset.limite);
      const alerta = limite && seg > limite;
      td.innerHTML = ccTempo(seg) + (alerta ? ' ' + icon('alert','ico ico-sm') : '');
      td.style.color = alerta ? 'var(--danger)' : '';
      td.style.fontWeight = alerta ? '700' : '';
    });
    const passou = Math.max(0, ccDesde(this._foto, this._foto.agora));
    document.querySelectorAll('#ccEsperando [data-espera]').forEach(td => {
      const espera = Number(td.dataset.espera) + passou;
      td.textContent = ccTempo(espera);
      const fora = espera > Number(td.dataset.sla);
      td.style.color = fora ? 'var(--danger)' : '';
      td.style.fontWeight = fora ? '650' : '';
    });
  },

  pintarEsperando() {
    const el = document.getElementById('ccEsperando');
    if (!el) return;
    const todos = this.filasVisiveis().flatMap(f => (f.esperando || []).map(c => ({ ...c, fila: f.numero, filaNome: f.nome, sla: f.sla_segundos })));
    todos.sort((a, b) => b.espera - a.espera);
    el.innerHTML = todos.length ? `<div class="table-wrap"><table class="table">
      <thead><tr><th>Fila</th><th>Posição</th><th>Quem liga</th><th>Esperando</th></tr></thead>
      <tbody>${todos.map(c => {
        const espera = c.espera + Math.max(0, ccDesde(this._foto, this._foto.agora));
        return `<tr>
        <td>${esc(c.filaNome)} <span class="tiny muted mono">${esc(c.fila)}</span></td>
        <td class="num">${c.posicao}${c.prioridade ? ' <span class="badge badge-brand">retorno</span>' : ''}</td>
        <td><b class="mono">${esc(c.numero || 'anônimo')}</b> <span class="tiny muted">${esc(c.nome || '')}</span></td>
        <td class="num" data-espera="${c.espera}" data-sla="${c.sla}"
            style="${espera > c.sla ? 'color:var(--danger);font-weight:650' : ''}">${ccTempo(espera)}</td></tr>`;
      }).join('')}</tbody></table></div>`
      : vazio('checkCirc', 'Ninguém esperando', 'Toda chamada que entrou já foi atendida.');
  },

  async comando(id, cmd) {
    const agente = this._foto.agentes.find(a => a.id === id);
    const nome = agente?.nome || 'o agente';
    let corpo = { acao: cmd };

    if (cmd === 'pausar') {
      const motivo = await new Promise(ok => Drawer.open({
        titulo: `Pausar ${nome}`, sub: 'O agente para de receber chamadas até voltar.',
        corpo: `<div class="grid" style="gap:8px">${this._motivos.map(m => `
          <button class="btn btn-outline btn-block" data-motivo="${m.id}">${esc(m.nome)}</button>`).join('')}</div>`,
        aoAbrir: dw => dw.querySelectorAll('[data-motivo]').forEach(b => b.onclick = () => { ok(Number(b.dataset.motivo)); Drawer.close(); }),
        aoFechar: () => ok(null)
      }));
      if (!motivo) return;
      corpo.motivo_id = motivo;
    } else if (cmd === 'sair') {
      if (!await Modal.confirm({ titulo: `Tirar ${nome} do atendimento?`,
        texto: 'Ele sai de todas as filas e precisa entrar de novo para voltar a receber chamadas.', ok: 'Tirar', tone: 'warn' })) return;
    } else if (['escutar', 'sussurrar', 'intervir'].includes(cmd)) {
      const textos = {
        escutar: 'Seu ramal vai tocar. Ao atender, você ouve a conversa sem ser ouvido.',
        sussurrar: 'Seu ramal vai tocar. Ao atender, só o agente ouve você — o cliente não.',
        intervir: 'Seu ramal vai tocar. Ao atender, você entra na conversa e os dois ouvem você.'
      };
      if (!await Modal.confirm({ titulo: `${cmd[0].toUpperCase() + cmd.slice(1)} ${nome}?`, texto: textos[cmd],
                                 ok: 'Chamar meu ramal', tone: 'brand', ico: 'headset' })) return;
    }

    try {
      await Api.post(`/cc/agentes/${id}/comando`, corpo);
      toast(['escutar', 'sussurrar', 'intervir'].includes(cmd) ? 'Atenda o seu ramal.' : 'Feito.', 'ok');
    } catch (e) { toast(e.message, 'err'); }
  }
};

/* =================================================================
   Agentes — o cadastro
   ================================================================= */
PAGES['cc.agentes'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/cc/agentes'); }
    catch (e) { return pageHead('Agentes', '') + blocoErro(e); }
    this._d = d;

    const novo = ctx.can('criar') ? `<button class="btn btn-primary btn-sm" id="ccNovo">${icon('plus','ico ico-sm')} Novo agente</button>` : '';
    const cabecalho = pageHead('Agentes',
      'Quem atende no call center: o nome, a matrícula do telefone e as filas com o nível de cada uma. A conta do console é opcional.',
      readOnlyNote(ctx) + novo);

    if (!d.filas.length) {
      return cabecalho + `<div class="card">${vazio('headset', 'Nenhuma fila de call center',
        'Antes dos agentes, marque ao menos uma fila como "call center" em Filas de Atendimento.',
        '<a class="btn btn-primary btn-sm" href="#/apps.filas">Ir para filas</a>')}</div>`;
    }
    if (!d.dados.length) {
      return cabecalho + `<div class="card">${vazio('users', 'Nenhum agente cadastrado',
        'Cadastre as pessoas que atendem: cada uma entra no ramal em que estiver sentada.', novo)}</div>`;
    }

    return cabecalho + `<div class="card"><div class="table-wrap"><table class="table">
      <thead><tr><th>Agente</th><th>Matrícula</th><th>Filas (nível)</th><th>Ramal habitual</th><th>Estado</th><th></th></tr></thead>
      <tbody>${d.dados.map(a => `<tr>
        <td><b>${esc(a.nome)}</b><div class="tiny muted">${a.usuario_id ? `${esc(a.usuario)} · ${esc(a.perfil)}` : 'só telefone'}</div></td>
        <td class="mono">${esc(a.matricula)}${a.tem_pin ? ' <span class="badge" title="Pede PIN no telefone">PIN</span>' : ''}</td>
        <td>${a.filas.map(f => `<span class="badge mono" title="${esc(f.nome)}">${esc(f.numero)} · ${f.penalidade}</span>`).join(' ') || '<span class="badge badge-warn">sem fila</span>'}</td>
        <td class="mono">${esc(a.ramal_padrao || a.ramal_usuario || '—')}</td>
        <td>${Number(a.ativo) ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>' : '<span class="badge"><i class="dot"></i>Inativo</span>'}</td>
        <td class="col-actions"><span class="row-actions">
          ${ctx.can('editar') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Editar" data-editar="${a.id}">${icon('edit','ico ico-sm')}</button>` : ''}
          ${ctx.can('excluir') ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Excluir" data-excluir="${a.id}">${icon('trash','ico ico-sm')}</button>` : ''}
        </span></td></tr>`).join('')}</tbody></table></div></div>`;
  },

  mount() {
    document.getElementById('ccNovo')?.addEventListener('click', () => this.form(null));
    document.querySelectorAll('[data-editar]').forEach(b => b.onclick = () =>
      this.form(this._d.dados.find(a => a.id === Number(b.dataset.editar))));
    document.querySelectorAll('[data-excluir]').forEach(b => b.onclick = async () => {
      const a = this._d.dados.find(x => x.id === Number(b.dataset.excluir));
      if (!await Modal.confirm({ titulo: `Excluir o agente ${a.nome}?`,
        texto: 'Ele sai das filas agora. O histórico de atendimentos e os relatórios continuam com o que ele fez.', ok: 'Excluir' })) return;
      try { await Api.delete(`/cc/agentes/${a.id}`); toast('Agente excluído.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  },

  form(a) {
    const d = this._d;
    const usados = new Set(d.dados.filter(x => !a || x.id !== a.id).map(x => x.usuario_id));
    const minhas = Object.fromEntries((a?.filas || []).map(f => [f.fila_id, f.penalidade]));

    Drawer.open({
      titulo: a ? `Agente ${a.nome}` : 'Novo agente',
      sub: 'A matrícula é o que se digita no telefone para entrar.',
      wide: true,
      corpo: `<form id="fAgente" autocomplete="off"><div class="form-grid">
        <div class="field" data-campo="nome"><label class="label">Nome *</label>
          <input class="input" name="nome" maxlength="80" value="${esc(a?.nome || '')}" placeholder="Maria Souza">
          <span class="hint">É o que o supervisor e os relatórios mostram.</span></div>
        <div class="field" data-campo="usuario_id"><label class="label">Conta do console</label>
          <select class="select" name="usuario_id">
            <option value="">Nenhuma — só telefone ou softphone</option>
            ${d.usuarios.filter(u => !usados.has(u.id)).map(u => `<option value="${u.id}" data-nome="${esc(u.nome)}" ${a?.usuario_id === u.id ? 'selected' : ''}>${esc(u.nome)} (${esc(u.usuario)})${u.ramal ? ' · ramal ' + esc(u.ramal) : ''}</option>`).join('')}
          </select>
          <span class="hint">Opcional. Com ela a pessoa abre o painel "Meu Atendimento" (perfil Call Center); sem ela, entra e pausa só pelos códigos do telefone.</span></div>
        <div class="field" data-campo="matricula"><label class="label">Matrícula *</label>
          <input class="input mono" name="matricula" inputmode="numeric" maxlength="8" value="${esc(a?.matricula || '')}" required>
          <span class="hint">2 a 8 dígitos.</span></div>
        <div class="field" data-campo="pin"><label class="label">PIN do telefone</label>
          <input class="input mono" name="pin" type="password" inputmode="numeric" maxlength="8" autocomplete="new-password"
                 placeholder="${a?.tem_pin ? 'deixe em branco para manter' : 'opcional'}">
          <span class="hint">Com PIN, entra-se digitando matrícula * PIN (ex.: 501*1234). Sem PIN, só a matrícula.</span>
          ${a?.tem_pin ? '<label class="check small" style="margin-top:6px"><input type="checkbox" name="remover_pin"> Remover o PIN</label>' : ''}</div>
        <div class="field" data-campo="ramal_padrao"><label class="label">Ramal habitual</label>
          <input class="input mono" name="ramal_padrao" inputmode="numeric" value="${esc(a?.ramal_padrao || '')}">
          <span class="hint">Sugestão na hora de entrar. Ele pode entrar em qualquer ramal.</span></div>
        <div class="field"><label class="label">Agente ativo</label>
          <label class="switch"><input type="checkbox" name="ativo" ${!a || Number(a.ativo) ? 'checked' : ''}><span class="track"></span></label></div>
      </div>
      <div class="secao-form" style="margin-top:18px"><b>Filas e nível de habilidade</b>
        <p class="small muted" style="margin:4px 0 10px">Nível 0 recebe primeiro; os níveis seguintes só quando os de
          nível menor estão ocupados — ou quando a espera passa do tempo de ampliação da fila.</p>
        <div class="table-wrap"><table class="table">
          <thead><tr><th style="width:40px"></th><th>Fila</th><th style="width:140px">Nível</th></tr></thead>
          <tbody>${d.filas.map(f => `<tr>
            <td><input type="checkbox" data-fila="${f.id}" ${f.id in minhas ? 'checked' : ''}></td>
            <td><b>${esc(f.nome)}</b> <span class="tiny muted mono">${esc(f.numero)}</span></td>
            <td><select class="select" data-nivel="${f.id}">
              ${[0,1,2,3,4,5,6,7,8,9].map(n => `<option value="${n}" ${(minhas[f.id] ?? 0) === n ? 'selected' : ''}>${n}${n === 0 ? ' — primeiro' : ''}</option>`).join('')}
            </select></td></tr>`).join('')}</tbody></table></div>
        <div data-campo="filas"></div>
      </div></form>`,
      rodape: `<button class="btn btn-outline" data-drawer-close>Cancelar</button>
               <button class="btn btn-primary" id="salvarAgente">${icon('check','ico ico-sm')} Salvar</button>`,
      aoAbrir: dw => {
        // Escolher a conta preenche o nome, se ele ainda estiver vazio.
        const nome = dw.querySelector('[name="nome"]');
        dw.querySelector('[name="usuario_id"]').onchange = ev => {
          const op = ev.target.selectedOptions[0];
          if (!nome.value.trim() && op?.dataset.nome) nome.value = op.dataset.nome;
        };
        dw.querySelector('#salvarAgente').onclick = async () => {
          const form = dw.querySelector('#fAgente');
          limparErros(form);
          const v = lerFormulario(form);
          const corpo = {
            nome: (v.nome || '').trim(), usuario_id: v.usuario_id ? Number(v.usuario_id) : null,
            matricula: v.matricula, pin: v.pin || '',
            remover_pin: v.remover_pin ? 1 : 0, ramal_padrao: v.ramal_padrao, ativo: v.ativo ? 1 : 0,
            filas: [...form.querySelectorAll('[data-fila]:checked')].map(c => ({
              fila_id: Number(c.dataset.fila), penalidade: Number(form.querySelector(`[data-nivel="${c.dataset.fila}"]`).value)
            }))
          };
          try {
            const r = a ? await Api.put(`/cc/agentes/${a.id}`, corpo) : await Api.post('/cc/agentes', corpo);
            Drawer.close();
            toast(r.aviso || 'Agente salvo.', r.aviso ? 'warn' : 'ok');
            App.route();
          } catch (e) {
            // Campo sem lugar para marcar (as filas são uma lista de caixas):
            // a mensagem dele vai no aviso, senão "confira os campos
            // destacados" apontava para nada.
            const soltos = Object.entries(e.detalhe?.campos || {})
              .filter(([c, m]) => !marcarErro(form, c, m)).map(([, m]) => m);
            toast(soltos.length ? soltos.join(' ') : e.message, 'err');
          }
        };
      }
    });
  }
};

/* =================================================================
   Motivos de pausa e tabulações — cadastros simples
   ================================================================= */
PAGES['cc.pausas'] = paginaCrud({
  recurso: 'cc-pausas',
  titulo: 'Motivos de Pausa',
  sub: 'Por que o agente parou de receber chamadas. Cada pausa tem o seu código: o agente disca e já fica pausado por ela.',
  ico: 'clock',
  plural: 'motivos',
  rotuloNovo: 'Novo motivo',
  vazioTitulo: 'Nenhum motivo de pausa',
  vazioTexto: 'Sem motivo, o agente não consegue se pausar pelo painel.',
  placeholderBusca: 'Buscar…',
  textoBusca: m => m.nome,
  colunas: [
    { label: 'Motivo', render: m => `<b>${esc(m.nome)}</b>${Number(m.sistema) ? ' <span class="badge" title="Posto pela própria central">da central</span>' : ''}` },
    { label: 'Código no telefone', render: m => m.codigo ? `<span class="mono">${esc(m.codigo)}</span>`
        : `<span class="muted small">${Number(m.sistema) ? 'posto pela central' : 'só pelo painel'}</span>` },
    { label: 'Limite', render: m => m.limite_minutos ? `${m.limite_minutos} min` : '<span class="muted small">sem limite</span>' },
    { label: 'Tipo', render: m => Number(m.produtiva) ? '<span class="badge badge-info">produtiva</span>' : '<span class="badge">improdutiva</span>' },
    { label: 'Estado', render: m => Number(m.ativo) ? '<span class="badge badge-ok"><i class="dot"></i>Ativo</span>' : '<span class="badge"><i class="dot"></i>Inativo</span>' }
  ],
  // Os motivos da central não se discam: não têm o campo do código.
  campos: m => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Almoço' },
    ...(Number(m.sistema) ? [] : [
      { campo: 'codigo', label: 'Código no telefone', mono: true, placeholder: '*14',
        padraoValido: /^[*#][0-9]{1,6}$/, mensagemPadrao: 'use * seguido de 1 a 6 dígitos',
        ajuda: 'O que o agente disca para entrar nesta pausa. Não pode ser igual nem o começo de outro código de recurso. Vazio: só pelo painel. Vale depois de Aplicar configurações.' }]),
    { campo: 'limite_minutos', label: 'Limite (minutos)', tipo: 'number',
      ajuda: 'Passou disso, o supervisor vê a pausa em vermelho. Vazio: sem limite.' },
    { campo: 'produtiva', label: 'Pausa produtiva', tipo: 'switch',
      ajuda: 'Treinamento, reunião, feedback: é trabalho, e o relatório não conta contra a ocupação.' },
    { campo: 'ordem', label: 'Ordem', tipo: 'number', padrao: 100 },
    { campo: 'ativo', label: 'Motivo ativo', tipo: 'switch', padrao: 1 }
  ]
});

PAGES['cc.tabulacoes'] = paginaCrud({
  recurso: 'cc-tabulacoes',
  titulo: 'Tabulações',
  sub: 'O que o agente marca ao fim de cada atendimento. Obrigatório nas filas com "Exigir tabulação".',
  ico: 'list',
  plural: 'tabulações',
  rotuloNovo: 'Nova tabulação',
  vazioTitulo: 'Nenhuma tabulação cadastrada',
  vazioTexto: 'Cadastre os desfechos de atendimento: "Resolvido", "Encaminhado ao técnico", "Reclamação"…',
  placeholderBusca: 'Buscar…',
  textoBusca: t => `${t.nome} ${t.grupo || ''}`,
  aoCarregar: async pagina => {
    pagina._filas = (await Api.get('/filas', { limite: 500 }).catch(() => ({ dados: [] }))).dados.filter(f => Number(f.callcenter));
  },
  colunas: [
    { label: 'Tabulação', render: t => `<b>${esc(t.nome)}</b>` },
    { label: 'Grupo', render: t => t.grupo ? `<span class="badge">${esc(t.grupo)}</span>` : '<span class="muted small">—</span>' },
    { label: 'Fila', render: (t) => {
        const f = (PAGES['cc.tabulacoes']._filas || []).find(x => Number(x.id) === Number(t.fila_id));
        return f ? `<span class="mono">${esc(f.numero)}</span> ${esc(f.nome)}` : '<span class="muted small">todas</span>';
      } },
    { label: 'Estado', render: t => Number(t.ativo) ? '<span class="badge badge-ok"><i class="dot"></i>Ativa</span>' : '<span class="badge"><i class="dot"></i>Inativa</span>' }
  ],
  campos: (t, ctx, pagina) => [
    { campo: 'nome', label: 'Nome', obrigatorio: true, placeholder: 'Resolvido no primeiro contato' },
    { campo: 'grupo', label: 'Grupo', placeholder: 'Suporte', ajuda: 'Agrupa as opções na lista do agente.' },
    { campo: 'fila_id', label: 'Só para a fila', tipo: 'select',
      opcoes: [{ valor: '', rotulo: 'Todas as filas' }, ...(pagina._filas || []).map(f => ({ valor: f.id, rotulo: `${f.numero} — ${f.nome}` }))] },
    { campo: 'ordem', label: 'Ordem', tipo: 'number', padrao: 100 },
    { campo: 'ativo', label: 'Tabulação ativa', tipo: 'switch', padrao: 1 }
  ]
});

/* =================================================================
   Retornos
   ================================================================= */
const CC_RETORNO = {
  pendente:  ['Aguardando agente', 'warn'],
  discando:  ['Discando', 'info'],
  concluido: ['Falou com o cliente', 'ok'],
  falhou:    ['Não conseguiu falar', 'danger'],
  cancelado: ['Cancelado', '']
};

PAGES['cc.retornos'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/cc/retornos', this._estado ? { estado: this._estado } : null); }
    catch (e) { return pageHead('Retornos', '') + blocoErro(e); }
    this._d = d.dados;

    const filtro = `<div class="segmented" id="ccRetFiltro">
      ${[['', 'Últimos 7 dias'], ['pendente', 'Aguardando'], ['falhou', 'Não conseguiu'], ['concluido', 'Concluídos']]
        .map(([v, r]) => `<button class="${(this._estado || '') === v ? 'on' : ''}" data-e="${v}">${r}</button>`).join('')}
    </div>`;

    return pageHead('Retornos',
      'Quem pediu, na espera, para ser chamado de volta. A central disca quando há agente livre — o agente primeiro, depois o cliente.',
      filtro) + `<div class="card">${this._d.length ? `<div class="table-wrap"><table class="table">
        <thead><tr><th>Pedido</th><th>Fila</th><th>Cliente</th><th>Estado</th><th>Tentativas</th><th>Último resultado</th><th></th></tr></thead>
        <tbody>${this._d.map(r => {
          const [rot, tone] = CC_RETORNO[r.estado] || [r.estado, ''];
          return `<tr><td class="small">${dataHora(r.pedido_em)}</td>
            <td>${esc(r.fila_nome || r.fila)} <span class="tiny muted mono">${esc(r.fila)}</span></td>
            <td><b class="mono">${esc(r.numero)}</b> <span class="tiny muted">${esc(r.nome || '')}</span></td>
            <td><span class="badge ${tone ? 'badge-' + tone : ''}"><i class="dot"></i>${rot}</span></td>
            <td class="num">${r.tentativas}</td>
            <td class="small dim">${esc(r.resultado || '—')}</td>
            <td class="col-actions"><span class="row-actions">${ctx.can('editar') ? `
              ${['pendente', 'falhou'].includes(r.estado) ? `<button class="btn btn-ghost btn-sm" data-ret="${r.id}" data-acao="cancelar">Cancelar</button>` : ''}
              ${['falhou', 'cancelado', 'concluido'].includes(r.estado) ? `<button class="btn btn-ghost btn-sm" data-ret="${r.id}" data-acao="refazer">Tentar de novo</button>` : ''}` : ''}
            </span></td></tr>`;
        }).join('')}</tbody></table></div>`
        : vazio('phoneIn', 'Nenhum retorno', 'Ligue a tecla de retorno numa fila de call center (aba "Call center" da fila) para oferecer isto a quem espera.')}</div>`;
  },

  mount() {
    document.getElementById('ccRetFiltro')?.addEventListener('click', ev => {
      const b = ev.target.closest('[data-e]');
      if (b) { this._estado = b.dataset.e; App.route(); }
    });
    document.querySelectorAll('[data-ret]').forEach(b => b.onclick = async () => {
      try { await Api.post(`/cc/retornos/${b.dataset.ret}`, { acao: b.dataset.acao }); toast('Feito.', 'ok'); App.route(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* =================================================================
   Relatórios — do queue_log
   ================================================================= */
PAGES['cc.relatorios'] = {
  async render() {
    const hoje = diaLocal();
    this._p ??= { de: hoje, ate: hoje, fila: '' };
    let d;
    try { d = await Api.get('/cc/relatorio', this._p); }
    catch (e) { return pageHead('Relatórios do Call Center', '') + blocoErro(e); }
    this._d = d;

    const t = d.filas.reduce((s, f) => {
      for (const k of ['recebidas', 'atendidas', 'abandonadas', 'no_sla', 'retorno', 'estouradas']) s[k] = (s[k] || 0) + f[k];
      s.tmeSoma = (s.tmeSoma || 0) + f.tme * f.atendidas;
      return s;
    }, {});
    const sla = t.recebidas ? Math.round(t.no_sla / t.recebidas * 1000) / 10 : null;
    const pct = v => v === null || v === undefined ? '—' : String(v).replace('.', ',') + '%';

    const periodo = `<form class="row gap-8" id="ccRelForm" style="flex-wrap:wrap;align-items:flex-end">
      <div class="segmented" id="ccRelAtalho">
        <button type="button" data-dias="0">Hoje</button><button type="button" data-dias="6">7 dias</button>
        <button type="button" data-dias="29">30 dias</button></div>
      <input class="input" type="date" name="de" value="${esc(this._p.de)}" style="width:150px">
      <input class="input" type="date" name="ate" value="${esc(this._p.ate)}" style="width:150px">
      <select class="select" name="fila" style="width:190px">
        <option value="">Todas as filas</option>
        ${d.lista_filas.map(f => `<option value="${esc(f.numero)}" ${this._p.fila === f.numero ? 'selected' : ''}>${esc(f.numero)} — ${esc(f.nome)}</option>`).join('')}
      </select>
      <button class="btn btn-primary btn-sm" type="submit">${icon('refresh','ico ico-sm')} Ver</button>
    </form>`;

    const csv = tabela => `<button class="btn btn-outline btn-sm" data-csv="${tabela}">${icon('download','ico ico-sm')} CSV</button>`;

    return pageHead('Relatórios do Call Center',
      'Tirado do registro das próprias filas (queue_log): cada chamada contada uma vez, com a espera e a conversa medidas pelo Asterisk.',
      periodo) + `
      <div class="grid g-4" style="margin-bottom:16px">
        ${ccKpi('Recebidas', num(t.recebidas || 0), `${num(t.atendidas || 0)} atendidas`, 'brand', 'phoneIn')}
        ${ccKpi('Nível de serviço', pct(sla), 'atendidas dentro da meta ÷ recebidas', sla === null ? 'brand' : sla >= 80 ? 'ok' : sla >= 60 ? 'warn' : 'danger', 'target')}
        ${ccKpi('Abandono', pct(t.recebidas ? Math.round((t.abandonadas || 0) / t.recebidas * 1000) / 10 : null),
               `${num(t.abandonadas || 0)} desistiram esperando`, (t.abandonadas || 0) ? 'warn' : 'ok', 'phoneOff')}
        ${ccKpi('Espera média', ccTempo(t.atendidas ? t.tmeSoma / t.atendidas : 0), `${num(t.retorno || 0)} pediram retorno`, 'info', 'clock')}
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><div class="card-title">Filas</div>${csv('filas')}</div>
        <div class="card-body tight">${d.filas.length ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Fila</th><th class="num">Recebidas</th><th class="num">Atendidas</th><th class="num">SLA</th>
            <th class="num">Abandono</th><th class="num">Estouro</th><th class="num">Sem agente</th><th class="num">Retorno</th>
            <th class="num">TME</th><th class="num">Maior espera</th><th class="num">TMA</th></tr></thead>
          <tbody>${d.filas.map(f => `<tr>
            <td><b>${esc(f.nome)}</b> <span class="tiny muted mono">${esc(f.numero)}</span><div class="tiny muted">meta ${f.sla_segundos}s</div></td>
            <td class="num">${num(f.recebidas)}</td><td class="num">${num(f.atendidas)}</td>
            <td class="num"><b>${pct(f.sla)}</b></td><td class="num">${pct(f.abandono)}</td>
            <td class="num">${num(f.estouradas)}</td><td class="num">${num(f.sem_agente)}</td><td class="num">${num(f.retorno)}</td>
            <td class="num">${ccTempo(f.tme)}</td><td class="num">${ccTempo(f.maior_espera)}</td><td class="num">${ccTempo(f.tma)}</td></tr>`).join('')}
          </tbody></table></div>` : vazio('chart', 'Nenhuma chamada de fila no período', 'Escolha outro período ou outra fila.')}</div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><div class="card-title">Por hora do dia</div>${csv('horas')}</div>
        <div class="card-body"><div id="ccRelHoras"></div></div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><div><div class="card-title">Agentes</div>
          <div class="card-sub">Ocupação = tempo falando ÷ tempo logado fora de pausa improdutiva.</div></div>${csv('agentes')}</div>
        <div class="card-body tight">${d.agentes.length ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Agente</th><th class="num">Atendidas</th><th class="num">TMA</th><th class="num">Não atendeu</th>
            <th class="num">Logado</th><th class="num">Em pausa</th><th>Pausas</th><th class="num">Ocupação</th>
            <th class="num" data-tip="Índice da pesquisa de satisfação, de 0 (pior) a 100 (melhor)">Satisfação</th><th class="num">Tabuladas</th></tr></thead>
          <tbody>${d.agentes.map(a => `<tr>
            <td><b>${esc(a.nome)}</b>${a.matricula ? ` <span class="tiny muted mono">${esc(a.matricula)}</span>` : ''}</td>
            <td class="num">${num(a.atendidas)}</td><td class="num">${ccTempo(a.tma)}</td><td class="num">${num(a.nao_atendeu)}</td>
            <td class="num">${ccTempo(a.logado)}</td><td class="num">${ccTempo(a.pausado)}</td>
            <td class="small">${Object.entries(a.pausas || {}).map(([m, s]) => `${esc(m)} ${ccTempo(s)}`).join(' · ') || '<span class="muted">—</span>'}</td>
            <td class="num">${pct(a.ocupacao)}</td>
            <td class="num">${a.nota === null ? '—' : String(a.nota).replace('.', ',')}${a.respostas ? ` <span class="tiny muted">(${a.respostas})</span>` : ''}</td>
            <td class="num">${num(a.tabuladas)}</td></tr>`).join('')}
          </tbody></table></div>` : vazio('users', 'Nenhum agente no período', 'Os números aparecem quando alguém entrar e atender.')}</div>
      </div>

      <div class="card">
        <div class="card-head"><div class="card-title">Tabulações</div></div>
        <div class="card-body">${d.tabulacoes.length
          ? hbars(d.tabulacoes.map(x => ({ label: `${x.grupo ? x.grupo + ' — ' : ''}${x.nome} (${x.fila})`, valor: Number(x.n) })))
          : '<p class="small muted" style="margin:0">Nenhum atendimento tabulado no período.</p>'}</div>
      </div>`;
  },

  mount() {
    const el = document.getElementById('ccRelHoras');
    if (el && this._d) {
      const h = this._d.por_hora;
      stackedBarChart(el, {
        labels: h.map(x => String(x.hora).padStart(2, '0') + 'h'),
        series: [
          { key: 'atendidas', label: 'Atendidas', color: 'var(--series-1)', values: h.map(x => x.atendidas) },
          { key: 'perdidas', label: 'Não atendidas', color: 'var(--series-2)', values: h.map(x => Math.max(0, x.recebidas - x.atendidas)) }
        ],
        aria: 'Chamadas de fila por hora do dia'
      });
    }

    const form = document.getElementById('ccRelForm');
    form?.addEventListener('submit', ev => { ev.preventDefault(); this._p = { ...this._p, ...lerFormulario(form) }; App.route(); });
    document.getElementById('ccRelAtalho')?.addEventListener('click', ev => {
      const b = ev.target.closest('[data-dias]');
      if (!b) return;
      const ate = new Date(), de = new Date(Date.now() - Number(b.dataset.dias) * 86400000);
      this._p = { ...this._p, de: diaLocal(de), ate: diaLocal(ate) };
      App.route();
    });

    // O CSV vem com a sessão: um link simples não levaria o token.
    document.querySelectorAll('[data-csv]').forEach(b => b.onclick = async () => {
      try {
        const q = new URLSearchParams({ ...this._p, formato: 'csv', tabela: b.dataset.csv });
        const r = await fetch(`/api/cc/relatorio?${q}`, {
          headers: Api.token ? { Authorization: `Bearer ${Api.token}` } : {}, credentials: 'same-origin'
        });
        if (!r.ok) throw new Error('Não foi possível gerar a planilha.');
        const url = URL.createObjectURL(await r.blob());
        const a = document.createElement('a');
        a.href = url; a.download = `callcenter-${b.dataset.csv}-${this._p.de}_${this._p.ate}.csv`;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } catch (e) { toast(e.message, 'err'); }
    });
  }
};

/* =================================================================
   Configurações do módulo: os códigos do telefone
   ================================================================= */
PAGES['cc.config'] = {
  async render(ctx) {
    let d;
    try { d = await Api.get('/cc/config'); }
    catch (e) { return pageHead('Configurações do Call Center', '') + blocoErro(e); }
    this._d = d;
    const pode = ctx.can('editar');

    return pageHead('Configurações do Call Center',
      'Os códigos que o agente disca no telefone. Os áudios que ele ouve são os do call center, em português.',
      pode ? `<button class="btn btn-primary btn-sm" id="ccCfgSalvar">${icon('check','ico ico-sm')} Salvar</button>` : readOnlyNote(ctx)) + `
      <div class="card">
        <div class="card-head"><div><div class="card-title">Códigos do agente no telefone</div>
          <div class="card-sub">Trocar um código não tem efeito até "Aplicar configurações". O código não pode
            colidir com outro código de recurso nem com o de uma pausa — nem ser o começo de um.
            O código de cada pausa fica em <a href="#/cc.pausas">Motivos de Pausa</a>.</div></div></div>
        <div class="card-body"><form id="ccCfg">${d.codigos.map(c => `
          <div class="row gap-12" style="align-items:flex-start;margin-bottom:14px;flex-wrap:wrap" data-campo="${c.chave}">
            <div style="flex:1;min-width:220px"><b>${esc(c.nome)}</b>
              <div class="small muted">${esc(c.descricao || '')}</div></div>
            <div class="field" style="margin:0;width:140px"><label class="label">Código${c.argumento ? ' + ' + esc(c.argumento) : ''}</label>
              <input class="input mono" name="${c.chave}" data-codigo="${c.chave}" value="${esc(c.codigo)}" maxlength="7" ${pode ? '' : 'disabled'}></div>
            <div class="field" style="margin:0"><label class="label">Ativo</label>
              <label class="switch"><input type="checkbox" data-ativo="${c.chave}" ${Number(c.ativo) ? 'checked' : ''} ${pode ? '' : 'disabled'}>
              <span class="track"></span></label></div>
          </div>`).join('')}</form></div>
      </div>`;
  },

  mount() {
    document.getElementById('ccCfgSalvar')?.addEventListener('click', async () => {
      const form = document.getElementById('ccCfg');
      limparErros(form);
      const codigos = this._d.codigos.map(c => ({
        chave: c.chave,
        codigo: form.querySelector(`[data-codigo="${c.chave}"]`).value.trim(),
        ativo: form.querySelector(`[data-ativo="${c.chave}"]`).checked ? 1 : 0
      }));
      try {
        await Api.put('/cc/config', { codigos });
        toast('Salvo. Aplique as configurações para os telefones passarem a usar os códigos novos.', 'warn');
        App.route();
      } catch (e) {
        Object.entries(e.detalhe?.campos || {}).forEach(([c, m]) => marcarErro(form, c, m));
        toast(e.message, 'err');
      }
    });
  }
};
