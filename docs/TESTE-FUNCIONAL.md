# Roteiro de teste funcional

Revisão dos 63 módulos do console para montar um ambiente de teste e
exercitar cada função. Escrito para quem vai testar, não para quem
escreveu o código.

Cada módulo traz **como testar** e um **estado**, que significa:

| Estado | O que quer dizer |
|---|---|
| **automático** | a bateria (`php bin/telium testar`) cobre; falha ali interrompe a entrega |
| **provado** | já foi conferido de ponta a ponta com Asterisk de verdade |
| **falta provar** | implementado e revisado, sem teste de ponta a ponta — é o que este roteiro pede |
| **não faz nada** | a tela existe e o efeito não; está detalhado em [PENDENCIAS.md](PENDENCIAS.md) §2 |

Não confie no estado: a ideia é você derrubar o que estiver errado.

---

## 1. Montar o ambiente

```sh
cd /opt/telium-src && git pull
cd infra/ansible
sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false \
                               -e cenario_teste=true
```

O `-e cenario_teste=true` carrega um cenário pronto — sem ele, testar
qualquer coisa começa com vinte cadastros à mão:

| O que | Número | Para quê |
|---|---|---|
| Ramais de mesa | 1001, 1002 | ramal chama ramal, transfere, estaciona |
| Ramal do navegador | 1003 | WebRTC, com DTLS/ICE/AVPF/rtcp-mux ligados |
| Ramal restrito | 1004 | só liga local — prova o bloqueio por permissão |
| Grupo de toque | 2000 | toca 1001 e 1002 juntos |
| Fila | 3000 | agentes 1001 e 1002, rodízio com memória |
| Conferência | 4000 | PIN `1234`, admin `4321` |
| URA | — | teclas 1 → fila, 2 → 1001, 3 → conferência, 9 → desliga |
| Horário | Comercial | seg-sex 8h-18h; fora, cai no correio de voz |
| Rota de entrada | 1140041000 | DID vai para a URA |
| Rotas de saída | local e celular | usam o primeiro tronco cadastrado |

As duas rotas de saída **só nascem se já houver tronco cadastrado** — sem
tronco elas não teriam para onde apontar. Cadastre o tronco primeiro e
rode o cenário de novo, ou crie as rotas pelo console.

Senha SIP de todos os ramais: `T3st3_R4mal_@2024`.
Número que já existir **fica como está** — o script não renomeia nada.

Para desfazer tudo: `-e limpar_banco=true` apaga a base operacional e
recria só empresa, perfis e o admin.

### Antes de testar qualquer função

```sh
sudo asterisk -rx "core reload"            # não pode sair nenhum ERROR
sudo asterisk -rx "pjsip show endpoints"   # os ramais do cenário
sudo asterisk -rx "pjsip show transports"  # udp, tcp, tls, wss
sudo asterisk -rx "queue show 3000"        # a fila com dois agentes
sudo -u telium php /opt/telium/api/bin/telium testar
```

A bateria termina em `✓ N testes, nenhuma falha`. Qualquer falha aqui
invalida o resto do roteiro — corrija antes de seguir.

---

## 2. Painel de Indicadores

São telas de leitura: perguntam ao Asterisk a cada carga e não guardam
estado. Por isso não existe chamada fantasma — e por isso não atualizam
sozinhas, o que é decisão registrada em PENDENCIAS §4.

### Visão Geral, Estatísticas do Sistema, Asterisk Info — **falta provar**
Com dois ramais registrados e uma chamada em curso, os números têm de
bater com `pjsip show contacts` e `core show channels`.

### Tempo Real (Wallboard) — **falta provar**
Abra durante uma chamada na fila 3000. O agente e o chamador têm de
aparecer, e sumir quando a chamada encerra. Recarregue a tela para
atualizar — ela não se atualiza sozinha.

---

## 3. Conectividade — o caminho da chamada

Comece por aqui: sem isto, nada mais vale.

