# Caderno de teste — aceitação antes de produção

Documento para **preencher**. Cada caso tem o que fazer, o que tem de
acontecer e onde olhar a prova. Você marca, anota o que viu e assina no
fim. O que não foi marcado não foi testado — e o que não foi testado não
entra em produção.

Não é o mesmo que [TESTE-FUNCIONAL.md](TESTE-FUNCIONAL.md), que é um
passeio pelas telas para encontrar defeito. Este aqui decide **se a
central sobe ou não sobe**.

## Como usar

- **🔴 bloqueador** — falhou, não vai para produção. Sem discussão.
- **🟡 importante** — falhou, vai para produção com a limitação escrita
  e combinada com o cliente.
- **⚪ desejável** — falhou, vira chamado depois.

Marque `[x]` no que passou. Em tudo que falhar, escreva **o que você
esperava** e **o que aconteceu** — não "não funcionou". A seção final
diz o que mandar junto.

Faça na ordem. Os blocos de baixo dependem dos de cima: testar fila
antes de o ramal registrar é perder a tarde.

---

## 0. Identificação

| Campo | Preencher |
|---|---|
| Cliente | |
| Endereço do console | `https://` |
| Versão instalada (`git log --oneline -1` em `/opt/TeliumPABX`) | |
| Data de início / término | |
| Quem testou | |
| Quantos ramais em produção | |
| Operadora / tronco | |
| Central que está sendo substituída | |

**Combinado antes de começar:**

- [ ] Existe janela de manutenção definida, com hora de início e de fim
- [ ] Existe caminho de volta escrito: como a empresa volta a receber
      chamada na central antiga se algo der errado
- [ ] O cliente sabe quais funções **não** entram nesta entrega
      (seção 17)

---

## 1. Ambiente de teste

Monte o cenário antes de qualquer caso. Sem ele, cada teste começa com
vinte cadastros à mão.

```sh
cd /opt/TeliumPABX && git pull
cd infra/ansible
sudo ansible-playbook site.yml -e compilar_asterisk=false \
                               -e compilar_janus=false \
                               -e cenario_teste=true
```

O cenário cria ramais 1001/1002 (mesa), 1003 (navegador), 1004
(restrito), grupo 2000, fila 3000, conferência 4000, uma URA, o grupo de
horário Comercial e a rota de entrada do DID de teste. Senha SIP de
todos: `T3st3_R4mal_@2024`.

As rotas de saída do cenário **só nascem se já houver tronco
cadastrado**. Cadastre o tronco primeiro e rode o cenário de novo.

> **Teste com o cenário, mas não entregue com ele.** Antes de produção,
> remova os ramais e a rota de teste — ou refaça a instalação com
> `-e limpar_banco=true`. Ramal 1004 esquecido num cliente é um ramal
> com senha pública.

---

## 2. Bloco A — Fundação

O que derruba tudo o mais. Nada abaixo vale se algum destes falhar.

### A1 🔴 A bateria passa inteira

```sh
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

Tem de terminar em `✓ N testes, nenhuma falha`.

- [ ] Passou — número de testes: ______
- [ ] Falhou — cole a lista de falhas no verso

> Esta bateria roda sozinha no fim de todo provisionamento e **aborta a
> entrega** quando falha. Se você chegou até aqui, ela já passou uma
> vez; rodar de novo é conferir que nada mudou depois.

### A2 🔴 O diagnóstico não acusa nada

```sh
sudo -u teliumpbx php /opt/telium/api/bin/telium doctor
```

Confere banco, AMI e permissão de escrita nos diretórios.

- [ ] Todas as linhas em verde

### A3 🔴 Os serviços estão de pé e voltam sozinhos

```sh
systemctl is-active asterisk nginx php8.4-fpm mariadb
systemctl is-enabled asterisk nginx php8.4-fpm mariadb
```

- [ ] Os quatro `active` **e** `enabled` (sem `enabled`, não voltam depois de um reboot)

### A4 🔴 Os quatro temporizadores estão ligados

```sh
systemctl list-timers 'telium-*'
```

| Unidade | Frequência | Para quê |
|---|---|---|
| `telium-certificados.timer` | 1 min | aplica certificado enviado pelo console |
| `telium-backup.timer` | 1 min | dispara as rotinas de backup na hora marcada |
| `telium-despertador.timer` | 1 min | toca os despertadores marcados |
| `telium-tarifar.timer` | 1 hora | calcula o custo das chamadas novas |

- [ ] Os quatro aparecem com `NEXT` preenchido

### A5 🔴 O Asterisk recarrega sem erro

```sh
sudo asterisk -rx "core reload"
sudo tail -50 /var/log/asterisk/full | grep -i error
```

- [ ] Nenhum `ERROR` na recarga

### A6 🔴 Os transportes estão escutando

```sh
sudo asterisk -rx "pjsip show transports"
```

- [ ] `transport-udp` (5060), `transport-tcp`, `transport-tls` (5061), `transport-wss`

### A7 🔴 A central tem voz

Esta é a que mais passa despercebida: `Playback` de arquivo que não
existe **não é erro** para o Asterisk. Ele escreve uma linha no log e
segue — a URA fica muda e ninguém vê falha nenhuma.

```sh
ls /var/lib/asterisk/sounds/digits | wc -l     # espere ~90 ou mais
ls /var/lib/asterisk/sounds/vm-intro.*         # o correio de voz precisa deste
ls /var/lib/asterisk/moh/ | head               # música em espera
```

E ouvindo de verdade, de um ramal registrado:

- Disque `*43` (teste de eco): tem de falar antes de ecoar
- Disque `*60` (hora certa): tem de dizer a hora
- Disque `*65`: tem de falar o número do seu ramal, dígito a dígito

- [ ] Os três falaram

### A8 🟡 O idioma é o combinado

```sh
sudo asterisk -rx "core show settings" | grep -i language
#   Default language:  en   ← é esta linha
```

Se o cliente contratou a central **em português**, os áudios têm de
estar em `/var/lib/asterisk/sounds/pt_BR/` e `defaultlanguage` tem de
dizer `pt_BR`. O projeto Asterisk não publica áudio em português — o
pacote entra por `sons_url` ou `sons_dir`, e está explicado em
[../infra/README.md](../infra/README.md).

- [ ] Idioma conferido: ____________  ☐ combinado com o cliente

> Trocar `pabx_idioma` sem trazer o pacote **não muda nada** além de
> esconder o problema: a central continua respondendo em inglês, agora
> com a configuração dizendo o contrário.

### A9 🔴 A configuração gerada é legível pelo Asterisk

```sh
ls -l /etc/asterisk/telium/ | head
```

Tem de estar `0640`, grupo `asterisk`. Errar isso é silencioso: o
console diz "aplicado", o Asterisk recarrega e **segue com a
configuração anterior**.

- [ ] Modo e grupo corretos em todos os arquivos

### A10 🔴 Aplicar configuração funciona de ponta a ponta

Pelo console, em **Configurações → Arquivos e Aplicação**: altere o nome
de um ramal, confirme que o aviso de *configuração pendente* acende,
clique em **Aplicar** e veja todas as etapas em `ok`.

- [ ] Todas as etapas `ok`
- [ ] A alteração apareceu em `sudo asterisk -rx "pjsip show endpoints"`

### A11 🔴 O console abre por HTTPS com certificado válido

- [ ] Abre sem aviso do navegador
- [ ] `http://` redireciona para `https://`
- [ ] Vencimento do certificado: ____/____/______

