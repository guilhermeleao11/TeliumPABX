# Configurações — Telium PABX

Manual das telas do menu **Configurações**: como a central aplica o que foi salvo, os ajustes globais do SIP, do correio de voz, da música em espera, dos PINs, do fax e do e-mail, a tabela de tarifas e os dados da empresa.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Arquivos e Aplicação](#2-arquivos-e-aplicação)
3. [SIP / PJSIP](#3-sip--pjsip)
4. [Correio de Voz](#4-correio-de-voz)
5. [Música em Espera](#5-música-em-espera)
6. [Conjuntos de PIN](#6-conjuntos-de-pin)
7. [Fax](#7-fax)
8. [Notificações e E-mail](#8-notificações-e-e-mail)
9. [Tabela de Tarifas](#9-tabela-de-tarifas)
10. [Dados da Empresa](#10-dados-da-empresa)
11. [Problemas comuns](#11-problemas-comuns)

---

## 1. Visão geral

O console grava tudo no banco de dados. O Asterisk não lê o banco para a maior parte da configuração: ele lê arquivos `.conf`. Quem transforma um no outro é o botão **Aplicar configurações**: ele escreve os arquivos a partir do cadastro e manda o Asterisk recarregá-los.

**Regra de ouro:** salvou algo que muda o comportamento das chamadas (ramal, tronco, rota, fila, correio de voz, PIN, código de recurso…)? **Aplique.** Até lá, a central continua com a configuração anterior.

| Tela | Para que serve | Precisa aplicar depois? |
|---|---|---|
| Arquivos e Aplicação | Ver o que a central escreve e aplicar | É onde se aplica |
| SIP / PJSIP | Ver o estado do PJSIP, só leitura | Não altera nada |
| Correio de Voz | Regras gerais das caixas postais | Sim |
| Música em Espera | Enviar as músicas das filas | Sim |
| Conjuntos de PIN | Senhas pedidas antes de ligar por uma rota | Sim |
| Fax | Parâmetros da recepção de fax | Veja a [seção 7](#7-fax) |
| Notificações e E-mail | Servidor de saída de e-mail | Não: publica na hora |
| Tabela de Tarifas | Preço de cada tipo de chamada | Não |
| Dados da Empresa | Identificação da central | Veja a [seção 10](#10-dados-da-empresa) |

> 📷 **Print:** menu lateral aberto na seção **Configurações**.

---

## 2. Arquivos e Aplicação

**Configurações › Arquivos e Aplicação**

Mostra quais arquivos do Asterisk a central gera a partir do cadastro, quais ficam reservados para personalização manual e o histórico das últimas aplicações. Tem o botão **Aplicar agora**.

> 📷 **Print:** a tela inteira, com a lista de arquivos gerados e o histórico.

### O que a tela mostra

**Gerados a partir do cadastro** — os arquivos que a central reescreve a cada aplicação, todos em `/etc/asterisk/telium` (o diretório aparece no aviso do topo da tela).

| Coluna | O que mostra |
|---|---|
| Arquivo | Nome do arquivo gerado (ex.: `pjsip.transports.conf`, `queues.conf`, `voicemail.conf`, `musicaespera.conf`) |
| Se aplicar agora | **criado** (o arquivo ainda não existe), **atualizado** (o conteúdo mudaria) ou **inalterado** |
| Tamanho | Tamanho atual no disco |
| Escrito em | Data da última escrita |

No cabeçalho do quadro aparece quantos arquivos **mudariam se aplicar agora**, ou **tudo aplicado**. A comparação ignora a linha de data do cabeçalho do arquivo, então gerar de novo sem mudança não conta como alteração.

**Reservados para personalização** — os arquivos `*_custom.conf` (`pjsip_custom.conf`, `extensions_custom.conf`, `queues_custom.conf`, `confbridge_custom.conf`). O gerador nunca os toca. A coluna **Conteúdo** diz se há algo escrito além de comentários (**tem personalização**) ou se está **vazio**.

**Últimas aplicações** — as 10 mais recentes, com data, resultado (**aplicada** ou **falhou**) e as recargas feitas.

**Importante:**
- **Não edite à mão** os arquivos da primeira lista: a próxima aplicação desfaz a edição.
- Dialplan, endpoint ou fila feitos à mão vão nos `*_custom.conf`.
- Se a API não tiver permissão de escrita no diretório, aparece uma faixa vermelha no topo explicando o problema.

### A faixa "Alterações ainda não aplicadas no Asterisk"

Quando algo que afeta o Asterisk é salvo, aparece uma faixa amarela no topo do console, em qualquer tela:

- **Título:** *Alterações ainda não aplicadas no Asterisk*.
- **Texto:** quantos arquivos serão reescritos, ou, se nenhum arquivo muda, *"O que você salvou está no banco, mas a central ainda opera com a configuração anterior."*
- **Botão:** **Aplicar configurações**, para quem tem a permissão de reiniciar no módulo. Quem não tem vê *"Peça a um administrador para aplicar."*
- O sino de avisos do topo também mostra **Configuração pendente**.

A faixa só aparece para quem tem acesso a **Arquivos e Aplicação**.

> 📷 **Print:** a faixa amarela no topo do console, com o botão **Aplicar configurações**.

### O que acontece ao aplicar

O botão (na faixa ou o **Aplicar agora** desta tela) faz, em ordem:

1. **Gera os arquivos** a partir do banco, só reescrevendo os que mudaram.
2. **Sincroniza as listas na base interna do Asterisk** (as listas que os códigos do telefone também alteram).
3. **Recarrega os módulos**, um por vez, cada um virando uma etapa no resultado:

| Etapa | O que recarrega |
|---|---|
| ramais e troncos | PJSIP |
| dialplan | Plano de discagem |
| filas | Filas de atendimento |
| correio de voz | Caixas postais |
| conferências | Salas de conferência |
| estacionamento | Estacionamento de chamadas |
| música em espera | Classes de música |
| códigos de recurso | Códigos de recurso (features) |
| colunas do CDR | Gravação do registro de chamadas no banco |
| candidatos ICE e portas de voz | RTP, usado pelo telefone do navegador |

4. **Confere se os ramais e troncos carregaram de fato.** Espera até cerca de 4 segundos; se algum não aparecer, a etapa **ramais e troncos carregados** falha e a saída lista quais.

**O resultado fica na própria faixa:**

| Resultado | O que aparece |
|---|---|
| Tudo certo | *Configuração aplicada no Asterisk*, com ✓ em cada etapa. A faixa some sozinha depois de alguns segundos |
| Aplicada, mas com destino quebrado no cadastro | *Configuração aplicada, com N problema(s) no cadastro*, com a lista de onde está cada problema (ex.: uma URA que aponta para algo que foi apagado) |
| Alguma recarga falhou | *O Asterisk recusou parte da recarga*, com ✗ na etapa e o botão **Ver a saída**, que mostra a resposta do Asterisk a cada comando |

**Regras:**
- Duas aplicações ao mesmo tempo não são permitidas. A segunda recebe *"Outra aplicação de configuração está em andamento."*
- Só uma aplicação **sem falha** tira o aviso de pendência.
- A **porta de escuta** do SIP não muda com uma recarga: o Asterisk mantém a porta anterior até ser reiniciado.
- Toda aplicação fica no **Registro de Atividades**.

Pelo servidor, o mesmo é feito com `php bin/telium aplicar-config` (veja [05-Servidor-Comandos-e-Servicos.md](05-Servidor-Comandos-e-Servicos.md)).

---

## 3. SIP / PJSIP

**Configurações › SIP / PJSIP**

Tela **só de leitura**. Mostra o PJSIP como o Asterisk está agora, lido da própria central. Nada se altera aqui: os arquivos do PJSIP são gerados a partir dos cadastros (ramais, troncos, rede).

> 📷 **Print:** a tela com os quadros Transportes e Registros nos provedores.

| Quadro | Comando que a tela roda no Asterisk | Para que serve |
|---|---|---|
| Transportes | `pjsip show transports` | Onde a central escuta SIP |
| Registros nos provedores | `pjsip show registrations` | Se cada tronco está registrado na operadora |
| Ramais e troncos | `pjsip show endpoints` | Estado de cada endpoint |
| Ajustes globais | `pjsip show settings` | Parâmetros globais do PJSIP |
| Custo de conversão entre codecs | `core show translation` | Quanto custa converter um codec em outro |

Se a central não responder, cada quadro mostra *"A central não respondeu. Isso costuma ser o Asterisk parado ou o AMI sem autenticar."*

### Os transportes gerados

| Transporte | Protocolo | Porta padrão |
|---|---|---|
| `transport-udp` | UDP | 5060 |
| `transport-tcp` | TCP | 5060 |
| `transport-tls` | TLS (TLS 1.2) | 5061 |
| `transport-wss` | WebSocket seguro, do telefone do navegador | 8090 (atrás do nginx) |

- O endereço público e as faixas da rede interna (NAT) entram em todos os transportes. Eles se configuram em **Conectividade › Configurações de Rede** (veja [17-Conectividade.md](17-Conectividade.md)).
- O certificado do `transport-tls` é o de `/etc/asterisk/keys`, trocado pelo módulo de certificados do console.

---

## 4. Correio de Voz

**Configurações › Correio de Voz**

Como as caixas postais se comportam. **Quem tem** caixa, a senha e o e-mail de cada uma ficam no cadastro do ramal (ou da caixa postal avulsa).

> 📷 **Print:** a tela com os grupos Gravação, O que a pessoa ouve e E-mail.

### Gravação

| Campo | Padrão | O que faz |
|---|---|---|
| Duração máxima do recado (s) | 180 | Passou disso, a central corta. Só dígitos |
| Duração mínima (s) | 2 | Recado mais curto é descartado (quase sempre engano ou silêncio) |
| Máximo de recados por caixa | 100 | Limite de mensagens guardadas |
| Formatos gravados | `wav49\|gsm\|wav` | Separados por barra vertical. O `wav49` abre no Windows sem instalar nada. Aceita só letras minúsculas, números e barra |
| Tentativas de senha | 3 | Erros de senha antes de a central desligar |

### O que a pessoa ouve

| Campo | O que faz |
|---|---|
| Falar de quem é o recado | Diz o número de quem deixou a mensagem |
| Falar a duração do recado | Diz a duração da mensagem |

### E-mail

| Campo | O que faz |
|---|---|
| Anexar a gravação no e-mail | Manda o áudio junto com o aviso |
| Apagar da caixa depois de enviar por e-mail | O recado passa a existir só no e-mail. Quem ouve pelo telefone perde o acesso |
| Assunto | Aceita `${VM_CALLERID}`, `${VM_NAME}`, `${VM_DATE}` e `${VM_DUR}` |
| Corpo da mensagem | Em branco, a central usa um texto padrão que termina com o nome da empresa |

**Regras e comportamentos:**
- O cadastro de cada ramal tem os próprios ajustes de anexar, apagar, máximo de recados e duração máxima: o que está no ramal vale para a caixa dele.
- O **remetente** do e-mail do recado é o mesmo de **Notificações e E-mail**. Sem envio de e-mail ligado, a central usa `pabx@<domínio da central>`.
- Salvar mostra *"Salvo. Aplique as configurações para a central passar a usar."*
- O envio por e-mail só funciona com **Notificações e E-mail** ligado e testado ([seção 8](#8-notificações-e-e-mail)).

---

## 5. Música em Espera

**Configurações › Música em Espera**

O que o cliente ouve enquanto espera na fila ou fica em espera numa chamada.

> 📷 **Print:** lista de músicas, com a coluna **Em uso**.

### A lista

| Coluna | O que mostra |
|---|---|
| Música | Nome e, abaixo, o nome do arquivo |
| Duração | Duração do áudio |
| Formatos | Formatos gerados na conversão |
| Em uso | Quais filas usam esta música, ou *nenhuma fila usa* |

Ações por linha: **Ouvir** (toca no próprio navegador) e **Excluir**.

### Classes

- A classe **Padrão do sistema** usa os sons que vêm com o Asterisk e está sempre disponível.
- **Cada música enviada vira uma classe própria**, com o mesmo nome do arquivo, e passa a ser uma opção no cadastro da fila. Trocar a música de uma fila não mexe nas outras.
- Um arquivo só basta: a central o repete enquanto durar a espera.

### Enviar música

Clique em **Enviar música**.

| Campo | Regra |
|---|---|
| Nome * | Como a música aparece na lista e na fila. Também dá o nome ao arquivo |
| Arquivo de áudio * | Até 20 MB. Aceita wav, mp3, ogg, gsm, g722, alaw, ulaw, flac e m4a |

- A central converte o áudio para os formatos que o Asterisk toca. Se a conversão falhar, a mensagem pede para conferir se o pacote `sox` está instalado.
- Dois áudios não podem ter o mesmo nome de arquivo.
- Depois de enviar: **aplique as configurações** para as filas passarem a usar a música.

### Excluir

As filas que usavam a música voltam para a música padrão do sistema na próxima aplicação.

---

## 6. Conjuntos de PIN

**Configurações › Conjuntos de PIN**

Senhas que a central pede antes de completar uma chamada. Um conjunto é escolhido numa **rota de saída**: quem discar por ela ouve o pedido de senha antes de a chamada sair. Serve para controlar quem liga para interurbano e internacional sem trancar o ramal inteiro.

> 📷 **Print:** formulário do conjunto de PIN.

| Campo | O que faz |
|---|---|
| Nome do conjunto * | Até 60 caracteres, único. É o nome que aparece na rota de saída |
| PINs * | Um por linha. **Só dígitos**: letra ou símbolo faz a central recusar até o PIN certo. Ao editar, em branco mantém os que já estão gravados |
| Não identificar quem discou | Ligado (padrão): o PIN digitado não vai para o relatório. Desligado: o PIN vai para o registro de chamadas, o que permite cobrar a ligação de um centro de custo e saber quem ligou |

- A coluna **No relatório** mostra *PIN vai para o CDR* quando a identificação está ligada.
- Os PINs nunca voltam para a tela depois de gravados.
- Depois de salvar: **aplique as configurações**.
- Para usar: **Conectividade › Rotas de Saída**, escolha o conjunto na rota (veja [17-Conectividade.md](17-Conectividade.md)).

---

## 7. Fax

**Configurações › Fax**

Recepção de fax pela própria central, sem aparelho. O código `*666` recebe o fax no ramal.

> 📷 **Print:** a tela de Fax com o aviso sobre VoIP no rodapé.

| Campo | Padrão | O que faz |
|---|---|---|
| Receber fax nesta central | ligado | Liga a recepção |
| Mandar o fax recebido para | vazio | E-mail de destino. Precisa ser um e-mail válido |
| Cabeçalho impresso | vazio | Texto do topo da página no aparelho do outro lado |
| Correção de erro (ECM) | ligado | Melhora a recepção em linha ruim |
| Velocidade mínima | 4800 | Em bits por segundo |
| Velocidade máxima | 14400 | Em bits por segundo |

**Como a recepção funciona hoje:** quem disca `*666` faz a central atender e receber o fax, com ECM ligado. O arquivo fica em `/var/spool/asterisk/fax`, em formato TIFF, com a data, a hora e o número de quem mandou no nome.

> **Atenção:** nesta versão, os campos desta tela são gravados, mas **não são lidos pelo gerador de configuração**. O fax recebido **não é enviado por e-mail** e o cabeçalho e as velocidades não são aplicados. Para pegar um fax, busque o arquivo no servidor em `/var/spool/asterisk/fax`.

**T.38 e VoIP:** fax sobre VoIP é frágil. O áudio comprimido destrói o sinal do fax. Funciona melhor com T.38 no tronco e codec G.711; com celular ou compressão agressiva, falha de forma intermitente. A central não liga o T.38 sozinha: isso depende do tronco e da operadora.

---

## 8. Notificações e E-mail

**Configurações › Notificações e E-mail**

Por onde a central manda e-mail. A central **não roda servidor de e-mail próprio**: ela entrega as mensagens a um servidor de saída (relay) do seu provedor, pelo `msmtp`.

> 📷 **Print:** a tela com o formulário **Servidor de saída** e o quadro **Conferir**.

### Servidor de saída

| Campo | O que faz |
|---|---|
| Enviar e-mail por este servidor | Liga o envio. A etiqueta do quadro mostra **ligado** ou **desligado** |
| Servidor | O relay do provedor (ex.: `smtp.gmail.com`). Obrigatório com o envio ligado |
| Porta | Padrão 587 |
| Segurança | **STARTTLS (587)** — o mais comum; **TLS direto (465)**; **Nenhuma (25, rede interna)** |
| Usuário | Em branco, a central não autentica (só serve em relay interno) |
| Senha | Nunca volta para a tela. Em branco mantém a atual. No Gmail e no Microsoft 365 é uma **senha de aplicativo**, não a senha de entrada |
| Remetente (De:) | Obrigatório com o envio ligado, precisa ser um e-mail válido. Muitos provedores recusam remetente de outro domínio |
| Nome do remetente | Nome que aparece no "De:" (ex.: *Central Telefônica*) |

**Ao salvar**, a central publica a configuração do `msmtp` na hora (não precisa aplicar):
- Deu certo: *"Configuração publicada. O Asterisk já usa este servidor para mandar os recados."*
- Não deu: *"Gravado no banco, mas não foi possível publicar a configuração no servidor."* Isso indica que o instalador não rodou nesta máquina (falta o script `/usr/local/sbin/telium-smtp` ou a regra de sudo dele).

### Conferir (teste de envio)

1. Ligue o envio e salve. Antes disso, o campo de teste fica desabilitado.
2. Em **Mandar para**, digite um e-mail.
3. Clique em **Enviar teste**.

- A central **publica a configuração antes de testar**. Se não conseguir publicar, o teste não é feito (para não testar a configuração antiga).
- A mensagem tem o assunto *"Teste de e-mail da central <nome da empresa>"*.
- O resultado aparece embaixo do botão e fica registrado: a etiqueta mostra **último teste entregue** ou **último teste falhou**, com data e hora, ou **nunca testado**.
- Se falhou, a resposta do servidor aparece na tela, para diagnóstico.
- *"Entregue"* quer dizer entregue ao servidor de saída. Confira a caixa de destino e o spam.

### Quem usa o e-mail

| Uso | Onde se liga |
|---|---|
| Cópia do recado do correio de voz | No cadastro do ramal ou da caixa postal, e em [Correio de Voz](#4-correio-de-voz) |
| Ditado enviado pelo código `*35` | No cadastro do ramal |

---

## 9. Tabela de Tarifas

**Configurações › Tabela de Tarifas**

O que a operadora cobra por cada tipo de chamada. É com ela que a tela **Telium › Tarifação** calcula o custo (veja [20-Telium-Tarifacao-Integracoes-Suporte.md](20-Telium-Tarifacao-Integracoes-Suporte.md)).

**A classe da chamada vem da rota de saída** que a completou, não é adivinhada pelo número. Cada rota de saída tem uma classe (local, celular, DDD, DDI, emergência, especial).

> 📷 **Print:** lista de tarifas, com a etiqueta **sem preço** em alguma linha.

### Tarifas que já vêm prontas

Todas nascem **com preço zero**: falta digitar os valores do seu contrato.

| Nome | Aplica-se a | Mínimo cobrado | Fração |
|---|---|---|---|
| Emergência | Emergência | 0 s | 6 s |
| 0800 e gratuitos | Qualquer chamada, só `_0800.` | 0 s | 6 s |
| Fixo local | Fixo local | 30 s | 6 s |
| Celular | Celular | 30 s | 6 s |
| Interurbano (DDD) | Interurbano (DDD) | 30 s | 6 s |
| Internacional | Internacional (DDI) | 30 s | 6 s |
| Especiais | Especiais | 30 s | 6 s |

### Campos

| Campo | O que faz |
|---|---|
| Nome * | Como aparece na lista |
| Aplica-se a | A classe: Qualquer chamada, Fixo local, Celular, Interurbano (DDD), Internacional (DDI), Emergência ou Especiais |
| Só para destinos assim (opcional) | Padrão de número, na mesma escrita das rotas de saída: `X` um dígito, `N` de 2 a 9, `Z` de 1 a 9, `.` o resto. Preenchido, vence a tarifa da classe inteira |
| Custo por minuto (R$) | Até 4 casas decimais |
| Taxa fixa por chamada (R$) | Cobrada uma vez, além do tempo. Zero na maioria dos planos |
| Mínimo cobrado (s) | No Brasil costuma ser 30: uma chamada de 5 s custa 30 s. Zero desliga o mínimo |
| Depois, de quantos em quantos (s) | 6 é o padrão brasileiro. 60 cobra minuto cheio; 1 cobra por segundo |
| Ordem | Desempate entre tarifas que servem à mesma chamada. Menor vem primeiro |
| Tarifa ativa | Inativa não é usada |

### Qual tarifa vale para uma chamada

1. Só entram as tarifas **ativas** cuja classe é a da chamada ou **Qualquer chamada**.
2. Se a tarifa tem padrão, o número discado precisa casar com ele por inteiro (um padrão sem curinga casa só com o número igual).
3. Entre as que sobram, **a que tem padrão vence** a que não tem; depois vale a menor **Ordem**.

Exemplo: "0800 e gratuitos" (com padrão `_0800.`) vence "Fixo local" mesmo sendo cadastrada antes ou depois.

### Como o custo é calculado

- Chamada não atendida não custa.
- Tempo cobrado: se a conversa durou até o mínimo, cobra o mínimo; depois, arredonda para cima de fração em fração.
- Custo = taxa fixa + (tempo cobrado ÷ 60) × custo por minuto.

Exemplo, com mínimo de 30 s e fração de 6 s: 5 s viram 30 s; 31 s viram 36 s.

**Importante:** uma chamada já tarifada **não é recalculada**. O preço fica congelado no que valia quando ela aconteceu. Mudar o preço vale para as chamadas tarifadas daqui em diante.

---

## 10. Dados da Empresa

**Configurações › Dados da Empresa**

Identificação da central. Aparece em relatórios, no portal do usuário e em alguns pontos do funcionamento.

> 📷 **Print:** a tela com Identificação e Licenciamento.

### Identificação

| Campo | O que faz |
|---|---|
| Nome fantasia * | Nome da empresa. Vai no rodapé do e-mail do correio de voz e no assunto do e-mail de teste |
| Razão social | Cadastro |
| CNPJ | Cadastro |
| Telefone principal | **Número que o cliente vê nos retornos do call center** (veja abaixo) |
| Service ID | Identificador do serviço contratado, no sistema da operadora |
| Client ID | Identificador do cliente, no sistema da operadora |
| Endereço | Cadastro |
| Fuso horário | America/Sao_Paulo, America/Manaus, America/Belem ou America/Cuiaba |
| Idioma | pt_BR, en_US ou es_ES |
| Plano | Plano contratado |
| Ramais contratados | Número inteiro |

O **Service ID** e o **Client ID** servem para o suporte casar a central com o cliente sem perguntar.

### Licenciamento

Quadro só de leitura: o **Plano**, o medidor **Ramais em uso** (usados / contratados, vermelho acima de 90%) e o número de **Troncos ativos**.

### Telefone principal e o call center

Quando o call center liga de volta para um cliente (retorno), o número que aparece para o cliente é o **Telefone principal**, só com os dígitos. Sem telefone cadastrado, a central não troca o número de origem do retorno. Veja [16-Call-Center.md](16-Call-Center.md).

**Regras:**
- Nenhum campo aceita `${` ou `$[` (o texto também é lido pelo Asterisk).
- **Ramais contratados** só aceita número inteiro.
- O telefone e o nome entram em arquivos gerados. Salvar esta tela **não acende** a faixa de pendência: depois de trocar o telefone ou o nome, use **Aplicar agora** em [Arquivos e Aplicação](#2-arquivos-e-aplicação).

---

## 11. Problemas comuns

**Salvei e nada mudou no telefone.**
Falta aplicar. Use a faixa amarela ou **Configurações › Arquivos e Aplicação › Aplicar agora**. Algumas telas (música, dados da empresa) não acendem a faixa: aplique pela tela de Arquivos.

**A aplicação diz "O Asterisk recusou parte da recarga".**
Clique em **Ver a saída** e veja qual comando falhou. Se a etapa foi **ramais e troncos carregados**, a saída lista os ramais ou troncos que o Asterisk descartou; o motivo está no log do Asterisk (veja [06-Diretorios-Arquivos-e-Logs.md](06-Diretorios-Arquivos-e-Logs.md)).

**"Outra aplicação de configuração está em andamento."**
Alguém (ou outra aba) está aplicando. Espere alguns segundos e tente de novo.

**Editei um arquivo em /etc/asterisk/telium e a mudança sumiu.**
Esses arquivos são reescritos a cada aplicação. Use os `*_custom.conf`.

**A tela SIP / PJSIP diz que a central não respondeu.**
O Asterisk está parado ou o AMI não autentica. Veja [07-Solucao-de-Problemas.md](07-Solucao-de-Problemas.md).

**O recado do correio de voz não chega por e-mail.**
1. **Notificações e E-mail** está ligado e o último teste foi entregue?
2. O ramal tem e-mail e a cópia por e-mail ligada?
3. O remetente é do mesmo domínio da conta autenticada?
4. Confira o spam.

**O teste de e-mail falha com erro de autenticação.**
No Gmail e no Microsoft 365, use uma senha de aplicativo. Confira a porta e a segurança (587 com STARTTLS, 465 com TLS direto).

**"Não foi possível publicar a configuração de envio no servidor."**
O script `/usr/local/sbin/telium-smtp` ou a regra de sudo dele não existem. Rode o instalador de novo (veja [01-Instalacao.md](01-Instalacao.md)).

**A fila toca silêncio em vez da música.**
A música foi enviada mas as configurações não foram aplicadas. Aplique pela tela de Arquivos.

**O PIN certo é recusado.**
O conjunto tem caractere que não é dígito, ou as configurações não foram aplicadas depois de editar.

**O fax recebido não chegou por e-mail.**
Nesta versão o fax não é enviado por e-mail: busque o arquivo em `/var/spool/asterisk/fax`.

**O fax falha no meio.**
Tronco sem T.38 ou com codec comprimido. Use G.711 e T.38 no tronco, se a operadora suportar.

**O relatório de custos fecha em R$ 0,00.**
As tarifas estão com preço zero. Digite os valores em **Tabela de Tarifas** e use **Recalcular o mês** em **Telium › Tarifação**.
