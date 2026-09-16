#!/usr/bin/env bash
#
# Bancada de desenvolvimento do Telium PABX.
#
# Sobe MariaDB, a API em PHP e um Asterisk de verdade em contêineres,
# aplica todos os scripts de banco e gera a configuração. É o que
# permite provar uma correção antes de ela virar entrega: sem um
# Asterisk carregando os .conf, uma classe inteira de erro só aparece
# no cliente.
#
#   infra/dev/subir.sh            sobe tudo
#   infra/dev/subir.sh --limpar   recria o banco do zero
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TRAB="${TELIUM_DEV_DIR:-/tmp/telium-dev}"
PORTA="${TELIUM_DEV_PORTA:-18110}"

IMG_DB="mariadb:12.3.3"
IMG_PHP="php:8.4-cli"
IMG_AST="andrius/asterisk:latest"

verde=$'\033[32m'; amarelo=$'\033[33m'; zero=$'\033[0m'
passo() { echo "${verde}▸${zero} $*"; }

if [[ "${1:-}" == "--limpar" ]]; then
  passo "derrubando e apagando o que existia"
  docker rm -f v-db v-web v-ast >/dev/null 2>&1 || true
  rm -rf "$TRAB"
fi

mkdir -p "$TRAB"/{ast/telium,ast/keys,gravacoes,fotos,cofre,audios,backup}

# ---------------------------------------------------------------- banco
if ! docker ps --format '{{.Names}}' | grep -qx v-db; then
  passo "subindo o MariaDB"
  docker rm -f v-db >/dev/null 2>&1 || true
  docker run -d --name v-db \
    -e MARIADB_ROOT_PASSWORD=r -e MARIADB_DATABASE=telium \
    "$IMG_DB" >/dev/null

  for _ in $(seq 1 60); do
    docker exec v-db mariadb -uroot -pr -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 1
  done
fi

