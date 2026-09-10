#!/usr/bin/env bash
# =========================================================
# Telium PABX — por que o AMI recusa as credenciais
#   sudo bash infra/diagnostico-ami.sh
# =========================================================
export LC_ALL=C
CONF=/etc/asterisk/manager.conf
CRED=/etc/telium/credenciais/ami_senha
VAULT=/opt/telium-src/infra/ansible/group_vars/all/vault.yml

echo "== 1. Asterisk escutando na 5038?"
ss -lntp 2>/dev/null | grep ':5038' || echo "   (nada escutando)"

echo
echo "== 2. usuários do manager carregados pelo Asterisk"
asterisk -rx "manager show users" 2>&1 | sed 's/^/   /'
echo "   --- detalhe do usuário telium (sem a senha)"
asterisk -rx "manager show user telium" 2>&1 | grep -viE 'secret|password' | sed 's/^/   /'

echo
echo "== 3. senha em manager.conf x origem usada pelo Ansible"
# a linha é "secret  = valor" (espaçamento variável): extrai só o valor
disco=$(awk -F'=' '/^[[:space:]]*secret[[:space:]]*=/ {sub(/^[[:space:]]+/,"",$2); print $2; exit}' "$CONF" 2>/dev/null)

if [ -f "$VAULT" ] && grep -q vault_ami_senha "$VAULT"; then
  origem="vault.yml (tem precedência sobre a senha gerada)"
  esperada=$(grep vault_ami_senha "$VAULT" | sed -E 's/^[^:]*:[[:space:]]*"?([^"]*)"?[[:space:]]*$/\1/')
elif [ -f "$CRED" ]; then
  origem="$CRED"
  esperada=$(cat "$CRED")
else
  origem="(nenhuma encontrada)"; esperada=""
fi

printf "   origem ........ %s\n" "$origem"
printf "   em disco ...... %s caracteres, começa com %s\n" "${#disco}" "${disco:0:4}…"
printf "   esperada ...... %s caracteres, começa com %s\n" "${#esperada}" "${esperada:0:4}…"
if [ "$disco" = "$esperada" ]; then
  echo "   => IGUAIS"
else
  echo "   => DIFERENTES"
fi

echo
echo "== 4. o Asterisk reclamou de algo ao carregar o manager?"
asterisk -rx "manager reload" >/dev/null 2>&1
sleep 1
grep -iE "manager|acl" /var/log/asterisk/messages 2>/dev/null | tail -10 | sed 's/^/   /' \
  || echo "   (sem log)"

echo
echo "== 5. login real usando a senha que está em manager.conf"
python3 - "$disco" <<'PY'
import socket, sys
senha = sys.argv[1]
try:
    with socket.create_connection(("127.0.0.1", 5038), timeout=5) as s:
        print("   banner ...", s.recv(4096).decode(errors="replace").strip())
        s.sendall(f"Action: Login\r\nUsername: telium\r\nSecret: {senha}\r\n\r\n".encode())
        r = s.recv(4096).decode(errors="replace")
        print("   resposta .", " | ".join(l.strip() for l in r.splitlines() if l.strip()))
        s.sendall(b"Action: Logoff\r\n\r\n")
except OSError as e:
    print("   falhou:", e)
PY

echo
echo "== 6. manager.conf completo (senha mascarada)"
sed -E 's/^([[:space:]]*secret[[:space:]]*=[[:space:]]*).*/\1********/' "$CONF" 2>&1 | sed 's/^/   /'
echo
echo "== FIM =="
