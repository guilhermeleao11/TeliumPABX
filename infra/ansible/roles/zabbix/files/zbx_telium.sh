#!/bin/sh
# Telium PABX — itens do sistema para o Zabbix (template "PBX - TeliumPABX"):
# o nome da central, a versão, a saúde da API, o certificado HTTPS e o
# backup. Asterisk, nginx e MariaDB têm os templates próprios; os
# serviços vêm do systemd.unit.info do próprio agent2.
#
# O nome é o server_name do console no nginx. O certificado é o que o
# nginx entrega de verdade na 443 para esse nome (SNI): vale para o de
# fábrica, o do Let's Encrypt e o enviado pelo console, sem ler arquivo
# nenhum (o agente roda como zabbix).
#
#   nome          o nome da central (ex.: voz.cliente.com.br)
#   versao        a versão do Telium PABX (ex.: 1.8.0)
#   saudavel      1 se a API responde e vê o banco e o Asterisk, 0 se não
#   dias          dias até o certificado vencer (negativo: já venceu)
#   expira        data de vencimento, em segundos desde 1970 (unixtime)
#   emissor       quem emitiu o certificado
#   nome_confere  1 se o certificado vale para o nome da central, 0 se não
#
#   backup.horas          horas desde o último backup concluído (-1: nunca)
#   backup.ok             1 se a última execução terminou bem, 0 se falhou
#                         (-1: nenhuma terminou ainda)
#   backup.tamanho        bytes do último backup concluído
#   backup.rotinas        rotinas agendadas ativas (0: nada agendado)
#   backup.remoto.estado  envio ao destino remoto: desligado, nunca, ok, falha
#   backup.remoto.horas   horas desde o último envio (-1: nunca)
#
# O backup é lido do banco como zbx_monitor, que só enxerga as colunas
# de estado (/etc/zabbix/telium-db.cnf).

CONF=/etc/nginx/conf.d/telium-pabx.conf

# O primeiro server_name que não é o "_" (o "qualquer nome" do nginx).
nome() {
  awk '$1 == "server_name" { for (i = 2; i <= NF; i++) { gsub(";", "", $i); if ($i != "_") { print $i; exit } } }' \
    "$CONF" 2>/dev/null
}

falha() { echo ZBX_NOTSUPPORTED; exit 1; }

# A saúde é a mesma que o console consulta (/api/health, sem login): a API
# respondendo, com o banco, o esquema e o Asterisk em ordem.
saude() { timeout 10 curl -sk https://127.0.0.1/api/health 2>/dev/null; }

# Uma linha do banco; sem resposta, o item fica sem suporte.
sql() {
  r=$(mariadb --defaults-extra-file=/etc/zabbix/telium-db.cnf --protocol=socket -N -B -e "$1" 2>/dev/null) || falha
  echo "$r"
}

case "${1:-}" in
  backup.horas)
    sql "SELECT IFNULL(TIMESTAMPDIFF(HOUR, MAX(concluido_em), NOW()), -1) FROM backups WHERE estado = 'concluido'"
    exit 0 ;;
  backup.ok)
    sql "SELECT IFNULL((SELECT estado = 'concluido' FROM backups WHERE estado IN ('concluido', 'falha')
                         ORDER BY concluido_em DESC LIMIT 1), -1)"
    exit 0 ;;
  backup.tamanho)
    sql "SELECT IFNULL((SELECT tamanho FROM backups WHERE estado = 'concluido' ORDER BY concluido_em DESC LIMIT 1), 0)"
    exit 0 ;;
  backup.rotinas)
    sql "SELECT COUNT(*) FROM backup_rotinas WHERE ativo = 1 AND periodicidade <> 'manual'"
    exit 0 ;;
  backup.remoto.estado)
    sql "SELECT IF(ativo = 1, ultimo_estado, 'desligado') FROM backup_destino_remoto WHERE id = 1"
    exit 0 ;;
  backup.remoto.horas)
    sql "SELECT IFNULL(TIMESTAMPDIFF(HOUR, ultimo_envio, NOW()), -1) FROM backup_destino_remoto WHERE id = 1"
    exit 0 ;;
esac

# Daqui em diante, tudo depende do nome da central.
NOME=$(nome)
[ -n "$NOME" ] || falha

case "${1:-}" in
  nome)
    echo "$NOME"; exit 0 ;;
  versao)
    v=$(saude | sed -n 's/.*"versao":"\([^"]*\)".*/\1/p')
    [ -n "$v" ] || falha
    echo "$v"; exit 0 ;;
  saudavel)
    if saude | grep -q '"saudavel":true'; then echo 1; else echo 0; fi
    exit 0 ;;
esac

CERT=$(echo | timeout 10 openssl s_client -connect 127.0.0.1:443 -servername "$NOME" 2>/dev/null \
         | openssl x509 2>/dev/null)
[ -n "$CERT" ] || falha

case "${1:-}" in
  dias|expira)
    fim=$(printf '%s\n' "$CERT" | openssl x509 -noout -enddate | cut -d= -f2)
    fim=$(date -d "$fim" +%s 2>/dev/null) || falha
    if [ "$1" = expira ]; then echo "$fim"; else echo $(( (fim - $(date +%s)) / 86400 )); fi
    ;;
  emissor)
    printf '%s\n' "$CERT" | openssl x509 -noout -issuer | sed 's/^issuer= *//'
    ;;
  nome_confere)
    case "$NOME" in
      *[!0-9.]*) teste="-checkhost" ;;
      *)         teste="-checkip" ;;
    esac
    if printf '%s\n' "$CERT" | openssl x509 -noout "$teste" "$NOME" | grep -q 'does match'; then
      echo 1
    else
      echo 0
    fi
    ;;
  *) falha ;;
esac
