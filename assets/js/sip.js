/* =========================================================
   Telium PABX — ligação SIP do navegador
   O softphone fala SIP sobre WebSocket direto com o Asterisk:
   o navegador é um ramal como outro qualquer, e aparece como tal
   no "pjsip show contacts", no BLF e na captura.
   ========================================================= */

/**
 * Campainha do softphone.
 *
 * O toque é o arquivo em assets/audios/webrtc-chamada.mp3, publicado
 * junto com o console — nada de CDN: um PABX instalado na rede do
 * cliente não pode depender de buscar som fora.
 *
 * Se o arquivo não puder tocar — sumiu do servidor, ou o navegador
 * recusou o autoplay porque a pessoa ainda não clicou em nada na página
 * — cai num toque sintetizado. Chamada entrando sem nenhum som é a
 * falha que ninguém percebe até perder a ligação.
 */
const Campainha = {
  ARQUIVO: 'assets/audios/webrtc-chamada.mp3',

  el: null, ctx: null, timer: null, tocando: false,

  /** O elemento fica pronto desde o começo: carregar na hora atrasa o toque. */
  preparar() {
    if (this.el) return this.el;
    this.el = document.getElementById('spToque');
    if (!this.el) {
      this.el = document.createElement('audio');
      this.el.id = 'spToque';
      this.el.src = this.ARQUIVO;
      this.el.loop = true;
      this.el.preload = 'auto';
      this.el.hidden = true;
      document.body.appendChild(this.el);
    }
    return this.el;
  },

  tocar() {
    if (this.tocando) return;
    this.tocando = true;

    const el = this.preparar();
    try {
      el.currentTime = 0;
      const p = el.play();
      if (p && typeof p.catch === 'function') p.catch(() => this._sintetizado());
    } catch {
      this._sintetizado();
    }
  },

  parar() {
    this.tocando = false;
    if (this.el) { try { this.el.pause(); this.el.currentTime = 0; } catch { /* nunca tocou */ } }
    clearTimeout(this.timer);
    this.timer = null;
  },

  /** Plano B: dois tons no compasso de telefone, sem depender de arquivo. */
  _sintetizado() {
    if (!this.tocando) return;
    try {
      this.ctx = this.ctx || new (window.AudioContext || window.webkitAudioContext)();
      this.ctx.resume?.().catch(() => {});
    } catch { return; }

    const toque = () => {
      if (!this.tocando) return;
      [0, 0.45].forEach((atraso, i) => this._bip(440 + i * 110, atraso, 0.35));
      this.timer = setTimeout(toque, 2400);
    };
    toque();
  },

  _bip(hz, atraso, duracao) {
    const t = this.ctx.currentTime + atraso;
    const osc = this.ctx.createOscillator();
    const vol = this.ctx.createGain();
    osc.frequency.value = hz;
    osc.type = 'sine';
    // Entrada e saída suaves: onda quadrada seca estala no alto-falante.
    vol.gain.setValueAtTime(0, t);
    vol.gain.linearRampToValueAtTime(0.14, t + 0.02);
    vol.gain.setValueAtTime(0.14, t + duracao - 0.04);
    vol.gain.linearRampToValueAtTime(0, t + duracao);
    osc.connect(vol).connect(this.ctx.destination);
    osc.start(t);
    osc.stop(t + duracao + 0.02);
  }
};

