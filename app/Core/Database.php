<?php

declare(strict_types=1);

namespace PlainCMS\Core;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->statement($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $result = $this->statement($sql, $params)->fetch();

        return $result === false ? null : $result;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->statement($sql, $params);

        return $this->pdo()->lastInsertId();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function update(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function delete(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function statement(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($parameter, $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        try {
            $pdo->beginTransaction();
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function executeScript(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    private function pdo(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s',
            $this->config['driver'] ?? 'pgsql',
            $this->config['host'] ?? 'localhost',
            (string) ($this->config['port'] ?? 5432),
            $this->config['database'] ?? '',
        );

        try {
            $this->connection = new PDO(
                $dsn,
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed.', previous: $exception);
        }

        return $this->connection;
    }
}
