/* =========================================================
   Telium PABX — Portal do Usuário (PCU)

   O que cada pessoa resolve sozinha, sem passar pelo
   administrador: ver o próprio ramal, ligar o siga-me, ouvir
   o recado, conferir as chamadas e trocar a senha.

   Nenhuma tela daqui manda o número do ramal para a API: o
   servidor o tira da sessão. Mandar seria dar ao navegador a
   chance de pedir o ramal do vizinho.
   ========================================================= */

/** Aviso comum a todas as telas quando a conta não tem ramal vinculado. */
function pcuSemRamal(d) {
  return `<div class="card">${vazio('phone', 'Sua conta não tem ramal',
    esc(d?.motivo || 'Peça ao administrador para vincular um ramal à sua conta.'))}</div>`;
}

/** Linha de rótulo e valor, usada nos cartões de resumo. */
function pcuLinha(rotulo, valor, destaque = false) {
  return `<div class="pcu-linha">
    <span class="tiny muted">${esc(rotulo)}</span>
    <b class="${destaque ? 'mono' : ''}">${valor}</b>
  </div>`;
}

/* ------------------------- Meu Ramal ------------------------- */
PAGES['pcu.meuramal'] = {
  _dados: null,

  async render(ctx) {
    let d;
    try { d = await Api.get('/me/ramal'); }
    catch (e) { return pageHead('Meu Ramal', '') + blocoErro(e); }
    if (!d.disponivel) return pageHead('Meu Ramal', '') + pcuSemRamal(d);

    this._dados = d;
    const r = d.ramal;
    const reg = d.registro || {};

    const estado = !reg.disponivel
      ? '<span class="badge">central sem resposta</span>'
      : reg.registrado
        ? `<span class="badge badge-ok"><i class="dot dot-pulse"></i>Registrado</span>`
        : '<span class="badge badge-warn">Nenhum aparelho registrado</span>';

    const aparelhos = (reg.dispositivos || []).length
      ? `<table class="table"><thead><tr><th>Aparelho</th><th>Estado</th><th class="num">Resposta</th></tr></thead>
         <tbody>${reg.dispositivos.map(x => `<tr>
           <td class="mono small">${esc(x.uri)}</td>
           <td>${x.estado === 'Avail'
             ? '<span class="badge badge-ok">disponível</span>'
             : `<span class="badge badge-warn">${esc(x.estado)}</span>`}</td>
           <td class="num small dim">${x.atraso != null ? `${x.atraso} ms` : '—'}</td>
         </tr>`).join('')}</tbody></table>`
      : `<p class="small muted">Nenhum telefone registrado neste ramal agora. Um aparelho de mesa
         precisa do número, do endereço da central e da senha SIP — peça a senha ao administrador.</p>`;

    return pageHead('Meu Ramal', 'O que é seu, e o que você pode mudar sem pedir a ninguém.') + `
      <div class="grid g-2">
        <div class="card" style="padding:18px">
          <div class="row-between" style="margin-bottom:14px">
            <div>
              <b style="font-size:20px" class="mono">${esc(r.numero)}</b>
              <div class="tiny muted">${esc(r.nome || '')}${r.setor ? ` · ${esc(r.setor)}` : ''}</div>
            </div>
            ${estado}
          </div>
          ${pcuLinha('Correio de voz', r.voicemail
            ? '<span class="badge badge-ok">ligado</span>'
            : '<span class="muted">desligado</span>')}
          ${pcuLinha('Softphone no navegador', r.webrtc
            ? '<span class="badge badge-brand">liberado</span>'
            : '<span class="muted">não liberado</span>')}
          ${pcuLinha('Gravação de chamadas', `<span class="small">${esc({
            nao: 'não grava', entrada: 'só recebidas',
            saida: 'só feitas', ambas: 'recebidas e feitas'
          }[r.gravar] || r.gravar || '—')}</span>`)}
          <div style="margin-top:16px">${aparelhos}</div>
        </div>

        <form class="card" id="fMeuRamal" style="padding:18px">
          <b>Preferências</b>
          <p class="small muted" style="margin:4px 0 14px">
            Valem na hora, sem precisar de ninguém aplicar configuração.</p>

          ${campoHtml({ campo: 'dnd', label: 'Não perturbe', tipo: 'switch',
            ajuda: 'Quem ligar cai direto no correio de voz, ou ouve ocupado.' }, r)}

          ${campoHtml({ campo: 'chamada_espera', label: 'Chamada em espera', tipo: 'switch',
            ajuda: 'Desligado, a segunda chamada recebe ocupado em vez de bipar no seu telefone.' }, r)}

          ${campoHtml({ campo: 'tempo_toque', label: 'Tempo de toque (segundos)', tipo: 'number',
            ajuda: 'Quanto o telefone toca antes de desistir. Entre 5 e 120.' }, r)}

          ${campoHtml({ campo: 'email', label: 'Meu e-mail',
            ajuda: 'Para onde vai a cópia do recado, quando você pedir.' }, r)}

          ${r.voicemail ? campoHtml({ campo: 'vm_email', label: 'Receber recado por e-mail',
            tipo: 'switch' }, r) : ''}

          <div class="row gap-8" style="margin-top:16px">
            <button class="btn btn-primary btn-sm" type="submit">
              ${icon('check','ico ico-sm')} Salvar</button>
            <span class="small muted" id="mrEstado"></span>
          </div>
        </form>
      </div>`;
  },

  mount() {
    const f = document.getElementById('fMeuRamal');
    f?.addEventListener('submit', async ev => {
      ev.preventDefault();
      const estado = document.getElementById('mrEstado');
      const dados = lerFormulario(f);
      estado.textContent = 'salvando…';
      try {
        await Api.put('/me/ramal', dados);
        estado.textContent = 'salvo';
        toast('Preferências salvas', 'ok');
        setTimeout(() => App.route(), 600);
      } catch (e) {
        estado.textContent = '';
        toast(e.message, 'err');
      }
    });
  }
};

