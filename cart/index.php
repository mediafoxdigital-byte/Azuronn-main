<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        site_flash_set('error', 'Session expired. Please try again.');
        redirect(resolve_link('/cart/'));
    }

    $action = clean_string($_POST['action'] ?? '', 40);
    $removeKey = clean_string($_POST['remove_key'] ?? '', 80);
    if ($removeKey !== '') {
        cart_remove_item($removeKey);
        site_flash_set('success', 'Item removed from cart.');
    } elseif ($action === 'update-cart') {
        $result = cart_update_items(is_array($_POST['quantities'] ?? null) ? $_POST['quantities'] : []);
        site_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Cart updated.'));
    } elseif ($action === 'apply-coupon') {
        $result = cart_apply_coupon((string) ($_POST['coupon_code'] ?? ''));
        site_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Unable to apply coupon.'));
    } elseif ($action === 'clear-coupon') {
        cart_clear_coupon();
        site_flash_set('success', 'Coupon removed.');
    }

    redirect(resolve_link('/cart/'));
}

$cart = cart_state();
$flash = site_flash_pull();
$pageTitle = 'Cart - ' . SITE_NAME;
$bodyClass = 'cart-page';
$recommended = array_slice(catalog_products(), 0, 4);
require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
  /* ============================================================
     CART PAGE — Classic / Elegant single-source styles
     Presentation only. Every form field, data-* hook, link and
     PHP expression in the markup below is untouched.
     ============================================================ */

  /* Load a proper elegant serif for headings + Jost for body.
     Scoped under .cart-page so the rest of the site is unaffected. */
  .cart-page {
    --cart-serif: 'Cormorant Garamond', 'Jost', Georgia, serif;
    --cart-sans: 'Jost', 'Helvetica Neue', Arial, sans-serif;
    --cart-ink: #1c1c1c;
    --cart-ink-soft: #6b6b6b;
    --cart-mute: #9a948a;
    --cart-gold: #b08d57;
    --cart-line: #e7e2d9;
    --cart-bg-tint: #fbfaf7;
    font-family: var(--cart-sans);
    background: #ffffff;
    color: var(--cart-ink);
  }

  /* ---- Wider shell: fills the viewport, kills the side gutters ---- */
  .cart-page .cart-shell .container {
    width: min(1500px, calc(100vw - 48px));
    max-width: min(1500px, calc(100vw - 48px));
  }

  /* ---- Breadcrumbs ---- */
  .cart-page .store-breadcrumbs {
    background: transparent;
    padding: 22px 0;
    border-bottom: 1px solid var(--cart-line);
    font-size: 0.72rem;
    color: var(--cart-mute);
    text-transform: uppercase;
    letter-spacing: 0.14em;
  }
  .cart-page .store-breadcrumbs a { color: var(--cart-ink-soft); text-decoration: none; transition: color .2s; }
  .cart-page .store-breadcrumbs a:hover { color: var(--cart-gold); }
  .cart-page .store-breadcrumbs span { margin: 0 12px; color: #d8d3ca; }
  .cart-page .store-breadcrumbs strong { color: var(--cart-gold); font-weight: 600; }

  /* ---- Header ---- */
  .cart-page .collection-hero {
    background: none;
    padding: 46px 0 14px;
    text-align: left;
    border-bottom: none;
  }
  .cart-page .collection-hero::before { display: none; }
  .cart-page .collection-hero .container { position: static; }
  .cart-page .collection-hero h1 {
    font-family: var(--cart-serif);
    font-size: clamp(2.1rem, 3.6vw, 3rem);
    font-weight: 500;
    color: var(--cart-ink);
    margin: 0 0 8px;
    letter-spacing: 0.01em;
    line-height: 1.1;
  }
  .cart-page .collection-hero p {
    margin: 0;
    color: var(--cart-ink-soft);
    font-size: 1rem;
    max-width: 620px;
    line-height: 1.6;
  }
  .cart-page .hero-ornament {
    display: flex; align-items: center; gap: 14px; margin-top: 18px;
  }
  .cart-page .hero-ornament-line { height: 1px; width: 48px; background: var(--cart-gold); }
  .cart-page .hero-ornament i { color: var(--cart-gold); font-size: 0.75rem; }

  /* ---- Flash message ---- */
  .cart-page .store-flash {
    background: var(--cart-bg-tint);
    color: var(--cart-ink);
    border: 1px solid var(--cart-line);
    border-radius: 4px;
    padding: 11px 20px;
    text-align: center;
    font-size: 0.85rem;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    box-shadow: none;
    margin: 0 auto 28px !important;
    position: relative; z-index: 5;
  }
  .cart-page .store-flash.success::before {
    content: "\f00c"; font-family: "Font Awesome 6 Free"; font-weight: 900; color: #3f7a4f;
  }

  /* ---- Empty state ---- */
  .cart-page .cart-empty-state {
    background: #fff; border: 1px solid var(--cart-line); border-radius: 6px;
    padding: 70px 30px; text-align: center; margin: 40px 0; box-shadow: none;
  }
  .cart-page .cart-empty-state h3 {
    font-family: var(--cart-serif); font-size: 2rem; font-weight: 500;
    color: var(--cart-ink); margin: 0 0 14px;
  }
  .cart-page .cart-empty-state p {
    color: var(--cart-ink-soft); font-size: 1rem; max-width: 460px;
    margin: 0 auto 28px; line-height: 1.6;
  }

  /* ---- Two-column page grid (lines | summary) ---- */
  .cart-page .cart-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 56px;
    align-items: start;
    margin-top: 18px;
  }
  .cart-page .cart-lines-panel { background: transparent; border: 0; border-radius: 0; padding: 0; box-shadow: none; }

  /* ============================================================
     LINE ITEM — open, editorial layout (no boxed card).
     [image]  [details ……………]  [price / qty / remove]
     Separated only by a thin hairline between items.
     ============================================================ */
  .cart-page .cart-line-card {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 34px;
    margin-bottom: 34px;
    padding-bottom: 34px;
    border-bottom: 1px solid var(--cart-line);
    background: transparent;
    border-radius: 0;
  }
  .cart-page .cart-line-card:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }

  /* Media — thumbnails stacked vertically (ring on top, diamond below) */
  .cart-page .cart-line-media {
    flex: 0 0 auto;
    display: flex; flex-direction: column; gap: 12px;
    align-self: flex-start;
    text-decoration: none; color: inherit;
  }
  .cart-page .cart-media-tile {
    width: 128px; height: 128px;
    min-height: 0; aspect-ratio: 1 / 1;
    padding: 12px;
    background: var(--cart-bg-tint);
    border: 1px solid var(--cart-line);
    border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
  }
  .cart-page .cart-media-tile strong { display: none; }
  .cart-page .cart-media-tile img,
  .cart-page .cart-media-tile video {
    width: 100%; height: 100%; object-fit: contain;
    margin: 0; mix-blend-mode: multiply; display: block;
  }

  /* Details column */
  .cart-page .cart-line-copy { flex: 1 1 360px; min-width: 0; }
  .cart-page .cart-line-type {
    display: block;
    font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.2em;
    color: var(--cart-gold); margin: 0 0 10px; font-weight: 600;
  }
  .cart-page .cart-line-copy h2 {
    font-family: var(--cart-serif);
    font-size: 1.7rem; font-weight: 500;
    color: var(--cart-ink);
    margin: 0 0 18px; line-height: 1.2; letter-spacing: 0.01em;
  }
  .cart-page .cart-line-copy h2 a { color: inherit; text-decoration: none; }
  .cart-page .cart-line-copy h2 a:hover { color: var(--cart-gold); }
  .cart-page .cart-line-copy p { color: var(--cart-ink-soft); font-size: 0.95rem; margin: 0; }

  /* Specs — single-column spec table: each spec is one full-width row with the
     label on the left and the value on the right. Because every row ALWAYS has
     both a label and a value, there are never any empty cells — so the old
     "half border line" defect (caused by mixing full-width + half-width cells
     in a 2-col grid, which left empty tracks that drew only a partial border)
     is now structurally impossible. The `>` combinator on label/value keeps
     the metal colour dot (a <span> nested inside the value) from being styled
     as a label. */
  .cart-page .cart-line-specs {
    margin-top: 4px;
    border: 1px solid var(--cart-line);
    border-radius: 3px;
    overflow: hidden;
  }
  .cart-page .cart-line-spec {
    display: flex;
    align-items: baseline;
    gap: 18px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--cart-line);
    background: none;
  }
  .cart-page .cart-line-spec:last-child { border-bottom: 0; }
  /* `.is-wide` is now a harmless no-op (kept in the markup for readability);
     every row uses the same full-width layout, so no special case is needed. */
  .cart-page .cart-line-spec i { display: none; }
  .cart-page .cart-line-spec > span {
    flex: 0 0 132px;
    max-width: 132px;
    font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.16em;
    color: var(--cart-mute); margin: 0; font-weight: 600; line-height: 1.4;
  }
  .cart-page .cart-line-spec > strong {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 0.92rem; color: var(--cart-ink); font-weight: 500;
    line-height: 1.4; overflow-wrap: anywhere; word-break: break-word;
  }
  .cart-page .cart-metal-dot {
    display: inline-block; width: 11px; height: 11px; border-radius: 50%;
    border: 1px solid rgba(0,0,0,0.15); vertical-align: -1px; margin-right: 7px;
  }
  .cart-page .cart-line-copy p.cart-line-note {
    font-size: 0.8rem; color: var(--cart-mute); margin: 14px 0 0; line-height: 1.5;
  }

  /* Right rail of each line: price, qty, remove */
  .cart-page .cart-line-actions {
    flex: 0 0 auto; min-width: 150px;
    display: flex; flex-direction: column;
    align-items: flex-end; justify-content: flex-start;
    gap: 18px; padding-top: 2px; margin-top: 0;
    border-top: 0;
  }
  .cart-page .cart-line-price-stack {
    display: flex; flex-direction: column; align-items: flex-end; gap: 4px; margin-left: 0;
  }
  .cart-page .cart-line-price-stack span {
    font-size: 0.6rem; letter-spacing: 0.18em; text-transform: uppercase;
    color: var(--cart-mute); font-weight: 600;
  }
  .cart-page .cart-line-actions strong {
    font-size: 0.92rem; color: var(--cart-ink); font-weight: 500;
    font-family: var(--cart-sans);
  }
  .cart-page .store-qty {
    display: flex; align-items: center;
    border: 1px solid var(--cart-line); border-radius: 2px; overflow: hidden;
    background: #fff; padding: 0;
  }
  .cart-page .store-qty button {
    background: transparent; border: 0; padding: 7px 13px;
    color: var(--cart-ink); cursor: pointer; font-size: 1rem;
    transition: background .2s, color .2s;
  }
  .cart-page .store-qty button:hover { background: var(--cart-bg-tint); color: var(--cart-gold); }
  .cart-page .store-qty input {
    width: 36px; text-align: center; border: 0; background: transparent;
    font-weight: 500; color: var(--cart-ink); -moz-appearance: textfield;
  }
  .cart-page .store-qty input::-webkit-outer-spin-button,
  .cart-page .store-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .cart-page .store-link-btn {
    background: transparent; border: 0;
    color: var(--cart-mute); font-size: 0.68rem;
    text-transform: uppercase; letter-spacing: 0.16em;
    cursor: pointer; padding: 0; text-decoration: none; font-weight: 600;
    transition: color .2s;
  }
  .cart-page .store-link-btn:hover { color: var(--cart-ink); }

  /* Footer actions (continue shopping / update cart) */
  .cart-page .cart-footer-actions {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 36px; padding-top: 28px; border-top: 1px solid var(--cart-line);
  }

  /* ============================================================
     ORDER SUMMARY — classic panel
     ============================================================ */
  .cart-page .summary-card {
    background: var(--cart-bg-tint);
    border: 1px solid var(--cart-line);
    border-radius: 4px;
    padding: 32px 28px;
    box-shadow: none;
    position: sticky; top: 24px;
  }
  .cart-page .summary-card h2 {
    font-family: var(--cart-serif);
    font-size: 1.6rem; font-weight: 500; color: var(--cart-ink);
    text-align: center; justify-content: center;
    margin: 0 0 4px; gap: 0; display: block;
  }
  .cart-page .summary-card h2::before { display: none; }
  .cart-page .summary-ornament {
    display: flex; align-items: center; justify-content: center; gap: 12px;
    margin-bottom: 26px;
  }
  .cart-page .summary-ornament-line { height: 1px; flex: 1; background: var(--cart-line); }
  .cart-page .summary-ornament i { color: var(--cart-gold); font-size: 0.6rem; }
  .cart-page .summary-row {
    display: flex; justify-content: space-between;
    margin-bottom: 14px; color: var(--cart-ink-soft); font-size: 0.92rem;
  }
  .cart-page .summary-row span { color: var(--cart-ink-soft); }
  .cart-page .summary-row strong { color: var(--cart-ink); font-weight: 500; }
  .cart-page .summary-row-total {
    display: flex; justify-content: space-between; align-items: baseline;
    background: transparent;
    border: 0; border-top: 1px solid var(--cart-line); border-radius: 0;
    padding: 20px 0 4px; margin-top: 8px;
    font-size: 0.92rem; font-family: var(--cart-sans);
  }
  .cart-page .summary-row-total span { color: var(--cart-ink-soft); font-size: 0.92rem; font-family: var(--cart-sans); font-weight: 400; }
  .cart-page .summary-row-total strong { color: var(--cart-ink); font-weight: 500; }

  /* Coupon row */
  .cart-page .coupon-form {
    display: flex; align-items: stretch; gap: 10px;
    margin: 28px 0 22px;
  }
  .cart-page .coupon-form .store-field { flex: 1 1 auto; margin: 0; }
  .cart-page .coupon-form .store-field span { display: none; }
  .cart-page .coupon-input-wrap { position: relative; margin: 0; }
  .cart-page .coupon-input-wrap i { display: none; }
  .cart-page .coupon-form input {
    width: 100%; padding: 11px 14px;
    border: 1px solid var(--cart-line); border-radius: 2px;
    background: #fff; font-family: var(--cart-sans);
    font-size: 0.85rem; letter-spacing: 0.04em; box-sizing: border-box;
    transition: border-color .2s;
  }
  .cart-page .coupon-form input::placeholder { text-transform: uppercase; color: var(--cart-mute); letter-spacing: 0.12em; font-size: 0.72rem; }
  .cart-page .coupon-form input:focus { outline: none; border-color: var(--cart-gold); }
  .cart-page .coupon-form .btn-outline-gold { flex: 0 0 auto; width: auto; align-self: auto; padding: 11px 18px; }
  .cart-page .coupon-form .summary-pill { flex: 1 1 auto; margin: 0; align-self: center; }

  /* ---- Buttons — classic, refined hierarchy ---- */
  .cart-page .btn-gold {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    background: var(--cart-ink); color: #fff;
    border: 1px solid var(--cart-ink); border-radius: 2px;
    font-family: var(--cart-sans);
    font-size: 0.74rem; letter-spacing: 0.18em; font-weight: 600;
    padding: 16px 24px; text-transform: uppercase;
    text-decoration: none; transition: background .3s, color .3s, border-color .3s;
    cursor: pointer; width: 100%; box-sizing: border-box;
  }
  .cart-page .btn-gold:hover { background: var(--cart-gold); border-color: var(--cart-gold); color: #fff; }

  .cart-page .btn-dark-green {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    background: transparent; color: var(--cart-ink);
    border: 1px solid var(--cart-line); border-radius: 2px;
    font-family: var(--cart-sans);
    font-size: 0.72rem; letter-spacing: 0.18em; font-weight: 600;
    padding: 14px 26px; text-transform: uppercase;
    text-decoration: none; transition: border-color .3s, color .3s; cursor: pointer;
  }
  .cart-page .btn-dark-green:hover { border-color: var(--cart-ink); color: var(--cart-ink); }

  .cart-page .btn-outline-gold {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: transparent; color: var(--cart-ink);
    border: 1px solid var(--cart-ink); border-radius: 2px;
    font-family: var(--cart-sans);
    font-size: 0.68rem; letter-spacing: 0.16em; font-weight: 600;
    padding: 11px 18px; text-transform: uppercase;
    text-align: center; text-decoration: none;
    transition: background .3s, color .3s; cursor: pointer; width: auto; box-sizing: border-box;
  }
  .cart-page .btn-outline-gold:hover { background: var(--cart-ink); color: #fff; }

  .cart-page .summary-pill {
    background: #fff; border: 1px dashed var(--cart-gold);
    color: var(--cart-gold); border-radius: 2px;
    padding: 11px 14px; text-align: center;
    font-weight: 600; letter-spacing: 0.06em; font-size: 0.8rem;
  }

  /* ---- Recommended section header (uses page fonts) ---- */
  .cart-page .sec-hdr-premium .sec-hdr-title-row h2,
  .cart-page .commerce-related h2 {
    font-family: var(--cart-serif);
  }

  /* ============================================================
     RESPONSIVE
     ============================================================ */
  @media (max-width: 1100px) {
    .cart-page .cart-shell .container { width: min(100%, calc(100vw - 40px)); max-width: min(100%, calc(100vw - 40px)); }
    .cart-page .cart-layout { grid-template-columns: minmax(0, 1fr) 320px; gap: 40px; }
    .cart-page .cart-line-copy h2 { font-size: 1.5rem; }
  }
  /* Touch targets. These text links are 12-16px tall, which is untappable on a
     finger-driven device; every width a tablet or phone can report gets them
     sized up, not just the ones where the layout also stacks. */
  @media (max-width: 1024px) {
    .cart-page .cart-line-actions .store-link-btn,
    .cart-page .cart-footer-actions .store-link-btn {
      display: flex; align-items: center; justify-content: center;
      min-height: 42px;
    }
    .cart-page .store-breadcrumbs .container a,
    .cart-page .store-breadcrumbs .container strong {
      display: inline-flex; align-items: center; min-height: 40px;
    }
  }
  @media (max-width: 900px) {
    .cart-page .cart-shell .container { width: min(100%, calc(100vw - 32px)); max-width: min(100%, calc(100vw - 32px)); }
    .cart-page .cart-layout { grid-template-columns: minmax(0, 1fr); gap: 36px; }
    .cart-page .summary-card { position: static; }
    .cart-page .cart-line-card { gap: 22px; }
    .cart-page .cart-line-media { flex: 0 0 auto; }
    .cart-page .cart-media-tile { width: 110px; height: 110px; }
    .cart-page .cart-line-actions {
      flex-direction: row; align-items: center; justify-content: space-between;
      width: 100%; border-top: 1px solid var(--cart-line); padding-top: 18px; margin-top: 4px; gap: 16px;
      /* The desktop 150px floor plus a nowrap qty + total + Remove row gives this
         a 302px min-content, which pushes the card past its grid track. */
      min-width: 0; flex-wrap: wrap;
    }
    /* Remove drops to its own row once the qty + total pair fills the first one;
       as a bare 12px text link it is not tappable, so make it a real target. */
    .cart-page .cart-line-actions .store-link-btn {
      flex: 1 1 100%;
      display: flex; align-items: center; justify-content: center;
      min-height: 42px; margin-top: 2px;
      border: 1px solid var(--cart-line); border-radius: 2px;
    }
    .cart-page .cart-line-price-stack { flex-direction: row; align-items: baseline; gap: 8px; margin-left: auto; }
    .cart-page .cart-footer-actions { flex-direction: column; gap: 16px; align-items: stretch; }
    .cart-page .btn-dark-green { width: 100%; }
  }
  @media (max-width: 620px) {
    /* Spec box is single-column at every width now, so no grid / border-right /
       wide-row-padding overrides are needed (the old ones stripped padding on
       the full-width rows and misaligned them on mobile). */
    .cart-page .cart-line-card { flex-direction: column; }
    .cart-page .cart-line-media { width: 100%; flex: none; }
    .cart-page .cart-media-tile { width: 120px; height: 120px; }
    .cart-page .coupon-form { flex-wrap: wrap; }
    /* Coupon input and its button share a wrapping flex row; let each own a full
       row so the button is not left as a stub next to a full-width field. */
    .cart-page .coupon-form > * { flex: 1 1 100%; }
    .cart-page .btn-outline-gold { width: 100%; min-height: 44px; }
    /* Once the card stacks, the details column is the only thing in its row, so
       the desktop 360px flex-basis becomes a floor that both overflows the
       container and pads the card with ~190px of dead space. */
    .cart-page .cart-line-copy { flex: 1 1 auto; width: 100%; }
  }
</style>

<script>
  /* Inject the elegant serif (Cormorant Garamond) only on the cart page. */
  (function () {
    if (document.getElementById('cart-serif-font')) return;
    var l = document.createElement('link');
    l.id = 'cart-serif-font';
    l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&display=swap';
    document.head.appendChild(l);
  })();
</script>

<div class="store-breadcrumbs">
  <div class="container">
    <a href="<?= h(resolve_link('/')) ?>">Home</a>
    <span>/</span>
    <strong>Your Cart</strong>
  </div>
</div>

<section class="collection-hero reveal-in" style="padding-bottom: 0;">
  <div class="container">
    <h1>Your selected pieces</h1>
    <p>Review selections, apply your coupon, and move into a refined checkout flow.</p>
    <div class="hero-ornament">
      <span class="hero-ornament-line"></span>
      <i class="far fa-gem"></i>
      <span class="hero-ornament-line"></span>
    </div>
  </div>
</section>

<section class="cart-shell reveal-in" style="padding-top: 20px; padding-bottom: 80px;">
  <div class="container">

    <?php if ($flash !== null): ?>
      <div style="text-align:center;"><div class="store-flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div></div><?php /*  */ endif; ?>

    <?php if ($cart['items'] === []): ?>
      <div class="cart-empty-state">
        <h3>Your cart is empty</h3>
        <p>Start with the catalog and add products with your preferred color and size.</p>
        <a class="btn-gold" href="<?= h(resolve_link('/shop/')) ?>" style="width: auto;">Browse Collection</a>
      </div>
    <?php else: ?>
      <div class="cart-layout">
        <div class="cart-lines-panel">
          <form method="post" action="<?= h(resolve_link('/cart/')) ?>" class="cart-lines-form">
            <?php csrf_field(); ?>
            <div class="cart-line-list">
              <?php foreach ($cart['items'] as $line): ?>
                <article class="cart-line-card">
                  <a href="<?= h($line['url']) ?>" class="cart-line-media">
                    <div class="cart-media-tile">
                      <strong>Ring</strong>
                      <?= store_media_markup((string) ($line['ring_media'] ?? ''), (string) ($line['ring_media_alt'] ?? ($line['product']['name'] ?? 'Ring')), 'cart-line-media-asset') ?>
                    </div>
                    <?php if ((string) ($line['diamond_image'] ?? '') !== ''): ?>
                      <div class="cart-media-tile">
                        <strong>Diamond</strong>
                        <?= store_media_markup((string) ($line['diamond_image'] ?? ''), (string) ($line['diamond_title'] ?? 'Diamond'), 'cart-line-media-asset') ?>
                      </div>
                    <?php endif; ?>
                  </a>
                  <div class="cart-line-copy">
                    <span class="cart-line-type"><?= h($line['product']['product_type']) ?> / <?= h($line['product']['category']) ?></span>
                    <h2><a href="<?= h($line['url']) ?>"><?= h($line['product']['name']) ?></a></h2>
                    <div class="cart-line-specs">
                      <div class="cart-line-spec"><?php $cartMetalHex = (string) ($line['metal_color_hex'] ?? ''); ?><i class="far fa-gem"></i><span>Metal</span><strong><?php if ($cartMetalHex !== ''): ?><span class="cart-metal-dot" style="background:<?= h($cartMetalHex) ?>;" aria-hidden="true"></span><?php endif; ?><?= h((string) ($line['metal_label'] ?? '')) ?></strong></div>
                      <?php if ((string) ($line['band_claw_metal_label'] ?? '') !== ''): ?>
                        <div class="cart-line-spec is-wide"><i class="fas fa-ring"></i><span>Band / Claw</span><strong><?= h((string) ($line['band_claw_metal_label'] ?? '')) ?></strong></div>
                      <?php endif; ?>
                      <?php foreach (catalog_addon_groups() as $cartAddonKey => $cartAddonMeta): ?>
                        <?php $cartAddonLabel = clean_string((string) ($line['addon_labels'][$cartAddonKey] ?? ''), 120); ?>
                        <?php if ($cartAddonLabel === '') { continue; } ?>
                        <div class="cart-line-spec is-wide"><i class="fas fa-plus-circle"></i><span><?= h((string) $cartAddonMeta['label']) ?></span><strong><?= h($cartAddonLabel) ?></strong></div>
                      <?php endforeach; ?>
                      <?php if ((string) ($line['diamond_title'] ?? '') !== ''): ?>
                        <div class="cart-line-spec"><i class="far fa-gem"></i><span>Diamond</span><strong><?= h((string) ($line['diamond_title'] ?? '')) ?></strong></div>
                      <?php endif; ?>
                      <?php if ((string) ($line['size'] ?? '') !== ''): ?>
                        <div class="cart-line-spec"><i class="fas fa-compress-arrows-alt"></i><span>Size</span><strong><?= h((string) ($line['size'] ?? '')) ?></strong></div>
                      <?php endif; ?>
                      <?php $cartDeliveryLabel = trim((string) ($line['delivery_label'] ?? '')); ?>
                      <?php if ($cartDeliveryLabel === ''): ?>
                        <?php $cartDeliveryLabel = ($line['delivery_surcharge'] ?? 0) > 0 ? 'Express Delivery' : 'Basic Delivery'; ?>
                      <?php endif; ?>
                      <div class="cart-line-spec"><i class="fas fa-truck"></i><span>Delivery</span><strong><?= h($cartDeliveryLabel) ?></strong></div>
                    </div>
                    <?php if (($line['delivery_surcharge'] ?? 0) > 0): ?>
                      <p class="cart-line-note"><?= h($cartDeliveryLabel) ?>: <?= h((string) $line['delivery_surcharge_label']) ?> each</p>
                    <?php endif; ?>
                  </div>
                  <div class="cart-line-actions">
                    <div class="store-qty" data-qty-wrap>
                      <button type="button" data-qty-step="-1">−</button>
                      <input type="number" min="1" max="99" name="quantities[<?= h($line['key']) ?>]" value="<?= h((string) $line['quantity']) ?>" data-qty-input>
                      <button type="button" data-qty-step="1">+</button>
                    </div>
                    <div class="cart-line-price-stack">
                      <span>Total</span>
                      <strong><?= h($line['line_total_label']) ?></strong>
                    </div>
                    <button type="submit" name="remove_key" value="<?= h($line['key']) ?>" class="store-link-btn">Remove</button>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            
            <div class="cart-footer-actions">
                <a class="store-link-btn" style="text-decoration: none;" href="<?= h(resolve_link('/shop/')) ?>"><i class="fas fa-arrow-left"></i> CONTINUE SHOPPING</a>
                <button type="submit" name="action" value="update-cart" class="btn-dark-green">UPDATE CART <i class="fas fa-shopping-bag"></i></button>
            </div>
          </form>
        </div>

        <aside class="summary-panel">
          <div class="summary-card">
            <h2>Order Summary</h2><div class="summary-ornament"><span class="summary-ornament-line"></span><i class="fas fa-star-of-life"></i><span class="summary-ornament-line"></span></div>
            <div class="summary-row"><span>Subtotal</span><strong><?= h($cart['subtotal_label']) ?></strong></div>
            <div class="summary-row"><span><?= h((string) ($cart['delivery_summary_label'] ?? 'Delivery')) ?></span><strong><?= h((string) ($cart['delivery_total_label'] ?? 'Free')) ?></strong></div>
            <div class="summary-row"><span>Shipping</span><strong><?= h((string) $cart['shipping_label']) ?></strong></div>
            <?php if ($cart['discount'] > 0): ?>
              <div class="summary-row"><span>Discount</span><strong>-<?= h($cart['discount_label']) ?></strong></div>
            <?php endif; ?>
            <div class="summary-row summary-row-total"><span>Total</span><strong><?= h($cart['total_label']) ?></strong></div>

            <form method="post" action="<?= h(resolve_link('/cart/')) ?>" class="coupon-form">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="<?= $cart['coupon_code'] !== '' ? 'clear-coupon' : 'apply-coupon' ?>">
              <?php if ($cart['coupon_code'] === ''): ?>
                <label class="store-field">
                  <span>Coupon Code</span>
                  <div class="coupon-input-wrap"><i class="fas fa-tag"></i><input type="text" name="coupon_code" placeholder="Enter coupon code"></div>
                </label>
                <button type="submit" class="btn-outline-gold">APPLY COUPON</button>
              <?php else: ?>
                <div class="summary-pill"><?= h($cart['coupon_code']) ?></div>
                <button type="submit" class="btn-outline-gold">REMOVE COUPON</button>
              <?php endif; ?>
            </form>

            <a class="btn-gold" href="<?= h(resolve_link('/checkout/')) ?>"><i class="fas fa-lock"></i> <?= customer_is_logged_in() ? 'PROCEED TO CHECKOUT' : 'SIGN IN FOR CHECKOUT' ?></a>
          </div>
        </aside>
      </div>
    <?php endif; ?>

    <div class="commerce-related" style="margin-top: 80px;">
      <div class="sec-hdr-premium">
        <span class="shop-style-kicker">We Think You'll Love</span>
        <div class="sec-hdr-title-row">
            <span class="sec-line"></span>
            <h2>Recommended Pieces</h2>
            <span class="sec-line"></span>
        </div>
      </div>
      <div class="shop-product-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 30px;">
        <?php foreach ($recommended as $product): ?>
          <?php render_product_card($product); ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
