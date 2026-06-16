<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician'];
$user = $_SESSION['admin_user'] ?? null;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function blog_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensure_blog_columns(PDO $pdo): void
{
    if (!blog_column_exists($pdo, 'blog_posts', 'seo_title')) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN seo_title VARCHAR(180) NULL AFTER body');
    }
    if (!blog_column_exists($pdo, 'blog_posts', 'seo_description')) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN seo_description VARCHAR(255) NULL AFTER seo_title');
    }
}

function blog_body_column(PDO $pdo): string
{
    return blog_column_exists($pdo, 'blog_posts', 'content') ? 'content' : 'body';
}

if (!is_array($user) || !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    header('Location: admin_blog.php?message=' . urlencode('Please log in to edit blog posts.') . '&tone=error');
    exit;
}

ensure_blog_columns($pdo);
$bodyColumn = blog_body_column($pdo);
$postId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$categories = $pdo->query('SELECT * FROM blog_categories ORDER BY name ASC')->fetchAll();
$post = [
    'id' => 0,
    'category_id' => '',
    'title' => '',
    'slug' => '',
    'excerpt' => '',
    $bodyColumn => '',
    'seo_title' => '',
    'seo_description' => '',
    'status' => 'draft',
    'published_at' => '',
    'featured_image_path' => '',
];

if ($postId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $postId]);
    $found = $stmt->fetch();
    if (!$found) {
        redirect_with_message('admin_blog.php', 'Post not found.', 'error');
    }
    $post = array_merge($post, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'draft');
    $publishedAt = trim((string) ($_POST['published_at'] ?? ''));
    $validStatuses = ['draft', 'published', 'archived'];

    if ($title === '') {
        redirect_with_message('admin_blog_edit.php' . ($postId > 0 ? '?id=' . $postId : ''), 'Title is required.', 'error');
    }
    if (!in_array($status, $validStatuses, true)) {
        $status = 'draft';
    }
    if ($slug === '') {
        $slug = slugify($title);
    } else {
        $slug = slugify($slug);
    }
    if ($status === 'published' && $publishedAt === '') {
        $publishedAt = now();
    }
    if ($status !== 'published' && $publishedAt === '') {
        $publishedAt = null;
    }

    $payload = [
        'author_id' => (int) $user['id'],
        'category_id' => (int) ($_POST['category_id'] ?? 0) ?: null,
        'title' => $title,
        'slug' => $slug,
        'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
        'body' => (string) ($_POST['body'] ?? ''),
        'seo_title' => trim((string) ($_POST['seo_title'] ?? '')),
        'seo_description' => trim((string) ($_POST['seo_description'] ?? '')),
        'featured_image_path' => trim((string) ($_POST['featured_image_path'] ?? '')),
        'status' => $status,
        'published_at' => $publishedAt,
    ];

    try {
        if ($postId > 0) {
            $sql = 'UPDATE blog_posts
                    SET author_id = :author_id, category_id = :category_id, title = :title, slug = :slug,
                        excerpt = :excerpt, ' . $bodyColumn . ' = :body, seo_title = :seo_title,
                        seo_description = :seo_description, featured_image_path = :featured_image_path,
                        status = :status, published_at = :published_at, updated_at = :updated_at
                    WHERE id = :id';
            $payload['updated_at'] = now();
            $payload['id'] = $postId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($payload);
        } else {
            $sql = 'INSERT INTO blog_posts
                    (author_id, category_id, title, slug, excerpt, ' . $bodyColumn . ', seo_title, seo_description,
                     featured_image_path, status, published_at, created_at, updated_at)
                    VALUES
                    (:author_id, :category_id, :title, :slug, :excerpt, :body, :seo_title, :seo_description,
                     :featured_image_path, :status, :published_at, :created_at, :updated_at)';
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($payload);
            $postId = (int) $pdo->lastInsertId();
        }
        redirect_with_message('admin_blog_edit.php?id=' . $postId, 'Post saved.');
    } catch (Throwable $exception) {
        redirect_with_message('admin_blog_edit.php' . ($postId > 0 ? '?id=' . $postId : ''), 'Could not save post: ' . $exception->getMessage(), 'error');
    }
}

$content = (string) ($post[$bodyColumn] ?? $post['body'] ?? $post['content'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $postId > 0 ? 'Edit Blog Post' : 'New Blog Post' ?> | Mobimend</title>
  <link rel="stylesheet" href="admin_ops.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
  <style>
    body { margin: 0; background: #f6f8fb; color: #111827; font-family: Inter, Arial, sans-serif; }
    .shell { max-width: 1120px; margin: 0 auto; padding: 24px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; }
    .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .full { grid-column: 1 / -1; }
    label { display: grid; gap: 6px; font-weight: 800; color: #334155; }
    input, select, textarea, button { border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font: inherit; }
    button, .button-link { background: #1766c5; color: #fff; border: 0; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; min-height: 36px; padding: 8px 12px; border-radius: 8px; }
    .button-link.ghost { background: #eef2f7; color: #334155; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    @media (max-width: 760px) { .form-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="admin-ops">
  <header class="admin-hero">
    <div class="ops-header-inner">
      <div class="ops-brand"><h1><?= $postId > 0 ? 'Edit Blog Post' : 'New Blog Post' ?></h1><p>Write Markdown, tune SEO, and publish customer-facing guides.</p></div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_blog.php">Blog</a>
        <a href="blog.php">Public blog</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="shell">
    <section class="card">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $postId ?>">
        <label class="full">Title
          <input name="title" value="<?= h($post['title']) ?>" required>
        </label>
        <label>Slug
          <input name="slug" value="<?= h($post['slug']) ?>" placeholder="auto-generated-from-title">
        </label>
        <label>Category
          <select name="category_id">
            <option value="">Uncategorized</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>" <?= (int) ($post['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= h($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Status
          <select name="status">
            <?php foreach (['draft', 'published', 'archived'] as $status): ?>
              <option value="<?= $status ?>" <?= (string) $post['status'] === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Published at
          <input name="published_at" value="<?= h($post['published_at']) ?>" placeholder="YYYY-MM-DD HH:MM:SS">
        </label>
        <label class="full">Excerpt
          <textarea name="excerpt" rows="3"><?= h($post['excerpt']) ?></textarea>
        </label>
        <label class="full">Markdown content
          <textarea id="body" name="body" rows="16"><?= h($content) ?></textarea>
        </label>
        <label>SEO title
          <input name="seo_title" value="<?= h($post['seo_title']) ?>">
        </label>
        <label>SEO description
          <input name="seo_description" value="<?= h($post['seo_description']) ?>">
        </label>
        <label class="full">Featured image path
          <input name="featured_image_path" value="<?= h($post['featured_image_path']) ?>" placeholder="uploads/blog/example.jpg or assets/...">
        </label>
        <div class="full actions">
          <button type="submit">Save post</button>
          <a class="button-link ghost" href="admin_blog.php">Back to blog CMS</a>
          <?php if ($postId > 0 && (string) $post['status'] === 'published'): ?>
            <a class="button-link ghost" href="blog.php?slug=<?= h($post['slug']) ?>">View public post</a>
          <?php endif; ?>
        </div>
      </form>
    </section>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
  <script>
    new EasyMDE({
      element: document.getElementById('body'),
      spellChecker: false,
      status: false,
      minHeight: '360px'
    });
  </script>
</body>
</html>
