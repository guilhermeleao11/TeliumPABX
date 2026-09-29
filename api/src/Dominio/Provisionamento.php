<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

/**
 * A configuração que o telefone busca sozinho ao ligar.
 *
 * Instalar trinta ramais num cliente era configurar trinta aparelhos à
 * mão, digitando senha SIP em teclado de telefone. O cadastro de
 * aparelhos já existia e ninguém lia: faltava o outro lado, o endereço
 * que o aparelho consulta no boot.
 *
 * Cada fabricante tem o próprio formato, e não há padrão: Grandstream
 * fala XML, Yealink e Fanvil falam "chave = valor". O que muda entre
 * eles é a grafia, não a ideia — por isso tudo nasce do mesmo conjunto
 * de dados e cada um só sabe escrever.
 */
final class Provisionamento
{
    /** O MAC como os aparelhos o escrevem: minúsculo e sem separador. */
    public static function normalizarMac(string $mac): string
    {
        return strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
    }

    /**
     * O MAC que está no nome do arquivo pedido.
     *
     * Cada fabricante monta um nome: a Grandstream pede "cfg<mac>.xml",
     * as outras "<mac>.cfg". Tirar só o que não é hexadecimal não
     * resolve — "cfg" tem dois dígitos hexadecimais dentro ("c" e "f"),
     * e o MAC saía deslocado, sempre desconhecido. O prefixo tem de ser
     * retirado antes, explicitamente.
     */
    public static function macDoArquivo(string $arquivo): string
    {
        $nome = pathinfo($arquivo, PATHINFO_FILENAME);

        // Só quando o que sobra é um MAC inteiro: um aparelho que se
        // chame "cfg..." de verdade continua sendo lido como está.
        // Sem o "i" o prefixo só era reconhecido em minúsculo, e há
        // aparelho que pede "CFG<MAC>.XML" — todo ele ficava desconhecido.
        if (preg_match('/^cfg([0-9A-Fa-f]{12})$/i', $nome, $m) === 1) {
            return strtolower($m[1]);
        }

        return self::normalizarMac($nome);
    }

    /** Doze dígitos hexadecimais, nem mais nem menos. */
    public static function macValido(string $mac): bool
    {
        return preg_match('/^[0-9a-f]{12}$/', self::normalizarMac($mac)) === 1;
    }

    /**
     * Os fabricantes que sabemos configurar.
     *
     * @return array<string,string>
     */
    public static function fabricantes(): array
    {
        return [
            'grandstream' => 'Grandstream',
            'yealink'     => 'Yealink',
            'fanvil'      => 'Fanvil',
        ];
    }

    /** O nome do arquivo que cada fabricante procura, para o MAC dado. */
    public static function nomeDoArquivo(string $fabricante, string $mac): string
    {
        $mac = self::normalizarMac($mac);

        return match (strtolower($fabricante)) {
            // A Grandstream busca "cfg<mac>.xml"; as outras, "<mac>.cfg".
            'grandstream' => "cfg{$mac}.xml",
            default       => "{$mac}.cfg",
        };
    }

    /**
     * Monta a configuração do aparelho.
     *
     * @param array<string,mixed> $dispositivo linha de "dispositivos"
     * @param array<string,mixed> $ramal       linha de "ramais"
     */
    public static function gerar(array $dispositivo, array $ramal): string
    {
        $dados = self::dados($dispositivo, $ramal);

        return match (strtolower((string) ($dispositivo['fabricante'] ?? ''))) {
            'grandstream' => self::grandstream($dados),
            'fanvil'      => self::fanvil($dados),
            default       => self::yealink($dados),
        };
    }

