# Relatórios — Telium PABX

O histórico da central: cada chamada (CDR), o desempenho das filas, dos ramais e dos troncos, os passos de uma chamada (CEL) e a produtividade dos agentes.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [CDR — Registro de Chamadas](#2-cdr--registro-de-chamadas)
3. [Desempenho de Filas](#3-desempenho-de-filas)
4. [Desempenho de Ramais](#4-desempenho-de-ramais)
5. [Ocupação de Troncos](#5-ocupação-de-troncos)
6. [Eventos de Canal (CEL)](#6-eventos-de-canal-cel)
7. [Produtividade de Agentes](#7-produtividade-de-agentes)
8. [Problemas comuns](#8-problemas-comuns)
9. [Glossário](#9-glossário)

---

## 1. Visão geral

| Tela (menu) | Título na tela | Fonte dos dados | Período |
|---|---|---|---|
| CDR — Registro de Chamadas | CDR — Registro de Chamadas | CDR | De / até (padrão: hoje) |
| Desempenho de Filas | Desempenho de Filas | queue_log | 7, 30 ou 90 dias |
| Desempenho de Ramais | Relatório por Ramal | CDR | De / até (padrão: todo o histórico) |
| Ocupação de Troncos | Relatório por Tronco | CDR | De / até (padrão: todo o histórico) |
| Eventos de Canal (CEL) | Eventos da Chamada | CEL | De / até (padrão: todo o histórico) |
| Produtividade de Agentes | Produtividade de Agentes | queue_log | Últimos 7 dias, fixo |

- **CDR** é uma linha por chamada (ou perna de chamada) gravada pelo Asterisk.
- **queue_log** é o que o Asterisk escreve sobre cada chamada de fila: entrou, foi atendida, desistiu. Por isso os relatórios de fila e de agentes saem dele, e não do CDR.
- **CEL** é cada passo de cada chamada.

Cada tela é um módulo próprio (rel.cdr, rel.filas, rel.ramais, rel.troncos, rel.cel, rel.agentes) em **Administrador › Perfis e Permissões**. A **Auditoria** não fica aqui: está em **Administrador › Auditoria** ([12-Administrador.md](12-Administrador.md)).

O relatório completo do call center (nível de serviço por fila, pausas, ocupação, satisfação, CSV) é **Call Center › Relatórios** ([16-Call-Center.md](16-Call-Center.md)).

> 📷 **Print:** menu lateral aberto na seção **Relatórios**.

---

## 2. CDR — Registro de Chamadas

**Relatórios › CDR — Registro de Chamadas**

Histórico de chamadas entrantes, saintes e internas, da mais nova para a mais antiga.

> 📷 **Print:** CDR com filtros preenchidos e algumas linhas.

### Filtros

Os filtros aplicam sozinhos ao mudar (a busca espera você parar de digitar).

| Filtro | O que faz |
|---|---|
| Data inicial / até | Período, dia inteiro de cada ponta. Ao abrir a tela, vem o dia de hoje (no fuso do seu computador) |
| Todos os sentidos | entrada, saida ou interna |
| Todos os estados | Atendida, Não atendida, Ocupado, Falha, Congestionada |
| Número ou ramal… | Procura o texto na origem, no destino ou na identificação de quem chamou (parte do número serve) |

### Colunas

| Coluna | O que mostra |
|---|---|
| Data / hora | Início da chamada |
| Origem | Número de quem ligou |
| Destino | Número discado, com o botão **Ligar para este número** |
| Sentido | entrada, saida ou interna |
| Duração | Tempo total, desde o início (inclui o tempo chamando) |
| Falado | Tempo de conversa, depois de atendida |
| Tronco | Tronco usado, quando houve |
| Estado | Resultado da chamada; abaixo, quando houver, o motivo registrado |
| Gravação | Botão **Ouvir**, quando a chamada foi gravada; "—" quando não |

| Estado | Significado |
|---|---|
| Atendida | Alguém atendeu |
| Não atendida | Tocou e ninguém atendeu |
| Ocupado | O destino estava ocupado |
| Falha | A chamada não pôde ser completada |
| Congestionada | Sem caminho disponível (rota ou tronco) |

### Ações

| Ação | O que faz |
|---|---|
| Ligar para este número | Liga para a origem, numa chamada de entrada, ou para o destino, nas demais. Usa o telefone do navegador ou o de mesa ([11-Telefone-WebRTC.md](11-Telefone-WebRTC.md)) |
| Ouvir | Abre um player logo abaixo da linha e toca a gravação. Clicar de novo fecha. Só uma gravação toca por vez |
| Setas da paginação | 50 registros por página. O rodapé mostra o total e a página |
| Exportar CSV | Aparece para quem tem a ação Exportar, mas **ainda não exporta**: mostra "Exportação assíncrona ainda não implementada no servidor." |

Para **ouvir** a gravação, o perfil precisa também do módulo **Aplicações › Gravação de Chamadas** (apps.gravacao). Sem ele, a central recusa e a mensagem aparece em vermelho. O CDR não tem botão de baixar: para baixar gravações, use **Aplicações › Gravação de Chamadas** ([13-Aplicacoes-Filas-URA-e-Atendimento.md](13-Aplicacoes-Filas-URA-e-Atendimento.md)).

---

## 3. Desempenho de Filas

**Relatórios › Desempenho de Filas**

Nível de serviço e abandono das filas ativas.

> 📷 **Print:** Desempenho de Filas com o gráfico e a tabela.

### Período

Botões **7 dias**, **30 dias** e **90 dias**, sempre terminando hoje (hoje conta como o último dia).

### Gráfico "Atendidas e não atendidas por dia"

Só chamadas que passaram por fila.

| Série | Conta |
|---|---|
| Atendidas | Chamadas atendidas no dia |
| Não atendidas | Chamadas que entraram no dia menos as atendidas (abandono, tempo esgotado, sem agente, pedido de retorno) |

### Tabela "Por fila"

| Coluna | Definição |
|---|---|
| Fila | Número e nome |
| Agentes | Quantos membros a fila tem cadastrados. Em fila de call center, quantos agentes do cadastro estão ligados a ela. Não é quantos estão logados |
| Recebidas | Chamadas que entraram na fila |
| Atendidas | Chamadas que um agente atendeu |
| Abandonadas | O cliente desligou enquanto esperava |
| TMA | Tempo médio de atendimento: média da conversa das chamadas atendidas e encerradas |
| Atendimento | Nível de serviço: atendidas dentro da **Meta de SLA** da fila ÷ recebidas. Quem desistiu, estourou o tempo ou saiu sem agente conta contra |

Sem meta de SLA configurada na fila, vale 20 segundos.

Cores da coluna **Atendimento**: verde a partir de 90%, amarelo de 75% a 89,9%, vermelho abaixo de 75%. Fila sem chamada no período mostra "sem chamadas".

Só filas **ativas** aparecem. Não há exportação nesta tela.

---

## 4. Desempenho de Ramais

**Relatórios › Desempenho de Ramais** (título na tela: **Relatório por Ramal**)

Quanto cada ramal falou no período e quantas chamadas deixou de atender.

> 📷 **Print:** Relatório por Ramal com algumas linhas e as barras de uso.

### Filtro de período

| Controle | O que faz |
|---|---|
| Período (duas datas) | Data inicial e final. Aplica ao mudar |
| Tudo | Limpa as datas: todo o histórico |

À direita, o texto mostra o período em uso ("todo o histórico" quando sem datas).

### Colunas

| Coluna | Definição |
|---|---|
| Ramal | Número, nome e setor |
| Feitas | Registros do CDR em que o ramal é a origem |
| Recebidas | Registros do CDR em que o ramal é o destino |
| Não atendidas | Recebidas menos as recebidas com resultado Atendida |
| Tempo falado | Soma do tempo de conversa das feitas e das recebidas |
| Barra | Tempo falado comparado ao ramal que mais falou |

Regras:
- Só aparecem ramais **ativos** e com pelo menos uma chamada no período.
- Ordem: quem mais falou primeiro.
- A conta é pelo número do ramal no CDR: uma chamada entre dois ramais conta como feita para um e recebida para o outro.

---

## 5. Ocupação de Troncos

**Relatórios › Ocupação de Troncos** (título na tela: **Relatório por Tronco**)

Volume e qualidade de cada operadora. É o relatório para levar à operadora quando "a linha não completa".

> 📷 **Print:** Relatório por Tronco com a coluna Completamento colorida.

O filtro de período é o mesmo do relatório de ramais ([seção 4](#4-desempenho-de-ramais)).

### Colunas

| Coluna | Definição |
|---|---|
| Tronco | Nome e host |
| Estado | Ativo ou Desativado, conforme o cadastro |
| Chamadas | Registros do CDR que usaram o tronco |
| Atendidas | Com resultado Atendida |
| Ocupado | Com resultado Ocupado |
| Falha | Com resultado Falha mais Não atendida |
| Completamento | Atendidas ÷ Chamadas (ASR). "—" sem chamadas |
| Média | Tempo falado ÷ atendidas (ACD): quanto dura, em média, a chamada atendida |
| Total falado | Soma do tempo de conversa |

Cores do **Completamento**: verde a partir de 60%, amarelo de 40% a 59,9%, vermelho abaixo de 40%.

Como ler (texto da própria tela):
- Completamento abaixo de 40% costuma ser problema da operadora ou da rota, não do PABX.
- Média muito curta com completamento alto costuma ser áudio ruim, com o cliente desligando.

Todos os troncos cadastrados aparecem, mesmo sem chamadas. Chamadas Congestionadas entram no total de **Chamadas**, mas em nenhuma das colunas de resultado.

---

## 6. Eventos de Canal (CEL)

**Relatórios › Eventos de Canal (CEL)** (título na tela: **Eventos da Chamada**)

O CDR diz que a chamada durou doze segundos; o CEL diz por quê. Mostra cada passo que a central registrou: atendeu, transferiu, entrou na ponte, desligou.

### Lista de chamadas

O filtro de período é o mesmo da [seção 4](#4-desempenho-de-ramais). A lista mostra as **50 chamadas mais recentes** do período.

| Coluna | O que mostra |
|---|---|
| Início | Primeiro evento da chamada |
| Origem | Número de quem chamou no primeiro evento |
| Destino | Número discado no primeiro evento |
| Passos | Quantos eventos a chamada teve |
| Identificador | O identificador da chamada (linkedid), que junta todas as pernas |

Clique numa linha para abrir os passos.

> 📷 **Print:** lista de chamadas do CEL.

### Passos de uma chamada

O título vira **Chamada** seguido do identificador.

| Coluna | O que mostra |
|---|---|
| Hora | Hora do evento |
| Evento | Tipo do evento no Asterisk (ex.: CHAN_START, ANSWER, BRIDGE_ENTER, HANGUP) |
| Canal | Canal envolvido |
| Origem | Número de quem chama |
| Destino | Extensão |
| Aplicação | Aplicação e o começo dos parâmetros (até 60 caracteres) |

O botão **Voltar à lista** retorna à lista.

> 📷 **Print:** passos de uma chamada transferida.

---

## 7. Produtividade de Agentes

**Relatórios › Produtividade de Agentes**

Quem atendeu chamadas de fila nos **últimos 7 dias**, pelo queue_log. Não há escolha de período nesta tela.

> 📷 **Print:** Produtividade de Agentes com o ranking e o gráfico.

### Ranking

| Coluna | Definição |
|---|---|
| Agente | Nome. Em fila de call center, o nome do agente do cadastro; em fila comum, o nome do membro (o nome do ramal) |
| Ramal | Em agente de call center, a **matrícula**. Em fila comum, repete o nome do membro, e não o número do ramal |
| Atendidas | Chamadas de fila que o agente atendeu |
| TMA | Conversa total ÷ chamadas encerradas |

Ordem: mais atendidas primeiro; empate, por nome.

### Chamadas atendidas

Gráfico de barras com as atendidas de cada agente.

Para tempo logado, pausas, ocupação, satisfação e tabulações, use **Call Center › Relatórios** ([16-Call-Center.md](16-Call-Center.md)).

---

## 8. Problemas comuns

**O CDR abre vazio.**
- Por padrão ele mostra só o dia de hoje. Ajuste as datas.

**O botão Ouvir dá erro.**
- O seu perfil não tem o módulo **Gravação de Chamadas**. Peça ao administrador.
- O arquivo pode ter sido apagado pela retenção. Veja [04-Backup-e-Restauracao.md](04-Backup-e-Restauracao.md).

**Exportar CSV do CDR só mostra um aviso.**
- A exportação do CDR ainda não está implementada no servidor. Para CSV de filas e agentes, use **Call Center › Relatórios**.

**Uma chamada aparece em várias linhas do CDR.**
- O CDR registra pernas: uma chamada que toca vários destinos ou é transferida gera mais de uma linha. Para ver o caminho completo, use o **CEL**.

**Os números da fila no relatório não batem com o wallboard.**
- O wallboard usa contadores do Asterisk que zeram quando ele reinicia; o relatório conta o período no queue_log. Veja [10-Painel-de-Indicadores.md](10-Painel-de-Indicadores.md).

**A fila não aparece em Desempenho de Filas.**
- Está desativada no cadastro.

**Nível de serviço baixo com poucas chamadas abandonadas.**
- A conta é dura de propósito: atendida fora da meta, tempo esgotado e "sem agente" também contam contra. Confira a **Meta de SLA** da fila em **Aplicações › Filas de Atendimento**.

**Um ramal não aparece no Relatório por Ramal.**
- Não teve chamada no período, ou está inativo.

**Na Produtividade de Agentes, a coluna Ramal mostra um nome.**
- É uma fila comum: o relatório usa o nome do membro da fila. Em fila de call center aparece a matrícula.

**O relatório de troncos mostra zero para um tronco que funciona.**
- As chamadas são ligadas ao tronco pelo nome. Se o tronco foi renomeado, as chamadas antigas ficam com o nome anterior.

---

## 9. Glossário

| Termo | Significado |
|---|---|
| CDR | Registro de detalhes de chamada: uma linha por chamada ou perna |
| CEL | Registro de eventos de canal: cada passo da chamada |
| queue_log | Registro do Asterisk sobre cada chamada de fila |
| Duração | Tempo total, inclui o tempo chamando |
| Falado | Tempo de conversa, depois de atendida |
| TMA | Tempo médio de atendimento |
| SLA / nível de serviço | Porcentagem atendida dentro da meta de tempo da fila |
| ASR / Completamento | Porcentagem das chamadas do tronco que foram atendidas |
| ACD / Média | Duração média da chamada atendida |
| linkedid | Identificador que junta todas as pernas de uma chamada |
