document.addEventListener('DOMContentLoaded', () => {
  const mediaTypeFor = (src) => {
    const cleanSrc = String(src || '').split('?')[0].toLowerCase();
    if (cleanSrc.endsWith('.mp4') || cleanSrc.endsWith('.webm') || cleanSrc.endsWith('.ogv') || cleanSrc.endsWith('.mov') || cleanSrc.endsWith('.m4v')) {
      return 'video';
    }
    return 'image';
  };

  const mediaMimeFor = (src) => {
    const cleanSrc = String(src || '').split('?')[0].toLowerCase();
    if (cleanSrc.endsWith('.webm')) return 'video/webm';
    if (cleanSrc.endsWith('.ogv')) return 'video/ogg';
    if (cleanSrc.endsWith('.mov')) return 'video/quicktime';
    if (cleanSrc.endsWith('.m4v')) return 'video/x-m4v';
    return 'video/mp4';
  };

  const buildGalleryMediaNode = (src, type, alt, isStage) => {
    if (!src) return null;

    if ((type || mediaTypeFor(src)) === 'video') {
      const video = document.createElement('video');
      video.className = isStage ? 'product-gallery-media' : 'product-thumb-media';
      video.playsInline = true;
      video.preload = 'metadata';
      video.muted = true;
      if (isStage) {
        video.controls = true;
        video.autoplay = true;
        video.loop = true;
        video.setAttribute('data-product-main-media', 'true');
      } else {
        video.setAttribute('aria-hidden', 'true');
      }
      const source = document.createElement('source');
      source.src = src;
      source.type = mediaMimeFor(src);
      video.appendChild(source);
      return video;
    }

    const image = document.createElement('img');
    image.className = isStage ? 'product-gallery-media' : 'product-thumb-media';
    image.src = src;
    image.alt = alt;
    if (isStage) {
      image.setAttribute('data-product-main-media', 'true');
    }
    return image;
  };

  document.querySelectorAll('[data-qty-wrap]').forEach((wrap) => {
    const input = wrap.querySelector('[data-qty-input]');
    if (!input) return;

    wrap.querySelectorAll('[data-qty-step]').forEach((button) => {
      button.addEventListener('click', () => {
        const delta = Number.parseInt(button.dataset.qtyStep || '0', 10);
        const min = Number.parseInt(input.getAttribute('min') || '1', 10);
        const max = Number.parseInt(input.getAttribute('max') || '99', 10);
        const current = Number.parseInt(input.value || String(min), 10) || min;
        const next = Math.max(min, Math.min(max, current + delta));
        input.value = String(next);
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  });

  document.addEventListener('click', (event) => {
    const thumb = event.target.closest('[data-product-thumb]');
    if (!thumb) return;

    const stage = document.querySelector('[data-product-stage]');
    if (!stage) return;

    event.preventDefault();

    const nextSrc = thumb.dataset.mediaSrc || '';
    if (!nextSrc) return;

    const nextType = thumb.dataset.mediaType || mediaTypeFor(nextSrc);
    const nextNode = buildGalleryMediaNode(nextSrc, nextType, stage.dataset.productAlt || 'Product', true);
    if (!nextNode) return;

    document.querySelectorAll('[data-product-thumb]').forEach((item) => item.classList.remove('is-active'));
    thumb.classList.add('is-active');

    const currentMedia = stage.querySelector('[data-product-main-media]');
    if (!currentMedia) {
      stage.replaceChildren(nextNode);
      return;
    }

    currentMedia.classList.add('is-swapping');
    window.setTimeout(() => {
      stage.replaceChildren(nextNode);
    }, 120);
  });

  const optionCards = document.querySelectorAll('.option-card input[type="radio"]');
  const syncOptionCards = () => {
    optionCards.forEach((input) => {
      const card = input.closest('.option-card');
      if (!card) return;
      card.classList.toggle('is-selected', input.checked);
    });
  };
  optionCards.forEach((input) => input.addEventListener('change', syncOptionCards));
  syncOptionCards();

  const revealNodes = document.querySelectorAll('.reveal-in');
  if ('IntersectionObserver' in window && revealNodes.length) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.18 });

    revealNodes.forEach((node) => observer.observe(node));
  } else {
    revealNodes.forEach((node) => node.classList.add('is-visible'));
  }

  document.querySelectorAll('[data-print-order]').forEach((button) => {
    button.addEventListener('click', () => window.print());
  });
});