    /**
     * O que todo aparelho precisa saber, com nome nosso.
     *
     * @return array<string,string>
     */
    private static function dados(array $d, array $r): array
    {
        // O aparelho fala SIP direto com o Asterisk, e não pelo nginx:
        // o endereço é o do SIP, não o do console.
        $servidor = trim((string) Ambiente::get('SIP_DOMINIO', ''));
        if ($servidor === '') {
            $servidor = Rede::ipPublico() ?: Rede::enderecoLocal();
        }

        return [
            'servidor'  => $servidor,
            'porta'     => (string) Rede::portaSip(),
            'ramal'     => (string) $r['numero'],
            'senha'     => (string) $r['senha_sip'],
            'nome'      => (string) ($r['nome'] ?? $r['numero']),
            'fuso'      => (string) (Bd::valor('SELECT fuso FROM empresa WHERE id = 1') ?: 'America/Sao_Paulo'),
            'ntp'       => 'a.ntp.br',
            'vm'        => '*97',
            'empresa'   => (string) (Bd::valor('SELECT nome FROM empresa WHERE id = 1') ?: 'Telium PABX'),
            'mac'       => self::normalizarMac((string) $d['mac']),
        ];
    }

    /** Grandstream: XML de pares P-code, que é como ela lê. */
    private static function grandstream(array $x): string
    {
        // Os números são os "P values" da Grandstream. Sem comentário ao
        // lado deles, nenhum humano consegue manter isto depois.
        $p = [
            '271' => '1',                    // conta 1 ativa
            '47'  => $x['servidor'],         // servidor SIP
            '35'  => $x['ramal'],            // ID do usuário SIP
            '36'  => $x['ramal'],            // ID de autenticação
            '34'  => $x['senha'],            // senha
            '3'   => $x['nome'],             // nome exibido
            '270' => $x['ramal'],            // nome da conta
            '40'  => $x['vm'],               // correio de voz
            '64'  => $x['fuso'],             // fuso
            '30'  => $x['ntp'],              // servidor NTP
            '2'   => 'admin',                // usuário do menu web
        ];

        $linhas = '';
        foreach ($p as $codigo => $valor) {
            $linhas .= sprintf("    <P%s>%s</P%s>\n", $codigo, htmlspecialchars($valor, ENT_XML1), $codigo);
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<!-- Gerado pelo Telium PABX — NÃO EDITE À MÃO -->\n"
             . "<!-- Aparelho {$x['mac']} · ramal {$x['ramal']} · " . date('d/m/Y H:i:s') . " -->\n"
             . "<gs_provision version=\"1\">\n  <config version=\"1\">\n{$linhas}  </config>\n</gs_provision>\n";
    }

    /** Yealink: "chave = valor", uma por linha. */
    private static function yealink(array $x): string
    {
        return self::chaveValor($x, [
            'account.1.enable'              => '1',
            'account.1.label'               => $x['ramal'],
            'account.1.display_name'        => $x['nome'],
            'account.1.auth_name'           => $x['ramal'],
            'account.1.user_name'           => $x['ramal'],
            'account.1.password'            => $x['senha'],
            'account.1.sip_server.1.address' => $x['servidor'],
            'account.1.sip_server.1.port'   => $x['porta'],
            'account.1.voice_mail.number.1' => $x['vm'],
            'local_time.time_zone_name'     => $x['fuso'],
            'local_time.ntp_server1'        => $x['ntp'],
        ]);
    }

    /** Fanvil: também "chave = valor", com outra grafia. */
    private static function fanvil(array $x): string
    {
        return self::chaveValor($x, [
            'sip_account_1_enable'      => '1',
            'sip_account_1_display_name' => $x['nome'],
            'sip_account_1_user_name'   => $x['ramal'],
            'sip_account_1_auth_name'   => $x['ramal'],
            'sip_account_1_password'    => $x['senha'],
            'sip_account_1_sip_server_1' => $x['servidor'],
            'sip_account_1_sip_port_1'  => $x['porta'],
            'sip_account_1_voice_mail'  => $x['vm'],
            'time_zone_name'            => $x['fuso'],
            'ntp_server1'               => $x['ntp'],
        ]);
    }

    /** @param array<string,string> $pares */
    private static function chaveValor(array $x, array $pares): string
    {
        $txt = "#!version:1.0.0.1\n"
             . "# Gerado pelo Telium PABX — NÃO EDITE À MÃO\n"
             . "# Aparelho {$x['mac']} · ramal {$x['ramal']} · " . date('d/m/Y H:i:s') . "\n\n";

        foreach ($pares as $chave => $valor) {
            // Quebra de linha num valor vira linha nova de configuração:
            // o nome do ramal vem de cadastro, e cadastro é digitado.
            $txt .= sprintf("%s = %s\n", $chave, str_replace(["\r", "\n"], ' ', $valor));
        }

        return $txt;
    }

    /** O segredo que vai no caminho da URL. */
    public static function segredo(): string
    {
        return trim((string) Bd::valor("SELECT valor FROM sistema WHERE chave = 'prov_segredo'"));
    }

    /** Só da rede interna? É o padrão, e é o que se deve manter. */
    public static function soRedeLocal(): bool
    {
        return trim((string) Bd::valor("SELECT valor FROM sistema WHERE chave = 'prov_so_rede_local'")) !== '0';
    }

    /**
     * O endereço está numa das faixas que a central chama de internas?
     *
     * Aparelho que se provisiona pela internet é a senha SIP do ramal
     * viajando para quem pedir.
     */
    public static function daRedeLocal(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach (Rede::redesLocais() as $faixa) {
            if (self::dentroDaFaixa($ip, $faixa)) {
                return true;
            }
        }

        // A própria máquina sempre pode: é o caso do técnico testando
        // pelo console, e de qualquer proxy local na frente.
        return str_starts_with($ip, '127.');
    }

    private static function dentroDaFaixa(string $ip, string $faixa): bool
    {
        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }

        $partes = explode('/', $faixa, 2);
        $base = ip2long($partes[0]);
        if ($base === false) {
            return false;
        }

        if (!isset($partes[1])) {
            return $n === $base;
        }

        // Aceita /24 e /255.255.255.0, como o Asterisk.
        $bits = str_contains($partes[1], '.')
            ? self::bitsDaMascara($partes[1])
            : (int) $partes[1];

        if ($bits < 0 || $bits > 32) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mascara = -1 << (32 - $bits);

        return ($n & $mascara) === ($base & $mascara);
    }

