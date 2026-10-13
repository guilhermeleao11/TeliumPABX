# Códigos de Recurso

Referência completa do que se disca no telefone da central: os códigos de recurso de fábrica, os códigos que nascem de outros cadastros (pausas do call center, condições horárias, caixas postais) e as regras para trocar um código sem quebrar outro.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Como os códigos funcionam](#1-como-os-códigos-funcionam)
2. [A tela Códigos de Recurso](#2-a-tela-códigos-de-recurso)
3. [Regras de colisão](#3-regras-de-colisão)
4. [Catálogo de fábrica](#4-catálogo-de-fábrica)
5. [Códigos que nascem de outros cadastros](#5-códigos-que-nascem-de-outros-cadastros)
6. [Códigos que mudaram de versão](#6-códigos-que-mudaram-de-versão)
7. [Colinha para o usuário](#7-colinha-para-o-usuário)
8. [Problemas comuns](#8-problemas-comuns)

---

## 1. Como os códigos funcionam

Cada facilidade tem uma **chave** fixa (o que ela faz, que a central já sabe montar) e um **código discado**, que o administrador pode trocar.

Há dois lugares onde um código vale:

| Onde vale | Como se usa | Exemplo |
|---|---|---|
| **Ao discar** | Tira o fone do gancho e disca o código, como um número | `*97` ouve o correio de voz |
| **Durante a chamada** | Aperta as teclas no meio da conversa | `*2` transfere com consulta |

São dois espaços separados: o mesmo código pode existir nos dois. É o caso de `*0`: ao discar é a discagem rápida da agenda; durante a chamada, desliga.

Alguns códigos levam um **argumento** discado logo depois, sem pausa: `*21` + destino, `*45` + fila, `*86` + vaga. Exemplo: `*2111988887777` liga o siga-me para o celular 11 98888-7777.

**Toda troca de código só vale depois de Aplicar configurações** (faixa amarela no topo do console).

---

## 2. A tela Códigos de Recurso

**Administrador › Códigos de Recurso**

O que o usuário disca para usar cada facilidade, agrupado por categoria. Todo código pode ser trocado; o que ele faz é fixo.

> 📷 **Print:** tela Códigos de Recurso com a busca e os cartões de categoria.

### Lista

| Coluna | O que mostra |
|---|---|
| Descrição | Nome da facilidade e o que ela faz |
| Código | O código discado e, quando há, o que se disca depois (ex.: `*21` + destino) |
| Onde vale | **ao discar** ou **durante a chamada** |
| Ações | **Personalizar** e a chave **Habilitado/Desabilitado** |

A busca procura por nome, código ou descrição ("desvio", "gravar", "fila"). O topo mostra quantos estão habilitados e quantos valem durante a chamada.

### Personalizar um código

| Campo | O que faz |
|---|---|
| Código discado | O novo código. Só dígitos, `*` e `#`, até 12. Comece com `*` ou `#` para não colidir com número de ramal |
| Facilidade | Só leitura |
| O que se disca depois | Só leitura |
| O que ele faz | Só leitura |
| Habilitado | Liga ou desliga o código |

Desabilitar pela chave da lista tem o mesmo efeito: o código some do telefone na próxima aplicação.

> 📷 **Print:** gaveta "Personalizar" de um código.

### Código próprio

O botão **Código próprio** cadastra um código para uma facilidade escrita à mão em `extensions_custom.conf`. Pede também **Chave interna** (minúsculas, números e sublinhado), **Categoria** e **Onde vale**. A central só monta o dialplan das chaves que conhece: o de uma chave nova é você que escreve. Veja [30-Desenvolvimento.md](30-Desenvolvimento.md).

---

## 3. Regras de colisão

Os códigos **ao discar** de três cadastros dividem o mesmo espaço:

- os códigos de recurso (esta tela);
- os códigos dos motivos de pausa do call center ([16-Call-Center.md](16-Call-Center.md));
- os códigos das caixas postais sem ramal ([14-Aplicacoes-Recursos-do-Ramal.md](14-Aplicacoes-Recursos-do-Ramal.md)).

Ao salvar, a central recusa um código que:

1. seja **igual** a outro código ativo; ou
2. seja o **começo** de outro, ou tenha outro como começo. Exemplo: com `*1` existindo, `*14` é recusado, porque o telefone que disca assim que reconhece um código pararia em `*1`.

A mensagem diz com qual código bateu:
- "O código *X já é de "…"." (igual);
- "Colide com "…" (*Y): um é o começo do outro, e o telefone só alcançaria um deles." (começo).

Detalhes:

- Código com argumento ocupa tudo o que começa com ele: `*45` reserva `*450`, `*4512` etc.
- Os códigos **durante a chamada** ficam fora da conferência.
- Pausas e caixas postais aceitam só `*` ou `#` seguido de 1 a 6 dígitos.
- A conferência só roda quando o código **muda** ou quando um código **desligado é religado**. Mudar outra coisa não reconfere.

### Colisões que já vêm de fábrica

O catálogo de fábrica tem códigos que são começo de outros. Eles convivem porque o telefone manda o número inteiro depois do `#` ou do tempo de discagem:

| Código curto | Códigos mais longos que começam com ele |
|---|---|
| `*8` (captura de grupo) | `*80` interfonia, `*82` estado da conferência, `*85` estacionar, `*86` retomar vaga, `*87` aceitar interfonia, `*88` recusar interfonia |
| `*55` (alternar desvio quando não atende) | `*555` escuta de chamadas |
| `*77` (alternar chamada em espera) | `*7777` simular chamada de entrada |

Consequências práticas:

- Num telefone com plano de discagem que envia assim que reconhece `*8` ou `*55`, os códigos mais longos não chegam. Ajuste o plano de discagem do aparelho ou troque um dos códigos.
- Como a conferência roda ao religar, **desligar um desses e religar depois é recusado** enquanto o outro lado estiver ativo. Exemplo: desligou `*8`; para religá-lo, é preciso antes trocar ou desligar `*80`, `*82`, `*85`–`*88`.
- Pelo mesmo motivo, trocar `*85` para outro código começado por `*8` é recusado.

---

## 4. Catálogo de fábrica

Na ordem e com os nomes de categoria da tela. "Arg." é o que se disca logo depois do código.

### Núcleo

| Código | Arg. | Facilidade | Onde vale | O que faz |
|---|---|---|---|---|
| `*99` | | Tomar linha (tom de discar) | ao discar | A central dá tom de discar e você disca como no seu ramal |
| `*555` | ramal | Escuta de chamadas | ao discar | Ouve a chamada do ramal. Só funciona no ramal de um usuário com permissão de supervisor do call center; os outros ouvem "acesso negado" |
| `*8` | | Capturar chamada do grupo | ao discar | Atende o que toca em outro ramal do mesmo grupo de captura |
| `**` | ramal | Capturar um ramal específico | ao discar | Atende o que está tocando naquele ramal |
| `*7777` | DID | Simular chamada de entrada | ao discar | A chamada segue a rota de entrada daquele DID. Serve para testar URA e horários sem ligar de fora |
| `*0` | | Desligar durante a chamada | durante a chamada | Encerra a chamada dos dois lados |
| `*1` | | Gravar esta chamada | durante a chamada | Começa ou para a gravação |
| `*2` | | Transferência com consulta | durante a chamada | Põe o outro lado em espera, pede o destino e você fala antes de passar |
| `##` | | Transferência cega | durante a chamada | Pede o destino e passa a chamada na hora |

### Serviços de Informação

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*43` | | Teste de eco | Devolve o seu áudio, para conferir microfone, alto-falante e atraso |
| `*60` | | Hora certa | Fala a hora do servidor |
| `*65` | | Falar o número do ramal | Fala o número do ramal de onde você liga |
| `*69` | | Rastrear a última chamada | Fala quem ligou por último para o seu ramal. Pode estar bloqueado no cadastro do ramal |
| `*411` | | Diretório por nome | Procura um ramal soletrando as primeiras letras do nome |

### Correio de Voz

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*97` | | Ouvir o meu correio de voz | Entra na sua caixa, sem pedir o número dela |
| `*98` | | Ouvir outro correio de voz | Pede o número da caixa e a senha (vale também para as caixas sem ramal) |
| `*95` | ramal | Deixar recado sem tocar o ramal | Cai direto na caixa do ramal |

### Encaminhamento de Chamadas

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*72` | destino | Ativar desvio de todas as chamadas | Toda chamada vai direto para o destino |
| `*73` | | Desativar desvio de todas | Cancela |
| `*74` | | Ativar desvio de todas perguntando o destino | A central pede o destino |
| `*75` | | Alternar desvio de todas | Liga ou desliga com o último destino |
| `*90` | destino | Ativar desvio quando ocupado | Só desvia com o ramal em outra chamada |
| `*91` | | Desativar desvio quando ocupado | Cancela |
| `*92` | | Ativar desvio quando ocupado perguntando o destino | A central pede o destino |
| `*93` | | Alternar desvio quando ocupado | Liga ou desliga com o último destino |
| `*52` | destino | Ativar desvio quando não atende | Desvia quando ninguém atende ou o ramal está fora do ar |
| `*53` | | Desativar desvio quando não atende | Cancela |
| `*54` | | Ativar desvio quando não atende perguntando o destino | A central pede o destino |
| `*55` | | Alternar desvio quando não atende | Liga ou desliga com o último destino |

### Siga-me

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*21` | destino | Ativar siga-me | O ramal toca junto com o destino |
| `*22` | | Desativar siga-me | Cancela |
| `*23` | | Alternar siga-me | Liga ou desliga com o último destino |

### Não Perturbe

| Código | Facilidade | O que faz |
|---|---|---|
| `*78` | Ativar não perturbe | O ramal para de tocar; quem ligar cai no correio de voz ou ouve ocupado |
| `*79` | Desativar não perturbe | Volta a receber |
| `*76` | Alternar não perturbe | Liga ou desliga e informa como ficou |

### Chamada em Espera

| Código | Facilidade | O que faz |
|---|---|---|
| `*70` | Ativar chamada em espera | A segunda chamada avisa com um bipe em vez de dar ocupado |
| `*71` | Desativar chamada em espera | Quem ligar enquanto você fala ouve ocupado (ou cai no desvio por ocupado) |
| `*77` | Alternar chamada em espera | Liga ou desliga e informa como ficou |

### Filas

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*45` | fila | Entrar ou sair de uma fila | Se você é membro dinâmico, sai; se não é, entra |
| `*46` | fila | Pausar ou despausar numa fila | Pausa; discando de novo, volta |
| `*47` | fila | Ouvir quantas chamadas estão na fila | Fala quantas estão esperando |

`*45` e `*46` **não funcionam em fila de call center**: a central responde "operação falhou". Nelas se usam os códigos do agente.

### Call Center

| Código | Facilidade | O que faz |
|---|---|---|
| `*11` | Call center: entrar | Pede o código do agente (matrícula, ou matrícula `*` PIN) e o põe nas filas dele, neste ramal |
| `*12` | Call center: sair | Tira o agente logado neste ramal de todas as filas |
| `*13` | Call center: voltar da pausa | Encerra a pausa |

Estes três também se trocam em **Call Center › Configurações**. Os códigos de pausa estão na [seção 5](#5-códigos-que-nascem-de-outros-cadastros).

### Estacionamento

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*85` | | Estacionar a chamada | Põe a chamada numa vaga do lote padrão e fala o número da vaga |
| `*86` | vaga | Retomar chamada estacionada | Assume a chamada da vaga |

### Interfonia e Megafonia

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*80` | ramal | Chamar em interfonia | O aparelho do ramal atende sozinho em viva-voz |
| `*87` | | Aceitar interfonia neste ramal | Volta a aceitar |
| `*88` | | Recusar interfonia neste ramal | Quem tentar faz o seu ramal tocar normalmente |

### Conferências

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*82` | sala | Estado de uma conferência | Fala quantas pessoas estão na sala |

### Agenda de Contatos

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*0` | código do contato | Discagem rápida da agenda | Liga para o contato com aquele código de discagem rápida |

### Lista Negra

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*30` | número | Adicionar número à lista negra | Chamadas dele passam a ser recusadas na entrada |
| `*32` | | Adicionar o último chamador à lista negra | Bloqueia quem acabou de ligar para o seu ramal |
| `*31` | número | Remover número da lista negra | Desbloqueia |

### Allowlist

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*38` | número | Adicionar número à allowlist | Passa a ser sempre atendido, mesmo que a lista negra o alcance |
| `*39` | | Adicionar o último chamador à allowlist | Libera quem acabou de ligar |
| `*36` | número | Remover número da allowlist | Volta a ser avaliado pela lista negra |
| `*37` | | Pausar ou retomar a allowlist | Desliga a checagem de exceções na central inteira; discando de novo, religa |

### Chamadas Perdidas

| Código | Facilidade | O que faz |
|---|---|---|
| `*58` | Ativar aviso de chamada perdida | Avisa por e-mail quando alguém liga e você não atende |
| `*59` | Desativar aviso de chamada perdida | Para os avisos |
| `*57` | Alternar aviso de chamada perdida | Liga ou desliga e informa como ficou |

### Despertador

| Código | Facilidade | O que faz |
|---|---|---|
| `*68` | Marcar despertador | Pede a hora em 4 dígitos (ex.: `0730`) e liga para o ramal nesse horário, uma vez |
| `*67` | Cancelar despertador | Apaga o despertador marcado pelo telefone |

### Condições Horárias

| Código | Arg. | Facilidade | O que faz |
|---|---|---|---|
| `*27` | número da condição | Condição horária (prefixo) | Prefixo do código de cada condição. Veja a [seção 5](#5-códigos-que-nascem-de-outros-cadastros) |

### Ditado

| Código | Facilidade | O que faz |
|---|---|---|
| `*34` | Gravar um ditado | Grava um áudio no serviço de ditado |
| `*35` | Ditado enviado por e-mail | Grava e deixa na sua caixa postal, que manda por e-mail se o envio estiver ligado |

Os dois só funcionam em ramal com **ditado** liberado no cadastro; nos outros, a central diz que não pode completar.

### Fax

| Código | Facilidade | O que faz |
|---|---|---|
| `*666` | Receber fax neste ramal | Atende em modo fax e guarda o documento recebido |

---

## 5. Códigos que nascem de outros cadastros

Estes não estão na tela Códigos de Recurso, mas dividem o mesmo espaço e seguem as mesmas [regras de colisão](#3-regras-de-colisão).

### Pausas do call center

Cada motivo de pausa tem o seu código, em **Call Center › Motivos de Pausa**. De fábrica:

| Código | Pausa |
|---|---|
| `*14` | Almoço |
| `*15` | Banheiro |
| `*16` | Café |
| `*17` | Treinamento |
| `*18` | Reunião |
| `*19` | Feedback |

Motivo sem código só se usa pelo console. Detalhes em [16-Call-Center.md](16-Call-Center.md).

### Condições horárias: `*27` + número

Cada condição horária tem o seu código: o prefixo `*27` mais o número da condição (`*271`, `*272`…). O código de cada uma aparece em **Aplicações › Condições Horárias**.

- Discar força a condição. O que acontece depende do campo **o que o código faz** da condição: **fechar** (padrão) manda para o destino de fora do horário mesmo dentro dele; **abrir**, o contrário; **inverter** faz o oposto do que o relógio diz agora.
- Discar de novo volta a seguir o horário.
- Se a condição tiver **PIN do código** (4 a 10 dígitos), o telefone pede o PIN antes.
- Uma tecla BLF fica acesa enquanto a condição está forçada.
- Desligar o prefixo `*27` em Códigos de Recurso desliga o código de todas as condições.

Uso típico: fechar a entrada num feriado ou numa reunião geral sem abrir o console. Veja [13-Aplicacoes-Filas-URA-e-Atendimento.md](13-Aplicacoes-Filas-URA-e-Atendimento.md).

### Caixas postais sem ramal

Cada caixa em **Aplicações › Caixas Postais** pode ter um **Código para ouvir** (ex.: `*981`). Discado de qualquer ramal, abre a caixa, pedindo ou não a senha conforme o cadastro. Sem código, ouve-se pelo `*98`. Veja [14-Aplicacoes-Recursos-do-Ramal.md](14-Aplicacoes-Recursos-do-Ramal.md#4-caixas-postais-sem-ramal).

### Números (sem `*`) que também se discam

Não são códigos de recurso, mas o usuário costuma perguntar:

| Número | O que é |
|---|---|
| `70` | Estacionar no lote padrão (transfira a chamada para ele) |
| `71` a `80` | Vagas do lote padrão (disque para retomar) |
| Número do grupo de megafonia | Abre o viva-voz do grupo |

Os do estacionamento são os de fábrica; podem ter sido trocados em **Aplicações › Estacionamento**.

---

## 6. Códigos que mudaram de versão

Quem atualiza uma central antiga encontra estas mudanças. Os scripts de atualização só trocam o código se ele ainda estiver no valor antigo: código personalizado fica como está.

| Antes | Agora | O quê |
|---|---|---|
| `*70` | `*85` | Estacionar a chamada (`*70` passou a ser ativar chamada em espera) |
| `*40` | `*11` | Call center: entrar |
| `*44` | `*12` | Call center: sair |
| `*49` | `*13` | Call center: voltar da pausa |
| código único de pausa + número do motivo | um código por motivo (`*14` a `*19`) | Pausar no call center. O código genérico foi removido |

Pausas antigas numeradas de 1 a 6 viraram `*14` a `*19`; outras numeradas viraram `*10` + dois dígitos (ex.: `*1007`). A que bateria num código já usado ficou sem código e precisa de um novo.

Avise os usuários e atualize etiquetas e teclas programadas nos aparelhos.

---

## 7. Colinha para o usuário

Para imprimir e deixar ao lado do telefone. Confira antes se algum código foi trocado na sua central.

**Ao discar**

| Para… | Disque |
|---|---|
| Ouvir meus recados | `*97` |
| Ouvir outra caixa | `*98` |
| Deixar recado direto para um ramal | `*95` + ramal |
| Desviar tudo para outro número | `*72` + número |
| Cancelar o desvio | `*73` |
| Ligar o siga-me | `*21` + número |
| Desligar o siga-me | `*22` |
| Não perturbe: ligar / desligar | `*78` / `*79` |
| Atender o telefone do colega (mesmo grupo) | `*8` |
| Atender um ramal específico | `**` + ramal |
| Retomar chamada estacionada | número da vaga, ou `*86` + vaga |
| Falar no viva-voz de um ramal | `*80` + ramal |
| Despertador | `*68` + hora em 4 dígitos (`0730`) |
| Cancelar despertador | `*67` |
| Quem me ligou por último | `*69` |
| Qual é o meu ramal | `*65` |
| Hora certa | `*60` |
| Testar o áudio | `*43` |
| Discagem rápida da agenda | `*0` + código do contato |

**Durante a chamada**

| Para… | Aperte |
|---|---|
| Transferir falando antes | `*2` + ramal |
| Transferir direto | `##` + ramal |
| Estacionar | transfira para `70` ou `*85` |
| Gravar / parar de gravar | `*1` |

**Agente de call center**

| Para… | Disque |
|---|---|
| Entrar | `*11` + matrícula `#` (com PIN: matrícula `*` PIN `#`) |
| Pausar | `*14` almoço · `*15` banheiro · `*16` café · `*17` treinamento · `*18` reunião · `*19` feedback |
| Voltar da pausa | `*13` |
| Sair | `*12` |

> 📷 **Print:** colinha impressa ao lado de um telefone (opcional).

---

## 8. Problemas comuns

**Troquei o código e o telefone diz que o número não existe.**
- Falta **Aplicar configurações**.
- O código pode estar desabilitado.

**"Colide com…" ao salvar.**
- O novo código é igual ou é começo de outro (ou o contrário). Escolha um que não comece com nenhum código existente. Lembre que pausas e caixas postais também contam.

**Desliguei um código e agora não consigo religar.**
- É uma das [colisões de fábrica](#colisões-que-já-vêm-de-fábrica). Troque ou desligue o código do outro lado antes de religar.

**`*80`, `*85` ou `*555` cai em outra coisa.**
- O telefone envia assim que reconhece `*8` ou `*55`. Ajuste o plano de discagem do aparelho para esperar o `#` ou o tempo, ou troque o código curto.

**O código durante a chamada não faz nada.**
- Confira se o aparelho manda os tons por RFC 2833/4733 (DTMF fora do áudio), conforme o cadastro do ramal.

**`*555` responde "acesso negado".**
- Só o ramal vinculado a um usuário com permissão de supervisor do call center pode escutar. Vincule em **Administrador › Gerenciador de Usuários** e aplique.

**`*34`/`*35` diz que não pode completar.**
- O ramal não tem ditado liberado no cadastro.

**`*45`/`*46` diz "operação falhou".**
- A fila é de call center. Use `*11`, os códigos de pausa e `*13`.

**`*69` diz que não pode completar.**
- O rastreio está desligado no cadastro do ramal.
