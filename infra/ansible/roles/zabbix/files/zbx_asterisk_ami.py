#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import socket
import sys
import re

AMI_HOST = "127.0.0.1"
AMI_PORT = 5038
# Usuário e senha do AMI vêm de /etc/zabbix/telium-ami.conf (root:zabbix
# 0640), escrito pelo Ansible do Telium PABX: cada central tem a sua senha
# e ela não fica no código. Linhas "usuario=..." e "senha=...".
AMI_CONF = "/etc/zabbix/telium-ami.conf"
TIMEOUT = 5


def ami_credenciais():
    dados = {}
    with open(AMI_CONF, encoding="utf-8") as f:
        for linha in f:
            if "=" in linha and not linha.lstrip().startswith("#"):
                chave, valor = linha.split("=", 1)
                dados[chave.strip()] = valor.strip()
    return dados["usuario"], dados["senha"]


def ami_command(command):
    ami_user, ami_pass = ami_credenciais()
    payload = (
        f"Action: Login\r\n"
        f"Username: {ami_user}\r\n"
        f"Secret: {ami_pass}\r\n"
        f"\r\n"
        f"Action: Command\r\n"
        f"Command: {command}\r\n"
        f"\r\n"
        f"Action: Logoff\r\n"
        f"\r\n"
    )

    data = b""

    with socket.create_connection((AMI_HOST, AMI_PORT), timeout=TIMEOUT) as sock:
        sock.settimeout(TIMEOUT)
        sock.sendall(payload.encode())

        while True:
            try:
                part = sock.recv(4096)
                if not part:
                    break
                data += part
            except socket.timeout:
                break

    return data.decode(errors="ignore")

def only_command_output(text):
    lines = text.splitlines()
    output_lines = []
    capture = False
    has_output_prefix = False

    for line in lines:
        if line.startswith("Output:"):
            has_output_prefix = True
            value = line.replace("Output:", "", 1).strip()

            if "--END COMMAND--" in value:
                break

            output_lines.append(value)

    if has_output_prefix:
        return "\n".join(output_lines).strip()

    for line in lines:
        if line.startswith("Response: Follows"):
            capture = True
            continue

        if capture:
            if "--END COMMAND--" in line:
                break

            if line.startswith("Privilege:"):
                continue

            if line.startswith("ActionID:"):
                continue

            output_lines.append(line.strip())

    return "\n".join(output_lines).strip()

def get_last_non_empty_line(text):
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    return lines[-1] if lines else ""


def get_bracket_content(line):
    m = re.search(r"\[(.*?)\]", line)
    return m.group(1) if m else ""


def metric_channels_active():
    out = only_command_output(ami_command("core show channels"))
    m = re.search(r"(\d+)\s+active channels", out, re.IGNORECASE)
    return m.group(1) if m else "0"


def metric_calls_active():
    out = only_command_output(ami_command("core show channels"))
    m = re.search(r"(\d+)\s+active calls", out, re.IGNORECASE)
    return m.group(1) if m else "0"


def metric_calls_processed():
    out = only_command_output(ami_command("core show channels"))
    m = re.search(r"(\d+)\s+calls processed", out, re.IGNORECASE)
    return m.group(1) if m else "0"


def metric_pjsip_endpoints_total():
    out = only_command_output(ami_command("pjsip show endpoints"))
    return str(len(re.findall(r"^Endpoint:", out, re.MULTILINE)))


def metric_pjsip_endpoints_online():
    out = only_command_output(ami_command("pjsip show endpoints"))
    return str(len(re.findall(r"\bAvail\b", out, re.IGNORECASE)))


def metric_pjsip_endpoints_offline():
    out = only_command_output(ami_command("pjsip show endpoints"))
    return str(len(re.findall(r"\bUnavail\b", out, re.IGNORECASE)))


def metric_sip_peers_total():
    out = only_command_output(ami_command("sip show peers"))
    last_line = get_last_non_empty_line(out)

    m = re.search(r"^(\d+)\s+sip peers", last_line, re.IGNORECASE)
    if m:
        return m.group(1)

    m = re.search(r"(\d+)\s+sip peers", out, re.IGNORECASE)
    return m.group(1) if m else "0"


def metric_sip_peers_online():
    out = only_command_output(ami_command("sip show peers"))
    last_line = get_last_non_empty_line(out)
    bracket = get_bracket_content(last_line)

    m = re.search(r"Monitored:\s*(\d+)\s+online", bracket, re.IGNORECASE)
    if m:
        return m.group(1)

    m = re.search(r"(\d+)\s+online", bracket, re.IGNORECASE)
    return m.group(1) if m else "0"