---

## 3. Bloco B — Registro e chamada interna

### B1 🔴 Dois ramais registram

Registre um softphone (ou aparelho) em 1001 e outro em 1002.

```sh
sudo asterisk -rx "pjsip show contacts"
```

- [ ] Os dois aparecem como `Avail`

### B2 🔴 Ramal chama ramal, com áudio nos dois sentidos

1001 → 1002. Fale dos dois lados.

- [ ] Toca  - [ ] Atende  - [ ] Ouço  - [ ] Sou ouvido
- [ ] Desliga dos dois lados e o canal some de `core show channels`

### B3 🔴 A chamada interna aparece no CDR

**Relatórios → CDR**, logo depois de desligar.

- [ ] Aparece com origem, destino, duração e sentido `interna`

### B4 🟡 Aparelho de mesa registra igual

Se o cliente usa telefone físico (Grandstream, Yealink, Fanvil,
Intelbras), registre **um de cada modelo** que ele tem.

- [ ] Modelos testados: ________________________________

### B5 🟡 Dois aparelhos no mesmo ramal

Registre o mesmo ramal em dois aparelhos. Ao ligar para ele, os dois têm
de tocar, e quem atender primeiro fica com a chamada.

- [ ] Os dois tocam  - [ ] O primeiro a atender fica

### B6 🟡 Transferência

Com aparelho de mesa ou softphone:

- [ ] Transferência cega (`##`) chega ao destino
- [ ] Transferência com consulta (`*2`) — dá para falar antes de passar
- [ ] Hold toca música em espera para quem ficou esperando

### B7 🟡 DTMF chega

Disque `*43` e digite números durante o eco, ou entre numa URA e aperte
teclas. Confirme no log:

```sh
sudo asterisk -rx "pjsip set logger on"
```

- [ ] As teclas são reconhecidas (RFC4733)

---

## 4. Bloco C — Tronco, entrada e saída

**É o bloco que decide a virada.** Nada substitui uma chamada de
verdade pela operadora do cliente.

### C1 🔴 O tronco registra na operadora

**Conectividade → Troncos**, coluna **Na central**: tem de dizer
`no ar`. Qualquer outra coisa, ela explica o quê.

```sh
sudo asterisk -rx "pjsip show registrations"
```

- [ ] `Registered`

### C2 🔴 Chamada externa SAINDO, atendida, com áudio

De 1001, ligue para um celular de verdade. Atenda. Fale dos dois lados.

- [ ] Completa  - [ ] Áudio nos dois sentidos  - [ ] Encerra normal
- [ ] O número que apareceu no visor do celular foi: ______________
      (tem de ser o CID combinado com o cliente, não o do ramal)

### C3 🔴 Chamada externa ENTRANDO cai no destino certo

De um celular, ligue para o número principal da empresa.

- [ ] Toca no destino configurado
- [ ] Atende com áudio nos dois sentidos
- [ ] O número de quem ligou aparece no visor do ramal

### C4 🔴 O CDR registra as duas, com o tronco

**Relatórios → CDR**.

