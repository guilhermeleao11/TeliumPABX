<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Envio do backup para fora do servidor.
 *
 * Backup que só existe na máquina que ele deveria salvar não é backup.
 * O console passou a permitir baixar o arquivo, mas isso depende de
 * alguém lembrar de clicar; este é o caminho automático, usado pelo
 * executor logo depois de cada backup concluído e pelo botão "Enviar
 * agora".
 *
 * O transporte é o curl, que já vem com o PHP da instalação e fala
 * ftp, ftps (TLS explícito) e sftp. Não há extensão nova a instalar.
 */
final class BackupRemoto
{
    /** @return array<string,mixed> a linha única de configuração */
    public static function destino(): array
    {
        $d = Bd::um('SELECT * FROM backup_destino_remoto WHERE id = 1');

        return $d ?? ['id' => 1, 'ativo' => 0, 'tipo' => 'ftps', 'host' => '', 'porta' => 21,
                      'usuario' => '', 'senha' => '', 'caminho' => '/', 'passivo' => 1,
                      'aceitar_cert_invalido' => 0, 'ultimo_estado' => 'nunca'];
    }

    /** O destino está configurado a ponto de valer a pena tentar? */
    public static function utilizavel(array $d): bool
    {
        return (int) ($d['ativo'] ?? 0) === 1
            && trim((string) ($d['host'] ?? '')) !== ''
            && trim((string) ($d['usuario'] ?? '')) !== '';
    }