def metric_sip_peers_offline():
    out = only_command_output(ami_command("sip show peers"))
    last_line = get_last_non_empty_line(out)
    bracket = get_bracket_content(last_line)

    m = re.search(r"Monitored:\s*\d+\s+online,\s*(\d+)\s+offline", bracket, re.IGNORECASE)
    if m:
        return m.group(1)

    m = re.search(r"(\d+)\s+offline", bracket, re.IGNORECASE)
    return m.group(1) if m else "0"


def metric_queue_calls():
    out = only_command_output(ami_command("queue show"))
    total = 0

    for value in re.findall(r"has\s+(\d+)\s+calls", out, re.IGNORECASE):
        total += int(value)

    return str(total)


def metric_queue_count():
    out = only_command_output(ami_command("queue show"))
    return str(len(re.findall(r"has\s+\d+\s+calls", out, re.IGNORECASE)))


def metric_queue_agents():
    out = only_command_output(ami_command("queue show"))
    return str(len(re.findall(r"\bAgent\b", out, re.IGNORECASE)))


def metric_queue_agents_busy():
    out = only_command_output(ami_command("queue show"))
    return str(len(re.findall(r"in call", out, re.IGNORECASE)))


def metric_queue_agents_free():
    out = only_command_output(ami_command("queue show"))
    return str(len(re.findall(r"Not in use", out, re.IGNORECASE)))


def metric_queue_agents_paused():
    out = only_command_output(ami_command("queue show"))
    return str(len(re.findall(r"paused", out, re.IGNORECASE)))


def metric_uptime():
    out = only_command_output(ami_command("core show uptime"))
    m = re.search(r"System uptime:\s*(.+)", out, re.IGNORECASE)
    return m.group(1).strip() if m else ""


def metric_threads():
    out = only_command_output(ami_command("core show threads"))
    lines = [line for line in out.splitlines() if line.strip()]
    return str(len(lines))


def metric_tasks():
    out = only_command_output(ami_command("core show taskprocessors"))
    lines = [line for line in out.splitlines() if line.strip()]
    return str(len(lines))


def metric_version():
    out = only_command_output(ami_command("core show version"))
    m = re.search(r"Asterisk\s+([\w\.\-\~]+)", out, re.IGNORECASE)

    if m:
        return m.group(1)

    first_line = out.splitlines()[0].strip() if out.splitlines() else ""
    return first_line


def debug_command(command):
    print(only_command_output(ami_command(command)))


def get_value(metric):
    metrics = {
        "channels.active": metric_channels_active,
        "calls.active": metric_calls_active,
        "calls.processed": metric_calls_processed,

        "pjsip.endpoints.total": metric_pjsip_endpoints_total,
        "pjsip.endpoints.online": metric_pjsip_endpoints_online,
        "pjsip.endpoints.offline": metric_pjsip_endpoints_offline,

        "sip.peers.total": metric_sip_peers_total,
        "sip.peers.online": metric_sip_peers_online,
        "sip.peers.offline": metric_sip_peers_offline,

        "queue.calls": metric_queue_calls,
        "queue.count": metric_queue_count,
        "queue.agents": metric_queue_agents,
        "queue.agents.busy": metric_queue_agents_busy,
        "queue.agents.free": metric_queue_agents_free,
        "queue.agents.paused": metric_queue_agents_paused,

        "uptime": metric_uptime,
        "threads": metric_threads,
        "tasks": metric_tasks,
        "version": metric_version,
    }

    if metric in metrics:
        return metrics[metric]()

    if metric == "debug.raw.sip":
        print(ami_command("sip show peers"))
        return None

    if metric == "debug.sip":
        debug_command("sip show peers")
        return None

    if metric == "debug.pjsip":
        debug_command("pjsip show endpoints")
        return None

    if metric == "debug.channels":
        debug_command("core show channels")
        return None

    if metric == "debug.queue":
        debug_command("queue show")
        return None

    return "ZBX_NOTSUPPORTED"


def main():
    if len(sys.argv) != 2:
        print("ZBX_NOTSUPPORTED")
        sys.exit(1)

    try:
        result = get_value(sys.argv[1])

        if result is not None:
            print(result)

    except Exception:
        print("ZBX_NOTSUPPORTED")
        sys.exit(1)


if __name__ == "__main__":
    main()