- [ ] A de saída aparece com sentido `saida` e o nome do tronco
- [ ] A de entrada aparece com sentido `entrada` e o DID pelo qual entrou

### C5 🔴 Chamada que NÃO completa diz por quê

Ligue para um número inexistente ou inválido pela operadora.

- [ ] **Relatórios → CDR** mostra o motivo que a operadora deu
      (`SIP 404`, `SIP 403`, `SIP 486`…)
- [ ] Quem discou ouviu um aviso, e não silêncio

### C6 🔴 O ramal restrito é barrado

De **1004**, disque um celular. Tem de ser recusado **antes** de sair
pela operadora.

- [ ] Barrado  - [ ] O CDR registra a tentativa

### C7 🟡 Chamada sem número e número em E.164

Boa parte dos gateways FXO e dos E1 entrega a chamada **sem número**, e
vários SIP entregam com `+55` na frente. As duas formas têm de cair em
algum lugar, nunca morrer caladas.

- [ ] Se a operadora entrega com `+`, a chamada entra igual
- [ ] Chamada para um DID sem rota cadastrada toca um aviso, não cai

### C8 🟡 Número oculto

Peça para alguém ligar com o número oculto (`#31#` antes do número, na
maioria das operadoras).

- [ ] A rota com *barrar oculto* ligado recusa com ocupado, sem atender
      (sem atender = sem ser cobrada)

### C9 🟡 O que acontece quando a operadora cai

Com uma chamada externa em curso, derrube a internet do link do tronco.

- [ ] A chamada encerra e o canal some — não fica canal preso
- [ ] O tronco volta a `no ar` sozinho quando a internet volta

---

## 5. Bloco D — Rotas de entrada e destinos

A rota de entrada pega o número que veio do tronco e aponta para o
destino. Teste **cada tipo de destino** que o cliente vai usar.

Para cada um: ligue de fora para o DID configurado e confirme que chega.

- [ ] 🔴 **Ramal** — toca no ramal
- [ ] 🔴 **URA** — atende e toca o menu
- [ ] 🔴 **Fila** — entra na fila, com música e posição
- [ ] 🟡 **Grupo de toque** — todos os ramais do grupo tocam
- [ ] 🟡 **Condição horária** — dentro do horário vai para um destino, fora vai para outro
- [ ] 🟡 **Anúncio** — toca a mensagem e segue para o destino seguinte
- [ ] 🟡 **Correio de voz** — atende e pede o recado
- [ ] 🟡 **Conferência** — entra na sala, pedindo PIN se houver
- [ ] ⚪ **DISA** — pede senha e devolve tom de discar
- [ ] ⚪ **Desligar / ocupado** — encerra do jeito escolhido
- [ ] ⚪ **Número externo** — transborda para um celular

### D1 🔴 A condição horária vira de verdade

Não confie em ler a tela. Mude a faixa do grupo Comercial para **excluir
o horário atual**, aplique, e ligue.

- [ ] A chamada passou a cair no destino de fora do expediente
- [ ] Desfeita a alteração, voltou ao normal

> O campo de dia da semana usa `&`, não vírgula. Errar isso empurra os
> campos e a condição deixa de valer em qualquer dia — sem aviso nenhum.
> A bateria cobre; este caso é a confirmação com chamada.

### D2 🟡 Lista negra e allowlist

- [ ] Ponha um celular na lista negra e ligue dele: é barrado
- [ ] Ponha o mesmo na allowlist: volta a passar

---

## 6. Bloco E — Aplicações

### E1 🔴 Fila de atendimento

Fila 3000, com 1001 e 1002 como agentes.

- [ ] Entra na fila com música em espera
- [ ] O rodízio alterna entre os dois agentes
- [ ] Agente pausado pelo console **para** de receber
- [ ] Estouro: deixando tocar além do tempo, cai no destino de falha
- [ ] Quem espera ouve a posição na fila (depende do A7)

### E2 🟡 Grupo de toque

Disque 2000.

- [ ] 1001 e 1002 tocam juntos; quem atende primeiro fica

### E3 🔴 URA

Disque o DID da URA e teste **cada tecla configurada**, mais duas que
não costumam ser testadas:

- [ ] Cada tecla leva ao destino certo
- [ ] Tecla **inválida** dá o aviso e repete o menu
- [ ] **Silêncio** até o timeout cai no destino de tempo esgotado
- [ ] Com discagem direta ligada, digitar `1001` vai ao ramal

### E4 🟡 Conferência

Dois ramais em 4000, PIN `1234`.

- [ ] Os dois entram e se ouvem
- [ ] Entrando como admin (`4321`), a tecla de trancar a sala funciona
- [ ] `*82` diz o estado da sala

### E5 🔴 Gravação de chamadas

- [ ] A chamada gravada aparece em **Administrador → Gravações**
- [ ] O arquivo **toca** no navegador
- [ ] **Os dois lados estão no áudio** (não só um)
- [ ] `*1` durante uma chamada começa a gravar sob demanda

### E6 🔴 Correio de voz

- [ ] Deixe um recado em 1001 e ele aparece na caixa
- [ ] `*97` ouve o próprio correio
- [ ] `*98` ouve o de outro ramal, pedindo senha
- [ ] O recado chega **por e-mail**, com o áudio anexado
- [ ] O remetente do e-mail é o configurado (não `pabx@<hostname>`)

