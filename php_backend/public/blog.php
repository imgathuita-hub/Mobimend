<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();

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

function blog_body_column(PDO $pdo): string
{
    return blog_column_exists($pdo, 'content') ? 'content' : 'body';
}

function markdown_inline(string $text): string
{
    $text = h($text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', static function (array $match): string {
        return '<a href="' . h($match[2]) . '">' . h($match[1]) . '</a>';
    }, $text) ?? $text;

    return $text;
}

function markdown_to_html(string $markdown): string
{
    $lines = preg_split('/\R/', trim($markdown)) ?: [];
    $html = '';
    $paragraph = [];
    $inList = false;

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph !== []) {
            $html .= '<p>' . markdown_inline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            $flushParagraph();
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $match)) {
            $flushParagraph();
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $level = strlen($match[1]) + 1;
            $html .= '<h' . $level . '>' . markdown_inline($match[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $line, $match)) {
            $flushParagraph();
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . markdown_inline($match[1]) . '</li>';
            continue;
        }

        $paragraph[] = $line;
    }

    $flushParagraph();
    if ($inList) {
        $html .= '</ul>';
    }

    return $html;
}

$bodyColumn = blog_body_column($pdo);
$hasSeoTitle = blog_column_exists($pdo, 'seo_title');
$hasSeoDescription = blog_column_exists($pdo, 'seo_description');
$slug = trim((string) ($_GET['slug'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$categorySlug = trim((string) ($_GET['category'] ?? ''));
$post = null;
$posts = [];

$categories = $pdo->query(
    'SELECT bc.*, COUNT(bp.id) AS post_count
     FROM blog_categories bc
     LEFT JOIN blog_posts bp ON bp.category_id = bc.id
       AND bp.status = "published"
       AND (bp.published_at IS NULL OR bp.published_at <= NOW())
     GROUP BY bc.id, bc.name, bc.slug, bc.description, bc.created_at, bc.updated_at
     ORDER BY bc.name ASC'
)->fetchAll();

$select = 'bp.id, bp.title, bp.slug, bp.excerpt, bp.' . $bodyColumn . ' AS body, bp.featured_image_path,
           bp.status, bp.published_at, bp.created_at, bc.name AS category_name, bc.slug AS category_slug';
if ($hasSeoTitle) {
    $select .= ', bp.seo_title';
}
if ($hasSeoDescription) {
    $select .= ', bp.seo_description';
}

if ($slug !== '') {
    $stmt = $pdo->prepare(
        'SELECT ' . $select . '
         FROM blog_posts bp
         LEFT JOIN blog_categories bc ON bc.id = bp.category_id
         WHERE bp.slug = :slug
           AND bp.status = "published"
           AND (bp.published_at IS NULL OR bp.published_at <= NOW())
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    $post = $stmt->fetch() ?: null;
} else {
    $where = ['bp.status = "published"', '(bp.published_at IS NULL OR bp.published_at <= NOW())'];
    $params = [];

    if ($search !== '') {
        $where[] = '(bp.title LIKE :search OR bp.excerpt LIKE :search OR bp.' . $bodyColumn . ' LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    if ($categorySlug !== '') {
        $where[] = 'bc.slug = :category';
        $params['category'] = $categorySlug;
    }

    $stmt = $pdo->prepare(
        'SELECT ' . $select . '
         FROM blog_posts bp
         LEFT JOIN blog_categories bc ON bc.id = bp.category_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY COALESCE(bp.published_at, bp.created_at) DESC
         LIMIT 24'
    );
    $stmt->execute($params);
    $posts = $stmt->fetchAll();
}

$pageTitle = $post
    ? (string) (($post['seo_title'] ?? '') ?: $post['title']) . ' | Mobimend Spares'
    : 'Repair Guides | Mobimend Spares';
$description = $post
    ? (string) (($post['seo_description'] ?? '') ?: ($post['excerpt'] ?? 'Mobimend repair guide.'))
    : 'Phone repair guides, payment help, parts quality advice, and wholesale buying tips from Mobimend.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <style>
    .article-body { display: grid; gap: 14px; color: #334155; line-height: 1.75; }
    .article-body h2, .article-body h3, .article-body h4 { margin: 10px 0 0; color: #111827; }
    .article-body p, .article-body ul { margin: 0; }
    .article-body a { color: #08788d; font-weight: 800; }
    .blog-card img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 8px; margin-bottom: 14px; }
  </style>
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Repair knowledge</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a class="active" href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main class="section alt">
    <div class="section-inner">
      <?php if ($slug !== ''): ?>
        <?php if (!$post): ?>
          <p class="section-kicker"><i class="fa-solid fa-book-open"></i> Repair help center</p>
          <h1 class="section-title">Post not found.</h1>
          <p class="section-copy">That guide may be unpublished or the link may have changed.</p>
          <a class="btn-dark" href="blog.php">Back to all guides</a>
        <?php else: ?>
          <p class="section-kicker"><i class="fa-solid fa-book-open"></i> <?= h($post['category_name'] ?? 'Mobimend guide') ?></p>
          <h1 class="section-title"><?= h($post['title']) ?></h1>
          <p class="section-copy"><?= h($post['excerpt'] ?? '') ?></p>
          <div class="blog-meta" style="margin: 18px 0;">
            <span class="status-pill"><?= h($post['published_at'] ?? $post['created_at']) ?></span>
            <?php if (!empty($post['category_name'])): ?><span class="status-pill"><?= h($post['category_name']) ?></span><?php endif; ?>
          </div>
          <?php if (!empty($post['featured_image_path'])): ?>
            <img src="<?= h($post['featured_image_path']) ?>" alt="<?= h($post['title']) ?>" style="width: 100%; max-height: 430px; object-fit: cover; border-radius: 8px; margin-bottom: 24px;">
          <?php endif; ?>
          <article class="track-card article-body">
            <?= markdown_to_html((string) $post['body']) ?>
          </article>
        <?php endif; ?>
      <?php else: ?>
        <p class="section-kicker"><i class="fa-solid fa-book-open"></i> Repair help center</p>
        <h1 class="section-title">Useful phone repair information before customers book.</h1>
        <p class="section-copy">Search symptoms, parts quality, repair expectations, payment help, and wholesale buying guidance.</p>

        <form class="blog-tools" method="get" action="blog.php">
          <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search: iPhone battery swelling, Tecno screen price, charging port issue...">
          <button class="btn-dark" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </form>
        <div class="category-pills">
          <a class="pill <?= $categorySlug === '' ? 'active' : '' ?>" href="blog.php">All</a>
          <?php foreach ($categories as $category): ?>
            <a class="pill <?= $categorySlug === (string) $category['slug'] ? 'active' : '' ?>" href="blog.php?category=<?= h($category['slug']) ?>"><?= h($category['name']) ?></a>
          <?php endforeach; ?>
        </div>

        <div class="blog-grid">
          <?php if ($posts === []): ?>
            <article class="blog-card featured">
              <h3>No published posts yet</h3>
              <p>Published guides from the Mobimend CMS will appear here.</p>
              <a class="btn-light" href="repair.php#repair-booking">Start a repair booking</a>
            </article>
          <?php endif; ?>
          <?php foreach ($posts as $index => $item): ?>
            <article class="blog-card <?= $index === 0 ? 'featured' : '' ?>">
              <?php if (!empty($item['featured_image_path'])): ?><img src="<?= h($item['featured_image_path']) ?>" alt="<?= h($item['title']) ?>"><?php endif; ?>
              <div class="blog-meta">
                <span class="status-pill"><?= h($item['category_name'] ?? 'Guide') ?></span>
                <span class="status-pill"><?= h($item['published_at'] ?? $item['created_at']) ?></span>
              </div>
              <h3><?= h($item['title']) ?></h3>
              <p><?= h($item['excerpt'] ?: substr(strip_tags((string) $item['body']), 0, 150) . '...') ?></p>
              <a class="btn-light" href="blog.php?slug=<?= h($item['slug']) ?>">Read guide</a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div><h3>Mobimend Guides</h3><p>Repair content that feeds search, trust, and better booking decisions.</p></div>
      <div><h3>Topics</h3><ul><li>Diagnostics</li><li>Parts</li><li>Payments</li></ul></div>
      <div><h3>Actions</h3><ul><li><a href="repair.php">Book repair</a></li><li><a href="track.php">Track order</a></li></ul></div>
      <div><h3>Shop</h3><ul><li><a href="accessories.php">Accessories</a></li><li><a href="wholesale.php">Wholesale</a></li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
