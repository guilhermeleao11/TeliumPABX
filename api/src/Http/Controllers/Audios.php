<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Áudios do sistema: saudações de URA, anúncios, música em espera.
 *
 * O Asterisk toca melhor arquivos já na taxa certa, então cada envio é
 * convertido para WAV 8 kHz mono 16 bits e GSM. Ele escolhe o formato
 * mais barato na hora de tocar.
 */
final class Audios
{
    private const TAMANHO_MAX = 20 * 1024 * 1024;          // 20 MB
    private const EXTENSOES = ['wav', 'mp3', 'ogg', 'gsm', 'g722', 'alaw', 'ulaw', 'flac', 'm4a'];

    /** GET /api/audios */
    public function listar(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $where = [];
        $args = [];

        if (($p['categoria'] ?? '') !== '') {
            $where[] = 'a.categoria = ?';
            $args[] = $p['categoria'];
        }
        if (($p['q'] ?? '') !== '') {
            $where[] = '(a.nome LIKE ? OR a.arquivo LIKE ?)';
            $args = [...$args, "%{$p['q']}%", "%{$p['q']}%"];
        }

        $filtro = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        return Resposta::json($res, [
            'dados' => Bd::todos(
                "SELECT a.*, u.nome AS enviado_por_nome
                   FROM audios a
              LEFT JOIN usuarios u ON u.id = a.enviado_por
                 {$filtro}
               ORDER BY a.categoria, a.nome",
                $args
            ),
            'diretorio' => $this->diretorio(),
            'conversor' => $this->conversorDisponivel(),
        ]);
    }