### E7 🟡 Siga-me

- [ ] `*21` liga o siga-me para outro ramal e a chamada desvia
- [ ] Modo **junto**: o ramal e o destino tocam ao mesmo tempo
- [ ] Modo **depois**: toca o ramal primeiro e só então o destino
- [ ] `*22` desliga

### E8 🟡 Estacionamento

- [ ] `*85` estaciona e a central fala a vaga
- [ ] `*86` (ou a vaga discada) retoma de outro ramal
- [ ] Vaga não retomada volta a tocar no ramal que estacionou

### E9 🟡 Megafonia e interfonia

- [ ] `*80` + ramal abre o viva-voz do aparelho de destino
- [ ] A megafonia para o grupo toca em todos os aparelhos
- [ ] `*88` faz o ramal recusar interfonia; `*87` volta a aceitar

### E10 🟡 Despertador

- [ ] `*68` marca e o ramal toca na hora
- [ ] `*67` cancela

### E11 ⚪ DISA

- [ ] Pede a senha, recusa a errada e devolve tom de discar na certa
- [ ] O número discado pela DISA passa pela rota de saída

### E12 ⚪ Ditado e diretório

- [ ] `*34` grava um ditado, `*35` manda por e-mail
- [ ] `*411` acha um ramal pelo nome

---

## 7. Bloco F — Códigos de recurso

São 66 códigos. Teste os que o cliente vai usar e **risque os que ele
não vai** — treinar usuário em código que ninguém usa é desperdício.

A lista completa está em **Administrador → Códigos de Recurso**, na
tela, com a descrição de cada um. Os mais pedidos:

| Código | O que faz | Testado |
|---|---|---|
| `*8` | capturar chamada do grupo | ☐ |
| `**` + ramal | capturar um ramal específico | ☐ |
| `*97` | ouvir o meu correio de voz | ☐ |
| `*78` / `*79` | ligar / desligar não perturbe | ☐ |
| `*72` / `*73` | ligar / desligar desvio de todas | ☐ |
| `*52` / `*53` | desvio quando não atende | ☐ |
| `*90` / `*91` | desvio quando ocupado | ☐ |
| `*45` / `*46` | entrar/sair e pausar numa fila | ☐ |
| `*85` / `*86` | estacionar e retomar | ☐ |
| `*69` | rastrear a última chamada | ☐ |
| `*0` (agenda) | discagem rápida | ☐ |

- [ ] Os códigos que o cliente vai usar foram testados
- [ ] Os códigos que ele não vai usar foram **desligados** na tela

---

## 8. Bloco G — Administração e acesso

### G1 🔴 Perfis limitam de verdade

Crie um perfil com **um único módulo** marcado. Entre com ele e abra a
tela desse módulo.

- [ ] A tela funciona **inteira** — nenhuma chamada volta 403
- [ ] O menu mostra só o que o perfil tem
- [ ] Digitando a URL de outro módulo na barra, não entra

### G2 🔴 O console não pode ficar sem dono

- [ ] Tentar excluir a própria conta é **recusado**, com explicação
- [ ] Tentar desativar a própria conta é **recusado**
- [ ] Tentar trocar o próprio perfil é **recusado**
- [ ] Tentar tirar "Usuários" do único perfil que administra é **recusado**

### G3 🔴 Um ramal, uma conta

- [ ] Vincular a duas contas o mesmo ramal é **recusado**
- [ ] Vincular um ramal que não existe é **recusado**

### G4 🔴 Senha e sessão

- [ ] Senha fraca é recusada, dizendo o que falta
- [ ] Trocar a senha de alguém **derruba as sessões abertas** dela
- [ ] Cinco senhas erradas travam a conta; o administrador libera pela tela
- [ ] Sessão expira sozinha depois do tempo configurado

### G5 🟡 Verificação em dois passos

- [ ] Ligar o 2FA no próprio painel: o QR Code funciona no aplicativo
- [ ] Entrar passa a pedir o código, e o código errado é recusado
- [ ] O mesmo código **não** entra duas vezes
- [ ] O administrador consegue desligar o 2FA de quem perdeu o celular

### G6 🔴 Auditoria registra

Depois de tudo o que você fez até aqui, abra **Administrador →
Auditoria**.

- [ ] Cada alteração está lá, com quem fez, quando e de qual IP
- [ ] Um perfil não-administrador **não** enxerga esta tela

### G7 🟡 Telas de apoio

- [ ] **Contatos** — cadastro aparece na agenda e na discagem rápida
- [ ] **Destinos personalizados** — aparecem no seletor de destino
- [ ] **CLI Asterisk** — executa e devolve a saída
- [ ] **Gravações do sistema** — lista e toca

---

## 9. Bloco H — Tarifação

Tarifa é o que o cliente confere contra a fatura da operadora. Uma
conta que não bate é pior do que nenhuma.

### H1 🟡 As tarifas estão cadastradas com os valores da operadora

**Telium → Tarifação** (ou **Configurações → Tabela de Tarifas**).

- [ ] Há tarifa por classe (local, celular, DDD, DDI, 0800)
- [ ] O incremento está como a operadora cobra (no Brasil, 30 s de
      mínimo e depois de 6 em 6 é o mais comum)

> Com todas as tarifas em zero, o relatório fecha em **R$ 0,00** — e a
> tela diz que é por isso, em vez de deixar o zero passar por resposta.

