document.querySelectorAll('[data-shape-selector]').forEach((shapeRoot) => {
  const chips = Array.from(shapeRoot.querySelectorAll('[data-shape-option]'));
  const preview = shapeRoot.querySelector('[data-shape-preview]');
  const imageNode = shapeRoot.querySelector('[data-shape-image]');
  const labelNode = shapeRoot.querySelector('[data-shape-label]');
  const nameNode = shapeRoot.querySelector('[data-shape-name]');
  const descriptionNode = shapeRoot.querySelector('[data-shape-description]');
  const linkNode = shapeRoot.querySelector('[data-shape-link]');
  let activeIndex = 0;
  const imageCache = new Map();

  if (!chips.length || !preview || !imageNode || !labelNode || !nameNode || !descriptionNode || !linkNode) return;

  const warmShapeImage = (url) => {
    if (!url) return Promise.resolve();
    if (imageCache.has(url)) return imageCache.get(url);

    const loader = new Promise((resolve) => {
      const img = new Image();
      img.decoding = 'async';
      img.onload = () => resolve(url);
      img.onerror = () => resolve(url);
      img.src = url;
      if (img.complete) resolve(url);
    });

    imageCache.set(url, loader);
    return loader;
  };

  const applyShape = (chip) => {
    const tone = chip.dataset.shapeTone || 'classic';
    const toneClass = `shape-tone-${tone}`;
    const nextImage = chip.dataset.shapeImage || imageNode.src;
    const displayMode = chip.dataset.shapeDisplay || 'gem';

    chips.forEach((item) => {
      item.classList.remove('is-active');
      item.classList.remove('is-spinning');
      item.setAttribute('aria-pressed', 'false');
    });
    chip.classList.add('is-active');
    chip.classList.add('is-spinning');
    chip.setAttribute('aria-pressed', 'true');
    window.setTimeout(() => {
      chip.classList.remove('is-spinning');
    }, 960);

    imageNode.alt = `${chip.dataset.shapeName || 'Diamond'} shape preview`;
    labelNode.textContent = chip.dataset.shapeLabel || '';
    nameNode.textContent = chip.dataset.shapeName || '';
    descriptionNode.textContent = chip.dataset.shapeDescription || '';
    linkNode.href = chip.dataset.shapeUrl || '/shop';
    linkNode.textContent = `Shop ${chip.dataset.shapeName || 'Diamond'} Shape`;
    preview.style.setProperty('--shape-accent', chip.dataset.shapeAccent || '#b6a174');
    preview.dataset.shapeTone = tone;
    preview.dataset.shapeDisplay = displayMode;
    preview.classList.toggle('is-lifestyle', displayMode === 'lifestyle');
    Array.from(preview.classList)
      .filter((className) => className.startsWith('shape-tone-'))
      .forEach((className) => preview.classList.remove(className));
    preview.classList.add(toneClass);

    warmShapeImage(nextImage).then(() => {
      if (imageNode.src !== nextImage) imageNode.src = nextImage;
    });

    preview.classList.remove('is-flashing');
    void preview.offsetWidth;
    preview.classList.add('is-flashing');
    window.setTimeout(() => {
      preview.classList.remove('is-flashing');
    }, 420);
  };

  const goToShape = (index) => {
    activeIndex = (index + chips.length) % chips.length;
    applyShape(chips[activeIndex]);
  };

  chips.forEach((chip, index) => {
    chip.addEventListener('click', () => {
      goToShape(index);
    });
  });

  const preloadAllShapes = () => {
    chips.forEach((chip) => {
      warmShapeImage(chip.dataset.shapeImage || '');
    });
  };

  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(preloadAllShapes, { timeout: 1200 });
  } else {
    window.setTimeout(preloadAllShapes, 240);
  }
});

/* ── Product card share button ── */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.qv-share-btn');
  if (!btn) return;
  e.preventDefault();

  let url = btn.dataset.shareUrl || window.location.href;
  try {
    url = new URL(url, window.location.origin).toString();
  } catch (error) {
    url = window.location.href;
  }
  const title = btn.dataset.shareTitle || document.title;
  const icon  = btn.querySelector('i');

  const flashSuccess = () => {
    if (icon) { icon.className = 'fas fa-check'; }
    btn.style.setProperty('--share-flash', '1');
    window.setTimeout(() => {
      if (icon) { icon.className = 'fas fa-share-nodes'; }
      btn.style.removeProperty('--share-flash');
    }, 1800);
  };

  if (navigator.share) {
    navigator.share({ title, url }).then(flashSuccess).catch(() => {});
  } else {
    navigator.clipboard.writeText(url).then(flashSuccess).catch(() => {});
  }
});
