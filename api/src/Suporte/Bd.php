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