### H2 🟡 O cálculo roda e bate

```sh
sudo -u teliumpbx php /opt/telium/api/bin/telium tarifar
```

Depois, faça uma chamada externa de duração conhecida (marque no
relógio) e rode de novo.

- [ ] A chamada ganhou custo
- [ ] O valor confere com a conta feita à mão: `taxa fixa + (segundos
      cobrados ÷ 60) × custo por minuto`
- [ ] Chamada **não atendida** custou zero

### H3 🟡 O custo não muda depois

Rode `tarifar` de novo.

- [ ] A chamada já tarifada **não** foi recalculada (o preço congela no
      que valia quando ela aconteceu — é como uma fatura se comporta)

### H4 ⚪ O relatório soma

- [ ] O total do mês bate com a soma das linhas

---

## 10. Bloco I — Provisionamento de telefones

### I1 🟡 O aparelho baixa a própria configuração

Cadastre o MAC em **Conectividade → Provisionamento**, aponte o ramal, e
configure no aparelho o endereço de provisionamento que a tela mostra.

- [ ] O aparelho baixa e registra **sozinho**, sem ninguém digitar senha
      no teclado do telefone
- [ ] O visor mostra o nome e o número certos
- [ ] Fabricantes testados: ☐ Grandstream ☐ Yealink ☐ Fanvil

### I2 🔴 O provisionamento não entrega senha para quem pedir

- [ ] Pedir o arquivo **sem** o segredo do endereço devolve 404
- [ ] Pedir um MAC **não cadastrado** devolve 404
- [ ] Com *só rede local* ligado, um pedido de fora da rede é recusado
- [ ] As tentativas aparecem no log de provisionamento

> Aparelho que se provisiona pela internet é a senha SIP do ramal
> viajando para quem pedir. *Só rede local* é o padrão e é o que se deve
> manter.

### I3 🟡 O Linphone no Android se configura pelo QR Code

Em **Conectividade → Provisionamento → Linphone no celular**, escolha o
ramal (400, por exemplo), deixe a senha em branco e clique em *Gerar QR
Code*. No Android, com o Linphone instalado, use *Ler QR code* no
assistente de conta. O QR leva o endereço pelo qual você abriu o
console: abra-o pelo IP público (ou pelo nome público) da central, e não
por um IP interno, se o celular estiver no 4G. Com certificado de
confiança o QR é https; com o autoassinado da instalação, é http — o
Linphone recusa o autoassinado.

- [ ] A tela mostra ramal, servidor e UDP, e **não** mostra a senha
- [ ] O servidor mostrado é o endereço da barra do navegador
- [ ] O Linphone baixa a configuração e a conta aparece: `sip:400@<servidor>`
- [ ] O ramal registra: `pjsip show contacts` lista o 400 com `transport=udp`
- [ ] Uma chamada de teste completa, com áudio nos dois sentidos
- [ ] O log de provisionamento mostra `entregue`, o ramal 400 e o IP do celular
- [ ] Fechar e abrir o Linphone **não** dá aviso de erro de configuração
      (o aplicativo esquece a URL depois do primeiro uso)

### I4 🔴 O QR não entrega a senha para quem pedir

- [ ] Ler o mesmo QR de novo devolve **410** (uso único)
- [ ] Depois de 10 minutos, um QR não lido devolve **410**
- [ ] Um token inventado em `/p/…` devolve **404**
- [ ] O conteúdo do QR é só `http(s)://<servidor>/p/<token>` — sem senha,
      sem `sip:` (confira com qualquer leitor de QR)
- [ ] Em http, só o `/p/` responde: qualquer outro caminho redireciona para https
- [ ] `grep -r <senha> /var/log/nginx/ /var/log/php*` não encontra nada
- [ ] `grep '/p/' /var/log/nginx/access.log` mostra `GET /p/…`, sem o token

---

## 11. Bloco J — Backup e restauração

**O teste mais demorado e o mais importante de todos. Um backup que não
restaura não é backup.** Não assine a aceitação sem o J2.

### J1 🔴 O backup roda e gera arquivo

- [ ] A rotina agendada gerou o arquivo na hora marcada
- [ ] O arquivo baixa pelo console e abre
- [ ] O tamanho é compatível com o que a central tem

### J2 🔴 O backup RESTAURA numa máquina limpa

Instale o Telium numa VM nova, restaure o backup e confira:

- [ ] Ramais, troncos, rotas, filas e URAs voltaram
- [ ] Usuários e perfis voltaram
- [ ] Um ramal registra na VM restaurada
- [ ] Uma chamada interna completa nela

Tempo que levou a restauração: __________ (anote — é o tempo de parada
numa emergência de verdade)

### J3 🟡 O backup sai da máquina

Backup que só existe no servidor que queimou não serve.

- [ ] Destino remoto (FTP, FTPS ou SFTP) configurado
- [ ] O botão **Testar** do console conclui
- [ ] O arquivo chegou no destino
- [ ] Alguém **fora do servidor** tem acesso a esse destino

### J4 🟡 A retenção funciona

- [ ] Backups antigos são apagados conforme o cadastro
- [ ] O disco não enche: `df -h /` com folga

---

## 12. Bloco K — Certificados e e-mail

### K1 🟡 Certificado enviado pelo console entra em uso

