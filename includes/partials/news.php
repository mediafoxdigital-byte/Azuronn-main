<?php $news = site_content()['news']; ?>
<section class="news-section" id="azuronn-news" style="position: relative; max-width: 1400px; margin: 0 auto; padding: 60px 20px;">
  <div class="news-head-row" style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px;">
    <div class="sec-hdr-premium" style="margin-bottom: 0; text-align: center; width: 100%;">
      <span class="shop-style-kicker">Latest Updates</span>
      <div class="sec-hdr-title-row" style="justify-content: center;">
          <span class="sec-line"></span>
          <h2><?= h($news['title']) ?></h2>
          <span class="sec-line"></span>
      </div>
      <div style="color: #666; font-size: 0.95rem; margin-top: 15px;">Discover stories, insights, and inspiration from the world of fine jewellery.</div>
    </div>
    <div class="news-viewall" style="position: absolute; right: 20px; top: 120px;">
      <a href="<?= h(resolve_link('/news/')) ?>" style="display: inline-flex; align-items: center; color: #c9a96e; border: 1px solid rgba(200, 169, 110, 0.5); padding: 12px 24px; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.1em; transition: all 0.3s ease;">
        VIEW ALL NEWS <i class="fas fa-arrow-right" style="margin-left: 10px;"></i>
      </a>
    </div>
  </div>
  
  <div class="news-premium-grid">
    <?php foreach (array_slice($news['items'], 0, 3) as $index => $story): 
      // Parse the date (e.g. "30 OCT 2018")
      $time = strtotime($story['date'] ?? 'now');
      $day = date('d', $time);
      $my = date('M Y', $time);
      
      // Determine mock category and icon based on index
      $catName = match($index) { 0 => 'Insights', 1 => 'Inspiration', 2 => 'Trends', default => 'News' };
      $icon = match($index) { 0 => 'fa-file-alt', 1 => 'fa-images', 2 => 'fa-play', default => 'fa-file-alt' };
    ?>
      <a class="news-card-premium" href="<?= h(news_article_url($story)) ?>">
        <div class="news-card-img-wrap">
          <img src="<?php e($story['image']); ?>" alt="<?php e($story['alt']); ?>">
          <div class="news-date-badge">
            <span class="news-date-day"><?= $day ?></span>
            <span class="news-date-my"><?= $my ?></span>
          </div>
          <div class="news-cat-icon"><i class="far <?= $icon ?>"></i></div>
        </div>
        <div class="news-card-body-premium">
          <div class="news-cat-label"><?= $catName ?></div>
          <div class="news-title-premium"><?php e($story['title']); ?></div>
          <p class="news-excerpt-premium"><?php e($story['excerpt']); ?></p>
          <div class="news-read-more">READ FULL STORY <i>&rarr;</i></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