    private static function bitsDaMascara(string $mascara): int
    {
        $n = ip2long($mascara);

        return $n === false ? -1 : substr_count(decbin($n & 0xFFFFFFFF), '1');
    }

    /** Guarda quem pediu o quê — inclusive quem pediu e não devia. */
    public static function registrar(
        ?string $mac,
        string $ip,
        string $agente,
        string $resultado,
        ?string $ramal = null
    ): void {
        Bd::executar(
            'INSERT INTO provisionamento_log (mac, ramal, ip, agente, resultado) VALUES (?, ?, ?, ?, ?)',
            [$mac, $ramal, substr($ip, 0, 45), substr($agente, 0, 160) ?: null, $resultado]
        );
    }

    /* -----------------------------------------------------------------
       Linphone por QR Code
       -----------------------------------------------------------------
       O celular não tem MAC cadastrado nem opção 66: o QR leva uma URL
       que o aplicativo busca uma vez só. Formato conferido no código do
       próprio Linphone (linphone-android 5.3/6.x e liblinphone):

       - O QR é uma URL http(s) simples. O 6.x também aceita
         "linphone-config:https://…", o 5.x não — por isso a URL pura.
       - O XML é o lpconfig: <config><section><entry>. Sem
         overwrite="true" o aplicativo mantém o valor que já tinha.
       - Não há chave de transporte: ele vai na URI do reg_proxy.
       - misc/transient_provisioning=1 faz o aplicativo esquecer a URL
         depois de aplicá-la. Sem isso ele a buscaria a cada abertura,
         e um token de uso único viraria aviso de erro toda vez.
       ----------------------------------------------------------------- */

    /** Validade do QR. Tempo de abrir o aplicativo e apontar a câmera. */
    public const LINPHONE_VALIDADE_SEG = 600;

    /** 24 bytes de random_bytes: 48 hexadecimais, 192 bits. */
    public static function novoToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** O formato é conferido antes de ir ao banco. */
    public static function tokenValido(string $token): bool
    {
        return preg_match('/^[0-9a-f]{48}$/', $token) === 1;
    }

