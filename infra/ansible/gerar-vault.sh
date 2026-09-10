#!/usr/bin/env bash
# Cria group_vars/all/vault.yml com senhas aleatórias.
set -euo pipefail
cd "$(dirname "$0")"

destino="group_vars/all/vault.yml"

if [ -f "$destino" ]; then
  echo "✗ $destino já existe. Apague-o antes se quiser gerar de novo." >&2
  exit 1
fi

senha() { tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 28; }

mkdir -p group_vars/all
cat > "$destino" <<YAML
---
# Gerado por gerar-vault.sh em $(date '+%d/%m/%Y %H:%M:%S')
# NÃO versione este arquivo. Para cifrar: ansible-vault encrypt $destino

vault_db_senha: "$(senha)"
vault_db_cdr_senha: "$(senha)"
vault_ami_senha: "$(senha)"
vault_ari_senha: "$(senha)"
YAML

chmod 600 "$destino"
echo "✓ $destino criado com senhas aleatórias"
echo "  Para cifrar:  ansible-vault encrypt $destino"