Envie um par real em **Administrador → Certificados**, marque os
serviços e aplique.

- [ ] O console passa a usar o certificado novo (confira no navegador)
- [ ] O SIP TLS passa a usá-lo: `sudo asterisk -rx "pjsip show transport transport-tls"`
- [ ] **Sem editar nada à mão no nginx**

### K2 🟡 A renovação não passa despercebida

- [ ] A tela avisa quantos dias faltam para vencer
- [ ] Está combinado quem renova e como

### K3 🔴 E-mail sai pelo servidor do cliente

Teste com o servidor **que o cliente vai usar**, não com um de captura.
Gmail e Microsoft 365 costumam barrar por senha de aplicativo ou por
domínio do remetente.

- [ ] O teste de envio do console chega na caixa
- [ ] O recado de correio de voz chega, com o áudio anexado
- [ ] O remetente é o do cliente e o e-mail **não** cai em spam

---

## 13. Bloco L — Relatórios

Faça este bloco **depois** dos de cima: todos dependem de haver chamada
registrada.

- [ ] 🔴 **CDR** — as chamadas que você fez estão lá, com sentido,
      tronco, duração e motivo quando falhou
- [ ] 🟡 **Desempenho de filas** — os números batem com o que você fez
- [ ] 🟡 **Desempenho de ramais** — idem
- [ ] 🟡 **Ocupação de troncos** — idem
- [ ] 🟡 **Produtividade de agentes** — idem
- [ ] ⚪ **Eventos de canal (CEL)** — registra o ciclo da chamada
- [ ] 🟡 **Exportar** — o CSV abre no Excel com acento certo
- [ ] 🟡 Um perfil **auditor** consegue exportar e **não** consegue editar

---

## 14. Bloco M — Portal do usuário (PCU)

Entre com uma conta de perfil **Operador**, vinculada ao ramal 1001.

- [ ] 🔴 Vê **só** o PCU — nenhum menu de administração
- [ ] 🔴 **Meu Ramal** mostra o ramal dele, e só o dele
- [ ] 🔴 **Minhas Chamadas** mostra as chamadas dele, e só as dele
- [ ] 🟡 **Meu Correio de Voz** lista e toca os recados dele
- [ ] 🟡 **Meu Siga-me** liga e desliga, e a chamada desvia de verdade
- [ ] 🟡 **Meus Contatos** — agenda pessoal, separada da do sistema
- [ ] 🟡 **Meu Perfil** — troca a própria senha provando a atual
- [ ] 🟡 **Click-to-call**: clicar em *Ligar* faz o ramal dele tocar e,
      ao atender, a central disca o destino
- [ ] 🔴 Trocar o número do ramal na requisição **não** dá acesso ao de
      outra pessoa (o ramal vem da sessão, nunca do navegador)

---

## 15. Bloco N — Segurança

### N1 🔴 Nada responde sem sessão

- [ ] Abrir uma URL da API sem estar logado devolve 401
- [ ] Copiar o endereço de uma gravação e abrir em aba anônima não toca

### N2 🔴 O SIP não está exposto ao mundo sem defesa

```sh
sudo ufw status verbose
sudo fail2ban-client status          # espere: asterisk, asterisk-tcp,
                                     # nginx-http-auth, telium-console, sshd
```

- [ ] O firewall está ativo
- [ ] As cinco cadeias do fail2ban estão de pé
- [ ] Registre um ramal com a senha errada várias vezes de uma máquina de
      teste: o IP tem de acabar banido
- [ ] **Conectividade → Firewall** mostra esse IP na lista de bloqueados
- [ ] E **desbane** por ali, sem SSH (é o que salva o técnico que errou a
      senha do próprio softphone)
- [ ] A faixa de RTP (10000–20000/UDP) está aberta **e** redirecionada
      se houver NAT

> A faixa de RTP é a que mais se esquece de abrir, e a falha dela não dá
> erro: a chamada conecta e ninguém ouve.

### N3 🟡 Senha de ramal não é fraca

- [ ] Nenhum ramal ficou com senha de teste ou com o número como senha
- [ ] O ramal 1004 e os demais ramais de teste foram **removidos**

### N4 🟡 O console resiste a força bruta

- [ ] Errar a senha várias vezes seguidas passa a ser recusado
- [ ] O log de auditoria registra as tentativas, com IP

---

## 16. Bloco O — Sobrevivência

O que separa uma instalação de uma central em produção.

### O1 🔴 Reboot

```sh
sudo reboot
```

- [ ] Tudo voltou sozinho: console, banco, Asterisk, temporizadores
- [ ] Os ramais voltaram a registrar sem ninguém mexer
- [ ] Uma chamada completa depois do reboot

### O2 🔴 Asterisk reiniciado sozinho

```sh
sudo systemctl restart asterisk
```

- [ ] Volta em menos de 30 s
- [ ] Os ramais voltam a registrar
- [ ] Nenhum `ERROR` no log ao subir

### O3 🟡 Banco fora do ar

```sh
sudo systemctl stop mariadb
```

- [ ] **A chamada em curso não cai** e novas chamadas continuam
      completando, com áudio
- [ ] O console mostra erro claro, não uma tela branca

```sh
sudo systemctl start mariadb
```

- [ ] Tudo volta sozinho
- [ ] `sudo asterisk -rx "odbc show all"` volta a mostrar conexão ativa

