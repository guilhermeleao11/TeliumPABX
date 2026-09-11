/* =========================================================
   Telium PABX — cliente da API
   Toda leitura e escrita do console passa por aqui.
   ========================================================= */
const Api = {
  base: '/api',

  /** Token da sessão. O cookie HttpOnly também vale; isto cobre file:// e testes. */
  get token() {
    try { return sessionStorage.getItem('telium.token') || localStorage.getItem('telium.token'); }
    catch { return null; }
  },
  set token(v) {
    try {
      if (v) sessionStorage.setItem('telium.token', v);
      else { sessionStorage.removeItem('telium.token'); localStorage.removeItem('telium.token'); }
    } catch { /* armazenamento indisponível: o cookie assume */ }
  },

  async requisicao(metodo, caminho, corpo = null, opcoes = {}) {
    const cabecalhos = { 'Accept': 'application/json' };
    if (corpo !== null) cabecalhos['Content-Type'] = 'application/json';
    if (this.token) cabecalhos['Authorization'] = `Bearer ${this.token}`;

    let resposta;
    try {
      resposta = await fetch(this.base + caminho, {
        method: metodo,
        headers: cabecalhos,
        credentials: 'same-origin',
        body: corpo === null ? undefined : JSON.stringify(corpo),
        signal: opcoes.signal
      });
    } catch (e) {
      throw new ErroApi('Não foi possível falar com o servidor. Verifique a conexão.', 0, e);
    }

    // 204 e afins
    const texto = await resposta.text();
    let dados = null;
    if (texto) {
      try { dados = JSON.parse(texto); }
      catch { throw new ErroApi('O servidor devolveu uma resposta inesperada.', resposta.status); }
    }

    if (!resposta.ok) {
      if (resposta.status === 401 && !caminho.startsWith('/auth/login')) {
        Api.token = null;
        location.replace('index.html?expirada=1');
        throw new ErroApi('Sessão expirada', 401);
      }
      throw new ErroApi(dados?.erro || `Erro ${resposta.status}`, resposta.status, dados);
    }

    return dados;
  },

  /** Envio de arquivo (multipart). Não define Content-Type: o navegador cuida do boundary. */
  async upload(caminho, formData) {
    const cabecalhos = { 'Accept': 'application/json' };
    if (this.token) cabecalhos['Authorization'] = `Bearer ${this.token}`;

    let resposta;
    try {
      resposta = await fetch(this.base + caminho, {
        method: 'POST', headers: cabecalhos, credentials: 'same-origin', body: formData
      });
    } catch (e) {
      throw new ErroApi('Não foi possível enviar o arquivo. Verifique a conexão.', 0, e);
    }

    const texto = await resposta.text();
    let dados = null;
    if (texto) { try { dados = JSON.parse(texto); } catch { /* resposta não-JSON */ } }

    if (!resposta.ok) {
      throw new ErroApi(dados?.erro || `Erro ${resposta.status} ao enviar`, resposta.status, dados);
    }
    return dados;
  },

  get(caminho, params = null) {
    const qs = params ? '?' + new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v != null))
    ) : '';
    return this.requisicao('GET', caminho + qs);
  },
  post(caminho, corpo)  { return this.requisicao('POST', caminho, corpo ?? {}); },
  put(caminho, corpo)   { return this.requisicao('PUT', caminho, corpo ?? {}); },
  delete(caminho)       { return this.requisicao('DELETE', caminho); }
};

class ErroApi extends Error {
  constructor(mensagem, status = 0, detalhe = null) {
    super(mensagem);
    this.name = 'ErroApi';
    this.status = status;
    this.detalhe = detalhe;
  }
  /** Erro de permissão tem tratamento próprio na interface. */
  get semPermissao() { return this.status === 403; }
  get semRede() { return this.status === 0; }
}
