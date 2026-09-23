<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Certificado;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Certificados TLS da interface web, do SIP TLS e do Janus.
 *
 * A API guarda os arquivos num cofre que pertence a ela e registra a
 * atribuição no banco. Quem copia para /etc/ssl, /etc/asterisk/keys e
 * /etc/janus/certs e recarrega os serviços é /usr/local/sbin/telium-certificados,
 * que roda como root e não aceita argumento nenhum — mesmo desenho do backup.
 */
final class Certificados
{
    private const TAMANHO_MAX = 512 * 1024;      // 512 KB é muito para um PEM

    /** GET /api/certificados */
    public function listar(Request $req, Response $res): Response
    {
        $certs = Bd::todos(
            'SELECT c.*, u.nome AS enviado_por_nome
               FROM certificados c
          LEFT JOIN usuarios u ON u.id = c.enviado_por
           ORDER BY c.estado DESC, c.valido_ate IS NULL, c.valido_ate'
        );

        $servicos = Bd::todos(
            'SELECT s.*, c.nome AS certificado_nome, c.cn, c.valido_ate
               FROM certificado_servicos s
          LEFT JOIN certificados c ON c.id = s.certificado_id'
        );

        $usoPorCert = [];
        foreach ($servicos as $s) {
            if ($s['certificado_id'] !== null) {
                $usoPorCert[(int) $s['certificado_id']][] = $s['servico'];
            }
        }

        foreach ($certs as &$c) {
            $c['dias'] = Certificado::diasParaVencer($c['valido_ate']);
            $c['servicos'] = $usoPorCert[(int) $c['id']] ?? [];
        }
        unset($c);

        foreach ($servicos as &$s) {
            $s['rotulo'] = Certificado::rotuloServico((string) $s['servico']);
            $s['dias'] = Certificado::diasParaVencer($s['valido_ate'] ?? null);
            $s['destino'] = $this->destinos()[$s['servico']] ?? '';
        }
        unset($s);

        return Resposta::json($res, [
            'dados'    => $certs,
            'servicos' => $servicos,
            'cofre'    => $this->cofre(),
            'openssl'  => Certificado::disponivel(),
            'cofre_ok' => is_dir($this->cofre()) && is_writable($this->cofre()),
            'aplicando' => (int) Bd::valor(
                "SELECT COUNT(*) FROM certificado_servicos WHERE estado = 'pendente'"
            ) > 0,
        ]);
    }

    /** POST /api/certificados — envio de cert + chave (+ cadeia), multipart */
    public function enviar(Request $req, Response $res): Response
    {
        if (!Certificado::disponivel()) {
            return Resposta::erro($res, 'A extensão openssl do PHP não está ativa no servidor.', 500);
        }

        $corpo = (array) $req->getParsedBody();
        $arqs = $req->getUploadedFiles();

        $certPem = $this->texto($arqs['certificado'] ?? null, $corpo['certificado'] ?? null);
        $chavePem = $this->texto($arqs['chave'] ?? null, $corpo['chave'] ?? null);
        $cadeiaPem = $this->texto($arqs['cadeia'] ?? null, $corpo['cadeia'] ?? null);

        if ($certPem === null) {
            return Resposta::erro($res, 'Envie o arquivo do certificado (.crt, .pem ou .cer).', 422,
                                  ['campo' => 'certificado']);
        }
        if ($chavePem === null) {
            return Resposta::erro($res, 'Envie a chave privada (.key) que corresponde ao certificado.', 422,
                                  ['campo' => 'chave']);
        }

        // O arquivo do certificado muitas vezes já vem com a cadeia junto.
        $blocos = Certificado::separarPem($certPem);
        if ($blocos === []) {
            return Resposta::erro($res, 'O arquivo enviado não contém nenhum certificado PEM.', 422,
                                  ['campo' => 'certificado']);
        }
        $folha = $blocos[0];
        if (count($blocos) > 1 && $cadeiaPem === null) {
            $cadeiaPem = implode("\n", array_slice($blocos, 1));
        }

        $info = Certificado::inspecionar($folha);
        if (!$info['ok']) {
            return Resposta::erro($res, $info['erro'], 422, ['campo' => 'certificado']);
        }

        $conf = Certificado::chaveConfere($folha, $chavePem, (string) ($corpo['senha_chave'] ?? ''));
        if (!$conf['ok']) {
            return Resposta::erro($res, $conf['erro'], 422, ['campo' => 'chave']);
        }

        $avisos = [];
        if ($cadeiaPem !== null) {
            $cad = Certificado::cadeiaCobre($folha, $cadeiaPem);
            if (!$cad['ok']) {
                $avisos[] = $cad['erro'];
            }
        }
        $dias = Certificado::diasParaVencer($info['dados']['valido_ate']);
        if ($dias !== null && $dias < 0) {
            $avisos[] = 'Atenção: este certificado venceu em '
                      . date('d/m/Y', (int) strtotime((string) $info['dados']['valido_ate'])) . '.';
        }

        $nome = trim((string) ($corpo['nome'] ?? '')) ?: ($info['dados']['cn'] ?: 'Certificado');

        return $this->gravar($req, $res, $nome, $corpo, $info['dados'], [
            'crt' => $folha,
            'key' => $chavePem,
            'chain' => $cadeiaPem,
        ], 'upload', 'pronto', $avisos);
    }