### Ramais — **provado**
Registre um softphone em 1001 e outro em 1002.
`pjsip show contacts` tem de mostrar os dois como `Avail`.
Ligue 1001 → 1002: toca, atende, áudio nos dois sentidos.

### Troncos — **provado** (cadastro e publicação) · **falta provar** (operadora)
A coluna **Na central** é o teste: tem de dizer `no ar`. Se disser
outra coisa, ela explica o quê — não publicado, senha faltando,
registro recusado. Confira contra `pjsip show registrations`.

### Rotas de saída — **provado** (bloqueio e CID) · **falta provar** (completar)
- De 1001, disque um externo: tem de sair pelo tronco com o CID certo.
- De **1004**, disque um celular: tem de ser barrado antes de discar.
- Quando não completar, **Relatórios → Chamadas** mostra o motivo que a
  operadora deu (`SIP 404`, `SIP 403`…). Esse campo é novo: confira que
  aparece.

### Rotas de entrada — **provado**
Ligue para o DID 1140041000 de fora: tem de cair na URA.

### DIDs, Firewall, Configurações de Rede, WebRTC — **provado** (leitura)
São telas de diagnóstico. Confira que **Rede** mostra os quatro
transportes e que o aviso de STUN bloqueante **não** aparece.

### Provisionamento — **não faz nada**
Guarda o MAC e nada mais. Não gaste tempo aqui.

---

## 4. Aplicações

### Filas — **provado**
Entre na fila 3000 por dentro e pela URA. Com 1001 e 1002 registrados,
o rodízio alterna. Pause um agente pelo console e confira que ele para
de receber. Teste o estouro: deixe tocar além do tempo e veja cair no
1001.

### Grupos de toque — **falta provar**
Disque 2000: 1001 e 1002 tocam juntos; quem atender primeiro fica.

### URA — **falta provar**
Disque o DID e teste cada tecla, inclusive uma inválida e o silêncio
até o timeout. Com discagem direta ligada, digitar `1001` vai ao ramal.

### Conferências — **falta provar**
Dois ramais em 4000 com PIN `1234`. Entre como admin (`4321`) e use a
tecla 2 para trancar a sala.

### Gravação de chamadas — **provado** (regras) · **falta provar** (ouvir)
As quatro regras por ramal já foram conferidas. Falta ouvir o arquivo
em **Administrador → Gravações** e confirmar que os dois lados estão lá.

### Anúncios, Condições horárias, Grupos de horário — **falta provar**
Mude a faixa do grupo Comercial para excluir o horário atual, aplique, e
confirme que a chamada passa a cair no destino de fora do expediente.

### Siga-me — **falta provar**
Ligue o siga-me de 1001 para 1002 e confirme o desvio. Teste os dois
modos (junto e depois).

### Correio de voz — **provado** (geração) · **falta provar** (deixar recado)
Deixe um recado em 1001, confira o e-mail e ouça pelo portal do usuário.

### DISA, Estacionamento, Megafonia e Interfonia, Despertar — **falta provar**
São os recursos que só um telefone de verdade prova. A megafonia tem o
cabeçalho de atendimento automático já conferido no INVITE; falta ver o
aparelho abrir o viva-voz.

---

## 5. Administrador

### Usuários, Perfis e Permissões — **automático**
Crie um perfil com **um único módulo** marcado, entre com ele e abra a tela
desse módulo. Ela tem de funcionar inteira — nenhuma chamada pode voltar
403. Foi assim que se descobriu que 19 telas dependiam da permissão de
outro módulo para montar o seletor de destino.
A bateria já garante que nenhuma rota responde sem sessão e que o perfil
limita o menu. À mão, vale entrar com um Operador e confirmar que ele vê
só o PCU.

### Backup e Restauração — **falta provar**
Gere um backup, baixe, restaure numa VM limpa. É o teste mais demorado e
o mais importante de todos: um backup que não restaura não é backup.

