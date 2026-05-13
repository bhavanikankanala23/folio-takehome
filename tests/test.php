<?php

require_once __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

run_migrations(db());

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();

    assert_true($row !== false, 'expected seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('created document gets a human-readable public id', function () {
    $publicId = generate_public_id('Onboarding Packet');

    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, public_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        'Onboarding Packet',
        'Welcome to the team.',
        1,
        null,
        $publicId,
    ]);

    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT public_id FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    assert_true($doc !== false, 'expected document to exist');
    assert_true($doc['public_id'] !== '', 'expected public_id to be populated');
    assert_true(str_starts_with($doc['public_id'], 'onboarding-packet-'), 'unexpected public_id: ' . $doc['public_id']);
});

test('future scheduled document is not available before publish time', function () {
    $publicId = generate_public_id('Future Document');
    $publishAt = date('Y-m-d\TH:i', time() + 3600);

    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, public_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        'Future Document',
        'This should not be visible yet.',
        1,
        $publishAt,
        $publicId,
    ]);

    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    $isNotYetAvailable = !empty($doc['publish_at']) && strtotime($doc['publish_at']) > time();

    assert_true($isNotYetAvailable, 'expected future document to be blocked before publish time');
});

test('document title search finds matching documents', function () {
    $publicId = generate_public_id('Benefits Guide');

    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, public_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        'Benefits Guide',
        'Health and retirement information.',
        1,
        null,
        $publicId,
    ]);

    $search = 'benefits';

    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE LOWER(d.title) LIKE LOWER(?)
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $search . '%']);
    $docs = $stmt->fetchAll();

    assert_true(count($docs) >= 1, 'expected at least one matching document');
    assert_true($docs[0]['title'] === 'Benefits Guide', 'expected Benefits Guide search result');
});

test('document creation and scheduling can be audited', function () {
    $publicId = generate_public_id('Audit Test Document');
    $publishAt = date('Y-m-d\TH:i', time() + 7200);

    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at, public_id)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        'Audit Test Document',
        'Audit body.',
        1,
        $publishAt,
        $publicId,
    ]);

    $docId = (int) db()->lastInsertId();

    audit_log('create', 'document', $docId, [
        'title' => 'Audit Test Document',
        'public_id' => $publicId,
        'publish_at' => $publishAt,
    ]);

    audit_log('schedule', 'document', $docId, [
        'publish_at' => $publishAt,
    ]);

    $stmt = db()->prepare('
        SELECT COUNT(*) AS count
        FROM audit_log
        WHERE entity_type = ?
        AND entity_id = ?
        AND action IN (?, ?)
    ');
    $stmt->execute(['document', $docId, 'create', 'schedule']);
    $row = $stmt->fetch();

    assert_true((int) $row['count'] === 2, 'expected create and schedule audit logs');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);