    /** POST /api/certificados/autoassinado */
    public function autoassinado(Request $req, Response $res): Response
    {
        if (!Certificado::disponivel()) {
            return Resposta::erro($res, 'A extensão openssl do PHP não está ativa no servidor.', 500);
        }

        $corpo = (array) $req->getParsedBody();
        $cn = trim((string) ($corpo['cn'] ?? ''));
        if ($cn === '') {
            return Resposta::erro($res, 'Informe o nome principal (o endereço pelo qual o PABX é acessado).',
                                  422, ['campo' => 'cn']);
        }

        $dias = max(1, min(3650, (int) ($corpo['dias'] ?? 825)));
        $g = Certificado::gerarAutoassinado($cn, $this->nomes($corpo['san'] ?? ''), $dias, $corpo);
        if (!$g['ok']) {
            return Resposta::erro($res, $g['erro'], 500);
        }

        $info = Certificado::inspecionar($g['cert']);

        return $this->gravar(
            $req, $res,
            trim((string) ($corpo['nome'] ?? '')) ?: $cn,
            $corpo, $info['dados'],
            ['crt' => $g['cert'], 'key' => $g['chave'], 'chain' => null],
            'autoassinado', 'pronto',
            ['Certificado autoassinado: os navegadores vão avisar que não confiam nele '
             . 'até que alguém instale esta autoridade nas máquinas.']
        );
    }

    /** POST /api/certificados/csr — gera chave + pedido para uma autoridade assinar */
    public function csr(Request $req, Response $res): Response
    {
        if (!Certificado::disponivel()) {
            return Resposta::erro($res, 'A extensão openssl do PHP não está ativa no servidor.', 500);
        }

        $corpo = (array) $req->getParsedBody();
        $cn = trim((string) ($corpo['cn'] ?? ''));
        if ($cn === '') {
            return Resposta::erro($res, 'Informe o nome principal do certificado.', 422, ['campo' => 'cn']);
        }

        $g = Certificado::gerarCsr($cn, $this->nomes($corpo['san'] ?? ''), $corpo);
        if (!$g['ok']) {
            return Resposta::erro($res, $g['erro'], 500);
        }

        $base = $this->baseLivre($cn);
        $erro = $this->escrever($base, ['key' => $g['chave'], 'csr' => $g['csr']]);
        if ($erro !== null) {
            return Resposta::erro($res, $erro, 500);
        }

        Bd::executar(
            'INSERT INTO certificados (nome, base, descricao, origem, estado, cn, san, enviado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                trim((string) ($corpo['nome'] ?? '')) ?: $cn, $base,
                ($corpo['descricao'] ?? '') ?: null, 'csr', 'aguardando_assinatura',
                $cn, implode("\n", $this->nomes($corpo['san'] ?? '')) ?: null,
                $req->getAttribute('usuario')['id'] ?? null,
            ]
        );
        $id = (int) Bd::conexao()->lastInsertId();

        Auditoria::registrar($req->getAttribute('usuario'), 'criar', 'admin.certificados', $base,
                             ['origem' => 'csr', 'cn' => $cn]);