### Certificados — **falta provar**
Envie um par real e confirme que o console e o SIP TLS passam a usá-lo.

### Lista negra e Allowlist — **provado** (dialplan)
Ponha seu celular na lista negra e ligue: tem de ser barrado.

### CLI Asterisk, Gravações, Contatos, Destinos, Códigos de recurso — **falta provar**
Telas de apoio. Os códigos de recurso (`*` algo) precisam de telefone.

---

## 6. Relatórios

Todos dependem de haver chamada registrada — faça os testes das seções 3 e
4 primeiro, depois volte aqui.

### CDR — **provado**
Confira que a chamada aparece com sentido, tronco, duração e, quando
falha, **o motivo da operadora**.

### Filas, Ramais, Troncos, Agentes, CEL, Atividades — **falta provar**
Compare os números com o que você acabou de fazer. O relatório de
atividades tem de registrar cada alteração que você fez no console.

---

## 7. Configurações

### Arquivos e Aplicação — **automático**
É a tela do "Aplicar configurações", que toda alteração depende. A
bateria cobre o aplicar inteiro. À mão: altere um ramal, confira que o
aviso de configuração pendente acende, aplique e veja todas as etapas em
`ok`. Se alguma falhar, a saída bruta do comando fica ali mesmo.

### SIP/PJSIP, Correio de voz, Música em espera, Conjuntos de PIN, Fax — **falta provar**
Conjuntos de PIN: ponha o conjunto "Diretoria (teste)" numa rota de
saída e confirme que ela pede o PIN e recusa o errado.

### Notificações e E-mail — **provado** (com servidor de captura)
Falta confirmar com Gmail ou Microsoft 365, onde senha de aplicativo e
domínio do remetente costumam ser o que barra.

### Dados da empresa — **falta provar**

### Tabela de tarifas — **não faz nada**

---

## 8. Telium

### Tarifação — **não faz nada**
O custo é sempre R$ 0,00. Nada escreve `cdr.custo`.

### Integrações / API — **não faz nada**
Guarda webhooks e chaves; nenhuma URL é chamada.

### Suporte — **falta provar**

---

## 9. PCU — Portal do Usuário

Entre com um usuário de perfil Operador vinculado ao ramal 1001.

### Click-to-call — **falta provar**
Na agenda ou no relatório de chamadas, clique em **Ligar**. Com o
softphone do navegador registrado, a chamada sai por ele. Sem ele, a
central liga para o seu ramal e, quando você atende, disca o destino —
teste com um aparelho de mesa, que é o caso de quem não usa o navegador.
O número discado passa pela sua permissão e pela rota de saída como se
você tivesse digitado no aparelho.

### Meu Ramal, Minhas Chamadas, Meu Correio de Voz, Meu Siga-me, Meus Contatos, Meu Perfil — **falta provar**
O ramal vem sempre da sessão, nunca do que o navegador manda — vale
tentar trocar o ramal na requisição e confirmar que não adianta.

---

## 10. O que só telefone, operadora ou cliente provam

Copiado de [PENDENCIAS.md](PENDENCIAS.md) §3, porque é a lista que
fecha a entrega:

- áudio nos dois sentidos, perda de pacote, jitter, troca de rede no
  meio da chamada;
- transferência assistida e cega, hold com música, dois aparelhos com o
  mesmo ramal;
- DTMF por RFC4733 numa URA de verdade, com aparelho de mesa;
- chamada externa real entrando e saindo, e o que acontece quando a
  operadora cai no meio;
- fax T.38 de verdade;
- e-mail por Gmail ou Microsoft 365.

---

## 11. Como reportar

Para cada falha, o que resolve mais rápido:

```sh
sudo asterisk -rx "pjsip set logger on"
# reproduza
sudo asterisk -rx "pjsip set logger off"
sudo tail -200 /var/log/asterisk/full
```

Mande o trecho do log **e** o que você esperava que acontecesse. Erro de
tela: abra o console do navegador (F12) e copie o que estiver em
vermelho.
