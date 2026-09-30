# 03 — Backup e restauração

## Pelo console (o caminho normal)

**Administrador › Backup & Restauração**

- **Fazer backup agora**: um backup na hora. Escolha o que entra: banco,
  configuração e áudios (ligados de fábrica) e gravações de chamadas
  (desligadas de fábrica, porque são o que mais ocupa espaço).
- **Rotinas**: backups automáticos por dia e hora, com retenção.
- **Destino remoto**: manda cada backup para fora do servidor. Um backup
  que só existe na máquina que ele deveria salvar não é backup. Use
  **Testar** para conferir o destino.
- **Baixar**: leva o arquivo para o seu computador.
- **Restaurar**: volta a central para aquele backup. **O Asterisk para
  durante a restauração, e as chamadas em andamento caem.** Faça fora do
  horário.

Os arquivos ficam em `/var/backup/pabx-telium`.

> O comando `telium-backup` que existe no servidor **não faz um backup
> novo quando chamado sozinho**: ele executa o que o console pediu e as
> rotinas vencidas. Para um backup na hora, use o console ou o terminal
> abaixo.

## Backup rápido pelo terminal

Útil antes de uma atualização, ou quando o console não está acessível:

```bash
sudo mkdir -p /root/backup-manual && cd /root/backup-manual
DATA=$(date +%F-%H%M)
sudo mariadb-dump --single-transaction --routines --triggers telium_pabx | gzip > banco-$DATA.sql.gz
sudo tar czf config-$DATA.tgz --ignore-failed-read \
  /etc/telium /etc/asterisk /opt/TeliumPABX/infra/ansible/group_vars/all/servidor.yml
ls -lh
```

- `banco-*.sql.gz` é o banco inteiro: cadastros, usuários, CDR e resultados.
- `config-*.tgz` guarda as senhas de serviço (`/etc/telium/credenciais`),
  os certificados e a configuração do Asterisk.
- Os áudios e as gravações ficam de fora (podem ser grandes). Se quiser:
  `sudo tar czf audios-$DATA.tgz /var/lib/asterisk/sounds/telium`.

**Guarde fora do servidor.** O `config-*.tgz` tem senhas: trate como segredo.

## Restaurar

### Pelo console

**Administrador › Backup & Restauração**, no backup desejado, **Restaurar**.

### Pelo terminal, a partir do backup rápido

```bash
cd /root/backup-manual
sudo systemctl stop telium-cc asterisk
gunzip -c banco-AAAA-MM-DD-HHMM.sql.gz | sudo mariadb telium_pabx
sudo tar xzf config-AAAA-MM-DD-HHMM.tgz -C /
sudo systemctl start asterisk telium-cc
sudo -u teliumpbx php /opt/telium/api/bin/telium aplicar-config
```

Depois, confira como em
[04 — Verificação e problemas comuns](04-VERIFICACAO-E-PROBLEMAS.md).
