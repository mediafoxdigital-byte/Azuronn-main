<?php
$settings = site_content()['settings'];
$headerCart = cart_state();
$headerCustomer = current_customer();
$headerWishlistCount = customer_wishlist_count($headerCustomer);
$headerSearchQuery = clean_string((string) ($_GET['q'] ?? ''), 120);
$headerSearchIndexJson = json_encode(
    header_search_index(),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php e(isset($pageTitle) && is_string($pageTitle) && $pageTitle !== '' ? $pageTitle : SITE_NAME . ' - ' . SITE_TAGLINE); ?></title>
<link rel="icon" type="image/png" href="<?php e(resolve_link('/assets/favicon.png')); ?>">
<link rel="shortcut icon" href="<?php e(resolve_link('/assets/favicon.png')); ?>">
<?php
  // ── Performance: warm the connections to the third-party font/icon hosts so
  //    the TLS+DNS handshake overlaps page parsing instead of stalling it.
  //    crossorigin is REQUIRED on the gstatic preconnect (fonts are fetched
  //    cross-origin anonymously) or the hint is wasted.
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<link rel="dns-prefetch" href="https://fonts.gstatic.com">
<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
<?php
  // ── Performance: load the two THIRD-PARTY stylesheets without blocking the
  //    first paint (media="print" + onload swap is the no-JS-safe async-CSS
  //    pattern; the <noscript> below restores them for clients without JS).
  //    The LOCAL style.css stays render-blocking on purpose so the page never
  //    flashes unstyled. Output is identical once loaded — only timing changes.
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
<noscript>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</noscript>
<link rel="stylesheet" href="<?php e(asset_url('assets/css/style.css')); ?>">
<?php if (isset($bodyClass) && $bodyClass === 'product-page'): ?><link rel="stylesheet" href="<?php e(asset_url('assets/css/product.css')); ?>"><?php endif; ?>
<?php if (isset($bodyClass) && $bodyClass === 'shop-page'): ?><link rel="stylesheet" href="<?php e(asset_url('assets/css/shop-listing.css')); ?>"><?php endif; ?>
<link rel="stylesheet" href="<?php e(asset_url('assets/css/responsive.css')); ?>">
</head>
<body class="<?php e(isset($bodyClass) && is_string($bodyClass) ? $bodyClass : ''); ?>">

<style>
.header-luxury-wrapper, .site-header, #main-site-header, .promo-strip {
    background: #ffffff !important;
}

/* Everything below is the DESKTOP header/mega-menu layout. It is gated behind
   min-width:1025px because the !important declarations (static positioning,
   fixed paddings, absolutely-positioned mega panels) would otherwise beat the
   tablet/mobile rules in responsive.css. Tablets and phones get their own
   layout there; desktop output is byte-identical to before. */
