# Call Center — Telium PABX

Manual completo do módulo de call center: implantação, rotina do agente, rotina do supervisor, retornos e relatórios.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Quem acessa o quê](#2-quem-acessa-o-quê)
3. [Implantação passo a passo](#3-implantação-passo-a-passo)
4. [Configurar a fila](#4-configurar-a-fila)
5. [Agentes](#5-agentes)
6. [Motivos de pausa](#6-motivos-de-pausa)
7. [Tabulações](#7-tabulações)
8. [Configurações (códigos do telefone)](#8-configurações-códigos-do-telefone)
9. [O agente pelo telefone](#9-o-agente-pelo-telefone)
10. [O agente pelo console: Meu Atendimento](#10-o-agente-pelo-console-meu-atendimento)
11. [O supervisor: Monitor ao Vivo](#11-o-supervisor-monitor-ao-vivo)
12. [Retornos (callback)](#12-retornos-callback)
13. [Relatórios](#13-relatórios)
14. [O que a central faz sozinha](#14-o-que-a-central-faz-sozinha)
15. [Problemas comuns](#15-problemas-comuns)
16. [Glossário](#16-glossário)

---

## 1. Visão geral

O módulo transforma uma fila de atendimento comum numa operação de call center: quem atende são **pessoas** (agentes), não ramais fixos.

- **O agente é uma pessoa.** Ele tem nome, matrícula e, se quiser, um PIN. Senta em qualquer ramal, entra no atendimento e recebe as chamadas das filas dele naquele ramal. Amanhã pode sentar em outro.
- **O relatório é da pessoa.** Como o atendimento fica registrado no nome do agente, e não do ramal, os números acompanham quem atendeu, onde quer que tenha sentado.
- **Não precisa de usuário no console.** O agente pode trabalhar só pelo telefone ou softphone, com os códigos de discagem. A conta do console é opcional e dá acesso ao painel **Meu Atendimento**.
- **Tudo ao vivo.** O supervisor vê, no instante em que muda, quem está livre, falando ou em pausa, e quem está esperando na fila.

**O que o módulo oferece:**

| Recurso | Para que serve |
|---|---|
| Nível de habilidade | Define quem recebe primeiro em cada fila |
| Pausas com motivo e código próprio | Cada pausa tem o seu código no telefone; o relatório mostra o tempo de cada motivo |
| Tabulação | O agente registra o que foi resolvido em cada atendimento |
| Retorno (callback) | O cliente aperta uma tecla, desliga e a central liga de volta quando houver agente livre |
| Monitor ao Vivo | Filas, agentes e espera em tempo real, com escuta, sussurro e intervenção |
| Relatórios | Nível de serviço, abandono, tempos médios, ocupação, pausas e satisfação, com exportação em CSV |

> 📷 **Print:** menu lateral aberto na seção **Call Center**.

---

## 2. Quem acessa o quê

| Perfil | O que vê no Call Center |
|---|---|
| **Administrador** | Tudo |
| **Supervisor** | Tudo: Monitor ao Vivo, cadastros, retornos, relatórios e configurações |
| **Call Center** | Só **Meu Atendimento**. Não vê a central, os colegas nem relatórios |
| **Operador** | **Meu Atendimento** e o **Meu Painel** (siga-me, correio de voz, agenda) |
| Agente sem conta | Não acessa o console; trabalha só pelos códigos do telefone |

- Para **escutar, sussurrar ou intervir**, o supervisor precisa ter um **ramal vinculado ao usuário dele**: é esse ramal que toca.
- O **Modo TV** do Monitor ao Vivo aparece para quem tem a permissão do painel em tempo real.

---

## 3. Implantação passo a passo

Na ordem abaixo, do zero até o primeiro atendimento:

1. **Criar a fila** em **Aplicações › Filas de Atendimento** e marcar **Fila de call center** ([seção 4](#4-configurar-a-fila)).
2. **Cadastrar os motivos de pausa** em **Call Center › Motivos de Pausa** ([seção 6](#6-motivos-de-pausa)). Já vêm seis prontos.
3. **Cadastrar as tabulações** em **Call Center › Tabulações**, se a operação vai registrar o resultado dos atendimentos ([seção 7](#7-tabulações)).
4. **Cadastrar os agentes** em **Call Center › Agentes**, com as filas e o nível de cada um ([seção 5](#5-agentes)).
5. **Aplicar configurações** (faixa amarela no topo do console). Sem isso, os códigos novos não funcionam no telefone.
6. **Testar:** um agente entra com `*11`, alguém liga para a fila e o supervisor acompanha no **Monitor ao Vivo**.
7. **Avisar a equipe** dos códigos ([seção 9](#9-o-agente-pelo-telefone)).

---

## 4. Configurar a fila

**Aplicações › Filas de Atendimento › Nova fila** (ou editar uma existente).

> 📷 **Print:** lista de filas com a etiqueta **call center** numa delas.

### Aba Geral

| Campo | O que faz |
|---|---|
| Número da fila | O número que se disca (ou para onde a URA manda) para cair na fila |
| Nome / Descrição | Como a fila aparece no monitor e nos relatórios |
| Estratégia de toque | Como a chamada é distribuída entre os agentes livres (veja abaixo) |
| Toque por agente (s) | Quanto tempo toca em cada agente antes de passar ao próximo |
| Intervalo entre tentativas (s) | Pausa entre uma rodada de toques e a seguinte |
| Pausa pós-atendimento (s) | Segundos em que o agente fica sem receber chamada depois de desligar |
| Meta de SLA (s) | Atender dentro deste tempo conta como "dentro da meta" |
| Gravar as chamadas | Grava as conversas da fila |
| **Fila de call center** | **Liga o modo call center.** Os agentes passam a vir do cadastro de agentes |

**Estratégias de toque:**

| Opção | Comportamento |
|---|---|
| Rodízio com memória (padrão) | Distribui em rodízio e lembra quem foi o último; a mais justa |
| Quem está há mais tempo sem atender | Prioriza quem está ocioso há mais tempo |
| Quem atendeu menos chamadas | Equilibra pela quantidade |
| Tocar em todos ao mesmo tempo | Quem atender primeiro leva |
| Na ordem da lista | Sempre começa pelo primeiro |
| Aleatório | Sorteia |

> 📷 **Print:** aba **Geral** com **Fila de call center** ligado.

### Aba Call center

Vale só para fila marcada como call center.

| Campo | O que faz |
|---|---|
| Exigir tabulação | Depois de cada atendimento, o agente fica em **Pós-atendimento** (sem receber chamadas) até registrar o que foi resolvido |
| Tecla de retorno | A tecla que o cliente aperta na espera para pedir que liguem de volta. Vazio: não oferece retorno |
| Anúncio de confirmação do retorno | O que o cliente ouve depois de pedir o retorno. Sem anúncio, a central diz "obrigado" |
| Tentativas de retorno | Quantas vezes a central tenta falar com o cliente, com 3 minutos entre as tentativas |
| Ampliar habilidade a cada (s) | A chamada começa oferecida só ao nível 0; a cada tantos segundos de espera, entra mais um nível. 0 desliga a ampliação: todos os níveis recebem, os menores primeiro |
| Até o nível | O último nível que a ampliação alcança |

**Importante sobre o retorno:**
- A central **não avisa sozinha** que a tecla existe. Inclua no **anúncio periódico** da fila algo como: *"Se preferir, aperte 1 e nós ligamos de volta."*
- Chamadas sem número identificado (número restrito) não conseguem pedir retorno: o cliente ouve um aviso e volta para a fila.

> 📷 **Print:** aba **Call center** da fila.

### Outras abas que afetam o call center

| Aba | Campo | Uso no call center |
|---|---|---|
| Áudios | Anúncio de entrada | Mensagem que o cliente ouve ao entrar na fila |
| Áudios | Sussurro, para quem vai atender | Só o agente ouve antes de conectar: útil para saber de qual fila veio a chamada |
| Áudios | Anúncio periódico | Repetido para quem espera. É aqui que se avisa da tecla de retorno |
| Espera | Anunciar posição / tempo estimado | Informa o cliente enquanto ele espera |
| Espera | Espera máxima (s) | Passou disso, a chamada sai para o destino de tempo esgotado (conta como **estouro** no relatório) |
| Espera | Máximo de chamadas na fila | Ao encher, a chamada vai para o destino de fila cheia |
| Comportamento | Entrar quando não há agente logado | Se o cliente entra e espera mesmo sem ninguém logado, ou vai direto ao destino de fila vazia |
| Comportamento | **Pausar quem não atende** | Se o agente deixa tocar sem atender, a central o pausa como **Não atendeu**, nesta fila ou em todas as dele. Evita a chamada ficar rodando em quem saiu da mesa sem pausar |

### Botão "Agentes" numa fila de call center

Numa fila de call center, o botão **Agentes** da lista de filas não monta ramais: ele avisa que os agentes vêm de **Call Center › Agentes**. Os códigos `*45`/`*46` (entrar e sair de fila comum) **não funcionam** em fila de call center. Na fila comum, o `*45` só põe na fila o ramal que está na lista de agentes dela como **Entra por código** (veja [Filas](13-Aplicacoes-Filas-URA-e-Atendimento.md)).

---

## 5. Agentes

**Call Center › Agentes**

A lista mostra cada agente, a matrícula (com a etiqueta **PIN** quando ele tem um), as filas com o nível em cada uma, o ramal habitual e se está ativo. Quem não tem conta no console aparece como **só telefone**.

> 📷 **Print:** lista de agentes.

### Cadastrar ou editar

Clique em **Novo agente**.

| Campo | Regra | Observação |
|---|---|---|
| Nome * | 2 a 80 caracteres | É o que o supervisor e os relatórios mostram |
| Conta do console | Opcional | Com ela, a pessoa abre o painel **Meu Atendimento**. Uma conta só pode estar em um agente. Escolher a conta preenche o nome, se estiver vazio |
| Matrícula * | 2 a 8 dígitos, única | É o que se digita no telefone para entrar |
| PIN do telefone | 4 a 8 dígitos, opcional | Com PIN, entra-se com **matrícula \* PIN** (ex.: `501*1234`). Ao editar, deixe em branco para manter, ou marque **Remover o PIN** |
| Ramal habitual | Um ramal existente, opcional | Só uma sugestão no painel; o agente pode entrar em qualquer ramal |
| Agente ativo | | Inativo não consegue entrar |
| Filas e nível | Marque as filas e escolha o nível de 0 a 9 | Só filas de call center aparecem |

> 📷 **Print:** formulário do agente, com a tabela de filas e níveis.

### Nível de habilidade

- **Nível 0** recebe primeiro.
- Os níveis 1, 2, 3… só recebem quando os de nível menor estão **todos ocupados**, ou quando a **ampliação** da fila alcança o nível deles.
- Exemplo: a equipe especializada fica no nível 0 da fila "Suporte" e os generalistas no nível 1. Os generalistas só pegam suporte quando os especialistas estão todos ocupados.

### O que acontece ao salvar

- **Se o agente está logado**, a mudança vale na hora: ele entra nas filas novas, sai das que perdeu e o nível é ajustado. Se ele estava em pausa, entra nas filas novas também pausado.
- Se a central não responder no momento, aparece o aviso de que a mudança vale no próximo login.
- **Excluir** um agente o tira das filas imediatamente. O **histórico e os relatórios** continuam com o que ele fez.
- Excluir a **conta do console** de uma pessoa **não exclui** o agente: ele continua atendendo pelo telefone.

---

## 6. Motivos de pausa

**Call Center › Motivos de Pausa**

Cada pausa tem **o seu próprio código no telefone**: o agente disca e já fica pausado por aquele motivo.

> 📷 **Print:** lista de motivos de pausa.

### Motivos que já vêm prontos

| Motivo | Código | Limite | Tipo |
|---|---|---|---|
| Almoço | `*14` | 60 min | improdutiva |
| Banheiro | `*15` | 10 min | improdutiva |
| Café | `*16` | 15 min | improdutiva |
| Treinamento | `*17` | sem limite | produtiva |
| Reunião | `*18` | sem limite | produtiva |
| Feedback | `*19` | 30 min | produtiva |
| Pós-atendimento | — (posto pela central) | 5 min | produtiva |
| Não atendeu | — (posto pela central) | sem limite | improdutiva |

### Campos

| Campo | O que faz |
|---|---|
| Nome | Como aparece no painel, no monitor e no relatório |
| Código no telefone | `*` seguido de 1 a 6 dígitos. Vazio: a pausa só pode ser usada pelo console. Vale depois de **Aplicar configurações** |
| Limite (minutos) | Passou disso, o supervisor vê a pausa em **vermelho**. Vazio: sem limite |
| Pausa produtiva | Treinamento, reunião, feedback: é trabalho, e **não conta contra a ocupação** no relatório |
| Ordem | Ordem em que aparece na lista do agente |
| Ativo | Motivo inativo não aparece para o agente |

**Regras do código:**
- Não pode ser **igual** a nenhum outro código da central (códigos de recurso, outras pausas, caixas postais).
- Não pode ser o **começo** de outro código, nem ter outro como começo. Exemplo: se existe `*1`, não dá para criar `*14`, porque o telefone discaria `*1` antes de terminar.
- A tela recusa ao salvar e diz com qual código bateu.

**Motivos da central** (Pós-atendimento e Não atendeu) são postos automaticamente ([seção 14](#14-o-que-a-central-faz-sozinha)). Não têm código e não aparecem na lista de pausas do agente. Dá para mudar o limite e a ordem deles, mas **não renomeie**.

---

## 7. Tabulações

**Call Center › Tabulações**

A tabulação é o desfecho do atendimento: "Resolvido", "Encaminhado ao técnico", "Reclamação", "Venda realizada"…

> 📷 **Print:** lista de tabulações.

| Campo | O que faz |
|---|---|
| Nome | O que o agente escolhe |
| Grupo | Agrupa as opções na lista do agente (ex.: "Suporte — Resolvido") |
| Só para a fila | Restringe a uma fila. Vazio: vale para todas |
| Ordem | Ordem na lista |
| Ativa | Inativa não aparece para o agente |

- Nas filas com **Exigir tabulação**, o agente só volta a receber chamadas depois de tabular.
- Nas outras filas, tabular é opcional: o agente pode qualificar qualquer atendimento do dia pelo painel.
- A tabulação só pode ser feita **pelo console** (Meu Atendimento). Agente sem conta no console não consegue tabular, então **não use Exigir tabulação** em fila com agentes só de telefone.

---

## 8. Configurações (códigos do telefone)

**Call Center › Configurações**

Aqui ficam os códigos que o agente usa para entrar, sair e voltar da pausa. O código de cada pausa fica em **Motivos de Pausa**.

| Ação | Código padrão |
|---|---|
| Entrar no atendimento | `*11` |
| Sair do atendimento | `*12` |
| Voltar da pausa | `*13` |

- Cada código pode ser **trocado** ou **desativado**.
- Valem as mesmas regras de colisão dos motivos de pausa.
- A troca só vale nos telefones depois de **Aplicar configurações**.

> 📷 **Print:** tela de Configurações do Call Center.

---

## 9. O agente pelo telefone

Funciona em qualquer telefone IP ou softphone registrado na central, sem abrir o console.

### Códigos

| Código | O que faz |
|---|---|
| `*11` | **Entrar.** A central pede o código do agente: digite a **matrícula** e `#`, ou **matrícula `*` PIN** e `#` para quem tem PIN (ex.: `501*1234#`) |
| `*14` … `*19` (ou o código de cada pausa) | **Pausar** pelo motivo daquele código |
| `*13` | **Voltar** da pausa |
| `*12` | **Sair** do atendimento |

> Os códigos acima são os de fábrica. Confira os da sua central em **Call Center › Configurações** e **Motivos de Pausa**.

> 📷 **Sugestão de print:** um cartão impresso com os códigos, para deixar na mesa de cada agente.

### O que a central responde

| Mensagem ouvida | Significado |
|---|---|
| "Login realizado" | Entrou em todas as filas dele, neste ramal |
| "Pausa ativada" | Está em pausa, em todas as filas |
| "Pausa removida" | Voltou a receber chamadas |
| "Logout realizado" | Saiu de todas as filas |
| "Agente inválido" | Matrícula ou PIN errados, ou nada digitado |
| "Agente sem fila" | O agente existe, mas não está em nenhuma fila de call center ativa |
| "Serviço indisponível" | O serviço de call center da central está fora. Chamar o suporte |
| "Operação falhou" | Veja abaixo |

**"Operação falhou" acontece quando:**
- outro agente já está logado neste ramal;
- o agente tenta pausar, voltar ou sair sem estar logado neste ramal;
- o agente tenta voltar (`*13`) com tabulação pendente numa fila que exige tabulação;
- o motivo de pausa foi desativado.

### Regras

- **Um ramal, um agente.** Se outra pessoa já está logada no ramal, a entrada é recusada. Ela precisa sair primeiro.
- **Trocou de mesa?** Basta entrar no ramal novo: a central tira o agente do ramal antigo sozinha.
- **Pausa vale para todas as filas** do agente.
- **Segurança do PIN:** cinco códigos errados em dez minutos no mesmo ramal bloqueiam novas tentativas naquele ramal por cerca de quinze minutos.
- **Reinício da central não desloga ninguém.** Quem estava logado continua logado.

---

## 10. O agente pelo console: Meu Atendimento

**Call Center › Meu Atendimento**

Disponível para o agente que tem conta no console. Se aparecer **"Sua conta não é de agente"**, o supervisor precisa vincular a conta a um agente em **Call Center › Agentes**.

O indicador no canto superior direito mostra a conexão:
- **Ao vivo:** recebendo as mudanças na hora.
- **Atualizando a cada 3 s:** funciona, mas em modo de consulta.
- **Sem comunicação com a central:** a tela não está recebendo dados.

> 📷 **Print:** Meu Atendimento com o agente **Disponível**.

### Entrar

Informe o **ramal em que você está** (vem preenchido com o ramal habitual) e clique em **Entrar no atendimento**.

> 📷 **Print:** cartão com o campo de ramal e o botão **Entrar no atendimento**.

### O cartão de estado

| Estado | Cor | Significado |
|---|---|---|
| Disponível | verde | Pronto para receber |
| Tocando | azul | Uma chamada está tocando no ramal |
| Em atendimento | azul | Em conversa |
| Em pausa | amarelo | Pausado, com o motivo |
| Pós-atendimento | azul Telium | Aguardando a tabulação |
| Ramal indisponível | vermelho | Logado, mas o telefone não está registrado |
| Fora do atendimento | cinza | Não logado |

O relógio grande mostra:
- **em pausa:** há quanto tempo, e o limite do motivo (em vermelho quando passa);
- **disponível:** quanto tempo desde o último atendimento;
- **em atendimento:** a duração da conversa.

**Durante uma chamada**, o cartão mostra em destaque **quem está na linha**: o número, o nome do contato (se estiver na agenda de contatos), a fila e quanto tempo o cliente esperou.

> 📷 **Print:** cartão "Na linha" durante um atendimento.

### Botões

| Botão | O que faz |
|---|---|
| **Pausar** | Abre a lista de motivos, com o limite de cada um e se é produtiva |
| **Voltar a atender** | Sai da pausa. Não aparece enquanto houver tabulação pendente |
| **Sair do atendimento** | Sai de todas as filas (pede confirmação) |

### Tabular

Quando a fila exige tabulação, ao desligar aparece o quadro **"O que foi resolvido?"**:
1. Escolha a **tabulação**.
2. Se quiser, escreva uma **observação** (até 1000 caracteres).
3. Clique em **Tabular**.

Tabulado o último pendente, o agente **volta sozinho** a receber chamadas.

> 📷 **Print:** quadro "O que foi resolvido?".

### Os outros quadros

| Quadro | Conteúdo |
|---|---|
| Hoje | Atendidas, tempo médio de atendimento e quantas vezes não atendeu |
| Minhas filas | Cada fila, o nível do agente nela, quantos esperam e a maior espera (vermelho quando passa da meta) |
| Pelo telefone | Os códigos válidos para este agente |
| Meus atendimentos de hoje | Hora, quem ligou, fila, espera e qualificação. O botão **Qualificar** (ou **Alterar**) permite tabular qualquer atendimento do dia |

---

## 11. O supervisor: Monitor ao Vivo

**Call Center › Monitor ao Vivo**

> 📷 **Print:** Monitor ao Vivo completo.

### Filtro de filas

Com duas filas de call center ou mais, aparece no topo a linha **Filas:**
- **Todas** mostra tudo.
- Marque uma ou mais filas para ver só elas. Os indicadores, os cartões, os agentes e a lista de espera passam a considerar só as filas marcadas.
- A escolha fica **guardada no navegador**: quem cuida de uma fila só não precisa escolher toda vez.

> 📷 **Print:** filtro com duas filas marcadas.

### Indicadores

| Indicador | O que mostra |
|---|---|
| Esperando agora | Clientes na espera e a maior espera |
| Disponíveis | Agentes livres e quantos estão logados |
| Em atendimento | Agentes falando ou tocando, e quantos estão em pausa |
| SLA hoje | Percentual atendido dentro da meta, recebidas e abandonos. **Verde** a partir de 80%, **amarelo** de 60% a 80%, **vermelho** abaixo de 60% |

### Cartões das filas

Um por fila: quantos esperam, a maior espera (em vermelho quando passa da meta), agentes livres sobre logados, atendidas no dia e o SLA do dia.

### Agentes

A tabela mostra os agentes logados e, no fim, **os cadastrados que não estão logados**, para o supervisor ver quem falta.

- Filtros: **Todos**, **Disponíveis**, **Em atendimento**, **Em pausa**.
- **Há quanto tempo:** tempo na pausa (vermelho com ⚠ quando passa do limite do motivo) ou tempo desde o último atendimento.
- **Filas (nível):** as filas do agente, com o nível.
- **Hoje / TMA:** atendidas no dia e tempo médio de atendimento.

> 📷 **Print:** tabela de agentes com um agente em pausa estourada.

### Ações sobre o agente

Disponíveis para quem pode editar no Monitor ao Vivo:

| Ação | Quando aparece | O que faz |
|---|---|---|
| **Escutar** | Agente em atendimento | Seu ramal toca; ao atender, você ouve a conversa sem ser ouvido |
| **Sussurrar** | Agente em atendimento | Só o agente ouve você; o cliente não |
| **Intervir** | Agente em atendimento | Você entra na conversa e os dois ouvem você |
| **Pausar** | Agente não pausado | Pausa o agente por um motivo escolhido |
| **Tirar da pausa** | Agente pausado | Volta o agente a receber chamadas |
| **Tirar do atendimento** | Sempre | Desloga o agente de todas as filas |

- A escuta é **discreta**: nem o agente nem o cliente recebem aviso de que há alguém na linha.
- A escuta termina quando o agente desliga.
- Para escutar, sussurrar e intervir, **o usuário do supervisor precisa ter ramal**.
- Todas as ações ficam registradas na **auditoria**.

### Quem está esperando

Os clientes na espera, do que espera há mais tempo para o mais recente: fila, posição, número e tempo de espera (vermelho quando passa da meta). Os **retornos** em andamento aparecem com a etiqueta **retorno**.

### Modo TV

O botão **Modo TV** abre o painel em tela cheia, para deixar numa TV da operação.

---

## 12. Retornos (callback)

**Call Center › Retornos**

O cliente que cansou de esperar aperta a **tecla de retorno**, ouve a confirmação e desliga. A central liga de volta quando houver agente livre.

> 📷 **Print:** lista de retornos.

### Como a central liga de volta

1. **O agente primeiro.** A central só liga para o cliente depois que um agente atendeu. Assim o cliente não volta a esperar na fila.
2. O retorno entra na fila **com prioridade**. O visor do agente mostra **"Retorno"** e o número do cliente.
3. O agente atende e a central **disca o cliente** pela rota de saída. O cliente vê o **telefone da empresa** cadastrado, se houver um.
4. **Um retorno por fila de cada vez**, e só quando há agente livre nela: os retornos não tiram agentes de quem está ligando agora.
5. Se o cliente não atender ou estiver ocupado, a central tenta de novo **3 minutos depois**, até o número de tentativas da fila.
6. Se nenhum agente atender o retorno, **não conta como tentativa**: ele volta para a fila de retornos.

**Pedidos repetidos:** se o mesmo número pede retorno na mesma fila enquanto já há um pendente, o novo pedido é ignorado.

### Estados

| Estado | Significado |
|---|---|
| Aguardando agente | Na fila de retornos, esperando agente livre |
| Discando | Em andamento |
| Falou com o cliente | Concluído |
| Não conseguiu falar | Esgotou as tentativas |
| Cancelado | Cancelado pelo supervisor |

**Filtros:** Últimos 7 dias, Aguardando, Não conseguiu, Concluídos.

**Ações:** **Cancelar** (aguardando ou não conseguiu) e **Tentar de novo** (não conseguiu, cancelado ou concluído).

---

## 13. Relatórios

**Call Center › Relatórios**

Os números vêm do **registro das próprias filas**: cada chamada é contada uma vez, com a espera e a conversa medidas pela central.

> 📷 **Print:** relatório com os indicadores e a tabela de filas.

### Filtros

- Atalhos **Hoje**, **7 dias**, **30 dias**, ou as datas de início e fim (período de no máximo um ano).
- Uma fila ou todas.
- Clique em **Ver** para atualizar.

### Indicadores do topo

| Indicador | Cálculo |
|---|---|
| Recebidas | Chamadas que entraram nas filas (e quantas foram atendidas) |
| Nível de serviço | Atendidas dentro da meta ÷ recebidas |
| Abandono | Quem desistiu esperando ÷ recebidas |
| Espera média | Tempo médio de espera de quem foi atendido, e quantos pediram retorno |

**Sobre o nível de serviço:** quem desistiu antes do tempo da meta também conta contra. É a conta mais rigorosa, e a que mostra uma fila mal dimensionada.

### Tabela de filas

| Coluna | Significado |
|---|---|
| Recebidas / Atendidas | Entraram / foram atendidas |
| SLA | Nível de serviço da fila |
| Abandono | % que desistiu esperando |
| Estouro | Saíram por atingir a espera máxima |
| Sem agente | Saíram, ou não entraram, por não haver agente |
| Retorno | Pediram retorno pela tecla |
| TME | Tempo médio de espera |
| Maior espera | A maior espera do período |
| TMA | Tempo médio de atendimento |

### Por hora do dia

Gráfico de barras com atendidas e não atendidas por hora: mostra os horários de pico e onde falta gente.

> 📷 **Print:** gráfico por hora.

### Tabela de agentes

| Coluna | Significado |
|---|---|
| Atendidas | Chamadas atendidas |
| TMA | Tempo médio de atendimento |
| Não atendeu | Vezes em que a chamada tocou e o agente não atendeu |
| Logado | Tempo total logado no período |
| Em pausa | Tempo total em pausa |
| Pausas | Tempo por motivo de pausa |
| Ocupação | Tempo falando ÷ tempo disponível. Disponível é o tempo logado menos as pausas improdutivas: pausa produtiva não conta contra o agente |
| Satisfação | Índice de 0 (pior) a 100 (melhor), da pesquisa de satisfação; entre parênteses, quantas respostas |
| Tabuladas | Atendimentos tabulados |

### Tabulações

Gráfico com a quantidade de cada tabulação no período, por fila.

### Exportar

Os botões **CSV** exportam as tabelas de filas, de horas e de agentes para planilha.

---

## 14. O que a central faz sozinha

| Situação | O que acontece |
|---|---|
| Agente desliga numa fila com **Exigir tabulação** | Fica em pausa **Pós-atendimento** até tabular. Tabulado o último pendente, volta sozinho. Se já estava em outra pausa, continua nela |
| Agente deixa tocar sem atender, com **Pausar quem não atende** ligado | Fica em pausa **Não atendeu**. Precisa voltar com `*13` ou pelo botão |
| Agente tabula durante a chamada | Ao desligar, não é pausado: já disse o que foi resolvido |
| Fila com **ampliação de habilidade** | A cada intervalo de espera, a chamada passa a ser oferecida a mais um nível |
| Cadastro do agente muda com ele logado | As filas e níveis são ajustados na hora |
| A central reinicia | Os agentes continuam logados e no mesmo estado |
| Agente entra em outro ramal | Sai do ramal anterior automaticamente |

---

## 15. Problemas comuns

**O agente digita o código e ouve "Agente inválido".**
- Confira a matrícula em **Call Center › Agentes**.
- Se o agente tem **PIN**, ele precisa digitar `matrícula*PIN#` (ex.: `501*1234#`).
- Confira se o agente está **ativo**.
- Depois de cinco erros em dez minutos, o ramal fica bloqueado por cerca de quinze minutos.

**Ouve "Agente sem fila".**
- O agente não tem nenhuma fila marcada, ou as filas dele estão inativas ou deixaram de ser de call center.

**Ouve "Operação falhou" ao entrar.**
- Outro agente está logado nesse ramal. Veja quem no **Monitor ao Vivo** e peça para sair, ou tire pelo monitor.

**Ouve "Operação falhou" ao voltar da pausa.**
- Há tabulação pendente. O agente precisa tabular no **Meu Atendimento**.

**O código não faz nada (a central diz que o número não existe).**
- O código foi criado ou trocado e ninguém clicou em **Aplicar configurações**.
- O código pode estar desativado em **Call Center › Configurações**.

**O agente está logado, mas não recebe chamadas.**
- Está em pausa (veja o motivo no monitor). Em **Não atendeu**, ele precisa voltar.
- O nível dele na fila é alto e os de nível menor estão dando conta.
- O ramal aparece como **Ramal indisponível**: o telefone não está registrado.

**O agente ficou parado em "Pós-atendimento".**
- Ele precisa tabular. Agente sem conta no console não consegue: desligue **Exigir tabulação** nessa fila, ou dê a ele uma conta com o perfil **Call Center**.
- O supervisor pode usar **Tirar da pausa** no monitor.

**"Sua conta não é de agente" no Meu Atendimento.**
- A conta do console não está vinculada a nenhum agente. Edite o agente e escolha a conta em **Conta do console**.

**O supervisor clica em Escutar e recebe erro.**
- O usuário do supervisor não tem ramal vinculado. Vincule em **Administrador › Gerenciador de Usuários**.

**O Monitor mostra "Atualizando a cada 3 s" ou "Sem comunicação".**
- "Atualizando a cada 3 s" funciona normalmente, só que mais devagar.
- "Sem comunicação" persistente: chame o suporte para verificar o serviço de call center da central.

**O retorno não acontece.**
- Só dispara quando há **agente livre** na fila.
- O cliente precisa ter ligado com número identificado.
- Confira em **Call Center › Retornos** o estado e o último resultado.

**O agente ultrapassa o limite de pausa e ninguém percebe.**
- O limite não tira o agente da pausa: ele só destaca em vermelho no monitor. Combine com a supervisão de acompanhar o painel ou o Modo TV.

---

## 16. Glossário

| Termo | Significado |
|---|---|
| Agente | A pessoa que atende, identificada pela matrícula |
| Fila de call center | Fila cujos atendentes vêm do cadastro de agentes |
| Nível (habilidade) | Ordem de prioridade do agente numa fila; 0 recebe primeiro |
| Ampliação | Liberar a chamada para níveis maiores conforme a espera cresce |
| Pausa produtiva | Pausa que é trabalho (treinamento, reunião) e não conta contra a ocupação |
| Tabulação | Registro do desfecho do atendimento |
| Pós-atendimento | Pausa automática até o agente tabular |
| Retorno (callback) | A central liga de volta para quem pediu na espera |
| SLA / Nível de serviço | Percentual de chamadas atendidas dentro da meta |
| TME | Tempo médio de espera |
| TMA | Tempo médio de atendimento |
| Abandono | O cliente desligou antes de ser atendido |
| Estouro | A chamada atingiu a espera máxima da fila |
| Ocupação | Quanto do tempo disponível o agente passou em conversa |
| Escutar / Sussurrar / Intervir | Formas de o supervisor entrar numa chamada em andamento |


---

## Stats (Asternic Call Center Stats)

**Call Center › Stats**

Relatórios de filas do **Asternic Call Center Stats PRO**, um produto comercial da House Internet, dentro do console. Ele roda na própria central, em `https://<central>/stats/`.

> [PRINT: tela Stats com o Asternic aberto dentro do console]

### Licença

- **Cada central precisa de uma licença comprada**, presa ao endereço MAC do servidor e ativada pela internet na primeira abertura.
- Se a VPS mudar de placa de rede ou de máquina (por exemplo, restaurada numa VPS nova), a licença precisa ser transferida pelo fornecedor.
- Os arquivos do Asternic **não estão no repositório do Telium** (a licença proíbe redistribuir): a instalação baixa o pacote oficial.

### Instalar numa central

1. Compre a licença para o servidor.
2. No `servidor.yml` da central, acrescente `instalar_asternic: true`.
3. Rode a atualização de sempre (o playbook). Ela:
   - instala o carregador ionCube no PHP-FPM e baixa o Asternic da versão de `asternic_versao`;
   - publica a tela em `/opt/asternic/stats`, servida em `/stats/` por um PHP-FPM próprio, com usuário próprio (`asternic`), que não enxerga a API;
   - cria o banco `qstats` com usuário e senha aleatória (em `/etc/telium/credenciais/asternic_db_senha`), sem permissão sobre o resto do MariaDB;
   - faz o Asterisk gravar o `queue_log` também em `/var/log/asterisk/queue_log` (os relatórios do Telium continuam lendo o do banco);
   - liga o serviço `asterniclog`, que lê esse arquivo para o banco `qstats`;
   - cria o usuário `asternic` no AMI, que só lê e mexe em fila e agente, e já o cadastra no Asternic (Setup › AMI Servers);
   - atualiza as tabelas do Asternic para a versão instalada (`update_db.php` do fornecedor);
   - carrega no Asternic, uma vez, o histórico de filas que a central já tinha;
   - agenda o cron dos relatórios agendados e alertas por e-mail.
4. Abra **Call Center › Stats**. Na primeira vez, a tela do Asternic pede o **código de ativação** e o nome do licenciado. É o único passo manual: o código é o da compra.
5. Entre com o usuário **`admin`** e **a mesma senha do admin do console**.

A senha do admin do Asternic é gravada uma vez por central, com a senha padrão do admin do console. Se a senha do console for trocada depois, a do Asternic não acompanha; troque também dentro do Asternic, que o playbook não a desfaz.

### Quem acessa

- O `/stats/` só abre para quem está **logado no console** e tem a permissão **Call Center › Stats** no perfil. A cada acesso, o nginx pergunta isso à API; sem sessão, volta para o login do console.
- Dentro dele vale também o login do próprio Asternic.
- O Supervisor e o Administrador já têm a permissão.

### Atualizar o Asternic

Troque `asternic_versao` no `servidor.yml` e rode o playbook. A licença (`asterniclic.php`), o `config.php` e as personalizações (`custom.php`, `css/custom.css`, `js/custom.js`) ficam como estão.

### O que saber

- **Agentes e filas** o Asternic tira do `queue_log`: aparecem os que tiveram algum evento (entrar na fila, pausa, chamada). Na instalação, o histórico que a central já tinha no banco é carregado uma vez (`/var/log/asternic/historico.log`); daí em diante, o serviço `asterniclog` lê o arquivo `/var/log/asterisk/queue_log` em tempo real. Agente que nunca entrou numa fila não aparece.
- **Agentes do call center** aparecem no Asternic como `Agente/<número>`; ramais fixos em fila, com o nome do ramal. Dê nome a eles na tela de agentes do Asternic.
- **O ionCube desliga o JIT do PHP** no FPM da central (aviso no log). Não muda nada no funcionamento do console.
- **O PDF** dos relatórios depende do `wkhtmltopdf`, que o fornecedor não oferece para Debian 13. Os relatórios em tela e em planilha funcionam.
- O banco `qstats` entra no backup do console (`asternic.sql` dentro do arquivo), e `/opt/asternic` (com a licença) entra no backup da empresa.

### Problemas comuns

**A tela Stats diz que o Asternic não está instalado.** Falta `instalar_asternic: true` no `servidor.yml` e rodar o playbook.

**O `/stats/` volta para o login do console.** A sessão expirou, ou o perfil não tem **Call Center › Stats**.

**A tela do Asternic diz que a licença não foi encontrada.** Ainda não foi ativada nesta central: use o código de ativação comprado. Licença de outro servidor não vale aqui.

**A senha do admin está certa e a tela volta para o login.** Era a sessão do PHP sem pasta própria (corrigido na 1.2.3): rode a atualização. Os erros ficam em `/var/log/asternic/php.log`.

**"Could not connect to the Asterisk Manager Interface" no tempo real.** O Asternic usa a sua própria lista de servidores AMI (Setup › AMI Servers), não o `config.php`. O playbook deixa a linha da central em `127.0.0.1`, usuário `asternic` e a senha de `/etc/telium/credenciais/asternic_ami_senha` (corrigido na 1.2.5). Não troque para `localhost`: o AMI só escuta no IPv4. Para conferir o usuário no Asterisk: `asterisk -rx "manager show user asternic"`.

**O serviço `asterniclog` reinicia sem parar ("Unable to locate executable").** Falta o `/usr/local/parselog/asterniclog` (corrigido na 1.2.5): rode a atualização.

**"Bad username or password" com a senha certa.** As tabelas do Asternic não foram atualizadas para a versão instalada: falta a coluna `auth_type` em `users`, que o `update_db.php` cria (corrigido na 1.2.4). Rode a atualização; o playbook agora para com erro se as tabelas não estiverem certas. Para conferir: `sudo mariadb qstats -e "SHOW COLUMNS FROM users LIKE 'auth_type'"`.

**Relatórios agendados não chegam por e-mail.** Confira o cron (`/etc/cron.d/asternic`) e os erros em `/var/log/asternic/agendados.log`. Ele chama o `sched_check.php` a cada minuto; o relatório é pedido à própria tela em `http://127.0.0.1/stats/`, que só responde para a própria máquina. O envio usa o servidor de e-mail configurado no Setup do Asternic.

**Os relatórios ficam vazios.** Confira o serviço e se o arquivo cresce:
```bash
systemctl status asterniclog
tail -3 /var/log/asterisk/queue_log
```

---

## Veja também

- [Aplicações: filas, URA e atendimento](13-Aplicacoes-Filas-URA-e-Atendimento.md): os demais campos da fila e o seletor de destinos.
- [Códigos de recurso](15-Codigos-de-Recurso.md): todos os códigos da central e as regras de colisão.
- [Relatórios](18-Relatorios.md): CDR, gravações e desempenho de filas fora do módulo de call center.
- [Administrador](12-Administrador.md): usuários, perfis e permissões.
