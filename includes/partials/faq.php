<?php
$faq = site_content()['faq'] ?? [];
$items = is_array($faq['items'] ?? null) ? $faq['items'] : [];

if (empty($items)) {
    return;
}
?>

<section class="faq-section-v2">
    <div class="container">
        <div class="faq-v2-header">
            <p class="faq-v2-kicker"><?= h($faq['kicker'] ?? 'FREQUENTLY ASKED QUESTIONS') ?></p>
            <h2 class="faq-v2-title"><?= h($faq['title'] ?? 'Everything you need to know') ?></h2>
        </div>

        <div class="faq-v2-grid">
            <!-- Left: Accordion Questions -->
            <div class="faq-v2-list">
                <?php foreach ($items as $index => $item): ?>
                    <details class="faq-v2-details" <?= $index === 0 ? 'open' : '' ?>>
                        <summary class="faq-v2-summary">
                            <span><?= h($item['question']) ?></span>
                            <svg class="faq-v2-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="faq-v2-answer">
                            <p><?= nl2br(h($item['answer'])) ?></p>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <!-- Right: Booking Card -->
            <div class="faq-v2-booking">
                <div class="faq-v2-booking-img">
                    <img src="/assets/uploads/faq-booking.png" alt="Book a Consultation">
                </div>
                <div class="faq-v2-booking-body">
                    <h3>Still have questions?</h3>
                    <p>Book a free consultation with our diamond experts and get personalised guidance for your perfect piece.</p>
                    <a href="<?= h(resolve_link('/appointment/')) ?>" class="faq-v2-booking-btn">Book a Consultation</a>
                </div>
            </div>
        </div>
    </div>
</section>
