#!/usr/bin/env bash
# =========================================================
# Telium PABX — relatório compacto de diagnóstico.
# Rode na VM:  sudo bash infra/diagnostico.sh
# A saída é curta de propósito: cole ela inteira.
# =========================================================
export LC_ALL=C
linha() { printf '\n=== %s %s\n' "$1" "$(printf '%*s' $((60 - ${#1})) '' | tr ' ' '=')"; }
ok()    { printf '  %-26s %s\n' "$1" "$2"; }

echo "TELIUM PABX — DIAGNÓSTICO — $(date '+%d/%m/%Y %H:%M:%S')"
echo "host: $(hostname) · $(. /etc/os-release; echo "$PRETTY_NAME") · kernel $(uname -r)"

linha "SERVIÇOS"
for s in mariadb php8.4-fpm asterisk janus nginx fail2ban; do
  estado=$(systemctl is-active "$s" 2>/dev/null || echo "ausente")
  detalhe=""
  [ "$estado" != "active" ] && detalhe=" | $(systemctl is-enabled "$s" 2>/dev/null || echo '-') | $(journalctl -u "$s" -n 1 --no-pager -o cat 2>/dev/null | head -c 90)"
  ok "$s" "$estado$detalhe"
done

linha "VERSÕES"
ok "mariadb"  "$(mariadbd --version 2>/dev/null | head -1 || echo 'não instalado')"
ok "php"      "$(php -v 2>/dev/null | head -1 || echo 'não instalado')"
ok "nginx"    "$(nginx -v 2>&1 | head -1 || echo 'não instalado')"
ok "asterisk" "$(asterisk -V 2>/dev/null || echo 'não instalado')"
ok "janus"    "$(/opt/janus/bin/janus --version 2>&1 | head -1 || echo 'não instalado')"
ok "ansible"  "$(ansible --version 2>/dev/null | head -1 || echo '-')"

linha "PORTAS EM ESCUTA"
ss -lntup 2>/dev/null | awk 'NR==1 || /:(80|443|3306|5060|5038|8088|8090|8188|7088)\y/' | head -15

linha "BANCO"
if mariadb --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
  mariadb --protocol=socket -uroot -N -B telium_pabx -e "
    SELECT 'usuarios', COUNT(*) FROM usuarios UNION ALL
    SELECT 'ramais',   COUNT(*) FROM ramais   UNION ALL
    SELECT 'troncos',  COUNT(*) FROM troncos  UNION ALL
    SELECT 'filas',    COUNT(*) FROM filas    UNION ALL
    SELECT 'rotas_saida', COUNT(*) FROM rotas_saida UNION ALL
    SELECT 'cdr',      COUNT(*) FROM cdr;" 2>&1 | sed 's/^/  /'
else
  echo "  !! não foi possível conectar como root via socket"
fi

linha "ASTERISK"
if command -v asterisk >/dev/null && asterisk -rx "core show version" >/dev/null 2>&1; then
  echo "  --- pjsip show endpoints"
  asterisk -rx "pjsip show endpoints" 2>&1 | head -18 | sed 's/^/  /'
  echo "  --- odbc show all"
  asterisk -rx "odbc show all" 2>&1 | head -8 | sed 's/^/  /'
  echo "  --- dialplan show telium-ramais (5 primeiras)"
  asterisk -rx "dialplan show telium-ramais" 2>&1 | head -8 | sed 's/^/  /'
else
  echo "  !! Asterisk não responde ao CLI"
  systemctl status asterisk --no-pager -l 2>&1 | head -12 | sed 's/^/  /'
fi

linha "JANUS"
curl -s --max-time 4 http://127.0.0.1:8088/janus/info 2>/dev/null \
  | head -c 400 | sed 's/^/  /' || echo "  !! não respondeu em 127.0.0.1:8088"
echo

linha "API"
curl -sk --max-time 6 https://127.0.0.1/api/health 2>/dev/null | head -c 700 | sed 's/^/  /' \
  || echo "  !! não respondeu"
echo

linha "ARQUIVOS .CONF GERADOS"
ls -la /etc/asterisk/telium/ 2>/dev/null | sed 's/^/  /' | head -16 || echo "  !! diretório não existe"

linha "ERROS RECENTES"
echo "  --- asterisk"
grep -iE "error|warning" /var/log/asterisk/messages 2>/dev/null | tail -8 | sed 's/^/  /' || echo "  (sem log)"
echo "  --- nginx"
tail -5 /var/log/nginx/error.log 2>/dev/null | sed 's/^/  /' || echo "  (sem log)"
echo "  --- api"
tail -5 /var/log/telium/php-api.log 2>/dev/null | sed 's/^/  /' || echo "  (sem log)"

echo
echo "=== FIM ==="
