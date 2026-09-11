<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * Leitura e geração de certificados X.509.
 *
 * Tudo o que sabe sobre openssl mora aqui; o controller só trata de
 * HTTP e banco. As mensagens são as que o usuário vai ler na tela, então
 * dizem o que fazer, não só o que deu errado.
 */
final class Certificado
{
    public const SERVICOS = ['web', 'asterisk', 'janus'];

    public static function disponivel(): bool
    {
        return function_exists('openssl_x509_parse') && function_exists('openssl_pkey_new');
    }

    /** Nomes amigáveis, para mensagens e para a tela. */
    public static function rotuloServico(string $servico): string
    {
        return match ($servico) {
            'web'      => 'Interface web (nginx)',
            'asterisk' => 'SIP TLS (Asterisk)',
            'janus'    => 'WebRTC (Janus)',
            default    => $servico,
        };
    }

    /**
     * Lê um certificado PEM e devolve o que interessa para a tela.
     *
     * @return array{ok:bool, erro?:string, dados?:array<string,mixed>}
     */
    public static function inspecionar(string $pem): array
    {
        $info = @openssl_x509_parse($pem);
        if ($info === false) {
            return ['ok' => false, 'erro' => 'O arquivo não é um certificado PEM válido. '
                . 'Ele precisa começar com -----BEGIN CERTIFICATE-----.'];
        }

        $sujeito = self::dn($info['subject'] ?? []);
        $emissor = self::dn($info['issuer'] ?? []);

        return ['ok' => true, 'dados' => [
            'cn'           => (string) ($info['subject']['CN'] ?? ''),
            'san'          => implode("\n", self::san($info)),
            'sujeito'      => $sujeito,
            'emissor'      => $emissor,
            'serie'        => (string) ($info['serialNumberHex'] ?? $info['serialNumber'] ?? ''),
            'assinatura'   => (string) ($info['signatureTypeSN'] ?? ''),
            'algoritmo'    => self::algoritmo($pem),
            'impressao'    => self::impressao($pem),
            'autoassinado' => $sujeito !== '' && $sujeito === $emissor ? 1 : 0,
            'valido_de'    => self::data($info['validFrom_time_t'] ?? null),
            'valido_ate'   => self::data($info['validTo_time_t'] ?? null),
        ]];
    }

    /** A chave privada corresponde a este certificado? */
    public static function chaveConfere(string $certPem, string $chavePem, string $senha = ''): array
    {
        $chave = @openssl_pkey_get_private($chavePem, $senha !== '' ? $senha : null);
        if ($chave === false) {
            $protegida = str_contains($chavePem, 'ENCRYPTED');

            return ['ok' => false, 'erro' => $protegida
                ? 'A chave privada está protegida por senha. Informe a senha ou envie a chave sem proteção.'
                : 'A chave privada não pôde ser lida. Ela precisa estar em PEM '
                  . '(-----BEGIN PRIVATE KEY----- ou RSA PRIVATE KEY).'];
        }

        if (!@openssl_x509_check_private_key($certPem, $chave)) {
            return ['ok' => false, 'erro' => 'Esta chave privada não é a do certificado enviado. '
                . 'Confira se os dois arquivos vieram do mesmo pedido de emissão.'];
        }

        return ['ok' => true];
    }

    /**
     * Confere se a cadeia enviada realmente emite o certificado.
     * Uma cadeia errada não impede o uso, mas vale o aviso.
     */
    public static function cadeiaCobre(string $certPem, string $cadeiaPem): array
    {
        $certs = self::separarPem($cadeiaPem);
        if ($certs === []) {
            return ['ok' => false, 'erro' => 'O arquivo de cadeia não contém nenhum certificado.'];
        }

        $folha = @openssl_x509_parse($certPem);
        $emissor = self::dn($folha['issuer'] ?? []);

        foreach ($certs as $c) {
            $info = @openssl_x509_parse($c);
            if ($info !== false && self::dn($info['subject'] ?? []) === $emissor) {
                return ['ok' => true, 'quantidade' => count($certs)];
            }
        }

        return ['ok' => false, 'quantidade' => count($certs),
                'erro' => 'Nenhum certificado da cadeia foi emitido para "' . $emissor . '", '
                        . 'que é quem assinou o seu certificado. Confira se é a cadeia intermediária certa.'];
    }

