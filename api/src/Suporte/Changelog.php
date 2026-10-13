<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * O histórico de versões (api/CHANGELOG.md), lido para a tela Telium ›
 * Versões e Novidades.
 *
 * O arquivo é Markdown para ser lido também no Git, num formato fixo:
 *
 *   ## 1.1.0 — 2026-10-13
 *   Commits: ae4f854, …
 *   Uma frase de resumo.
 *   ### Novidades
 *   - item
 *
 * Linha fora do formato é ignorada: um erro de digitação no histórico não
 * pode derrubar a tela.
 */
final class Changelog
{
    /**
     * @return array{introducao:string, versoes:list<array{numero:string, data:string, commits:list<string>,
     *               resumo:string, secoes:list<array{titulo:string, itens:list<string>}>}>}
     */
    public static function ler(?string $arquivo = null): array
    {
        $arquivo ??= dirname(__DIR__, 2) . '/CHANGELOG.md';
        $linhas = is_file($arquivo) ? (file($arquivo, FILE_IGNORE_NEW_LINES) ?: []) : [];

        $introducao = [];
        $versoes = [];
        $atual = null;
        $secao = null;

        foreach ($linhas as $linha) {
            $l = rtrim($linha);
            if (preg_match('/^##\s+(\d+\.\d+\.\d+)\s+[—-]+\s+(\d{4}-\d{2}-\d{2})\s*$/u', $l, $m) === 1) {
                if ($atual !== null) {
                    $versoes[] = $atual;
                }
                $atual = ['numero' => $m[1], 'data' => $m[2], 'commits' => [], 'resumo' => '', 'secoes' => []];
                $secao = null;
                continue;
            }
            if ($atual === null) {
                if ($l !== '' && !str_starts_with($l, '#')) {
                    $introducao[] = trim($l);
                }
                continue;
            }
            if (preg_match('/^###\s+(.+)$/u', $l, $m) === 1) {
                $atual['secoes'][] = ['titulo' => trim($m[1]), 'itens' => []];
                $secao = count($atual['secoes']) - 1;
                continue;
            }
            if (preg_match('/^Commits:\s*(.*)$/u', $l, $m) === 1) {
                $atual['commits'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/u', $l, $m) === 1 && $secao !== null) {
                $atual['secoes'][$secao]['itens'][] = trim($m[1]);
                continue;
            }
            if ($l !== '' && $secao === null && $atual['resumo'] === '') {
                $atual['resumo'] = trim($l);
            }
        }
        if ($atual !== null) {
            $versoes[] = $atual;
        }

        return ['introducao' => implode(' ', $introducao), 'versoes' => $versoes];
    }
}
