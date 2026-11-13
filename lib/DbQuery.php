<?php

/**
 * DbQuery — lightweight query builder over BbPdo.
 *
 * No ORM, no magic. Just sugar over prepared statements.
 * Supports SQLite and MySQL via BbPdo.
 *
 * Values are always bound as parameters. Identifiers (tables, columns, the
 * ordering expression and the operator of a where clause) cannot be bound by
 * PDO, so they are validated against a strict allow-list instead: letters,
 * digits and underscores, optionally qualified as table.column. Invalid
 * identifiers throw InvalidArgumentException instead of being interpolated
 * into SQL.
 *
 * whereRaw(), raw(), rawFirst() and rawExec() are the documented escape hatches
 * and take *trusted* SQL only — those statements are not inspected or
 * sanitised, so never pass user input as the SQL string (bind every value
 * through the $params argument instead). Everything they return is still safe;
 * only the statement text is the caller's responsibility.
 *
 * The builder covers the constructs plugins actually need without escaping to
 * raw SQL: joins, NULL checks and ranges are all first-class and validated.
 *
 * Usage:
 *   $db = new DbQuery($pdo);
 *   $db->table('threads')->leftJoin('posts', 'posts.thread_id', '=', 'threads.id')
 *      ->select('threads.*')->whereNull('posts.deleted_at')->get();
 *   $user = $db->table('users')->where('id', 42)->first();
 *   $db->table('users')->insert(['username' => 'alice', 'email' => 'a@b.com']);
 *   $db->table('users')->where('id', 42)->update(['email' => 'new@b.com']);
 *   $db->table('users')->where('id', 42)->delete();
 */

