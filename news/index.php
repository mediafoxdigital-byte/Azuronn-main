<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$newsItems = news_items();
$articleId = clean_string((string) ($_GET['article'] ?? ''), 80);
$isListingPage = $articleId === '';
$article = $isListingPage ? null : find_news_article($articleId);
$relatedArticles = [];

if ($article !== null) {
    foreach ($newsItems as $story) {
        if ((string) ($story['id'] ?? '') === (string) ($article['id'] ?? '')) {
            continue;
        }
        $relatedArticles[] = $story;
        if (count($relatedArticles) >= 3) {
            break;
        }
    }
} elseif (!$isListingPage) {
    http_response_code(404);
}

$pageTitle = ($isListingPage ? 'Azuronn News' : ($article['title'] ?? 'Azuronn News')) . ' - ' . SITE_NAME;
$bodyClass = $isListingPage ? 'news-article-page news-index-page' : 'news-article-page';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($isListingPage): ?>
  <main class="news-index-main">
    <section class="news-index-hero">
      <div class="news-article-wrap">
        <span class="news-article-kicker">Editorial Journal</span>
        <h1>Azuronn News</h1>
        <p>Explore the full editorial feed with every Azuronn story, update, and collection feature in one place.</p>
      </div>
    </section>

    <section class="news-index-section">
      <div class="news-article-wrap">
        <?php if ($newsItems === []): ?>
          <div class="news-article-missing-shell news-index-empty">
            <h2>No news posts yet</h2>
            <p>The editorial feed is empty right now. Publish a post from admin and it will appear here automatically.</p>
            <a class="news-article-back" href="<?= h(resolve_link('/')) ?>">Return home</a>
          </div>
        <?php else: ?>
          <div class="news-index-grid">
            <?php foreach ($newsItems as $index => $story):
              $time = strtotime((string) ($story['date'] ?? 'now'));
              if ($time === false) {
                  $time = time();
              }
              $day = date('d', $time);
              $monthYear = date('M Y', $time);
              $labels = ['Insights', 'Inspiration', 'Trends', 'Editorial'];
              $icons = ['fa-file-alt', 'fa-images', 'fa-play', 'fa-feather-alt'];
              $cardLabel = $labels[$index % count($labels)];
              $cardIcon = $icons[$index % count($icons)];
              $excerpt = trim((string) ($story['excerpt'] ?? ''));
              if ($excerpt === '') {
                  $excerpt = news_article_text($story);
              }
            ?>
              <a class="news-card-premium" href="<?= h(news_article_url($story)) ?>">
                <div class="news-card-img-wrap">
                  <img src="<?= h((string) ($story['image'] ?? '')) ?>" alt="<?= h((string) ($story['alt'] ?? $story['title'] ?? 'Azuronn news story')) ?>">
                  <div class="news-date-badge">
                    <span class="news-date-day"><?= h($day) ?></span>
                    <span class="news-date-my"><?= h($monthYear) ?></span>
                  </div>
                  <div class="news-cat-icon"><i class="far <?= h($cardIcon) ?>"></i></div>
                </div>
                <div class="news-card-body-premium">
                  <div class="news-cat-label"><?= h($cardLabel) ?></div>
                  <div class="news-title-premium"><?= h((string) ($story['title'] ?? 'Azuronn Story')) ?></div>
                  <p class="news-excerpt-premium"><?= h($excerpt) ?></p>
                  <div class="news-index-card-meta">
                    <span>By <?= h((string) ($story['author'] ?? 'Azuronn')) ?></span>
                    <span><?= h((string) ($story['date'] ?? '')) ?></span>
                  </div>
                  <div class="news-read-more">READ FULL STORY <i>&rarr;</i></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
<?php elseif ($article === null): ?>
  <main class="news-article-missing">
    <section class="news-article-missing-shell">
      <span class="news-article-kicker">Azuronn News</span>
      <h1>Article not found</h1>
      <p>The story you tried to open is unavailable or has been moved.</p>
      <a class="news-article-back" href="<?= h(resolve_link('/#azuronn-news')) ?>">Return to news</a>
    </section>
  </main>
<?php else: ?>
  <?php
  $articleHtml = news_article_body($article);
  ?>
  <main class="news-article-main">
    <section class="news-article-hero">
      <div class="news-article-hero-media">
        <img src="<?= h($article['image']) ?>" alt="<?= h($article['alt']) ?>">
      </div>
      <div class="news-article-hero-overlay"></div>
      <div class="news-article-wrap">
        <div class="news-article-hero-copy">
          <a class="news-article-breadcrumb" href="<?= h(resolve_link('/#azuronn-news')) ?>">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span>Back to Azuronn News</span>
          </a>
          <span class="news-article-kicker">Editorial Journal</span>
          <h1><?= h($article['title']) ?></h1>
          <p class="news-article-summary"><?= h($article['excerpt']) ?></p>
          <div class="news-article-meta">
            <span>By <?= h($article['author']) ?></span>
            <span><?= h($article['date']) ?></span>
          </div>
        </div>
      </div>
    </section>

    <section class="news-article-body-section">
      <div class="news-article-wrap news-article-grid">
        <article class="news-article-story">
          <div class="news-article-story-card">
            <div class="news-article-prose"><?= $articleHtml ?></div>
          </div>
        </article>

        <aside class="news-article-rail">
          <div class="news-article-rail-card">
            <span class="news-article-rail-label">Story Details</span>
            <ul class="news-article-detail-list">
              <li><span>Author</span><strong><?= h($article['author']) ?></strong></li>
              <li><span>Published</span><strong><?= h($article['date']) ?></strong></li>
            </ul>
          </div>

          <?php if ($relatedArticles !== []): ?>
            <div class="news-article-rail-card">
              <span class="news-article-rail-label">More From Azuronn</span>
              <div class="news-article-related-list">
                <?php foreach ($relatedArticles as $story): ?>
                  <a class="news-article-related-card" href="<?= h(news_article_url($story)) ?>">
                    <img src="<?= h($story['image']) ?>" alt="<?= h($story['alt']) ?>">
                    <div>
                      <span class="news-article-related-date"><?= h($story['date']) ?></span>
                      <strong><?= h($story['title']) ?></strong>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </aside>
      </div>
    </section>
  </main>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