    /**
     * Manda um arquivo. Sem curl no PHP, diz isso em vez de falhar mudo.
     *
     * @return array{ok:bool, detalhe:string}
     */
    public static function enviar(string $caminho, array $d, bool $apagarDepois = false): array
    {
        if (!is_file($caminho)) {
            return ['ok' => false, 'detalhe' => 'O arquivo do backup não está no servidor.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detalhe' =>
                'O PHP deste servidor está sem a extensão curl, e é ela que fala FTP. '
                . 'Instale php8.4-curl e recarregue o PHP-FPM.'];
        }
        if (!self::utilizavel($d)) {
            return ['ok' => false, 'detalhe' => 'O destino remoto não está configurado.'];
        }

        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            return ['ok' => false, 'detalhe' => 'Não foi possível ler o arquivo do backup.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, self::opcoes($d, basename($caminho), $arquivo, (int) filesize($caminho)));
        if ($apagarDepois) {
            // POSTQUOTE roda DEPOIS da transferência, quando o curl já
            // está dentro do diretório de destino: aí um "DELE nome"
            // solto resolve no lugar certo. Antes disso ele seria
            // resolvido na pasta de entrada do usuário.
            curl_setopt($ch, CURLOPT_POSTQUOTE, ['DELE ' . ($d['_nome'] ?? basename($caminho))]);
        }

        $ok = curl_exec($ch) !== false;
        $erro = curl_error($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($arquivo);

        return $ok
            ? ['ok' => true, 'detalhe' => sprintf('%s enviado para %s.', basename($caminho), self::url($d, ''))]
            : ['ok' => false, 'detalhe' => self::explicar($erro, $codigo)];
    }

    /**
     * Confere o destino sem mandar backup nenhum.
     *
     * Envia um arquivinho de teste e o apaga em seguida: só listar o
     * diretório não prova que dá para ESCREVER nele, que é o que
     * interessa saber antes da madrugada em que o backup roda.
     *
     * @return array{ok:bool, detalhe:string}
     */
    public static function testar(array $d): array
    {
        if (!self::utilizavel($d)) {
            return ['ok' => false, 'detalhe' =>
                'Preencha endereço e usuário, e ligue o destino, antes de testar.'];
        }

        $temp = tempnam(sys_get_temp_dir(), 'telium-teste-');
        if ($temp === false) {
            return ['ok' => false, 'detalhe' => 'Não foi possível criar o arquivo de teste.'];
        }
        file_put_contents($temp, "Telium PABX — teste de destino remoto\n" . date('c') . "\n");

        $nome = 'telium-teste-' . date('Ymd-His') . '.txt';
        // Sobe e apaga na MESMA conexão: não deixa lixo no destino de
        // quem só quis conferir, e prova o que interessa — que dá para
        // escrever ali.
        $r = self::enviar($temp, [...$d, '_nome' => $nome], true);
        @unlink($temp);

        return $r['ok']
            ? ['ok' => true, 'detalhe' => 'Gravação no destino remoto confirmada.']
            : $r;
    }

    /** @return array<int,mixed> */
    private static function opcoes(array $d, string $nome, $arquivo, int $tamanho): array
    {
        $tipo = (string) ($d['tipo'] ?? 'ftps');

        $o = [
            CURLOPT_URL            => self::url($d, (string) ($d['_nome'] ?? $nome)),
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $arquivo,
            CURLOPT_INFILESIZE     => $tamanho,
            CURLOPT_USERPWD        => $d['usuario'] . ':' . $d['senha'],
            CURLOPT_CONNECTTIMEOUT => 15,
            // Backup grande em link ruim leva tempo; o teto existe para
            // não travar o executor para sempre.
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
        ];

        if ($tipo !== 'sftp') {
            $o[CURLOPT_FTP_USE_EPSV] = (int) ($d['passivo'] ?? 1) === 1;
        }
        if ($tipo === 'ftps') {
            // TLS explícito: conecta em claro na 21 e sobe para TLS com
            // AUTH TLS. O implícito (porta 990) seria ftps:// na url.
            $o[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        }
        if ((int) ($d['aceitar_cert_invalido'] ?? 0) === 1) {
            $o[CURLOPT_SSL_VERIFYPEER] = false;
            $o[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        return $o;
    }

    /** O diretório de destino, sempre com barra no começo e no fim. */
    private static function pasta(array $d): string
    {
        $caminho = '/' . trim((string) ($d['caminho'] ?? '/'), '/');

        return rtrim($caminho, '/') . '/';
    }

    private static function url(array $d, string $nome): string
    {
        // ftps com TLS explícito continua sendo ftp:// na url — quem
        // liga o TLS é o CURLOPT_USE_SSL.
        $esquema = ($d['tipo'] ?? 'ftps') === 'sftp' ? 'sftp' : 'ftp';

        return sprintf('%s://%s:%d%s%s',
            $esquema,
            trim((string) $d['host']),
            (int) ($d['porta'] ?? 21),
            self::pasta($d),
            rawurlencode($nome)
        );
    }

    /** A mensagem do curl vira frase que diz o que fazer. */
    private static function explicar(string $erro, int $codigo): string
    {
        $baixo = strtolower($erro);

        return match (true) {
            str_contains($baixo, 'login'),
            str_contains($baixo, 'access denied'),
            str_contains($baixo, '530')         => 'Usuário ou senha recusados pelo servidor remoto.',
            str_contains($baixo, 'denied'),
            str_contains($baixo, '550')         => 'O servidor remoto recusou a gravação. '
                                                 . 'Confira se o caminho existe e se o usuário pode escrever nele.',
            str_contains($baixo, 'resolve')     => 'O endereço do servidor remoto não resolve.',
            str_contains($baixo, 'connect')     => 'Não foi possível conectar. Confira endereço, porta e firewall.',
            str_contains($baixo, 'certificate') => 'O certificado do servidor remoto não foi aceito. '
                                                 . 'Use um certificado válido ou marque a opção de aceitar autoassinado.',
            str_contains($baixo, 'timed out')   => 'O servidor remoto não respondeu no tempo esperado.',
            $erro !== ''                        => "O envio falhou: {$erro}",
            default                             => "O envio falhou (código {$codigo}).",
        };
    }

    /** Guarda o resultado do último envio, que é o que a tela mostra. */
    public static function registrar(bool $ok, string $detalhe): void
    {
        Bd::executar(
            "UPDATE backup_destino_remoto
                SET ultimo_envio = NOW(), ultimo_estado = ?, ultima_saida = ?
              WHERE id = 1",
            [$ok ? 'ok' : 'falha', $detalhe]
        );
    }
}
