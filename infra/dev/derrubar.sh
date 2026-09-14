#!/usr/bin/env bash
# Derruba a bancada. Com --tudo, apaga também o diretório de trabalho.
set -euo pipefail
docker rm -f v-ast v-web v-db >/dev/null 2>&1 || true
echo "bancada derrubada"
if [[ "${1:-}" == "--tudo" ]]; then
  rm -rf "${TELIUM_DEV_DIR:-/tmp/telium-dev}"
  echo "diretório de trabalho apagado"
fi
