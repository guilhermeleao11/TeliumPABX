# 04 — Verificação e problemas comuns

## A central está de pé?

### 1. A bateria de testes

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium testar
```

Confere banco, geração da configuração, Asterisk, áudios, permissões,
certificados e muito mais. **Tem de terminar sem falha.** Se falhar, pare
e resolva: nada que você testar depois será confiável.

### 2. Os serviços

```bash
systemctl is-active mariadb nginx php8.4-fpm asterisk telium-cc coturn fail2ban
```

Todos `active`.

### 3. O Asterisk

```bash
sudo asterisk -rx "core show uptime"
sudo asterisk -rx "pjsip show transports"      # udp, tcp, tls e wss
sudo asterisk -rx "pjsip show contacts"        # os aparelhos registrados
sudo asterisk -rx "pjsip show registrations"   # o registro na operadora
curl -sk https://127.0.0.1/api/health
```

### 4. O diagnóstico completo

```bash
cd /opt/TeliumPABX/infra/ansible
sudo ansible-playbook verificar.yml
```

Serviços, versões, portas em escuta, banco, saída do Asterisk e os
últimos erros, num só relatório. **É a saída a mandar quando pedir ajuda.**

### Comandos úteis

```bash
sudo -u teliumpbx php /opt/telium/api/bin/telium doctor          # banco, AMI e permissões
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config  # gera e recarrega o Asterisk
sudo -u teliumpbx php /opt/telium/api/bin/telium migrar          # aplica o banco que faltar
sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin     # troca a senha de um usuário
sudo asterisk -rvvv                                              # console do Asterisk
tail -f /var/log/asterisk/full                                   # log do Asterisk
tail -f /var/log/telium/php-api.log                              # log da API
journalctl -u telium-cc -f                                       # serviço do call center
```

O console tem um terminal do Asterisk em **Administrador › CLI Asterisk**.

---

## Problemas comuns

### A chamada completa, mas ninguém ouve (ou só um lado ouve)

Quase sempre é o **áudio (RTP)** não chegando.

1. Confira que as portas **UDP 10000–20000** chegam ao servidor, inclusive
   no firewall da nuvem ou do roteador na frente dele.
2. **Conectividade › Configurações de Rede**: o IP público tem de ser o
   do servidor (use **Descobrir**) e as redes locais têm de incluir a rede
   dos telefones. Depois, **Aplicar configurações**.
3. Em servidor atrás de NAT, a faixa de RTP precisa estar redirecionada
   para ele.

### O console abre, mas uma tela ou botão novo não aparece

É o navegador mostrando a versão antiga. Aperte **Ctrl + Shift + R** (no
Mac, Cmd + Shift + R). Se continuar, confira que o servidor foi mesmo
atualizado: `cd /opt/TeliumPABX && git log --oneline -1`, e que o playbook
rodou depois do `git pull`. Também vale conferir se o seu perfil tem a
permissão daquela tela.

### Entra pelo IP, mas não pelo nome (ou o contrário)

1. O nome aponta para este servidor? `nslookup voz.cliente.com.br` deve
   devolver o IP público dele.
2. O certificado é desse nome? Em **Administrador › Certificados**, o
   certificado em uso tem de cobrir o nome digitado. Com o certificado de
   fábrica, o navegador avisa e às vezes bloqueia.
3. O nome está no `servidor.yml` como `pabx_hostname`? Depois de mudar,
   rode o playbook de novo.
4. Não sendo nada disso: teste do próprio servidor com
   `curl -k --resolve voz.cliente.com.br:443:127.0.0.1 https://voz.cliente.com.br/api/health`.
   Se responder, o problema está entre o navegador e o servidor (DNS,
   proxy, firewall), e não na central.

### O telefone do navegador fica em "registrando"

1. **Conectividade › WebRTC / Softphone** confere a configuração, e o botão
   **Testar agora** diz do seu navegador se há caminho até a central.
2. O ramal tem **WebRTC** ligado no cadastro, e o usuário tem a permissão
   **Telefone (WebRTC)** no perfil.
3. O `pabx_hostname` é um nome válido: começa por letra, sem `_`, e
   **não termina em `.local`**. O navegador ignora em silêncio tudo que a
   central assina com um nome fora da regra.
4. No navegador, F12 › Rede › filtro **WS**: a conexão com `/ws` tem de
   aparecer com status **101**. Outro status diz onde está o bloqueio.

### Um ramal não registra

- Senha errada, ou aparelho apontando para outro servidor.
- **Redes permitidas** no cadastro do ramal: fora delas, a central recusa.
- O IP do aparelho foi **bloqueado pelo firewall** depois de senhas
  erradas: **Conectividade › Firewall** mostra quem está bloqueado e
  desbloqueia. Um endereço de confiança (o escritório, o monitoramento)
  pode ir para **Nunca bloquear**.

### A chamada de fora não chega

- O número que a operadora entrega não casa com a rota. Veja em
  **Relatórios › CDR** o número que chegou e ajuste o tronco (tirar o
  `+55`, ficar com os últimos N dígitos) ou a rota de entrada.
- Sem uma rota "qualquer número" (DID em branco ou `*`), quem liga para um
  número não cadastrado ouve o aviso de número inexistente.

### A URA, a fila ou a pesquisa não tocam o áudio

- O anúncio escolhido está **ativo** em **Aplicações › Anúncios**.
- Depois de trocar o áudio, **Aplicar configurações**.

### A pesquisa de satisfação não acontece

- A pesquisa está **ativa** e escolhida na aba **Pesquisa** da fila.
- **Aplicar configurações** depois de mudar.
- A pesquisa entra quando **o atendente desliga**. Quando o cliente
  desliga primeiro, não há pesquisa.

### "Aplicar configurações" termina com erro

A faixa mostra a etapa que falhou.

- **"O Asterisk não carregou: 1005"**: um ramal (ou tronco) tem uma opção
  que o Asterisk recusou. O motivo está no log:
  `sudo grep -i "1005" /var/log/asterisk/full | tail`.
- **Conexão com o Asterisk**: o Asterisk está parado
  (`sudo systemctl status asterisk`).

### O playbook para com erro

- Na **bateria de testes**: a saída diz qual teste falhou e por quê.
- No **git pull**, por mudança local no `main.yml`: veja
  [02 — Atualização](02-ATUALIZACAO.md#se-o-git-pull-recusar).
- Em qualquer outro ponto: rode `sudo ansible-playbook verificar.yml` e
  guarde a saída junto com a do playbook.