@media (min-width: 1025px) {

/* Ensure navigation parents do not trap absolute positioning or create offset containing blocks */
.mnav-item,
nav.mnav,
nav.mnav.luxury-mnav,
.luxury-mnav-pill,
.d-flex-nav,
.header-grid-nav {
    position: static !important;
    transform: none !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

#main-site-header:not(.is-sticky),
.site-header:not(.is-sticky) {
    position: relative !important;
}

/* Category Link Styling */
.mnav-item > a, .mnav.luxury-mnav .mnav-item > a, .luxury-mnav-pill .mnav-item > a {
    font-family: 'Montserrat', var(--sans), sans-serif !important;
    font-size: 15.5px !important;
    font-weight: 700 !important;
    letter-spacing: 1.25px !important;
    color: #111 !important;
    text-transform: uppercase !important;
    padding: 13px 32px !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    position: relative !important;
    transition: color 0.25s ease !important;
}

/* Completely eliminate old or conflicting pseudo-element underlines from style.css */
.mnav-item > a::before,
.mnav-item > a::after {
    content: none !important;
    display: none !important;
}

/* Clean, crisp underline attached to span.nav-underline that ONLY expands on hover */
.mnav-item > a .nav-underline {
    display: block !important;
    position: absolute !important;
    bottom: 5px !important;
    left: 50% !important;
    width: 0 !important;
    height: 2px !important;
    background: #c9a96e !important;
    transition: width 0.28s cubic-bezier(0.16, 1, 0.3, 1) !important;
    transform: translateX(-50%) !important;
    pointer-events: none !important;
}
.mnav-item:hover > a .nav-underline,
.mnav-item.is-open > a .nav-underline {
    width: calc(100% - 36px) !important;
}
.mnav-item > a:hover,
.mnav-item.is-open > a,
.luxury-mnav-pill .mnav-item > a:hover {
    color: #c9a96e !important;
    background: transparent !important;
}

/* ── Full Height Stretch & Hover Bridge to Prevent Menu Drop ── */
.header-grid-nav {
    display: flex !important;
    align-items: stretch !important;
}
.mnav-item {
    display: flex !important;
    align-items: center !important;
}

/* Invisible Bridge extending 40px up from dropdown to catch cursor leaving the link */
.mega-drop::before,
.mega-drop-wide::before,
.luxury-mnav .mnav-item.has-mega .mega-drop-wide::before,
.luxury-mnav .mnav-item.has-mega .mega-drop::before {
    content: '' !important;
    display: block !important;
    position: absolute !important;
    top: -40px !important;
    left: 0 !important;
    right: 0 !important;
    height: 40px !important;
    background: transparent !important;
    z-index: 99999 !important;
}

/* ── Perfectly Aligned, Full-Width Centered Mega Dropdown ── */
.mega-drop,
.mega-drop-wide,
.luxury-mnav .mnav-item.has-mega .mega-drop-wide,
.luxury-mnav .mnav-item.has-mega .mega-drop {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    transform: translateY(10px) !important;
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
    background: #ffffff !important;
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.08) !important;
    border-bottom: 1px solid #eaeaea !important;
    border-top: 1px solid #eaeaea !important;
    margin: 0 !important;
    padding: 0 !important;
    z-index: 9999 !important;
    /* 180ms closing buffer prevents accidental closing when moving across gaps */
    transition: opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1), transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), visibility 0s linear 0.18s !important;
}

/* On Hover: Reveal smoothly immediately and stay open while inside */
.mnav-item:hover .mega-drop,
.mnav-item:hover .mega-drop-wide,
.luxury-mnav .mnav-item.has-mega:hover .mega-drop-wide,
.luxury-mnav .mnav-item.has-mega:hover .mega-drop,
.mnav-item.is-open .mega-drop,
.mnav-item.is-open .mega-drop-wide {
    opacity: 1 !important;
    visibility: visible !important;
    transform: translateY(0) !important;
    pointer-events: auto !important;
    transition: opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1), transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), visibility 0s linear 0s !important;
}

/* Inside the Mega Menu: Center exactly under the website container */
.luxury-mega-menu {
    display: grid !important;
    gap: 40px !important;
    padding: 35px 30px !important;
    max-width: 1250px !important;
    margin: 0 auto !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}


/* ── Austen & Blake Luxury Header Styles ── */
.header-flex-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    grid-template-rows: auto auto;
    grid-template-areas:
        "left center right"
        "nav nav nav";
    align-items: center;
    width: 100%;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.header-grid-left {
    grid-area: left;
    justify-self: start;
    align-self: center;
    padding: 18px 0;
    transition: all 0.3s ease;
}

.header-grid-center {
    grid-area: center;
    justify-self: center;
    align-self: center;
    padding: 18px 0;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.header-grid-right {
    grid-area: right;
    justify-self: end;
    align-self: center;
    padding: 18px 0;
    transition: all 0.3s ease;
}

.header-grid-nav {
    grid-area: nav;
    justify-self: center;
    align-self: center;
    width: 100%;
    border-top: 1px solid #eaeaea;
    padding: 8px 0;
    display: flex;
    justify-content: center;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.main-header-logo-img {
    height: 40px;
    max-width: 180px;
    width: auto;
    object-fit: contain;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.appointment-link {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'Montserrat', var(--sans), sans-serif;
    font-size: 14.5px;
    font-weight: 700;
    color: #111;
    text-decoration: none;
    letter-spacing: 0.6px;
    transition: color 0.2s ease;
}
.appointment-link:hover {
    color: #c9a96e;
}
.appointment-link i {
    font-size: 18px;
    color: #11261f;
    transition: transform 0.2s ease;
}
.appointment-link:hover i {
    transform: scale(1.1);
}

/* Ensure no parent traps position:fixed or creates offset containing blocks */
html, body, .header-luxury-wrapper, .new-header-overrides {
    transform: none !important;
    filter: none !important;
    perspective: none !important;
}

/* ── Sticky Scrolled State ── */
#main-site-header.is-sticky {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    z-index: 99999 !important;
    background: #ffffff !important;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08) !important;
    border-bottom: 1px solid #eaeaea !important;
    animation: luxuryStickySlide 0.38s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
}

@keyframes luxuryStickySlide {
    0% { transform: translateY(-100%); }
    100% { transform: translateY(0); }
}

#main-site-header.is-sticky .container {
    padding-left: 5px !important;
}

#main-site-header.is-sticky .header-flex-grid {
    grid-template-columns: auto 1fr auto;
    grid-template-rows: auto;
    grid-template-areas: "center nav right";
}

