<?php

/**
 * DbQuery — lightweight query builder over BbPdo.
 *
 * No ORM, no magic. Just sugar over prepared statements.
 * Supports SQLite and MySQL via BbPdo.
 *
 * Usage:
 *   $db = new DbQuery($pdo);
 *   $user = $db->table('users')->where('id', 42)->first();
 *   $db->table('users')->insert(['username' => 'alice', 'email' => 'a@b.com']);
 *   $db->table('users')->where('id', 42)->update(['email' => 'new@b.com']);
 *   $db->table('users')->where('id', 42)->delete();
 */

class DbQuery
{
    private PDO $pdo;
    private string $table = '';
    private array $wheres = [];
    private array $params = [];
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private array $orderBy = [];
    private array $selectCols = ['*'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        $clone->reset();
        return $clone;
    }

    private function reset(): void
    {
        $this->wheres = [];
        $this->params = [];
        $this->limitVal = null;
        $this->offsetVal = null;
        $this->orderBy = [];
        $this->selectCols = ['*'];
    }

    public function select(string ...$cols): self
    {
        $clone = clone $this;
        $clone->selectCols = $cols ?: ['*'];
        return $clone;
    }

    public function where(string $column, $value, string $op = '='): self
    {
        $clone = clone $this;
        $paramName = ':w' . count($clone->params);
        $clone->wheres[] = "{$column} {$op} {$paramName}";
        $clone->params[$paramName] = $value;
        return $clone;
    }

    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this->where('1', 0);
        }
        $clone = clone $this;
        $placeholders = [];
        foreach ($values as $i => $val) {
            $paramName = ':in' . count($clone->params) . '_' . $i;
            $placeholders[] = $paramName;
            $clone->params[$paramName] = $val;
        }
        $clone->wheres[] = "{$column} IN (" . implode(', ', $placeholders) . ")";
        return $clone;
    }

    public function whereRaw(string $sql, array $params = []): self
    {
        $clone = clone $this;
        $clone->wheres[] = $sql;
        foreach ($params as $k => $v) {
            $clone->params[is_int($k) ? ':raw' . count($clone->params) : $k] = $v;
        }
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $clone = clone $this;
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $clone->orderBy[] = "{$column} {$dir}";
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limitVal = $limit;
        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offsetVal = $offset;
        return $clone;
    }

    private function buildWhere(): string
    {
        if (empty($this->wheres)) {
            return '';
        }
        return ' WHERE ' . implode(' AND ', $this->wheres);
    }

    private function buildOrder(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }
        return ' ORDER BY ' . implode(', ', $this->orderBy);
    }

    private function buildLimit(): string
    {
        $sql = '';
        if ($this->limitVal !== null) {
            $sql .= ' LIMIT ' . (int)$this->limitVal;
        }
        if ($this->offsetVal !== null) {
            $sql .= ' OFFSET ' . (int)$this->offsetVal;
        }
        return $sql;
    }

    public function get(): array
    {
        $cols = implode(', ', $this->selectCols);
        $sql = "SELECT {$cols} FROM {$this->table}"
            . $this->buildWhere()
            . $this->buildOrder()
            . $this->buildLimit();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(): ?array
    {
        $this->limitVal = 1;
        $result = $this->get();
        return $result[0] ?? null;
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}" . $this->buildWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return (int) $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        $columns = array_keys($data);
        $placeholders = [];
        $params = [];
        foreach ($columns as $col) {
            $paramName = ':i_' . $col;
            $placeholders[] = $paramName;
            $params[$paramName] = $data[$col];
        }

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertIgnore(array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $columns = array_keys($data);
        $placeholders = [];
        $params = [];
        foreach ($columns as $col) {
            $paramName = ':i_' . $col;
            $placeholders[] = $paramName;
            $params[$paramName] = $data[$col];
        }

        if ($driver === 'mysql') {
            $sql = "INSERT IGNORE INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        } else {
            $sql = "INSERT OR IGNORE INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            return 0;
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $paramName = ':u_' . $col;
            $setParts[] = "{$col} = {$paramName}";
            $params[$paramName] = $val;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $setParts) . $this->buildWhere();
        $params = array_merge($params, $this->params);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}" . $this->buildWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return $stmt->rowCount();
    }

    public function raw(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function rawFirst(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function rawExec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function pluck(string $column): array
    {
        $this->selectCols = [$column];
        $rows = $this->get();
        return array_column($rows, $column);
    }

    public function paginate(int $perPage, int $page = 1): array
    {
        $total = $this->count();
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $this->limitVal = $perPage;
        $this->offsetVal = $offset;
        $items = $this->get();

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }
}