> **A chamada continua; o registro dela, não.** O roteamento vem dos
> `.conf`, que não dependem do banco — mas o CDR e o CEL vão para o
> banco por ODBC, e o que não entrou **se perde para sempre**:
> `cdr_adaptive_odbc: Unable to retrieve database handle ... CDR failed`.
> Conferido na bancada em 29/09/2026: a chamada completou e tocou o
> áudio com o MariaDB parado, e não apareceu no relatório depois que ele
> voltou.
>
> Se isso importa para o cliente (faturamento, auditoria), o banco
> precisa entrar no monitoramento dele — não é a central que avisa.

### O4 🟡 Disco e carga

```sh
df -h /  ;  free -m  ;  uptime
```

- [ ] Disco com folga para gravação e backup pelo tempo de retenção
- [ ] A rotação de log está ativa: `ls /etc/logrotate.d/asterisk`

### O5 🟡 Volume real

Com o número de ramais que o cliente tem, em horário de pico simulado:

- [ ] Chamadas simultâneas testadas: ______
- [ ] Sem falha de áudio, sem canal preso

---

## 16-A. Bloco P — Call center

Com uma fila marcada como "call center", dois agentes cadastrados em
**Call Center → Agentes** (cada um com a sua conta do console) e dois
telefones. Um supervisor com ramal vinculado à própria conta.

### P1 🔴 O agente entra, pausa e sai

- [ ] Pelo painel **Meu Atendimento**: entrar no ramal, pausar (Almoço),
      voltar e sair — o **Monitor ao Vivo** mostra cada mudança na hora
- [ ] Pelo telefone: *40 (ouve "digite o código do agente"), matrícula e
      #, ouve "login realizado"; *421 pausa (ouve "pausa ativada"); *49
      volta; *44 sai
- [ ] Com PIN no cadastro, só a matrícula é recusada ("agente inválido");
      matrícula * PIN entra
- [ ] Dois agentes no mesmo ramal: o segundo é recusado
- [ ] Reiniciar o Asterisk com agentes logados: eles continuam logados

### P2 🔴 A chamada chega a quem deve

- [ ] Agente de nível 0 livre recebe antes do nível 1
- [ ] Com "ampliar habilidade a cada 30 s", o nível 1 só toca depois de
      30 s de espera
- [ ] Agente em pausa não recebe

### P3 🟡 Tabulação

- [ ] Fila com "Exigir tabulação": ao desligar, o agente fica em
      "Pós-atendimento" e não recebe chamada
- [ ] Tabulou no painel: volta a receber sozinho
- [ ] O atendimento aparece em "Meus atendimentos de hoje" com a tabulação

### P4 🟡 Supervisor

- [ ] Pausar, tirar da pausa e tirar do atendimento um agente pelo monitor
- [ ] Escutar: o ramal do supervisor toca e ouve a conversa sem ser ouvido
- [ ] Sussurrar: só o agente ouve o supervisor
- [ ] Intervir: os dois ouvem
- [ ] Pausa além do limite do motivo aparece em vermelho

### P5 🟡 Retorno

- [ ] Fila com tecla de retorno 1: quem espera aperta 1, ouve a
      confirmação e desliga; o pedido aparece em **Retornos**
- [ ] Com agente livre, o agente toca primeiro com o número do cliente e,
      ao atender, a central liga para o cliente
- [ ] Cliente que não atende: nova tentativa em 3 minutos, até o limite

### P6 🔴 Relatório

- [ ] 10 chamadas de teste: recebidas, atendidas e abandonadas batem com
      o que foi feito (cada chamada contada uma vez)
- [ ] SLA, espera e conversa conferem com um cronômetro
- [ ] Tempo logado e em pausa por motivo conferem com o que o agente fez
- [ ] O CSV abre no Excel com acento e separador certos

> O serviço de tempo real: `systemctl status telium-cc` ativo. Parado,
> o console segue funcionando (a tela avisa "atualizando a cada 3 s"),
> mas os códigos do telefone respondem "serviço indisponível".

---

### Q 🟡 Ramais em planilha (Conectividade › Ramais)

- [ ] **Modelo CSV** baixa `modelo-ramais.csv`; aberto no Excel, acento e
      colunas aparecem certos
- [ ] O modelo, sem mudar nada, importa: a prévia mostra 2001 e 2002 a criar
- [ ] Planilha salva pelo Excel (";" e acento): a prévia lê os nomes certos
- [ ] Linha com e-mail errado, senha curta, número com letra ou ramal
      repetido aparece como **Erro** com o motivo; as outras seguem
- [ ] Na prévia nada é gravado (a lista de ramais não muda)
- [ ] **Importar** cria as linhas certas; sem `senha_sip`, a senha gerada
      aparece e **Baixar ramais e senhas** traz a lista
- [ ] Ramal que já existe fica como está; com "Atualizar os que já
      existem", só as células preenchidas mudam
- [ ] Ramal com `webrtc` = sim sai com o softphone do navegador pronto
- [ ] **Exportar** traz todos os ramais e nenhuma senha; o arquivo
      exportado volta pela importação sem mudar senha nenhuma
- [ ] Depois de importar, **Aplicar** e registrar um aparelho num ramal novo

---

### S 🟡 Código das condições horárias (Aplicações › Condições Horárias)

