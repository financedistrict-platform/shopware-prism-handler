<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

class Connection
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|false
     */
    public function fetchAssociative(string $sql, array $params = []): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }
}
