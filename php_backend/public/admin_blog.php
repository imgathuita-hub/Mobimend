<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();
require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician'];
$user = $_SESSION['admin_user'] ?? null;
$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function blog_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "blog_posts" AND COLUMN_NAME = :column'
    );
    $stmt->execute(['column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensure_blog_columns(PDO $pdo): void
{
    if (!blog_column_exists($pdo, 'seo_title')) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN seo_title VARCHAR(180) NULL AFTER body');
    }
    if (!blog_column_exists($pdo, 'seo_description')) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN seo_description VARCHAR(255) NULL AFTER seo_title');
    }
}

if (is_array($user) && !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    unset($_SESSION['admin_user']);
    $user = null;
}
$role = is_array($user) ? (string) ($user['role'] ?? '') : '';
$canSeePayments = in_array($role, ['admin', 'super_admin', 'finance'], true);

if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $foundUser = $stmt->fetch();
    if (!$foundUser || !password_verify($password, (string) $foundUser['password_hash']) || !in_array((string) $foundUser['role'], $adminRoles, true)) {
        $message = 'Invalid credentials.';
        $tone = 'error';
    } else {
        $_SESSION['admin_user'] = [
            'id' => (int) $foundUser['id'],
            'name' => (string) $foundUser['name'],
            'email' => (string) $foundUser['email'],
            'role' => (string) $foundUser['role'],
        ];
        header('Location: admin_blog.php');
        exit;
    }
}

if ($user) {
    ensure_blog_columns($pdo);
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_category') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            redirect_with_message('admin_blog.php', 'Category name is required.', 'error');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO blog_categories (name, slug, description)
             VALUES (:name, :slug, :description)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        $stmt->execute([
            'name' => $name,
            'slug' => slugify($name),
            'description' => trim((string) ($_POST['description'] ?? '')),
        ]);
        redirect_with_message('admin_blog.php', 'Category saved.');
    }

    if ($action === 'delete_post') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $stmt = $pdo->prepare('DELETE FROM blog_posts WHERE id = :id');
            $stmt->execute(['id' => $postId]);
        }
        redirect_with_message('admin_blog.php', 'Post deleted.');
    }
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$posts = [];
$categories = [];
$stats = ['all' => 0, 'published' => 0, 'draft' => 0];

if ($user) {
    $posts = $pdo->query(
        'SELECT bp.*, bc.name AS category_name, u.name AS author_name
         FROM blog_posts bp
         LEFT JOIN blog_categories bc ON bc.id = bp.category_id
         LEFT JOIN users u ON u.id = bp.author_id
         ORDER BY COALESCE(bp.published_at, bp.created_at) DESC'
    )->fetchAll();
    $categories = $pdo->query('SELECT * FROM blog_categories ORDER BY name ASC')->fetchAll();
    foreach ($posts as $post) {
        $stats['all']++;
        if ((string) $post['status'] === 'published') {
            $stats['published']++;
        }
        if ((string) $post['status'] === 'draft') {
            $stats['draft']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blog CMS | Mobimend</title>
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    body { margin: 0; background: #f6f8fb; color: #111827; font-family: Inter, Arial, sans-serif; }
    .shell { max-width: 1240px; margin: 0 auto; padding: 24px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; }
    .stats-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
    .stat span, .muted { color: #64748b; }
    .stat strong { display: block; margin-top: 6px; font-size: 1.45rem; }
    .banner { margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-weight: 700; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .grid { display: grid; grid-template-columns: 1fr 340px; gap: 16px; align-items: start; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 11px; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: top; }
    th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; }
    input, textarea, button { border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font: inherit; }
    button, .button-link { background: #1766c5; color: #fff; border: 0; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; min-height: 36px; padding: 8px 12px; border-radius: 8px; }
    .button-link.ghost, button.ghost { background: #eef2f7; color: #334155; }
    .inline-form { display: inline-flex; gap: 8px; align-items: center; }
    @media (max-width: 920px) { .grid, .stats-row { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="admin-ops">
  <header class="admin-hero">
    <div class="ops-header-inner">
      <div class="ops-brand"><h1>Blog CMS</h1><p>Create repair guides, SEO pages, and customer education posts.</p></div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_dashboard.php">Operations</a>
        <?php if ($canSeePayments): ?><a href="admin_payments.php">Payments</a><?php endif; ?>
        <a href="admin_inventory.php">Inventory</a>
        <a href="admin_orders.php">Orders</a>
        <a href="admin_repairs.php">Repairs</a>
        <a href="admin_products.php">Products</a>
        <a class="active" href="admin_blog.php">Blog</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="shell">
    <?php if ($message !== ''): ?><div class="banner <?= h($tone) ?>"><?= h($message) ?></div><?php endif; ?>

    <?php if (!$user): ?>
      <section class="card" style="max-width: 460px; margin: 40px auto;">
        <h2>Admin Login</h2>
        <?php if ($adminCount === 0): ?><div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div><?php endif; ?>
        <form method="post" style="display: grid; gap: 12px;">
          <input type="hidden" name="action" value="login">
          <input type="email" name="email" placeholder="Email" required>
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Login</button>
        </form>
      </section>
    <?php else: ?>
      <section class="stats-row">
        <div class="stat"><span>Total posts</span><strong><?= number_format($stats['all']) ?></strong></div>
        <div class="stat"><span>Published</span><strong><?= number_format($stats['published']) ?></strong></div>
        <div class="stat"><span>Drafts</span><strong><?= number_format($stats['draft']) ?></strong></div>
      </section>

      <div class="grid">
        <section class="card">
          <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 12px;">
            <h2 style="margin: 0;">Posts</h2>
            <a class="button-link" href="admin_blog_edit.php">New post</a>
          </div>
          <div style="overflow-x: auto;">
            <table>
              <thead><tr><th>Title</th><th>Status</th><th>Category</th><th>Published</th><th>Actions</th></tr></thead>
              <tbody>
                <?php if ($posts === []): ?><tr><td colspan="5" class="muted">No posts yet.</td></tr><?php endif; ?>
                <?php foreach ($posts as $post): ?>
                  <tr>
                    <td><strong><?= h($post['title']) ?></strong><br><span class="muted">/blog.php?slug=<?= h($post['slug']) ?></span></td>
                    <td><?= h($post['status']) ?></td>
                    <td><?= h($post['category_name'] ?? 'Uncategorized') ?></td>
                    <td><?= h($post['published_at'] ?? '') ?></td>
                    <td>
                      <a class="button-link ghost" href="admin_blog_edit.php?id=<?= (int) $post['id'] ?>">Edit</a>
                      <form method="post" class="inline-form" onsubmit="return confirm('Delete this post?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <button class="ghost" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <aside class="card">
          <h2>Categories</h2>
          <form method="post" style="display: grid; gap: 10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_category">
            <input name="name" placeholder="Repair guides" required>
            <textarea name="description" rows="3" placeholder="Short category description"></textarea>
            <button type="submit">Save category</button>
          </form>
          <div style="display: grid; gap: 8px; margin-top: 16px;">
            <?php foreach ($categories as $category): ?>
              <div><strong><?= h($category['name']) ?></strong><br><span class="muted"><?= h($category['slug']) ?></span></div>
            <?php endforeach; ?>
          </div>
        </aside>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
