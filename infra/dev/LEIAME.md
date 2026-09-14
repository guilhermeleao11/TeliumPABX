# Bancada de desenvolvimento

Sobe uma central inteira em contêineres: MariaDB, a API em PHP e um
**Asterisk 22 de verdade**, carregando os mesmos `.conf` que o Ansible
publica no servidor do cliente.

```sh
infra/dev/subir.sh             # sobe tudo
infra/dev/subir.sh --limpar    # recria o banco do zero
infra/dev/derrubar.sh          # derruba
infra/dev/derrubar.sh --tudo   # derruba e apaga o diretório de trabalho
```

Depois disso:

| | |
| --- | --- |
| Console | <http://127.0.0.1:18110/> |
| Entrada | `admin` / `T3l1um_@2024_@aD1m` |
| Asterisk | `docker exec -it v-ast asterisk -rvvv` |
| Testes | `docker exec v-web sh -c 'cd /w/api && php bin/telium testar'` |
| Banco | `docker exec -it v-db mariadb -uroot -pr telium` |

## Por que um Asterisk de verdade

Uma classe inteira de defeito só aparece quando o Asterisk lê o arquivo:
uma opção com nome errado faz ele **descartar o objeto inteiro em
silêncio**. Já aconteceu com `mailbox` no lugar de `mailboxes` (o ramal
simplesmente deixava de existir), com `type` no `res_parking.conf` e com
`(type=bridge)` entre parênteses no `confbridge.conf`. Nenhum desses
aparece em teste que só compara texto.

## O que a bancada não prova

- **Áudio.** A imagem do Asterisk vem sem arquivo de som nenhum; a
  música em espera é silêncio sintético. O que se confere é o dialplan
  executando `Playback(...)`, não o que se ouve.
- **Mídia de WebRTC.** DTLS, áudio nos dois sentidos e ICE só um
  navegador de verdade mostra.
- **Operadora.** Não há tronco real. Para provar a rota de saída,
  aponte o tronco para um soquete de teste e leia o `From` do INVITE.

## Diferenças de propósito em relação ao servidor

Ficam explícitas no `subir.sh`, e valem **só** na bancada:

- o Asterisk roda como `root` (no servidor é `asterisk`, e quem gera os
  `.conf` é o usuário da API pelo grupo comum);
- as senhas são de bancada (`r`, `bancada`);
- o `APP_DEBUG` fica ligado.
