/* =========================================================
   Telium PABX — ligação SIP do navegador
   O softphone fala SIP sobre WebSocket direto com o Asterisk:
   o navegador é um ramal como outro qualquer, e aparece como tal
   no "pjsip show contacts", no BLF e na captura.
   ========================================================= */
const SipLink = {
  ua: null,
  sessao: null,
  audio: null,
  estado: 'desligado',        // desligado | registrando | pronto | erro
  motivo: '',
  cfg: null,

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

    // O áudio remoto precisa de um elemento no documento: sem ele o
    // navegador negocia a mídia e não toca nada.
    if (!this.audio) {
      this.audio = document.createElement('audio');
      this.audio.id = 'spAudio';
      this.audio.autoplay = true;
      this.audio.hidden = true;
      document.body.appendChild(this.audio);
    }

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
        this.motivo = 'Sem conexão com a central.';
        this._avisar('estado');
      }
    });

    this.ua.on('newRTCSession', ({ session, originator, request }) => {
      // Uma chamada por vez: a segunda é recusada com ocupado.
      if (this.sessao && this.sessao.status !== 8 /* TERMINATED */) {
        if (originator === 'remote') session.terminate({ status_code: 486 });
        return;
      }

      this.sessao = session;
      this.ligarEventos(session);

      if (originator === 'remote') {
        this._avisar('entrante', {
          numero: session.remote_identity.uri.user,
          nome: session.remote_identity.display_name || ''
        });
      }
    });

    try {
      this.ua.start();
    } catch (e) {
      this.estado = 'erro';
      this.motivo = 'Não foi possível iniciar o softphone.';
      this._avisar('estado');
    }
  },

  ligarEventos(session) {
    session.on('progress', () => this._avisar('chamando'));
    session.on('accepted', () => this._avisar('atendida'));
    session.on('confirmed', () => this._avisar('atendida'));

    session.on('failed', e => {
      this.sessao = null;
      this._avisar('encerrada', { motivo: this.explicar(e?.cause) });
    });
    session.on('ended', () => {
      this.sessao = null;
      this._avisar('encerrada', {});
    });

    // O áudio que vem da central.
    const pc = session.connection;
    if (pc) {
      pc.addEventListener('track', e => {
        if (e.track.kind === 'audio') this.audio.srcObject = e.streams[0];
      });

      // ICE que não fecha é a causa clássica da "chamada conectada e
      // muda". Sem isto o usuário ficava olhando o cronômetro correr
      // sem ouvir nada e sem nenhuma pista do motivo.
      pc.addEventListener('iceconnectionstatechange', () => {
        if (pc.iceConnectionState === 'failed') {
          this._avisar('midia', {
            estado: 'falhou',
            motivo: 'O áudio não conseguiu passar pela rede (ICE falhou). '
                  + 'Em rede com firewall restritivo é preciso um servidor TURN.'
          });
        }
        if (pc.iceConnectionState === 'disconnected') {
          this._avisar('midia', { estado: 'instavel', motivo: 'Áudio instável — a rede oscilou.' });
        }
        if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
          this._avisar('midia', { estado: 'ok', motivo: '' });
        }
      });
    }
  },

  /** Há chamada viva agora? */
  ocupado() {
    return !!this.sessao && this.sessao.status !== 8 /* TERMINATED */;
  },

  /** @returns {boolean} false quando não dá para discar agora */
  ligar(numero) {
    if (this.estado !== 'pronto' || !numero) return false;

    // Sem esta guarda, discar com uma chamada em curso trocava
    // this.sessao pela nova e deixava a anterior viva no Asterisk, sem
    // nenhum botão para encerrá-la: canal fantasma até o outro lado
    // desligar.
    if (this.ocupado()) {
      this._avisar('encerrada', { motivo: 'Já existe uma chamada em curso.' });
      return false;
    }

    this.sessao = this.ua.call(`sip:${numero}@${this.cfg.dominio}`, {
      mediaConstraints: { audio: true, video: false },
      rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false },
      pcConfig: this.pcConfig
    });
    this.ligarEventos(this.sessao);
    return true;
  },

  atender() {
    this.sessao?.answer({
      mediaConstraints: { audio: true, video: false },
      pcConfig: this.pcConfig
    });
  },

  desligar() {
    if (!this.sessao) return;
    try {
      // Recusar uma chamada que ainda não foi atendida é 486, não BYE.
      if (this.sessao.direction === 'incoming' && !this.sessao.isEstablished()) {
        this.sessao.terminate({ status_code: 486 });
      } else {
        this.sessao.terminate();
      }
    } catch { /* já encerrada do outro lado */ }
    this.sessao = null;
  },

  mudo(ligado) {
    if (!this.sessao) return;
    ligado ? this.sessao.mute({ audio: true }) : this.sessao.unmute({ audio: true });
  },

  espera(ligado) {
    if (!this.sessao) return;
    ligado ? this.sessao.hold() : this.sessao.unhold();
  },

  dtmf(tecla) {
    this.sessao?.sendDTMF(tecla);
  },

  transferir(destino) {
    if (!this.sessao || !destino) return false;
    this.sessao.refer(`sip:${destino}@${this.cfg.dominio}`);
    return true;
  },

  encerrar() {
    try { this.ua?.stop(); } catch { /* já parada */ }
    this.ua = null;
    this.sessao = null;
    this.estado = 'desligado';
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
      'WebRTC Error': 'O navegador não conseguiu abrir o microfone.',
      'User Denied Media Access': 'Você precisa permitir o microfone para ligar.'
    };
    return mapa[causa] || (causa ? String(causa) : '');
  }
};
