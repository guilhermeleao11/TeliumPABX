/* =========================================================
   Telium PABX — Telefone (WebRTC)

   A tela do telefone do navegador, para quem tem o módulo
   "fone.webrtc": o aparelho (Softphone, em ui.js), volume do
   alto-falante e do microfone, dispositivos, não perturbe do ramal,
   contatos pessoais e globais, ramais e as chamadas desta sessão.
   ========================================================= */
PAGES['fone.webrtc'] = {
  async render() {
    const fone = await Api.get('/me/telefone').catch(e => ({ erro: e }));
    this._fone = fone;
    this._aba = this._aba || 'meus';
    this._busca = '';

    if (fone.erro) return pageHead('Telefone', '') + blocoErro(fone.erro);
    if (!fone.disponivel) {
      return pageHead('Telefone', 'O telefone do seu ramal, no navegador.') + `
        <div class="card">${vazio('phoneOff', 'Sua conta não tem ramal', esc(fone.motivo || ''))}</div>`;
    }

    const r = fone.ramal;
    const cfg = Auth.sessao?.softphone || {};
    const avisoRamal = !r.webrtc
      ? `<div class="aviso-form" style="margin-bottom:16px">${icon('info','ico')}
          <div><b>O ramal ${esc(r.numero)} não está marcado como WebRTC</b>
            <div class="tiny">Enquanto isso, "Ligar" chama o seu telefone de mesa e completa a ligação quando você
              atender. Para falar pelo navegador, peça ao administrador para marcar "Softphone do navegador" no
              cadastro do ramal.</div></div></div>`
      : !cfg.disponivel
        ? `<div class="aviso-form" style="margin-bottom:16px">${icon('info','ico')}
            <div><b>O telefone do navegador não está disponível</b><div class="tiny">${esc(cfg.motivo || '')}</div></div></div>`
        : '';

    return pageHead('Telefone', `Ramal <span class="mono">${esc(r.numero)}</span> · ${esc(r.nome)}`,
      '<span id="foneEstado"></span>') + avisoRamal + `
      <div class="fone-grade">
        <div>
          <div class="card fone-aparelho" style="margin-bottom:16px">
            <div class="card-body" id="foneTelefone"></div>
          </div>

          <div class="card" style="margin-bottom:16px">
            <div class="card-head row-between">
              <div><div class="card-title">Não perturbe</div>
                <div class="card-sub">Ligado, o ramal não toca: quem ligar cai no correio de voz ou no desvio.</div></div>
              <label class="switch"><input type="checkbox" id="foneDnd" ${r.dnd ? 'checked' : ''}><span class="track"></span></label>
            </div>
          </div>

          <div class="card">
            <div class="card-head"><div class="card-title">Áudio</div></div>
            <div class="card-body">
              <div class="field"><label class="label">Volume de quem fala com você
                <span class="tiny muted" id="foneAltoTxt"></span></label>
                <input type="range" class="fone-faixa" id="foneAlto" min="0" max="100" step="1"></div>
              <div class="field"><label class="label">Volume do seu microfone
                <span class="tiny muted" id="foneMicroTxt"></span></label>
                <input type="range" class="fone-faixa" id="foneMicro" min="0" max="200" step="5">
                <div class="fone-nivel" title="O que está saindo pelo microfone agora"><i id="foneNivel"></i></div>
                <span class="hint">100% é como o microfone capta. O medidor mexe quando você fala, durante a chamada.</span></div>
              <div class="field"><label class="label">Microfone</label>
                <select class="select" id="foneEntrada"><option value="">Padrão do sistema</option></select>
                <span class="hint">Vale a partir da próxima chamada.</span></div>
              <div class="field" id="foneSaidaCampo"><label class="label">Alto-falante</label>
                <select class="select" id="foneSaida"><option value="">Padrão do sistema</option></select></div>
            </div>
          </div>
        </div>

        <div>
          <div class="card" style="margin-bottom:16px">
            <div class="card-head row-between" style="flex-wrap:wrap;gap:10px">
              <div class="segmented" id="foneAbas">
                <button data-aba="meus">Meus contatos</button>
                <button data-aba="globais">Globais</button>
                <button data-aba="ramais">Ramais</button>
              </div>
              <div class="input-icon search-mini">${icon('search','ico ico-sm')}
                <input class="input" id="foneBusca" placeholder="Buscar nome, empresa ou número…"></div>
            </div>
            <div class="card-body tight" id="foneContatos"></div>
          </div>

          <div class="card">
            <div class="card-head"><div class="card-title">Chamadas desta sessão</div></div>
            <div class="card-body tight" id="foneHistorico"></div>
          </div>
        </div>
      </div>`;
  },

  mount() {
    if (!this._fone?.disponivel) return;
    Softphone.pintar();
    this.pintarEstado();
    this.pintarHistorico();
    this.ligarAudio();
    this.ligarDnd();
    this.ligarContatos();

    // O aparelho avisa quando muda; a tela acompanha o que depende dele.
    // Os ouvintes são os mesmos objetos a cada visita, e saem antes de
    // entrar: sair e voltar para a tela empilhava um par novo por vez.
    this._aoEstado ??= () => { if (document.getElementById('foneTelefone')) { this.pintarEstado(); this.pintarContatosAcoes(); } };
    this._aoHistorico ??= () => { if (document.getElementById('foneHistorico')) this.pintarHistorico(); };
    document.removeEventListener('telium:fone-estado', this._aoEstado);
    document.removeEventListener('telium:fone-historico', this._aoHistorico);
    document.addEventListener('telium:fone-estado', this._aoEstado);
    document.addEventListener('telium:fone-historico', this._aoHistorico);
  },

  pintarEstado() {
    const el = document.getElementById('foneEstado');
    if (!el) return;
    const r = Softphone.registro?.estado;
    el.innerHTML = r === 'pronto'
      ? '<span class="badge badge-ok"><i class="dot"></i>Registrado</span>'
      : r === 'registrando'
        ? '<span class="badge badge-warn"><i class="dot dot-pulse"></i>Registrando…</span>'
        : r === 'erro'
          ? `<span class="badge badge-danger" title="${esc(Softphone.registro?.motivo || '')}"><i class="dot"></i>Sem registro</span>`
          : r === 'aparelho' || r === 'indisponivel'
            ? '<span class="badge"><i class="dot"></i>Pelo telefone de mesa</span>'
            : '<span class="badge badge-warn"><i class="dot"></i>Desconectado</span>';
  },

  // ------------------------------------------------------------ áudio
  ligarAudio() {
    const alto = document.getElementById('foneAlto');
    const micro = document.getElementById('foneMicro');
    const txt = () => {
      document.getElementById('foneAltoTxt').textContent = `${Math.round(SipLink.volume.alto * 100)}%`;
      document.getElementById('foneMicroTxt').textContent = `${Math.round(SipLink.volume.micro * 100)}%`;
    };
    alto.value = Math.round(SipLink.volume.alto * 100);
    micro.value = Math.round(SipLink.volume.micro * 100);
    txt();
    alto.oninput = () => { SipLink.ajustarAlto(alto.value / 100); txt(); };
    micro.oninput = () => { SipLink.ajustarMicro(micro.value / 100); txt(); };

    // Os nomes dos dispositivos só aparecem depois que o navegador
    // liberou o microfone uma vez; antes disso vêm vazios.
    SipLink.dispositivos().then(d => {
      const entrada = document.getElementById('foneEntrada');
      const saida = document.getElementById('foneSaida');
      if (!entrada) return;
      d.entradas.forEach((x, i) => entrada.insertAdjacentHTML('beforeend',
        `<option value="${esc(x.deviceId)}" ${x.deviceId === SipLink.volume.entrada ? 'selected' : ''}>${esc(x.label || `Microfone ${i + 1}`)}</option>`));
      entrada.onchange = () => { SipLink.escolherEntrada(entrada.value); toast('Microfone escolhido. Vale a partir da próxima chamada.', 'ok'); };

      if (!d.escolheSaida) {
        document.getElementById('foneSaidaCampo').innerHTML = `<label class="label">Alto-falante</label>
          <p class="hint" style="margin:0">Este navegador não deixa escolher o alto-falante: vale o padrão do sistema.</p>`;
        return;
      }
      d.saidas.forEach((x, i) => saida.insertAdjacentHTML('beforeend',
        `<option value="${esc(x.deviceId)}" ${x.deviceId === SipLink.volume.saida ? 'selected' : ''}>${esc(x.label || `Alto-falante ${i + 1}`)}</option>`));
      saida.onchange = () => SipLink.escolherSaida(saida.value);
    });

    // O medidor do microfone: lê o nível só enquanto a tela está aberta.
    const nivel = document.getElementById('foneNivel');
    const quadro = () => {
      if (!document.getElementById('foneNivel')) return;
      nivel.style.width = `${Math.round(SipLink.nivel() * 100)}%`;
      requestAnimationFrame(quadro);
    };
    requestAnimationFrame(quadro);
  },

  // ------------------------------------------------------------ não perturbe
  ligarDnd() {
    const dnd = document.getElementById('foneDnd');
    dnd.onchange = async () => {
      dnd.disabled = true;
      try {
        const r = await Api.put('/me/telefone/dnd', { ativo: dnd.checked });
        this._fone.ramal.dnd = r.dnd;
        toast(r.dnd ? 'Não perturbe ligado: o ramal não toca.' : 'Não perturbe desligado.', 'ok');
      } catch (e) {
        dnd.checked = !dnd.checked;
        toast(e.message, 'err');
      } finally { dnd.disabled = false; }
    };
  },

  // ------------------------------------------------------------ contatos
  ligarContatos() {
    const abas = document.getElementById('foneAbas');
    const marcar = () => abas.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.aba === this._aba));
    marcar();
    abas.onclick = ev => {
      const b = ev.target.closest('[data-aba]');
      if (!b) return;
      this._aba = b.dataset.aba;
      marcar();
      this.carregarContatos();
    };

    let espera;
    document.getElementById('foneBusca').oninput = ev => {
      clearTimeout(espera);
      espera = setTimeout(() => { this._busca = ev.target.value.trim(); this.carregarContatos(); }, 250);
    };

    // Um clique só para a lista inteira: ligar ou transferir.
    document.getElementById('foneContatos').onclick = ev => {
      const b = ev.target.closest('[data-num]');
      if (!b) return;
      if (b.dataset.acao === 'transferir') Softphone.transferirPara(b.dataset.num);
      else Softphone.discarPara(b.dataset.num, b.dataset.nome || '');
    };

    this.carregarContatos();
  },

  async carregarContatos() {
    const alvo = document.getElementById('foneContatos');
    if (!alvo) return;
    alvo.innerHTML = '<p class="small muted" style="padding:16px">Carregando…</p>';
    const q = this._busca;
    // Trocar de aba ou digitar rápido: só a resposta do último pedido vale.
    const pedido = this._pedido = (this._pedido || 0) + 1;

    try {
      let linhas = [];
      if (this._aba === 'ramais') {
        const r = await Api.get('/ramais', { limite: 500, q });
        linhas = r.dados.filter(x => Number(x.ativo ?? 1)).map(x => ({
          nome: x.nome, sub: x.setor || '', numeros: [{ rotulo: 'ramal', valor: x.numero }]
        }));
      } else {
        const r = await Api.get('/contatos', { escopo: this._aba === 'meus' ? 'pessoal' : 'corporativo', q });
        linhas = (r.dados || []).filter(c => Number(c.ativo ?? 1)).map(c => ({
          nome: c.nome, sub: [c.empresa, c.cargo].filter(Boolean).join(' · '),
          favorito: Number(c.favorito) === 1,
          numeros: [
            c.ramal_interno && { rotulo: 'ramal', valor: c.ramal_interno },
            c.numero && { rotulo: 'número', valor: c.numero },
            c.celular && { rotulo: 'celular', valor: c.celular },
            c.telefone && { rotulo: 'telefone', valor: c.telefone }
          ].filter(Boolean)
        })).filter(c => c.numeros.length);
      }
      if (pedido !== this._pedido) return;
      this._linhas = linhas;
      this.pintarContatos();
    } catch (e) {
      if (pedido !== this._pedido) return;
      alvo.innerHTML = `<p class="small" style="padding:16px;color:var(--danger)">${esc(e.message)}</p>`;
    }
  },

  pintarContatos() {
    const alvo = document.getElementById('foneContatos');
    if (!alvo) return;
    const linhas = this._linhas || [];
    if (!linhas.length) {
      alvo.innerHTML = vazio('book', this._busca ? 'Nada encontrado' : 'Nenhum contato aqui',
        this._aba === 'meus' ? 'Os seus contatos pessoais aparecem aqui. Cadastre em PCU → Meus Contatos, se tiver acesso.'
          : this._aba === 'globais' ? 'A agenda corporativa, comum a todos, aparece aqui.'
          : 'Nenhum ramal encontrado.');
      return;
    }
    alvo.innerHTML = `<div class="fone-lista">${linhas.map(c => `
      <div class="fone-contato">
        <span class="avatar avatar-sm">${esc((c.nome || '?').slice(0, 2).toUpperCase())}</span>
        <div class="grow" style="min-width:0"><b class="truncate">${c.favorito ? '★ ' : ''}${esc(c.nome)}</b>
          <div class="tiny muted truncate">${esc(c.sub)}</div></div>
        <div class="fone-numeros">${c.numeros.map(n => `
          <span class="fone-numero"><span class="tiny muted">${esc(n.rotulo)}</span>
            <span class="mono small">${esc(n.valor)}</span>
            <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ligar" data-num="${esc(n.valor)}" data-nome="${esc(c.nome)}">${icon('phone','ico ico-sm')}</button>
            <button class="btn btn-ghost btn-sm btn-icon fone-transf" data-tip="Transferir a chamada para cá" data-acao="transferir"
                    data-num="${esc(n.valor)}" hidden>${icon('shuffle','ico ico-sm')}</button>
          </span>`).join('')}</div>
      </div>`).join('')}</div>`;
    this.pintarContatosAcoes();
  },

  /** Em chamada, cada número ganha o botão de transferir para ele. */
  pintarContatosAcoes() {
    const emChamada = Softphone.estado === 'em chamada';
    document.querySelectorAll('#foneContatos .fone-transf').forEach(b => { b.hidden = !emChamada; });
  },

  // ------------------------------------------------------------ histórico
  pintarHistorico() {
    const alvo = document.getElementById('foneHistorico');
    if (!alvo) return;
    const h = Softphone.historico;
    alvo.innerHTML = h.length ? `<div class="fone-lista">${h.map(x => `
      <div class="fone-contato">
        <span class="fone-dir fone-dir-${x.dir}">${icon(x.dir === 'saida' ? 'arrowUp' : x.dir === 'perdida' ? 'phoneOff' : 'arrowDown','ico ico-sm')}</span>
        <div class="grow"><b class="mono">${esc(x.num)}</b>
          <div class="tiny muted">${esc(x.nome || '')} ${x.dir === 'perdida' ? '· não atendida' : x.seg ? '· ' + Softphone.fmt(x.seg) : ''}</div></div>
        <span class="tiny muted">${esc(x.quando)}</span>
        <button class="btn btn-ghost btn-sm btn-icon" data-tip="Ligar de volta" data-rediscar="${esc(x.num)}">${icon('phone','ico ico-sm')}</button>
      </div>`).join('')}</div>`
      : '<p class="small muted" style="padding:16px">As chamadas que você fizer e receber por aqui aparecem nesta lista.</p>';
    alvo.querySelectorAll('[data-rediscar]').forEach(b => b.onclick = () => Softphone.discarPara(b.dataset.rediscar));
  }
};
