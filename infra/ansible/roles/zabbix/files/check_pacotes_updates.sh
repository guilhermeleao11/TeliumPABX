#!/bin/bash
###########################################################################
#
# Nome        : check_pacotes_updates
# Descricao   : Verifica os pacotes a serem atualizados em CentOS, Ubuntu e Debian
# Author      : Jose Edson Moreno Jr.
# Email       : edson.moreno@telium.com.br
# Versao      : 0.1 - 20260518
# Data        : 18/05/2026
# Copyright   : (C) 2026 by Telium
# Dependencia :
# Parametros  : prog
# Uso ./check_pacotes_updates.sh <opcao>
# Opcoes:
#    -d          : enable debug output
#    -v          : show the plugin version
#    -h          : Show this help
#
# CHANGELOG   :
############################################################################

VERSION=$(grep -E "^# Versao" "$0" | awk -F': ' '{print $2}')
PROGNAME="$0"
DEBUG="false"

export LC_NUMERIC="en_US.UTF-8"

show_version() {
    echo "$PROGNAME $VERSION"
}

show_help() {
    echo "Usage $PROGNAME [-d|--debug] [-v|--version] [-h|--help], where:"
    echo "-d: enable debug output"
    echo "-v: show the plugin version"
    echo "-h: Show this help"
}

writeDebug() {
    [ "$DEBUG" = "true" ] && echo "$1"
}

get_lock_file() {
    local dir_base run_dir

    if [ "$(dirname "$PROGNAME")" = "." ]; then
        dir_base="$PWD"
    else
        dir_base="$(dirname "$PROGNAME")"
    fi

    run_dir="${dir_base}/run"
    echo "${run_dir}/.$(basename "$PROGNAME").lck"
}

refresh_cache_if_needed() {
    local lock_file now last_run

    lock_file="$(get_lock_file)"
    now="$(date +%s)"

    if [ ! -f "$lock_file" ]; then
        echo "$now" > "$lock_file"
    fi

    last_run="$(cat "$lock_file" 2>/dev/null)"
    [ -z "$last_run" ] && last_run=0

    if [ $((now - last_run)) -le 14400 ]; then
        return 0
    fi

    case "$1" in
        apt)
            apt-get update > /dev/null 2>&1
            ;;
        dnf)
            dnf -q makecache > /dev/null 2>&1
            ;;
        yum)
            yum -q makecache > /dev/null 2>&1
            ;;
    esac

    echo "$now" > "$lock_file"
}

count_apt_updates() {
    apt list --upgradable 2>/dev/null \
        | awk -F/ 'NR > 1 && $1 != "" { count++ } END { print count + 0 }'
}

count_dnf_updates() {
    local output rc

    output="$(dnf -q check-update 2>/dev/null)"
    rc=$?

    if [ "$rc" -ne 0 ] && [ "$rc" -ne 100 ]; then
        echo "-1"
        return 0
    fi

    echo "$output" \
        | awk 'NF >= 3 && $1 ~ /^[[:alnum:]_.:+-]+(\.[[:alnum:]_+-]+)?$/ { count++ } END { print count + 0 }'
}

count_yum_updates() {
    local output rc

    output="$(yum -q check-update 2>/dev/null)"
    rc=$?

    if [ "$rc" -ne 0 ] && [ "$rc" -ne 100 ]; then
        echo "-1"
        return 0
    fi

    echo "$output" \
        | awk 'NF >= 3 && $1 ~ /^[[:alnum:]_.:+-]+(\.[[:alnum:]_+-]+)?$/ { count++ } END { print count + 0 }'
}

detect_package_manager() {
    if command -v apt >/dev/null 2>&1; then
        echo "apt"
        return 0
    fi

    if command -v dnf >/dev/null 2>&1; then
        echo "dnf"
        return 0
    fi

    if command -v yum >/dev/null 2>&1; then
        echo "yum"
        return 0
    fi

    echo ""
}

while (( "$#" )); do
    case "$1" in
        -d|--debug)
            DEBUG="true"
            writeDebug "Debug enabled!"
            ;;
        -h|--help)
            show_help
            exit 1
            ;;
        -v|--version)
            show_version
            exit 1
            ;;
    esac
    shift
done

PKG_MANAGER="$(detect_package_manager)"

if [ -z "$PKG_MANAGER" ]; then
    writeDebug "Error: gerenciador de pacotes nao suportado!"
    echo "-1"
    exit 0
fi

refresh_cache_if_needed "$PKG_MANAGER"

case "$PKG_MANAGER" in
    apt)
        PKG_UPGRADE="$(count_apt_updates)"
        ;;
    dnf)
        PKG_UPGRADE="$(count_dnf_updates)"
        ;;
    yum)
        PKG_UPGRADE="$(count_yum_updates)"
        ;;
esac

writeDebug "Gerenciador detectado: ${PKG_MANAGER}"
writeDebug "Pacotes para upgrade: ${PKG_UPGRADE}"

echo "${PKG_UPGRADE}"
exit 0