class DbQuery
{
    /** Operators accepted by where(). Everything else is rejected. */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];

    private PDO $pdo;
    private string $table = '';
    private array $joins = [];
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
        $clone->table = self::assertIdentifier($table, 'table');
        $clone->reset();
        return $clone;
    }

    /**
     * Validate a bare identifier: "users", "users.id", "thread_watchers".
     * Anything that could change the shape of the statement is rejected.
     */
    private static function assertIdentifier(string $identifier, string $what = 'identifier'): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier)) {
            throw new InvalidArgumentException("DbQuery: invalid {$what} '" . substr($identifier, 0, 64) . "'");
        }
        return $identifier;
    }

    /**
     * Validate a select/order clause made of identifiers, "*" or a column
     * alias ("created_at AS c"). Expressions and functions are not supported:
     * use raw()/rawFirst() for those.
     */
    private static function assertColumnExpression(string $expression): string
    {
        $expression = trim(preg_replace('/\s+/', ' ', $expression) ?? '');
        if ($expression === '*' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.\*$/', $expression)) {
            return $expression;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?(?: (?:AS )?[A-Za-z_][A-Za-z0-9_]*)?$/i', $expression)) {
            return $expression;
        }
        throw new InvalidArgumentException("DbQuery: invalid column expression '" . substr($expression, 0, 64) . "'");
    }

    private static function assertOperator(string $operator): string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $operator) ?? ''));
        if (!in_array($normalized, self::OPERATORS, true)) {
            throw new InvalidArgumentException("DbQuery: unsupported operator '{$operator}'");
        }
        return $normalized;
    }

    private function reset(): void
    {
        $this->joins = [];
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
        $validated = [];
        foreach ($cols as $col) {
            $validated[] = self::assertColumnExpression($col);
        }
        $clone->selectCols = $validated ?: ['*'];
        return $clone;
    }

    public function where(string $column, $value, string $op = '='): self
    {
        $clone = clone $this;
        $column = self::assertIdentifier($column, 'column');
        $operator = self::assertOperator($op);

        // NULL never compares equal: "col = NULL" is always false (and
        // "col != NULL" always true). Emit the IS / IS NOT form meant instead.
        if ($value === null && ($operator === '=' || $operator === 'IS')) {
            $clone->wheres[] = "{$column} IS NULL";
            return $clone;
        }
        if ($value === null && ($operator === '!=' || $operator === '<>' || $operator === 'IS NOT')) {
            $clone->wheres[] = "{$column} IS NOT NULL";
            return $clone;
        }

        $paramName = ':w' . count($clone->params);
        $clone->wheres[] = "{$column} {$operator} {$paramName}";
        $clone->params[$paramName] = $value;
        return $clone;
    }

    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            // No values: match nothing. Expressed as raw SQL because it is not
            // a column comparison.
            return $this->whereRaw('1 = 0');
        }
        $clone = clone $this;
        $placeholders = [];
        foreach ($values as $i => $val) {
            $paramName = ':in' . count($clone->params) . '_' . $i;
            $placeholders[] = $paramName;
            $clone->params[$paramName] = $val;
        }
        $clone->wheres[] = self::assertIdentifier($column, 'column') . " IN (" . implode(', ', $placeholders) . ")";
        return $clone;
    }

    /**
     * Add a raw WHERE fragment.
     *
     * SECURITY: $sql is inserted verbatim. It must come from code, never from
     * user input; bind every dynamic value through $params.
     */
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
        $dir = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        $clone->orderBy[] = self::assertIdentifier($column, 'order column') . " {$dir}";
        return $clone;
    }

    /**
     * INNER JOIN with validated identifiers on both sides.
     *
     * $table may be "posts" or "posts AS p"; $first and $second are columns
     * (optionally qualified) and $operator must be one of the comparison
     * operators accepted by where().
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    /**
     * LEFT JOIN — see join() for the accepted arguments.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    private function addJoin(string $type, string $table, string $first, string $operator, string $second): self
    {
        $clone = clone $this;
        $clone->joins[] = $type . ' JOIN ' . self::assertColumnExpression($table)
            . ' ON ' . self::assertIdentifier($first, 'join column')
            . ' ' . self::assertOperator($operator)
            . ' ' . self::assertIdentifier($second, 'join column');
        return $clone;
    }

    /**
     * WHERE column IS NULL (no parameter, no raw SQL needed).
     */
    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = self::assertIdentifier($column, 'column') . ' IS NULL';
        return $clone;
    }

    /**
     * WHERE column IS NOT NULL.
     */
    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = self::assertIdentifier($column, 'column') . ' IS NOT NULL';
        return $clone;
    }

    /**
     * WHERE column BETWEEN min AND max (both bound).
     */
    public function whereBetween(string $column, $min, $max): self
    {
        $clone = clone $this;
        $column = self::assertIdentifier($column, 'column');
        $from = ':between_a' . count($clone->params);
        $clone->params[$from] = $min;
        $to = ':between_b' . count($clone->params);
        $clone->params[$to] = $max;
        $clone->wheres[] = "{$column} BETWEEN {$from} AND {$to}";
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

    private function buildJoins(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        return ' ' . implode(' ', $this->joins);
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
        $validatedCols = [];
        foreach ($this->selectCols as $col) {
            $validatedCols[] = self::assertColumnExpression((string)$col);
        }
        $cols = implode(', ', $validatedCols);
        $sql = "SELECT {$cols} FROM {$this->table}"
            . $this->buildJoins()
            . $this->buildWhere()
            . $this->buildOrder()
            . $this->buildLimit();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limitVal = 1;
        $result = $clone->get();
        return $result[0] ?? null;
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}" . $this->buildJoins() . $this->buildWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return (int) $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        $columns = array_map(fn($c) => self::assertIdentifier((string)$c, 'column'), array_keys($data));
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
        $columns = array_map(fn($c) => self::assertIdentifier((string)$c, 'column'), array_keys($data));
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
            $column = self::assertIdentifier((string)$col, 'column');
            $paramName = ':u_' . $col;
            $setParts[] = "{$column} = {$paramName}";
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

    /**
     * Run a trusted SQL statement and return all rows.
     * SECURITY: $sql is executed verbatim — see the class docblock.
     */
    public function raw(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Run a trusted SQL statement and return the first row.
     * SECURITY: $sql is executed verbatim — see the class docblock.
     */
    public function rawFirst(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Run a trusted SQL write statement and return the affected row count.
     * SECURITY: $sql is executed verbatim — see the class docblock.
     */
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
        $clone = clone $this;
        $clone->selectCols = [$column];
        $rows = $clone->get();
        return array_column($rows, $column);
    }

    public function paginate(int $perPage, int $page = 1): array
    {
        $total = $this->count();
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $clone = clone $this;
        $clone->limitVal = $perPage;
        $clone->offsetVal = $offset;
        $items = $clone->get();

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }
}