/* ------------------------- Meu Siga-me ------------------------- */
PAGES['pcu.sigame'] = {
  async render() {
    let d;
    try { d = await Api.get('/me/ramal'); }
    catch (e) { return pageHead('Meu Siga-me', '') + blocoErro(e); }
    if (!d.disponivel) return pageHead('Meu Siga-me', '') + pcuSemRamal(d);

    const r = d.ramal;
    const ligado = Number(r.siga_me_ativo) === 1 && r.siga_me;

    return pageHead('Meu Siga-me',
      'Manda as chamadas do seu ramal para outro número — um celular, por exemplo.') + `
      <div class="grid g-2">
        <form class="card" id="fSigaMe" style="padding:18px">
          <div class="row-between" style="margin-bottom:14px">
            <b>Para onde vão minhas chamadas</b>
            ${ligado
              ? '<span class="badge badge-ok"><i class="dot dot-pulse"></i>ativo</span>'
              : '<span class="badge">desligado</span>'}
          </div>

          ${campoHtml({ campo: 'siga_me_ativo', label: 'Siga-me ligado', tipo: 'switch' }, r)}

          ${campoHtml({ campo: 'siga_me', label: 'Número de destino', mono: true,
            placeholder: '11987654321',
            ajuda: 'Pode ser outro ramal ou um número externo, com DDD. '
                 + 'Para número externo, seu ramal precisa ter permissão de discagem para ele.' }, r)}

          ${campoHtml({ campo: 'siga_me_modo', label: 'Quando tocar no destino', tipo: 'select',
            opcoes: [
              { valor: 'junto',  rotulo: 'Junto — toca o ramal e o destino ao mesmo tempo' },
              { valor: 'depois', rotulo: 'Depois — só procura o destino se o ramal não atender' }
            ] }, r)}

          <div class="row gap-8" style="margin-top:16px">
            <button class="btn btn-primary btn-sm" type="submit">
              ${icon('check','ico ico-sm')} Salvar</button>
            ${ligado ? `<button class="btn btn-outline btn-sm" type="button" id="sgDesligar">
              Desligar agora</button>` : ''}
            <span class="small muted" id="sgEstado"></span>
          </div>
        </form>

        <div class="card" style="padding:18px">
          <b>Como funciona</b>
          <ul class="small muted lista-ajuda" style="margin-top:10px">
            <li><b>Junto</b> toca os dois ao mesmo tempo. Quem atender primeiro fica com a chamada.</li>
            <li><b>Depois</b> deixa o seu ramal tocar até o tempo acabar e só então procura o destino.
                É o que se usa quando o celular é o plano B.</li>
            <li>Se o destino não atender, a chamada volta para o <b>seu</b> correio de voz — e não
                para a caixa postal da operadora do celular.</li>
            <li>Pelo telefone: <span class="mono">*72</span> mais o número liga,
                <span class="mono">*73</span> desliga.</li>
          </ul>
        </div>
      </div>`;
  },

  mount() {
    const f = document.getElementById('fSigaMe');
    const salvar = async dados => {
      const estado = document.getElementById('sgEstado');
      estado.textContent = 'salvando…';
      try {
        await Api.put('/me/ramal', dados);
        toast('Siga-me atualizado', 'ok');
        App.route();
      } catch (e) { estado.textContent = ''; toast(e.message, 'err'); }
    };

    f?.addEventListener('submit', ev => { ev.preventDefault(); salvar(lerFormulario(f)); });
    document.getElementById('sgDesligar')
      ?.addEventListener('click', () => salvar({ siga_me_ativo: 0 }));
  }
};

