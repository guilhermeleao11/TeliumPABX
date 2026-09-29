# Telium PABX — o que ainda falta

Lista honesta do que **não** está pronto. Serve para decidir o que entra
na próxima versão e, antes disso, para não prometer ao cliente algo que
a central ainda não faz.

Atualizada em 29/09/2026. O que foi resolvido está no fim, para dar
para conferir o que mudou desde a última leitura.

---

## 1. Decisões de produto, não de engenharia

Estes três saíram do menu de propósito. Não estão pela metade: estão
esperando uma escolha que não é técnica. Menu que não leva a lugar
nenhum, num sistema revendido, é pior do que menu menor.

| Item | A escolha que falta |
| --- | --- |
| **Texto em Voz** | Motor offline (qualidade ruim em português) ou serviço pago com chave e custo por uso. |
| **Licenciamento** | Como o produto é licenciado — por ramal, por instalação, por prazo. Nada disso está definido. |
| **Painel Telium** | O que essa tela mostraria além do que o Painel de Indicadores já mostra. |

Para reativar qualquer um, basta devolver a linha em `assets/js/data.js`
e construir a tela.

---

## 2. Tela que promete o que a central ainda não faz

Estes têm cadastro completo, tela bonita e **nenhum efeito**. São o
mesmo tipo de defeito que a auditoria vinha limpando; ficaram por
último porque cada um é um módulo, não um ajuste.

Eram quatro. Tarifação, Provisionamento e Call Center saíram daqui —
estão na seção 5.

### 2.1 Integrações e API — o webhook não dispara

O cadastro guarda webhooks e chaves. Nenhum código chama nenhuma URL
quando uma chamada começa ou termina.

Falta decidir o formato do evento e quem o dispara — o candidato natural
é um ouvinte de AMI. O do call center (telium-cc) já existe e escuta os
eventos de fila; o webhook pode nascer dele.

---

## 3. Só um navegador e um telefone de verdade provam

Foram desenhados, revisados e, onde possível, conferidos por trás — mas
**não puderam ser testados de ponta a ponta** na bancada. Validação
necessária no ambiente real:

**Com dois computadores e microfone**
- aperto de mão DTLS e áudio nos dois sentidos;
- perda de pacote, jitter e latência;
- troca de rede no meio da chamada (cabo → wi-fi, wi-fi → 4G);
- permissão de microfone negada e depois concedida;
- duas abas do console com o mesmo ramal — as duas devem tocar;
- transferência assistida e cega pelo softphone;
- hold e retorno com música em espera.

**Com telefone físico**
- transferência com aparelho de mesa (`##` e `*2`);
- DTMF por RFC4733 numa URA de verdade;
- atendimento automático da megafonia (o cabeçalho já foi conferido no
  INVITE; falta ver o aparelho abrir o viva-voz).

**Com linha de operadora**
- ~~chamada externa saindo por um tronco~~ — **feito em 16/09/2026**:
  atendida, RTP em alaw nos dois sentidos, encerramento normal. Faltou o
  mesmo caminho pelo navegador, que negocia opus;
- chamada externa entrando por um tronco;
- comportamento quando a operadora cai no meio de uma chamada;
- fax sobre T.38 de verdade.

**Com servidor de e-mail do cliente**
- o envio foi provado com um servidor de captura na bancada; falta
  confirmar com Gmail ou Microsoft 365, onde a senha de aplicativo e o
  domínio do remetente costumam ser o que barra.

---

## 4. Limitações conhecidas, por decisão

Não são defeitos; ficam registradas para ninguém "corrigir" por engano.

- **Estado da chamada não é espelhado no banco.** O painel pergunta ao
  Asterisk pelo AMI a cada carga de tela. É por isso que não existe
  chamada fantasma: não há tabela de chamada ativa para ficar presa. O
  custo é que a tela não atualiza sozinha. Um WebSocket de eventos
  resolveria, e é um módulo próprio.
- **Aplicar configuração é serializado.** Duas aplicações simultâneas se
  atropelavam na base do Asterisk; agora a segunda espera até 20 s.
- **Siga-me, não perturbe, chamada em espera, interfonia, rastreio e
  ditado são repostos do banco a cada aplicação.** Quem ligar o não
  perturbe pelo telefone e logo depois alguém aplicar a configuração
  perde o ajuste. O console é a fonte da verdade, por decisão. A
  exceção é o portal do usuário, que escreve nos dois lugares na hora.
- **Um ramal registrado em dois lugares** funciona; o terceiro registro
  derruba o mais antigo.
- **Janus é opcional e fica fora do caminho da chamada.** O WebRTC vai
  direto ao Asterisk pelo `/ws` do nginx.