    /** POST /api/audios — envio de arquivo (multipart) */
    public function enviar(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $arquivos = $req->getUploadedFiles();
        $enviado = $arquivos['arquivo'] ?? null;

        if (!$enviado instanceof UploadedFileInterface) {
            return Resposta::erro($res, 'Nenhum arquivo enviado no campo "arquivo"', 422);
        }
        if ($enviado->getError() !== UPLOAD_ERR_OK) {
            return Resposta::erro($res, 'Falha no envio: ' . $this->erroUpload($enviado->getError()), 422);
        }
        if ($enviado->getSize() > self::TAMANHO_MAX) {
            return Resposta::erro($res, 'O arquivo passa de 20 MB', 422);
        }

        $original = (string) $enviado->getClientFilename();
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSOES, true)) {
            return Resposta::erro(
                $res,
                'Formato não aceito. Envie ' . implode(', ', self::EXTENSOES) . '.',
                422
            );
        }

        $nome = trim((string) ($corpo['nome'] ?? '')) ?: pathinfo($original, PATHINFO_FILENAME);
        // Campo separado do upload: 'arquivo' é o binário, 'nome_arquivo' é o nome base.
        $base = $this->nomeBase((string) ($corpo['nome_arquivo'] ?? '') ?: $nome);

        if ($base === '') {
            return Resposta::erro($res, 'Informe um nome de arquivo válido', 422);
        }
        if (Bd::valor('SELECT COUNT(*) FROM audios WHERE arquivo = ?', [$base]) > 0) {
            return Resposta::erro($res, "Já existe um áudio com o nome {$base}", 409);
        }

        $dir = $this->diretorio();
        if (!is_dir($dir) || !is_writable($dir)) {
            return Resposta::erro(
                $res,
                "Sem permissão de escrita em {$dir}. Verifique se o diretório existe e pertence ao grupo asterisk.",
                500
            );
        }

        $temp = tempnam(sys_get_temp_dir(), 'telium-audio-');
        $enviado->moveTo($temp);

        $formatos = $this->converter($temp, $dir, $base, $ext);
        $duracao = $this->duracao($temp);
        $tamanho = (int) filesize($temp);
        @unlink($temp);

        if ($formatos === []) {
            return Resposta::erro(
                $res,
                'Não foi possível converter o áudio. Confira se o pacote sox está instalado no servidor.',
                500
            );
        }

        $categoria = in_array($corpo['categoria'] ?? '', ['ura','anuncio','espera','fila','sistema'], true)
            ? $corpo['categoria'] : 'anuncio';

        Bd::executar(
            'INSERT INTO audios (nome, arquivo, descricao, categoria, duracao, tamanho, formatos, enviado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $nome, $base, ($corpo['descricao'] ?? '') ?: null, $categoria,
                $duracao, $tamanho, implode(',', $formatos),
                ($req->getAttribute('usuario')['id'] ?? null),
            ]
        );

        Auditoria::registrar($req->getAttribute('usuario'), 'criar', 'admin.gravacoes', $base,
                             ['formatos' => $formatos, 'duracao' => $duracao]);

        return Resposta::json($res, [
            'arquivo' => $base,
            'formatos' => $formatos,
            'duracao' => $duracao,
        ], 201);
    }

    /** PUT /api/audios/{id} — renomeia/descreve (não troca o arquivo) */
    public function atualizar(Request $req, Response $res, array $args): Response
    {
        $audio = Bd::um('SELECT * FROM audios WHERE id = ?', [$args['id']]);
        if ($audio === null) {
            return Resposta::erro($res, 'Áudio não encontrado', 404);
        }

        $corpo = (array) $req->getParsedBody();
        Bd::executar(
            'UPDATE audios SET nome = ?, descricao = ?, categoria = ? WHERE id = ?',
            [
                trim((string) ($corpo['nome'] ?? $audio['nome'])) ?: $audio['nome'],
                ($corpo['descricao'] ?? $audio['descricao']) ?: null,
                in_array($corpo['categoria'] ?? '', ['ura','anuncio','espera','fila','sistema'], true)
                    ? $corpo['categoria'] : $audio['categoria'],
                $audio['id'],
            ]
        );

        return Resposta::json($res, ['ok' => true]);
    }

    /** DELETE /api/audios/{id} */
    public function remover(Request $req, Response $res, array $args): Response
    {
        $audio = Bd::um('SELECT * FROM audios WHERE id = ?', [$args['id']]);
        if ($audio === null) {
            return Resposta::erro($res, 'Áudio não encontrado', 404);
        }

        $emUso = $this->ondeEstaEmUso((string) $audio['arquivo']);
        if ($emUso !== []) {
            return Resposta::erro(
                $res,
                'Este áudio está em uso em: ' . implode(', ', $emUso) . '. Troque lá antes de excluir.',
                409
            );
        }

        foreach (explode(',', (string) $audio['formatos']) as $fmt) {
            @unlink($this->diretorio() . '/' . $audio['arquivo'] . '.' . trim($fmt));
        }

        Bd::executar('DELETE FROM audios WHERE id = ?', [$audio['id']]);
        Auditoria::registrar($req->getAttribute('usuario'), 'excluir', 'admin.gravacoes',
                             (string) $audio['arquivo']);

        return Resposta::json($res, ['removido' => true]);
    }

    // ------------------------------------------------------------------
    private function diretorio(): string
    {
        return rtrim((string) Ambiente::get('AUDIOS_DIR', '/var/lib/asterisk/sounds/telium'), '/');
    }

    private function conversorDisponivel(): bool
    {
        exec('command -v sox 2>/dev/null', $saida, $rc);
        return $rc === 0;
    }

    /**
     * Converte para os formatos que o Asterisk toca sem custo:
     * WAV 8 kHz mono 16 bits (nítido) e GSM (leve).
     *
     * @return string[] formatos gerados
     */
    private function converter(string $origem, string $dir, string $base, string $ext): array
    {
        if (!$this->conversorDisponivel()) {
            // Sem sox, ainda assim guardamos o original se já for wav.
            if ($ext === 'wav' && @copy($origem, "{$dir}/{$base}.wav")) {
                @chmod("{$dir}/{$base}.wav", 0664);
                return ['wav'];
            }
            return [];
        }

        $gerados = [];
        $alvos = [
            'wav' => sprintf('sox %s -r 8000 -c 1 -b 16 -t wav %s 2>&1',
                             escapeshellarg($origem), escapeshellarg("{$dir}/{$base}.wav")),
            'gsm' => sprintf('sox %s -r 8000 -c 1 -t gsm %s 2>&1',
                             escapeshellarg($origem), escapeshellarg("{$dir}/{$base}.gsm")),
        ];

        foreach ($alvos as $formato => $comando) {
            exec($comando, $saida, $rc);
            if ($rc === 0 && is_file("{$dir}/{$base}.{$formato}")) {
                @chmod("{$dir}/{$base}.{$formato}", 0664);
                $gerados[] = $formato;
            }
        }

        return $gerados;
    }

    private function duracao(string $arquivo): int
    {
        exec(sprintf('soxi -D %s 2>/dev/null', escapeshellarg($arquivo)), $saida, $rc);

        return $rc === 0 ? (int) round((float) ($saida[0] ?? 0)) : 0;
    }

    /** @return string[] onde o áudio está referenciado */
    private function ondeEstaEmUso(string $arquivo): array
    {
        $usos = [];

        $uras = Bd::todos('SELECT nome FROM ura WHERE audio = ?', [$arquivo]);
        foreach ($uras as $u) {
            $usos[] = "URA {$u['nome']}";
        }

        $filas = Bd::todos('SELECT numero, nome FROM filas WHERE musica_espera = ?', [$arquivo]);
        foreach ($filas as $f) {
            $usos[] = "fila {$f['numero']}";
        }

        $negra = (int) Bd::valor(
            'SELECT COUNT(*) FROM lista_negra ln JOIN audios a ON a.id = ln.audio_id WHERE a.arquivo = ?',
            [$arquivo]
        );
        if ($negra > 0) {
            $usos[] = 'lista negra';
        }

        return $usos;
    }

    private function erroUpload(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'arquivo maior que o limite do servidor',
            UPLOAD_ERR_PARTIAL => 'envio interrompido no meio',
            UPLOAD_ERR_NO_FILE => 'nenhum arquivo recebido',
            UPLOAD_ERR_NO_TMP_DIR => 'servidor sem diretório temporário',
            UPLOAD_ERR_CANT_WRITE => 'servidor não conseguiu gravar',
            default => "código {$codigo}",
        };
    }

    private function nomeBase(string $texto): string
    {
        $sem = iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto;
        $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $sem) ?? '');

        return trim(substr($base, 0, 60), '-');
    }
}
