<?php

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publishAt = trim($_POST['publish_at'] ?? '');
    $publishAt = $publishAt !== '' ? $publishAt : null;

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $publicId = generate_public_id($title);

        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at, public_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publishAt, $publicId]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title' => $title,
            'public_id' => $publicId,
            'publish_at' => $publishAt,
        ]);

        if ($publishAt !== null) {
            audit_log('schedule', 'document', $docId, [
                'publish_at' => $publishAt,
            ]);
        }

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE LOWER(d.title) LIKE LOWER(?)
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $search . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>

    <form method="get" class="form-field">
        <label for="q">Search by title</label>
        <input
            type="search"
            id="q"
            name="q"
            placeholder="Search documents by title"
            value="<?= h($search) ?>"
        >
        <button type="submit" class="btn">Search</button>
        <?php if ($search !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear search</a>
        <?php endif ?>
    </form>

    <?php if (empty($docs)): ?>
        <p class="empty">No documents found.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Readable ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Publish At</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['public_id'] ?? '') ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><?= h($d['publish_at'] ?? 'Immediately') ?></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>