<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

final class Sessao
{
    /** Cria a sessão e devolve o token em claro (só o hash vai para o banco). */
    public static function criar(int $usuarioId, ?string $ip, ?string $agente): string
    {
        $token = bin2hex(random_bytes(32));
        $minutos = Ambiente::int('SESSAO_MINUTOS', 30);

        Bd::executar(
            'INSERT INTO sessoes (id, usuario_id, ip, user_agent, ultima_atividade, expira_em)
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [hash('sha256', $token), $usuarioId, $ip, substr((string) $agente, 0, 255), $minutos]
        );

        return $token;
    }

    /** Valida o token, renova a janela de inatividade e devolve o usuário. */
    public static function usuarioDoToken(string $token): ?array
    {
        $usuario = Bd::um(
            'SELECT u.*, p.chave AS perfil_chave, p.nome AS perfil_nome, p.cor AS perfil_cor
               FROM sessoes s
               JOIN usuarios u ON u.id = s.usuario_id
               JOIN perfis   p ON p.id = u.perfil_id
              WHERE s.id = ? AND s.expira_em > NOW() AND u.status = ?',
            [hash('sha256', $token), 'ativo']
        );

        if ($usuario === null) {
            return null;
        }

        $minutos = Ambiente::int('SESSAO_MINUTOS', 30);
        Bd::executar(
            'UPDATE sessoes
                SET ultima_atividade = NOW(), expira_em = DATE_ADD(NOW(), INTERVAL ? MINUTE)
              WHERE id = ?',
            [$minutos, hash('sha256', $token)]
        );

        return $usuario;
    }

    public static function encerrar(string $token): void
    {
        Bd::executar('DELETE FROM sessoes WHERE id = ?', [hash('sha256', $token)]);
    }

    public static function encerrarOutras(int $usuarioId, string $tokenAtual): int
    {
        return Bd::executar(
            'DELETE FROM sessoes WHERE usuario_id = ? AND id <> ?',
            [$usuarioId, hash('sha256', $tokenAtual)]
        );
    }

    public static function limparExpiradas(): int
    {
        return Bd::executar('DELETE FROM sessoes WHERE expira_em < NOW()');
    }
}
