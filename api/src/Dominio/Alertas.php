<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

/**
 * Avisos do sistema por e-mail.
 *
 * A tela de Notificações dizia que mandava aviso de backup com falha e de
 * certificado perto de vencer, e nada mandava. Quem descobria era o
 * cliente: no dia em que precisou do backup, ou quando o navegador passou
 * a recusar o console.
 *
 * Roda pelo comando "telium alertas", que o executor de backup chama de
 * hora em hora e logo depois de um backup que falha. Cada tipo de aviso
 * sai no máximo uma vez por dia (o de backup, uma vez por backup), para
 * a caixa de quem cuida da central não virar spam.
 */
final class Alertas
{
    private const CERT_DIAS = 15;
    private const DISCO_LIVRE_MIN = 10;   // % livre no disco das gravações

    /** @return list<string> os avisos enviados nesta rodada */
    public static function verificar(): array
    {
        $smtp = Bd::um('SELECT * FROM smtp WHERE id = 1') ?? [];
        $para = self::destinatarios((string) ($smtp['alertas_para'] ?? ''));
        if ((int) ($smtp['ativo'] ?? 0) !== 1 || $para === []) {
            return [];
        }

        $enviados = [];
        foreach (self::pendentes() as [$chave, $umaVezPor, $assunto, $texto]) {
            if (!self::podeMandar($chave, $umaVezPor)) {
                continue;
            }
            [$ok] = self::enviar($smtp, $para, $assunto, $texto);
            if ($ok) {
                self::marcar($chave, $umaVezPor);
                $enviados[] = $assunto;
            }
        }

        return $enviados;
    }

    /** @return list<string> e-mails válidos de uma lista separada por vírgula ou espaço */
    public static function destinatarios(string $texto): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', $texto) ?: []),
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string}> chave, marca de repetição, assunto, texto */
    private static function pendentes(): array
    {
        $empresa = (string) (Bd::valor('SELECT nome FROM empresa WHERE id = 1') ?: 'Telium PABX');
        $avisos = [];

        // Backup que falhou: um aviso por backup.
        $falha = Bd::um("SELECT id, criado_em, saida FROM backups WHERE estado = 'falha'
                          AND criado_em > NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1");
        if ($falha !== null) {
            $avisos[] = ['backup_falha', (string) $falha['id'], "[{$empresa}] O backup da central falhou",
                "O backup iniciado em {$falha['criado_em']} terminou com falha.\n\n"
                . trim((string) $falha['saida']) . "\n\n"
                . "Veja em Administrador › Backup & Restauração."];
        }

        // Rotina ligada e nenhum backup concluído há mais de 26 horas.
        $rotinas = (int) Bd::valor("SELECT COUNT(*) FROM backup_rotinas WHERE ativo = 1 AND periodicidade <> 'manual'");
        $ultimo = Bd::valor("SELECT MAX(concluido_em) FROM backups WHERE estado = 'concluido'");
        if ($rotinas > 0 && ($ultimo === null || strtotime((string) $ultimo) < time() - 26 * 3600)) {
            $avisos[] = ['backup_atrasado', date('Y-m-d'), "[{$empresa}] Nenhum backup concluído há mais de 26 horas",
                'Há rotina de backup ligada, e o último backup concluído é de '
                . ($ultimo ?? 'nunca') . ".\n\nVeja em Administrador › Backup & Restauração."];
        }

        // Certificado do console perto de vencer (o que o nginx apresenta).
        $vence = self::vencimentoDoCertificado();
        if ($vence !== null && $vence < time() + self::CERT_DIAS * 86400) {
            $dias = (int) floor(($vence - time()) / 86400);
            $avisos[] = ['certificado', date('Y-m-d'), $dias < 0
                    ? "[{$empresa}] O certificado do console venceu"
                    : "[{$empresa}] O certificado do console vence em {$dias} dia(s)",
                'O certificado HTTPS da central vence em ' . date('d/m/Y', $vence) . '. '
                . "Depois disso o navegador recusa o console e o softphone.\n\n"
                . 'Veja em Administrador › Certificados.'];
        }

        // Disco quase cheio: com ele cheio, gravação e correio de voz param.
        $dir = (string) Ambiente::get('GRAVACOES_DIR', '/var/spool/asterisk/monitor');
        $total = (float) @disk_total_space($dir);
        $livre = (float) @disk_free_space($dir);
        if ($total > 0 && $livre / $total * 100 < self::DISCO_LIVRE_MIN) {
            $avisos[] = ['disco', date('Y-m-d'), "[{$empresa}] O disco da central está quase cheio",
                sprintf("Restam %.1f GB livres de %.1f GB (%d%%).\n\n", $livre / 1073741824, $total / 1073741824,
                        (int) round($livre / $total * 100))
                . 'Com o disco cheio, a central para de gravar chamadas e recados. Defina um prazo '
                . 'de guarda em Aplicações › Gravação de Chamadas ou reduza a retenção do backup.'];
        }

        return $avisos;
    }

    /** O fim da validade do certificado que o nginx da central apresenta, ou null. */
    private static function vencimentoDoCertificado(): ?int
    {
        $nome = (string) (Ambiente::get('SIP_DOMINIO', '') ?: 'localhost');
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'peer_name' => $nome, 'SNI_enabled' => true,
        ]]);
        $s = @stream_socket_client('ssl://127.0.0.1:443', $erro, $msg, 3, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            return null;
        }
        $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
        fclose($s);
        $info = $cert ? openssl_x509_parse($cert) : false;

        return is_array($info) && isset($info['validTo_time_t']) ? (int) $info['validTo_time_t'] : null;
    }

    private static function podeMandar(string $chave, string $marca): bool
    {
        return Bd::valor('SELECT valor FROM sistema WHERE chave = ?', ["alerta_{$chave}"]) !== $marca;
    }

    private static function marcar(string $chave, string $marca): void
    {
        Bd::executar(
            'INSERT INTO sistema (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            ["alerta_{$chave}", $marca]
        );
    }

    /**
     * @param array<string,mixed> $smtp
     * @param list<string> $para
     * @return array{0:bool, 1:string}
     */
    private static function enviar(array $smtp, array $para, string $assunto, string $texto): array
    {
        $config = rtrim((string) Ambiente::get('TELIUM_ETC', '/etc/telium'), '/') . '/msmtprc';
        if (!is_readable($config)) {
            return [false, 'configuração de envio não publicada'];
        }
        $de = (string) $smtp['remetente'];
        $nome = (string) ($smtp['nome_remetente'] ?: 'Telium PABX');
        $assunto = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
        $mensagem = "From: {$nome} <{$de}>\r\nTo: " . implode(', ', $para) . "\r\nSubject: {$assunto}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . str_replace("\n", "\r\n", $texto) . "\r\n\r\n-- \r\nAviso automático da central Telium PABX.\r\n";

        $p = @proc_open(['msmtp', '--file=' . $config, '-t'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canos);
        if (!is_resource($p)) {
            return [false, 'msmtp não instalado'];
        }
        fwrite($canos[0], $mensagem);
        fclose($canos[0]);
        $saida = trim((string) stream_get_contents($canos[1]) . stream_get_contents($canos[2]));
        fclose($canos[1]);
        fclose($canos[2]);

        return [proc_close($p) === 0, $saida];
    }
}
