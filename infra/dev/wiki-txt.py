#!/usr/bin/env python3
"""Converte a wiki (documentacao/wiki/*.md) em texto puro (documentacao/wiki-txt/*.txt).

A wiki da empresa recebe texto colado, sem Markdown. Os .md são a fonte; os
.txt são gerados por aqui e não se editam à mão:

    python3 infra/dev/wiki-txt.py

Títulos viram maiúsculas sublinhadas, tabelas viram listas "- Campo: texto",
os prints ficam "[PRINT: …]", as figuras "[IMAGEM: arquivo.png — legenda]"
(os arquivos estão em documentacao/wiki/imagens/, para anexar na wiki) e os
links entre páginas, "(ver página NN)".
"""
import os
import re
import sys

LARG = 78


def inline(t: str) -> str:
    def link(m):
        texto, alvo = m.group(1), m.group(2)
        if alvo.startswith('#'):
            return texto
        n = re.match(r'(?:\.\./wiki/|\./)?(\d\d)-[^)]*\.md', alvo)
        if n:
            return f'{texto} (ver página {n.group(1)})'
        if alvo.endswith('.md') or alvo.startswith('../'):
            return f'{texto} ({alvo.lstrip("./")})'
        return f'{texto} ({alvo})'
    # Figura: na wiki de texto vai como anexo, pelo nome do arquivo.
    t = re.sub(r'!\[([^\]]*)\]\(([^)]+)\)',
               lambda m: f'[IMAGEM: {m.group(2).rsplit("/", 1)[-1]} — {m.group(1)}]', t)
    t = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', link, t)
    t = re.sub(r'\*\*(.+?)\*\*', r'\1', t)
    t = re.sub(r'(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])', r'\1', t)
    t = re.sub(r'`([^`]+)`', r'\1', t)
    t = t.replace('\\*', '*').replace('\\|', '|').replace('\\_', '_')
    t = t.replace('<br>', ' ').replace('&nbsp;', ' ')
    return t


def celulas(linha: str) -> list:
    linha = linha.strip()
    if linha.startswith('|'):
        linha = linha[1:]
    if linha.endswith('|') and not linha.endswith('\\|'):
        linha = linha[:-1]
    return [inline(p.strip()) for p in re.split(r'(?<!\\)\|', linha)]


def tabela(linhas: list) -> list:
    cab = celulas(linhas[0])
    out = []
    for c in (celulas(l) for l in linhas[2:]):
        c = c + [''] * (len(cab) - len(c))
        if len(cab) == 2:
            out.append(f'- {c[0]}: {c[1]}' if c[1] else f'- {c[0]}')
        else:
            resto = '; '.join(f'{h}: {v}' for h, v in zip(cab[1:], c[1:]) if v)
            out.append(f'- {c[0]} — {resto}' if resto else f'- {c[0]}')
    return out


def converter(md: str) -> str:
    linhas = md.splitlines()
    out = []
    i = 0
    em_codigo = False
    while i < len(linhas):
        l = linhas[i]
        if l.strip().startswith('```'):
            em_codigo = not em_codigo
            i += 1
            continue
        if em_codigo:
            out.append('    ' + l)
            i += 1
            continue
        if l.strip().startswith('|') and i + 1 < len(linhas) and re.match(r'^\s*\|?\s*:?-{2,}', linhas[i + 1]):
            bloco = []
            while i < len(linhas) and linhas[i].strip().startswith('|'):
                bloco.append(linhas[i])
                i += 1
            out.extend(tabela(bloco))
            continue
        m = re.match(r'^(#{1,4})\s+(.*)$', l)
        if m:
            nivel, titulo = len(m.group(1)), inline(m.group(2)).strip()
            if nivel == 1:
                out += ['=' * LARG, titulo.upper(), '=' * LARG]
            elif nivel == 2:
                out += ['', '', titulo.upper(), '-' * min(LARG, len(titulo))]
            else:
                out += ['', titulo, '~' * min(LARG, len(titulo))]
            i += 1
            continue
        if re.match(r'^\s*(-{3,}|\*{3,})\s*$', l):
            i += 1
            continue
        m = re.match(r'^>\s?(.*)$', l)
        if m:
            t = inline(m.group(1)).strip()
            p = re.match(r'^(?:📷\s*)?(?:Sugestão de print|Print)\s*:\s*(.*)$', t)
            if p:
                out.append(f'[PRINT: {p.group(1).rstrip(".")}]')
            elif t.startswith('[PRINT:'):
                out.append(t)
            elif t:
                out.append(f'NOTA: {t}' if not out or not out[-1].startswith(('NOTA:', '      ')) else f'      {t}')
            i += 1
            continue
        out.append(inline(l))
        i += 1

    texto = re.sub(r'\n{4,}', '\n\n\n', '\n'.join(out))
    return texto.strip() + '\n'


def main(origem: str, destino: str) -> None:
    os.makedirs(destino, exist_ok=True)
    for nome in sorted(os.listdir(origem)):
        if nome.endswith('.md'):
            with open(os.path.join(origem, nome), encoding='utf-8') as f:
                txt = converter(f.read())
            with open(os.path.join(destino, nome[:-3] + '.txt'), 'w', encoding='utf-8') as f:
                f.write(txt)
            print(f'{nome[:-3]}.txt')


if __name__ == '__main__':
    raiz = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    main(sys.argv[1] if len(sys.argv) > 1 else os.path.join(raiz, 'documentacao', 'wiki'),
         sys.argv[2] if len(sys.argv) > 2 else os.path.join(raiz, 'documentacao', 'wiki-txt'))
