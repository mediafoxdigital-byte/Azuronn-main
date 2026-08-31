// Tab switching
function switchTab(btn, id) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(id).classList.add('active');
}

// Scroll-to-top button
window.addEventListener('scroll', () => {
  const scrollTop = document.getElementById('scrollTop');
  if (scrollTop) {
      scrollTop.classList.toggle('show', window.scrollY > 400);
  }
});

// Preload hover images for instant swap
document.addEventListener('DOMContentLoaded', () => {
  // Homepage cards can reference hosting uploads or remote URLs. If one later
  // becomes unavailable, replace it once with its stable local fallback so the
  // card never collapses into a broken-image icon and alt text.
  const bindImageFallbacks = () => {
    document.querySelectorAll('img[data-image-fallback]').forEach((image) => {
      const fallback = image.getAttribute('data-image-fallback') || '';
      if (!fallback) return;

      const useFallback = () => {
        if (image.dataset.fallbackApplied === '1') return;
        image.dataset.fallbackApplied = '1';
        image.src = fallback;
      };

      image.addEventListener('error', useFallback, { once: true });
      if (image.complete && image.naturalWidth === 0) useFallback();
    });
  };

  bindImageFallbacks();

  document.querySelectorAll('.img-hover').forEach(img => {
    const src = img.getAttribute('src');
    if (src) {
      const preload = new Image();
      preload.src = src;
    }
  });

  const normalizeHeaderSearchTerm = (value) => (
    (value || '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .trim()
      .replace(/\s+/g, ' ')
  );

  const tokenizeHeaderSearch = (value) => {
    const normalized = normalizeHeaderSearchTerm(value);
    return normalized === '' ? [] : normalized.split(' ');
  };

  const escapeHeaderSearchHtml = (value) => (
    String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;')
  );

  const headerSearchIndexNode = document.getElementById('header-search-index');
  let headerSearchIndex = [];
  const diamondShapeTerms = new Set(['round', 'oval', 'cushion', 'princess', 'emerald', 'pear', 'marquise', 'radiant', 'asscher', 'heart']);

  if (headerSearchIndexNode) {
    try {
      const parsedIndex = JSON.parse(headerSearchIndexNode.textContent || '[]');
      if (Array.isArray(parsedIndex)) {
        headerSearchIndex = parsedIndex.map((item) => {
          const label = String(item.label || '');
          const searchText = String(item.search_text || '');
          return {
            ...item,
            _labelNormalized: normalizeHeaderSearchTerm(label),
            _searchNormalized: normalizeHeaderSearchTerm(searchText),
            _labelTokens: tokenizeHeaderSearch(label),
            _searchTokens: tokenizeHeaderSearch(searchText),
          };
        });
      }
    } catch (error) {
      headerSearchIndex = [];
    }
  }

  const scoreHeaderSearchItem = (item, query) => {
    const normalizedQuery = normalizeHeaderSearchTerm(query);
    if (!normalizedQuery) return -1;

    const queryTokens = tokenizeHeaderSearch(normalizedQuery);
    const label = item._labelNormalized;
    const haystack = item._searchNormalized;
    if (!label && !haystack) return -1;

    let score = 0;

    if (label === normalizedQuery) score += 560;
    if (haystack === normalizedQuery) score += 420;
    if (label.startsWith(normalizedQuery)) score += 300;
    if (haystack.startsWith(normalizedQuery)) score += 220;
    if (label.includes(normalizedQuery)) score += 160;
    if (haystack.includes(normalizedQuery)) score += 130;

    for (const token of queryTokens) {
      if (!token) continue;

      if (item._labelTokens.some((value) => value === token)) {
        score += 130;
        continue;
      }
      if (item._labelTokens.some((value) => value.startsWith(token))) {
        score += 104;
        continue;
      }
      if (item._searchTokens.some((value) => value === token)) {
        score += 82;
        continue;
      }
      if (item._searchTokens.some((value) => value.startsWith(token))) {
        score += 62;
        continue;
      }
      if (haystack.includes(token)) {
        score += 26;
        continue;
      }

      return -1;
    }

    const kindBoost = {
      collection: 34,
      shape: 32,
      metal: 28,
      style: 22,
      facet: 18,
      product: 12,
    };
    score += kindBoost[item.kind] || 0;

    if (queryTokens.length === 1 && diamondShapeTerms.has(queryTokens[0])) {
      if (item.kind === 'shape' && item._labelTokens[0] === queryTokens[0]) {
        score += 1800;
      }
      if (item.kind === 'facet' && item._labelNormalized === queryTokens[0]) {
        score -= 900;
      }
    }

    if (normalizedQuery.length <= 2) {
      score += item.kind === 'product' ? -18 : 24;
    }

    if (item.kind === 'product' && label.startsWith(normalizedQuery)) {
      score += 40;
    }

    return score;
  };

  document.querySelectorAll('[data-header-search]').forEach((searchForm) => {
    const searchInput = searchForm.querySelector('[data-header-search-input]');
    const searchDropdown = searchForm.querySelector('[data-header-search-dropdown]');
    const fallbackUrl = searchForm.getAttribute('action') || '/shop/';

    if (!searchInput || !searchDropdown) return;

    let matches = [];
    let activeIndex = -1;

    const buildFallbackSearchUrl = (query) => {
      const separator = fallbackUrl.includes('?') ? '&' : '?';
      return `${fallbackUrl}${separator}q=${encodeURIComponent(query)}`;
    };

    const closeSearchDropdown = () => {
      searchDropdown.hidden = true;
      searchDropdown.innerHTML = '';
      searchForm.classList.remove('is-open');
      matches = [];
      activeIndex = -1;
    };

    const renderSearchResults = () => {
      if (!matches.length) {
        const fallbackSearchUrl = buildFallbackSearchUrl(searchInput.value.trim());
        searchDropdown.innerHTML = `
          <div class="luxury-search-results">
            <a href="${escapeHeaderSearchHtml(fallbackSearchUrl)}" class="luxury-search-item luxury-search-cta is-active" data-search-fallback>
              <span class="luxury-search-thumb-fallback"><i class="fas fa-search" aria-hidden="true"></i></span>
              <span class="luxury-search-copy">
                <strong>Search for "${escapeHeaderSearchHtml(searchInput.value.trim())}"</strong>
                <span>Browse the full catalogue</span>
              </span>
              <span class="luxury-search-arrow" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
            </a>
          </div>
        `;
        searchDropdown.hidden = false;
        searchForm.classList.add('is-open');
        return;
      }

      const resultMarkup = matches.map((item, index) => {
        const thumbMarkup = item.image
          ? `<span class="luxury-search-thumb"><img src="${escapeHeaderSearchHtml(item.image)}" alt="${escapeHeaderSearchHtml(item.label)}"></span>`
          : `<span class="luxury-search-thumb-fallback"><i class="far fa-gem" aria-hidden="true"></i></span>`;

        return `
          <a href="${escapeHeaderSearchHtml(item.url)}" class="luxury-search-item${index === activeIndex ? ' is-active' : ''}" data-search-item data-search-index="${index}">
            ${thumbMarkup}
            <span class="luxury-search-copy">
              <strong>${escapeHeaderSearchHtml(item.label)}</strong>
              <span>${escapeHeaderSearchHtml(item.subtitle || 'Search Result')}</span>
            </span>
            <span class="luxury-search-arrow" aria-hidden="true"><i class="fas fa-arrow-up-right-from-square"></i></span>
          </a>
        `;
      }).join('');

      searchDropdown.innerHTML = `<div class="luxury-search-results">${resultMarkup}</div>`;
      searchDropdown.hidden = false;
      searchForm.classList.add('is-open');
    };

    const updateSearchMatches = () => {
      const query = searchInput.value.trim();
      if (!query) {
        closeSearchDropdown();
        return;
      }

      matches = headerSearchIndex
        .map((item) => ({ ...item, _score: scoreHeaderSearchItem(item, query) }))
        .filter((item) => item._score > 0)
        .sort((left, right) => right._score - left._score || left.label.localeCompare(right.label))
        .slice(0, 8);

      activeIndex = matches.length ? 0 : -1;
      renderSearchResults();
    };

    const commitSearch = () => {
      const query = searchInput.value.trim();
      if (!query) {
        window.location.assign(fallbackUrl);
        return;
      }

      const destination = matches[activeIndex]?.url || matches[0]?.url || buildFallbackSearchUrl(query);
      window.location.assign(destination);
    };

    searchInput.addEventListener('input', () => {
      updateSearchMatches();
    });

    searchInput.addEventListener('focus', () => {
      if (searchInput.value.trim() !== '') {
        updateSearchMatches();
      }
    });

    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSearchDropdown();
        return;
      }

      if (event.key === 'ArrowDown') {
        if (searchDropdown.hidden) {
          updateSearchMatches();
        }
        if (!matches.length) return;
        event.preventDefault();
        activeIndex = (activeIndex + 1) % matches.length;
        renderSearchResults();
        return;
      }

      if (event.key === 'ArrowUp') {
        if (!matches.length) return;
        event.preventDefault();
        activeIndex = (activeIndex - 1 + matches.length) % matches.length;
        renderSearchResults();
        return;
      }

      if (event.key === 'Enter') {
        event.preventDefault();
        commitSearch();
      }
    });

    searchForm.addEventListener('submit', (event) => {
      event.preventDefault();
      commitSearch();
    });

    searchDropdown.addEventListener('mousedown', (event) => {
      event.preventDefault();
    });

    searchDropdown.addEventListener('click', (event) => {
      const target = event.target.closest('[data-search-item]');
      if (!target) return;
      const matchIndex = Number.parseInt(target.getAttribute('data-search-index') || '-1', 10);
      if (Number.isNaN(matchIndex) || !matches[matchIndex]) {
        const fallbackTarget = event.target.closest('[data-search-fallback]');
        if (fallbackTarget) {
          window.location.assign(buildFallbackSearchUrl(searchInput.value.trim()));
        }
        return;
      }
      window.location.assign(matches[matchIndex].url);
    });

    document.addEventListener('click', (event) => {
      if (!searchForm.contains(event.target)) {
        closeSearchDropdown();
      }
    });
  });

  document.querySelectorAll('[data-mobile-nav]').forEach((nav) => {
    // The toggle and scrim are siblings of <nav>, not descendants: below 1025px
    // the nav is translated off-screen, so a nested toggle could not be tapped.
    const toggle = document.querySelector('[data-mobile-nav-toggle]');
    const scrim = document.querySelector('[data-mobile-nav-scrim]');
    const items = Array.from(nav.querySelectorAll('.mnav-item.has-mega'));
    // Matches the responsive.css breakpoint where the drawer layout takes over.
    const mobileMedia = window.matchMedia('(max-width: 1024px)');

    if (!toggle || !items.length) return;

    const closeItems = () => {
      items.forEach((item) => {
        item.classList.remove('is-open');
        const itemToggle = item.querySelector('[data-mobile-submenu-toggle]');
        if (itemToggle) itemToggle.setAttribute('aria-expanded', 'false');
      });
    };

    const setNavState = (open) => {
      nav.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
      // Locking the body stops the page behind the drawer from scrolling.
      document.body.classList.toggle('mnav-open', open);
      if (scrim) {
        scrim.classList.toggle('is-visible', open);
      }
      if (!open) closeItems();
    };

    const closeNav = () => setNavState(false);

    const openItem = (item) => {
      items.forEach((candidate) => {
        const isTarget = candidate === item;
        candidate.classList.toggle('is-open', isTarget && !candidate.classList.contains('is-open'));
        const itemToggle = candidate.querySelector('[data-mobile-submenu-toggle]');
        if (itemToggle) {
          itemToggle.setAttribute('aria-expanded', candidate.classList.contains('is-open') ? 'true' : 'false');
        }
      });
    };

    toggle.addEventListener('click', () => {
      if (!mobileMedia.matches) return;
      setNavState(!nav.classList.contains('is-open'));
    });

    if (scrim) {
      scrim.addEventListener('click', closeNav);
    }

    items.forEach((item) => {
      const link = item.querySelector('[data-mobile-nav-link]');
      const itemToggle = item.querySelector('[data-mobile-submenu-toggle]');

      if (!link || !itemToggle) return;

      itemToggle.addEventListener('click', (event) => {
        if (!mobileMedia.matches) return;
        event.preventDefault();
        openItem(item);
      });

      link.addEventListener('click', (event) => {
        if (!mobileMedia.matches) return;
        if (!item.classList.contains('is-open')) {
          event.preventDefault();
          if (!nav.classList.contains('is-open')) {
            setNavState(true);
          }
          openItem(item);
        }
      });
    });

    document.addEventListener('click', (event) => {
      if (!mobileMedia.matches) return;
      if (nav.contains(event.target) || toggle.contains(event.target)) return;
      closeNav();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && nav.classList.contains('is-open')) closeNav();
    });

    window.addEventListener('resize', () => {
      if (!mobileMedia.matches) {
        closeNav();
      }
    });
  });

  document.querySelectorAll('[data-style-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-style-track]');
    const prev = carousel.querySelector('[data-style-prev]');
    const next = carousel.querySelector('[data-style-next]');

    if (!track || !prev || !next) return;

    const getStep = () => {
      const firstCard = track.querySelector('.shop-style-card');
      const gap = parseFloat(window.getComputedStyle(track).gap || '0') || 0;
      return firstCard ? firstCard.getBoundingClientRect().width + gap : track.clientWidth;
    };

    const moveStyles = (direction) => {
      const step = getStep();
      const maxScroll = track.scrollWidth - track.clientWidth;
      const distance = step; // Scroll exactly one item at a time

      if (direction > 0 && track.scrollLeft >= maxScroll - 4) {
        track.scrollTo({ left: 0, behavior: 'smooth' });
        return;
      }

      if (direction < 0 && track.scrollLeft <= 4) {
        track.scrollTo({ left: maxScroll, behavior: 'smooth' });
        return;
      }

      track.scrollBy({ left: direction * distance, behavior: 'smooth' });
    };

    prev.addEventListener('click', () => moveStyles(-1));
    next.addEventListener('click', () => moveStyles(1));
  });

  document.querySelectorAll('[data-category-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-category-track]');
    const prev = carousel.querySelector('[data-category-prev]');
    const next = carousel.querySelector('[data-category-next]');

    if (!track || !prev || !next) return;

    const getStep = () => {
      const firstCard = track.querySelector('.category-showcase-card');
      const gap = parseFloat(window.getComputedStyle(track).gap || '0') || 0;
      return firstCard ? firstCard.getBoundingClientRect().width + gap : track.clientWidth;
    };

    const syncControls = () => {
      const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
      const disabled = maxScroll <= 8;
      prev.disabled = disabled;
      next.disabled = disabled;
    };

    const moveCategories = (direction) => {
      const step = getStep();
      const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
      const distance = step; // Scroll exactly one item at a time

      if (maxScroll <= 8) return;

      if (direction > 0 && track.scrollLeft >= maxScroll - 4) {
        track.scrollTo({ left: 0, behavior: 'smooth' });
        return;
      }

      if (direction < 0 && track.scrollLeft <= 4) {
        track.scrollTo({ left: maxScroll, behavior: 'smooth' });
        return;
      }

      track.scrollBy({ left: direction * distance, behavior: 'smooth' });
    };

    prev.addEventListener('click', () => moveCategories(-1));
    next.addEventListener('click', () => moveCategories(1));
    track.addEventListener('scroll', syncControls, { passive: true });
    window.addEventListener('resize', syncControls);
    syncControls();
  });

  document.querySelectorAll('[data-style-selector-link]').forEach((link) => {
    link.addEventListener('click', (event) => {
      const href = link.getAttribute('href') || '';
      if (href === '') return;
      event.preventDefault();
      window.location.assign(href);
    });
  });

  document.querySelectorAll('[data-product-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-product-track]');
    if (!track) return;

    // Find buttons: prefer header-level, fall back to old overlay buttons
    const section = carousel.closest('.products-section, .premium-popular-section');
    const prev = (section && section.querySelector('[data-rail-prev]')) || carousel.querySelector('[data-product-prev]');
    const next = (section && section.querySelector('[data-rail-next]')) || carousel.querySelector('[data-product-next]');

    if (!prev || !next) return;

    const getStep = () => {
      const firstCard = track.querySelector('.prod-card, .news-card') || track.firstElementChild;
      const gap = parseFloat(window.getComputedStyle(track).gap || '0') || 0;
      return firstCard ? firstCard.getBoundingClientRect().width + gap : track.clientWidth;
    };

    const moveProducts = (direction) => {
      const step = getStep();
      const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);

      if (maxScroll <= 8) return;

      if (direction > 0 && track.scrollLeft >= maxScroll - 4) {
        track.scrollTo({ left: 0, behavior: 'smooth' });
        return;
      }

      if (direction < 0 && track.scrollLeft <= 4) {
        track.scrollTo({ left: maxScroll, behavior: 'smooth' });
        return;
      }

      track.scrollBy({ left: direction * step, behavior: 'smooth' });
    };

    prev.addEventListener('click', () => moveProducts(-1));
    next.addEventListener('click', () => moveProducts(1));
  });

  // Marquee section nav buttons (reviews + social gallery)
  document.querySelectorAll('.reviews-marquee-section, .social-gallery-section').forEach((section) => {
    const prevBtn = section.querySelector('[data-marquee-prev]');
    const nextBtn = section.querySelector('[data-marquee-next]');
    const containers = section.querySelectorAll('.reviews-marquee-container, .social-gallery-marquee-container');
    if (!prevBtn || !nextBtn || !containers.length) return;

    const scrollMarquee = (direction) => {
      containers.forEach((container) => {
        const track = container.querySelector('.reviews-marquee-track, .social-gallery-marquee-track');
        if (!track) return;
        track.style.animationPlayState = 'paused';
        const scrollAmount = 380 * direction;
        const current = parseFloat(getComputedStyle(track).transform.split(',')[4]) || 0;
        track.style.transition = 'transform 0.5s ease';
        track.style.transform = `translateX(${current + scrollAmount}px)`;
        setTimeout(() => {
          track.style.transition = '';
          track.style.animationPlayState = 'running';
        }, 600);
      });
    };

    prevBtn.addEventListener('click', () => scrollMarquee(1));
    nextBtn.addEventListener('click', () => scrollMarquee(-1));
  });

  document.querySelectorAll('[data-celebs-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-celebs-track]');
    if (!track || track.children.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    let timer = null;

    const getShiftDistance = () => {
      const firstCard = track.firstElementChild;
      if (!firstCard) return 0;
      const gap = parseFloat(window.getComputedStyle(track).gap || '0') || 0;
      return firstCard.getBoundingClientRect().width + gap;
    };

    const slideNext = () => {
      const firstCard = track.firstElementChild;
      const shift = getShiftDistance();
      if (!firstCard || !shift) return;

      track.style.transition = 'transform 700ms ease';
      track.style.transform = `translateX(-${shift}px)`;

      const onTransitionEnd = () => {
        track.removeEventListener('transitionend', onTransitionEnd);
        track.style.transition = 'none';
        track.appendChild(firstCard);
        track.style.transform = 'translateX(0)';
      };

      track.addEventListener('transitionend', onTransitionEnd, { once: true });
    };

    const startAutoScroll = () => {
      if (timer) clearInterval(timer);
      timer = setInterval(slideNext, 5000);
    };

    startAutoScroll();
  });

  document.querySelectorAll('[data-reviews-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-reviews-track]');
    const prev = carousel.querySelector('[data-reviews-prev]');
    const next = carousel.querySelector('[data-reviews-next]');

    if (!track || !prev || !next) return;

    const getStep = () => {
      const firstCard = track.querySelector('.review-card');
      const gap = parseFloat(window.getComputedStyle(track).gap || '0') || 0;
      return firstCard ? firstCard.getBoundingClientRect().width + gap : track.clientWidth;
    };

    const moveReviews = (direction) => {
      const step = getStep();
      const maxScroll = track.scrollWidth - track.clientWidth;
      const visibleCards = Math.max(1, Math.floor(track.clientWidth / step));
      const distance = step * Math.max(1, visibleCards - 1);

      if (direction > 0 && track.scrollLeft >= maxScroll - 4) {
        track.scrollTo({ left: 0, behavior: 'smooth' });
        return;
      }

      if (direction < 0 && track.scrollLeft <= 4) {
        track.scrollTo({ left: maxScroll, behavior: 'smooth' });
        return;
      }

      track.scrollBy({ left: direction * distance, behavior: 'smooth' });
    };

    prev.addEventListener('click', () => moveReviews(-1));
    next.addEventListener('click', () => moveReviews(1));
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const consentCookieName = 'azuronn_cookie_consent';
  const consentVersion = 1;
  const consentMaxAgeDays = 180;
  const banner = document.querySelector('[data-cookie-banner]');
  const modalShell = document.querySelector('[data-cookie-modal]');
  const launcher = document.querySelector('[data-cookie-settings-launcher]');
  const preferenceTriggers = document.querySelectorAll('[data-cookie-preferences-trigger]');
  const categoryInputs = {
    analytics: document.querySelector('[data-cookie-category="analytics"]'),
    marketing: document.querySelector('[data-cookie-category="marketing"]'),
  };

  if (!banner || !modalShell || !launcher || !preferenceTriggers.length) {
    return;
  }

  const modal = modalShell.querySelector('.cookie-modal');
  const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
  let lastFocusedElement = null;

  const readCookie = (name) => {
    const prefix = `${name}=`;
    return document.cookie
      .split(';')
      .map((part) => part.trim())
      .find((part) => part.startsWith(prefix))
      ?.slice(prefix.length) || '';
  };

  const writeCookie = (name, value, days) => {
    const maxAge = Math.max(0, Math.floor(days * 24 * 60 * 60));
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax${secure}`;
  };

  const deleteCookie = (name) => {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
  };

  const defaultConsent = () => ({
    version: consentVersion,
    necessary: true,
    analytics: false,
    marketing: false,
    updatedAt: new Date().toISOString(),
  });

  const parseConsent = () => {
    const raw = readCookie(consentCookieName);
    if (!raw) {
      return null;
    }

    try {
      const parsed = JSON.parse(decodeURIComponent(raw));
      if (!parsed || Number(parsed.version) !== consentVersion) {
        return null;
      }

      return {
        version: consentVersion,
        necessary: true,
        analytics: Boolean(parsed.analytics),
        marketing: Boolean(parsed.marketing),
        updatedAt: String(parsed.updatedAt || ''),
      };
    } catch (error) {
      return null;
    }
  };

  const syncConsentInputs = (consent) => {
    if (categoryInputs.analytics) {
      categoryInputs.analytics.checked = Boolean(consent.analytics);
    }
    if (categoryInputs.marketing) {
      categoryInputs.marketing.checked = Boolean(consent.marketing);
    }
  };

  const optionalCookieNames = [
    '_ga',
    '_ga_*',
    '_gid',
    '_gat',
    '_gcl_au',
    '_fbp',
    '_uetvid',
    '_uetsid',
  ];

  const deleteCookieByName = (name) => {
    if (name.includes('*')) {
      const prefix = name.replace('*', '');
      document.cookie.split(';').forEach((part) => {
        const cookieName = part.split('=')[0].trim();
        if (cookieName.startsWith(prefix)) {
          deleteCookie(cookieName);
        }
      });
      return;
    }
    deleteCookie(name);
  };

  const enforceConsent = (consent) => {
    if (!consent.analytics || !consent.marketing) {
      optionalCookieNames.forEach(deleteCookieByName);
    }

    document.documentElement.dataset.cookieAnalytics = consent.analytics ? 'granted' : 'denied';
    document.documentElement.dataset.cookieMarketing = consent.marketing ? 'granted' : 'denied';
    window.azuronnCookieConsent = { ...consent };
    window.dispatchEvent(new CustomEvent('azuronn:cookie-consent-updated', {
      detail: { ...consent },
    }));
  };

  const saveConsent = (consent) => {
    const nextConsent = {
      version: consentVersion,
      necessary: true,
      analytics: Boolean(consent.analytics),
      marketing: Boolean(consent.marketing),
      updatedAt: new Date().toISOString(),
    };

    writeCookie(consentCookieName, JSON.stringify(nextConsent), consentMaxAgeDays);
    syncConsentInputs(nextConsent);
    enforceConsent(nextConsent);
    banner.hidden = true;
    launcher.hidden = false;
    modalShell.hidden = true;
    document.body.classList.remove('cookie-modal-open');
    if (lastFocusedElement instanceof HTMLElement) {
      lastFocusedElement.focus();
    }
  };

  const openModal = (trigger = null) => {
    lastFocusedElement = trigger instanceof HTMLElement ? trigger : document.activeElement;
    modalShell.hidden = false;
    launcher.hidden = false;
    document.body.classList.add('cookie-modal-open');

    const firstFocusable = modal ? modal.querySelector(focusableSelector) : null;
    if (firstFocusable instanceof HTMLElement) {
      firstFocusable.focus();
    }
  };

  const closeModal = () => {
    modalShell.hidden = true;
    document.body.classList.remove('cookie-modal-open');
    if (lastFocusedElement instanceof HTMLElement) {
      lastFocusedElement.focus();
    }
  };

  document.addEventListener('click', (event) => {
    const preferenceTrigger = event.target.closest('[data-cookie-preferences-trigger]');
    if (preferenceTrigger) {
      event.preventDefault();
      syncConsentInputs(parseConsent() || defaultConsent());
      openModal(preferenceTrigger);
      return;
    }

    const actionButton = event.target.closest('[data-cookie-action]');
    if (actionButton) {
      event.preventDefault();
      const action = actionButton.getAttribute('data-cookie-action');
      if (action === 'accept-all') {
        saveConsent({ analytics: true, marketing: true });
        return;
      }
      if (action === 'reject-all') {
        saveConsent({ analytics: false, marketing: false });
        return;
      }
      if (action === 'open-settings') {
        syncConsentInputs(parseConsent() || defaultConsent());
        openModal(actionButton);
        return;
      }
      if (action === 'save-settings') {
        saveConsent({
          analytics: categoryInputs.analytics ? categoryInputs.analytics.checked : false,
          marketing: categoryInputs.marketing ? categoryInputs.marketing.checked : false,
        });
        return;
      }
    }

    const closeTrigger = event.target.closest('[data-cookie-modal-close]');
    if (closeTrigger) {
      event.preventDefault();
      closeModal();
    }
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modalShell.hidden) {
      closeModal();
    }
  });

  const currentConsent = parseConsent();
  if (currentConsent) {
    syncConsentInputs(currentConsent);
    enforceConsent(currentConsent);
    banner.hidden = true;
    launcher.hidden = false;
  } else {
    const pendingConsent = defaultConsent();
    syncConsentInputs(pendingConsent);
    enforceConsent(pendingConsent);
    banner.hidden = false;
    launcher.hidden = false;
  }
});

/* Scroll-linked progress bar under each horizontal rail (phone + tablet).

   responsive.css draws a track and a thumb under every rail shell and sizes
   the thumb from two custom properties:
     --rail-fill  visible fraction of the track (thumb width)
     --rail-pos   scroll progress, 0 at the start and 1 at the end
   Both are written here from the track's live scrollLeft, so the bar grows
   toward full as the user swipes and shrinks back on the way out.

   Desktop is untouched: the media query that draws the bar stops at 1024px,
   and this observer detaches above it. */
document.addEventListener('DOMContentLoaded', () => {
  const RAILS = [
    ['.category-showcase-shell', '.category-showcase-track'],
    ['.product-rail-shell', '.best-grid'],
    ['.shop-style-shell', '.shop-style-track'],
    ['.diamond-shape-layout.minimal-layout', '.diamond-shape-controls.minimal-controls'],
  ];

  const mobile = window.matchMedia('(max-width: 1024px)');
  const rails = [];

  RAILS.forEach(([shellSel, trackSel]) => {
    document.querySelectorAll(shellSel).forEach((shell) => {
      const track = shell.querySelector(trackSel);
      if (track) rails.push({ shell, track });
    });
  });

  if (!rails.length) return;

  const paint = ({ shell, track }) => {
    const scrollable = track.scrollWidth - track.clientWidth;
    // Under ~2px of travel is rounding noise, not a scrollable rail.
    if (scrollable <= 2) {
      shell.setAttribute('data-rail-static', '');
      return;
    }
    shell.removeAttribute('data-rail-static');

    const fill = track.clientWidth / track.scrollWidth;
    const pos = track.scrollLeft / scrollable;
    // A hairline thumb is unreadable on a 54px track; floor it at a third.
    shell.style.setProperty('--rail-fill', Math.max(0.34, Math.min(1, fill)).toFixed(4));
    shell.style.setProperty('--rail-pos', Math.max(0, Math.min(1, pos)).toFixed(4));
  };

  const paintAll = () => rails.forEach(paint);

  let queued = false;
  const schedule = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      paintAll();
    });
  };

  let attached = false;

  const attach = () => {
    if (attached) return;
    attached = true;
    rails.forEach(({ track }) => track.addEventListener('scroll', schedule, { passive: true }));
    window.addEventListener('resize', schedule);
    paintAll();
  };

  const detach = () => {
    if (!attached) return;
    attached = false;
    rails.forEach(({ track }) => track.removeEventListener('scroll', schedule));
    window.removeEventListener('resize', schedule);
    // Leave no inline overrides behind for the desktop stylesheet.
    rails.forEach(({ shell }) => {
      shell.style.removeProperty('--rail-fill');
      shell.style.removeProperty('--rail-pos');
      shell.removeAttribute('data-rail-static');
    });
  };

  const sync = () => (mobile.matches ? attach() : detach());

  sync();
  mobile.addEventListener('change', sync);

  // Lazy-loaded card images change scrollWidth after first paint.
  if ('ResizeObserver' in window) {
    const ro = new ResizeObserver(schedule);
    rails.forEach(({ track }) => ro.observe(track));
  }
  window.addEventListener('load', schedule);
});