        return Resposta::json($res, ['id' => $id, 'csr' => $g['csr'], 'base' => $base], 201);
    }

    /** GET /api/certificados/{id}/pem — texto do CSR ou do certificado público */
    public function pem(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT * FROM certificados WHERE id = ?', [$args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Certificado não encontrado', 404);
        }

        $csr = $this->cofre() . '/' . $c['base'] . '.csr';
        $crt = $this->cofre() . '/' . $c['base'] . '.crt';
        $arquivo = $c['estado'] === 'aguardando_assinatura' ? $csr : $crt;

        if (!is_file($arquivo)) {
            return Resposta::erro($res, 'O arquivo não está mais no cofre do servidor.', 404);
        }

        // A chave privada nunca sai por aqui — só o pedido e a parte pública.
        return Resposta::json($res, [
            'tipo' => $c['estado'] === 'aguardando_assinatura' ? 'csr' : 'certificado',
            'nome' => basename($arquivo),
            'pem'  => (string) file_get_contents($arquivo),
        ]);
    }

    /** POST /api/certificados/{id}/assinar — recebe o certificado emitido para um CSR */
    public function assinar(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT * FROM certificados WHERE id = ?', [$args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Certificado não encontrado', 404);
        }
        if ($c['estado'] !== 'aguardando_assinatura') {
            return Resposta::erro($res, 'Este certificado já está completo.', 409);
        }

        $corpo = (array) $req->getParsedBody();
        $arqs = $req->getUploadedFiles();
        $certPem = $this->texto($arqs['certificado'] ?? null, $corpo['certificado'] ?? null);
        $cadeiaPem = $this->texto($arqs['cadeia'] ?? null, $corpo['cadeia'] ?? null);

        if ($certPem === null) {
            return Resposta::erro($res, 'Cole ou envie o certificado que a autoridade emitiu.', 422,
                                  ['campo' => 'certificado']);
        }

        $blocos = Certificado::separarPem($certPem);
        if ($blocos === []) {
            return Resposta::erro($res, 'O conteúdo enviado não contém um certificado PEM.', 422,
                                  ['campo' => 'certificado']);
        }
        $folha = $blocos[0];
        if (count($blocos) > 1 && $cadeiaPem === null) {
            $cadeiaPem = implode("\n", array_slice($blocos, 1));
        }

        $chave = @file_get_contents($this->cofre() . '/' . $c['base'] . '.key');
        if ($chave === false) {
            return Resposta::erro($res, 'A chave privada deste pedido não está mais no cofre. '
                                      . 'Gere um novo pedido.', 409);
        }

        $conf = Certificado::chaveConfere($folha, (string) $chave);
        if (!$conf['ok']) {
            return Resposta::erro($res, 'O certificado enviado não corresponde ao pedido gerado aqui. '
                                      . 'Confira se é a emissão deste CSR.', 422, ['campo' => 'certificado']);
        }

        $info = Certificado::inspecionar($folha);
        $arquivos = ['crt' => $folha];
        if ($cadeiaPem !== null) {
            $arquivos['chain.crt'] = $cadeiaPem;
            $arquivos['fullchain.crt'] = rtrim($folha) . "\n" . trim($cadeiaPem) . "\n";
        } else {
            $arquivos['fullchain.crt'] = rtrim($folha) . "\n";
        }

        $erro = $this->escrever((string) $c['base'], $arquivos);
        if ($erro !== null) {
            return Resposta::erro($res, $erro, 500);
        }

        $d = $info['dados'];
        Bd::executar(
            "UPDATE certificados SET estado = 'pronto', cn = ?, san = ?, emissor = ?, serie = ?,
                    algoritmo = ?, assinatura = ?, impressao = ?, autoassinado = ?, tem_cadeia = ?,
                    valido_de = ?, valido_ate = ? WHERE id = ?",
            [
                $d['cn'], $d['san'] ?: null, $d['emissor'], $d['serie'], $d['algoritmo'],
                $d['assinatura'], $d['impressao'], $d['autoassinado'], $cadeiaPem !== null ? 1 : 0,
                $d['valido_de'], $d['valido_ate'], $c['id'],
            ]
        );

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'admin.certificados',
                             (string) $c['base'], ['acao' => 'assinado', 'emissor' => $d['emissor']]);

        return Resposta::json($res, ['id' => (int) $c['id'], 'valido_ate' => $d['valido_ate']]);
    }

    /** PUT /api/certificados/{id} — só nome e descrição */
    public function atualizar(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT * FROM certificados WHERE id = ?', [$args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Certificado não encontrado', 404);
        }

        $corpo = (array) $req->getParsedBody();
        Bd::executar(
            'UPDATE certificados SET nome = ?, descricao = ? WHERE id = ?',
            [
                trim((string) ($corpo['nome'] ?? $c['nome'])) ?: $c['nome'],
                ($corpo['descricao'] ?? $c['descricao']) ?: null,
                $c['id'],
            ]
        );

        return Resposta::json($res, ['ok' => true]);
    }

    /** DELETE /api/certificados/{id} */
    public function remover(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT * FROM certificados WHERE id = ?', [$args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Certificado não encontrado', 404);
        }

        $usos = Bd::todos('SELECT servico FROM certificado_servicos WHERE certificado_id = ?', [$c['id']]);
        if ($usos !== []) {
            $rotulos = array_map(
                static fn ($u) => Certificado::rotuloServico((string) $u['servico']),
                $usos
            );

            return Resposta::erro(
                $res,
                'Este certificado está em uso em: ' . implode(', ', $rotulos)
                . '. Aponte esses serviços para outro certificado antes de excluir.',
                409
            );
        }

        foreach (['crt', 'key', 'csr', 'chain.crt', 'fullchain.crt'] as $ext) {
            @unlink($this->cofre() . '/' . $c['base'] . '.' . $ext);
        }

        Bd::executar('DELETE FROM certificados WHERE id = ?', [$c['id']]);
        Auditoria::registrar($req->getAttribute('usuario'), 'excluir', 'admin.certificados',
                             (string) $c['base']);

        return Resposta::json($res, ['removido' => true]);
    }

    /** POST /api/certificados/aplicar — atribui certificados aos serviços e dispara o aplicador */
    public function aplicar(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $pedido = (array) ($corpo['servicos'] ?? $corpo);
        $mudou = [];

        foreach (Certificado::SERVICOS as $servico) {
            if (!array_key_exists($servico, $pedido)) {
                continue;
            }

            $id = $pedido[$servico];
            $id = ($id === null || $id === '' || $id === 'fabrica') ? null : (int) $id;

            if ($id !== null) {
                $cert = Bd::um("SELECT * FROM certificados WHERE id = ? AND estado = 'pronto'", [$id]);
                if ($cert === null) {
                    return Resposta::erro(
                        $res,
                        'O certificado escolhido para ' . Certificado::rotuloServico($servico)
                        . ' não existe ou ainda está aguardando assinatura.',
                        422,
                        ['campo' => $servico]
                    );
                }
                if (!is_file($this->cofre() . '/' . $cert['base'] . '.key')) {
                    return Resposta::erro(
                        $res,
                        'A chave privada de "' . $cert['nome'] . '" não está no cofre do servidor. '
                        . 'Envie o par novamente.',
                        409
                    );
                }
            }

            $atual = Bd::um('SELECT certificado_id FROM certificado_servicos WHERE servico = ?', [$servico]);
            if ($atual !== null && (int) ($atual['certificado_id'] ?? 0) === (int) $id) {
                continue;
            }

            Bd::executar(
                "INSERT INTO certificado_servicos (servico, certificado_id, estado)
                 VALUES (?, ?, 'pendente')
                 ON DUPLICATE KEY UPDATE certificado_id = VALUES(certificado_id), estado = 'pendente'",
                [$servico, $id]
            );
            $mudou[] = $servico;
        }

        if ($mudou === []) {
            return Resposta::json($res, [
                'aplicado' => false,
                'detalhe' => 'Nenhuma mudança: os serviços já usam esses certificados.',
            ]);
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'admin.certificados',
                             implode(',', $mudou), ['servicos' => $pedido]);

        $disparo = $this->dispararAplicador();

        // Lido DEPOIS de o aplicador terminar: é o que cada serviço
        // realmente ficou, e não o que se pediu.
        $estados = [];
        foreach (Bd::todos('SELECT servico, estado, saida FROM certificado_servicos') as $s) {
            if (in_array($s['servico'], $mudou, true)) {
                $estados[] = $s;
            }
        }
        $falhou = array_values(array_filter(
            $estados,
            static fn (array $s): bool => $s['estado'] === 'falha'
        ));

        return Resposta::json($res, [
            'aplicado' => $disparo['ok'] && $falhou === [],
            'servicos' => $mudou,
            'estados'  => $estados,
            'detalhe'  => $falhou !== []
                ? implode(' ', array_map(
                    static fn (array $s): string
                        => Certificado::rotuloServico((string) $s['servico']) . ': ' . $s['saida'],
                    $falhou
                ))
                : $disparo['detalhe'],
        ], $falhou !== [] ? 200 : 202);
    }

    // ------------------------------------------------------------------
    /** Grava os arquivos no cofre e registra o certificado. */
    private function gravar(
        Request $req,
        Response $res,
        string $nome,
        array $corpo,
        array $d,
        array $pem,
        string $origem,
        string $estado,
        array $avisos
    ): Response {
        $base = $this->baseLivre($d['cn'] ?: $nome);

        $arquivos = ['crt' => $pem['crt'], 'key' => $pem['key']];
        if ($pem['chain'] !== null) {
            $arquivos['chain.crt'] = $pem['chain'];
            $arquivos['fullchain.crt'] = rtrim($pem['crt']) . "\n" . trim($pem['chain']) . "\n";
        } else {
            $arquivos['fullchain.crt'] = rtrim($pem['crt']) . "\n";
        }

        $erro = $this->escrever($base, $arquivos);
        if ($erro !== null) {
            return Resposta::erro($res, $erro, 500);
        }

        Bd::executar(
            'INSERT INTO certificados (nome, base, descricao, origem, estado, cn, san, emissor, serie,
                                       algoritmo, assinatura, impressao, autoassinado, tem_cadeia,
                                       valido_de, valido_ate, enviado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $nome, $base, ($corpo['descricao'] ?? '') ?: null, $origem, $estado,
                $d['cn'], $d['san'] ?: null, $d['emissor'], $d['serie'], $d['algoritmo'],
                $d['assinatura'], $d['impressao'], $d['autoassinado'], $pem['chain'] !== null ? 1 : 0,
                $d['valido_de'], $d['valido_ate'],
                $req->getAttribute('usuario')['id'] ?? null,
            ]
        );
        $id = (int) Bd::conexao()->lastInsertId();

        Auditoria::registrar($req->getAttribute('usuario'), 'criar', 'admin.certificados', $base,
                             ['origem' => $origem, 'cn' => $d['cn'], 'vence' => $d['valido_ate']]);

        return Resposta::json($res, [
            'id' => $id,
            'base' => $base,
            'cn' => $d['cn'],
            'valido_ate' => $d['valido_ate'],
            'dias' => Certificado::diasParaVencer($d['valido_ate']),
            'avisos' => $avisos,
        ], 201);
    }

    /**
     * Escreve o conjunto no cofre. A chave privada fica 0600; a parte
     * pública 0644, porque o aplicador roda como root e lê tudo mesmo.
     */
    private function escrever(string $base, array $arquivos): ?string
    {
        $dir = $this->cofre();
        if (!is_dir($dir)) {
            return "O cofre de certificados {$dir} não existe no servidor. "
                 . 'Rode o playbook do Ansible para criá-lo.';
        }
        if (!is_writable($dir)) {
            $usuario = function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();

            return "Sem permissão de escrita em {$dir} (a API roda como {$usuario}). "
                 . 'O diretório precisa pertencer a esse usuário.';
        }

        foreach ($arquivos as $ext => $conteudo) {
            $caminho = "{$dir}/{$base}.{$ext}";
            $tmp = $caminho . '.novo';
            if (@file_put_contents($tmp, $conteudo) === false) {
                @unlink($tmp);
                return "Não foi possível gravar {$caminho}.";
            }
            @chmod($tmp, $ext === 'key' ? 0600 : 0644);
            if (!@rename($tmp, $caminho)) {
                @unlink($tmp);
                return "Não foi possível concluir a gravação de {$caminho}.";
            }
        }

        return null;
    }

    /** Um nome de arquivo que ainda não existe no cofre nem no banco. */
    private function baseLivre(string $texto): string
    {
        $sem = iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto;
        $base = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $sem) ?? ''), '-');
        $base = substr($base, 0, 48) ?: 'certificado';

        $tentativa = $base;
        $n = 1;
        while (
            (int) Bd::valor('SELECT COUNT(*) FROM certificados WHERE base = ?', [$tentativa]) > 0
            || is_file($this->cofre() . '/' . $tentativa . '.crt')
        ) {
            $tentativa = $base . '-' . (++$n);
        }

        return $tentativa;
    }

    /** Lê o PEM do upload ou do campo de texto colado. */
    private function texto(mixed $enviado, mixed $colado): ?string
    {
        if ($enviado instanceof UploadedFileInterface && $enviado->getError() === UPLOAD_ERR_OK) {
            if ($enviado->getSize() > self::TAMANHO_MAX) {
                return null;
            }
            $conteudo = (string) $enviado->getStream();
            if (trim($conteudo) !== '') {
                return $conteudo;
            }
        }

        $colado = is_string($colado) ? trim($colado) : '';

        return $colado === '' ? null : $colado;
    }

    /** @return string[] */
    private function nomes(mixed $bruto): array
    {
        $texto = is_array($bruto) ? implode("\n", $bruto) : (string) $bruto;
        $partes = preg_split('/[\s,;]+/', $texto) ?: [];

        return array_values(array_filter(array_map('trim', $partes), static fn ($n) => $n !== ''));
    }

    private function cofre(): string
    {
        return rtrim((string) Ambiente::get('CERT_DIR', '/etc/telium/certificados'), '/');
    }

    /** Onde o aplicador deposita cada serviço — só para mostrar na tela. */
    private function destinos(): array
    {
        return [
            'web'      => '/etc/ssl/telium/pabx.crt',
            'asterisk' => '/etc/asterisk/keys/asterisk.crt',
            'turn'     => '/etc/coturn/turn.crt',
            'janus'    => '/etc/janus/certs/janus.crt',
        ];
    }

    /**
     * Aciona o aplicador que roda como root.
     *
     * O disparo é em segundo plano porque recarregar nginx, Asterisk e
     * coturn leva alguns segundos e o resultado de cada serviço fica
     * gravado em certificado_servicos — é de lá que a tela lê.
     *
     * O que ANTES não se conferia era se o disparo era possível. Com o
     * "&" no fim, o shell devolve sucesso assim que bifurca: script
     * ausente, regra de sudo faltando, qualquer coisa — a API respondia
     * "Aplicando nos serviços" e nada acontecia. O serviço ficava
     * "pendente" para sempre e a tela mostrava "Aplicando" sem fim, que
     * é a pior forma de falhar: parece que está trabalhando.
     *
     * @return array{ok:bool, detalhe:string}
     */
    private function dispararAplicador(): array
    {
        $script = '/usr/local/sbin/telium-certificados';

        if (!is_file($script)) {
            return [
                'ok' => false,
                'detalhe' => "O aplicador não está instalado neste servidor ({$script}). "
                           . 'A atribuição ficou registrada; rode o playbook de instalação para publicá-lo.',
            ];
        }

        // "sudo -n -l <comando>" pergunta se a regra existe, sem executar
        // nada e sem pedir senha. É o único jeito de saber a verdade
        // antes de mandar para segundo plano.
        $saida = [];
        $rc = 0;
        @exec('sudo -n -l ' . escapeshellarg($script) . ' 2>&1', $saida, $rc);

        if ($rc !== 0) {
            return [
                'ok' => false,
                'detalhe' => 'O servidor não autoriza o console a acionar o aplicador — falta a regra em '
                           . '/etc/sudoers.d/telium-certificados. A atribuição ficou pendente e o '
                           . 'temporizador do sistema aplica em até um minuto.',
            ];
        }

        // Em primeiro plano, e não em segundo. Recarregar nginx, Asterisk
        // e coturn leva uns segundos — e são exatamente os segundos em
        // que quem clicou quer saber se deu certo. Mandar para trás
        // significava responder "aplicando" e deixar a pessoa
        // descobrir sozinha, olhando o certificado do serviço, que nada
        // tinha acontecido.
        $saida = [];
        $rc = 0;
        @exec('sudo -n ' . escapeshellarg($script) . ' 2>&1', $saida, $rc);

        if ($rc !== 0) {
            return [
                'ok' => false,
                'detalhe' => 'O aplicador terminou com erro: '
                           . (trim(implode(' ', array_slice($saida, -3))) ?: "código {$rc}")
                           . '. Veja o detalhe em cada serviço.',
            ];
        }

        return [
            'ok' => true,
            'detalhe' => 'Aplicado. O resultado de cada serviço está no cartão dele.',
        ];
    }
}
