<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Envio de e-mail pela central.
 *
 * Quem manda e-mail de verdade é o msmtp, um cliente de relay. A
 * central não roda servidor de e-mail: servidor próprio em PABX de
 * cliente acaba com a caixa de entrada bloqueada por reputação de IP
 * em poucos dias, e vira máquina de spam se a senha vazar.
 */
final class Email
{
    /** GET /api/email */
    public function obter(Request $req, Response $res): Response
    {
        $c = Bd::um('SELECT * FROM smtp WHERE id = 1') ?? [];
        unset($c['senha']);                       // nunca volta para a tela

        return Resposta::json($res, [
            'config'    => $c,
            'tem_senha' => (string) (Bd::valor('SELECT senha FROM smtp WHERE id = 1') ?? '') !== '',
            'publicado' => $this->configPublicada(),
        ]);
    }

    /** PUT /api/email */
    public function salvar(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();

        $servidor = trim((string) ($corpo['servidor'] ?? ''));
        $remetente = trim((string) ($corpo['remetente'] ?? ''));
        $ativo = (int) (bool) ($corpo['ativo'] ?? 0);

        if ($ativo === 1) {
            if ($servidor === '') {
                return Resposta::erro($res, 'Informe o servidor de saída', 422, ['campo' => 'servidor']);
            }
            if (!filter_var($remetente, FILTER_VALIDATE_EMAIL)) {
                return Resposta::erro(
                    $res,
                    'O remetente precisa ser um e-mail válido — é ele que vai no "De:".',
                    422,
                    ['campo' => 'remetente']
                );
            }
        }

        $seguranca = in_array($corpo['seguranca'] ?? '', ['starttls', 'tls', 'nenhuma'], true)
            ? $corpo['seguranca'] : 'starttls';

        // Senha em branco quer dizer "mantém a que está": a tela nunca
        // recebe a senha de volta, então mandá-la vazia é o normal.
        $senha = (string) ($corpo['senha'] ?? '');
        $mudaSenha = $senha !== '';

        Bd::executar(
            'UPDATE smtp SET ativo = ?, servidor = ?, porta = ?, seguranca = ?,
                    usuario = ?, remetente = ?, nome_remetente = ?'
            . ($mudaSenha ? ', senha = ?' : '') . '
              WHERE id = 1',
            $mudaSenha
                ? [$ativo, $servidor, max(1, (int) ($corpo['porta'] ?? 587)), $seguranca,
                   trim((string) ($corpo['usuario'] ?? '')), $remetente,
                   trim((string) ($corpo['nome_remetente'] ?? '')), $senha]
                : [$ativo, $servidor, max(1, (int) ($corpo['porta'] ?? 587)), $seguranca,
                   trim((string) ($corpo['usuario'] ?? '')), $remetente,
                   trim((string) ($corpo['nome_remetente'] ?? ''))]
        );

        $publicou = $this->publicar();
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'cfg.notificacoes',
                             $servidor, ['ativo' => $ativo]);