/* ------------------------- Minhas Chamadas ------------------------- */
PAGES['pcu.chamadas'] = {
  _f: { direcao: '', de: '', ate: '', pagina: 1 },

  async render() {
    let d;
    try { d = await Api.get('/me/chamadas', this._f); }
    catch (e) { return pageHead('Minhas Chamadas', '') + blocoErro(e); }
    if (!d.disponivel) return pageHead('Minhas Chamadas', '') + pcuSemRamal(d);

    const paginas = Math.max(1, Math.ceil(d.total / d.limite));
    const sel = v => this._f.direcao === v ? 'selected' : '';

    const linhas = d.dados.length ? d.dados.map(c => {
      const atendida = c.disposition === 'ANSWERED';
      return `<tr>
        <td class="small">${dataHora(c.calldate)}</td>
        <td>${c.sentido === 'feita'
          ? `<span class="badge badge-brand">${icon('arrowUp','ico ico-sm')}feita</span>`
          : `<span class="badge badge-info">${icon('arrowDown','ico ico-sm')}recebida</span>`}</td>
        <td><b class="mono">${esc(c.outro || '—')}</b></td>
        <td>${atendida
          ? '<span class="badge badge-ok">atendida</span>'
          : `<span class="badge badge-warn">${esc({
              NO: 'não atendida', BUSY: 'ocupado', FAILED: 'falhou',
              'NO ANSWER': 'não atendida'
            }[c.disposition] || c.disposition || '—')}</span>`}</td>
        <td class="num">${atendida ? duracao(c.billsec) : '—'}</td>
        <td class="num small dim">${duracao(c.duration)}</td>
      </tr>`;
    }).join('') : '';

    return pageHead('Minhas Chamadas',
      `Tudo o que passou pelo ramal ${esc(d.ramal)}.`) + `
      <div class="card" style="padding:14px;margin-bottom:14px">
        <div class="row gap-8 wrap">
          <select class="input" id="chDirecao" style="max-width:190px">
            <option value="">Todas as chamadas</option>
            <option value="entrada" ${sel('entrada')}>Só de fora</option>
            <option value="saida" ${sel('saida')}>Só para fora</option>
            <option value="interna" ${sel('interna')}>Só internas</option>
          </select>
          <input class="input" type="date" id="chDe" value="${esc(this._f.de)}" style="max-width:170px">
          <input class="input" type="date" id="chAte" value="${esc(this._f.ate)}" style="max-width:170px">
          <button class="btn btn-outline btn-sm" id="chLimpar">Limpar</button>
          <span class="grow"></span>
          <span class="small muted">${num(d.total)} chamada${d.total === 1 ? '' : 's'}</span>
        </div>
      </div>

      <div class="card">
        ${linhas ? `<div class="table-wrap"><table class="table">
          <thead><tr><th>Quando</th><th>Sentido</th><th>Com quem</th>
            <th>Resultado</th><th class="num">Conversa</th><th class="num">Total</th></tr></thead>
          <tbody>${linhas}</tbody></table></div>
          ${paginas > 1 ? `<div class="row-between" style="padding:12px 14px">
            <span class="small muted">Página ${d.pagina} de ${paginas}</span>
            <div class="row gap-8">
              <button class="btn btn-outline btn-sm" data-pagina="${d.pagina - 1}"
                ${d.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
              <button class="btn btn-outline btn-sm" data-pagina="${d.pagina + 1}"
                ${d.pagina >= paginas ? 'disabled' : ''}>Próxima</button>
            </div></div>` : ''}`
        : vazio('list', 'Nenhuma chamada no período',
            'Quando você ligar ou receber, aparece aqui. A conversa é o tempo falado; '
          + 'o total inclui o tempo chamando.')}
      </div>`;
  },

  mount() {
    const rec = () => { this._f.pagina = 1; App.route(); };
    document.getElementById('chDirecao')?.addEventListener('change', e => {
      this._f.direcao = e.target.value; rec();
    });
    document.getElementById('chDe')?.addEventListener('change', e => { this._f.de = e.target.value; rec(); });
    document.getElementById('chAte')?.addEventListener('change', e => { this._f.ate = e.target.value; rec(); });
    document.getElementById('chLimpar')?.addEventListener('click', () => {
      this._f = { direcao: '', de: '', ate: '', pagina: 1 }; App.route();
    });
    document.querySelectorAll('[data-pagina]').forEach(b => b.addEventListener('click', () => {
      this._f.pagina = Number(b.dataset.pagina); App.route();
    }));
  }
};

