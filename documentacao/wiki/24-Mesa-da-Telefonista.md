# Mesa da Telefonista — Telium PABX

O painel da recepção: todos os ramais da central numa tela só, ao vivo, para saber antes de passar uma ligação quem está livre, quem está falando, quem está tocando e quem pediu para não ser incomodado.

> Os pontos marcados com [PRINT: ...] indicam onde colocar um print da tela.

---

## Sumário

1. [Liberar a tela](#1-liberar-a-tela)
2. [A tela](#2-a-tela)
3. [Os estados do ramal](#3-os-estados-do-ramal)
4. [Não perturbe](#4-não-perturbe)
5. [Ligar, transferir e puxar](#5-ligar-transferir-e-puxar)
6. [Problemas comuns](#6-problemas-comuns)

---

## 1. Liberar a tela

**Telefonista › Mesa da Telefonista** é um módulo próprio (`tel.mesa`). Nos perfis de fábrica só o Administrador o tem.

Para a recepcionista:
1. Em **Administrador › Perfis e Permissões**, escolha (ou crie) o perfil dela e marque **Mesa da Telefonista**.
2. Para ela **pôr e tirar o não perturbe** dos ramais, marque também a ação **Editar registros**. Sem ela, a tela mostra o não perturbe mas não deixa mudar.
3. Em **Administrador › Gerenciador de Usuários**, vincule o **ramal** da recepção à conta dela. É desse ramal que saem as ligações, as transferências e as capturas.
4. Se ela atende pelo navegador, marque também **Telefone (WebRTC)** ([Telefone WebRTC](11-Telefone-WebRTC.md)).

Quem tem só a Mesa da Telefonista entra no console direto nela.

---

## 2. A tela

[PRINT: Mesa da Telefonista com ramais em vários estados]

- **Contadores no topo**: Todos, Disponíveis, Em chamada, Tocando, Não perturbe e Offline. Clicar num contador mostra só aqueles ramais; clicar de novo volta a mostrar todos.
- **Busca** por número, nome ou setor, e **filtro por setor** (aparece quando os ramais têm setor no cadastro).
- **Um cartão por ramal ativo**, com o número, o nome, o setor, o estado e, durante uma ligação, **com quem** (nome e número, com a seta de recebida ou feita) e **há quanto tempo**.
- O cartão do ramal da própria telefonista vem marcado como **(você)**.
- A tela muda **no instante** em que o ramal toca, atende, desliga ou põe em espera, sem perder o que foi digitado na busca. O não perturbe ligado pelo telefone (*78/*79) aparece em até 3 segundos.
- O selo no alto diz como a tela está recebendo:

| Selo | O que quer dizer |
|---|---|
| **Ao vivo** (verde) | Recebendo do serviço de tempo real (`telium-cc`) a cada mudança |
| **Atualiza a cada 3 s** (amarelo) | O serviço de tempo real não respondeu; a tela pergunta à central a cada 3 segundos e tenta o tempo real de novo a cada minuto |
| **Asterisk não respondeu** / **Sem conexão** (vermelho) | Sem leitura da central ou do servidor |

---

## 3. Os estados do ramal

| Estado | Cor | O que quer dizer |
|---|---|---|
| **Disponível** | verde | Registrado e livre |
| **Tocando** | laranja, piscando | Uma ligação está chegando e ninguém atendeu ainda |
| **Chamando** | azul | O ramal discou e espera o outro lado atender |
| **Em chamada** | azul | Falando |
| **Em espera** | laranja | A ligação está em espera (hold) no aparelho |
| **Offline** | cinza | Nenhum aparelho registrado: desligado, sem rede ou com a senha errada |
| **Sem leitura** | cinza | A central não respondeu; o cartão volta sozinho quando ela responder |

Ramal com **mais de um aparelho** (mesa e softphone, por exemplo) aparece em chamada quando qualquer um deles atende.

---

## 4. Não perturbe

O botão **DND** de cada cartão põe o ramal em não perturbe; o botão vermelho **Não perturbe** tira. Vale na hora, igual ao **\*78 / \*79** discado no aparelho, e o que for feito no aparelho aparece na tela na próxima atualização.

Ramal em não perturbe tem a borda vermelha e entra no contador **Não perturbe**, esteja ele livre, falando ou offline. Ligação para ele não toca: segue o destino de ocupado do ramal (correio de voz, quando houver).

Cada mudança fica registrada na **Auditoria**, com quem fez.

---

## 5. Ligar, transferir e puxar

Os botões aparecem quando a conta tem ramal vinculado.

| Botão | Quando aparece | O que faz |
|---|---|---|
| **Ligar** | A telefonista não está em ligação | Liga para o ramal. Pelo telefone do navegador, se estiver registrado; senão o aparelho de mesa dela toca primeiro e, ao atender, a central chama o ramal |
| **Transferir** | A telefonista está numa ligação atendida | Passa a ligação para o ramal, **sem consulta** (o mesmo que **##** e o ramal). A pessoa do outro lado vai para o ramal escolhido e o aparelho da telefonista fica livre. Funciona também com ligação que veio de fila |
| **Puxar** | O ramal está tocando | Atende no ramal da telefonista a ligação que está tocando no outro ramal (captura direta, o código **\*\*** + ramal). Some se o código de captura direta estiver desligado em Códigos de Recurso |

Para transferir **com consulta** (falar antes com quem vai receber), use o aparelho ou o telefone do navegador.

---

## 6. Problemas comuns

**A tela não aparece no menu.** O perfil não tem **Mesa da Telefonista**. Depois de marcar, a pessoa precisa entrar de novo (ou recarregar com Ctrl + Shift + R).

**Não aparecem os botões Ligar, Transferir e Puxar.** A conta não tem ramal vinculado; o aviso aparece ao lado da busca.

**O botão DND não aparece, só o selo.** O perfil não tem a ação **Editar registros**.

**O selo fica em "Atualiza a cada 3 s".** O serviço de tempo real está parado. No servidor: `sudo systemctl status telium-cc` e, se preciso, `sudo systemctl restart telium-cc`. O log fica em `journalctl -u telium-cc`.

**Todos os ramais em "Sem leitura".** O console não fala com o Asterisk. Veja [Solução de problemas](07-Solucao-de-Problemas.md).

**"O ramal não está em nenhuma ligação atendida agora" ao transferir.** A ligação ainda não foi atendida no ramal da telefonista, ou já caiu.

**Ramal aparece offline mas o aparelho diz que está registrado.** Confira em **Conectividade › Ramais** e em `pjsip show contacts` no servidor. Normalmente é o aparelho registrado em outra central ou com o endereço errado.