    /** O banco guarda só isto — nunca o token. */
    public static function hashDoToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Cifra a senha digitada com uma chave que só o token abre.
     *
     * O token não está no banco, então quem ler a tabela não abre a
     * senha — e a do cadastro, quando é ela que vale, nem passa por aqui.
     */
    public static function cifrarSenha(string $senha, string $token): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cifra = openssl_encrypt($senha, 'aes-256-gcm', self::chaveDoToken($token),
                                 OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifra === false) {
            throw new \RuntimeException('Não foi possível cifrar a senha do convite.');
        }

        return $iv . $tag . $cifra;
    }

    public static function decifrarSenha(string $bruto, string $token): ?string
    {
        if (strlen($bruto) < 29) {
            return null;
        }

        $senha = openssl_decrypt(substr($bruto, 28), 'aes-256-gcm', self::chaveDoToken($token),
                                 OPENSSL_RAW_DATA, substr($bruto, 0, 12), substr($bruto, 12, 16));

        return $senha === false ? null : $senha;
    }

    private static function chaveDoToken(string $token): string
    {
        return hash_hkdf('sha256', $token, 32, 'telium-prov-linphone');
    }

    /** Servidor, porta e transporte que o Linphone vai usar. */
    public static function destinoLinphone(): array
    {
        $servidor = trim((string) Ambiente::get('SIP_DOMINIO', ''));
        if ($servidor === '') {
            $servidor = Rede::ipPublico() ?: Rede::enderecoLocal();
        }

        return ['servidor' => $servidor, 'porta' => Rede::portaSip(), 'transporte' => 'udp'];
    }

    /**
     * O XML de configuração do Linphone para um ramal.
     *
     * Só chaves que a liblinphone lê de fato (account-params.cpp e
     * auth-info.cpp). O realm fica de fora de propósito: sem ele a
     * credencial serve a qualquer realm que o Asterisk anunciar.
     *
     * @param array<string,mixed> $ramal linha de "ramais"
     */
    public static function linphone(array $ramal, string $senha, ?array $destino = null): string
    {
        $d = $destino ?? self::destinoLinphone();
        $numero = (string) $ramal['numero'];
        $servidor = (string) $d['servidor'];

        // O nome vai entre aspas no reg_identity: aspas e barra no nome
        // do ramal quebrariam o endereço inteiro.
        $nome = str_replace(['\\', '"', "\r", "\n"], ['', '', ' ', ' '],
                            (string) ($ramal['nome'] ?? $numero));

        $secoes = [
            'misc' => [
                // Aplica uma vez e esquece a URL. O token é de uso único.
                'transient_provisioning' => '1',
            ],
            'proxy_0' => [
                'reg_identity'     => "\"{$nome}\" <sip:{$numero}@{$servidor}>",
                'reg_proxy'        => "<sip:{$servidor}:{$d['porta']};transport={$d['transporte']}>",
                'reg_expires'      => '3600',
                'reg_sendregister' => '1',
                'publish'          => '0',
            ],
            'auth_info_0' => [
                'username' => $numero,
                'userid'   => $numero,
                'passwd'   => $senha,
                'domain'   => $servidor,
            ],
        ];

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<config xmlns=\"http://www.linphone.org/xsds/lpconfig.xsd\" "
             . "xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" "
             . "xsi:schemaLocation=\"http://www.linphone.org/xsds/lpconfig.xsd lpconfig.xsd\">\n";

        foreach ($secoes as $secao => $entradas) {
            $xml .= "  <section name=\"{$secao}\">\n";
            foreach ($entradas as $chave => $valor) {
                $xml .= sprintf("    <entry name=\"%s\" overwrite=\"true\">%s</entry>\n",
                                $chave, htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            $xml .= "  </section>\n";
        }

        return $xml . "</config>\n";
    }

    /** Some com o que venceu: senha cifrada primeiro, a linha depois. */
    public static function limparConvites(): void
    {
        Bd::executar(
            'UPDATE provisionamento_convite SET senha_cifrada = NULL
              WHERE senha_cifrada IS NOT NULL AND (expira_em <= NOW() OR usado_em IS NOT NULL)'
        );
        // Um dia de folga: é o que ainda responde "410, já usado" em vez
        // de "404, nunca existiu" para quem escaneou de novo.
        Bd::executar('DELETE FROM provisionamento_convite WHERE expira_em < NOW() - INTERVAL 1 DAY');
    }
}
