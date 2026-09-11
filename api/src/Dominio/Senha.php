<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * Hash de senha em PBKDF2-SHA256, formato:
 *   pbkdf2_sha256$<iteracoes>$<salt_b64>$<hash_b64>
 * Comparação em tempo constante.
 */
final class Senha
{
    private const ALGORITMO = 'sha256';
    private const ITERACOES = 390000;

    /** Política mínima de senha. O front espelha estas mesmas regras. */
    public const MINIMO = 8;

    /**
     * Hash de mentira, para conferir contra quando o usuário não existe.
     *
     * Sem ele, um login inexistente respondia na hora e um existente
     * esperava o PBKDF2 — a diferença de tempo era uma lista de contas
     * válidas para quem medisse. O segredo dentro dele não importa: o
     * que importa é gastar o mesmo trabalho.
     */
    public const HASH_FALSO = 'pbkdf2_sha256$390000$AAAAAAAAAAAAAAAAAAAAAA==$'
                            . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /**
     * Diz o que falta para a senha ser aceita.
     *
     * Devolve a lista de exigências não atendidas, em vez de um sim/não,
     * para a interface poder mostrar exatamente o que corrigir.
     *
     * @return string[] vazio quando a senha está boa
     */
    public static function validar(string $senha): array
    {
        $faltas = [];

        if (mb_strlen($senha) < self::MINIMO) {
            $faltas[] = 'pelo menos ' . self::MINIMO . ' caracteres';
        }
        if (preg_match('/\d/', $senha) !== 1) {
            $faltas[] = 'pelo menos um número';
        }
        if (preg_match('/[^\p{L}\p{N}]/u', $senha) !== 1) {
            $faltas[] = 'pelo menos um símbolo (por exemplo @ # ! _ -)';
        }

        return $faltas;
    }

    /** Mensagem pronta para devolver ao cliente. */
    public static function mensagemDeFalta(array $faltas): string
    {
        return count($faltas) === 1
            ? 'A senha precisa de ' . $faltas[0] . '.'
            : 'A senha precisa de: ' . implode('; ', $faltas) . '.';
    }

    public static function criar(string $senha, int $iteracoes = self::ITERACOES): string
    {
        $salt = random_bytes(16);
        $hash = hash_pbkdf2(self::ALGORITMO, $senha, $salt, $iteracoes, 32, true);

        return sprintf('pbkdf2_sha256$%d$%s$%s', $iteracoes, base64_encode($salt), base64_encode($hash));
    }

    public static function verificar(string $senha, string $armazenado): bool
    {
        $partes = explode('$', $armazenado);
        if (count($partes) !== 4 || $partes[0] !== 'pbkdf2_sha256') {
            return false;
        }

        [, $iteracoes, $salt64, $hash64] = $partes;
        $salt = base64_decode($salt64, true);
        $esperado = base64_decode($hash64, true);
        if ($salt === false || $esperado === false) {
            return false;
        }

        $calculado = hash_pbkdf2(self::ALGORITMO, $senha, $salt, (int) $iteracoes, strlen($esperado), true);

        return hash_equals($esperado, $calculado);
    }

    /** Indica que o hash usa parâmetros antigos e vale regravar no próximo login. */
    public static function precisaAtualizar(string $armazenado): bool
    {
        $partes = explode('$', $armazenado);
        return count($partes) !== 4
            || $partes[0] !== 'pbkdf2_sha256'
            || (int) $partes[1] < self::ITERACOES;
    }
}
