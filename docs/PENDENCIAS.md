# Telium PABX — o que ainda falta

Lista honesta do que **não** está pronto, levantada na auditoria completa
do sistema. Serve para decidir o que entra na próxima versão e, antes
disso, para não prometer ao cliente algo que a central ainda não faz.

Cada item diz o que acontece hoje, o que falta e o tamanho do estrago se
alguém contar com ele antes da hora.

---

## 1. Crítico para revenda

### 1.1 Servidor TURN próprio

**Hoje:** o softphone do navegador usa STUN (configurável em
`SOFTPHONE_STUN`) e o Asterisk faz ICE com endereço público. Isso
resolve a maioria das redes.

**Falta:** um TURN. Em rede corporativa que bloqueia UDP de saída, ou
atrás de NAT simétrico, o áudio não passa de jeito nenhum — o softphone
avisa na tela ("O áudio não conseguiu passar pela rede"), mas a chamada
fica muda.

**Estrago:** cliente com firewall restritivo não consegue usar o
softphone. É o item que mais vai gerar chamado de suporte.

**Como fechar:** subir um coturn (pode ser na própria VM), preencher
`SOFTPHONE_TURN`, `SOFTPHONE_TURN_USUARIO` e `SOFTPHONE_TURN_SENHA` no
`.env`. O código já usa os três; falta só o serviço e a receita no
Ansible.

### 1.2 Tela de verificação em dois passos

**Hoje:** o backend confere o TOTP de verdade (RFC 6238, com marca do
passo usado para o mesmo código não valer duas vezes). Quando a conta
tem `totp_ativo = 1`, o login responde `precisa_2fa`.

**Falta:** a tela. O `Auth.login()` devolve `precisa2fa: true` e
**ninguém lê esse retorno** — não há campo para digitar o código nem
tela para ativar o recurso e mostrar o QR Code.

**Estrago:** ligar `totp_ativo` direto no banco hoje **tranca o usuário
para fora**, porque não existe onde digitar o código. Não ligue antes de
a tela existir.

**Como fechar:** campo de código na tela de entrada + tela de perfil com
QR Code (`Totp::uri()` já monta o `otpauth://`) e confirmação de um
código antes de gravar `totp_ativo = 1`.

### 1.3 Usuário comum não troca a própria senha

**Hoje:** `POST /api/usuarios/{id}/senha` exige o módulo
`admin.usuarios`. Só administrador troca senha — inclusive a dos outros.

**Falta:** uma rota `/me/senha` que aceite a senha atual + a nova e valha
para qualquer perfil.

**Estrago:** todo esquecimento de senha vira chamado para o
administrador. Numa central de 50 ramais isso cansa rápido.

---

## 2. Módulos com menu e sem tela

Vinte e cinco itens do menu abrem a página "Módulo ainda não
implementado". Ela é honesta de propósito — não mostra configuração
falsa —, mas o cliente vê o menu e espera o recurso.

**Telefonia**
- `apps.disa` — DISA (discagem direta de fora)
- `apps.paging` — Megafonia e interfonia (o código `*80` funciona, falta a tela)
- `apps.tts` — Texto em voz
- `conn.did` — cadastro de DIDs separado das rotas de entrada
- `conn.webrtc` — ajustes de WebRTC pela tela
- `cfg.pinsets` — conjuntos de PIN (o dialplan já usa `pin_set_id`)
- `cfg.musica` — música em espera
- `cfg.fax` — fax
- `cfg.correiovoz` — parâmetros gerais do correio de voz
- `cfg.sip`, `cfg.avancadas` — ajustes finos de SIP
- `cfg.notificacoes` — avisos por e-mail

**Portal do usuário (PCU)** — nenhuma tela existe
- `pcu.meuramal`, `pcu.chamadas`, `pcu.correiovoz`, `pcu.sigame`, `pcu.perfil`

Isso é relevante: o perfil **Operador** tem `pcu.*` e hoje não enxerga
nada além do painel. Um usuário comum não tem o que fazer no console.

**Relatórios**
- `rel.ramais`, `rel.troncos`, `rel.cel`

**Rede e administração**
- `conn.rede`, `conn.firewall`
- `telium.painel`, `telium.licencas`, `telium.suporte`

