# PCU — Meu Painel — Telium PABX

O portal do usuário: o que cada pessoa resolve sozinha, sem passar pelo administrador. Ver o próprio ramal, ligar o não perturbe e o siga-me, ouvir recados, conferir as chamadas, cuidar da agenda e da própria senha.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Meu Ramal](#2-meu-ramal)
3. [Minhas Chamadas](#3-minhas-chamadas)
4. [Meu Correio de Voz](#4-meu-correio-de-voz)
5. [Meu Siga-me](#5-meu-siga-me)
6. [Meus Contatos](#6-meus-contatos)
7. [Meu Perfil e Segurança](#7-meu-perfil-e-segurança)
8. [Ligar pelo console (click to call)](#8-ligar-pelo-console-click-to-call)
9. [Problemas comuns](#9-problemas-comuns)

---

## 1. Visão geral

- **Tudo é do próprio ramal.** O ramal vem da conta (vínculo feito em **Administrador › Gerenciador de Usuários**). Nenhuma tela do PCU deixa escolher outro ramal, e a central nunca aceita um número de ramal vindo do navegador: não há como ver o correio de voz do vizinho.
- **Sem ramal vinculado**, as telas de ramal, chamadas, correio e siga-me mostram **Sua conta não tem ramal**, com o pedido de vínculo ao administrador. Isso é normal para quem não atende telefone, como o administrador.
- **Vale na hora.** Não perturbe, siga-me e chamada em espera são gravados também na base do Asterisk no momento de salvar; não é preciso esperar ninguém aplicar configuração.
- Cada tela é um módulo (pcu.meuramal, pcu.chamadas, pcu.correiovoz, pcu.sigame, pcu.contatos, pcu.perfil) em **Administrador › Perfis e Permissões**.

> 📷 **Print:** menu lateral aberto na seção **PCU — Meu Painel**.

---

## 2. Meu Ramal

**PCU — Meu Painel › Meu Ramal**

O que é seu, e o que você pode mudar sem pedir a ninguém.

> 📷 **Print:** Meu Ramal com o cartão do ramal e o formulário de preferências.

### Cartão do ramal (só leitura)

| Campo | O que mostra |
|---|---|
| Número, nome e setor | Cadastro do ramal |
| Estado | **Registrado** (há aparelho registrado agora), **Nenhum aparelho registrado** ou **central sem resposta** |
| Correio de voz | ligado ou desligado |
| Softphone no navegador | liberado ou não liberado (o ramal está marcado como WebRTC) |
| Gravação de chamadas | não grava, só recebidas, só feitas, ou recebidas e feitas |

Abaixo, a tabela de aparelhos registrados agora, lida do Asterisk:

| Coluna | O que mostra |
|---|---|
| Aparelho | Endereço de contato do aparelho |
| Estado | **disponível** quando o aparelho responde; senão o estado informado pelo Asterisk |
| Resposta | Tempo de resposta do aparelho, em ms |

Sem aparelho, a tela lembra que um telefone de mesa precisa do número, do endereço da central e da senha SIP, e que a senha é pedida ao administrador.

### Preferências

| Campo | O que faz | Regra |
|---|---|---|
| Não perturbe | O ramal não toca: quem ligar cai no correio de voz, ou ouve ocupado | Mesmo efeito de `*78` / `*79` no telefone |
| Chamada em espera | Desligado, a segunda chamada recebe ocupado em vez de bipar no seu telefone | |
| Tempo de toque (segundos) | Quanto o telefone toca antes de desistir | Entre 5 e 120. Valor fora disso é ajustado ao limite |
| Meu e-mail | Para onde vai a cópia do recado | Precisa ser um e-mail válido, ou vazio |
| Receber recado por e-mail | Manda cada recado também por e-mail | Só aparece se o ramal tem correio de voz |

Clique em **Salvar**. A mudança fica registrada na auditoria.

O **discar** do ramal não fica nesta tela: está no telefone do console e nos botões **Ligar** espalhados pelo sistema ([seção 8](#8-ligar-pelo-console-click-to-call)).

---

## 3. Minhas Chamadas

**PCU — Meu Painel › Minhas Chamadas**

Tudo o que passou pelo seu ramal, do mais novo para o mais antigo.

> 📷 **Print:** Minhas Chamadas com o filtro e algumas linhas.

### Filtros

| Filtro | Opções |
|---|---|
| Sentido | Todas as chamadas, Só de fora, Só para fora, Só internas |
| Datas | Data inicial e final (dia inteiro) |
| Limpar | Volta tudo ao padrão (sem datas: todo o histórico) |

À direita, o total de chamadas encontradas.

### Colunas

| Coluna | O que mostra |
|---|---|
| Quando | Data e hora |
| Sentido | **feita** (o seu ramal ligou) ou **recebida** |
| Com quem | O outro número da chamada |
| Resultado | atendida, não atendida, ocupado ou falhou |
| Conversa | Tempo falado, só nas atendidas |
| Total | Tempo total, incluindo o tempo chamando |

Até 50 chamadas por página, com **Anterior** e **Próxima** quando há mais de uma página. Esta tela não toca gravações; quem tem acesso ouve pelo **CDR** ([18-Relatorios.md](18-Relatorios.md)).

---

## 4. Meu Correio de Voz

**PCU — Meu Painel › Meu Correio de Voz**

Os recados da sua caixa, para ouvir sem discar nada. Pelo telefone, o código é `*97`.

> 📷 **Print:** Meu Correio de Voz com a pasta Novas aberta e o player tocando.

Se o correio de voz não está ligado no ramal, aparece **Correio de voz desligado**: só o administrador liga, no cadastro do ramal.

### Pastas

Só aparecem as pastas com recado.

| Pasta | Conteúdo |
|---|---|
| Novas | Recados ainda não ouvidos pelo telefone |
| Ouvidas | Recados já ouvidos pelo telefone |
| Urgentes | Recados marcados como urgentes |

### Colunas e ações

| Item | O que faz |
|---|---|
| De | Identificação de quem deixou o recado |
| Quando | Data e hora do recado |
| Duração | Tamanho do recado |
| Ouvir | Abre um player abaixo da linha. Clicar de novo fecha. Recado sem arquivo de áudio mostra "sem áudio" |
| Apagar | Pede confirmação ("A mensagem sai da sua caixa e não tem como voltar.") e apaga o recado de vez |

Se a cópia por e-mail estiver ligada e houver e-mail, um aviso no topo diz para qual endereço vão as cópias.

Ouvir pelo console não move o recado de **Novas** para **Ouvidas**.

---

## 5. Meu Siga-me

**PCU — Meu Painel › Meu Siga-me**

Manda as chamadas do seu ramal para outro número, como um celular.

> 📷 **Print:** Meu Siga-me ligado, com o modo **Depois**.

| Campo | O que faz | Regra |
|---|---|---|
| Siga-me ligado | Liga ou desliga | A etiqueta ao lado do título mostra **ativo** só quando está ligado e há destino |
| Número de destino | Outro ramal ou número externo, com DDD | Só dígitos, `*`, `#` e `+` são guardados. Para número externo, o ramal precisa de permissão de discagem para ele |
| Quando tocar no destino | **Junto**: toca o ramal e o destino ao mesmo tempo; quem atender primeiro fica com a chamada. **Depois**: só procura o destino se o ramal não atender no tempo de toque | |

Botões: **Salvar** e, com o siga-me ativo, **Desligar agora**.

Comportamentos:
- Se o destino não atender, a chamada volta para o **seu** correio de voz, e não para a caixa postal do celular.
- Com **Não perturbe** ligado, o ramal dá ocupado antes de chegar ao siga-me.

**Pelo telefone:** o siga-me se liga com `*21` mais o número e se desliga com `*22`, como diz o quadro **Como funciona** da tela (códigos de fábrica; a central pode ter outros — confira em **Administrador › Códigos de Recurso**, [15-Codigos-de-Recurso.md](15-Codigos-de-Recurso.md)). Ligado pelo `*21`, o siga-me usa o modo escolhido nesta tela (**Junto** ou **Depois**): o modo fica guardado mesmo com o siga-me desligado.

---

## 6. Meus Contatos

**PCU — Meu Painel › Meus Contatos**

A sua agenda pessoal, mais a agenda da empresa. É a mesma tela do **Gerenciador de Contatos** do administrador, aberta na agenda pessoal.

> 📷 **Print:** Meus Contatos em cartões, com a aba **Meus contatos** selecionada.

### Abas e filtros

| Controle | O que faz |
|---|---|
| Meus contatos | Só a sua agenda pessoal. Ninguém mais vê |
| Da empresa | A agenda corporativa, comum a todos |
| Todos | As duas juntas |
| Busca | Nome, empresa, cargo, número ou e-mail |
| Todos os grupos | Filtra por grupo (só aparece se existir algum grupo) |
| só favoritos | Mostra só os favoritos |

O contador mostra quantos contatos aparecem com o filtro. Favoritos vêm primeiro.

### Cartão do contato

Mostra foto ou iniciais, nome, cargo e empresa, até dois telefones, e-mail, cidade, e a etiqueta **pessoal** ou **empresa**.

| Botão | O que faz |
|---|---|
| Ligar | Liga para o primeiro telefone do contato ([seção 8](#8-ligar-pelo-console-click-to-call)) |
| Ver ficha | Abre a ficha completa: todos os telefones com **Ligar**, discagem rápida, e-mails, endereço, links, aniversário e anotações |
| Editar / Excluir | Só nos contatos que você pode alterar (os seus pessoais) |

### Cadastrar ou editar

Clique em **Novo contato**. O formulário tem quatro abas.

| Aba | Campos |
|---|---|
| Pessoa | Nome completo *, Empresa, Cargo, Setor, Grupo na agenda, Aniversário, Favorito, Ativo |
| Telefones e e-mail | Telefone principal (o que o botão Ligar usa), Celular, Telefone fixo, Ramal interno, Discagem rápida, E-mail, E-mail alternativo |
| Endereço | Logradouro e número, Complemento, Bairro, Cidade, UF, CEP, País |
| Mais | Site, Outros links (um por linha, no formato rótulo, barra vertical, endereço), Anotações |

Regras (conferidas pela central):
- **Nome** é obrigatório.
- É preciso pelo menos **um telefone** (principal, celular, fixo ou ramal) **ou um e-mail**.
- E-mails precisam estar em formato válido.
- **Discagem rápida**: só dígitos, até oito, e o código não pode estar em uso por outro contato. Com ela, qualquer ramal liga para o contato discando `*0` seguido do código.
- Quem não tem o **Gerenciador de Contatos** só cria contatos na **agenda pessoal**. A agenda da empresa só é alterada por quem tem esse módulo.
- **Foto**: só ao editar um contato já salvo. JPG, PNG, WebP ou GIF, até 4 MB; é salva na hora e reduzida para 512 px. **Remover** tira a foto.

---

## 7. Meu Perfil e Segurança

**PCU — Meu Painel › Meu Perfil e Segurança**

Seus dados de acesso, a senha do console e a verificação em dois passos.

> 📷 **Print:** Meu Perfil e Segurança com os três cartões.

### Dados da conta (só leitura)

Nome, usuário, **Perfil de acesso**, **Ramal vinculado**, **Setor**, **E-mail** e o estado da **Verificação em dois passos**. Nome, perfil e ramal são definidos pelo administrador; o e-mail do ramal se muda em **Meu Ramal**.

### Trocar minha senha

| Campo | Regra |
|---|---|
| Senha atual | Precisa conferir. Errar fica registrado na auditoria |
| Senha nova | Pelo menos 8 caracteres, com um número e um símbolo. Precisa ser diferente da atual |
| Repita a senha nova | Igual à senha nova |

Clique em **Trocar senha**. Ao trocar:
- **as suas outras sessões são encerradas** (outros navegadores e computadores); a mensagem diz quantas. A sessão em uso continua;
- um eventual bloqueio por senha errada é zerado;
- a troca fica na auditoria.

Não há lista de sessões abertas nesta tela: trocar a senha é o jeito de derrubar as outras.

### Verificação em dois passos (2FA)

Além da senha, a entrada passa a pedir um código de seis dígitos que muda a cada trinta segundos, gerado por um aplicativo no celular (Google Authenticator, Microsoft Authenticator, Aegis, 1Password e outros).

**Para ligar:**
1. Clique em **Ligar verificação em dois passos**.
2. Leia o QR Code com o aplicativo. Se a câmera não ajudar, digite o **Código para digitação manual** (em grupos de quatro).
3. Digite em **Código do aplicativo** o número que o aplicativo mostrar e clique em **Confirmar e ligar**.

A verificação só passa a valer depois do passo 3: gerar o QR e desistir (**Cancelar**) não tranca a conta. O QR Code é desenhado no próprio navegador; o segredo não passa por serviço externo.

Se o código não for aceito: "O código não confere. Confira se o relógio do celular está certo e tente o próximo." A central aceita uma pequena diferença de relógio.

**Para desligar:** digite **Sua senha, para desligar** e clique em **Desligar**.

**Perdeu o celular?** Peça ao administrador: em **Administrador › Gerenciador de Usuários** ele desliga a verificação da sua conta ([12-Administrador.md](12-Administrador.md)).

> 📷 **Print:** passo do QR Code, com o código manual e o campo **Código do aplicativo**.

---

## 8. Ligar pelo console (click to call)

O PCU não tem um campo próprio de discar: quem liga é o telefone do console. Os botões **Ligar** (em Meus Contatos, no CDR, no telefone) funcionam assim:

| Situação | O que acontece |
|---|---|
| O telefone do navegador está registrado | A chamada sai pelo navegador ([11-Telefone-WebRTC.md](11-Telefone-WebRTC.md)) |
| Não está (sem permissão, ramal não WebRTC, softphone desligado) | **Click to call**: a central toca primeiro o **seu** telefone de mesa e, quando você atende, disca o destino |

Regras do click to call:
- A origem é sempre o ramal da sua conta. Sem ramal: "Sua conta não tem ramal vinculado, então não há de onde originar a chamada."
- A central confere antes se o aparelho está registrado e respondendo; se não, avisa e não tenta.
- A chamada passa pelas regras do seu ramal (permissão de discagem, limite de chamadas, rota de saída), como se você tivesse discado no aparelho.
- Cada pedido fica na auditoria, com o destino e o IP.

A mensagem de sucesso é "Seu ramal N vai tocar. Atenda para a central discar X.".

Para outros sistemas (CRM, ERP) usarem o click to call com chave de API, veja [20-Telium-Tarifacao-Integracoes-Suporte.md](20-Telium-Tarifacao-Integracoes-Suporte.md).

---

## 9. Problemas comuns

**"Sua conta não tem ramal" em todas as telas do PCU.**
- A conta não está vinculada a um ramal. O administrador faz o vínculo em **Administrador › Gerenciador de Usuários**.

**Meu Ramal mostra "Nenhum aparelho registrado".**
- O telefone de mesa está desligado, fora da rede ou com a senha SIP errada. Se você usa só o telefone do navegador (ramal WebRTC), o ramal só aparece registrado enquanto o console estiver aberto.

**Meu Ramal mostra "central sem resposta".**
- A central não respondeu à consulta naquele momento. Recarregue; persistindo, avise o suporte.

**Liguei o não perturbe e o telefone continua tocando.**
- Confira se salvou (a mensagem "Preferências salvas" aparece).
- Se a central estava fora do ar no momento de salvar, a preferência fica gravada no cadastro, mas pode não ter chegado ao Asterisk. Salve de novo com a central no ar, ou use `*78` no telefone.

**O siga-me não leva a chamada para o celular.**
- O ramal não tem permissão de discagem para números externos. Peça ao administrador.
- O modo é **Depois** e o ramal está atendendo antes de acabar o tempo de toque. O modo vale também quando o siga-me foi ligado pelo `*21`.
- O não perturbe está ligado.

**Disquei *72 achando que era o siga-me.**
- `*72` é o desvio de todas as chamadas. O siga-me pelo telefone é `*21` (ligar) e `*22` (desligar), nos códigos padrão.

**"Correio de voz desligado".**
- O correio de voz não está ligado no ramal. Só o administrador liga.

**Não consigo salvar um contato.**
- Falta um telefone ou e-mail, o e-mail está inválido, ou a discagem rápida já é de outro contato. A mensagem diz qual campo corrigir.
- Para cadastrar na agenda da empresa é preciso o **Gerenciador de Contatos**.

**"A senha atual não confere" ao trocar a senha.**
- Digite a senha com que você entrou hoje. Se esqueceu, o administrador define uma nova em **Gerenciador de Usuários**.

**"A senha precisa de…" ao trocar a senha.**
- A senha nova precisa de pelo menos 8 caracteres, um número e um símbolo.

**O código do aplicativo de 2FA nunca confere.**
- O relógio do celular está errado. Ative a hora automática no celular e tente o próximo código.

**O botão Ligar disse que o ramal não está registrado.**
- O click to call precisa do telefone de mesa ligado. Ou peça para o ramal ser marcado como WebRTC e ao seu perfil o módulo **Telefone (WebRTC)**.
