# Telium PABX 1.0.1 — o que testar

Versão para teste em VM. Este documento é a lista do que mudou e de como
conferir cada coisa. O que já foi verificado automaticamente está marcado;
o que precisa de telefone de verdade está separado no fim.

## Como colocar a 1.0.1 na VM

```sh
cd /opt/telium-src && git pull
cd infra/ansible && sudo ansible-playbook site.yml
```

O playbook publica os scripts de banco, aplica todos eles, manda o código
novo, reescreve os `.conf` e recarrega o Asterisk. Se só o banco estiver
atrasado, `php bin/telium migrar` no `/opt/telium/api` resolve.

O playbook pode ser rodado quantas vezes quiser: ele só mexe no que
mudou. Nada de reinstalar, nada de apagar configuração.

Duas coisas o Asterisk só lê ao subir — o `asterisk.conf` e a lista de
módulos. Quando elas mudam, o playbook agenda um reinício para quando a
central estiver sem chamada nenhuma e avisa no final. Para conferir se
já aconteceu:

```sh
sudo asterisk -rx "core show uptime"
```

Se preferir não esperar (derruba as chamadas em curso):

```sh
sudo systemctl restart asterisk
```

Confirme a versão em três lugares: a tela de entrada, o rodapé do menu e
`curl -sk https://SEU-IP/api/health | grep versao`.

## Antes de qualquer coisa: o console do Asterisk

```sh
sudo asterisk -rx "core reload"
```

Não deve sair **nenhum** `ERROR`. Numa instalação nova a 1.0.0 saía com
cerca de 35. Se aparecer algum, é regressão — vale reportar com a linha.

```sh
sudo asterisk -rx "pjsip show endpoints"     # tem de listar os ramais
sudo asterisk -rx "pjsip show transports"    # udp, tcp, tls e wss
sudo asterisk -rx "queue show"
sudo asterisk -rx "confbridge show profile bridges"
sudo asterisk -rx "parking show"
```

## Conferido automaticamente nesta versão

- Os 14 scripts de banco aplicam do zero e reaplicam sem erro (45 tabelas).
- As 29 rotas de listagem da API respondem 200.
- 23 arquivos de configuração são gerados a partir do banco.
- Tudo carrega num Asterisk 22: 36 contextos, 121 extensões, 653
  prioridades, 3 endpoints, 4 transportes, 2 filas, 1 sala de conferência,
  2 lotes de estacionamento, 3 caixas postais — com zero erro no log.
- O caminho inteiro de uma chamada entrante está ligado ponta a ponta:
  rota de entrada → condição horária → URA → fila → pesquisa de satisfação.

### Chamada de verdade, num Asterisk 22 rodando

Estes foram conferidos com telefones registrados e uma operadora
simulada, olhando o que sai no SIP e o arquivo que nasce no disco:

- Registro de ramal com autenticação digest, inclusive o WebRTC pelo
  WebSocket (`REGISTER` → `401` → `200 OK`).
- Ramal chamando ramal, com a gravação nascendo pela regra combinada dos
  dois lados e uma única vez.
- Chamada entrando pelo DID, caindo na fila e sendo atendida por um
  agente, com o `queue show` marcando a chamada completada.
- Rota de saída: o `From` que chega na operadora leva o número do ramal
  quando ele tem um próprio, e o do tronco quando não tem; o prefixo a
  remover sai do número; o tronco reserva só é tentado depois de falha
  de verdade, nunca depois de uma chamada atendida.
- Ramal sem permissão de celular é barrado antes de qualquer INVITE.
- Ramal com teto de chamadas de saída tem a segunda barrada.
- Número interno inexistente recebe aviso em vez de silêncio.
- `core reload` não escreve nenhum ERROR nem WARNING.

## WebRTC: o que já foi provado e o que falta

Provado com nginx e Asterisk de verdade:

- `transport-wss` carrega (`pjsip show transports`).
- O Asterisk publica `/asterisk/ws` (`http show status`).
- O caminho inteiro faz o upgrade: `https://SEU-IP/ws` → nginx → Asterisk
  responde **101 Switching Protocols** com `Sec-WebSocket-Protocol: sip`.
- Um ramal com WebRTC ligado sai com o perfil completo — conferido no
  `pjsip show endpoint`: `use_avpf`, `ice_support`, `rtcp_mux`,
  `media_encryption: dtls` e o par DTLS do módulo de Certificados.
- O endereço do WebSocket é montado pela origem da página, então funciona
  tanto por IP quanto por nome, com o certificado que o navegador já aceitou.

**Falta o que só um navegador de verdade mostra:** o registro do JsSIP, o
aperto de mão DTLS e o áudio. É o primeiro item da lista abaixo.

## O que testar na tela

**Ramais.** O cadastro tem oito abas. Crie um ramal usando o botão de
gerar senha; confira que a senha curta é recusada e que o número aceita só
dígitos. Registre um telefone e veja se ele aparece em
`pjsip show contacts`.

**Softphone do navegador.** Marque *Softphone do navegador (WebRTC)* no
ramal, vincule esse ramal ao seu usuário do console (Gerenciador de
Usuários) e recarregue a tela. O discador deve registrar sozinho e dizer
"Ramal 1003 registrado" no cabeçalho. Ligue de um ramal para o outro.

**Aplicar configurações.** Salve qualquer coisa e veja a barra amarela
aparecer no topo. Clique em aplicar: ela deve ficar verde e listar as
recargas. Se alguma falhar, o botão "Ver a saída" mostra o que o Asterisk
respondeu.

**Códigos de recurso.** 64 códigos em 19 categorias. Teste pelo menos
`*43` (eco), `*65` (fala o ramal), `*78`/`*79` (não perturbe) e
`*72 <destino>` seguido de `*73` (desvio).

**URA.** Monte uma com saudação, duas teclas e o destino de tempo
esgotado. Ligue para ela e confira que a tecla leva ao destino certo e que
insistir numa tecla inválida acaba no destino de inválido.

**Fila.** Com sussurro e confirmação ligados, o agente deve ouvir o
anúncio e precisar apertar 1 para assumir. Com a pesquisa escolhida, o
cliente cai nela quando o atendente desliga.

**Gravações.** Grave uma chamada, ouça na tela, baixe uma e leve duas num
zip. Confirme que não existe botão de apagar.

**Certificados.** Gere um autoassinado e aplique no serviço web. A barra
deve avisar, o nginx recarregar e o certificado anterior voltar sozinho se
algo der errado.

## O que ainda não existe

DISA, Megafonia e Interfonia (o código `*80` funciona, falta a tela),
Texto em Voz, as telas do PCU e o módulo de call center. Os menus estão
lá e abrem a página de "não implementado".

No cadastro do ramal, estes campos são gravados mas ainda não mudam o
comportamento da central — vale saber antes de prometer para um cliente:

| Campo | Situação |
| --- | --- |
| Descrição do DID | só anotação |
| CID de entrada | não muda o que o ramal vê chegar |
| Atender sozinho chamada interna | falta o cabeçalho de auto-resposta |
| Interfonia | o código `*80` não consulta a preferência do ramal |
| Permitir rastrear a última chamada | o `*69` vale para todos |
| Ditado | falta ligar o app_dictate |
| Prioridade da regra de gravação | o desempate hoje é fixo: "nunca" de
  qualquer lado proíbe, "forçar" de qualquer lado obriga |

## Se algo quebrar

`sudo bash infra/diagnostico.sh` e `sudo ansible-playbook verificar.yml`
dão o retrato do servidor. Para um erro de tela, o console do navegador
(F12) diz o arquivo e a linha.