passo "aplicando os scripts de banco"
for sql in "$RAIZ"/infra/sql/*.sql; do
  docker exec -i v-db mariadb -uroot -pr telium < "$sql" 2>&1 | grep -vi 'warning' || true
done

# --------------------------------------------------------- certificado
if [[ ! -f "$TRAB/ast/keys/asterisk.crt" ]]; then
  passo "gerando o par autoassinado do Asterisk"
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -subj "/CN=pabx.bancada.local" \
    -keyout "$TRAB/ast/keys/asterisk.key" \
    -out "$TRAB/ast/keys/asterisk.crt" 2>/dev/null
  chmod 644 "$TRAB/ast/keys/asterisk."*
fi

# ------------------------------------------------------------------ API
if ! docker ps --format '{{.Names}}' | grep -qx v-web; then
  passo "subindo a API"
  docker rm -f v-web >/dev/null 2>&1 || true

  cat > "$RAIZ/api/.env" <<ENV
APP_ENV=dev
APP_DEBUG=true
DB_HOST=v-db
DB_PORT=3306
DB_NAME=telium
DB_USER=root
DB_PASS=r
AMI_HOST=127.0.0.1
AMI_PORT=5038
AMI_USER=telium
AMI_PASS=bancada
CERT_DIR=/cofre
FOTOS_DIR=/fotos
FOTOS_URL=/uploads/contatos
BACKUP_DIR=/backup
AUDIOS_DIR=/audios
GRAVACOES_DIR=/gravacoes
SQL_DIR=/w/infra/sql
TELIUM_ETC=/etc/telium
ASTERISK_CONF_DIR=/s/ast
ASTERISK_GERADO_DIR=/s/ast/telium
# No servidor a API e o Asterisk dividem a mesma máquina e este caminho é
# o de fábrica. Na bancada são dois contêineres, então os áudios moram no
# diretório compartilhado e o Asterisk chega neles por um atalho.
ASTERISK_SONS_DIR=/s/ast/sounds
# Na bancada o Asterisk roda como root e os dois contêineres não
# compartilham o grupo asterisk. No servidor o padrão vale.
ASTERISK_GRUPO=root
SOFTPHONE_WS=
SIP_DOMINIO=
SOFTPHONE_STUN=stun:stun.l.google.com:19302
# Sem TURN na bancada: para provar o relay, suba um coturn e aponte
# SOFTPHONE_TURN para ele com o mesmo TURN_SEGREDO.
SOFTPHONE_TURN=
TURN_SEGREDO=
TURN_VALIDADE_SEGUNDOS=21600
ENV

  docker run -d --name v-web -p "${PORTA}:80" \
    -v "$RAIZ":/w -v "$TRAB":/s \
    -v "$TRAB/gravacoes":/gravacoes -v "$TRAB/fotos":/fotos \
    -v "$TRAB/cofre":/cofre -v "$TRAB/audios":/audios -v "$TRAB/backup":/backup \
    --link v-db:v-db \
    "$IMG_PHP" sh -c \
    'docker-php-ext-install pdo_mysql >/dev/null 2>&1; php -S 0.0.0.0:80 -t /w /w/infra/dev/router-dev.php' \
    >/dev/null

  for _ in $(seq 1 40); do
    curl -sf "http://127.0.0.1:${PORTA}/api/health" >/dev/null 2>&1 && break
    sleep 1
  done
fi

# ------------------------------------------------------------- Asterisk
passo "publicando a configuração base do Asterisk"
python3 - "$RAIZ" "$TRAB" <<'PY'
import sys, os, re, glob
raiz, trab = sys.argv[1], sys.argv[2]
# A bancada não roda Ansible: troca as variáveis do Jinja pelos valores
# da bancada. É o mesmo arquivo que vai para o cliente.
vals = {
    'ansible_managed': 'bancada de desenvolvimento',
    'asterisk_gerado_dir': '/etc/asterisk/telium',
    'cert_asterisk_dir': '/etc/asterisk/keys',
    'pabx_timezone': 'America/Sao_Paulo', 'pabx_empresa': 'Telium Networks',
    'sip_porta': '5060', 'sip_tls_porta': '5061', 'ast_http_porta': '8090',
    'ast_rtp_inicio': '10000', 'ast_rtp_fim': '10200',
    'ami_usuario': 'telium', 'ami_senha': 'bancada', 'ami_porta': '5038',
    'ari_usuario': 'telium', 'ari_senha': 'bancada',
    'pabx_hostname': 'pabx.bancada.local',
    'db_cdr_usuario': 'root', 'db_cdr_senha': 'r',
    'asterisk_max_chamadas': '480', 'asterisk_max_carga': '20',
    'telium_gravacoes': '/var/spool/asterisk/monitor',
    'db_nome': 'telium', 'db_usuario': 'root', 'db_senha': 'r',
    'udptl_inicio': '4000', 'udptl_fim': '4999',
    'pabx_idioma': 'pt_BR',
}
# Os transportes viraram arquivo gerado; o template é só o ponto de
# partida, e vai para o diretório do que a API gera — como o playbook faz.
GERADOS = {'pjsip.transports.conf'}

for origem in glob.glob(f'{raiz}/infra/ansible/roles/asterisk/templates/*.conf.j2'):
    nome = os.path.basename(origem)[:-3]
    s = open(origem).read()
    # blocos condicionais e laços: a bancada não tem rede local nem NAT
    s = re.sub(r'\{%\s*if [^%]*%\}.*?\{%\s*endif\s*%\}', '', s, flags=re.S)
    s = re.sub(r'\{%\s*for [^%]*%\}.*?\{%\s*endfor\s*%\}', '', s, flags=re.S)
    for k, v in vals.items():
        s = s.replace('{{ ' + k + ' }}', v)
        s = re.sub(r'\{\{\s*' + k + r'\s*\|[^}]*\}\}', v, s)
    # o que sobrou com "| default(x)" fica com o próprio default
    s = re.sub(r'\{\{[^}|]*\|\s*default\(([^)]*)\)\s*\}\}', r'\1', s)
    s = re.sub(r'\{\{[^}]*\}\}', '', s)
    destino = f'{trab}/ast/telium/{nome}' if nome in GERADOS else f'{trab}/ast/{nome}'
    open(destino, 'w').write(s)
print('   base publicada')
PY

# Na bancada o Asterisk roda como root: quem gera os .conf é o contêiner
# da API, e os dois não compartilham o grupo asterisk como no servidor
# de verdade. Em produção o runuser vem do template e vale.
sed -i 's/^runuser .*/; runuser: na bancada o Asterisk roda como root/; s/^rungroup .*/;/' \
  "$TRAB/ast/asterisk.conf"

: > "$TRAB/ast/telium/extensions_custom.conf"
: > "$TRAB/ast/telium/pjsip_custom.conf"
: > "$TRAB/ast/telium/queues_custom.conf"
: > "$TRAB/ast/telium/confbridge_custom.conf"

passo "gerando a configuração a partir do banco"
docker exec v-web sh -c 'cd /w/api && php bin/telium gerar-config' | tail -2