**Módulo de call center:** a fila já tem o marcador "fila de call
center" e o vínculo de agente por origem (`fila_agentes.origem =
'callcenter'`), mas o módulo que cria o agente e o atribui à fila ainda
não existe. Sem ele, marcar a fila como call center deixa a fila sem
agente nenhum.

---

## 3. Campos que a tela grava e a central ignora

No cadastro do ramal. Salvam no banco, aparecem preenchidos e **não
mudam o comportamento**. É o tipo de coisa que o cliente configura,
testa e conclui que a central está com defeito.

| Campo | O que falta |
| --- | --- |
| Descrição do DID | só anotação, nunca sai do banco |
| CID de entrada | não muda o que o ramal vê chegar |
| Atender sozinho chamada interna | falta o cabeçalho de auto-resposta no INVITE |
| Interfonia (permitir/negar) | o código `*80` não consulta a preferência do ramal |
| Permitir rastrear a última chamada | o `*69` vale para todos, sem consultar o campo |
| Ditado (e formato, e-mail, remetente) | falta ligar o `app_dictate` |
| Prioridade da regra de gravação | o desempate hoje é fixo: "nunca" de qualquer lado proíbe, "forçar" de qualquer lado obriga |

**Decisão pendente:** ou implementar, ou tirar da tela. Deixar como está
é a pior das três.

---

## 4. Só um navegador de verdade prova

Estes foram desenhados e revisados, mas **não puderam ser testados** na
bancada — o softphone de teste é um script que fala SIP e não faz mídia.
Validação necessária no ambiente real, com dois computadores:

- aperto de mão DTLS e áudio bidirecional de verdade;
- perda de pacote, jitter e latência;
- troca de rede no meio da chamada (cabo → wi-fi, wi-fi → 4G);
- permissão de microfone negada e depois concedida;
- duas abas do console com o mesmo ramal (hoje o AOR aceita dois
  contatos: as duas devem tocar);
- transferência assistida e cega pelo softphone;
- DTMF por RFC4733 numa URA de verdade;
- hold e retorno com música em espera.

Igualmente não testados por precisarem de telefone físico ou linha de
operadora:

- transferência com aparelho de mesa (`##` e `*2`);
- chamada externa real entrando e saindo por um tronco de operadora;
- comportamento do tronco quando a operadora fica indisponível no meio
  de uma chamada.

---

## 5. Limitações conhecidas, por decisão

Não são defeitos; ficam registradas para ninguém "corrigir" por engano.

- **Estado da chamada não é espelhado no banco.** O painel e o wallboard
  perguntam ao Asterisk pelo AMI a cada carga de tela. É por isso que
  não existe chamada fantasma: não há tabela de chamada ativa para ficar
  presa. O custo é que a tela não atualiza sozinha — é preciso recarregar.
  Um WebSocket de eventos resolveria, e é trabalho de um módulo próprio.
- **Aplicar configuração é serializado.** Duas aplicações simultâneas se
  atropelavam na base do Asterisk; agora a segunda espera até 20 s e, se
  não conseguir, avisa. Numa central com muitos administradores isso
  pode aparecer como "tente de novo".
- **Siga-me, não perturbe e chamada em espera são repostos do banco a
  cada aplicação.** Quem ligar o não perturbe pelo telefone e logo depois
  alguém aplicar a configuração perde o ajuste. O console é a fonte da
  verdade, por decisão.
- **Um ramal registrado em dois lugares** funciona (o AOR aceita dois
  contatos), mas o terceiro registro derruba o mais antigo.
- **Janus é opcional e fica fora do caminho da chamada.** O WebRTC vai
  direto ao Asterisk pelo `/ws` do nginx. O papel do Janus só existe para
  quem quiser vídeo ou sala de conferência no navegador mais adiante.
- **`identify` do tronco depende de DNS.** Se o nome da operadora não
  resolver na hora do reload, o objeto não carrega e a chamada entrante
  daquele tronco deixa de ser reconhecida. O console mostra o erro; o
  Asterisk não tenta de novo sozinho.

---

## 6. Como conferir o que já está pronto

```sh
php bin/telium testar      # 42 casos: senha, 2FA, permissões, dialplan,
                           # banco, cadastro e as 184 rotas da API
```

O playbook roda essa bateria no fim do provisionamento: falha ali
interrompe a entrega. O que cada caso cobre, e o que foi conferido com
chamada de verdade, está em `docs/TESTE-1.0.1.md`.