    /**
     * Gera um par autoassinado para laboratório ou uso interno.
     *
     * @param string[] $nomes  DNS/IP alternativos
     * @return array{ok:bool, erro?:string, cert?:string, chave?:string}
     */
    public static function gerarAutoassinado(string $cn, array $nomes, int $dias, array $dn = []): array
    {
        $conf = self::configComSan($cn, $nomes);
        $chave = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($chave === false) {
            return ['ok' => false, 'erro' => 'Não foi possível gerar a chave privada: ' . self::erroSsl()];
        }

        $csr = @openssl_csr_new(self::sujeito($cn, $dn), $chave, $conf);
        if ($csr === false) {
            return ['ok' => false, 'erro' => 'Não foi possível montar o pedido: ' . self::erroSsl()];
        }

        $cert = @openssl_csr_sign($csr, null, $chave, $dias, $conf, random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            return ['ok' => false, 'erro' => 'Não foi possível assinar o certificado: ' . self::erroSsl()];
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($chave, $chavePem, null, $conf);
        @unlink($conf['config']);

        return ['ok' => true, 'cert' => $certPem, 'chave' => $chavePem];
    }

    /**
     * Gera chave + pedido de assinatura (CSR) para mandar a uma autoridade.
     *
     * @return array{ok:bool, erro?:string, csr?:string, chave?:string}
     */
    public static function gerarCsr(string $cn, array $nomes, array $dn = []): array
    {
        $conf = self::configComSan($cn, $nomes);
        $chave = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($chave === false) {
            return ['ok' => false, 'erro' => 'Não foi possível gerar a chave privada: ' . self::erroSsl()];
        }

        $csr = @openssl_csr_new(self::sujeito($cn, $dn), $chave, $conf);
        if ($csr === false) {
            return ['ok' => false, 'erro' => 'Não foi possível montar o pedido: ' . self::erroSsl()];
        }

        openssl_csr_export($csr, $csrPem);
        openssl_pkey_export($chave, $chavePem, null, $conf);
        @unlink($conf['config']);

        return ['ok' => true, 'csr' => $csrPem, 'chave' => $chavePem];
    }

    /** Quantos dias faltam para vencer (negativo = já venceu). */
    public static function diasParaVencer(?string $validoAte): ?int
    {
        if ($validoAte === null || $validoAte === '') {
            return null;
        }
        $ts = strtotime($validoAte);

        return $ts === false ? null : (int) floor(($ts - time()) / 86400);
    }

    // ------------------------------------------------------------------
    /** @return string[] blocos -----BEGIN CERTIFICATE----- do arquivo */
    public static function separarPem(string $texto): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $texto, $m);

        return $m[0] ?? [];
    }

    private static function san(array $info): array
    {
        $bruto = (string) ($info['extensions']['subjectAltName'] ?? '');
        if ($bruto === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $bruto))));
    }

    private static function dn(array $partes): string
    {
        $saida = [];
        foreach ($partes as $chave => $valor) {
            $saida[] = $chave . '=' . (is_array($valor) ? implode('+', $valor) : $valor);
        }

        return implode(', ', $saida);
    }

    private static function algoritmo(string $pem): string
    {
        $chave = @openssl_pkey_get_public($pem);
        if ($chave === false) {
            return '';
        }
        $d = openssl_pkey_get_details($chave);
        $tipo = match ($d['type'] ?? -1) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_EC  => 'EC',
            OPENSSL_KEYTYPE_DSA => 'DSA',
            default             => 'chave',
        };

        return $tipo === 'EC'
            ? 'EC ' . ($d['ec']['curve_name'] ?? '')
            : trim($tipo . ' ' . ($d['bits'] ?? '') . ' bits');
    }

    private static function impressao(string $pem): string
    {
        $f = @openssl_x509_fingerprint($pem, 'sha256');

        return $f === false ? '' : strtoupper(implode(':', str_split($f, 2)));
    }

    private static function data(mixed $ts): ?string
    {
        return is_int($ts) ? date('Y-m-d H:i:s', $ts) : null;
    }

    private static function sujeito(string $cn, array $dn): array
    {
        return array_filter([
            'countryName'            => substr((string) ($dn['pais'] ?? 'BR'), 0, 2),
            'stateOrProvinceName'    => (string) ($dn['estado'] ?? ''),
            'localityName'           => (string) ($dn['cidade'] ?? ''),
            'organizationName'       => (string) ($dn['organizacao'] ?? ''),
            'organizationalUnitName' => (string) ($dn['unidade'] ?? ''),
            'commonName'             => $cn,
        ], static fn ($v) => $v !== '');
    }

    /**
     * openssl_csr_new só escreve subjectAltName se ele vier de um arquivo
     * de configuração, então montamos um temporário a cada chamada.
     *
     * @param string[] $nomes
     */
    private static function configComSan(string $cn, array $nomes): array
    {
        $lista = [];
        foreach ([$cn, ...$nomes] as $n) {
            $n = trim((string) $n);
            if ($n === '' || in_array($n, $lista, true)) {
                continue;
            }
            $lista[] = $n;
        }

        $linhas = [];
        $iDns = 0;
        $iIp = 0;
        foreach ($lista as $n) {
            if (filter_var($n, FILTER_VALIDATE_IP)) {
                $linhas[] = 'IP.' . (++$iIp) . ' = ' . $n;
            } else {
                $linhas[] = 'DNS.' . (++$iDns) . ' = ' . $n;
            }
        }

        $arquivo = tempnam(sys_get_temp_dir(), 'telium-ssl-');
        file_put_contents($arquivo, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            'req_extensions = ext',
            'x509_extensions = ext',
            'prompt = no',
            '[dn]',
            '[ext]',
            'basicConstraints = CA:FALSE',
            'keyUsage = digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth, clientAuth',
            'subjectAltName = @alt',
            '[alt]',
            ...$linhas,
            '',
        ]));

        return [
            'config' => $arquivo,
            'digest_alg' => 'sha256',
            'req_extensions' => 'ext',
            'x509_extensions' => 'ext',
            'encrypt_key' => false,
        ];
    }

    private static function erroSsl(): string
    {
        $msgs = [];
        while (($e = openssl_error_string()) !== false) {
            $msgs[] = $e;
        }

        return $msgs === [] ? 'erro desconhecido do openssl' : implode('; ', array_slice($msgs, 0, 2));
    }
}
