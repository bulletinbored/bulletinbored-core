<?php

/**
 * PDO wrapper that normalises SQLite-flavoured SQL to the active driver.
 *
 * Plugins are written against SQLite (AUTOINCREMENT, INTEGER primary keys,
 * no default on TEXT, reserved-word column names, CREATE INDEX IF NOT EXISTS,
 * etc.). When the forum runs on MySQL we rewrite the few dialect differences
 * so third-party plugins keep working unchanged.
 */
class BbPdo extends PDO
{
    private bool $mysql;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null, ?array $options = null)
    {
        parent::__construct($dsn, $user, $pass, $options);
        $this->mysql = stripos($dsn, 'mysql:') === 0;
    }

    private function normalize(string $sql): string
    {
        if (!$this->mysql) {
            return $sql;
        }

        // AUTOINCREMENT (SQLite) -> AUTO_INCREMENT (MySQL)
        $sql = preg_replace('#\bAUTOINCREMENT\b#i', 'AUTO_INCREMENT', $sql);

        // INTEGER PRIMARY KEY AUTOINCREMENT -> INT PRIMARY KEY AUTO_INCREMENT
        $sql = preg_replace('#\bINTEGER\s+PRIMARY\s+KEY\b#i', 'INT PRIMARY KEY', $sql);

        // Default values on TEXT/BLOB columns are not allowed on MySQL strict mode.
        $sql = preg_replace_callback(
            '#(\b(?:TEXT|BLOB)\b[^\n,;]*?)\s+DEFAULT\s+\x27\x27#i',
            fn($m) => $m[1],
            $sql
        );
        $sql = preg_replace_callback(
            '#(\b(?:TEXT|BLOB)\b[^\n,;]*?)\s+DEFAULT\s+\x27\[\]\x27#i',
            fn($m) => $m[1],
            $sql
        );

        // Quote MySQL reserved words used as column names (e.g. `read`).
        $colWords = ['read', 'write', 'rank', 'row', 'check', 'call', 'show', 'match'];
        $sql = preg_replace_callback(
            '#\b(' . implode('|', $colWords) . ')\b(?=\s*(?:[)=<>!+\-/,]|IS|NOT|AND|OR|LIKE|IN)\b|\s*$)#i',
            fn($m) => '`' . $m[1] . '`',
            $sql
        );

        // SQLite "CREATE INDEX IF NOT EXISTS" is not valid MySQL syntax.
        $sql = preg_replace('#CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS#i', 'CREATE INDEX', $sql);

        return $sql;
    }

    public function exec($statement, ...$rest)
    {
        $statement = $this->normalize($statement);
        if ($statement === '') {
            return false;
        }
        return parent::exec($statement, ...$rest);
    }

    public function query($statement, ...$rest)
    {
        return parent::query($this->normalize($statement), ...$rest);
    }

    public function prepare($statement, $options = null)
    {
        return parent::prepare($this->normalize($statement), $options ?? []);
    }
}
