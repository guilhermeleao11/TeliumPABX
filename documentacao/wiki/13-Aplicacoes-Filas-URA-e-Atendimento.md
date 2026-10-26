# Aplicações: Filas, URA e Atendimento — Telium PABX

Manual das aplicações que decidem o caminho de uma chamada dentro da central: filas, pesquisa de satisfação, grupos de toque, URA, conferências, gravações, anúncios, condições horárias, grupos de horário e feriados.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

A parte de **call center** das filas (agentes como pessoas, pausas, tabulação, retorno, monitor) está em [16-Call-Center.md](16-Call-Center.md). Aqui ela aparece só resumida.

---

## Sumário

1. [Destinos: para onde uma chamada pode ir](#1-destinos-para-onde-uma-chamada-pode-ir)
2. [Exemplo de fluxo completo de atendimento](#2-exemplo-de-fluxo-completo-de-atendimento)
3. [Filas de Atendimento](#3-filas-de-atendimento)
4. [Pesquisa de Satisfação](#4-pesquisa-de-satisfação)
5. [Grupos de Toque](#5-grupos-de-toque)
6. [URA / Atendimento Digital](#6-ura--atendimento-digital)
7. [Conferências](#7-conferências)
8. [Gravação de Chamadas](#8-gravação-de-chamadas)
9. [Anúncios](#9-anúncios)
10. [Condições Horárias](#10-condições-horárias)
11. [Grupos de Horário](#11-grupos-de-horário)
12. [Feriados](#12-feriados)
13. [Problemas comuns](#13-problemas-comuns)

---

## Antes de tudo: Aplicar configurações

Tudo o que se cria ou altera nestas telas é gravado no banco, mas **só vale no telefone depois de Aplicar configurações** (faixa amarela no topo do console). Ao aplicar, a central também confere os destinos e avisa, por exemplo, quando uma rota, URA, fila ou grupo aponta para algo que não existe ou está inativo, ou quando uma fila comum não tem nenhum agente.

As exceções, que valem na hora sem aplicar, estão indicadas em cada seção (forçar uma condição horária pelo console, agentes de fila de call center).

---

## 1. Destinos: para onde uma chamada pode ir

Quase toda aplicação termina mandando a chamada para um **destino**: a rota de entrada, cada tecla da URA, o "dentro" e o "fora" da condição horária, o fim de um anúncio, o tempo esgotado da URA e assim por diante.

Nessas telas o destino é escolhido num **seletor único**, agrupado por tipo. Só aparecem os itens ativos. Um destino já gravado que foi desativado continua aparecendo como escolhido, com a observação "(desativado ou fora do seu acesso)", para que salvar a tela não o apague sem querer.

> 📷 **Print:** seletor de destino aberto, mostrando os grupos (Ramais, Filas, URAs…).

| Grupo no seletor | O que acontece com a chamada |
|---|---|
| Ramais | Toca o ramal com as regras dele: desvio, não perturbe, siga-me, chamada em espera e, se o ramal tiver correio de voz, cai na caixa quando não atende ou está ocupado |
| Filas | Entra na fila, com anúncio de entrada, pesquisa e destinos de falha da fila |
| URAs | Atende e toca o menu da URA |
| Grupos de toque | Toca os ramais do grupo, todos juntos ou um de cada vez, conforme o grupo ([seção 5](#5-grupos-de-toque)) |
| Correio de voz | Vai direto para a caixa do ramal escolhido (mensagem de "indisponível") e desliga depois do recado |
| Caixas postais | Vai para uma caixa postal avulsa, sem ramal |
| Anúncios | Toca o anúncio e segue o que ele manda (outro destino ou desligar) |
| Condições horárias | Consulta o relógio e manda para o destino de dentro ou de fora do horário |
| Conferências | Entra na sala (pede PIN, se a sala tiver) |
| DISA | Entrega a chamada à DISA escolhida |
| Megafonia | Abre o grupo de megafonia |
| Pesquisa de satisfação | Faz a pesquisa e desliga |
| Destinos personalizados | Salta para um contexto escrito à mão (cadastrado em **Administrador › Destinos Personalizados**) |
| Fora da central › Número externo — transbordo | Liga para um número de fora. Aparece um campo para digitar o número |
| Encerrar › Desligar a chamada | Desliga em silêncio |
| Encerrar › Tom de ocupado | Toca o tom de ocupado e desliga |
| Encerrar › Tom de congestionamento | Toca o tom de "todos os circuitos ocupados" e desliga |

**Regras importantes:**

- **Número externo** sai pelas **rotas de saída**: escreva o número do jeito que um ramal discaria (com o prefixo que a rota espera). Se nenhuma rota de saída aceitar aquele formato, a chamada não sai. Veja [17-Conectividade.md](17-Conectividade.md).
- Destino em branco, quando a tela permite, é escolha: a chamada desliga.
- Destino que aponta para algo excluído ou desativado faz a chamada **cair**. O aviso aparece ao **Aplicar configurações**.
- Na **rota de entrada**, Tom de ocupado e Tom de congestionamento recusam a chamada sem atendê-la (não é tarifada).

A aba **Failover** da fila e o **Se ninguém atender** do grupo de toque usam o mesmo seletor. Antes da versão 1.1.0, o failover da fila pedia um tipo e um valor digitado (o "número" da URA era o identificador interno dela); agora a URA, o anúncio e o destino personalizado são escolhidos pelo nome.

---

## 2. Exemplo de fluxo completo de atendimento

Cenário: empresa atende de segunda a sexta, das 8h às 18h. No expediente, a URA oferece Vendas (fila) e Suporte (fila); fora dele, o cliente deixa recado.

**Montagem, na ordem:**

1. **Áudios:** envie as gravações (saudação da URA, aviso de fora do horário) em **Administrador › Gravações do Sistema**.
2. **Anúncios** (**Aplicações › Anúncios**): crie "Saudação URA" com a gravação da saudação (sem destino; ela só será usada como saudação). Crie "Fora do horário" com a gravação do aviso e destino **Caixas postais › Recados**.
3. **Filas** (**Aplicações › Filas de Atendimento**): crie 3001 Vendas e 3002 Suporte e monte os agentes de cada uma. Na aba **Failover**, mande o tempo esgotado para **Correio de voz** de um ramal, por exemplo.
4. **URA** (**Aplicações › URA / Atendimento Digital**): "URA Principal", saudação "Saudação URA", entradas `1` → fila 3001, `2` → fila 3002, `0` → ramal da recepção. Tempo esgotado → ramal da recepção.
5. **Grupo de horário** (**Aplicações › Grupos de Horário**): "Horário comercial", uma faixa Segunda a sexta, 08:00 às 18:00.
6. **Condição horária** (**Aplicações › Condições Horárias**): "Expediente", grupo "Horário comercial", Dentro → URA Principal, Fora → anúncio "Fora do horário".
7. **Rota de entrada** (**Conectividade › Rotas de Entrada**): o número da empresa com destino **Condições horárias › Expediente**.
8. **Aplicar configurações.**

**O que o cliente vive:**

```
Rota de entrada (DID da empresa)
  └─ Condição horária "Expediente"
       ├─ dentro  → URA Principal
       │             ├─ 1 → Fila 3001 Vendas ──(tempo esgotado)→ correio de voz
       │             ├─ 2 → Fila 3002 Suporte ──(pesquisa ao final, se ligada)
       │             ├─ 0 → Ramal da recepção
       │             ├─ número de um ramal → ramal direto (se permitido)
       │             └─ ninguém digita → Ramal da recepção
       └─ fora    → Anúncio "Fora do horário" → Caixa postal Recados
```

**Testar sem ligar de fora:** de um ramal, disque o código **Simular chamada de entrada** (de fábrica `*7777`) seguido do DID. A chamada segue exatamente a rota de entrada daquele número. Veja [15-Codigos-de-Recurso.md](15-Codigos-de-Recurso.md).

**Fechar num feriado sem mexer na configuração:** disque o código da condição (ex.: `*271`) ou use **Forçar fechado** no cartão da condição ([seção 10](#10-condições-horárias)).

---

## 3. Filas de Atendimento

**Aplicações › Filas de Atendimento**

A fila recebe as chamadas, toca música de espera, anuncia a posição e distribui entre os atendentes (agentes). Também mede o nível de serviço.

> 📷 **Print:** lista de filas, com as etiquetas **call center**, **grava**, **pesquisa** e **confirma**.

### A lista

| Coluna | O que mostra |
|---|---|
| Fila | O número que se disca |
| Nome | Nome e descrição, com etiquetas: **call center**, **grava**, **pesquisa** (tem pesquisa de satisfação), **confirma** (exige confirmação do agente) |
| Estratégia | Como a chamada é distribuída |
| SLA | Meta de atendimento, em segundos |
| Espera máx. | Tempo máximo de espera |
| Estado | **Ativa** ou **Parada** |

**Botões de cada linha:**

| Botão | O que faz |
|---|---|
| Situação agora (ícone de atividade) | Mostra os agentes neste instante ([veja abaixo](#situação-agora)) |
| **Agentes** | Monta quem atende a fila ([veja abaixo](#agentes-fixos-e-agentes-que-entram-por-código)) |
| Editar | Abre o cadastro |
| Excluir | Pede confirmação. Os agentes são desvinculados e as rotas que apontavam para a fila ficam sem destino |

No topo há **Nova fila** e, para quem tem acesso, o atalho **Pesquisas de satisfação**.

### Cadastro: aba Geral

| Campo | O que faz |
|---|---|
| Número da fila * | O número que se disca para cair na fila. Só dígitos, de 2 a 10. Não pode repetir outra fila |
| Nome * | Até 80 caracteres |
| Descrição | Texto livre |
| Estratégia de toque | Como a chamada é distribuída (tabela abaixo). Padrão: rodízio com memória |
| Toque por agente (s) | Quanto tempo toca em cada agente antes de passar ao próximo. Padrão 20 |
| Intervalo entre tentativas (s) | Pausa entre uma rodada de toques e a seguinte. Padrão 5 |
| Pausa pós-atendimento (s) | Tempo em que o agente fica sem receber depois de desligar. Padrão 10 |
| Meta de SLA (s) | Atender dentro deste tempo conta como dentro da meta nos relatórios. Padrão 20 |
| Gravar as chamadas | Grava as conversas da fila. Vem ligado ([seção 8](#8-gravação-de-chamadas)) |
| Fila ativa | Fila parada não existe no telefone |
| Fila de call center | Os agentes passam a ser as pessoas cadastradas em **Call Center › Agentes**. Veja [16-Call-Center.md](16-Call-Center.md) |

**Estratégias de toque:**

| Opção | Comportamento |
|---|---|
| Tocar em todos ao mesmo tempo | Toca todos os livres; quem atender primeiro leva |
| Rodízio com memória (mais justo) | Rodízio que lembra quem foi o último a receber |
| Quem está há mais tempo sem atender | Prioriza o agente ocioso há mais tempo |
| Quem atendeu menos chamadas | Equilibra pela quantidade |
| Na ordem da lista, sempre do começo | Sempre começa pelo primeiro |
| Aleatório | Sorteia |
| Aleatório com peso da penalidade | Sorteia, levando em conta a penalidade de cada agente |

> 📷 **Print:** aba **Geral** de uma fila nova.

### Aba Call center

Vale só para fila marcada como **Fila de call center**: Exigir tabulação, Tecla de retorno, Anúncio de confirmação do retorno, Tentativas de retorno, Ampliar habilidade a cada (s) e Até o nível. Explicação completa em [16-Call-Center.md](16-Call-Center.md).

### Aba Áudios

| Campo | O que faz |
|---|---|
| Música em espera | Música tocada a quem espera. "Padrão do sistema" ou um áudio da categoria espera ([19-Configuracoes.md](19-Configuracoes.md), Música em Espera) |
| Anúncio de entrada, para o cliente | Toca uma vez, assim que a chamada entra. Ex.: "Você ligou para o suporte, aguarde" |
| Sussurro, para quem vai atender | Só o agente ouve, antes de a conversa começar. É como ele sabe de que fila veio a chamada |
| Anúncio periódico, para quem espera | Repetido durante a espera |
| A cada quantos segundos | Intervalo do anúncio periódico. Padrão 60 (mínimo efetivo 10) |

Todos os áudios das filas são **anúncios** ([seção 9](#9-anúncios)): o seletor mostra só os anúncios ativos.

### Aba Espera

| Campo | O que faz |
|---|---|
| Anunciar a posição na fila | Diz ao cliente em que posição ele está. Vem ligado |
| Anunciar o tempo estimado | Diz o tempo estimado, calculado pelo Asterisk pela média das últimas chamadas |
| Repetir os anúncios a cada (s) | Intervalo dos anúncios de posição e tempo. Padrão 30 (mínimo efetivo 10) |
| Espera máxima (s) | Passou disso, a chamada sai para o destino de tempo esgotado. Padrão 300. **0 = sem limite** |
| Máximo de chamadas na fila | 0 = sem limite. Ao encher, a chamada vai para o destino de fila cheia |

### Aba Comportamento

| Campo | O que faz |
|---|---|
| Entrar quando não há agente logado | **Sim — entra e espera mesmo assim** (padrão); **Não — vai direto para o destino de fila vazia**; **Não, e conta pausado como ausente** |
| Sair se a fila ficar sem agente | Mesmas opções: se quem já espera sai quando a fila esvazia. Padrão: não |
| Peso da fila | Entre filas que dividem os mesmos agentes, a de maior peso é servida primeiro |
| Tocar em agente que já está em chamada | Deixe desligado, salvo se os agentes usam softphone com várias linhas |
| Pausar quem não atende | **Não pausar**, **Pausar nesta fila** ou **Pausar em todas as filas dele**. Evita a chamada rodar em quem saiu da mesa |
| Atraso antes de conectar (s) | Um ou dois segundos ajudam o agente a se preparar depois do sussurro |
| Exigir confirmação do agente | O agente ouve o sussurro e precisa **apertar 1** para assumir. Evita a chamada cair na caixa postal de um celular. Vale para os agentes montados na tela da fila; numa fila de call center fica desabilitado |

**Sobre a confirmação:** com ela ligada, o sussurro não toca separado. O mesmo áudio do sussurro passa a ser a pergunta do "aperte 1".

### Aba Failover

Para onde a chamada vai quando sai da fila sem ser atendida, por motivo. Os três campos usam o seletor de destino da [seção 1](#1-destinos-para-onde-uma-chamada-pode-ir): a URA, o anúncio e o destino personalizado aparecem pelo nome.

| Campo | Em branco | Quando vale |
|---|---|---|
| Tempo de espera esgotado | **Desligar** | Passou a espera máxima. **É também a saída para qualquer motivo sem destino próprio abaixo** |
| Fila sem agente | **Igual ao tempo esgotado** | Não há agente para atender (fila vazia ou esvaziou durante a espera, conforme a aba Comportamento) |
| Fila cheia | **Igual ao tempo esgotado** | A fila atingiu o máximo de chamadas |

> 📷 **Print:** aba **Failover** preenchida com tempo esgotado → correio de voz.

### Aba Pesquisa

| Campo | O que faz |
|---|---|
| Pesquisa de satisfação | Quando o atendente desliga, o cliente vai automaticamente para a pesquisa em vez de a chamada cair. Só aparecem pesquisas ativas. "— não perguntar nada —" desliga |

Veja a [seção 4](#4-pesquisa-de-satisfação).

### Agentes fixos e agentes que entram por código

Botão **Agentes** na lista de filas (fila comum, não call center).

> 📷 **Print:** gaveta **Agentes da fila** com um agente Fixo e um "Entra por código".

1. Escolha um ramal em "Escolha um ramal para adicionar…" e clique em **Adicionar**.
2. Ajuste a **Penalidade** (0 a 255): **penalidade menor atende primeiro**. Quem tem penalidade maior só recebe quando os de menor estão ocupados.
3. Escolha **Como entra**:
   - **Fixo:** o ramal fica sempre na fila, gravado na configuração.
   - **Entra por código:** o ramal não fica na fila sozinho; o próprio usuário entra e sai pelo telefone.
4. Clique em **Salvar agentes** e depois **Aplicar configurações**.

O **x** da linha tira o ramal da lista.

**Códigos de fila no telefone** (de fábrica; confira em **Administrador › Códigos de Recurso**):

| Código | O que faz |
|---|---|
| `*45` + número da fila (ex.: `*453001`) | Entra na fila; se já estava, sai. Ouve "login ok" ou "logged off". Entrar só vale para quem está na lista da fila como "Entra por código" |
| `*46` + número da fila (ex.: `*463001`) | Pausa na fila; de novo, volta a receber. Ouve "ativado" / "desativado" |
| `*47` + número da fila | Diz quantas chamadas estão esperando |

- **Entrar** pelo `*45` só vale para o ramal que está na lista de agentes da fila como **Entra por código** (e depois de **Aplicar configurações**). Outro ramal ouve "operação falhou" e não entra. Antes da versão 1.1.0 qualquer ramal entrava em qualquer fila. **Sair** pelo `*45` continua valendo para qualquer membro.
- Para ramal **Fixo**, use `*46` para pausar e voltar. O `*45` é para quem entra por código: o membro fixo é recriado a cada **Aplicar configurações**.
- Em fila de **call center**, `*45` e `*46` não funcionam: o telefone responde "operação falhou". Lá se usa o código do agente (`*11`). Veja [16-Call-Center.md](16-Call-Center.md).

**Fila de call center:** o botão **Agentes** abre outra gaveta, com os agentes cadastrados (pessoas), uma caixa para marcar quem atende nesta fila e o **Nível** (0 a 9; 0 recebe primeiro). Essa gravação vale na hora para quem está logado, sem aplicar configurações.

### Situação agora

O ícone de atividade da linha abre **Fila N agora**:

| Coluna | O que mostra |
|---|---|
| Agente | Ramal, nome e canal |
| Estado | Estado do Asterisk (ex.: "Not in use" em verde = livre; "In use", "Ringing", "Unavailable") ou **Pausado — motivo** |
| Penalidade | A penalidade (ou nível) do agente |
| Atendidas | Chamadas atendidas desde que entrou |

O subtítulo diz quantas chamadas estão esperando. Em **Saída bruta do Asterisk** fica o texto original do comando, útil para o suporte. Sem comunicação com o Asterisk, aparece "Asterisk fora do ar".

> 📷 **Print:** gaveta **Situação agora** com um agente livre e um pausado.

### O que acontece numa chamada para a fila

1. A central atende a chamada e marca a fila no registro (CDR).
2. Decide a gravação ([seção 8](#8-gravação-de-chamadas)).
3. Toca o **anúncio de entrada**, se houver.
4. A chamada espera, com música, anúncios de posição/tempo e periódico, enquanto a fila toca nos agentes pela estratégia.
5. Atendida: quando o agente desliga, a chamada termina, ou vai para a **pesquisa**, se a fila tiver uma.
6. Não atendida: sai pelo destino do motivo (tempo esgotado, sem agente, cheia).

### Exemplos práticos

- **Suporte com escala:** técnicos N1 com penalidade 0 e N2 com penalidade 5. Os N2 só recebem quando todos os N1 estão ocupados.
- **Recepção que sai para almoçar:** recepcionistas como "Entra por código"; antes do almoço discam `*453000` (sai), na volta discam de novo (entra).
- **Agentes com celular no siga-me:** ligue **Exigir confirmação do agente** para a chamada não ser "atendida" pela caixa postal do celular.

---

## 4. Pesquisa de Satisfação

**Aplicações › Pesquisa de Satisfação**

O cliente dá uma nota pelo teclado logo depois que o atendente desliga (pesquisa ligada à fila), ou quando é transferido para o número da pesquisa.

A tela tem duas abas: **Resultados** e **Pesquisas (N)**. Abre em Resultados quando já existe pesquisa.

### Aba Pesquisas

Cada pesquisa aparece num cartão com a escala (ex.: "1 a 5 · 5 é a melhor"), o número para transferir, tentativas e segundos, as quatro mensagens e as filas que a usam. Botões: **Resultados**, **Editar** e excluir.

> 📷 **Print:** cartão de uma pesquisa ligada a uma fila.

**Campos (Nova pesquisa / Editar):**

| Campo | O que faz |
|---|---|
| Nome da pesquisa * | Até 80 caracteres |
| Descrição | Só para reconhecer |
| Número para transferir | O atendente transfere o cliente para este número no fim da conversa. 2 a 10 dígitos. Em branco, a pesquisa é feita só pela fila. Não pode ser número de ramal, fila, grupo de toque, sala de conferência, grupo de megafonia, número de estacionar ou de outra pesquisa |
| Pesquisa ativa | Pesquisa parada não é feita |
| Mensagem de saudação | Anúncio tocado primeiro. Sem ela, vai direto à pergunta |
| Mensagem das notas | A pergunta, que deve dizer a escala ("Digite de 1 a 5…"). Sem ela, o cliente só ouve um bipe |
| Mensagem de agradecimento | Sem ela, toca o agradecimento padrão do Asterisk |
| Mensagem de tecla inválida | Toca quando o cliente digita fora da escala. Sem ela, toca o aviso padrão |
| Nota mínima | 0 a 9 |
| Nota máxima | 1 a 10. Tem de ser maior que a mínima |
| Qual é a melhor nota? | **A maior nota é a melhor** ou **A menor nota é a melhor** |
| Tentativas | 1 a 5: quantas vezes a pergunta se repete se o cliente não digita ou erra |
| Segundos para digitar | 3 a 30 |

O quadro **Como cada nota conta** mostra cada nota colorida: verde conta como satisfeito, vermelho como insatisfeito.

**Como a nota é lida no telefone:**
- O cliente digita **um dígito**. Para a nota **10**, digita 1 e 0: quem digita 1 tem dois segundos para completar com 0; se não completar, fica 1.
- Fora da escala: toca a mensagem de tecla inválida e pergunta de novo, até as tentativas.
- Sem digitar nada: pergunta de novo, até as tentativas.
- Cada pesquisa registra um desfecho: **Respondida**, **Não respondeu**, **Tecla inválida** ou **Desligou no meio**.
- O registro guarda a fila, o ramal que atendeu (ou o agente de call center) e o número de quem ligou.

**Índice de satisfação:** cada nota vira um índice de 0 (pior) a 100 (melhor), já no sentido da escala. Índice **70 ou mais** = satisfeito; **30 ou menos** = insatisfeito; o resto é neutro.

**Excluir:** não é permitido enquanto a pesquisa estiver em alguma fila (tire da fila ou desative). As respostas já dadas continuam no relatório.

**Como usar a pesquisa:**
- **Pela fila:** aba **Pesquisa** da fila. Ao fim de cada atendimento, o cliente cai na pesquisa.
- **Por transferência:** o atendente transfere para o **Número para transferir**.
- **Como destino:** a pesquisa aparece no seletor de destinos (URA, rota, condição).

### Aba Resultados

Filtros: **Pesquisa**, **De**, **Até** (abre nos últimos 30 dias), **Fila**, **Atendente**. Botão **Exportar respostas** (CSV), para quem tem permissão de exportar.

| Bloco | O que mostra |
|---|---|
| Índice de satisfação | De 0 a 100 |
| Satisfeitos | % satisfeitos, neutros e insatisfeitos |
| Respostas | Respondidas, de quantas pesquisas feitas, e a taxa de resposta |
| Média da nota | Na escala da pesquisa |
| Notas dadas | Distribuição por nota |
| Não completaram | Não responderam, tecla inválida, desligaram no meio |
| Por fila / Por atendente | Feitas, respostas, média, % satisfeitos e índice |
| Dia a dia | Índice de cada dia, quando o período tem mais de um dia |
| Respostas | Cada resposta, com quem ligou (com botão **Ligar de volta**), fila, atendente, situação, nota e avaliação |

Se a escala da pesquisa mudou, as respostas antigas aparecem com `*` ("Respondida na escala…").

> 📷 **Print:** aba **Resultados** com os quatro indicadores.

---

## 5. Grupos de Toque

**Aplicações › Grupos de Toque**

Um número que toca em vários ramais: todos juntos ou um de cada vez.

> 📷 **Print:** lista de grupos de toque.

Na lista, a coluna **Toque** mostra o tempo e, para o grupo que toca um de cada vez, a etiqueta **um de cada vez**.

| Campo | O que faz |
|---|---|
| Número do grupo * | O número que se disca. Só dígitos, 2 a 10 |
| Nome * | Como aparece nas listas |
| Ramais * | Os ramais, separados por hífen: `1000-1001-1002`. Só números de ramal. A ordem vale para o "um de cada vez" |
| Como toca | **Todos ao mesmo tempo — quem atender primeiro leva** (padrão) ou **Um de cada vez, na ordem da lista** |
| Tempo de toque (s) | De 5 a 300. Padrão 20. **Todos juntos:** quanto o grupo toca. **Um de cada vez:** quanto toca em cada ramal |
| Se ninguém atender | Destino quando ninguém do grupo atende ou ninguém está disponível, no seletor da [seção 1](#1-destinos-para-onde-uma-chamada-pode-ir). Em branco: **Encerrar a chamada** |
| Grupo ativo | |

**Comportamento:**
- **Todos juntos:** tocam ao mesmo tempo os ramais disponíveis; o primeiro que atende fica com a chamada.
- **Um de cada vez:** toca o primeiro da lista pelo tempo de toque, depois o segundo, e assim por diante; o primeiro que atende fica com a chamada.
- Quem está em **não perturbe** ou com **desvio de todas as chamadas** ligado fica de fora: o grupo não toca o aparelho dele. O **siga-me** pessoal não é usado (a chamada é do grupo, não da pessoa).
- Se ninguém atender (ou ninguém estiver disponível), a chamada vai para **Se ninguém atender**; em branco, é encerrada.
- Ramal excluído ou inativo que ficou na lista gera aviso ao **Aplicar configurações**.

**Quando usar grupo e quando usar fila:** grupo é para "toca todo mundo da sala" sem espera; fila é para quando o cliente pode esperar com música, há métricas e regras de distribuição.

---

## 6. URA / Atendimento Digital

**Aplicações › URA / Atendimento Digital**

A URA atende, toca a saudação e manda a chamada para onde o cliente escolher pelo teclado ("digite 1 para vendas, 2 para suporte").

Cada URA aparece num cartão: o anúncio de saudação (ou o aviso **sem anúncio de saudação**), a espera pelo dígito, as tentativas, se aceita discagem direta de ramal, a árvore das entradas (tecla → destino) e os destinos de **Tempo esgotado** e **Opção inválida**.

> 📷 **Print:** cartão de uma URA com três entradas.

### Campos

| Campo | O que faz |
|---|---|
| Nome da URA * | Só para reconhecer nas rotas e nos destinos |
| Anúncio de saudação * | O que o cliente ouve ao cair na URA. É um anúncio ([seção 9](#9-anúncios)); só o áudio dele é usado |
| Espera pelo dígito (s) | Quanto tempo a URA aguarda depois da saudação. Padrão 8 |
| Tentativas | Quantas vezes a saudação se repete antes de ir para o tempo esgotado. Padrão 3 |
| Permitir discar o ramal direto | Quem sabe o ramal digita e vai direto, sem passar pelo menu. Vem ligado |
| URA ativa | |

### Entradas da URA

Botão **Adicionar entrada**. Cada linha:

| Coluna | O que faz |
|---|---|
| Dígitos | O que o cliente aperta: um dígito, `*` ou `#` (até 4 caracteres), ou um padrão do dialplan começando com `_`, como `_2XX` (qualquer ramal da faixa 200) |
| Descrição | Para que serve. Em branco, vira "Opção N" |
| Destino | Qualquer destino do seletor ([seção 1](#1-destinos-para-onde-uma-chamada-pode-ir)) |

**Validações ao salvar:** toda entrada precisa de dígitos e de destino; a mesma tecla não pode aparecer duas vezes; dígitos fora do formato são recusados com o erro marcado na linha.

### Se ninguém escolher nada

| Campo | Quando vale |
|---|---|
| Tempo esgotado | O cliente não digitou nada depois de repetir a saudação o número de **Tentativas** |
| Opção inválida | O cliente insistiu numa tecla que não existe, tantas vezes quanto as **Tentativas**. **Em branco, vale o destino de Tempo esgotado** |

### Como a URA se comporta no telefone

1. Atende, espera 1 segundo e toca a saudação. O cliente **pode digitar durante a saudação** (não precisa esperar terminar).
2. Espera o dígito pelo tempo de **Espera pelo dígito**.
3. Sem dígito: toca a saudação de novo. Esgotadas as tentativas, vai para **Tempo esgotado**.
4. Tecla que não existe: ouve "opção inválida" e volta ao menu. Depois de insistir o número de **Tentativas**, vai para **Opção inválida**.
5. Tecla válida: segue para o destino da entrada.

**Discagem direta:** com **Permitir discar o ramal direto**, a URA aceita o número de **qualquer ramal cadastrado**, de qualquer tamanho, e manda para ele. Número que não é ramal conta como opção inválida. (Antes da versão 1.1.0, só ramais de 3 ou 4 dígitos funcionavam.)

**Repetir o menu:** uma entrada cujo destino é a própria URA volta ao começo do menu (ex.: `9` → URA Principal = "para ouvir de novo, tecle 9").

**Voltar à URA depois de um anúncio:** a URA marca de onde a chamada saiu. Um anúncio com **Retornar para a URA** ligado volta para ela depois de tocar ([seção 9](#9-anúncios)).

**DTMF:** a URA lê as teclas enviadas pelo tronco. Se o cliente aperta e nada acontece, o problema costuma ser o modo de DTMF do tronco. Veja [17-Conectividade.md](17-Conectividade.md) e [07-Solucao-de-Problemas.md](07-Solucao-de-Problemas.md).

### Exemplos práticos

- **Menu simples:** `1` → fila Vendas, `2` → fila Suporte, `3` → anúncio "Endereço e horário" com **Retornar para a URA**, `0` → recepção.
- **Submenu:** `2` → outra URA "Suporte" com `1` → fila Internet, `2` → fila Telefonia, `9` → URA Principal (voltar).
- **Faixa de ramais:** entrada `_2XX` → Ramais não serve, porque o destino é um ramal fixo; para faixa, use **Permitir discar o ramal direto**.

**Excluir:** as entradas somem junto, e as rotas que apontavam para a URA ficam sem destino.

---

## 7. Conferências

**Aplicações › Conferências**

Salas fixas em que várias pessoas conversam ao mesmo tempo. Basta discar o número da sala (de um ramal, ou mandando uma chamada externa para a sala pelo seletor de destinos).

Cada sala aparece num cartão com o número, o nome, o limite de pessoas, o anúncio de entrada e as etiquetas **PIN**, **PIN de admin**, **grava**, **espera o admin**, **entra mudo**.

> 📷 **Print:** cartões de salas de conferência.

### Aba Sala

| Campo | O que faz |
|---|---|
| Número da sala * | O que se disca para entrar. Só dígitos, 2 a 10. Não pode repetir outra sala |
| Nome da sala * | |
| Descrição | |
| Limite de usuários | 0 = sem limite. Cheia, quem disca não entra |
| Gravar a conferência | O áudio da sala inteira vira um arquivo, que aparece em **Gravação de Chamadas** |
| Sala ativa | |

### Aba Entrada

A tela e a API conferem as mesmas regras: número da sala só com dígitos (2 a 10) e PINs só com dígitos (até 10). As regras olham a sala inteira, já com o PIN mantido quando o campo fica em branco na edição.

| Campo | O que faz |
|---|---|
| PIN para entrar | Só dígitos (até 10). Sem PIN, qualquer um que disque o número entra. Ao editar, em branco mantém o atual |
| PIN de administrador | Só dígitos (até 10). Quem entra com ele administra a sala. **Tem de ser diferente do PIN para entrar**: com os dois iguais, todo mundo entraria como administrador, e a central recusa. Ao editar, em branco mantém o atual |
| Mensagem de anúncio de entrada | Anúncio tocado para quem entra, antes de cair na sala |
| Só começar quando o administrador entrar | Quem chega antes ouve música. **Quando o administrador sai, a sala encerra**. **Exige um PIN de administrador**: sem ele, ninguém seria administrador e a sala nunca começaria, e a central recusa salvar |
| Entrar com o microfone mudo | Cada um se libera com a tecla 1. Útil em sala grande |

**Como o PIN é pedido:**
- A central pede o PIN e lê exatamente o tamanho do maior PIN: não precisa terminar com `#`.
- PIN de administrador → entra como **administrador**. PIN comum → **participante**.
- Sala só com PIN de administrador: quem não digita nada entra como participante.
- PIN errado: ouve "PIN inválido" e tenta de novo. **Na quarta tentativa errada, a chamada desliga.**

### Aba Na sala

| Campo | O que faz |
|---|---|
| Anunciar quem entra e quem sai | A central pede o nome de quem entra e toca para a sala na entrada e na saída. Vem ligado |
| Dizer quantas pessoas já estão na sala | Vem ligado |
| Música em espera enquanto está sozinho | Vem ligado |

### Teclas dentro da sala

| Tecla | Participante | Administrador |
|---|---|---|
| `*` | Ouve o menu | Ouve o menu de administrador |
| `1` | Silencia / libera o microfone | Igual |
| `2` | — | Tranca / destranca a sala |
| `3` | — | Tira o último que entrou |
| `4` / `6` | Diminui / aumenta o volume que ouve | Igual |
| `7` / `9` | Diminui / aumenta o volume com que fala | Igual |
| `0` | Diz quantas pessoas estão na sala | Igual |

### Quem está na sala

O botão **Quem está na sala** mostra, ao vivo: canal, quem, papel (**administra** / **participante**) e microfone (**mudo** / **aberto**), com a saída bruta do Asterisk.

> 📷 **Print:** gaveta **Quem está na sala** com dois participantes.

**Código útil:** o código **Estado de uma conferência** (de fábrica `*82`; veja [15-Codigos-de-Recurso.md](15-Codigos-de-Recurso.md)) + número da sala diz quantas pessoas estão nela.

**Excluir:** o número deixa de atender; as gravações já feitas continuam guardadas.

---

## 8. Gravação de Chamadas

**Aplicações › Gravação de Chamadas**

Lista todas as gravações: de chamadas e de conferências. Aqui se **ouve, baixa uma ou baixa várias num pacote**. Nenhuma gravação se apaga à mão: o disco é limpo pelo **prazo de guarda**.

No topo aparece o espaço livre do disco (em vermelho acima de 85% de uso).

### Prazo de guarda

| Campo | O que faz |
|---|---|
| Guardar as gravações por (dias) | De 0 a 3650. **Zero (o padrão) guarda para sempre**, até o disco encher |
| Salvar prazo | Grava o prazo (precisa da ação **editar** no perfil) |

- Uma vez por dia, o executor de backup apaga do disco as gravações com mais dias que o prazo e as pastas que ficarem vazias. O registro da chamada continua no CDR; a lista passa a mostrar **sem arquivo**.
- Ao **encurtar** o prazo (ou sair do zero), a tela pede confirmação: as gravações mais antigas que o novo prazo são apagadas em até um minuto e **não há como recuperá-las**. Baixe antes, ou faça um backup com as gravações, se precisar delas.
- Abaixo do campo aparece o resultado da última limpeza (quando rodou e quantos arquivos apagou).
- A mudança fica na auditoria, com o prazo de antes e o novo.
- Defina o prazo com o cliente: gravação é dado pessoal (LGPD), e o disco cheio para a gravação e o correio de voz.

> 📷 **Print:** campo "Guardar as gravações por (dias)" com o resultado da última limpeza.

> 📷 **Print:** lista de gravações com o player aberto numa linha.

### Onde se liga a gravação

Esta tela não tem regras: a gravação é decidida em quatro lugares.

| Onde | Campo | Efeito |
|---|---|---|
| **Conectividade › Ramais**, aba Gravação | Externa recebida, Externa feita, Interna recebida, Interna feita | Regra do ramal para cada tipo de chamada |
| **Aplicações › Filas de Atendimento**, aba Geral | Gravar as chamadas | Grava as chamadas da fila (vem ligado) |
| **Conectividade › Rotas de Entrada**, aba Atendimento | Gravar chamadas desta rota | Grava tudo que entra pela rota |
| **Aplicações › Conferências**, aba Sala | Gravar a conferência | Grava a sala inteira |

**Opções de regra no ramal:**

| Opção | Significado |
|---|---|
| Não importa — quem decide é a fila ou a rota | Segue o outro lado |
| Sim, gravar | Grava, a não ser que alguém diga "nunca" |
| Não gravar | Não grava, a não ser que alguém force |
| Forçar — grava mesmo se outra regra disser que não | Obriga, salvo "nunca" |
| Nunca — não grava nem se outra regra mandar | Proíbe, e para uma gravação já iniciada pela rota |

**Ordem de desempate (fixa):** "nunca" de qualquer lado proíbe; depois, "forçar" de qualquer lado obriga; só então vale a preferência de cada um. Uma chamada é gravada uma vez só, mesmo passando por rota, fila e ramal.

**Gravação sob demanda** (aba Gravação do ramal): **Não permitir**, **Permitir o código \*1 durante a chamada**, ou **Permitir e deixar vencer as regras acima**. O código `*1` apertado durante a conversa começa ou para a gravação. Detalhes em [17-Conectividade.md](17-Conectividade.md).

### Onde ficam os arquivos

- Diretório no servidor: `/var/spool/asterisk/monitor`, organizado por data: `AAAA/MM/DD/`.
- Nome da gravação de chamada: `HHMMSS-sentido-origem-destino-identificador.wav`.
- Nome da gravação de conferência: `conferencia-<sala>-HHMMSS-identificador.wav`.
- Grava-se só a parte em que as duas pontas estão conectadas (não grava a espera na fila).

Veja também [06-Diretorios-Arquivos-e-Logs.md](06-Diretorios-Arquivos-e-Logs.md).

### Filtros

**De**, **Até**, sentido (**Todos os sentidos**, **Entrada**, **Saída**, **Interna**), busca por origem ou destino, **Filtrar** e **Limpar**. Filtrar por sentido esconde as conferências, que não têm sentido.

### Colunas e ações

| Coluna | O que mostra |
|---|---|
| Data | Início |
| Origem / Destino | Números |
| Sentido | Entrada, Saída, Interna ou Conferência, com a etiqueta da fila quando passou por uma |
| Duração | Tempo de conversa |
| Tamanho | Tamanho do arquivo |

| Ação | O que faz |
|---|---|
| **Ouvir** (play) | Abre um player na própria linha. Clicar de novo fecha |
| **Baixar** | Baixa o arquivo (fica na auditoria) |
| Caixas de seleção + **Baixar selecionadas** | Monta um `.zip` com até **200** gravações por vez (fica na auditoria) |

A etiqueta **sem arquivo** indica que o registro existe mas o arquivo não está mais no disco (apagado pela retenção, por exemplo). A lista mostra 50 por página.

---

## 9. Anúncios

**Aplicações › Anúncios**

O anúncio é **uma gravação mais o que fazer com ela**: deixar pular, repetir numa tecla, voltar para a URA e para onde ir depois. É o anúncio que URA, filas, conferências, pesquisas e rotas apontam; nenhum módulo usa o arquivo de áudio direto.

Antes, envie o áudio em **Administrador › Gravações do Sistema**. Sem nenhum áudio, a tela avisa e não deixa criar.

> 📷 **Print:** lista de anúncios com as colunas Comportamento e Destino após tocar.

| Coluna | O que mostra |
|---|---|
| Anúncio | Nome e descrição |
| Gravação | O áudio usado, ou **gravação removida** |
| Comportamento | **pode pular**, **repete no N**, **volta à URA**, **não atende** |
| Destino após tocar | O destino, ou **Desliga** |
| Estado | Ativo ou Parado |

### Campos

| Campo | O que faz |
|---|---|
| Nome * | É como o anúncio aparece nas listas de destino |
| Gravação * | O áudio, vindo de Gravações do Sistema |
| Repetir | **Desabilitar** ou **Tecla 0–9, \*, #**: a tecla que o cliente aperta para ouvir de novo |
| Permitir pular | Qualquer tecla interrompe o anúncio e segue para o destino |
| Retornar para a URA | Se a chamada veio de uma URA, volta para ela depois de tocar, em vez de ir ao destino |
| Não atender o canal | Toca sem atender: a operadora não tarifa, mas alguns aparelhos não reproduzem o áudio |
| Anúncio ativo | |
| Para onde a chamada vai (opcional) | Destino após a reprodução. **"— nenhum: desligar depois do anúncio —" é permitido**: o anúncio toca e a chamada desliga |

**Regras:**
- O anúncio não pode ter **ele mesmo** como destino (prenderia a chamada num laço).
- Com **Retornar para a URA**, o destino só vale quando a chamada **não** veio de uma URA. Sem destino e sem URA de origem, desliga.
- Com **Repetir** ou **Permitir pular**, depois do áudio a central espera 1 segundo por uma tecla; sem tecla, segue para o destino.
- Quando um módulo usa o anúncio só como áudio (saudação da URA, anúncio de entrada e sussurro da fila, mensagens da pesquisa, entrada da conferência), só a gravação conta; o destino e as opções do anúncio são ignorados.

**Excluir:** a gravação continua em Gravações do Sistema. Quem apontava para o anúncio fica sem o áudio.

### Exemplos práticos

- **Aviso e desliga:** "Estamos em recesso até dia 5" sem destino. Aponte a condição de fora do horário para ele.
- **Aviso e recado:** "Deixe seu recado após o sinal" com destino **Caixas postais › Recados**.
- **Informação dentro da URA:** "Nosso endereço é…" com **Repetir** na tecla `*` e **Retornar para a URA**.
- **Recusa sem custo:** anúncio com **Não atender o canal** e sem destino, para avisar sem tarifar quem liga.

---

## 10. Condições Horárias

**Aplicações › Condições Horárias**

Se o momento da chamada está dentro do **grupo de horário**, a chamada vai para um destino; fora dele, para outro. Cada condição tem um **código no telefone** para forçar a entrada aberta ou fechada na mão.

Precisa de ao menos um grupo de horário criado antes ([seção 11](#11-grupos-de-horário)).

### O cartão

> 📷 **Print:** cartão de condição com o estado **Dentro do horário** e o código no telefone.

| Item | O que mostra |
|---|---|
| Estado | **Dentro do horário**, **Fora do horário**, **Feriado: <nome>**, **forçado aberto / forçado fechado**, ou **estado desconhecido**. Calculado pelo **relógio do servidor** |
| Grupo | O grupo de horário e quantas faixas tem |
| Dentro → / Fora → | Os dois destinos |
| Feriados → | Aparece quando a condição respeita feriados: no feriado, toca o áudio dele |
| No telefone | O código da condição (ex.: `*271`), o que ele faz e se pede PIN |
| Pelo relógio / Forçar aberto / Forçar fechado | Força pelo console (para quem pode editar) |

**Forçar pelo console** vale **na hora**, sem aplicar configurações, e fica até alguém voltar para **Pelo relógio**. A ação fica registrada na auditoria. Sem comunicação com o Asterisk, a condição continua seguindo o relógio e a tela avisa.

### Campos

| Campo | O que faz |
|---|---|
| Nome * | |
| Observação | |
| Grupo de horário * | As faixas deste grupo dizem se estamos dentro ou fora |
| Condição ativa | |
| Nos feriados, tocar o áudio do feriado | Ligado (padrão): antes do horário, a condição consulta **Aplicações › Feriados** ([seção 12](#12-feriados)). Desligue numa condição que deve ignorar feriados (um plantão 24 horas, por exemplo) |
| O código no telefone | **Fecha a entrada** (padrão): vai para "Fora do horário" mesmo dentro dele. **Abre a entrada**: vai para "Dentro do horário" mesmo fora dele. **Inverte**: faz o contrário do que o horário diz agora |
| PIN para usar o código | Só dígitos, 4 a 10. Em branco: sem PIN |
| Dentro do horário * | Destino quando alguma faixa do grupo cobre o momento da chamada |
| Fora do horário * | Destino no resto do tempo (fim de semana, madrugada, feriado) |

### O código no telefone

- O código é o **prefixo** de **Administrador › Códigos de Recurso** (de fábrica `*27`) **seguido do número da condição**: `*271`, `*272`… O ajuda do campo e o cartão mostram o código exato.
- **Discar uma vez força** (fecha, abre ou inverte, conforme o campo); ouve "ativado".
- **Discar de novo volta a seguir o relógio**; ouve "desativado".
- Com **PIN**, o telefone pede o PIN antes; são três tentativas e desliga.
- **Inverter** grava o contrário do que o relógio diz **no momento em que se disca**: fica nesse estado até discar de novo.
- O código e o console usam a **mesma marca**: forçar por um e desfazer pelo outro funciona.
- Se o prefixo estiver desligado em Códigos de Recurso, as condições não têm código.

**Tecla BLF:** programe o código (ex.: `*271`) numa tecla BLF do telefone. Ela fica **acesa enquanto a condição está forçada**, venha a força do telefone ou do console.

### Como a condição decide

1. Forçada aberta → destino **Dentro**. Forçada fechada → destino **Fora**.
2. Sem força e com feriados ligado: se hoje é feriado, toca o **áudio do feriado** (e segue para o destino dele, se tiver). Feriado sem áudio nem destino vai para o destino **Fora**.
3. Não sendo feriado, confere as faixas do grupo. Basta **uma** faixa casar para estar dentro.
4. Grupo sem faixas: **nunca** está dentro.

Forçar vence o feriado: para atender num feriado, **Forçar aberto** (ou o código no telefone).

Quem já ouviu o áudio do feriado e volta a uma condição (por exemplo, a URA do destino do feriado aponta para a condição) segue o horário normal: o áudio do feriado não toca duas vezes.

### Exemplos práticos

- **Expediente:** grupo "Horário comercial", Dentro → URA, Fora → anúncio de fora do horário.
- **Recepção fecha para reunião geral:** a recepcionista aperta a tecla BLF `*271`; a entrada vai para o anúncio de fora. Ao voltar, aperta de novo.
- **Feriados:** cadastre em **Aplicações › Feriados**, cada um com o seu áudio ([seção 12](#12-feriados)). A condição não precisa de nada além de "Nos feriados" ligado.
- **Plantão de sábado:** condição "Sábado" com grupo "Sábado 8h–12h", Dentro → fila de plantão, Fora → condição "Expediente". Uma condição pode apontar para outra.

**Excluir:** as rotas que apontavam para ela ficam sem destino.

---

## 11. Grupos de Horário

**Aplicações › Grupos de Horário**

As faixas de tempo que uma condição horária consulta: hora, dia da semana, dia do mês e mês. Um grupo pode ter várias faixas, e **cada faixa vale por si: basta uma casar** para estar dentro.

Cada cartão mostra o nome, a descrição, a quantidade de faixas e cada faixa em português (ex.: "segunda a sexta · 08:00 às 18:00").

> 📷 **Print:** gaveta de edição de um grupo com duas faixas.

### Campos

| Campo | O que faz |
|---|---|
| Nome * | O nome do grupo, como aparece na condição horária |
| Descrição | Texto livre |

### Horários (faixas)

Botão **Adicionar horário**. Em cada faixa, deixe em branco o que não importa.

| Coluna | O que faz |
|---|---|
| Hora | Início **às** fim. Em branco: o dia inteiro. Preencha as duas ou nenhuma |
| Dia da semana | **Todos os dias**, **Segunda a sexta**, **Sábado e domingo** ou **Dias escolhidos…** (marque os dias). Nenhum dia marcado vale como todos |
| Dia do mês | De / até (1 a 31). Só o primeiro = um dia só |
| Mês | De / até (janeiro a dezembro). Só o primeiro = um mês só |

**Validações:** hora de início sem a de término (ou o contrário) é recusada; "até" preenchido sem "de" é recusado.

### Exemplos de faixas

| Situação | Hora | Dia da semana | Dia do mês | Mês |
|---|---|---|---|---|
| Comercial | 08:00 às 18:00 | Segunda a sexta | — | — |
| Almoço fechado (duas faixas) | 08:00 às 12:00 e 13:00 às 18:00 | Segunda a sexta | — | — |
| Sábado de manhã | 08:00 às 12:00 | Dias escolhidos: sáb | — | — |
| Recesso de fim de ano | em branco | Todos os dias | 24 a 31 | dezembro |

Feriados não se montam mais aqui: cada um tem o seu cadastro, com o seu áudio, em **Aplicações › Feriados** ([seção 12](#12-feriados)).

**Excluir:** as faixas somem junto, e as condições que usam o grupo ficam sem referência (e passam a nunca estar dentro).

---

## 12. Feriados

**Aplicações › Feriados**

Cada feriado com o **seu áudio**. As condições horárias consultam esta lista **antes do horário**: no Natal, quem liga ouve o áudio do Natal; no Ano Novo, o do Ano Novo; na Páscoa, o da Páscoa. Cadastra-se uma vez: os feriados de todo ano voltam sozinhos, e os de data móvel são calculados a cada ano.

```
Tronco → Rota de entrada → Condição "Expediente"
                             ├─ é feriado? → áudio do feriado (Natal, Ano Novo, Páscoa…)
                             ├─ dentro do horário → URA
                             └─ fora do horário → anúncio "Fora do horário"
```

> 📷 **Print:** lista de feriados com o áudio de cada um.

### A lista

| Coluna | O que mostra |
|---|---|
| Feriado | O nome |
| Quando | A data como foi cadastrada e, para os móveis e os de vários dias, os dias deste ano (ex.: "Páscoa · em 2026: 05/04") |
| Horário | **o dia inteiro** ou a faixa de horas |
| Áudio | O áudio e o que vem depois dele (**desliga** ou o destino). **sem áudio**: o feriado segue o "Fora do horário" da condição |
| Estado | Ativo / Inativo |

Quando hoje é feriado, uma faixa no alto diz qual.

**Carregar feriados nacionais:** cadastra os que ainda não estão na lista (pelo nome): Ano Novo (01/01), Sexta-feira Santa, Páscoa, Tiradentes, Dia do Trabalho, Independência, Nossa Senhora Aparecida, Finados, Proclamação da República, Consciência Negra e Natal (25/12). **Carnaval** e **Corpus Christi**, que são ponto facultativo, entram **desativados**: ative se a empresa folga. Eles entram **sem áudio**: escolha o áudio de cada um. Para fechar também na véspera, edite o Natal para 24/12 a 25/12 e o Ano Novo para 31/12 a 01/01. Apague o que a empresa não folga.

### Campos

| Campo | O que faz |
|---|---|
| Nome * | Como aparece na lista |
| A data | **Todo ano no mesmo dia**: dia e mês, e "até" para mais de um dia (24/12 a 25/12; 31/12 a 01/01 vira o ano). **Móvel**: Carnaval, Quarta-feira de Cinzas, Sexta-feira Santa, Páscoa ou Corpus Christi, calculados pela Páscoa de cada ano. **Só num ano**: uma data marcada (uma ponte, uma folga), com "até" opcional; no ano seguinte não volta |
| Das / Às | Em branco: o dia inteiro. Preenchido: só nessa faixa do dia (ex.: véspera de Natal a partir das 12:00). As duas horas no mesmo dia |
| Áudio do feriado | Um áudio de **Administrador › Gravações do Sistema** |
| Depois do áudio | Opcional: uma fila de plantão, uma caixa postal, um celular de sobreaviso. Em branco, **desliga** depois do áudio. Não pode ser uma condição horária (ela consultaria o feriado de novo) |
| Feriado ativo | Desativado, a data volta a seguir só o horário |

Até 31 dias por feriado.

### Qual feriado vale

Quando dois feriados caem no mesmo dia, vale primeiro o de **data marcada (só num ano)**, depois o que tem **horário**, depois o mais antigo da lista. Assim, com "Natal" (24/12 a 25/12, o dia inteiro, áudio do Natal) e "Véspera" (24/12, das 12:00 às 23:59, áudio "fechamos mais cedo"), no dia 24 de manhã toca o do Natal e à tarde o da véspera.

### Exemplos práticos

- **O caso comum:** carregue os nacionais; Natal → áudio "Feliz Natal", Ano Novo → áudio "Feliz Ano Novo", Páscoa → áudio "Feliz Páscoa"; os outros com o áudio "Hoje é feriado". Aplique.
- **Feriado municipal:** "Aniversário da cidade", todo ano, o dia e o mês.
- **Ponte:** "Ponte de novembro", só num ano, 16/11/2026, áudio "Não há expediente hoje".
- **Plantão no feriado:** áudio do feriado e, depois dele, a fila de plantão.

**Lembre de Aplicar configurações** depois de mudar um feriado. A lista de dias dos feriados móveis vai calculada para os próximos 20 anos e é refeita a cada aplicação.

---

## 13. Problemas comuns

**No feriado, a chamada foi para o horário normal.**
- Faltou **Aplicar configurações** depois de cadastrar o feriado.
- A condição horária está com "Nos feriados, tocar o áudio do feriado" desligado, ou a rota de entrada não passa por uma condição.
- A condição está forçada (aberta ou fechada): forçar vence o feriado.

**Mudei a configuração e nada mudou no telefone.**
- Faltou **Aplicar configurações**. Só forçar condição pelo console e agentes de fila de call center valem na hora.

**A chamada cai logo depois de entrar.**
- O destino aponta para algo excluído ou desativado. Ao **Aplicar configurações**, procure o aviso "aponta para … que não existe ou está inativo".
- O anúncio usado não tem mais a gravação (**gravação removida** na lista de anúncios).

**A fila toca, mas ninguém recebe.**
- Fila comum sem agente: aviso "está sem nenhum agente" ao aplicar.
- Agentes "Entra por código" não entraram (`*45` + fila).
- Agentes pausados: veja **Situação agora**.
- **Entrar quando não há agente logado** em "Não" manda direto para o destino de fila vazia.

**O cliente fica esperando para sempre na fila.**
- **Espera máxima** está em 0 (sem limite). Defina um tempo e o destino de tempo esgotado.

**Ao estourar a fila, a chamada desliga.**
- **Tempo de espera esgotado** está em branco (**Desligar**). Escolha um destino na aba **Failover**.
- Fila gravada antes da versão 1.1.0 com URA ou anúncio digitado errado no failover: abra a aba **Failover**, escolha o destino pelo nome e salve.

**`*45` responde "operação falhou".**
- A fila é de call center: o agente entra com `*11`. Veja [16-Call-Center.md](16-Call-Center.md).
- O ramal não está na lista de agentes da fila como **Entra por código** (ou faltou **Aplicar configurações** depois de pô-lo lá). Só esses entram pelo `*45`.

**A pesquisa de satisfação nunca toca.**
- A pesquisa está parada, ou não foi escolhida na aba **Pesquisa** da fila.
- Faltou aplicar configurações.
- O cliente desligou junto com o atendente. A pesquisa só acontece se o **atendente** desligar primeiro.

**A URA não reage às teclas.**
- DTMF do tronco. Teste de um ramal com `*7777` + DID: se funciona do ramal e não de fora, é o tronco.
- A URA está sem anúncio de saudação (**sem anúncio de saudação** no cartão): ela fica muda, mas ainda espera o dígito.

**Digitar o ramal na URA não funciona.**
- **Permitir discar o ramal direto** está desligado, o ramal está inativo, ou faltou **Aplicar configurações** depois de criar o ramal (a URA conhece os ramais que existiam no último aplicar).

**A condição mostra "Dentro" mas a chamada vai para "Fora" (ou o contrário).**
- A condição está **forçada** (etiqueta "forçado…" no cartão, tecla BLF acesa). Volte para **Pelo relógio** ou disque o código de novo.
- Confira a hora do servidor: é o relógio dele que vale. Veja [05-Servidor-Comandos-e-Servicos.md](05-Servidor-Comandos-e-Servicos.md).
- Faixa salva com hora de início e fim, mas os dias marcados não incluem hoje.

**O código `*27N` diz que o número não existe.**
- O prefixo está desligado em **Administrador › Códigos de Recurso**, a condição está inativa, ou faltou aplicar.

**Na conferência, todo mundo fica ouvindo música e a reunião não começa.**
- **Só começar quando o administrador entrar** está ligado e ninguém entrou com o **PIN de administrador**. (A central não deixa mais salvar essa opção sem PIN de administrador; uma sala gravada assim antes da versão 1.1.0 precisa do PIN cadastrado ou da opção desligada.)

**A conferência derrubou todos de uma vez.**
- Com **Só começar quando o administrador entrar**, a sala encerra quando o administrador sai.

**A gravação não aparece.**
- Nenhuma regra mandou gravar (ramal, fila, rota), ou alguém tem "Nunca".
- **sem arquivo**: o arquivo foi apagado pelo prazo de guarda (ou à mão, no servidor).

**O grupo de toque não toca um ramal.**
- O ramal foi excluído ou desativado (aviso ao aplicar), ou o número na lista está errado.
- O ramal está em **não perturbe** ou com **desvio de todas as chamadas**: de propósito, o grupo não toca quem está assim.
