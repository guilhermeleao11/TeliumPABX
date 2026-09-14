#!/usr/bin/env python3
"""
Telefone SIP de bancada: registra, atende e conta o que recebeu.

Não faz mídia. Serve para provar o que o Asterisk manda — cabeçalho de
atendimento automático, ordem de toque numa fila, siga-me — sem precisar
de um aparelho de verdade em cima da mesa.

    fone.py <ramal> <senha> <host> <porta> [--mostrar Call-Info,Alert-Info]
"""
import socket, sys, hashlib, uuid, re, time

RAMAL, SENHA, HOST, PORTA = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
MOSTRAR = []
if '--mostrar' in sys.argv:
    MOSTRAR = [c.strip().lower() for c in sys.argv[sys.argv.index('--mostrar') + 1].split(',')]

RTPP = PORTA + 1000
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.bind(('127.0.0.1', PORTA))
s.settimeout(1)

md5 = lambda x: hashlib.md5(x.encode()).hexdigest()


def autorizacao(cabecalho, metodo, uri):
    d = dict(re.findall(r'(\w+)="?([^",]+)"?', cabecalho))
    ha1 = md5(f'{RAMAL}:{d["realm"]}:{SENHA}')
    ha2 = md5(f'{metodo}:{uri}')
    if d.get('qop'):
        nc, cn = '00000001', uuid.uuid4().hex[:8]
        r = md5(f'{ha1}:{d["nonce"]}:{nc}:{cn}:{d["qop"]}:{ha2}')
        return (f'Digest username="{RAMAL}", realm="{d["realm"]}", nonce="{d["nonce"]}", '
                f'uri="{uri}", response="{r}", qop={d["qop"]}, nc={nc}, cnonce="{cn}"')
    r = md5(f'{ha1}:{d["nonce"]}:{ha2}')
    return (f'Digest username="{RAMAL}", realm="{d["realm"]}", nonce="{d["nonce"]}", '
            f'uri="{uri}", response="{r}"')


def cab(texto, nome):
    for l in texto.split('\r\n'):
        if l.lower().startswith(nome.lower() + ':'):
            return l.split(':', 1)[1].strip()
    return ''


tag = uuid.uuid4().hex[:8]


def registrar(autoriz='', cseq=1):
    a = f'Authorization: {autoriz}\r\n' if autoriz else ''
    s.sendto((
        f'REGISTER sip:{HOST} SIP/2.0\r\n'
        f'Via: SIP/2.0/UDP 127.0.0.1:{PORTA};branch=z9hG4bK{uuid.uuid4().hex[:8]};rport\r\n'
        f'Max-Forwards: 70\r\nFrom: <sip:{RAMAL}@{HOST}>;tag={tag}\r\n'
        f'To: <sip:{RAMAL}@{HOST}>\r\nCall-ID: reg-{RAMAL}-{tag}\r\n'
        f'CSeq: {cseq} REGISTER\r\nContact: <sip:{RAMAL}@127.0.0.1:{PORTA}>\r\n'
        f'{a}Expires: 300\r\nContent-Length: 0\r\n\r\n').encode(), (HOST, 5060))


def responder(texto, origem, linha, extra='', corpo=''):
    base = ''.join(f'{n}: {cab(texto, n)}\r\n' for n in ('Via', 'From', 'Call-ID', 'CSeq'))
    to = cab(texto, 'To')
    if 'tag=' not in to:
        to += f';tag=at{tag}'
    s.sendto((f'SIP/2.0 {linha}\r\n{base}To: {to}\r\n{extra}'
              f'Content-Length: {len(corpo)}\r\n\r\n{corpo}').encode(), origem)


registrar()
fim = time.time() + 3600
proximo_registro = time.time() + 120
cseq = 3

while time.time() < fim:
    # Registro vence em 300 s. Sem renovar, o telefone some da central no
    # meio do teste e o erro que aparece é "endpoint não registrado" —
    # que parece defeito do sistema e é só o fone de bancada dormindo.
    if time.time() > proximo_registro:
        registrar(cseq=cseq)
        cseq += 1
        proximo_registro = time.time() + 120

    try:
        dados, origem = s.recvfrom(9000)
    except socket.timeout:
        continue
    t = dados.decode('utf8', 'replace')

    if t.startswith(('SIP/2.0 401', 'SIP/2.0 407')):
        wa = cab(t, 'WWW-Authenticate') or cab(t, 'Proxy-Authenticate')
        cseq += 1
        registrar(autorizacao(wa, 'REGISTER', f'sip:{HOST}'), cseq)
    elif t.startswith('SIP/2.0 200') and 'REGISTER' in cab(t, 'CSeq'):
        print(f'[{RAMAL}] registrado', flush=True)
    elif t.startswith('INVITE'):
        quem = re.search(r'sip:([^@>]+)', cab(t, 'From'))
        print(f'[{RAMAL}] INVITE de {quem.group(1) if quem else "?"}', flush=True)
        for nome in MOSTRAR:
            valor = cab(t, nome)
            if valor:
                print(f'[{RAMAL}]   {nome}: {valor}', flush=True)
        responder(t, origem, '180 Ringing')
        time.sleep(0.3)
        sdp = (f'v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\n'
               f'm=audio {RTPP} RTP/AVP 0 101\r\na=rtpmap:0 PCMU/8000\r\n'
               f'a=rtpmap:101 telephone-event/8000\r\na=sendrecv\r\n')
        responder(t, origem, '200 OK',
                  f'Contact: <sip:{RAMAL}@127.0.0.1:{PORTA}>\r\nContent-Type: application/sdp\r\n',
                  sdp)
        print(f'[{RAMAL}] atendeu', flush=True)
    elif t.startswith('BYE'):
        responder(t, origem, '200 OK')
        print(f'[{RAMAL}] desligou', flush=True)
    elif t.startswith(('OPTIONS', 'CANCEL')):
        responder(t, origem, '200 OK' if t.startswith('OPTIONS') else '200 OK')
