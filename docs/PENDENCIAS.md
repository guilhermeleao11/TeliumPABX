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

## 3-A. Achados da revisão de 29/09/2026 que ficaram para depois

A revisão geral de código corrigiu o que tinha estrago imediato. Estes
ficaram, com o motivo:

- **Bloqueio de conta por quem sabe o login.** Cinco senhas erradas
  travam a conta por 15 minutos, e o bloqueio vale antes da senha certa:
  alguém de fora pode manter o `admin` travado. Pede decidir o desenho
  (bloqueio por conta + IP, ou atraso em vez de bloqueio).
- **Senhas geradas na máquina que roda o Ansible.** Os `lookup('password')`
  do `group_vars` rodam no controlador. Instalando localmente (o caso da
  VPS) não faz diferença; pelo inventário remoto (`vm.ini`) falha e deixa
  as credenciais fora do servidor.
- **A API é copiada com o que houver em `api/`**, inclusive um `.env` de
  bancada numa máquina de desenvolvimento. No clone da VPS o `.env` não
  existe (está no `.gitignore`), então não afeta a instalação normal.
- **`conn.provisionamento` + editar equivale a ler senha SIP**: gerar o QR
  do Linphone de qualquer ramal entrega a senha dele. É o desenho do
  provisionamento, e fica registrado para quem monta perfis.
- **O agente entra em qualquer ramal livre**, inclusive o de mesa de outra
  pessoa. Restringir ao ramal habitual é uma decisão de operação.
- **O retorno conta duas vezes no volume da fila** (a perna do retorno
  entra na fila como uma chamada) e não passa pela regra de gravação.
- **Detalhes de instalação:** `listen [::]` do nginx falha em servidor com
  IPv6 desligado; `tls_autoassinado: false` quebra o playbook; a regra de
  sudo de `telium-smtp`/`telium-backup` aceita argumentos; `/etc/telium`
  pertence ao usuário da API.

## 3-B. Achados da revisão de 30/09/2026 que ficaram para depois

- **`*49` com atendimento sem tabular** toca "operação falhou". O certo
  é um áudio "tabule o atendimento antes de voltar", que não está entre
  os áudios do call center; o painel do agente já mostra o motivo.
- **O retorno registra "sem resposta da central"** quando a central cai
  no meio da conversa com o cliente, e esse retorno fica em "discando"
  por até seis horas antes de voltar à fila. Os outros retornos da mesma
  fila seguem normalmente.

- **Siga-me e correio de voz num perfil próprio** salvam pelo cadastro do
  ramal e pedem "Ramais" com editar. Abrir isso só para essas telas
  deixaria mexer na senha e no contexto do ramal; precisa de uma rota que
  aceite só os campos delas.
- **Discagem rápida dos contatos** tem código único para a central
  inteira, e o *0 resolve também contato pessoal de outro usuário.
  Separar por usuário pede mudar a chave e o func_odbc.

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
  ditado são repostos do banco a cada aplicação.** O console é a fonte da
  verdade. Os códigos do telefone (*21, *76/*78/*79, *70/*71/*77, *87/*88)
  e o portal do usuário gravam no banco na hora, então aplicar não desfaz
  o que o ramal ligou pelo telefone.
- **A permissão de discagem é a do ramal que responde pela chamada.** No
  siga-me e no desvio vale a do ramal dono do desvio; na transferência às
  cegas, a de quem transferiu. Destino que o administrador configurou (URA
  para número externo, retorno do call center) não tem ramal e sai
  liberado. As permissões vão para a base do Asterisk no "aplicar": até a
  primeira aplicação depois de atualizar, siga-me para fora fica só com
  emergência.
- **DISA não disca internacional**, qualquer que seja o contexto; local,
  celular, DDD e 0800 sim. Senha com menos de 6 dígitos desativa a DISA.
- **Escuta (*555) é só para supervisor**: o ramal de um usuário ativo com
  a permissão de supervisor do call center. Trocar o perfil ou o ramal do
  usuário vale a partir da próxima aplicação.
- **0800/0300 (classe "especial") seguem a permissão de ligação local.**
- **Um ramal com opção que o Asterisk recusa** some da central só quando é
  novo; um que já existia continua com a versão anterior até o próximo
  reinício. O "aplicar" acusa o primeiro caso ("não carregou: …"); o
  segundo aparece só no log do Asterisk.
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