/* ------------------------- Meu Correio de Voz ------------------------- */
PAGES['pcu.correiovoz'] = {
  async render() {
    let d;
    try { d = await Api.get('/me/correiovoz'); }
    catch (e) { return pageHead('Meu Correio de Voz', '') + blocoErro(e); }
    if (!d.disponivel) return pageHead('Meu Correio de Voz', '') + pcuSemRamal(d);

    if (!d.ativo) {
      return pageHead('Meu Correio de Voz', '') +
        `<div class="card">${vazio('voicemail', 'Correio de voz desligado', esc(d.motivo))}</div>`;
    }

    const total = d.pastas.reduce((s, p) => s + p.mensagens.length, 0);
    if (total === 0) {
      return pageHead('Meu Correio de Voz', `Caixa do ramal ${esc(d.ramal)}.`) +
        `<div class="card">${vazio('voicemail', 'Nenhum recado',
          'Quando alguém deixar uma mensagem, ela aparece aqui — e você pode ouvir sem discar nada.')}</div>`;
    }

    const pastas = d.pastas.filter(p => p.mensagens.length).map(p => `
      <div class="card" style="margin-bottom:14px">
        <div class="card-head row-between">
          <b>${esc(p.rotulo)}</b>
          <span class="badge ${p.pasta === 'INBOX' ? 'badge-brand' : ''}">${p.mensagens.length}</span>
        </div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>De</th><th>Quando</th><th class="num">Duração</th><th class="col-actions"></th></tr></thead>
          <tbody>${p.mensagens.map(m => `<tr data-pasta="${esc(p.pasta)}" data-id="${esc(m.id)}">
            <td><b class="mono">${esc(m.origem || 'desconhecido')}</b></td>
            <td class="small">${dataHora(m.quando)}</td>
            <td class="num">${duracao(m.duracao)}</td>
            <td class="col-actions"><span class="row-actions">
              ${m.tem_audio ? `<button class="btn btn-ghost btn-sm btn-icon" data-tip="Ouvir" data-ouvir>
                ${icon('play','ico ico-sm')}</button>` : '<span class="tiny muted">sem áudio</span>'}
              <button class="btn btn-ghost btn-sm btn-icon" data-tip="Apagar" data-apagar>
                ${icon('trash','ico ico-sm')}</button>
            </span></td>
          </tr>`).join('')}</tbody>
        </table></div>
      </div>`).join('');

    return pageHead('Meu Correio de Voz',
      `Caixa do ramal ${esc(d.ramal)}. Pelo telefone, disque <span class="mono">*97</span>.`) +
      (d.copia_por_email && d.email
        ? `<div class="aviso" style="margin-bottom:14px">${icon('mail','ico ico-sm')}
             Uma cópia de cada recado também vai para ${esc(d.email)}.</div>`
        : '') + pastas;
  },

  mount() {
    document.querySelectorAll('[data-ouvir]').forEach(b => b.addEventListener('click', async () => {
      const tr = b.closest('tr');
      const aberto = tr.nextElementSibling?.hasAttribute('data-player-vm');
      document.querySelectorAll('[data-player-vm]').forEach(l => {
        l.querySelector('audio')?.pause(); l.remove();
      });
      if (aberto) return;

      b.disabled = true;
      b.innerHTML = '<span class="spin"></span>';
      try {
        const { blob } = await Api.baixar(
          `/me/correiovoz/audio?pasta=${encodeURIComponent(tr.dataset.pasta)}`
          + `&id=${encodeURIComponent(tr.dataset.id)}`);
        const url = URL.createObjectURL(blob);
        tr.insertAdjacentHTML('afterend', `
          <tr data-player-vm><td colspan="4" style="background:var(--surface-2)">
            <audio controls autoplay preload="auto" src="${url}" style="width:100%;height:38px"></audio>
          </td></tr>`);
        tr.nextElementSibling.querySelector('audio')
          .addEventListener('ended', () => setTimeout(() => URL.revokeObjectURL(url), 1000));
      } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
      b.innerHTML = icon('play', 'ico ico-sm');
    }));

    document.querySelectorAll('[data-apagar]').forEach(b => b.addEventListener('click', async () => {
      const tr = b.closest('tr');
      const ok = await Modal.confirm({
        titulo: 'Apagar este recado?',
        texto: 'A mensagem sai da sua caixa e não tem como voltar.',
        ok: 'Apagar'
      });
      if (!ok) return;
      try {
        await Api.delete(`/me/correiovoz?pasta=${encodeURIComponent(tr.dataset.pasta)}`
          + `&id=${encodeURIComponent(tr.dataset.id)}`);
        toast('Recado apagado', 'ok');
        App.route();
      } catch (e) { toast(e.message, 'err'); }
    }));
  }
};