- [ ] O card da condição mostra "No telefone: *27N"
- [ ] Dentro do horário, discar o código: ouve "activated", e uma chamada
      para a condição vai para o destino de **fora** do horário
- [ ] Discar de novo: ouve "de-activated" e volta a seguir o horário
- [ ] Com o código numa tecla BLF do telefone, ela acende enquanto a
      condição está forçada — também quando a força vem do console
- [ ] Com PIN na condição, o código pede o PIN; PIN errado não muda nada

### R 🔴 Pesquisa de satisfação (Aplicações › Pesquisa de Satisfação)

Prepare três anúncios (saudação, "digite de 1 a 5…", agradecimento), crie a
pesquisa com eles, escolha-a na aba **Pesquisa** de uma fila e aplique.

- [ ] Cliente liga na fila, o atendente atende e **desliga**: o cliente
      ouve a saudação e a mensagem das notas, sem a chamada cair
- [ ] Digita uma nota válida: ouve o agradecimento; a resposta aparece em
      **Resultados** com o número de quem ligou, a fila e o atendente
- [ ] Escala de 0 a 10: digitar **1** e **0** registra 10; digitar só **1**
      registra 1 (espera 2 segundos pelo segundo dígito)
- [ ] Tecla fora da escala: ouve o aviso e a pergunta de novo; errando
      todas, fica "Tecla inválida"
- [ ] Não digita nada: pergunta de novo; sem resposta, fica "Não respondeu"
- [ ] Desliga no meio da pesquisa: fica "Desligou no meio"
- [ ] Com "a menor nota é a melhor", nota 10 conta como **insatisfeito** e
      a nota mínima como **satisfeito**; o índice vai de 0 (pior) a 100 (melhor)
- [ ] Número de transferência: o atendente transfere o cliente para ele no
      fim da conversa; a resposta sai com o ramal de quem transferiu
- [ ] Filtros por período, fila e atendente mudam os números; **Exportar
      respostas** baixa o CSV com as mesmas linhas
- [ ] No call center, o relatório de agentes mostra a satisfação (0 a 100)
      de cada agente

---

## 17. O que NÃO entra nesta aceitação

Escreva aqui, e **mostre ao cliente antes de virar a chave**. Prometer o
que a central não faz é o que transforma entrega em briga.

| Função | Situação |
|---|---|
| **Integrações / API (webhooks)** | A tela guarda webhooks e chaves; **nenhuma URL é chamada**. Não ofereça integração. |
| **Texto em Voz** | Fora do menu por decisão de produto. |
| **Softphone no navegador (WebRTC)** | **Desligado de fábrica.** Ligar exige TURN funcionando e um nome que o navegador resolva. Se o cliente quiser, teste à parte com o botão *Testar agora* em Conectividade → WebRTC. |
| **Fax T.38** | Implementado, **sem prova de ponta a ponta** com aparelho de fax real. |
| **Licenciamento** | Não existe. |

Outras limitações combinadas com este cliente:

- ____________________________________________________
- ____________________________________________________

---

## 18. Antes de virar a chave

Checklist da véspera. Nada aqui é teste — é o que costuma ser esquecido.

- [ ] Ramais, rotas e usuários **de teste** removidos
- [ ] Senha do `admin` trocada, e a nova guardada onde o cliente acessa
- [ ] Senha de todos os ramais trocada das de instalação
- [ ] Backup da central **antiga** feito e guardado fora dela
- [ ] Primeiro backup da central nova feito, baixado e **restaurado**
      numa VM (J2)
- [ ] Portabilidade / redirecionamento do número combinado com a operadora
- [ ] Data e hora certas: `timedatectl`
- [ ] Contato de suporte e horário de atendimento informados ao cliente
- [ ] Usuários treinados nos códigos que vão usar (bloco F)
- [ ] Caminho de volta para a central antiga testado, não só escrito

---

## 19. Termo de aceite

| | |
|---|---|
| Casos 🔴 bloqueadores: total / passaram | ______ / ______ |
| Casos 🟡 importantes: total / passaram | ______ / ______ |
| Casos ⚪ desejáveis: total / passaram | ______ / ______ |

**Falhas em aberto, com o que ficou combinado:**

| # | O que falhou | Combinado | Prazo |
|---|---|---|---|
| | | | |
| | | | |
| | | | |

Só assine com **todos os 🔴 passando**.

```
Responsável técnico: ______________________  Data: ____/____/______

Pelo cliente:        ______________________  Data: ____/____/______
```

---

## 20. Como reportar uma falha

Para cada falha, o que resolve mais rápido:

```sh
sudo asterisk -rx "pjsip set logger on"
# reproduza a falha agora
sudo asterisk -rx "pjsip set logger off"
sudo tail -300 /var/log/asterisk/full
```

Mande o trecho do log **e** o que você esperava que acontecesse. "Não
funcionou" não dá para investigar; "liguei de 1001 para 1002, tocou, e
ao atender não havia áudio do lado do 1002" dá.

Erro de tela: abra o console do navegador (**F12**), aba *Console*, e
copie o que estiver em vermelho. Se for erro ao salvar, copie também a
aba *Network* com a requisição que falhou.

Para falha de configuração aplicada:

```sh
sudo -u teliumpbx php /opt/telium/api/bin/telium doctor
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```