if ! docker ps --format '{{.Names}}' | grep -qx v-ast; then
  passo "subindo o Asterisk"
  docker rm -f v-ast >/dev/null 2>&1 || true
  docker run -d --name v-ast --network "container:v-web" \
    -v "$TRAB/ast":/etc/asterisk \
    -v "$TRAB/gravacoes":/var/spool/asterisk/monitor \
    -v "$TRAB/audios":/audios \
    "$IMG_AST" sleep infinity >/dev/null

  # CDR e CEL vão para o banco por ODBC, igual ao servidor de verdade:
  # sem o driver a bancada não prova nada sobre relatório e gravação.
  passo "instalando o driver ODBC do MariaDB no Asterisk"
  docker exec v-ast sh -c 'apt-get update -qq && apt-get install -y -qq odbc-mariadb' >/dev/null 2>&1
  docker exec v-ast sh -c 'cat > /etc/odbcinst.ini <<INI
[MariaDB]
Description = MariaDB ODBC Connector
Driver      = /usr/lib/x86_64-linux-gnu/odbc/libmaodbc.so
Threading   = 0
INI
cat > /etc/odbc.ini <<INI
[telium-cdr]
Description = Telium PABX — CDR/CEL
Driver      = MariaDB
Server      = v-db
Port        = 3306
Database    = telium
User        = root
Password    = r
Charset     = utf8mb4
INI'

  docker exec -d v-ast asterisk -f -vvv
  sleep 6

  # "docker restart v-ast" mata o Asterisk e traz de volta só o
  # sleep do contêiner. O atalho abaixo é o jeito certo de reiniciá-lo
  # na bancada — está aqui para quem vier depois não penar como eu penei.
  #   docker exec v-ast sh -c 'pkill -f "asterisk -f"; rm -f /var/run/asterisk/asterisk.ctl'
  #   docker exec -d v-ast asterisk -f -vvv
  # Sem arquivo nenhum, a classe de música em espera some sem avisar e
  # a fila toca silêncio.
  # A imagem do Asterisk vem sem nenhum arquivo de som. Dois arquivos de
  # silêncio bastam para a classe de música em espera existir — sem
  # nenhum ela some sem avisar e a fila toca vazio. Em produção os sons
  # vêm do menuselect, na compilação.
  docker exec v-ast sh -c \
    'mkdir -p /var/lib/asterisk/moh && for i in 1 2; do head -c 320000 /dev/zero > /var/lib/asterisk/moh/silencio$i.sln; done'

  # A imagem do Asterisk vem sem os áudios do sistema, e Playback de
  # arquivo que não existe não dá erro: a URA fica muda e o dialplan
  # segue como se tivesse tocado. Aqui eles viram silêncio, com a
  # duração certa — o que interessa na bancada é o caminho da chamada,
  # não o que se ouve. No servidor de verdade quem instala é o playbook.
  passo "criando áudios de silêncio (a imagem do Asterisk vem sem sons)"
  docker exec v-ast sh -c '
    mkdir -p /etc/asterisk/sounds/pt_BR /var/lib/asterisk/sounds/pt_BR
    for s in activated de-activated all-circuits-busy-now beep conf-getpin \
             conf-invalidpin conf-onlyperson demo-echotest dial goodbye hello \
             invalid pbx-invalid please-enter-your privacy-you-are-not-permitted \
             queue-callswaiting ss-noservice vm-enter-num-to-call agent-loggedoff \
             agent-loginok demo-congrats vm-goodbye vm-intro auth-thankyou \
             pbx-invalidpark parking-lot-full; do
      head -c 16000 /dev/zero > /etc/asterisk/sounds/pt_BR/$s.sln
    done
    mkdir -p /etc/asterisk/sounds/pt_BR/digits /var/lib/asterisk/sounds/pt_BR/digits
    for d in 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 30 40 50 60 70 80 90 \
             hundred thousand million minute minutes second seconds oh at; do
      head -c 8000 /dev/zero > /etc/asterisk/sounds/pt_BR/digits/$d.sln
    done
    cp /etc/asterisk/sounds/pt_BR/*.sln /var/lib/asterisk/sounds/pt_BR/
    cp /etc/asterisk/sounds/pt_BR/digits/*.sln /var/lib/asterisk/sounds/pt_BR/digits/'
fi

echo
passo "pronto"
echo "   console : http://127.0.0.1:${PORTA}/"
echo "   entrada : admin / T3l1um_@2024_@aD1m"
echo "   asterisk: docker exec -it v-ast asterisk -rvvv"
echo "   testes  : docker exec v-web sh -c 'cd /w/api && php bin/telium testar'"
echo "${amarelo}   a bancada usa senhas de bancada; nada aqui vai para produção.${zero}"