- **`identify` do tronco depende de DNS.** Se o nome da operadora não
  resolver na hora do reload, o objeto não carrega e a chamada entrante
  daquele tronco deixa de ser reconhecida. O console mostra o erro; o
  Asterisk não tenta de novo sozinho.
- **Fax sobre VoIP é frágil por natureza.** Funciona com T.38 e G.711;
  com compressão agressiva falha de forma intermitente. A tela do
  módulo diz isso.

---

## 5. O que foi resolvido

Para quem leu a versão anterior desta lista.

**Era crítico antes de vender, e não é mais**
- Servidor TURN próprio, com credencial que vence — conferido com um
  coturn de verdade entregando candidato `relay` a um Chrome de verdade.
- Telas da verificação em dois passos. Antes, ligar `totp_ativo`
  trancava o usuário para fora: o backend conferia o código e não havia
  onde digitá-lo.
- Troca da própria senha, para qualquer perfil.

**Tarifação** — deixou de ser tela vazia. A rota de saída classifica a
chamada e o dialplan carrega a classe até o CDR; um temporizador de hora
em hora casa o número com o padrão da tarifa e grava `cdr.custo`, com as
frações que a operadora cobra (30 s de mínimo e depois de 6 em 6, no
padrão brasileiro). Chamada já tarifada não é recalculada: o preço
congela no que valia quando ela aconteceu.

**Call Center** — o módulo que a fila "call center" prometia e não
existia. O agente é uma pessoa (conta do console, matrícula e PIN) que
entra no ramal em que está, pelo painel ou por *40 no telefone, com os
áudios em português; pausa com motivo, tabulação obrigatória, monitor
ao vivo do supervisor (pausar, tirar, escutar, sussurrar, intervir),
retorno pedido na espera, ampliação de habilidade por tempo de espera e
relatórios tirados do queue_log (SLA de verdade, TME, TMA, abandono,
tempo logado e em pausa por motivo, CSV). O serviço telium-cc escuta o
AMI e empurra o estado ao navegador. Falta provar com agentes e
telefones de verdade (caderno, bloco P).

**Provisionamento** — o aparelho tem de onde baixar. Grandstream (XML),
Yealink e Fanvil (chave = valor), servidos num endereço com segredo no
caminho, travado à rede local por padrão e com registro de quem pediu o
quê — inclusive de quem pediu e não devia.
O Linphone do Android entra por QR Code: uma URL de uso único, válida
por dez minutos, que devolve o XML de configuração do próprio Linphone.
Falta provar num celular de verdade (caderno, I3).

**O gerenciador de usuários não deixa mais o console sem dono** —
excluir ou desativar a última conta que administra, movê-la para um
perfil sem acesso ou tirar "Usuários" da matriz desse perfil eram quatro
caminhos silenciosos para um console em que ninguém entra. E o
administrador passou a resolver pela tela os dois chamados que só o
banco resolvia: celular perdido com 2FA ligado e conta travada por senha
errada.

**A central não é mais entregue muda** — os áudios eram pedidos ao
menuselect com "falha aqui não interrompe": sem internet na compilação,
a pasta ficava vazia e `Playback` de arquivo inexistente não dá erro. A
instalação agora confere o disco e baixa o que faltar.

**Portal do Usuário** — as seis telas existem. Era a maior lacuna: o
perfil Operador via o menu e nada por trás.

**Recursos que existiam no menu e não no dialplan** — Megafonia,
Interfonia, DISA, Conjuntos de PIN, Música em Espera, e os relatórios
por ramal, por tronco e de eventos da chamada.

**Coisas que estavam quebradas de verdade**
- Rota de saída com PIN chamava um arquivo que nada escrevia: a rota
  derrubava toda chamada em vez de controlá-la.
- Fila apontava para uma classe de música que não existia: o cliente
  esperava em silêncio.
- Nenhum servidor de e-mail era instalado: toda cópia de recado falhava
  calada.
- Marcar WebRTC num ramal ligava as opções no arquivo gerado e deixava
  o cadastro mostrando tudo desligado.

**Campos que a tela gravava e a central ignorava** — DID do ramal,
descrição, CID de entrada, atendimento automático, interfonia, rastreio
e ditado. O campo de prioridade de gravação saiu do formulário, porque
prometia um desempate que o sistema não faz.

---

## 6. Como conferir o que está pronto

```sh
php bin/telium testar      # 272 casos, com ou sem banco
infra/dev/subir.sh         # central inteira em contêineres, para testar de verdade
```

O playbook roda a bateria no fim do provisionamento: falha ali
interrompe a entrega. O que já foi conferido com chamada de verdade
está em `docs/TESTE-1.0.1.md`.
