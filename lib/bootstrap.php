<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';

        $pdo = new PDO('sqlite:' . $path);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA foreign_keys = ON');

        // Only run migrations if base schema already exists
        $stmt = $pdo->query("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
            AND name = 'documents'
        ");

        if ($stmt->fetch()) {
            run_migrations($pdo);
        }
    }

    return $pdo;
}

function run_migrations(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            filename TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $files = glob(__DIR__ . '/../migrations/*.sql');

    sort($files);

    foreach ($files as $file) {
        $filename = basename($file);

        $stmt = $pdo->prepare('
            SELECT 1
            FROM schema_migrations
            WHERE filename = ?
        ');

        $stmt->execute([$filename]);

        if ($stmt->fetchColumn()) {
            continue;
        }

        $pdo->beginTransaction();

        try {
            $pdo->exec(file_get_contents($file));

            $insert = $pdo->prepare('
                INSERT INTO schema_migrations (filename)
                VALUES (?)
            ');

            $insert->execute([$filename]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');

    $stmt->execute();

    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException(
            'No staff row #1 found. Did you run `php seed.php`?'
        );
    }

    return $row;
}

function audit_log(
    string $action,
    string $entity_type,
    int $entity_id,
    array $details = []
): void {
    $staff = current_staff();

    $stmt = db()->prepare('
        INSERT INTO audit_log (
            staff_id,
            action,
            entity_type,
            entity_id,
            details
        )
        VALUES (?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function slugify_title(string $title): string {
    $slug = strtolower(trim($title));

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

    $slug = trim($slug, '-');

    return substr($slug ?: 'document', 0, 24);
}

function generate_public_id(string $title): string {
    $base = slugify_title($title);

    for ($i = 0; $i < 10; $i++) {
        $suffix = strtolower(
            substr(bin2hex(random_bytes(2)), 0, 3)
        );

        $publicId = $base . '-' . $suffix;

        $stmt = db()->prepare('
            SELECT COUNT(*)
            FROM documents
            WHERE public_id = ?
        ');

        $stmt->execute([$publicId]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $publicId;
        }
    }

    throw new RuntimeException(
        'Unable to generate unique public ID'
    );
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}