/* ------------------------- Meu Perfil e Segurança ------------------------- */
PAGES['pcu.perfil'] = {
  async render(ctx) {
    const u = ctx.sess || {};
    const dois = u.totp_ativo
      ? `<span class="badge badge-ok">${icon('lock','ico ico-sm')} ligada</span>`
      : '<span class="badge badge-warn">desligada</span>';

    return pageHead('Meu Perfil e Segurança', 'Seus dados de acesso e a senha do console.') + `
      <div class="grid g-2">
        <div class="card" style="padding:18px">
          <div class="row gap-12" style="align-items:center;margin-bottom:16px">
            <span class="avatar">${esc(initials(u.nome))}</span>
            <div><b style="font-size:17px">${esc(u.nome || '')}</b>
              <div class="tiny muted">${esc(u.usuario || '')}</div></div>
          </div>
          ${pcuLinha('Perfil de acesso', `<span class="badge">${esc(u.perfil?.nome || '—')}</span>`)}
          ${pcuLinha('Ramal vinculado', u.ramal
            ? `<span class="mono">${esc(u.ramal)}</span>`
            : '<span class="muted">nenhum</span>')}
          ${pcuLinha('Setor', esc(u.setor || '—'))}
          ${pcuLinha('E-mail', esc(u.email || '—'))}
          ${pcuLinha('Verificação em dois passos', dois)}
          <p class="small muted" style="margin-top:14px">
            Nome, perfil e ramal são definidos pelo administrador.
            O e-mail do ramal você muda em <b>Meu Ramal</b>.</p>
        </div>

        <form class="card" id="fSenha" style="padding:18px">
          <b>Trocar minha senha</b>
          <p class="small muted" style="margin:4px 0 14px">
            Ao trocar, as suas outras sessões são encerradas — é o que se quer
            quando se desconfia que alguém entrou.</p>

          ${campoHtml({ campo: 'atual', label: 'Senha atual', tipo: 'password',
            obrigatorio: true }, {})}
          ${campoHtml({ campo: 'nova', label: 'Senha nova', tipo: 'password',
            obrigatorio: true,
            ajuda: 'Pelo menos 8 caracteres, com um número e um símbolo.' }, {})}
          ${campoHtml({ campo: 'confirma', label: 'Repita a senha nova', tipo: 'password',
            obrigatorio: true }, {})}

          <div class="row gap-8" style="margin-top:16px">
            <button class="btn btn-primary btn-sm" type="submit">
              ${icon('lock','ico ico-sm')} Trocar senha</button>
            <span class="small muted" id="snEstado"></span>
          </div>
        </form>

        <div class="card span-2" style="padding:18px" id="cartao2fa">
          <div class="row-between" style="margin-bottom:10px">
            <b>Verificação em dois passos</b>${dois}
          </div>
          <p class="small muted" style="margin-bottom:14px">
            Além da senha, a entrada passa a pedir um código de seis dígitos que muda a cada
            trinta segundos no seu celular. Vale para aplicativos como Google Authenticator,
            Microsoft Authenticator, Aegis ou 1Password.</p>
          <div id="area2fa"></div>
        </div>
      </div>`;
  },

  mount(ctx) {
    this.pintar2fa(Number(ctx.sess?.totp_ativo) === 1);

    const f = document.getElementById('fSenha');
    f?.addEventListener('submit', async ev => {
      ev.preventDefault();
      const estado = document.getElementById('snEstado');
      const d = lerFormulario(f);

      if (d.nova !== d.confirma) {
        estado.textContent = '';
        toast('A repetição não confere com a senha nova', 'err');
        return;
      }

      estado.textContent = 'trocando…';
      try {
        const r = await Api.post('/me/senha', { atual: d.atual, nova: d.nova });
        estado.textContent = '';
        f.reset();
        toast(r.sessoes_encerradas
          ? `Senha trocada. ${r.sessoes_encerradas} outra(s) sessão(ões) encerrada(s).`
          : 'Senha trocada.', 'ok');
      } catch (e) {
        estado.textContent = '';
        toast(e.message, 'err');
      }
    });
  },

  /**
   * Desenha o estado da verificação em dois passos.
   *
   * Três momentos: desligada, ligando (com o QR na tela) e ligada. O
   * segredo só vira exigência depois de o usuário provar um código —
   * ligar antes disso trancaria a conta para fora.
   */
  pintar2fa(ligada, inicio = null) {
    const area = document.getElementById('area2fa');
    if (!area) return;

    if (ligada) {
      area.innerHTML = `
        <div class="aviso ok" style="margin-bottom:14px">
          ${icon('checkCirc','ico ico-sm')}
          A entrada já pede o código do seu celular.</div>
        <form id="f2faOff" class="row gap-8 wrap" style="align-items:flex-end">
          <div class="field" style="max-width:280px;margin:0">
            <label>Sua senha, para desligar</label>
            <input class="input" type="password" name="senha" required
                   autocomplete="current-password">
          </div>
          <button class="btn btn-outline btn-sm" type="submit">Desligar</button>
        </form>`;
      document.getElementById('f2faOff').addEventListener('submit', async ev => {
        ev.preventDefault();
        try {
          await Api.delete2fa(lerFormulario(ev.currentTarget).senha);
          toast('Verificação em dois passos desligada', 'ok');
          await Auth.carregar();
          App.route();
        } catch (e) { toast(e.message, 'err'); }
      });
      return;
    }

    if (!inicio) {
      area.innerHTML = `<button class="btn btn-primary btn-sm" id="b2faOn">
        ${icon('lock','ico ico-sm')} Ligar verificação em dois passos</button>`;
      document.getElementById('b2faOn').addEventListener('click', async ev => {
        const b = ev.currentTarget;
        b.disabled = true;
        b.innerHTML = '<span class="spin"></span> gerando…';
        try { this.pintar2fa(false, await Api.post('/me/2fa/iniciar')); }
        catch (e) { toast(e.message, 'err'); App.route(); }
      });
      return;
    }

    area.innerHTML = `
      <div class="grid" style="grid-template-columns:auto 1fr;gap:20px;align-items:start">
        <div id="qr2fa" class="qr-caixa"></div>
        <div>
          <ol class="lista-ajuda small" style="list-style:decimal">
            <li>Abra o aplicativo de autenticação no celular.</li>
            <li>Leia o QR Code ao lado. Se a câmera não ajudar, digite o código abaixo.</li>
            <li>Confirme aqui o número de seis dígitos que o aplicativo mostrar.</li>
          </ol>
          <div class="field" style="max-width:340px">
            <label>Código para digitação manual</label>
            <input class="input mono" readonly value="${esc(inicio.segredo_legivel)}"
                   onclick="this.select()">
          </div>
          <form id="f2faOn" class="row gap-8" style="align-items:flex-end;margin-top:10px">
            <div class="field" style="max-width:190px;margin:0">
              <label>Código do aplicativo</label>
              <input class="input mono" name="codigo" inputmode="numeric" maxlength="6"
                     placeholder="000000" autocomplete="one-time-code" required>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Confirmar e ligar</button>
            <button class="btn btn-ghost btn-sm" type="button" id="b2faCancela">Cancelar</button>
          </form>
        </div>
      </div>`;

    // O QR é desenhado aqui, no navegador: a URI carrega o segredo, e
    // mandá-la para um gerador de terceiros seria entregar de propósito
    // a segunda barreira.
    const alvo = document.getElementById('qr2fa');
    if (typeof qrcode === 'undefined') {
      alvo.innerHTML = '<span class="small muted">Use o código para digitação manual.</span>';
    } else {
      const q = qrcode(0, 'M');
      q.addData(inicio.uri);
      q.make();
      alvo.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    }

    document.getElementById('b2faCancela').addEventListener('click', () => this.pintar2fa(false));
    document.getElementById('f2faOn').addEventListener('submit', async ev => {
      ev.preventDefault();
      try {
        await Api.post('/me/2fa/confirmar', { codigo: lerFormulario(ev.currentTarget).codigo });
        toast('Pronto. A próxima entrada vai pedir o código.', 'ok');
        await Auth.carregar();
        App.route();
      } catch (e) { toast(e.message, 'err'); }
    });
  }
};