const SipLink = {
  ua: null,
  sessao: null,          // a chamada principal
  consulta: null,        // a segunda perna, durante uma transferência com consulta
  audio: null,
  audioConsulta: null,
  estado: 'desligado',   // desligado | registrando | pronto | erro
  motivo: '',
  cfg: null,

  /**
   * Teto para a coleta de candidatos ICE, em milissegundos.
   *
   * O JsSIP só manda o INVITE quando o navegador termina de juntar
   * candidatos, e não tem prazo nenhum para isso. Com um TURN que não
   * responde — o caso mais comum de instalação recém-entregue — o
   * navegador insiste até desistir sozinho, e quem clicou em "Ligar"
   * fica olhando para a tela sem nada acontecer por vários segundos.
   * Passado este prazo a chamada sai com o que já se tem: pior um
   * candidato a menos do que uma discagem que parece travada.
   */
  PRAZO_ICE: 2000,

  /**
   * Volumes e dispositivos, lembrados neste navegador.
   *   alto   0…1  — volume de quem fala do outro lado
   *   micro  0…2  — ganho do microfone (1 = como ele capta)
   *   saida        — alto-falante (setSinkId), vazio = o padrão
   *   entrada      — microfone (deviceId), vazio = o padrão
   */
  volume: (() => {
    const base = { alto: 1, micro: 1, saida: '', entrada: '' };
    try { return { ...base, ...JSON.parse(localStorage.getItem('telium.fone.volume') || '{}') }; }
    catch { return base; }
  })(),

  _guardarVolume() {
    try { localStorage.setItem('telium.fone.volume', JSON.stringify(this.volume)); } catch { /* sem armazenamento */ }
  },

  /** Microfones das chamadas vivas: um por perna. */
  _micros: new Set(),
  _geracao: 0,
  _geracaoConsulta: 0,

  /**
   * O microfone, passado por um ganho antes de sair.
   *
   * O navegador não tem "volume do microfone": o que sai é o que ele
   * capta. Para o controle da tela mexer de verdade no que o outro lado
   * ouve, o áudio passa por um GainNode e é essa saída — e não a
   * captura crua — que vai para a chamada. O medidor de nível lê o
   * mesmo ponto, então mostra o que está sendo enviado.
   */
  async _microfone() {
    const audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
    if (this.volume.entrada) audio.deviceId = { exact: this.volume.entrada };

    let captura;
    try {
      captura = await navigator.mediaDevices.getUserMedia({ audio, video: false });
    } catch (e) {
      // Microfone escolhido que sumiu (fone desplugado): tenta o padrão.
      if (this.volume.entrada && e?.name === 'OverconstrainedError') {
        this.volume.entrada = '';
        this._guardarVolume();
        return this._microfone();
      }
      throw e;
    }

    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return { stream: captura, ganho: null, medidor: null, parar: () => captura.getTracks().forEach(t => t.stop()) };

    const ctx = new Ctx();
    // Criado depois de um await, o contexto pode nascer suspenso (Safari,
    // página sem clique recente) — e aí sai silêncio pelo microfone.
    ctx.resume?.().catch(() => {});
    const fonte = ctx.createMediaStreamSource(captura);
    const ganho = ctx.createGain();
    ganho.gain.value = this.volume.micro;
    const medidor = ctx.createAnalyser();
    medidor.fftSize = 512;
    const destino = ctx.createMediaStreamDestination();
    fonte.connect(ganho);
    ganho.connect(medidor);
    ganho.connect(destino);

    const mic = {
      stream: destino.stream, ganho, medidor,
      parar: () => {
        captura.getTracks().forEach(t => t.stop());
        destino.stream.getTracks().forEach(t => t.stop());
        ctx.close?.().catch(() => {});
        this._micros.delete(mic);
      }
    };
    this._micros.add(mic);
    return mic;
  },

  /** Volume de quem está do outro lado, 0 a 1. */
  ajustarAlto(v) {
    this.volume.alto = Math.max(0, Math.min(1, Number(v)));
    [this.audio, this.audioConsulta].forEach(el => { if (el) el.volume = this.volume.alto; });
    this._guardarVolume();
  },

  /** Ganho do microfone, 0 a 2, valendo na hora para a chamada viva. */
  ajustarMicro(v) {
    this.volume.micro = Math.max(0, Math.min(2, Number(v)));
    this._micros.forEach(m => { if (m.ganho) m.ganho.gain.value = this.volume.micro; });
    this._guardarVolume();
  },

  /** Alto-falante de saída (quando o navegador deixa escolher). */
  async escolherSaida(id) {
    this.volume.saida = id || '';
    this._guardarVolume();
    const els = [this.audio, this.audioConsulta, Campainha.preparar()].filter(Boolean);
    for (const el of els) {
      if (typeof el.setSinkId === 'function') {
        try { await el.setSinkId(this.volume.saida); } catch { /* dispositivo sumiu: fica o padrão */ }
      }
    }
  },

  /** Microfone de entrada: vale para a próxima chamada. */
  escolherEntrada(id) {
    this.volume.entrada = id || '';
    this._guardarVolume();
  },

  /** Os dispositivos de áudio, para os seletores da tela. */
  async dispositivos() {
    try {
      const todos = await navigator.mediaDevices.enumerateDevices();
      return {
        entradas: todos.filter(d => d.kind === 'audioinput'),
        saidas: todos.filter(d => d.kind === 'audiooutput'),
        escolheSaida: typeof HTMLMediaElement !== 'undefined' && 'setSinkId' in HTMLMediaElement.prototype
      };
    } catch { return { entradas: [], saidas: [], escolheSaida: false }; }
  },

  /** Nível do que está saindo pelo microfone agora, 0 a 1. */
  nivel() {
    const m = [...this._micros].pop();
    if (!m?.medidor) return 0;
    const dados = new Uint8Array(m.medidor.fftSize);
    m.medidor.getByteTimeDomainData(dados);
    let pico = 0;
    for (const v of dados) pico = Math.max(pico, Math.abs(v - 128));
    return Math.min(1, pico / 128);
  },

  /** Quem quiser saber de mudança se inscreve aqui. */
  _ouvintes: [],
  ao(fn) { this._ouvintes.push(fn); },
  _avisar(evento, dados = {}) { this._ouvintes.forEach(fn => fn(evento, dados)); },

  /**
   * Sobe o ramal do navegador.
   * @param {{ws:string, ramal:string, senha:string, dominio:string, nome:string}} cfg
   */
  iniciar(cfg) {
    if (typeof JsSIP === 'undefined') {
      this.estado = 'erro';
      this.motivo = 'A biblioteca SIP não carregou.';
      this._avisar('estado');
      return;
    }
    // Sem endereço ou domínio o JsSIP estoura na construção do UA, e o
    // erro sai no console sem dizer o que falta.
    if (!cfg?.ws || !cfg?.dominio || !cfg?.ramal) {
      this.estado = 'erro';
      this.motivo = 'Falta o endereço do softphone na configuração do servidor.';
      this._avisar('estado');
      return;
    }
    if (this.ua) this.encerrar();

    this.cfg = cfg;
    this.estado = 'registrando';
    this.motivo = '';
    this._avisar('estado');

    this._prepararAudio();

    JsSIP.debug.disable();

    let socket;
    try {
      socket = new JsSIP.WebSocketInterface(cfg.ws);
    } catch (e) {
      this.estado = 'erro';
      this.motivo = `Endereço do softphone inválido: ${cfg.ws}`;
      this._avisar('estado');
      return;
    }

    // Sem servidor de ICE o navegador anuncia só o endereço da rede
    // local dele. Dentro da empresa a chamada fecha; de fora, o áudio
    // some de um lado só. A lista vem do servidor para o TURN poder ser
    // trocado sem mexer no código.
    this.pcConfig = {
      iceServers: Array.isArray(cfg.ice) && cfg.ice.length
        ? cfg.ice
        : [{ urls: 'stun:stun.l.google.com:19302' }]
    };

    this.ua = new JsSIP.UA({
      sockets: [socket],
      uri: `sip:${cfg.ramal}@${cfg.dominio}`,
      password: cfg.senha,
      display_name: cfg.nome || cfg.ramal,
      register: true,
      register_expires: 300,
      // O Asterisk cuida dos temporizadores de sessão; ligar dos dois
      // lados rende re-INVITE em duplicidade.
      session_timers: false,
      user_agent: 'Telium PABX'
    });

    this.ua.on('registered', () => {
      this.estado = 'pronto';
      this.motivo = '';
      this._avisar('estado');
    });
    this.ua.on('unregistered', () => {
      this.estado = 'desligado';
      this._avisar('estado');
    });
    this.ua.on('registrationFailed', e => {
      this.estado = 'erro';
      this.motivo = this.explicar(e?.cause);
      this._avisar('estado');
    });
    this.ua.on('disconnected', () => {
      if (this.estado !== 'erro') {
        this.estado = 'erro';
        this.motivo = 'Sem conexão com a central. Tentando reconectar…';
        this._avisar('estado');
      }
    });
    // O JsSIP reconecta sozinho quando a rede volta. Sem este aviso o
    // softphone ficava mostrando "sem conexão" com o WebSocket de pé,
    // até o registro renovar sozinho lá na frente.
    this.ua.on('connected', () => {
      if (this.estado === 'erro') {
        this.estado = 'registrando';
        this.motivo = '';
        this._avisar('estado');
      }
    });

    this.ua.on('newRTCSession', dados => this._novaSessao(dados));

    // O JsSIP encadeia hold, unhold e re-INVITE numa fila de promessas
    // interna, fora do alcance de quem chama. Quando a central derruba a
    // chamada no meio — o caso normal de uma transferência aceita —,
    // essa fila recusa com InvalidStateError e o navegador registra um
    // "Uncaught (in promise) Invalid status: 8" que não é defeito nenhum
    // e assusta quem abre o console para diagnosticar outra coisa. Só
    // esta classe é silenciada; qualquer outra recusa continua
    // aparecendo, que é como se descobre erro de verdade.
    if (!this._silencio) {
      this._silencio = e => {
        if (e?.reason?.name === 'INVALID_STATE_ERROR') e.preventDefault();
      };
      window.addEventListener('unhandledrejection', this._silencio);
    }

    // Fechar a aba sem avisar deixa o contato registrado no Asterisk até
    // o prazo vencer — cinco minutos em que a chamada toca num navegador
    // que não existe mais.
    if (!this._despedida) {
      this._despedida = () => { try { this.ua?.stop(); } catch { /* já parada */ } };
      window.addEventListener('pagehide', this._despedida);
    }

    try {
      this.ua.start();
    } catch (e) {
      this.estado = 'erro';
      this.motivo = 'Não foi possível iniciar o softphone.';
      this._avisar('estado');
    }
  },

  /**
   * Os dois elementos por onde sai o áudio remoto.
   *
   * Sem um <audio> no documento o navegador negocia a mídia e não toca
   * nada. São dois porque numa transferência com consulta há duas
   * chamadas vivas ao mesmo tempo, e uma sobrescreveria a outra.
   */
  _prepararAudio() {
    const criar = id => {
      let el = document.getElementById(id);
      if (el) return el;
      el = document.createElement('audio');
      el.id = id;
      el.autoplay = true;
      el.hidden = true;
      document.body.appendChild(el);
      return el;
    };
    this.audio = criar('spAudio');
    this.audioConsulta = criar('spAudioConsulta');
    Campainha.preparar();
    this.ajustarAlto(this.volume.alto);
    if (this.volume.saida) this.escolherSaida(this.volume.saida);
  },

  /** Chegou uma sessão: pode ser a principal, a consulta, ou uma a recusar. */
  _novaSessao({ session, originator }) {
    // A segunda perna que NÓS pedimos, para transferir com consulta.
    if (this._pedindoConsulta) {
      this._pedindoConsulta = false;
      this.consulta = session;
      this._ligarEventos(session, 'consulta');
      return;
    }

    // Uma chamada por vez: a segunda é recusada com ocupado.
    if (this.ocupado()) {
      if (originator === 'remote') session.terminate({ status_code: 486 });
      return;
    }

    this.sessao = session;
    this._ligarEventos(session, 'principal');

    if (originator === 'remote') {
      Campainha.tocar();
      this._avisar('entrante', {
        numero: session.remote_identity.uri.user,
        nome: session.remote_identity.display_name || ''
      });
    }
  },

  /**
   * Liga os eventos de uma sessão. Chamada UMA vez por sessão.
   *
   * Registrar de novo depois do ua.call() — que é o que se faz por
   * instinto, já que ele devolve a sessão — fazia cada evento chegar em
   * dobro: a chamada era anunciada como atendida quatro vezes e
   * encerrada duas, e cada aviso na tela aparecia repetido.
   */
  _ligarEventos(session, papel) {
    const consulta = papel === 'consulta';
    const avisar = (evento, dados = {}) => consulta
      ? this._avisar('consulta', { estado: evento, ...dados })
      : this._avisar(evento, dados);

    let atendida = false;

    session.on('progress', () => {
      // "progress" também dispara na chamada que ESTÁ chegando, e ali
      // ele trocava o "chamada recebida" da tela por "chamando".
      if (session.direction === 'outgoing') avisar('chamando');
    });

    // "accepted" e "confirmed" são dois marcos do mesmo atendimento.
    const atender = () => {
      if (atendida) return;
      atendida = true;
      Campainha.parar();
      avisar('atendida');
    };
    session.on('accepted', atender);
    session.on('confirmed', atender);

    const encerrou = motivo => {
      clearInterval(session._teliumRelogio);
      // Uma última foto antes de a conexão fechar; se já fechou, vale a
      // penúltima, tirada há no máximo três segundos.
      this._fotografar(session.connection)
        .then(foto => { if (foto) session._teliumFoto = foto; })
        .finally(() => this._relatar(session, papel));
      this._soltar(session);
      avisar('encerrada', motivo ? { motivo } : {});
    };

    session.on('failed', e => encerrou(this.explicar(e?.cause)));
    session.on('ended', () => encerrou(null));

    // Teto para a coleta de candidatos: ver PRAZO_ICE.
    let prazo = null;
    session.on('icecandidate', ({ ready }) => {
      if (prazo) return;
      prazo = setTimeout(() => { try { ready(); } catch { /* já enviou */ } }, this.PRAZO_ICE);
    });
    session.on('sdp', () => { clearTimeout(prazo); prazo = null; });

    // A conexão de mídia só existe depois: na chamada de saída o JsSIP a
    // cria dentro do connect(), e na de ENTRADA só quando se atende.
    // Ler session.connection aqui devolvia null na chamada recebida — o
    // áudio remoto nunca era ligado ao elemento, e a chamada ficava com
    // RTP chegando e ninguém ouvindo.
    session.on('peerconnection', ({ peerconnection }) => this._ligarMidia(session, peerconnection, papel));
    if (session.connection) this._ligarMidia(session, session.connection, papel);
  },

  /** Áudio remoto e vigilância do ICE, para uma conexão de mídia. */
  _ligarMidia(session, pc, papel) {
    if (pc._teliumLigado) return;
    pc._teliumLigado = true;

    const consulta = papel === 'consulta';
    const el = consulta ? this.audioConsulta : this.audio;
    const avisar = (evento, dados) => consulta
      ? this._avisar('consulta', { estado: evento, ...dados })
      : this._avisar(evento, dados);

    // Uma foto a cada três segundos. A última vale como o resumo da
    // chamada: no fim, o navegador já pode ter fechado a conexão, e aí
    // getStats não devolve mais nada.
    session._teliumInicio = Date.now();
    clearInterval(session._teliumRelogio);
    session._teliumRelogio = setInterval(async () => {
      const foto = await this._fotografar(pc);
      if (foto) session._teliumFoto = foto;
    }, 3000);
    this._fotografar(pc).then(foto => { if (foto) session._teliumFoto = foto; });

    pc.addEventListener('track', e => {
      if (e.track.kind !== 'audio') return;
      el.srcObject = e.streams[0];
      // O autoplay pode ser recusado quando a chamada entra sem que o
      // usuário tenha tocado na página. A chamada fica de pé e muda, e
      // sem este aviso não há nada na tela que explique.
      el.play?.().catch(() => avisar('midia', {
        estado: 'bloqueado',
        motivo: 'O navegador bloqueou o áudio. Clique na página para liberar o som.'
      }));
    });

    // ICE que não fecha é a causa clássica da "chamada conectada e
    // muda". Sem isto o usuário ficava olhando o cronômetro correr
    // sem ouvir nada e sem nenhuma pista do motivo.
    pc.addEventListener('iceconnectionstatechange', () => {
      if (pc.iceConnectionState === 'failed') {
        // O próprio JsSIP derruba a sessão quando o ICE falha, então a
        // frase precisa dizer que a chamada vai cair — e não só que o
        // áudio não passou.
        avisar('midia', {
          estado: 'falhou',
          motivo: 'O áudio não conseguiu passar pela rede (ICE falhou) e a chamada foi encerrada. '
                + 'Em rede com firewall restritivo é preciso um servidor TURN alcançável.'
        });
      }
      if (pc.iceConnectionState === 'disconnected') {
        avisar('midia', { estado: 'instavel', motivo: 'Áudio instável — a rede oscilou.' });
      }
      if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
        avisar('midia', { estado: 'ok', motivo: '' });
      }
    });
  },

  /**
   * Chama um método do JsSIP numa sessão que pode já ter morrido.
   *
   * terminate(), hold() e unhold() estouram InvalidStateError quando a
   * sessão já está encerrada, e o erro chega como promessa recusada —
   * que o navegador registra como "Uncaught (in promise)" e ninguém vê
   * até abrir o console. Numa transferência é o caso normal: a central
   * derruba as duas pernas no mesmo instante em que o REFER é aceito.
   */
  _seguro(fn) {
    try {
      const r = fn();
      if (r && typeof r.catch === 'function') r.catch(() => {});
    } catch { /* sessão já encerrada do outro lado */ }
  },

  /**
   * Fotografa o estado da mídia, para o relatório do fim da chamada.
   *
   * O navegador é o único que sabe por onde o áudio passou e quantos
   * pacotes entraram e saíram. Sem isto, "conectou e não ouvi nada" só
   * se investiga pedindo ao cliente que rode captura de rede — coisa
   * que não acontece. Aqui o resumo é tirado durante a chamada e
   * enviado quando ela acaba.
   *
   * Só números de transporte: nada de áudio, nada de conteúdo.
   */
  async _fotografar(pc) {
    if (!pc || pc.connectionState === 'closed') return null;

    let rel;
    try { rel = await pc.getStats(); } catch { return null; }

    const f = { entrada: 0, saida: 0, perdidos: 0, jitter: null, rtt: null,
                codec: null, local: null, remoto: null, ice: pc.iceConnectionState };
    const cands = {};
    const codecs = {};

    rel.forEach(x => {
      if (x.type === 'local-candidate' || x.type === 'remote-candidate') cands[x.id] = x.candidateType;
      if (x.type === 'codec') codecs[x.id] = x.mimeType;
    });
    rel.forEach(x => {
      if (x.type === 'inbound-rtp' && x.kind === 'audio') {
        f.entrada += x.packetsReceived || 0;
        f.perdidos += Math.max(0, x.packetsLost || 0);
        if (x.jitter != null) f.jitter = Math.round(x.jitter * 1000);
        if (codecs[x.codecId]) f.codec = String(codecs[x.codecId]).replace(/^audio\//, '');
      }
      if (x.type === 'outbound-rtp' && x.kind === 'audio') f.saida += x.packetsSent || 0;
      // Só o par que venceu: é por ele que o áudio realmente passou.
      if (x.type === 'candidate-pair' && x.state === 'succeeded' && x.nominated) {
        f.local = cands[x.localCandidateId] || null;
        f.remoto = cands[x.remoteCandidateId] || null;
        if (x.currentRoundTripTime != null) f.rtt = Math.round(x.currentRoundTripTime * 1000);
      }
    });

    return f;
  },

  /** Manda o resumo da chamada que acabou. Falha aqui não incomoda ninguém. */
  _relatar(session, papel) {
    if (papel === 'consulta') return;              // a perna de consulta não vira registro
    const f = session._teliumFoto;
    if (!f || typeof Api === 'undefined') return;

    const inicio = session._teliumInicio || Date.now();
    Api.post('/me/webrtc/chamada', {
      direcao: session.direction === 'incoming' ? 'entrada' : 'saida',
      numero: session.remote_identity?.uri?.user || null,
      duracao: Math.round((Date.now() - inicio) / 1000),
      ...f
    }).catch(() => { /* o relatório é cortesia, nunca atrapalha a ligação */ });
  },

  /** Tira a sessão encerrada de onde ela estiver guardada. */
  _soltar(session) {
    // O amostrador de mídia morre com a sessão, sempre: um relógio
    // sobrevivente ficaria pedindo getStats de uma conexão fechada.
    clearInterval(session._teliumRelogio);
    // E o microfone também: senão a luz de "gravando" do navegador fica
    // acesa depois de desligar, com o microfone aberto para ninguém.
    session._teliumMic?.parar();
    session._teliumMic = null;

    if (this.consulta === session) { this.consulta = null; this.audioConsulta.srcObject = null; return; }
    if (this.sessao === session) {
      this.sessao = null;
      this.audio.srcObject = null;
      Campainha.parar();
    }
  },

  /** Há chamada viva agora? */
  ocupado() {
    return !!this.sessao && this.sessao.status !== 8 /* TERMINATED */;
  },

  /** Há uma segunda perna aberta para transferir? */
  consultando() {
    return !!this.consulta && this.consulta.status !== 8 /* TERMINATED */;
  },

  /** @returns {Promise<boolean>} false quando não dá para discar agora */
  async ligar(numero) {
    if (this.estado !== 'pronto' || !numero) return false;

    // Sem esta guarda, discar com uma chamada em curso trocava
    // this.sessao pela nova e deixava a anterior viva no Asterisk, sem
    // nenhum botão para encerrá-la: canal fantasma até o outro lado
    // desligar.
    if (this.ocupado()) {
      this._avisar('encerrada', { motivo: 'Já existe uma chamada em curso.' });
      return false;
    }

    // Quem desliga enquanto o navegador ainda pergunta pelo microfone
    // cancela a discagem: sem esta marca, a chamada saía assim mesmo,
    // segundos depois, com a tela já dizendo que nada estava acontecendo.
    const minha = ++this._geracao;
    let mic;
    try { mic = await this._microfone(); }
    catch (e) {
      if (minha !== this._geracao) return false;
      this._avisar('encerrada', { motivo: this.explicar(e?.name === 'NotAllowedError' ? 'User Denied Media Access' : 'WebRTC Error') });
      return false;
    }
    if (minha !== this._geracao || this.estado !== 'pronto' || this.ocupado()) {
      mic.parar();
      return false;
    }

    // A sessão é guardada no ouvinte de newRTCSession, que dispara
    // dentro desta chamada.
    try {
      const sessao = this.ua.call(`sip:${numero}@${this.cfg.dominio}`, {
        mediaStream: mic.stream,
        rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false },
        pcConfig: this.pcConfig
      });
      sessao._teliumMic = mic;
    } catch {
      mic.parar();
      return false;
    }
    return true;
  },

  async atender() {
    Campainha.parar();
    const s = this.sessao;
    // Dois cliques (o aviso do topo e o botão da tela) não abrem dois
    // microfones para a mesma chamada.
    if (!s || s._teliumAtendendo || s._teliumMic) return;
    s._teliumAtendendo = true;
    let mic;
    try { mic = await this._microfone(); }
    catch (e) {
      s._teliumAtendendo = false;
      this._avisar('midia', { estado: 'falhou', motivo: this.explicar(e?.name === 'NotAllowedError' ? 'User Denied Media Access' : 'WebRTC Error') });
      this._seguro(() => s.terminate({ status_code: 480 }));
      return;
    }
    s._teliumAtendendo = false;
    // Quem ligou desistiu enquanto o navegador pedia o microfone: a
    // sessão já morreu, e o microfone recém-aberto não pode ficar aceso.
    if (this.sessao !== s || s.status === 8 /* TERMINATED */) {
      mic.parar();
      return;
    }
    s._teliumMic = mic;
    this._seguro(() => s.answer({ mediaStream: mic.stream, pcConfig: this.pcConfig }));
  },

  desligar() {
    // Cancela uma discagem que ainda espera o microfone (ver ligar).
    this._geracao++;
    // Com uma consulta aberta, "desligar" encerra as duas pernas: deixar
    // a consulta viva daria uma chamada sem nenhum botão que a encerre.
    if (this.consultando()) this.cancelarConsulta();
    if (!this.sessao) { Campainha.parar(); return; }
    const s = this.sessao;
    // Recusar uma chamada que ainda não foi atendida é 486, não BYE.
    this._seguro(() => (s.direction === 'incoming' && !s.isEstablished()
      ? s.terminate({ status_code: 486 })
      : s.terminate()));
    // Soltado aqui, e não só no evento de fim: com this.sessao já nulo,
    // o _soltar do evento não acha a sessão e o áudio e o microfone
    // ficavam presos nela.
    this._soltar(s);
    if (this.audio) this.audio.srcObject = null;
    this.sessao = null;
    Campainha.parar();
  },

  mudo(ligado) {
    const s = this.consultando() ? this.consulta : this.sessao;
    if (!s) return;
    this._seguro(() => (ligado ? s.mute({ audio: true }) : s.unmute({ audio: true })));
  },

  espera(ligado) {
    const s = this.sessao;
    if (!s) return;
    this._seguro(() => (ligado ? s.hold() : s.unhold()));
  },

  dtmf(tecla) {
    (this.consultando() ? this.consulta : this.sessao)?.sendDTMF(tecla);
  },

  /** Transferência cega: a central assume e o navegador sai da chamada. */
  transferir(destino) {
    if (!this.sessao || !destino) return false;

    return this._referir(`sip:${destino}@${this.cfg.dominio}`);
  },

  /**
   * Manda o REFER e acompanha o que a central responde.
   *
   * O REFER só diz "tome conta desta chamada". Quem transferiu continua
   * na ligação até desligar — e a central avisa por NOTIFY se deu certo.
   * Sem escutar esse NOTIFY, a tela dizia "transferência concluída" com
   * o usuário ainda na chamada, e sem dizer nada quando a central
   * recusava: o destino não existia e ninguém ficava sabendo.
   */
  _referir(alvo, opcoes = {}) {
    let assinatura;
    try {
      assinatura = this.sessao.refer(alvo, opcoes);
    } catch {
      return false;
    }
    if (!assinatura) return false;

    assinatura.on('accepted', () => {
      this._avisar('transferencia', { ok: true });
      this.desligar();
    });
    assinatura.on('failed', ({ status_line: linha }) => {
      this._avisar('transferencia', {
        ok: false,
        motivo: `A central não completou a transferência${linha ? ` (${linha.status_code} ${linha.reason_phrase})` : ''}.`
      });
    });
    assinatura.on('requestFailed', ({ response }) => {
      this._avisar('transferencia', {
        ok: false,
        motivo: `A central recusou a transferência${response ? ` (${response.status_code})` : ''}.`
      });
    });

    return true;
  },

  /**
   * Transferência com consulta, primeiro passo: põe a chamada em espera
   * e liga para o destino, para falar com ele antes de passar.
   */
  async consultar(destino) {
    if (!this.sessao || !destino || this.consultando()) return false;

    const minha = ++this._geracaoConsulta;
    let mic;
    try { mic = await this._microfone(); } catch { return false; }
    // A gaveta fechou, ou a chamada principal acabou, enquanto o
    // navegador pedia o microfone: a consulta não sai.
    if (minha !== this._geracaoConsulta || !this.ocupado() || this.consultando()) {
      mic.parar();
      return false;
    }

    try {
      this._seguro(() => this.sessao.hold());
      this._pedindoConsulta = true;
      const perna = this.ua.call(`sip:${destino}@${this.cfg.dominio}`, {
        mediaStream: mic.stream,
        rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false },
        pcConfig: this.pcConfig
      });
      perna._teliumMic = mic;
    } catch {
      mic.parar();
      this._pedindoConsulta = false;
      this._seguro(() => this.sessao.unhold());
      return false;
    }
    return true;
  },

  /**
   * Segundo passo: une as duas pernas e sai.
   *
   * O REFER leva um Replaces apontando para a chamada da consulta, que é
   * o que faz a central juntar as duas em vez de abrir uma terceira.
   */
  completarTransferencia() {
    if (!this.sessao || !this.consultando()) return false;

    return this._referir(this.consulta.remote_identity.uri.toString(), { replaces: this.consulta });
  },

  /** Desiste da consulta e volta para quem estava esperando. */
  cancelarConsulta() {
    this._geracaoConsulta++;
    if (this.consulta) {
      const c = this.consulta;
      this._seguro(() => c.terminate());
      this.consulta = null;
      if (this.audioConsulta) this.audioConsulta.srcObject = null;
    }
    // Só faz sentido retomar uma chamada que ainda está de pé: depois de
    // uma transferência aceita, a central já derrubou as duas pernas.
    if (this.ocupado()) this._seguro(() => this.sessao.unhold());
  },

  encerrar() {
    Campainha.parar();
    [...this._micros].forEach(m => m.parar());
    try { this.ua?.stop(); } catch { /* já parada */ }
    this.ua = null;
    this.sessao = null;
    this.consulta = null;
    if (this.audio) this.audio.srcObject = null;
    if (this.audioConsulta) this.audioConsulta.srcObject = null;
    this.estado = 'desligado';
    this.motivo = '';
  },

  /** Traduz o motivo do JsSIP para algo que se leia na tela. */
  explicar(causa) {
    const mapa = {
      'Rejected': 'A central recusou a chamada.',
      'Busy': 'Ocupado.',
      'Unavailable': 'Destino indisponível.',
      'Not Found': 'Número não encontrado.',
      'Canceled': 'Chamada cancelada.',
      'No Answer': 'Ninguém atendeu.',
      'Authentication Error': 'Ramal ou senha SIP não conferem.',
      'Connection Error': 'Sem conexão com a central.',
      'Request Timeout': 'A central não respondeu.',
      'RTP Timeout': 'O áudio parou de passar e a chamada foi encerrada.',
      'WebRTC Error': 'O navegador não conseguiu abrir o microfone.',
      'User Denied Media Access': 'Você precisa permitir o microfone para ligar.'
    };
    return mapa[causa] || (causa ? String(causa) : '');
  }
};
