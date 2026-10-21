<?php
declare(strict_types=1);

namespace Telium\Suporte;

use PDO;

final class Bd
{
    private static ?PDO $pdo = null;

    public static function conexao(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Ambiente::get('DB_HOST', '127.0.0.1'),
            Ambiente::get('DB_PORT', '3306'),
            Ambiente::get('DB_NAME', 'telium_pabx'),
        );

        self::$pdo = new PDO($dsn, Ambiente::get('DB_USER'), Ambiente::get('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00'",
        ]);

        return self::$pdo;
    }

    /**
     * Esquece a conexão. Para o serviço que fica de pé por dias: o
     * MariaDB derruba conexão parada, e a próxima consulta reabre.
     */
    public static function desconectar(): void
    {
        self::$pdo = null;
    }

    /**
     * Roda $trabalho numa transação e devolve o que ele devolver.
     *
     * Dentro de outra transação, vira um SAVEPOINT: o erro desfaz só o
     * pedaço, e quem abriu a de fora decide o resto. É o que deixa a
     * prévia de uma importação rodar o mesmo cadastro das telas — que
     * abre a sua própria transação — e desfazer tudo no fim.
     *
     * @template T
     * @param callable(): T $trabalho
     * @return T
     */
    public static function transacao(callable $trabalho): mixed
    {
        static $nivel = 0;
        $pdo = self::conexao();

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                $resultado = $trabalho();
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            return $resultado;
        }

        $ponto = 'telium_sp' . (++$nivel);
        $pdo->exec("SAVEPOINT {$ponto}");
        try {
            $resultado = $trabalho();
            $pdo->exec("RELEASE SAVEPOINT {$ponto}");
        } catch (\Throwable $e) {
            // Num deadlock o MariaDB já desfez a transação inteira e o
            // SAVEPOINT não existe mais: o erro que importa é o de cima.
            try {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$ponto}");
            } catch (\Throwable) {
            }
            throw $e;
        } finally {
            $nivel--;
        }

        return $resultado;
    }

    /** @return array<int,array<string,mixed>> */
    public static function todos(string $sql, array $params = []): array
    {
        $st = self::conexao()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function um(string $sql, array $params = []): ?array
    {
        $st = self::conexao()->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: null;
    }

    public static function executar(string $sql, array $params = []): int
    {
        $st = self::conexao()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $st = self::conexao()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
