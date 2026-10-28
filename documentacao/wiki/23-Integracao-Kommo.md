# Integração com o Kommo (CRM) — Telium PABX

O Telium PABX conversa com o **Kommo** em dois sentidos:

- **Clique para ligar.** No Kommo, a pessoa clica no telefone do contato (ou do lead, ou da empresa) e escolhe **Ligar pelo PABX**. A central toca o ramal **dessa pessoa** e, quando ela atende, disca o cliente. No visor do ramal aparece o nome do cartão.
- **Registro automático.** Cada ligação feita ou recebida vai para o **histórico do contato** no Kommo, com o resumo (quem ligou, para quem, quando e quanto durou), o resultado e o dono (o usuário do Kommo do ramal).
- **Gravação no cartão.** Se a chamada foi gravada, o registro leva o link, e o Kommo mostra o player no próprio histórico.

Tudo isso é configurado num lugar só: **Telium › Integrações / API**, seção **Kommo (CRM)**. Do lado do Kommo, instala-se o **app Telium PABX**, que a própria central gera.

---

## Sumário

1. [Antes de começar](#1-antes-de-começar)
2. [Passo a passo](#2-passo-a-passo)
3. [Os campos da tela](#3-os-campos-da-tela)
4. [Usuários do Kommo e ramais](#4-usuários-do-kommo-e-ramais)
5. [O clique para ligar](#5-o-clique-para-ligar)
6. [O registro das chamadas](#6-o-registro-das-chamadas)
7. [As gravações](#7-as-gravações)
8. [Problemas comuns](#8-problemas-comuns)
9. [Para o técnico: como funciona por dentro](#9-para-o-técnico-como-funciona-por-dentro)

---

## 1. Antes de começar

- Uma conta do Kommo e um usuário **administrador** dela (só o administrador cria integração).
- A central com **nome e certificado válidos** (https): o Kommo só toca gravação de endereço seguro, e o navegador só deixa o Kommo chamar a central por https. Veja [Certificados](19-Configuracoes.md).
- A central **alcançável de onde as pessoas usam o Kommo** (o navegador delas abre o link da gravação e manda o clique para ligar). Se o Kommo é usado de fora da empresa, a central precisa estar publicada na internet na porta 443.
- A central com **saída para a internet** na porta 443: é ela que manda as chamadas para o Kommo (`https://<conta>.kommo.com`).
- Os ramais de quem vai usar o Kommo já cadastrados em **Conectividade › Ramais**.

---

## 2. Passo a passo

1. **Baixe o app.** Em **Telium › Integrações / API**, seção **Kommo (CRM)**, clique em **telium-pabx-kommo.zip**.
2. **Crie a integração no Kommo.** No Kommo, como administrador: **Configurações › Integrações › Criar integração**.
   - Tipo: **integração privada**.
   - Nome: *Telium PABX*. Descrição: a que quiser.
   - **Redirect URL: em branco.**
   - Acesso: deixe os escopos marcados.
   - No campo do **widget**, envie o **telium-pabx-kommo.zip**. Salve.
3. **Gere o token.** Ainda na integração, aba **Chaves e escopos**, clique em **Gerar token de longo prazo**. Escolha a validade (até 5 anos) e copie o token: o Kommo não o mostra de novo.
4. **Configure a central.** Na seção **Kommo (CRM)**, preencha **Conta do Kommo** (ex.: `suaempresa.kommo.com`), cole o **Token de longo prazo**, informe o **DDD da central**, ligue a integração e **Salve**. Clique em **Testar conexão**: deve aparecer "Conectado à conta …".
5. **Gere a chave do app.** Clique em **Gerar chave** e copie a chave (`tlm_c2c_…`). Ela também só aparece uma vez.
6. **Instale o app no Kommo.** Na lista de integrações do Kommo, abra **Telium PABX** e instale. Preencha:
   - **Endereço da central:** o endereço que a seção Kommo da central mostra no passo a passo (ex.: `https://voz.suaempresa.com.br`);
   - **Chave da central:** a chave do passo 5.
7. **Ligue cada pessoa ao ramal dela.** De volta à central, clique em **Buscar usuários do Kommo**, escolha o ramal de cada usuário e clique em **Salvar ramais**.
8. **Teste.** No Kommo, abra um contato, clique no telefone dele e escolha **Ligar pelo PABX**. O seu ramal toca; atenda e a central disca o cliente. Uns dois minutos depois de desligar, a chamada aparece no histórico do contato e na lista **Chamadas levadas ao Kommo**, na central.

---

## 3. Os campos da tela

| Campo | O que é |
| --- | --- |
| **Integração ligada** | Desligada, nada vai para o Kommo e o app não liga. Ao ligar, só as chamadas **a partir daquele momento** são enviadas: o histórico antigo da central não é despejado no Kommo. |
| **Conta do Kommo** | `suaempresa` ou `suaempresa.kommo.com` (contas antigas do amoCRM também servem). Outro domínio não é aceito, porque o token vai junto em cada pedido. |
| **Token de longo prazo** | O token gerado na integração privada do Kommo. Depois de salvo, a tela mostra só os quatro últimos caracteres; em branco, o atual é mantido. |
| **Endereço da central para o Kommo** | O começo do link das gravações. Em branco, vale o nome da central (`https://<nome>`). Troque se o Kommo chega à central por outro nome. |
| **DDD da central** | Completa o número local (8 ou 9 dígitos) que chega do tronco, para o Kommo achar o contato. |
| **Prefixo para discar** | Vai na frente do número que o app manda, se o ramal disca um 0 (ou outro código) para sair. O `+55` do Kommo é sempre tirado. |
| **Discar sem o DDD quando é o da central** | Para centrais cuja rota de saída de ligação local não aceita o DDD. |
| **Registrar as chamadas recebidas / feitas** | Escolhe o que vai para o Kommo. |
| **Número que não é contato vira lead de entrada** | Ligação recebida de número que não está em nenhum contato vira um **lead de entrada** (a coluna Entrada do funil), com a chamada no cartão, para alguém aceitar e criar o contato. Desmarcado, a chamada fica só na lista da central, como "sem contato". |

---

## 4. Usuários do Kommo e ramais

**Buscar usuários do Kommo** traz os usuários da conta. Cada um ganha um ramal na lista. O ramal serve para duas coisas:

- **no clique para ligar**, é o ramal que toca quando aquela pessoa clica: o app manda quem clicou, e a central decide o ramal. Ninguém consegue, pelo Kommo, fazer tocar o ramal de outra pessoa;
- **no registro**, é por ele que a central sabe de quem é a chamada: a chamada do ramal 1001 entra no Kommo como feita (ou atendida) pelo usuário ligado ao 1001.

Usuário **sem ramal** não liga pelo Kommo (recebe o aviso "ainda não tem ramal na central"), e as chamadas de um ramal sem usuário entram no Kommo **sem dono** (aparecem como feitas pelo "robô"). Usuário que saiu do Kommo fica marcado **saiu do Kommo** e para de ligar e de receber chamadas.

Busque de novo sempre que entrar alguém no Kommo: quem já tinha ramal continua com ele.

---

## 5. O clique para ligar

- O botão **Ligar pelo PABX** aparece ao clicar em qualquer telefone no cartão do contato, do lead ou da empresa, e nas listas.
- A central faz exatamente o que o click to call faz: confere se o ramal está registrado, toca o ramal (com o nome do cliente no visor) e, quando ele atende, disca pelas **rotas de saída e permissões do próprio ramal**. O app não alcança o que o ramal não alcançaria.
- O número do Kommo (`+55 11 99999-0000`) vira o que o ramal discaria: sem o `+55`, com o **prefixo** e, se marcado, sem o DDD local. Número estrangeiro vai com `00` na frente.
- Se o telefone aceita, dá para pedir que ele **atenda sozinho** (viva-voz): edite a chave **Kommo (app do CRM)** em **Click to call** e marque **Atender sozinho**.
- Cada pedido aparece em **Click to call › Últimos pedidos**, como **app do Kommo**, com o resultado. É o primeiro lugar para olhar quando alguém diz que clicou e nada aconteceu.

O app chama a central direto do navegador. Se o navegador não alcançar a central (rede da empresa, firewall), o app tenta pelo servidor do Kommo, que chega à central pela internet.

---

## 6. O registro das chamadas

A cada minuto, a central lê as chamadas que **terminaram há pelo menos 90 segundos** (para já ter a fila, a transferência e a gravação fechadas) e manda cada uma ao Kommo.

| No Kommo | Vem de |
| --- | --- |
| Sentido | Feita (saída) ou recebida (entrada). Ligação interna, entre ramais, não vai. |
| Telefone | O número do cliente, com `+55` e DDD. O Kommo procura o contato por ele. |
| Data e hora | O começo da chamada. |
| Duração | O tempo de conversa (zero quando não foi atendida). |
| Responsável e autor | O usuário do Kommo do ramal que fez ou atendeu. |
| Resultado | Conversou, não atendeu, ocupado ou sem linha. |
| Resumo | Uma linha com tudo, por exemplo: *Ligação feita por Ana Vendas (ramal 1001) para +55 11 99999-0000 em 28/10/2026 às 14:12 — atendida, 1min 35s de conversa.* Na recebida: *Ligação recebida de +55 21 98888-7777 no número 1133334444 em 28/10/2026 às 14:20 — atendida por Bruno Suporte (ramal 1002), 40s de conversa.* |
| Gravação | O link para ouvir, quando a chamada foi gravada. |

A ligação recebida que passou por vários ramais (fila, siga-me) é **um registro só**, com o dono sendo quem atendeu. Se ninguém atendeu, o registro diz para quem tocou.

A lista **Chamadas levadas ao Kommo**, na central, mostra as 50 mais recentes e o que aconteceu com cada uma:

| Estado | Significa |
| --- | --- |
| **no histórico** | Entrou no contato (ou lead, ou empresa) indicado. |
| **lead de entrada** | O número não era contato, e virou lead de entrada. |
| **sem contato** | O número não é contato, e o lead de entrada está desligado (ou a chamada foi feita, não recebida). |
| **erro** | O Kommo não aceitou (token vencido, conta sem assinatura, Kommo fora do ar). A central tenta de novo a cada 5 minutos, até 5 vezes. |

**Enviar agora** manda o que está pendente sem esperar o próximo minuto.

---

## 7. As gravações

O link que vai ao Kommo é da própria central, assinado:

```
https://voz.suaempresa.com.br/api/kommo/gravacao/<id da chamada>/<assinatura>.wav
```

- Só toca com a assinatura certa: quem adivinhar o id de uma chamada não ouve nada.
- A gravação **não é copiada** para o Kommo: o player busca o arquivo na central cada vez que alguém ouve. Por isso a central precisa estar alcançável de onde se usa o Kommo, e a gravação some do Kommo quando o **prazo de guarda** das gravações (Aplicações › Gravações) a apaga da central.
- O link funciona para quem o tiver: trate o histórico do Kommo como trata o arquivo de gravações.

---

## 8. Problemas comuns

| Sintoma | Causa provável | O que fazer |
| --- | --- | --- |
| "Testar conexão" diz que o Kommo recusou o token | Token vencido, revogado ou de outra conta | Gere outro token na integração e cole na central. |
| O botão **Ligar pelo PABX** não aparece | App não instalado, ou instalado sem salvar as configurações | No Kommo, abra a integração Telium PABX e confira se está instalada. |
| "Configure o endereço e a chave da central" | Faltou preencher o app | Preencha **Endereço da central** e **Chave da central** no app. |
| "Chave do app inválida" | A chave foi gerada de novo na central | Copie a chave nova para o app. |
| "Não foi possível falar com a central" | O navegador e o Kommo não chegam à central | Confira o endereço (com https), o certificado e se a porta 443 está publicada. |
| "… ainda não tem ramal na central" | O usuário não tem ramal escolhido | Em **Usuários do Kommo e ramais**, escolha o ramal e salve. |
| "O ramal … não está registrado" | O telefone da pessoa está desligado ou fora da rede | Ligue o telefone; confira em **Conectividade › Ramais**. |
| "O ramal … não tem rota de saída para …" | O número, do jeito que vai, não casa com as rotas de saída | Ajuste o **Prefixo para discar** (o 0 da linha externa) ou o **DDD**. |
| A chamada não aparece no contato, só como **sem contato** | O telefone no Kommo está diferente do que chegou | Confira o número no contato (com DDD). |
| Aparece **erro** em todas as chamadas | Token, assinatura do Kommo ou saída para a internet | Clique em **Testar conexão** e leia a mensagem. |
| O player do Kommo não toca | Central sem https válido, ou não alcançável de onde se ouve | Confira o certificado e o **Endereço da central para o Kommo**. |

---

## 9. Para o técnico: como funciona por dentro

| Peça | Onde |
| --- | --- |
| Tabelas | `kommo_config` (linha única), `kommo_usuarios` (usuário do Kommo e ramal), `kommo_chamadas` (cada chamada enviada, pelo `linkedid` do CDR). Script: `infra/sql/50-kommo.sql`. |
| Envio | `php bin/telium kommo-enviar` (com `-v` mostra o resumo mesmo sem nada novo), rodado pelo temporizador `telium-kommo.timer` a cada minuto. Instalado em toda central; sem a integração ligada, não faz nada. |
| Código | `api/src/Dominio/Kommo.php` (CDR → registro, API do Kommo), `api/src/Http/Controllers/Kommo.php` (tela e portas do app). |
| App do Kommo | `api/recursos/kommo-app/` (manifest, script e imagens); a central monta o zip na hora. |
| Portas sem sessão | `POST /api/kommo/discar` (chave do app; CORS só para as telas do Kommo) e `GET /api/kommo/gravacao/...` (link assinado). |
| API do Kommo usada | `GET /api/v4/account`, `GET /api/v4/users`, `POST /api/v4/calls`, `POST /api/v4/leads/unsorted/sip`. |

Comandos úteis no servidor:

```sh
systemctl status telium-kommo.timer          # o temporizador está ativo?
journalctl -u telium-kommo --since today     # o que foi enviado e os erros
sudo -u teliumpbx php /opt/telium/api/bin/telium kommo-enviar -v   # enviar agora, com resumo
```
