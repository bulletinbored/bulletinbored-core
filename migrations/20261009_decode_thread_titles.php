<?php

/**
 * Decode thread titles that were previously stored HTML-escaped.
 *
 * Before this release the title input was run through htmlspecialchars() and
 * then escaped again on output, so titles like "we're" were stored as
 * "we&#039;re" and displayed literally as "we&#039;re". Titles are now stored
 * raw; this migration reverses the old escaping on existing rows so they
 * render correctly too.
 */
class DecodeThreadTitles
{
    public function up(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT id, title FROM threads')->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return;
        }

        $update = $pdo->prepare('UPDATE threads SET title = ? WHERE id = ?');
        foreach ($rows as $row) {
            $current = (string)$row['title'];
            $decoded = html_entity_decode($current, ENT_QUOTES, 'UTF-8');
            if ($decoded !== $current) {
                $update->execute([$decoded, $row['id']]);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        // Irreversible: re-escaping cannot reliably restore the exact previous
        // value for titles that only looked like entities.
    }

    public function irreversible(): bool
    {
        return true;
    }
}