#main-site-header.is-sticky .header-grid-left {
    display: none !important;
}

#main-site-header.is-sticky .header-grid-center {
    padding: 10px 0 !important;
    justify-self: start !important;
    transform: translateX(-18px) !important;
}

#main-site-header.is-sticky .main-header-logo-img {
    height: 28px !important;
    max-width: 140px !important;
}

#main-site-header.is-sticky .header-grid-nav {
    border-top: none;
    padding: 0;
    margin: 0;
}

#main-site-header.is-sticky .header-grid-nav .mnav-item > a {
    padding: 11px 22px !important;
    font-size: 14.5px !important;
}

#main-site-header.is-sticky .header-grid-right {
    padding: 10px 0;
}

/* Wider cart button in sticky mode only */
#main-site-header.is-sticky .header-cart-link {
    padding: 8px 32px !important;
    min-width: 154px !important;
    white-space: nowrap !important;
}

} /* end @media (min-width: 1025px) — desktop header layout */
</style>

<div class="header-luxury-wrapper new-header-overrides" style="background:#ffffff !important;">
  <div class="promo-strip" style="background:#ffffff !important; border-bottom:1px solid #eaeaea;">
    <div class="container" style="text-align:center; padding: 11px 0;">
      <div class="promo-item" style="width:100%; display:flex; justify-content:center; align-items:center; flex-wrap:wrap; font-family:'Montserrat', var(--sans), sans-serif; font-size:13.5px; font-weight:600; color:#222; letter-spacing:0.5px;">
        <?= $settings['top_bar_text'] ?? '<div class="promo-item" style="display:inline-block;"><i class="fas fa-gem" style="color: #c9a96e; margin-right:6px;"></i> FREE INSURED SHIPPING <small style="color:#666; font-weight:500;">Worldwide</small></div> <span style="color:#e0e0e0; margin:0 22px;">|</span> <div class="promo-item" style="display:inline-block;"><i class="fas fa-sync-alt" style="color: #c9a96e; margin-right:6px;"></i> 30 DAYS EASY RETURNS <small style="color:#666; font-weight:500;">Hassle-Free</small></div> <span style="color:#e0e0e0; margin:0 22px;">|</span> <div class="promo-item" style="display:inline-block;"><i class="fas fa-shield-alt" style="color: #c9a96e; margin-right:6px;"></i> LIFETIME WARRANTY <small style="color:#666; font-weight:500;">On All Pieces</small></div>' ?>
      </div>
    </div>
  </div>

  <div id="header-sticky-placeholder" style="height:0px; width:100%; transition:height 0s;"></div>

  <header id="main-site-header" class="site-header luxury-site-header" style="background:#ffffff !important; border-bottom:1px solid #eaeaea; padding: 0;">
    <div class="container">
      <div class="header-flex-grid">
        <div class="header-grid-left">
          <a href="<?php e(resolve_link('/appointment/')); ?>" class="appointment-link">
            <i class="far fa-calendar-alt"></i>
            <span>Request an appointment</span>
          </a>
        </div>
        <div class="header-grid-center">
          <a href="<?php e(resolve_link('/')); ?>" class="luxury-logo-box">
            <img src="<?php e(resolve_link(SITE_LOGO_PATH)); ?>" alt="<?php e(SITE_NAME . ' Logo'); ?>" class="main-header-logo-img" decoding="async" fetchpriority="high">
          </a>
        </div>
        <div class="header-grid-right">
          <div class="header-actions-group" style="display:flex; align-items:center; gap:26px;">
            <form class="luxury-search-form" action="<?php e(resolve_link('/shop/')); ?>" method="get" role="search" data-header-search style="position:relative; display:flex; align-items:center;">
              <input
                type="text"
                name="q"
                value="<?php e($headerSearchQuery); ?>"
                placeholder="Search..."
                autocomplete="off"
                spellcheck="false"
                data-header-search-input
                style="width:0; padding:0; opacity:0; border:none; outline:none; transition:width 0.3s, opacity 0.3s; font-family:var(--sans); font-size:14px; background:transparent;"
                onfocus="this.style.width='160px'; this.style.opacity='1'; this.style.padding='5px 10px'; this.style.borderBottom='1px solid #ccc';"
                onblur="if(this.value===''){this.style.width='0'; this.style.opacity='0'; this.style.padding='0'; this.style.borderBottom='none';}"
              >
              <button class="luxury-search-submit-btn" type="submit" aria-label="Search catalogue" style="background:transparent; border:none; cursor:pointer; padding:5px; color:#333; display:flex; align-items:center; justify-content:center;" onclick="var inp=this.previousElementSibling; if(inp.style.width==='0px'||inp.style.width===''){inp.focus();return false;}">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              </button>
              <div class="luxury-search-dropdown" data-header-search-dropdown hidden></div>
            </form>
            
            <a href="<?php e(resolve_link('/wishlist/')); ?>" style="color:#333; position:relative; display:flex; align-items:center; justify-content:center;" title="Wishlist">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="<?= $headerWishlistCount > 0 ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
              <?php if ($headerWishlistCount > 0): ?><span style="position:absolute; top:-6px; right:-10px; background:#e00000; color:#fff; border-radius:50%; font-size:10px; font-weight:bold; min-width:16px; height:16px; display:flex; align-items:center; justify-content:center; padding:0 3px; font-family:var(--sans);"><?= h((string) $headerWishlistCount) ?></span><?php endif; ?>
            </a>
            <a href="<?php e(resolve_link('/account/')); ?>" style="color:#333; display:flex; align-items:center; justify-content:center;" title="Account">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4-4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            </a>
            
            <a href="<?php e(resolve_link('/cart/')); ?>" class="header-cart-link" data-cart-count="<?php e((string) $headerCart['count']); ?>" style="background:#11261f; color:#fff; border-radius:30px; padding:8px 18px; display:flex; align-items:center; justify-content:center; gap:10px; text-decoration:none; font-family:var(--sans); font-weight:700; font-size:15px; margin-left: 5px;">
              <i class="fas fa-shopping-bag" style="color: #c9a96e; font-size: 18px;"></i>
              <span><?php e($headerCart['total_label']); ?> (<?php e((string) $headerCart['count']); ?>)</span>
            </a>
          </div>
        </div>
        <div class="header-grid-nav">
          <?php require_once __DIR__ . '/partials/nav.php'; ?>
        </div>
      </div>
    </div>
  </header>
  <script id="header-search-index" type="application/json"><?php e($headerSearchIndexJson !== false ? $headerSearchIndexJson : '[]'); ?></script>
  <script>
  (function() {
    var header = document.getElementById('main-site-header');
    var placeholder = document.getElementById('header-sticky-placeholder');
    if (!header || !placeholder) return;
    
    function checkHeaderScroll() {
      // Sticky shrink is a desktop-only behaviour; the tablet/mobile header is a
      // compact single row already and re-flowing it on scroll causes jumps.
      if (!window.matchMedia('(min-width: 1025px)').matches) {
        if (header.classList.contains('is-sticky')) {
          header.classList.remove('is-sticky');
          placeholder.style.height = '0px';
        }
        return;
      }
      var scrollY = window.pageYOffset || document.documentElement.scrollTop;
      if (scrollY > 185) {
        if (!header.classList.contains('is-sticky')) {
          placeholder.style.height = header.offsetHeight + 'px';
          header.classList.add('is-sticky');
        }
      } else if (scrollY <= 135) {
        if (header.classList.contains('is-sticky')) {
          header.classList.remove('is-sticky');
          placeholder.style.height = '0px';
        }
      }
    }
    window.addEventListener('scroll', checkHeaderScroll, { passive: true });
    window.addEventListener('resize', function() {
      if (!window.matchMedia('(min-width: 1025px)').matches) {
        checkHeaderScroll();
        return;
      }
      if (header.classList.contains('is-sticky')) {
        header.classList.remove('is-sticky');
        placeholder.style.height = header.offsetHeight + 'px';
        header.classList.add('is-sticky');
      }
    }, { passive: true });
    checkHeaderScroll();
  })();
  </script>
</div>