        return Resposta::json($res, [
            'ok' => true,
            'publicado' => $publicou,
            'detalhe' => $publicou
                ? 'Configuração publicada. O Asterisk já usa este servidor para mandar os recados.'
                : 'Gravado no banco, mas não foi possível publicar a configuração no servidor. '
                . 'Confira se o playbook do Ansible já rodou nesta máquina.',
        ]);
    }

    /**
     * POST /api/email/testar — manda uma mensagem de verdade.
     *
     * Sem isto, "configurei o e-mail" só é verdade na primeira vez que
     * alguém deixa um recado — e a descoberta de que não funciona vem
     * do cliente, não da tela.
     */
    public function testar(Request $req, Response $res): Response
    {
        $para = trim((string) (((array) $req->getParsedBody())['para'] ?? ''));
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return Resposta::erro($res, 'Informe um e-mail de destino válido', 422, ['campo' => 'para']);
        }

        $c = Bd::um('SELECT * FROM smtp WHERE id = 1');
        if ($c === null || (int) $c['ativo'] !== 1) {
            return Resposta::erro($res, 'Ligue o envio de e-mail antes de testar', 409);
        }

        // Publicar ANTES de testar, e conferir que publicou. Sem esta
        // conferência, quem trocasse o servidor e clicasse em testar
        // estaria testando a configuração ANTERIOR, que continua no
        // disco — e um teste que passa sobre o servidor errado é pior
        // do que um teste que falha.
        if (!$this->publicar()) {
            return Resposta::erro(
                $res,
                'Não foi possível publicar a configuração de envio no servidor. '
                . 'O teste mediria a configuração anterior, então ele não foi feito. '
                . 'Rode o playbook de instalação para publicar /usr/local/sbin/telium-smtp '
                . 'e a regra de sudo dele.',
                502
            );
        }

        $de = (string) $c['remetente'];
        $nome = (string) $c['nome_remetente'] ?: 'Telium PABX';
        $empresa = (string) (Bd::valor('SELECT nome FROM empresa WHERE id = 1') ?: 'Telium PABX');

        $mensagem = "From: {$nome} <{$de}>\r\n"
            . "To: {$para}\r\n"
            . "Subject: Teste de e-mail da central {$empresa}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Se você está lendo isto, a central consegue mandar e-mail.\r\n\r\n"
            . "É por este caminho que vão as cópias do correio de voz e os avisos\r\n"
            . "do sistema.\r\n\r\n"
            . "Enviado em " . date('d/m/Y \à\s H:i:s') . " por {$empresa}.\r\n";

        [$ok, $saida] = $this->enviar($mensagem);

        Bd::executar(
            'UPDATE smtp SET testado_em = NOW(), teste_ok = ?, teste_saida = ? WHERE id = 1',
            [$ok ? 1 : 0, mb_substr($saida, 0, 4000)]
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'testar', 'cfg.notificacoes', $para,
                             ['ok' => $ok]);

        return Resposta::json($res, [
            'enviado' => $ok,
            'detalhe' => $ok
                ? "Mensagem entregue ao servidor de saída. Confira a caixa de {$para} — "
                . 'e o spam, na primeira vez.'
                : 'O servidor de saída recusou: ' . ($saida !== '' ? $saida : 'sem detalhe'),
        ], $ok ? 200 : 502);
    }

    // ---------------------------------------------------------------
    /** Pede ao script com sudo que reescreva a configuração do msmtp. */
    private function publicar(): bool
    {
        $script = '/usr/local/sbin/telium-smtp';
        if (!is_file($script)) {
            return false;
        }

        $saida = [];
        $rc = 0;
        @exec('sudo -n ' . escapeshellarg($script) . ' 2>&1', $saida, $rc);

        return $rc === 0;
    }

    /** @return array{0:bool, 1:string} */
    private function enviar(string $mensagem): array
    {
        $config = rtrim((string) Ambiente::get('TELIUM_ETC', '/etc/telium'), '/') . '/msmtprc';
        if (!is_readable($config)) {
            return [false, "A configuração de envio não está publicada em {$config}."];
        }

        $descritores = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processo = @proc_open(
            ['msmtp', '--file=' . $config, '-t'],
            $descritores,
            $canos
        );

        if (!is_resource($processo)) {
            return [false, 'O msmtp não está instalado nesta máquina.'];
        }

        fwrite($canos[0], $mensagem);
        fclose($canos[0]);
        $saida = trim((string) stream_get_contents($canos[1]) . stream_get_contents($canos[2]));
        fclose($canos[1]);
        fclose($canos[2]);

        return [proc_close($processo) === 0, $saida];
    }

    private function configPublicada(): bool
    {
        $config = rtrim((string) Ambiente::get('TELIUM_ETC', '/etc/telium'), '/') . '/msmtprc';

        return is_file($config);
    }
}
