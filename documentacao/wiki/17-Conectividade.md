# Conectividade — Telium PABX

Manual da seção **Conectividade** do console: ramais, troncos, rotas de entrada e de saída, numeração, firewall, provisionamento de telefones, rede e softphone do navegador.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Ramais](#2-ramais)
3. [Troncos](#3-troncos)
4. [Rotas de Entrada](#4-rotas-de-entrada)
5. [Rotas de Saída](#5-rotas-de-saída)
6. [DIDs / Numeração](#6-dids--numeração)
7. [Firewall](#7-firewall)
8. [Provisionamento](#8-provisionamento)
9. [Configurações de Rede](#9-configurações-de-rede)
10. [WebRTC / Softphone](#10-webrtc--softphone)
11. [Exemplo de ponta a ponta](#11-exemplo-de-ponta-a-ponta)
12. [Problemas comuns](#12-problemas-comuns)

---

## 1. Visão geral

A seção **Conectividade** é onde a central fala com o mundo: os aparelhos (ramais), as operadoras (troncos) e o caminho que cada chamada faz para entrar e sair.

| Tela | Para que serve |
|---|---|
| Ramais | Cadastro de cada ramal SIP: senha, permissões de discagem, gravação, correio de voz, rede, mídia, WebRTC |
| Troncos | Entroncamento SIP com a operadora |
| Rotas de Entrada | Para onde vai cada número recebido (DID), e opcionalmente cada origem |
| Rotas de Saída | Por qual tronco sai cada número discado, com quais prefixos e quem pode usar |
| DIDs / Numeração | Lista, só de leitura, de todos os números que chegam à central |
| Firewall | Tentativas de invasão, endereços bloqueados e lista de "nunca bloquear" |
| Provisionamento | Configuração automática de telefones Grandstream, Yealink e Fanvil, e QR Code para o Linphone |
| Configurações de Rede | Endereço público (NAT) e faixas da rede interna |
| WebRTC / Softphone | Liga ou desliga o telefone do navegador e diagnostica o caminho do áudio |

**Ordem recomendada numa implantação nova:**

1. **Configurações de Rede:** se a central está atrás de NAT, preencher o endereço público.
2. **Troncos:** cadastrar a operadora e conferir a coluna **Na central**.
3. **Ramais:** cadastrar (um a um ou pela planilha CSV).
4. **Rotas de Saída:** pelo menos emergência, fixo local, celular e DDD.
5. **Rotas de Entrada:** o número principal para a URA ou para a fila.
6. **Aplicar configurações** (faixa amarela no topo do console).

**Sobre "Aplicar configurações":** tudo o que é salvo nestas telas vai para o banco. A central só passa a usar depois de **Aplicar configurações**, na faixa amarela "Alterações ainda não aplicadas no Asterisk". Só quem tem a permissão de reiniciar vê o botão; os outros veem "Peça a um administrador para aplicar". As exceções, que valem na hora, estão indicadas em cada seção (firewall, liga/desliga do WebRTC, segredo do provisionamento).

> 📷 **Print:** menu lateral aberto na seção **Conectividade**.

---

## 2. Ramais

**Conectividade › Ramais**

Cadastro dos ramais SIP. Cada ramal é um número que se disca internamente e uma conta (usuário e senha) que o aparelho ou softphone usa para registrar na central.

A lista mostra **Ramal**, **Nome**, **Setor**, **Tecnologia**, **Correio** (se tem correio de voz), **Gravação** e **Estado** (Ativo/Inativo). Dá para buscar por número, nome ou setor e filtrar por setor.

> 📷 **Print:** lista de ramais com o filtro de setor aberto.

### Botões da lista

| Botão | O que faz |
|---|---|
| Adicionar ramal | Abre o formulário em branco |
| Modelo CSV | Baixa a planilha modelo, com as colunas aceitas e dois exemplos |
| Exportar | Baixa todos os ramais em CSV, **sem as senhas** (a exportação fica na auditoria). É a planilha que pode circular |
| Exportar com senhas | A mesma planilha **com** a senha SIP e a do correio de voz, para configurar os aparelhos. Só para quem pode editar ramais; pede confirmação e fica na auditoria como "exportar com senhas". Quem tiver o arquivo registra um telefone em qualquer ramal: não mande por e-mail e apague depois de usar |
| Importar CSV | Abre a importação em lote (veja abaixo) |
| Chave (em cada linha) | **Credenciais SIP:** mostra usuário, senha e transporte do ramal. A consulta fica registrada na auditoria |

### Aba Geral

| Campo | O que faz |
|---|---|
| Ramal do usuário * | O número que se disca. Só dígitos, de 2 a 10. **Não muda depois de criado** |
| Nome de exibição * | Aparece no visor de quem recebe a chamada. Até 120 caracteres |
| Setor / centro de custo | Agrupa ramais na lista e nos filtros |
| E-mail | Recebe os recados do correio de voz |
| Senha SIP * | Mínimo de **12 caracteres**. Ao editar, em branco mantém a atual. O botão **Gerar senha** cria uma senha forte e mostra **Copiar**; ela não é mostrada de novo depois |
| PIN do usuário | Só dígitos. Pedido onde a central exige confirmação, como nas rotas com senha. Ao editar, em branco mantém |
| Código da conta | Vai para o registro de chamadas (CDR); serve para separar custo por cliente ou projeto |
| Ramal ativo | Ramal inativo não é publicado na central |

> 📷 **Print:** formulário do ramal, aba **Geral**, com a senha recém-gerada.

### Aba Identificação

| Campo | O que faz |
|---|---|
| DID atribuído | Número público que cai direto neste ramal. A central cria a rota sozinha (veja [DIDs / Numeração](#6-dids--numeração)) |
| Descrição do DID | Como o número aparece na lista de DIDs |
| CID de entrada | Prefixo mostrado no visor quando a chamada chega por este DID |
| Número de saída (pseudo CID) | O número que a operadora recebe como origem nas ligações deste ramal. Vence a identificação de saída do tronco |
| Apelido SIP | Um segundo nome pelo qual a central reconhece o ramal |
| Contexto | **interno — discagem normal** ou **telium-bloqueado — sem saída**. Outros valores são recusados, porque fariam o ramal pular a checagem de permissão |
| Contexto personalizado | Só para quem escreveu um contexto próprio no `extensions_custom.conf`. Substitui o de cima. Letras, números, `-` e `_`, até 40 caracteres; não pode começar com `telium` nem ser `interno`, `default`, `de-tronco` ou começar com `sub-` |

### Aba Voz

| Campo | O que faz |
|---|---|
| Correio de voz | Liga a caixa postal do ramal (padrão: ligado) |
| Senha do correio | Só dígitos. Em branco, a senha é o próprio número do ramal |
| Enviar recados por e-mail | Manda o recado para o e-mail do ramal |
| Tempo de toque (s) | Quanto tempo toca antes de desviar (padrão 20) |
| Tempo de toque no siga-me (s) | Padrão 20 |
| Siga-me | Ramal ou número externo (2 a 20 caracteres: dígitos, `*`, `#`, `+`). O modo e os detalhes ficam em **Aplicações › Siga-me** |
| Não perturbe | O ramal não recebe chamadas |
| Chamada em espera | Desligada, a segunda chamada ouve ocupado |
| Bipe ao chegar segunda chamada | Aviso sonoro da chamada em espera |
| Atender sozinho chamada interna | O aparelho abre o viva-voz sem tocar |
| Interfonia | Aceita ou recusa ser chamado em interfonia |
| Limite de chamadas simultâneas de saída | Teto de chamadas externas ao mesmo tempo. **0 deixa sem limite** |
| Contar estado do ramal nas filas | Desligado, a fila oferece chamada mesmo com o ramal ocupado |
| Permitir rastrear a última chamada | Libera o código `*69` neste ramal |
| Aparece no diretório | Inclui o ramal no diretório |
| Opções do Dial | Passadas direto ao Asterisk. Em branco, a central usa `tT` |

### Aba Gravação

Quatro regras, uma para cada sentido: **Externa recebida**, **Externa feita**, **Interna recebida** e **Interna feita**. Cada uma aceita:

| Opção | Efeito |
|---|---|
| Não importa | Quem decide é a fila ou a rota |
| Sim, gravar | Grava |
| Não gravar | Não grava |
| Forçar | Grava mesmo se outra regra disser que não |
| Nunca | Não grava nem se outra regra mandar |

**Quando os dois lados discordam**, a ordem é fixa: "nunca" de qualquer lado proíbe; "forçar" de qualquer lado obriga; só então vale a preferência de cada um.

| Campo | O que faz |
|---|---|
| Gravação sob demanda | **Não permitir**; **Permitir o código \*1 durante a chamada**; ou **Permitir e deixar vencer as regras acima** |
| Regra antiga (compatibilidade) | Mantida para instalações antigas. Quem vale são as quatro regras acima |

### Aba Rede

| Campo | O que faz |
|---|---|
| Transporte | UDP (padrão), TCP ou TLS. Com WebRTC ligado, vira WSS sozinho |
| Verificar o aparelho a cada (s) | Intervalo do teste de presença (padrão 60; 0 desliga) |
| Máximo de aparelhos | Quantos aparelhos registram com este ramal ao mesmo tempo (padrão 2) |
| Derrubar o registro mais antigo ao encher | Padrão ligado |
| Registro: expiração mínima / máxima (s) | Padrão 60 e 3600 |
| Forçar rport, Reescrever contato, RTP simétrico | Ajustes de NAT, ligados por padrão |
| Usar o transporte de onde veio | Para aparelho atrás de NAT com porta variável |
| Proxy de saída | Ex.: `sip:proxy.operadora.com.br` |
| Restringir a redes | Faixas de onde o ramal pode falar, ex.: `10.0.0.0/8, 192.168.0.0/16`. Fora delas, o ramal é recusado |
| Contexto de mensagens (SIP MESSAGE) | Contexto para mensagens de texto SIP |

### Aba Mídia

| Campo | O que faz |
|---|---|
| Codecs permitidos | Em ordem de preferência. Padrão `opus,alaw,ulaw,g722`. `alaw` é o padrão no Brasil |
| Codecs recusados | Codecs que o ramal nunca usa |
| Sinalização DTMF | RFC 4733 (padrão), inband, SIP INFO, automático, automático com INFO |
| Mídia direta entre aparelhos | Tira o Asterisk do caminho do áudio. Só em rede local e sem gravação |
| Endereço de mídia | Endereço anunciado para o áudio |
| Fluxos de áudio / vídeo | Padrão 1 e 0 |
| Desligar sem áudio por (s) / Idem, em espera (s) | 0 desliga a checagem |
| Exigir mídia criptografada (SRTP) | Só aceita áudio cifrado |
| Aceitar mídia sem criptografia | Tenta cifrar e aceita sem, se o outro lado não souber |
| Temporizador de sessão / Renovar a sessão a cada (s) | Usar quando o outro lado usa, não usar ou exigir; padrão 1800 s |

### Aba WebRTC

| Campo | O que faz |
|---|---|
| Softphone do navegador | Transforma o ramal em ramal de navegador (veja abaixo) |
| AVPF, Suporte a ICE, rtcp-mux, DTLS | Ligados junto com o WebRTC. Aqui só para um aparelho que peça isso sem ser navegador |
| Verificação do DTLS | Impressão digital (padrão), certificado, ambos, nenhuma |
| Papel no DTLS | actpass (padrão), active, passive |
| Trocar a chave DTLS a cada (s) | 0 não troca |

**O que acontece ao marcar "Softphone do navegador":** ao salvar, a central liga sozinha DTLS, AVPF, ICE, rtcp-mux e SRTP, troca o transporte para WSS, põe **Máximo de aparelhos = 1**, verificação DTLS por impressão digital, papel actpass e desliga a mídia direta. Os codecs ficam só entre os que o navegador fala (`opus`, `g722`, `ulaw`, `alaw`); se nenhum sobrar, vira `opus,ulaw,alaw`. Os codecs recusados são limpos.

**Um ramal serve a um tipo de aparelho só.** Ramal WebRTC não atende softphone comum, e vice-versa. Para a mesma pessoa usar os dois, crie dois ramais e use o **Siga-me** no modo "junto". Veja [WebRTC / Softphone](#10-webrtc--softphone) e [Telefone WebRTC](11-Telefone-WebRTC.md).

### Aba Avançado

| Campo | O que faz |
|---|---|
| Grupos de chamada | Ex.: `1,3-5`. Quem está no mesmo grupo pode ser capturado |
| Grupos de captura | Grupos que este ramal pode capturar |
| Confiar no RPID recebido / Enviar RPID / Enviar P-Asserted-Identity | Identificação de chamada no SIP |
| Enviar identificação de quem atendeu | Padrão ligado |
| Marcar user=phone na URI | Para equipamentos que exigem |
| Avisar progresso na transferência cega | Padrão ligado |
| Aviso de recado (MWI) | Automático, só quando o aparelho pede, ou enviar sem pedir |
| Agregar as caixas num aviso só | Um aviso de recado para todas as caixas |
| Serviço de ditado | Libera os códigos `*34` e `*35` neste ramal |
| Formato, E-mail e Remetente do ditado | WAV, GSM ou OGG; para onde e de quem o ditado é enviado |

### Aba Permissões (permissões de discagem)

| Campo | Libera as rotas da classe |
|---|---|
| Ligações locais e 0800/0300 | `local` e `especial` (padrão: ligado) |
| Celular | `celular` (padrão: ligado) |
| DDD nacional | `ddd` (padrão: ligado) |
| Internacional | `ddi` (padrão: desligado) |

- **Emergência é sempre permitida**, para qualquer ramal.
- A permissão é conferida contra a **Classe** da rota de saída ([seção 5](#5-rotas-de-saída)). Sem permissão, quem disca ouve que a chamada não pode ser completada.
- A permissão acompanha o ramal no siga-me, no desvio e na transferência às cegas: um ramal só-local não consegue transferir uma chamada para um número internacional.

| Campo | O que faz |
|---|---|
| Grupos de saída | Opcional. Nomes curtos, sem espaço, separados por vírgula (`NOC` ou `NOC,VoIP`). As rotas de saída marcadas **Só para** um desses grupos valem para este ramal: para o mesmo número, ele sai por elas, e não pela rota geral ([seção 5](#5-rotas-de-saída)) |

> 📷 **Print:** aba **Permissões** de um ramal só-local.

### Regras e validações

- Número do ramal **único**, só dígitos, de 2 a 10.
- Senha SIP com **pelo menos 12 caracteres**. Ramal sem senha não é publicado na central: ele simplesmente não registra. Só a migração do SNEP traz senha mais curta que 12 (a mesma do SNEP, para o telefone não ser reconfigurado); troque-a por uma forte quando puder.
- Senha do correio e PIN: só dígitos.
- Ao excluir, o ramal perde o acesso assim que a configuração for aplicada. O histórico de chamadas é preservado.

### Importar ramais pela planilha (CSV)

1. Baixe o **Modelo CSV**. A primeira linha tem os nomes das colunas: `numero`, `nome`, `setor`, `email`, `senha_sip`, `webrtc`, `voicemail`, `vm_senha`, `vm_email`, `perm_local`, `perm_celular`, `perm_ddd`, `perm_ddi`, `gravar`, `callgroup`, `pickupgroup`, `cid_pseudo`, `did`, `max_saidas`, `contexto`, `ativo`.
2. Só `numero` e `nome` são obrigatórias; as outras podem sair da planilha.
3. Colunas de sim/não aceitam `sim`, `não`, `1` ou `0`.
4. **Sem `senha_sip`, a central gera uma senha forte** e a mostra no fim.
5. Célula vazia não muda nada: num ramal novo vale o padrão do cadastro; num existente, fica o que estava.
6. Em **Importar CSV**, escolha o arquivo. A **prévia** aparece sozinha, linha a linha: **Criar**, **Atualizar**, **Já existe** ou **Erro**, com o motivo. Nada é gravado ainda.
7. Marque **Atualizar os ramais que já existem** se quiser mexer nos existentes (sem isso eles ficam como estão).
8. Clique em **Importar**. As linhas com erro ficam de fora.
9. Se houve senhas geradas, clique em **Baixar ramais e senhas** **na hora**: depois elas só voltam pela tela de credenciais de cada ramal.
10. **Aplique as configurações.**

Limites: até 2 MB e 2.000 ramais por arquivo; separador `;` ou `,`; UTF-8 ou o formato salvo pelo Excel. A importação passa pelas mesmas validações do formulário. O **Exportar** nunca traz `senha_sip` nem `vm_senha`; o **Exportar com senhas** traz as duas, e a planilha volta na importação com as mesmas senhas.

> 📷 **Print:** prévia da importação, com uma linha em erro e o aviso de senhas geradas.

### Configurar o aparelho ou o softphone

Use o botão de chave (**Credenciais SIP**) da linha do ramal:

| Dado | Onde vai no aparelho |
|---|---|
| Usuário | Número do ramal (usuário e usuário de autenticação) |
| Senha | Senha SIP |
| Transporte | UDP, TCP, TLS ou WSS |
| Servidor | O endereço da central (IP ou nome) |

Para telefones Grandstream, Yealink e Fanvil, prefira o [Provisionamento](#8-provisionamento). Para o Linphone no Android, use o QR Code da mesma tela. Passo a passo manual, com figuras, para o Yealink T20/T20P: [Telefone Yealink T20](22-Telefone-Yealink-T20.md).

---

## 3. Troncos

**Conectividade › Troncos**

O entroncamento com a operadora. Sem tronco a central só faz chamadas internas.

A lista mostra **Tronco** (nome, host e porta), **Canais**, **Registra**, **No cadastro** (Ativo/Inativo) e **Na central**, que é o estado lido do próprio Asterisk. No topo aparece "N troncos fora do ar" quando algum tronco ativo não está no ar, ou "central sem resposta" se o Asterisk não respondeu.

> 📷 **Print:** lista de troncos com a coluna **Na central** em verde num tronco e em vermelho em outro.

### Tipos de tronco

A central entronca **somente por SIP (PJSIP)**. Placa analógica ou E1 exigiria `chan_dahdi`, que não faz parte desta instalação, e o cadastro recusa outro tipo.

| Jeito de conectar | Como cadastrar |
|---|---|
| **Por registro** (a operadora dá usuário e senha e a central se registra nela) | Preencha **Usuário de autenticação** e **Senha** e deixe **Registrar no provedor** ligado |
| **Por IP** (a operadora libera o IP da central, sem registro) | Desligue **Registrar no provedor**. Usuário e senha só se a operadora pedir autenticação nas chamadas |

Nos dois casos, as chamadas que chegam do **host** cadastrado são reconhecidas como vindas deste tronco.

### Campos

| Campo | O que faz |
|---|---|
| Nome do tronco * | Até 60 caracteres. É como o tronco aparece nas rotas e no CDR |
| Tipo | SIP (PJSIP), único aceito |
| Host da operadora * | Ex.: `sip.operadora.com.br` |
| Porta | Padrão 5060 |
| Transporte | udp (padrão), tcp ou tls |
| Canais contratados | Informativo: aparece na lista e no painel |
| Usuário de autenticação | Conta da operadora |
| Senha / Nova senha | Ao editar, em branco mantém a atual |
| Registrar no provedor | Liga o registro (padrão ligado). Só funciona com usuário **e** senha |
| Tronco ativo | Tronco inativo não é publicado |
| From user | A parte antes do `@` no From. Sem `@` e sem espaço. Em branco, vale a identificação de saída |
| From domain | Domínio ou IP da operadora. Em branco, vale o host. **Não pode ser só número**: o número da conta vai em **From user** (senão a operadora responde 404) |
| Identificação de saída | O número que a operadora vê nas chamadas que saem por este tronco, quando o ramal não tem número de saída próprio |
| Codecs | Padrão `alaw,ulaw` |
| Tirar do começo do número recebido | Ex.: `+55`. Só é retirado quando está lá |
| Ficar só com os últimos N dígitos | 0 desliga. Com 8, `1133255800` vira `33255800`. Número mais curto fica como está |
| Contexto de entrada | Deixe `de-tronco`. Só mude para mandar as chamadas a um contexto seu, escrito à mão |

### Codecs

O Asterisk **não transcodifica g729**, só repassa. Com g729 no tronco e um ramal em opus ou alaw, a operadora atende e a chamada cai na hora. Só acrescente g729 se a central inteira falar g729.

### DID e identificação de saída

- **Número recebido (DID):** cada operadora entrega num formato. Acerte isso **uma vez no tronco**, com **Tirar do começo** e **Ficar só com os últimos N dígitos**, em vez de cadastrar a mesma rota de entrada em três formatos. O `+` do formato internacional sai sempre, antes de tudo, então `+55` e `55` são o mesmo pedido.
- **Chamada sem número:** quando o tronco entrega a chamada sem DID, ela vai para a rota de entrada "qualquer número".
- **Identificação de saída:** vale, nesta ordem, o **Número de saída** do ramal; se vazio, a **Identificação de saída** do tronco. Sem nenhum dos dois a chamada pode sair sem número e ser recusada pela operadora.

### Diagnóstico de registro (coluna "Na central")

| O que aparece | O que significa / o que fazer |
|---|---|
| no ar | Tudo certo |
| no ar (sem teste de resposta) | Funcionando, sem o teste de presença |
| sem resposta da central | O console não falou com o Asterisk |
| desativado no cadastro | **Tronco ativo** está desligado |
| não publicado — aplique as configurações | Salvo e não aplicado |
| usuário de autenticação sem senha — informe a senha da operadora | Falta a senha |
| marcado para registrar, mas sem usuário e senha da operadora | Preencha a conta ou desligue **Registrar no provedor** |
| a central não carregou a autenticação deste tronco — aplique de novo | Aplicar outra vez |
| publicado, registro ainda não tentado | Aguarde alguns segundos |
| registrando na operadora… | Em andamento |
| operadora recusou o registro — confira usuário e senha | Credencial errada ou não liberada |
| publicado, operadora não registrou o tronco | Registro não aconteceu |
| publicado, operadora não respondeu ao teste | Host, porta, firewall ou NAT |

Comportamento automático do registro: nova tentativa a cada 60 s; depois de uma recusa por credencial, a cada 10 minutos.

> 📷 **Print:** formulário do tronco preenchido para uma operadora com registro.

---

## 4. Rotas de Entrada

**Conectividade › Rotas de Entrada**

Para onde vai cada número recebido das operadoras. Sem rota, a chamada que chega não tem destino: quem liga ouve o aviso de serviço indisponível.

A lista mostra **Ordem**, **Número chamado** (ou "qualquer"), **De quem liga** (ou "qualquer origem"), **Destino**, com o **nome** do destino (ex.: o nome da URA ou da fila), e **Opções** (grava, barra oculto, prefixo, toque próprio, pausa).

> 📷 **Print:** lista de rotas de entrada com o teste "Uma chamada para o número" preenchido.

### Teste "Uma chamada para o número… vinda de…"

No topo da tela. Digite o número **como a operadora entrega, depois do ajuste do tronco**, e, se quiser, a origem. A tela responde na hora: "Vai para **<destino>**, pela rota …", ou que nenhuma rota pega a chamada.

### Como a rota é escolhida

1. Primeiro pelo **número chamado**: número exato vence padrão, que vence "qualquer número".
2. Entre rotas do mesmo número, por **quem ligou**: origem exata vence padrão, que vence a rota sem origem.
3. **Ordem de avaliação** só desempata entre rotas que servem à mesma chamada.

### Aba Rota

| Campo | O que faz |
|---|---|
| Número chamado (DID) | O número que a operadora entrega. Em branco, `*` ou `X.` vale para **qualquer número**, inclusive tronco que não entrega número. Aceita padrão: `113325xxxx` pega a faixa |
| Descrição | Ex.: "Comercial 0800" |
| Só quando quem liga for | Em branco, qualquer origem. Preenchido, só para esse número ou padrão (ex.: `11 9xxxx xxxx`), e vence a rota sem origem do mesmo DID |
| Para onde vai a chamada * | Condição de horário, URA, fila, ramal, grupo, anúncio, conferência, DISA, correio de voz, megafonia ou número externo |
| Ordem de avaliação | Padrão 10. Menor vem primeiro |
| Rota ativa | Padrão ligado |

### Aba Atendimento

| Campo | O que faz |
|---|---|
| Prefixo no nome de quem liga | Aparece antes do nome no visor: "SAC João Silva" |
| Toque distintivo (Alert-Info) | Ex.: `Bellcore-r2`. Grandstream, Yealink e Fanvil aceitam Bellcore-r2 a r8 |
| Música em espera desta rota | Vale para toda a chamada, inclusive na fila |
| Gravar chamadas desta rota | Grava sempre, independente do ramal que atender |

### Aba Tronco difícil

| Campo | O que faz |
|---|---|
| Sinalizar chamando antes de encaminhar | Quem liga ouve chamando em vez de silêncio. Não atende a chamada |
| Atender antes de encaminhar | Para troncos que só entregam áudio depois do atendimento. **A chamada passa a ser cobrada** |
| Esperar antes de encaminhar (s) | Para tronco que corta o começo da saudação. Um ou dois segundos resolvem; máximo de dez |
| Recusar quem esconde o número | Devolve ocupado sem atender. Reconhece número vazio, "anonymous", "unknown", "restricted" e "private" |

### Sintaxe de padrão (vale para entrada e saída)

| Símbolo | Significa |
|---|---|
| `X` | Qualquer dígito |
| `Z` | 1 a 9 |
| `N` | 2 a 9 |
| `[2-5]` | Faixa |
| `.` no fim | O resto do número (`X.` pega tudo) |

Pode escrever em minúsculas e com espaços ou hífen: a central acerta sozinha. Exemplos: `x.` vira `_X.`; `0xx xxxx xxxx` vira `_0XXXXXXXXXX`; `[2-5]xxxxxxx` vira `_[2-5]XXXXXXX`; `1140041000` fica como está.

---

## 5. Rotas de Saída

**Conectividade › Rotas de Saída**

Por onde sai cada número que os ramais discam. Sem rota de saída, os ramais só fazem chamadas internas.

A lista mostra **Ordem**, **Rota**, **Padrões** (um por linha, com "tira X · põe Y" quando mexe no número; "sem padrão" em alerta) e **Classe**.

> 📷 **Print:** lista de rotas de saída com a rota "Celular 11" mostrando dois padrões.

### Teste "Para onde vai um número?"

No topo da lista. Digite o número como o ramal discaria. A tela responde: "Sai pela rota **Celular 11** (padrão, classe celular), tronco X. A operadora recebe **<número>**", ou que nenhuma rota ativa pega o número.

### Campos

| Campo | O que faz |
|---|---|
| Nome da rota * | Ex.: "Celular 11" |
| Ordem na lista | Só organiza a lista. Quando dois padrões pegam o mesmo número, vale o **mais específico** |
| Modelos prontos | Botões que acrescentam um padrão e acertam a classe (veja abaixo) |
| Padrões de discagem * | Tabela **Padrão / Tirar / Pôr**, uma linha por jeito de discar. **Adicionar padrão** cria outra linha; o X tira a linha |
| Testar os padrões | Digite um número: mostra qual padrão pega e o que a operadora recebe |
| Classe | local, celular, ddd, ddi, emergencia ou especial. Decide quem pode usar a rota |
| Tronco | Tronco principal |
| Tronco reserva | Usado quando o principal não completa. "nenhum" por padrão |
| Só para | Opcional. Grupos de saída e números de ramal, separados por vírgula (`NOC,Suporte,3602`). Vazio: vale para todos os ramais |
| Horário | Opcional. Um **grupo de horário**: a rota só vale nele; fora, a chamada segue para a próxima rota do mesmo número |
| Pedir PIN | Pede uma senha de um **Conjunto de PIN** antes de completar. Os conjuntos ficam em **Configurações › Conjuntos de PIN** |
| Rota ativa | Padrão ligado |

### Rota por grupo de ramais e por horário

O mesmo padrão pode estar em mais de uma rota quando elas valem para ramais ou horários diferentes. Para cada número, a central tenta, nesta ordem:

1. as rotas **Só para** um grupo do ramal que discou (pela ordem da lista); a de **Horário**, só dentro dele;
2. as rotas gerais com **Horário**, se estiver dentro;
3. a rota geral sem horário.

Exemplo: "Celular NOC" (9XXXXXXXX, Só para `NOC`, tronco A), "Celular comercial" (9XXXXXXXX, Horário: comercial, tronco B) e "Celular" (9XXXXXXXX, tronco C). Quem é do NOC sai pelo A; os demais, pelo B no horário comercial e pelo C fora dele. Se nenhuma rota servir (só há rotas de grupo, e o ramal não é de nenhum), quem discou ouve que a chamada não pode ser completada.

O grupo vale para a chamada que o próprio ramal faz. No siga-me e no desvio, a chamada sai pela rota geral.

> [PRINT: lista de rotas de saída com a coluna "Vale para" mostrando grupos e horário]

### Vários padrões na mesma rota

Uma rota pode ter vários padrões, cada um com o seu **Tirar** e **Pôr**. Exemplo, a rota "Celular 11":

| Padrão | Tirar | Pôr | Quem disca | A operadora recebe |
|---|---|---|---|---|
| 9XXXXXXXX | — | — | 999998888 | 999998888 |
| 09XXXXXXXX | 0 | — | 0999998888 | 999998888 |

Tronco, classe, PIN e reserva são da rota e valem para todos os padrões dela.

### Modelos prontos

| Botão | Padrão | Classe | Tirar |
|---|---|---|---|
| Tudo | X. | ddi | — |
| Emergência | 1XX | emergencia | — |
| Fixo local | [2-5]XXXXXXX | local | — |
| Celular local | 9XXXXXXXX | celular | — |
| Celular com 0 | 09XXXXXXXX | celular | 0 |
| DDD com 0 | 0ZZX. | ddd | — (ponha 0 em "Tirar" se a operadora não quiser o 0) |
| Internacional | 00X. | ddi | — |
| 0800 / 0300 | 0[38]00XXXXXXX | especial | — |

O modelo ocupa a primeira linha em branco, ajusta a classe e, se o nome estiver vazio, preenche o nome. É ponto de partida: ajuste ao formato que a sua operadora recebe.

### Regras e validações

- A rota precisa de **pelo menos um padrão**, e no máximo 50.
- Padrão aceita dígitos, `X`, `Z`, `N`, `[ ]`, `.`, `!`, `*`, `#`, `+`, espaço e hífen, até 40 caracteres.
- **Tirar** e **Pôr**: só dígitos, `*`, `#` ou `+`, até 10.
- O mesmo padrão não pode aparecer duas vezes na mesma rota.
- **O padrão precisa começar com o que se manda tirar.** Tirar `0` de `9XXXXXXXX` é recusado ("tirar esse prefixo cortaria outros dígitos do número").
- Antes de gravar, o formulário avisa como o padrão vai ficar ("Vai ser gravado `0xx xxxx xxxx` como `_0XXXXXXXXXX`").

### Comportamentos automáticos

- **Quando vários padrões pegam o mesmo número**, vence o mais específico, posição a posição: número exato, depois faixa, `N`, `Z`, `X` e por fim `.`. Não é a ordem da lista.
- **O mesmo padrão em duas rotas** (para os mesmos ramais e o mesmo horário): vale o da rota de menor ordem; o outro é ignorado. Para ter reserva, use o **Tronco reserva**, não uma segunda rota.
- **Permissão:** a central confere a classe da rota contra as permissões do ramal ([seção 2](#aba-permissões-permissões-de-discagem)). Depois, o **limite de chamadas simultâneas** do ramal: estourado, ele ouve que todos os circuitos estão ocupados.
- **Ordem de failover:** a central tenta o tronco principal por até 60 s. Se ele não completar (e não foi atendida, ocupado ou cancelada por quem discou), tenta o **Tronco reserva**, com a identificação de saída do reserva.
- **Tronco principal desativado:** se há reserva ativa, ela vira a principal; sem reserva, quem disca ouve sinal de linha congestionada.
- **Motivo da falha:** quando o tronco não completa, o motivo devolvido pela operadora (ex.: 404, 403, 503) fica no registro de chamadas.
- A classe vai para o registro de chamadas e é usada na tarifação.

### Dica: rota "Tudo"

Uma rota com `X.` pega qualquer número. Dê a ela a classe mais restrita que ela realmente leva (o modelo usa `ddi`), para que ramais só-locais usem as rotas mais específicas.

---

## 6. DIDs / Numeração

**Conectividade › DIDs / Numeração**

Tela só de leitura com **todos os números que chegam à central**, venham de rota de entrada ou do campo **DID atribuído** do cadastro do ramal.

| Coluna | O que mostra |
|---|---|
| Número | O DID |
| Descrição | Da rota, ou do DID do ramal ("Ramal 1001" se vazia) |
| Vai para | O destino pelo nome (a URA, a fila, o anúncio…), com o mesmo texto da rota de entrada |
| Cadastrado em | **rota de entrada** ou **DID do ramal** |
| Estado | Ativo, Desativado ou **duplicado** |

- **Duplicado:** o mesmo número está numa rota de entrada e no cadastro de um ramal. **A rota de entrada vence**; o DID do ramal fica sem efeito. A tela mostra um aviso no topo com a quantidade.
- A comparação é **só pelos dígitos**: `(11) 3325-5800` no ramal e `1133255800` na rota são o mesmo número e aparecem como duplicado.
- O DID do ramal vira rota sozinho: a chamada cai direto no ramal, grava se o ramal grava entrantes e usa o **CID de entrada** como prefixo no visor.

> 📷 **Print:** lista de DIDs com uma linha **duplicado**.

---

## 7. Firewall

**Conectividade › Firewall** (título da tela: **Firewall e Segurança**)

Quem está tentando entrar na central. Uma varredura de senha aparece aqui dias antes de virar conta de telefone.

| Cartão | O que mostra |
|---|---|
| Origens com falha de autenticação SIP | IP, tentativas (acima de 20 em destaque) e a última |
| Falhas de entrada no console (7 dias) | Tentativas de login no console por IP |
| Bloqueados agora pelo firewall | IPs banidos e a cadeia, com **Desbloquear** e **Nunca bloquear** |
| Nunca bloquear | Lista de endereços que o firewall não bane (e que passam pelo SIP só para redes conhecidas, se ligado) |
| O que a central bloqueou | Eventos de segurança do Asterisk: quando, evento, conta tentada, origem |
| Restrição de rede dos ramais | O que o Asterisk tem carregado |

### Regras do bloqueio

- SIP (UDP e TCP, portas SIP e SIP-TLS): **5 erros de senha em 10 minutos** bane por **24 horas**.
- Varredura SIP: **3 pedidos para ramal que não existe em 1 hora** bane por **24 horas** (cadeias `asterisk-varredura`). Não vale para o IP de tronco nem para IP com ramal registrado.
- Console: 15 falhas em 10 minutos bane por 1 hora.
- O bloqueio vale **inclusive para um ramal mal configurado aqui dentro**, e pode derrubar o IP do próprio escritório.

### Ações

| Ação | O que faz |
|---|---|
| Desbloquear | Libera o IP agora. Se as tentativas continuarem, ele é banido de novo |
| Nunca bloquear (na linha do bloqueado) | Libera agora e inclui na lista de confiáveis |
| Adicionar (IP ou faixa + descrição) | Inclui na lista. Ex.: `200.170.201.2` ou `200.170.201.0/24` |
| Lixeira | Tira da lista: o endereço volta a poder ser bloqueado |

- Faixas maiores que `/8` (IPv4) são recusadas, assim como endereço repetido.
- As ações valem **na hora**, sem aplicar configurações. Se o fail2ban não responder, a tela avisa que a lista vale a partir da próxima execução do instalador.
- Use **Nunca bloquear** só para endereços seus (escritório, monitoramento, syslog): um endereço da lista pode tentar senha à vontade.
- Com o **SIP só para redes conhecidas** ligado (perguntado na instalação), o SIP e o áudio só respondem ao Brasil, à Telium, às redes internas, aos troncos e aos telefones já registrados. A lista **Nunca bloquear** também **abre o SIP** para quem está nela, em segundos: é por ela que entra o ramal ou a operadora de fora do Brasil, e o IP de onde a operadora manda as chamadas quando não é o host do tronco (SBC, nuvem). Tirar da lista fecha de novo (a não ser que o IP seja de um tronco ou já tenha registrado um telefone). Veja [Segurança](08-Seguranca.md#sip-só-para-redes-conhecidas).
- Se aparecer o aviso de que o console não consegue ler o arquivo de segurança, o registro de segurança do Asterisk precisa estar ligado no `logger.conf`.

Mais sobre portas e endurecimento em [Requisitos de Rede e Portas](03-Requisitos-Rede-e-Portas.md) e [Segurança](08-Seguranca.md).

> 📷 **Print:** cartão **Bloqueados agora pelo firewall** com um IP e os botões.

---

## 8. Provisionamento

**Conectividade › Provisionamento** (título da tela: **Provisionamento de Telefones**)

Configuração automática de telefones IP pelo MAC: o aparelho liga, busca o próprio arquivo na central e já registra no ramal.

### Fabricantes suportados

| Fabricante | Arquivo que o aparelho pede |
|---|---|
| Grandstream | `cfg<mac>.xml` |
| Yealink | `<mac>.cfg` |
| Fanvil | `<mac>.cfg` |

O campo **Modelo** é só para reconhecer na lista: o formato do arquivo depende do **fabricante**.

O arquivo entregue leva: servidor e porta SIP, ramal, senha SIP, nome de exibição, fuso horário da empresa, NTP `a.ntp.br`, o código do correio de voz `*97` e o nome da empresa.

### Cartão "Endereço que o telefone busca"

Mostra a URL de provisionamento, no formato `https://<servidor>/prov/<segredo>`, com o botão **Copiar**.

- Configure esse endereço no aparelho, em "servidor de provisionamento", **ou entregue-o pela opção 66 do DHCP**. O telefone monta sozinho o nome do arquivo a partir do MAC.
- **O segredo no caminho** impede alguém de baixar a senha SIP de um ramal só por adivinhar o MAC.
- **Só da rede local (padrão):** o arquivo só é entregue para as faixas internas definidas em [Configurações de Rede](#9-configurações-de-rede) (e para a própria máquina). Se os telefones estão em outra faixa, acrescente-a lá.
- Badge "N pedido(s) recusado(s) · 7 dias": pedidos com segredo errado, de fora da rede, de MAC desconhecido ou de aparelho sem ramal.

| Botão | O que faz |
|---|---|
| Permitir de qualquer origem | Abre para qualquer IP, inclusive a internet (pede confirmação). Só com os telefones fora da rede da central |
| Voltar a exigir rede interna | Volta ao padrão |
| Gerar novo segredo | Troca a URL. **Todos os aparelhos** param de receber configuração até alguém reconfigurar a URL em cada um. Use se o endereço vazou |

Essas ações valem na hora.

> 📷 **Print:** cartão com a URL de provisionamento e os botões.

### Cadastrar um aparelho

**Adicionar aparelho:**

| Campo | O que faz |
|---|---|
| MAC address * | 12 dígitos hexadecimais, com ou sem separador (ex.: `00:11:22:33:44:55`). Único |
| Fabricante * | Grandstream, Yealink ou Fanvil |
| Modelo | Ex.: T31G. Só para a lista |
| Ramal | Sem ramal (ou com ramal inativo) o aparelho não recebe configuração |
| Onde fica | Ex.: "Recepção, mesa 3" |

A lista mostra **MAC**, **Aparelho**, **Ramal**, **Buscou a configuração** (quando, de qual IP e quantas vezes) e **Estado**: Aguardando, Provisionado ou Erro. O olho (**Ver a configuração**) mostra o arquivo exatamente como o aparelho o recebe.

Para a central, qualquer recusa responde "não encontrado" ao aparelho, que volta a tentar no próximo boot.

### Últimos pedidos dos telefones

Cartão com os **30 pedidos mais recentes** de configuração. É aqui que se descobre por que um aparelho não pegou a configuração. Só aparece depois do primeiro pedido.

| Coluna | O que mostra |
|---|---|
| Quando | Data e hora do pedido |
| MAC | O MAC que o aparelho pediu |
| IP | De onde veio o pedido |
| Resultado | Veja abaixo |
| Aparelho | Como o telefone se identificou no pedido (o User-Agent: em geral, modelo e firmware) |

| Resultado | O que significa |
|---|---|
| entregue | O aparelho recebeu a configuração |
| MAC não cadastrado | Cadastre o aparelho com este MAC na lista abaixo |
| aparelho sem ramal | O aparelho está cadastrado, mas sem ramal |
| segredo errado | O endereço configurado no telefone está com o segredo antigo |
| fora da rede interna | O pedido veio de fora das redes internas |

> 📷 **Print:** cartão "Últimos pedidos dos telefones" com uma linha recusada.

### Linphone no celular (QR Code)

Cartão no topo da tela, para quem pode editar:

1. Escolha o **Ramal** (ramais WebRTC não aparecem: eles não falam UDP).
2. **Senha SIP:** em branco, vai a do cadastro. Se digitar uma diferente, a tela avisa que a central vai recusar o registro.
3. **Gerar QR Code.** O QR vale por **10 minutos** e para **uma leitura só**.
4. No Linphone para Android, no assistente de conta, use **Ler QR code**. O aplicativo configura na hora e substitui a primeira conta que já existia.

- Se o servidor mostrado é endereço de rede interna, o celular precisa estar no Wi-Fi dessa rede. Para usar de fora, abra o console pelo IP público e gere de novo.
- Sem certificado de confiança na central, o QR usa `http` (o Linphone recusa certificado autoassinado). Com um certificado válido em **Administrador › Certificados**, passa a ser `https` sozinho.

> 📷 **Print:** QR Code gerado, com o contador de validade.

---

## 9. Configurações de Rede

**Conectividade › Configurações de Rede**

Onde a central escuta e por onde a voz trafega.

### Central atrás de NAT

| Campo | O que faz |
|---|---|
| Endereço público | O IP pelo qual a central sai para a internet. **Deixe vazio se o IP da máquina já é público.** Precisa ser IP (IPv4), não nome |
| Faixas da rede interna | Ex.: `10.0.0.0/8, 192.168.0.0/16`. Para quem está nestas faixas, a central usa o endereço interno. Separadas por vírgula, ponto e vírgula ou linha; aceita `/24` ou `/255.255.255.0` |

- **Descobrir** pergunta o IP público a um servidor STUN de fora e preenche o campo. Confira e salve. Se o STUN responder um endereço de rede interna, a tela pede para informar à mão. Se o botão estiver desabilitado, não há servidor STUN de descoberta configurado.
- Depois de **Salvar**, **aplique as configurações**. O endereço público e as faixas recarregam sem reiniciar o Asterisk.
- Sem o endereço público, atrás de NAT, o tronco nunca registra e as chamadas conectam sem áudio.
- Instalação nova, sem nada salvo no console: valem as faixas `10.0.0.0/8`, `172.16.0.0/12` e `192.168.0.0/16`.
- Endereços na faixa `100.64.0.0/10` (CGNAT da operadora) são tratados como internos.

**Alerta "a chamada conecta e ninguém ouve":** aparece quando a máquina sai pela internet com endereço de rede interna e o endereço público está vazio. Preencha e aplique.

### Outros cartões

| Cartão | O que mostra |
|---|---|
| Transportes SIP | Transportes carregados no Asterisk e onde escutam. Quem atende o navegador é o nginx, que termina o TLS com o certificado do console e repassa ao Asterisk em 127.0.0.1; o `transport-wss` escuta em `0.0.0.0` de propósito: preso no loopback, o áudio do softphone do navegador não sai |
| Faixa de portas de voz (RTP) | Lido do Asterisk |
| STUN ativo no RTP | Alerta quando o `rtp.conf` tem `stunaddr`: se o endereço não responder, cada chamada atrasa nove segundos. Tire, a menos que haja motivo |
| Servidor HTTP do Asterisk | Estado do servidor que atende o WebSocket do WebRTC |

### STUN e TURN

- O STUN do botão **Descobrir** é um servidor **de fora** (por padrão, do Google e da Cloudflare), usado só quando alguém clica.
- O STUN e o TURN do **softphone do navegador** são outros, definidos na instalação. Veja a [seção 10](#10-webrtc--softphone).
- O **endereço público** desta tela também chega ao TURN da central (coturn, `external-ip`): vale o `nat_ip_publico` do `/etc/telium-servidor.yml` (perguntado na instalação); sem ele, o endereço gravado aqui. Isso é aplicado pelo playbook: depois de mudar o endereço, rode a atualização (ou o playbook) para o TURN passar a usá-lo.

> 📷 **Print:** cartão **Central atrás de NAT** preenchido.

---

## 10. WebRTC / Softphone

**Conectividade › WebRTC / Softphone**

Liga ou desliga o telefone do navegador e mostra o caminho que ele percorre. Quando o softphone falha, é um destes pontos.

### Ligar e desligar

- O telefone pelo navegador **vem desligado**. Desligado, o discador do console continua funcionando: liga pelo **telefone de mesa** de quem clicou (a central chama o ramal e, quando a pessoa atende, completa a chamada).
- **Ligar o telefone pelo navegador** e **Desligar** valem na hora. Ao ligar, cada usuário precisa **recarregar o console**. Ao desligar, chamadas em curso não caem.
- Para o ramal aparecer no discador do navegador, ele precisa estar marcado como **Softphone do navegador** no cadastro **e** vinculado ao usuário em **Administrador › Gerenciador de Usuários**.
- Numa instalação nova, o softphone do navegador já funciona **antes do primeiro "Aplicar configurações"**: o transporte WSS nasce do playbook com o mesmo ajuste que o console grava.
- O navegador só libera o microfone em página segura (HTTPS) ou em `localhost`. O certificado é o mesmo do console, gerido em **Administrador › Certificados** ([Administrador](12-Administrador.md)).

### O veredito

No topo, uma conclusão em uma linha:

| Veredito | Quando |
|---|---|
| O softphone do navegador não vai funcionar | Lista o que falta: transporte WSS não carregado, áudio preso no loopback, domínio SIP que o navegador não entende, WebSocket não publicado, servidor de ICE inalcançável, ramal que registra mas não recebe chamada |
| Funciona, menos em rede que bloqueia UDP | Caminho montado, mas sem TURN |
| O caminho do softphone está montado | Tudo conferido |

### Cartões de diagnóstico

| Cartão | O que mostra |
|---|---|
| Últimas chamadas pelo navegador | As 20 últimas, medidas pelo navegador de quem falou: áudio nos dois sentidos, picotado, só ouviu, só falou, mudo ou a mídia não fechou; o caminho (rede local, endereço público, pelo TURN) e os pacotes. Badge com as chamadas com problema nos últimos 7 dias. Aparece mesmo com o softphone desligado, se houver histórico |
| Este computador consegue fazer WebRTC? | **Testar agora** roda no navegador de quem está na tela, com os mesmos servidores do softphone, e diz se há endereço local, endereço público (STUN) e caminho pelo TURN (relay) |
| Caminho da chamada | Transporte WSS, áudio fora do loopback, domínio SIP, WebSocket publicado (`/ws`), servidor de ICE e TURN (próprio, com credencial que vence, ou de terceiros) |
| Ramais com WebRTC | Cada ramal marcado: registrado ou não, e DTLS, ICE, AVPF e rtcp-mux. **As quatro precisam estar em "sim"** |

### Diagnóstico do TURN (pelo DNS)

O navegador de quem usa o console precisa **resolver e alcançar** o STUN e o TURN. A central confere cada servidor:

- nome terminado em `.local` é recusado (só vale dentro da própria rede);
- nome que não resolve nem no servidor é recusado;
- **o nome da central costuma estar no `/etc/hosts` como `127.0.1.1`**. Isso é do servidor, não do navegador: nesse caso a central pergunta ao **DNS**, que é onde o navegador pergunta. Se o nome não existe no DNS, o alerta diz "o nome só existe no /etc/hosts deste servidor";
- nome que resolve para endereço de rede interna é recusado.

Quando o servidor configurado é `.local` ou endereço interno e há endereço público em [Configurações de Rede](#9-configurações-de-rede), o console **troca o host pelo IP público** ao entregar a lista ao navegador, e mostra o aviso "endereço do TURN corrigido pelo console". Funciona, mas o certo é acertar `turn_dominio` no instalador. Nome que só não resolve não é trocado (pode ser um TURN de terceiros).

Quando o TURN é o próprio da instalação, a credencial é gerada a cada login e vence (padrão de 6 horas).

### Alertas específicos

| Alerta | Causa e correção |
|---|---|
| o navegador não alcança o servidor de ICE | Veja o diagnóstico do TURN acima |
| ramal registra mas não recebe chamada | O navegador não responde ao teste da central. Quase sempre o domínio SIP: confira `pabx_hostname` no instalador (nome precisa começar por letra) |
| chamada conecta e fica muda (aparelho incompatível) | Um ramal WebRTC registrou por UDP/TCP, de um softphone comum. Crie dois ramais e use o siga-me "junto" |

> 📷 **Print:** tela com o veredito verde e o resultado de **Testar agora**.

---

## 11. Exemplo de ponta a ponta

Cenário: a empresa contratou um tronco SIP com registro, número principal 11 3325-5800. As chamadas recebidas devem ir para a URA "URA Principal" (já criada em **Aplicações › URA / Atendimento Digital**, veja [Filas, URA e Atendimento](13-Aplicacoes-Filas-URA-e-Atendimento.md)), e os ramais devem ligar para celular discando com ou sem o 0.

### Passo 1 — Rede

Em **Conectividade › Configurações de Rede**, se a central tem IP interno, clique em **Descobrir**, confira o IP, **Salvar**.

### Passo 2 — Tronco

**Conectividade › Troncos › Novo tronco**:

| Campo | Valor |
|---|---|
| Nome do tronco | Operadora-SP |
| Host da operadora | sip.operadora.com.br |
| Porta / Transporte | 5060 / udp |
| Canais contratados | 30 |
| Usuário de autenticação | 1133255800 |
| Senha | a fornecida pela operadora |
| Registrar no provedor | ligado |
| From user | 1133255800 |
| Identificação de saída | 1133255800 |
| Codecs | alaw,ulaw |
| Tirar do começo do número recebido | +55 |

### Passo 3 — Rota de entrada para a URA

**Conectividade › Rotas de Entrada › Nova rota**:

| Campo | Valor |
|---|---|
| Número chamado (DID) | 1133255800 |
| Descrição | Número principal |
| Só quando quem liga for | (vazio) |
| Para onde vai a chamada | URA — URA Principal |

Se a URA cortar o começo da saudação, use **Esperar antes de encaminhar** = 1 na aba **Tronco difícil**.

### Passo 4 — Rota de saída para celular com dois padrões

**Conectividade › Rotas de Saída › Nova rota**:

1. Nome: **Celular 11**.
2. Clique no modelo **Celular local** (`9XXXXXXXX`, classe celular).
3. Clique no modelo **Celular com 0** (`09XXXXXXXX`, Tirar `0`).
4. Tronco: **Operadora-SP**. Tronco reserva: outro tronco, se houver.
5. Em **Testar os padrões**, digite `0999998888`: "Pega pelo padrão `_09XXXXXXXX`. A operadora recebe **999998888**".

Lembre-se de criar também a rota de **Emergência** (modelo `1XX`).

### Passo 5 — Aplicar e conferir

1. **Aplicar configurações** na faixa amarela.
2. Em **Troncos**, a coluna **Na central** deve mostrar **no ar**.
3. Em **Rotas de Entrada**, teste `1133255800`: "Vai para URA Principal".
4. Em **Rotas de Saída**, teste `999998888` e `0999998888`: os dois saem pela **Celular 11**.
5. Ligue de um celular para o número principal e, de um ramal com permissão **Celular**, ligue para um celular.

> 📷 **Print:** os três testes (entrada, saída com e sem 0) com resultado verde.

---

## 12. Problemas comuns

**O tronco não registra.**
- Veja a frase na coluna **Na central** ([seção 3](#diagnóstico-de-registro-coluna-na-central)).
- "operadora recusou o registro": confira usuário e senha; pergunte à operadora se o IP precisa ser liberado.
- Central atrás de NAT sem **Endereço público**: preencha em **Configurações de Rede** e aplique.
- Verifique se o IP da operadora não está em **Bloqueados agora pelo firewall**.

**A operadora responde 404 em toda chamada de saída.**
- O número da conta está em **From domain**. Ele vai em **From user**; o From domain é o domínio ou IP da operadora.
- Confira o formato que a operadora espera (com ou sem 0, com ou sem DDD) e ajuste **Tirar**/**Pôr** no padrão.

**A chamada recebida cai no aviso de serviço indisponível.**
- Nenhuma rota pega o número. Use o teste da tela de **Rotas de Entrada**.
- A operadora entrega o número em outro formato (com `+55`, com DDD, só os últimos dígitos). Acerte no tronco, com **Tirar do começo** ou **Ficar só com os últimos N dígitos**.

**O ramal disca e ouve que a chamada não pode ser completada.**
- O ramal não tem permissão para a classe da rota (aba **Permissões**).
- O contexto do ramal está como **telium-bloqueado**.

**O ramal ouve que todos os circuitos estão ocupados.**
- Atingiu o **Limite de chamadas simultâneas de saída** do ramal.

**O ramal disca e ouve linha congestionada.**
- O tronco da rota está desativado e não há reserva, ou nenhum tronco completou. O motivo dado pela operadora fica no registro de chamadas.

**Celular com 0 sai com um dígito a menos.**
- O prefixo a tirar só pode estar no padrão que começa com ele (`09XXXXXXXX`), não no `9XXXXXXXX`. O cadastro recusa esse erro ao salvar.

**Uma rota nova não é usada.**
- Outra rota tem um padrão mais específico para o mesmo número, ou o mesmo padrão com ordem menor. Use **Para onde vai um número?**.
- Faltou **Aplicar configurações**.

**O ramal não registra.**
- Senha errada no aparelho: confira em **Credenciais SIP**.
- O IP do aparelho foi bloqueado pelo firewall depois de cinco senhas erradas. **Desbloquear** e corrigir o aparelho.
- **Restringir a redes** não inclui a rede do aparelho.
- Ramal inativo, ou não aplicado.

**A chamada conecta e ninguém ouve.**
- Central atrás de NAT sem endereço público ([seção 9](#9-configurações-de-rede)).
- No softphone do navegador: sem TURN alcançável, ou ramal WebRTC usado num softphone comum ([seção 10](#10-webrtc--softphone)).
- Tronco com g729 e ramais em outros codecs.

**O telefone não se provisiona.**
- MAC errado ou fabricante errado (Grandstream pede `cfg<mac>.xml`).
- Aparelho sem ramal, ou ramal inativo.
- Telefone fora das faixas internas: acrescente a faixa em **Configurações de Rede**.
- URL antiga depois de **Gerar novo segredo**.
- O badge de pedidos recusados e o cartão **Últimos pedidos dos telefones** mostram se o telefone chegou a pedir e por que foi recusado.

**O QR do Linphone não funciona.**
- Venceu (10 minutos) ou já foi lido uma vez: gere outro.
- Celular no 4G com servidor de rede interna: use o Wi-Fi da empresa ou abra o console pelo IP público.

**O softphone do navegador não aparece para o usuário.**
- WebRTC desligado em **WebRTC / Softphone**, ou o usuário não recarregou o console.
- O ramal não está marcado como **Softphone do navegador**, ou não está vinculado ao usuário.

Para problemas fora desta seção, veja [Solução de Problemas](07-Solucao-de-Problemas.md).
