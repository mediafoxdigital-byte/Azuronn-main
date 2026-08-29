document.addEventListener('click', (event) => {
  const addButton = event.target.closest('[data-add-item]');
  if (addButton) {
    event.preventDefault();
    const repeater = addButton.closest('[data-repeater]');
    const template = document.getElementById(addButton.dataset.template || '');
    const list = repeater ? repeater.querySelector('.admin-repeater-list') : null;
    if (!repeater || !template || !list) return;

    const token = repeater.dataset.indexToken || '__INDEX__';
    const nextIndex = Number.parseInt(repeater.dataset.nextIndex || '0', 10);
    repeater.dataset.nextIndex = String(nextIndex + 1);

    let html = template.innerHTML;
    html = html.split(token).join(String(nextIndex));

    const base = repeater.dataset.base || '';
    if (base) {
      html = html
        .split('__COLUMN_BASE__').join(base)
        .split('__LINK_BASE__').join(base)
        .split('__PRODUCT_BASE__').join(base)
        .split('__FOOTER_LINK_BASE__').join(base);
    }

    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    const node = wrapper.firstElementChild;
    if (node) {
      list.appendChild(node);
      removeRetiredAttributeControls(repeater);
      node.scrollIntoView({ behavior: 'smooth', block: 'center' });
      saveAttributeDraft(addButton.closest('form'));
    }
    return;
  }

  const removeButton = event.target.closest('[data-remove-item]');
  if (removeButton) {
    event.preventDefault();
    const item = removeButton.closest('.admin-repeater-item');
    if (item) {
      const form = removeButton.closest('form');
      item.remove();
      saveAttributeDraft(form);
    }
  }
});

// Bump this whenever the Attributes editor markup changes structurally (fields
// added/removed/reordered). restoreAttributeDrafts() otherwise replays a stale
// localStorage snapshot — saved while an older layout was live — over the fresh
// server HTML, resurrecting removed fields (e.g. a price box or a duplicate
// metal block) that no longer exist in the template. clearOldAttributeDrafts()
// purges any draft that doesn't carry the current version on load.
const ATTRIBUTE_DRAFT_VERSION = 'v13';
const REMOVED_ATTRIBUTE_TEXT = [
  'Color Choice Cards',
  'Color Choice',
  'Diamond Step Kicker',
  'Diamond Step Intro Copy',
];

function attributeDraftKey(form) {
  if (!form) return '';
  if (form.closest('#attribute-profile')) {
    const typeInput = form.querySelector('input[name="attribute_type"]');
    return `azuronn-admin-attribute-profile:${ATTRIBUTE_DRAFT_VERSION}:${typeInput ? typeInput.value : 'default'}`;
  }
  if (form.closest('#attribute-editor')) {
    const productInput = form.querySelector('input[name="product_id"]');
    return `azuronn-admin-attribute-product:${ATTRIBUTE_DRAFT_VERSION}:${productInput ? productInput.value : 'default'}`;
  }
  return '';
}

function clearOldAttributeDrafts() {
  const prefixes = [
    'azuronn-admin-attribute-profile:',
    'azuronn-admin-attribute-product:',
  ];

  Object.keys(localStorage).forEach((key) => {
    if (!prefixes.some((prefix) => key.startsWith(prefix))) {
      return;
    }
    if (!key.includes(`:${ATTRIBUTE_DRAFT_VERSION}:`)) {
      localStorage.removeItem(key);
    }
  });
}

function removeRetiredAttributeControls(scope = document) {
  scope.querySelectorAll('#attribute-profile .admin-field, #attribute-editor .admin-field').forEach((field) => {
    const label = field.querySelector(':scope > span');
    const labelText = label ? label.textContent.trim() : '';
    if (REMOVED_ATTRIBUTE_TEXT.includes(labelText)) {
      field.remove();
    }
  });

  scope.querySelectorAll('#attribute-profile .admin-repeater-item, #attribute-editor .admin-repeater-item').forEach((item) => {
    const heading = item.querySelector('.admin-item-head h4');
    if (heading && heading.textContent.trim() === 'Color Choice') {
      item.remove();
    }
  });
}

const attributeDraftTimers = new Map();
const submittingAttributeForms = new WeakSet();
const shapeMediaUploads = new WeakMap();
let shapeMediaUploadSequence = 0;

function resetMetalPriceAdjustmentButtons(scope = document) {
  scope.querySelectorAll('[data-metal-price-adjustment-button]').forEach((button) => {
    button.disabled = false;
    button.removeAttribute('disabled');
    button.removeAttribute('aria-busy');
  });
}

function setShapeMediaUploadState(slot, state = 'idle', label = 'Image / video', progress = 0) {
  if (!slot) return;
  const status = slot.querySelector('[data-shape-media-status]');
  const progressTrack = slot.querySelector('[data-shape-media-progress]');
  const progressBar = slot.querySelector('[data-shape-media-progress-bar]');
  const uploading = state === 'optimizing' || state === 'uploading';

  slot.classList.toggle('is-uploading', uploading);
  slot.classList.toggle('has-upload-error', state === 'error');
  if (status) status.textContent = label;
  if (progressTrack) progressTrack.hidden = !uploading;
  if (progressBar) progressBar.style.width = `${Math.max(0, Math.min(100, progress))}%`;
}

function cancelShapeMediaUpload(slot) {
  if (!slot) return;
  const state = shapeMediaUploads.get(slot);
  slot.dataset.shapeMediaUploadSequence = String(++shapeMediaUploadSequence);
  if (state?.xhr && state.xhr.readyState !== XMLHttpRequest.DONE) {
    state.xhr.abort();
  }
  shapeMediaUploads.delete(slot);
  setShapeMediaUploadState(slot);
}

function diamondMediaUploadUrl(form) {
  const url = new URL(form?.getAttribute('action') || window.location.href, window.location.href);
  url.searchParams.set('diamond_media_upload', '1');
  return url.href;
}

function canvasToBlob(canvas, type, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

async function optimizeDiamondImage(file) {
  const result = { blob: file, name: file.name || 'diamond-image' };
  if (!file.type.startsWith('image/') || file.type === 'image/gif' || typeof createImageBitmap !== 'function') {
    return result;
  }

  let bitmap;
  try {
    try {
      bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (_optionError) {
      bitmap = await createImageBitmap(file);
    }

    const maxDimension = 2200;
    const scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size <= 900 * 1024) return result;

    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d', { alpha: true });
    if (!context) return result;
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    const optimized = await canvasToBlob(canvas, 'image/webp', 0.86);
    if (!optimized || (scale === 1 && optimized.size >= file.size)) return result;

    const baseName = (file.name || 'diamond-image').replace(/\.[^.]+$/, '');
    return { blob: optimized, name: `${baseName}.webp` };
  } catch (_error) {
    return result;
  } finally {
    if (bitmap && typeof bitmap.close === 'function') bitmap.close();
  }
}

function sendDiamondMediaUpload(form, prepared, state, onProgress) {
  return new Promise((resolve, reject) => {
    const csrf = form?.querySelector('input[name="csrf_token"]')?.value || '';
    if (!form || csrf === '') {
      reject(new Error('Your admin session expired. Refresh the page and try again.'));
      return;
    }

    const data = new FormData();
    data.append('csrf_token', csrf);
    data.append('action', 'upload-diamond-media');
    data.append('media', prepared.blob, prepared.name);

    const xhr = new XMLHttpRequest();
    state.xhr = xhr;
    xhr.open('POST', diamondMediaUploadUrl(form), true);
    xhr.responseType = 'json';
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
    });
    xhr.addEventListener('load', () => {
      let payload = xhr.response;
      if (!payload || typeof payload !== 'object') {
        try {
          payload = JSON.parse(xhr.responseText || '{}');
        } catch (_error) {
          payload = {};
        }
      }
      if (xhr.status >= 200 && xhr.status < 300 && payload.ok && payload.url) {
        resolve(payload);
        return;
      }
      reject(new Error(payload.error || 'The background upload failed. It will retry when you save.'));
    });
    xhr.addEventListener('error', () => reject(new Error('The background upload failed. It will retry when you save.')));
    xhr.addEventListener('abort', () => reject(new DOMException('Upload cancelled.', 'AbortError')));
    xhr.send(data);
  });
}

function uploadShapeMediaFile(fileInput) {
  const slot = fileInput?.closest('[data-shape-media-slot]');
  const file = fileInput?.files?.[0] || null;
  const form = fileInput?.closest('form');
  if (!slot || !file || !form) return;

  cancelShapeMediaUpload(slot);
  const sequence = ++shapeMediaUploadSequence;
  slot.dataset.shapeMediaUploadSequence = String(sequence);
  const state = { status: 'pending', xhr: null, promise: null };
  shapeMediaUploads.set(slot, state);

  const uploadLabel = slot.querySelector('[data-shape-media-upload-label]');
  if (uploadLabel) uploadLabel.textContent = 'Uploading';
  setShapeMediaUploadState(slot, 'optimizing', file.type.startsWith('image/') ? 'Optimizing...' : 'Starting...', 5);

  state.promise = (async () => {
    const prepared = await optimizeDiamondImage(file);
    if (slot.dataset.shapeMediaUploadSequence !== String(sequence)) return;

    state.status = 'uploading';
    setShapeMediaUploadState(slot, 'uploading', 'Uploading 0%', 0);
    const payload = await sendDiamondMediaUpload(form, prepared, state, (progress) => {
      if (slot.dataset.shapeMediaUploadSequence === String(sequence)) {
        setShapeMediaUploadState(slot, 'uploading', `Uploading ${progress}%`, progress);
      }
    });
    if (slot.dataset.shapeMediaUploadSequence !== String(sequence)) return;

    const currentInput = slot.querySelector('[data-shape-media-current]');
    if (currentInput) currentInput.value = payload.url;
    fileInput.value = '';
    state.status = 'complete';
    syncShapeMediaSlot(slot);
    setShapeMediaUploadState(slot, 'complete', 'Ready', 100);
  })().catch((error) => {
    if (slot.dataset.shapeMediaUploadSequence !== String(sequence) || error?.name === 'AbortError') return;
    state.status = 'error';
    setShapeMediaUploadState(slot, 'error', 'Retries on Save', 0);
    if (uploadLabel) uploadLabel.textContent = 'Retry';
    console.error('Diamond media upload failed:', error);
  });
}

function pendingShapeMediaUploads(form) {
  return Array.from(form.querySelectorAll('[data-shape-media-slot]'))
    .map((slot) => shapeMediaUploads.get(slot))
    .filter((state) => state?.status === 'pending' || state?.status === 'uploading');
}

function adminMediaPreviewType(source, file = null) {
  if (file && typeof file.type === 'string' && file.type.startsWith('video/')) return 'video';
  if (file && typeof file.type === 'string' && file.type.startsWith('image/')) return 'image';
  const cleanSource = String((file && file.name) || source || '').split('?')[0].toLowerCase();
  return /\.(mp4|webm|ogv|mov|m4v)$/.test(cleanSource) ? 'video' : 'image';
}

function syncShapeMediaCount(panel) {
  if (!panel) return;
  const count = Array.from(panel.querySelectorAll('[data-shape-media-slot]')).filter((slot) => {
    const current = slot.querySelector('[data-shape-media-current]');
    const file = slot.querySelector('[data-shape-media-file]');
    return (current && current.value.trim() !== '') || (file && file.files && file.files.length > 0);
  }).length;
  const countNode = panel.querySelector('[data-shape-media-count]');
  if (countNode) countNode.textContent = `${count} / 6`;
}

function syncShapeMediaSlot(slot, selectedFile = null) {
  if (!slot) return;
  const currentInput = slot.querySelector('[data-shape-media-current]');
  const fileInput = slot.querySelector('[data-shape-media-file]');
  const preview = slot.querySelector('[data-shape-media-preview]');
  const removeButton = slot.querySelector('[data-shape-media-remove]');
  const uploadLabel = slot.querySelector('[data-shape-media-upload-label]');
  const file = selectedFile || (fileInput && fileInput.files ? fileInput.files[0] : null);

  if (slot.dataset.previewObjectUrl) {
    URL.revokeObjectURL(slot.dataset.previewObjectUrl);
    delete slot.dataset.previewObjectUrl;
  }

  let source = currentInput ? currentInput.value.trim() : '';
  if (file) {
    source = URL.createObjectURL(file);
    slot.dataset.previewObjectUrl = source;
  }

  if (preview) {
    preview.replaceChildren();
    if (source !== '') {
      if (adminMediaPreviewType(source, file) === 'video') {
        const video = document.createElement('video');
        video.src = source;
        video.muted = true;
        video.playsInline = true;
        video.preload = 'metadata';
        video.controls = true;
        preview.appendChild(video);
      } else {
        const image = document.createElement('img');
        image.src = source;
        image.alt = '';
        image.decoding = 'async';
        preview.appendChild(image);
      }
    } else {
      const icon = document.createElement('i');
      icon.className = 'far fa-image';
      icon.setAttribute('aria-hidden', 'true');
      preview.appendChild(icon);
    }
  }

  if (removeButton) removeButton.hidden = source === '';
  if (uploadLabel) uploadLabel.textContent = file ? 'Selected' : (source !== '' ? 'Replace' : 'Upload');
  syncShapeMediaCount(slot.closest('[data-shape-media-panel]'));
}

function syncMetalShapeMediaPicker(picker, preferredShape = '') {
  if (!picker) return;
  const options = Array.from(picker.querySelectorAll('[data-shape-media-option]'));
  const checkedShapes = options
    .filter((option) => option.querySelector('[data-shape-media-toggle]')?.checked)
    .map((option) => option.dataset.shapeMediaOption || '');
  let activeShape = preferredShape || picker.dataset.activeShape || '';
  if (!checkedShapes.includes(activeShape)) activeShape = checkedShapes[0] || '';
  picker.dataset.activeShape = activeShape;

  options.forEach((option) => {
    const shape = option.dataset.shapeMediaOption || '';
    const checkbox = option.querySelector('[data-shape-media-toggle]');
    const openButton = option.querySelector('[data-shape-media-open]');
    const checked = Boolean(checkbox?.checked);
    if (openButton) {
      openButton.hidden = !checked;
      openButton.classList.toggle('is-active', checked && shape === activeShape);
    }
  });

  picker.querySelectorAll('[data-shape-media-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.shapeMediaPanel !== activeShape;
  });
}

function syncMetalShapeMediaPickers(scope = document) {
  scope.querySelectorAll('[data-shape-media-slot]').forEach((slot) => syncShapeMediaSlot(slot));
  scope.querySelectorAll('[data-metal-shape-media-picker]').forEach((picker) => syncMetalShapeMediaPicker(picker));
}

document.addEventListener('change', (event) => {
  const shapeToggle = event.target.closest?.('[data-shape-media-toggle]');
  if (shapeToggle) {
    const picker = shapeToggle.closest('[data-metal-shape-media-picker]');
    const shape = shapeToggle.value || '';
    if (!shapeToggle.checked && picker) {
      const panel = Array.from(picker.querySelectorAll('[data-shape-media-panel]'))
        .find((item) => item.dataset.shapeMediaPanel === shape);
      panel?.querySelectorAll('[data-shape-media-file]').forEach((input) => {
        cancelShapeMediaUpload(input.closest('[data-shape-media-slot]'));
        input.value = '';
        syncShapeMediaSlot(input.closest('[data-shape-media-slot]'));
      });
    }
    syncMetalShapeMediaPicker(picker, shapeToggle.checked ? shape : '');
    return;
  }

  const mediaFile = event.target.closest?.('[data-shape-media-file]');
  if (mediaFile) {
    const selectedFile = mediaFile.files?.[0] || null;
    syncShapeMediaSlot(mediaFile.closest('[data-shape-media-slot]'), selectedFile);
    if (selectedFile) uploadShapeMediaFile(mediaFile);
  }
});

document.addEventListener('click', (event) => {
  const openButton = event.target.closest('[data-shape-media-open]');
  if (openButton) {
    event.preventDefault();
    syncMetalShapeMediaPicker(openButton.closest('[data-metal-shape-media-picker]'), openButton.dataset.shapeMediaOpen || '');
    return;
  }

  const removeButton = event.target.closest('[data-shape-media-remove]');
  if (!removeButton) return;
  event.preventDefault();
  const slot = removeButton.closest('[data-shape-media-slot]');
  const currentInput = slot?.querySelector('[data-shape-media-current]');
  const fileInput = slot?.querySelector('[data-shape-media-file]');
  cancelShapeMediaUpload(slot);
  if (currentInput) currentInput.value = '';
  if (fileInput) fileInput.value = '';
  syncShapeMediaSlot(slot);
});

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.querySelector('[data-shape-media-slot]')) return;

  const pending = pendingShapeMediaUploads(form);
  if (pending.length === 0) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  if (form.dataset.waitingForShapeMedia === '1') return;
  form.dataset.waitingForShapeMedia = '1';
  form.setAttribute('aria-busy', 'true');

  const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
  const originalLabel = submitter ? submitter.innerHTML : '';
  if (submitter) {
    submitter.disabled = true;
    submitter.setAttribute('aria-busy', 'true');
    submitter.textContent = 'Finishing media...';
  }

  Promise.allSettled(pending.map((state) => state.promise).filter(Boolean)).then(() => {
    delete form.dataset.waitingForShapeMedia;
    form.removeAttribute('aria-busy');
    if (submitter) {
      submitter.disabled = false;
      submitter.removeAttribute('aria-busy');
      submitter.innerHTML = originalLabel;
    }
    if (!form.isConnected) return;
    if (submitter && form.contains(submitter)) {
      form.requestSubmit(submitter);
    } else {
      form.requestSubmit();
    }
  });
}, true);

function snapshotEditorMarkup(editor) {
  const clone = editor.cloneNode(true);
  const sourceControls = editor.querySelectorAll('input, textarea, select');
  const cloneControls = clone.querySelectorAll('input, textarea, select');

  sourceControls.forEach((control, index) => {
    const mirror = cloneControls[index];
    if (!mirror) return;

    if (control instanceof HTMLTextAreaElement && mirror instanceof HTMLTextAreaElement) {
      mirror.value = control.value;
      mirror.textContent = control.value;
      return;
    }

    if (control instanceof HTMLSelectElement && mirror instanceof HTMLSelectElement) {
      [...mirror.options].forEach((option, optionIndex) => {
        const selected = control.options[optionIndex] ? control.options[optionIndex].selected : false;
        option.selected = selected;
        if (selected) {
          option.setAttribute('selected', 'selected');
        } else {
          option.removeAttribute('selected');
        }
      });
      return;
    }

    if (control instanceof HTMLInputElement && mirror instanceof HTMLInputElement) {
      if (control.type === 'checkbox' || control.type === 'radio') {
        if (control.checked) {
          mirror.setAttribute('checked', 'checked');
        } else {
          mirror.removeAttribute('checked');
        }
        return;
      }

      if (control.type !== 'file') {
        mirror.value = control.value;
        mirror.setAttribute('value', control.value);
      }
    }
  });

  resetMetalPriceAdjustmentButtons(clone);

  return clone.innerHTML;
}

function saveAttributeDraft(form) {
  if (!form) return;
  const key = attributeDraftKey(form);
  const editor = form.querySelector('.admin-product-editor');
  if (!key || !editor) return;
  localStorage.setItem(key, snapshotEditorMarkup(editor));
}

function getMetalPriceConfirmationDialog() {
  let dialog = document.querySelector('[data-metal-price-confirm-dialog]');
  if (dialog) return dialog;

  dialog = document.createElement('dialog');
  dialog.className = 'admin-metal-confirm-dialog';
  dialog.setAttribute('data-metal-price-confirm-dialog', '');
  dialog.setAttribute('aria-labelledby', 'metal-price-confirm-title');
  dialog.innerHTML = `
    <form method="dialog" class="admin-metal-confirm-shell">
      <div class="admin-metal-confirm-icon" data-metal-confirm-icon aria-hidden="true">
        <i class="fas fa-arrow-up"></i>
      </div>
      <div class="admin-metal-confirm-copy">
        <span>Confirm price adjustment</span>
        <h3 id="metal-price-confirm-title" data-metal-confirm-title></h3>
        <p data-metal-confirm-message></p>
      </div>
      <dl class="admin-metal-confirm-summary">
        <div><dt>Category</dt><dd data-metal-confirm-category></dd></div>
        <div><dt>Metal</dt><dd data-metal-confirm-metal></dd></div>
        <div><dt>Change</dt><dd data-metal-confirm-change></dd></div>
      </dl>
      <div class="admin-metal-confirm-actions">
        <button class="admin-ghost" type="submit" value="cancel" autofocus>Cancel</button>
        <button class="admin-metal-confirm-submit" type="submit" value="confirm" data-metal-confirm-submit>
          <i class="fas fa-arrow-up" aria-hidden="true"></i><span>Confirm increase</span>
        </button>
      </div>
    </form>
  `;
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close('cancel');
  });
  document.body.appendChild(dialog);
  return dialog;
}

function confirmMetalPriceAdjustment({ category, metal, direction, percentage }) {
  const dialog = getMetalPriceConfirmationDialog();
  const isDecrease = direction === 'decrease';
  const verb = isDecrease ? 'Decrease' : 'Increase';
  const icon = dialog.querySelector('[data-metal-confirm-icon] i');
  const submit = dialog.querySelector('[data-metal-confirm-submit]');

  dialog.classList.toggle('is-decrease', isDecrease);
  dialog.querySelector('[data-metal-confirm-title]').textContent = `${verb} ${metal} prices by ${percentage}%?`;
  dialog.querySelector('[data-metal-confirm-message]').textContent = `Every matching product will be recalculated once from its latest saved price.`;
  dialog.querySelector('[data-metal-confirm-category]').textContent = category;
  dialog.querySelector('[data-metal-confirm-metal]').textContent = metal;
  dialog.querySelector('[data-metal-confirm-change]').textContent = `${isDecrease ? '-' : '+'}${percentage}%`;
  icon.className = `fas fa-arrow-${isDecrease ? 'down' : 'up'}`;
  submit.querySelector('i').className = icon.className;
  submit.querySelector('span').textContent = `Confirm ${direction}`;

  dialog.returnValue = '';
  dialog.showModal();
  return new Promise((resolve) => {
    dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
  });
}

async function submitMetalPriceAdjustment(button) {
  const adjustment = button.closest('[data-metal-price-adjustment]');
  const item = button.closest('.admin-repeater-item');
  const profileForm = button.closest('form');
  const percentageInput = adjustment ? adjustment.querySelector('[data-metal-price-percentage]') : null;
  const metalInput = item ? item.querySelector('input[name$="[label]"]') : null;
  const categoryInput = profileForm ? profileForm.querySelector('input[name="attribute_type"]') : null;
  const csrfInput = profileForm ? profileForm.querySelector('input[name="csrf_token"]') : null;
  const direction = button.dataset.direction === 'decrease' ? 'decrease' : 'increase';

  if (!profileForm || !percentageInput || !metalInput || !categoryInput || !csrfInput) return;

  const percentage = Number.parseFloat(percentageInput.value);
  percentageInput.setCustomValidity('');
  if (!Number.isFinite(percentage) || percentage <= 0 || percentage > 100) {
    percentageInput.setCustomValidity('Enter a percentage greater than 0 and no more than 100.');
    percentageInput.reportValidity();
    return;
  }

  const metal = metalInput.value.trim();
  const category = categoryInput.value.trim();
  if (!metal) {
    metalInput.setCustomValidity('Enter and save the metal name before changing prices.');
    metalInput.reportValidity();
    metalInput.addEventListener('input', () => metalInput.setCustomValidity(''), { once: true });
    return;
  }

  const confirmed = await confirmMetalPriceAdjustment({ category, metal, direction, percentage });
  if (!confirmed) return;

  const submitForm = document.createElement('form');
  submitForm.method = 'post';
  const actionAttribute = profileForm.getAttribute('action');
  submitForm.action = actionAttribute ? new URL(actionAttribute, window.location.href).href : window.location.href;
  submitForm.hidden = true;

  const fields = {
    csrf_token: csrfInput.value,
    action: 'adjust-metal-prices',
    return_view: 'attributes',
    'metal_price_adjustment[attribute_type]': category,
    'metal_price_adjustment[metal]': metal,
    'metal_price_adjustment[direction]': direction,
    'metal_price_adjustment[percentage]': String(percentage),
  };

  Object.entries(fields).forEach(([name, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    submitForm.appendChild(input);
  });

  percentageInput.value = '';
  saveAttributeDraft(profileForm);
  submittingAttributeForms.add(profileForm);
  const draftKey = attributeDraftKey(profileForm);
  const draftTimer = attributeDraftTimers.get(draftKey);
  if (draftTimer) {
    window.clearTimeout(draftTimer);
    attributeDraftTimers.delete(draftKey);
  }
  adjustment.querySelectorAll('[data-metal-price-adjustment-button]').forEach((actionButton) => {
    actionButton.disabled = true;
    actionButton.setAttribute('aria-busy', 'true');
  });
  document.body.appendChild(submitForm);
  submitForm.submit();
}

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-metal-price-adjustment-button]');
  if (!button) return;
  event.preventDefault();
  submitMetalPriceAdjustment(button);
});

function scheduleAttributeDraftSave(form) {
  const key = attributeDraftKey(form);
  if (!form || !key) return;
  const timer = attributeDraftTimers.get(key);
  if (timer) {
    window.clearTimeout(timer);
  }
  attributeDraftTimers.set(key, window.setTimeout(() => {
    saveAttributeDraft(form);
    attributeDraftTimers.delete(key);
  }, 120));
}

function restoreAttributeDrafts(scope = document) {
  scope.querySelectorAll('#attribute-profile form, #attribute-editor form').forEach((form) => {
    const key = attributeDraftKey(form);
    const editor = form.querySelector('.admin-product-editor');
    if (!key || !editor) return;

    const saved = localStorage.getItem(key);
    if (saved) {
      editor.innerHTML = saved;
    }

    resetMetalPriceAdjustmentButtons(form);
    removeRetiredAttributeControls(form);
    syncProductEditorScopes(form);
    syncMetalShapeMediaPickers(form);

    form.addEventListener('submit', () => {
      submittingAttributeForms.add(form);
      const timer = attributeDraftTimers.get(key);
      if (timer) {
        window.clearTimeout(timer);
        attributeDraftTimers.delete(key);
      }
      localStorage.removeItem(key);
    });
  });
}

window.addEventListener('beforeunload', () => {
  document.querySelectorAll('#attribute-profile form, #attribute-editor form').forEach((form) => {
    if (submittingAttributeForms.has(form)) {
      return;
    }
    saveAttributeDraft(form);
  });
});

function syncCouponFields(scope = document) {
  scope.querySelectorAll('[data-coupon-type]').forEach((select) => {
    const form = select.closest('form');
    const valueInput = form ? form.querySelector('[data-coupon-value]') : null;
    if (!valueInput) return;

    const field = valueInput.closest('.admin-field');
    const label = field ? field.querySelector('span') : null;
    const hint = field ? field.querySelector('small') : null;
    const isFixed = select.value === 'fixed';

    if (label) {
      label.textContent = isFixed ? 'Fixed Amount' : 'Percentage Value';
    }

    valueInput.placeholder = isFixed ? '250' : '10';
    if (isFixed) {
      valueInput.removeAttribute('max');
      if (hint) {
        hint.textContent = 'Enter the discount amount to subtract from the order total.';
      }
    } else {
      valueInput.setAttribute('max', '100');
      if (hint) {
        hint.textContent = 'Enter the percentage to apply. Standard ecommerce range is 5 to 50.';
      }
    }
  });
}

function syncProductEditorScopes(scope = document) {
  // The caller may pass a <form> element directly (e.g. from a change-event
  // handler) or document. A form does NOT contain itself, so
  // form.querySelectorAll('form') returns nothing. Normalise so the logic
  // below always operates on the correct set of target forms.
  const forms = (scope instanceof HTMLFormElement)
    ? [scope]
    : Array.from(scope.querySelectorAll('form'));
  forms.forEach(function (form) {
    // The Category dropdown is the ONLY source of truth for the editor context.
    // It deliberately has no fallback: reading the hidden product[product_type]
    // select here is what pinned a blank-category form to whatever type the
    // server happened to render, so every category showed Engagement Rings'
    // metals until the dropdown was touched.
    const categorySelect = form.querySelector('select[name="product[category_taxonomy]"][data-category-type-map]');
    let type = '';
    if (categorySelect) {
      try {
        const map = JSON.parse(categorySelect.getAttribute('data-category-type-map') || '{}');
        type = (map[categorySelect.value] || '').toLowerCase();
      } catch (_e) { type = ''; }
    } else {
      // Forms without the Category dropdown (the Attributes editor) still key
      // off the type select.
      const hiddenType = form.querySelector('select[name="product[product_type]"]');
      if (hiddenType) type = (hiddenType.value || '').toLowerCase();
    }

    // Gender applies to wedding rings only — reveal it when the chosen Category
    // is that ring section, and disable it otherwise so it can't submit.
    if (categorySelect) {
      let sectionMap = {};
      try { sectionMap = JSON.parse(categorySelect.dataset.categoryStyleMap || '{}'); } catch (_e) { sectionMap = {}; }
      const activeSection = sectionMap[categorySelect.value] || '';
      form.querySelectorAll('[data-ring-section-scope]').forEach((node) => {
        const isVisible = node.dataset.ringSectionScope === activeSection;
        node.hidden = !isVisible;
        node.style.display = isVisible ? '' : 'none';
        node.querySelectorAll('input, select, textarea').forEach((control) => {
          control.disabled = !isVisible;
        });
      });
    }

    // "Choose a Category" prompts are CSS-driven off this one class, so a single
    // toggle governs every prompt in the form and none can get stuck visible.
    form.querySelectorAll('[data-category-state-root]').forEach((root) => {
      root.classList.toggle('has-no-category', type === '');
    });

    // NOTE: no `if (!type) return` here. An empty type must fall through so the
    // loops below hide and disable EVERY category block — that is what makes a
    // blank Category show nothing instead of another category's data.

    form.querySelectorAll('[data-product-scope]').forEach((node) => {
      const allowed = (node.dataset.productScope || '')
        .split(',')
        .map((item) => item.trim().toLowerCase())
        .filter(Boolean);

      if (!allowed.length) return;
      const isVisible = allowed.includes(type);
      node.hidden = !isVisible;
      node.style.display = isVisible ? '' : 'none';
      node.querySelectorAll('input, select, textarea, button').forEach((control) => {
        control.disabled = !isVisible;
      });
    });

    form.querySelectorAll('[data-matrix-profile-type]').forEach((matrix) => {
      const isMatch = matrix.dataset.matrixProfileType === type;
      matrix.style.display = isMatch ? 'block' : 'none';
      matrix.querySelectorAll('input, select, textarea, button').forEach((control) => {
        control.disabled = !isMatch;
      });
    });

    syncProductStyleSection(form);
  });
}

// Ring Style picker: show the style chips that match the chosen Category's ring
// section (engagement vs wedding) and DISABLE the other section's checkboxes so
// they never submit. The whole picker is hidden for non-ring types by the scope
// sync above (data-product-scope="ring, rings"); when it is hidden we disable
// every checkbox inside it so a hidden selection can't leak into the save.
function syncProductStyleSection(scope = document) {
  scope.querySelectorAll('[data-style-picker-wrap]').forEach((wrap) => {
    const form = wrap.closest('form');
    if (!form) return;

    let styleMap = {};
    let typeMap = {};
    let flatTypes = [];
    const categorySelect = form.querySelector('select[name="product[category_taxonomy]"][data-category-style-map]');
    if (categorySelect) {
      try { styleMap = JSON.parse(categorySelect.dataset.categoryStyleMap || '{}'); } catch (_e) { styleMap = {}; }
      try { typeMap = JSON.parse(categorySelect.dataset.categoryTypeMap || '{}'); } catch (_e) { typeMap = {}; }
      try { flatTypes = JSON.parse(categorySelect.dataset.categoryFlatTypes || '[]'); } catch (_e) { flatTypes = []; }
    }

    const catValue = categorySelect ? (categorySelect.value || '') : '';
    const ringSection = styleMap[catValue] || '';
    const canonicalType = typeMap[catValue] || '';

    // Determine which grid section key should be active.
    // Ring categories map to 'engagement' or 'wedding'.
    // Non-ring categories map to 'flat-<CanonicalType>', driven by the real
    // category list PHP emitted on the select.
    let activeSection = '';
    if (ringSection === 'engagement' || ringSection === 'wedding') {
      activeSection = ringSection;
    } else if (flatTypes.includes(canonicalType)) {
      activeSection = 'flat-' + canonicalType;
    }

    const wrapHidden = wrap.hidden || wrap.style.display === 'none';
    wrap.querySelectorAll('[data-style-section]').forEach((grid) => {
      const isActive = !wrapHidden && grid.dataset.styleSection === activeSection;
      grid.style.display = isActive ? '' : 'none';
      grid.querySelectorAll('input').forEach((input) => {
        input.disabled = !isActive;
      });
    });
  });
}

function createRepeaterNode(templateId, token, index) {
  const template = document.getElementById(templateId);
  if (!template) return null;

  const wrapper = document.createElement('div');
  wrapper.innerHTML = template.innerHTML.split(token).join(String(index)).trim();
  return wrapper.firstElementChild;
}

function setNodeValue(node, selector, value) {
  const field = node.querySelector(selector);
  if (!field) return;
  field.value = value ?? '';
}

const richTextSelections = new WeakMap();
let activeRichTextField = null;

function isSelectionInsideEditor(selection, editor) {
  if (!selection || !editor || selection.rangeCount === 0) return false;

  const range = selection.getRangeAt(0);
  const startNode = range.startContainer;
  const endNode = range.endContainer;
  return editor.contains(startNode) && editor.contains(endNode);
}

function saveRichTextSelection(field) {
  if (!field) return;
  const editor = field.querySelector('[data-richtext-editor]');
  const selection = window.getSelection();
  if (!editor || !selection || !isSelectionInsideEditor(selection, editor)) return;

  activeRichTextField = field;
  richTextSelections.set(field, selection.getRangeAt(0).cloneRange());
}

function restoreRichTextSelection(field) {
  const editor = field ? field.querySelector('[data-richtext-editor]') : null;
  const range = field ? richTextSelections.get(field) : null;
  if (!editor) return false;

  editor.focus();

  if (!range) {
    return false;
  }

  const selection = window.getSelection();
  if (!selection) {
    return false;
  }

  selection.removeAllRanges();
  selection.addRange(range);
  activeRichTextField = field;
  return true;
}

function getRichTextRange(field) {
  const editor = field ? field.querySelector('[data-richtext-editor]') : null;
  const selection = window.getSelection();
  if (editor && selection && isSelectionInsideEditor(selection, editor)) {
    return selection.getRangeAt(0);
  }

  return field ? richTextSelections.get(field) || null : null;
}

function closestRichTextElement(node, selector) {
  if (!node) return null;
  const base = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
  return base ? base.closest(selector) : null;
}

function placeCaretAtNodeStart(node) {
  const selection = window.getSelection();
  if (!selection || !node) return;

  const range = document.createRange();
  range.selectNodeContents(node);
  range.collapse(true);
  selection.removeAllRanges();
  selection.addRange(range);
}

function placeCaretAtNodeEnd(node) {
  const selection = window.getSelection();
  if (!selection || !node) return;

  const range = document.createRange();
  range.selectNodeContents(node);
  range.collapse(false);
  selection.removeAllRanges();
  selection.addRange(range);
}

function createRichTextParagraph() {
  const paragraph = document.createElement('p');
  paragraph.innerHTML = '<br>';
  return paragraph;
}

function isRangeAtEndOfElement(range, element) {
  if (!range || !element) return false;

  const probe = range.cloneRange();
  probe.selectNodeContents(element);
  probe.setStart(range.endContainer, range.endOffset);
  return (probe.toString() || '').trim() === '';
}

function exitRichTextList(field, listTag) {
  const range = getRichTextRange(field);
  if (!range || !range.collapsed) return false;

  const list = closestRichTextElement(range.startContainer, listTag);
  if (!list) return false;

  const paragraph = createRichTextParagraph();
  list.insertAdjacentElement('afterend', paragraph);
  placeCaretAtNodeStart(paragraph);
  saveRichTextSelection(field);
  return true;
}

function exitRichTextBlockquote(field) {
  const range = getRichTextRange(field);
  if (!range || !range.collapsed) return false;

  const quote = closestRichTextElement(range.startContainer, 'blockquote');
  if (!quote) return false;

  const paragraph = createRichTextParagraph();
  quote.insertAdjacentElement('afterend', paragraph);
  placeCaretAtNodeStart(paragraph);
  saveRichTextSelection(field);
  return true;
}

function richTextHasContent(editor) {
  if (!editor) return false;
  const text = (editor.textContent || '').replace(/\s+/g, ' ').trim();
  return text !== '' || Boolean(editor.querySelector('hr'));
}

function syncRichTextField(field) {
  if (!field) return;
  const editor = field.querySelector('[data-richtext-editor]');
  const input = field.querySelector('[data-richtext-input]');
  if (!editor || !input) return;

  const html = editor.innerHTML.trim();
  const cleaned = html === '<br>' || html === '<p><br></p>' ? '' : html;
  input.value = cleaned;
  editor.classList.toggle('is-empty', !richTextHasContent(editor));
}

function bindRichTextForm(form) {
  if (!form || form.dataset.richtextBound === '1') return;
  form.dataset.richtextBound = '1';
  form.addEventListener('submit', () => {
    form.querySelectorAll('[data-richtext-field]').forEach((field) => syncRichTextField(field));
  });
}

function updateRichTextToolbar(field) {
  if (!field) return;

  const stateMap = {
    bold: 'bold',
    italic: 'italic',
    underline: 'underline',
    insertUnorderedList: 'insertUnorderedList',
    insertOrderedList: 'insertOrderedList',
  };

  field.querySelectorAll('[data-richtext-action]').forEach((button) => {
    const action = button.dataset.richtextAction || '';
    let isActive = false;

    if (stateMap[action] && typeof document.queryCommandState === 'function') {
      try {
        isActive = document.queryCommandState(stateMap[action]);
      } catch (_error) {
        isActive = false;
      }
    } else if (action === 'formatBlock' && typeof document.queryCommandValue === 'function') {
      try {
        const current = (document.queryCommandValue('formatBlock') || '').toString().replace(/[<>]/g, '').toUpperCase();
        isActive = current === (button.dataset.richtextValue || '').toUpperCase();
      } catch (_error) {
        isActive = false;
      }
    }

    button.classList.toggle('is-active', isActive);
  });
}

function handleRichTextCommand(button) {
  const field = button.closest('[data-richtext-field]') || activeRichTextField;
  if (!field) return;

  const editor = field.querySelector('[data-richtext-editor]');
  if (!editor) return;

  const action = button.dataset.richtextAction || '';
  const value = (button.dataset.richtextValue || '').toUpperCase();
  const hadSelection = restoreRichTextSelection(field);

  if (!hadSelection) {
    editor.focus();
  }

  if (action === 'insertUnorderedList' && exitRichTextList(field, 'ul')) {
    syncRichTextField(field);
    updateRichTextToolbar(field);
    return;
  }

  if (action === 'insertOrderedList' && exitRichTextList(field, 'ol')) {
    syncRichTextField(field);
    updateRichTextToolbar(field);
    return;
  }

  if (action === 'formatBlock' && value === 'BLOCKQUOTE' && exitRichTextBlockquote(field)) {
    syncRichTextField(field);
    updateRichTextToolbar(field);
    return;
  }

  if (action === 'formatBlock' && value === 'P' && closestRichTextElement(getRichTextRange(field)?.startContainer || null, 'blockquote')) {
    if (exitRichTextBlockquote(field)) {
      syncRichTextField(field);
      updateRichTextToolbar(field);
      return;
    }
  }

  if (action === 'createLink') {
    saveRichTextSelection(field);
    const href = window.prompt('Enter the link URL', 'https://');
    if (!href) return;
    restoreRichTextSelection(field);
    document.execCommand('createLink', false, href.trim());
  } else if (action === 'formatBlock') {
    const blockMap = {
      P: '<p>',
      H2: '<h2>',
      H3: '<h3>',
      H4: '<h4>',
      BLOCKQUOTE: '<blockquote>',
    };
    document.execCommand('formatBlock', false, blockMap[value] || '<p>');
  } else {
    document.execCommand(action, false, null);
  }

  saveRichTextSelection(field);
  syncRichTextField(field);
  updateRichTextToolbar(field);
}

function initRichTextFields(scope = document) {
  scope.querySelectorAll('[data-richtext-field]').forEach((field) => {
    const editor = field.querySelector('[data-richtext-editor]');
    const input = field.querySelector('[data-richtext-input]');
    if (!editor || !input || field.dataset.richtextReady === '1') return;

    field.dataset.richtextReady = '1';
    editor.innerHTML = input.value.trim() !== '' ? input.value : '';
    syncRichTextField(field);
    bindRichTextForm(field.closest('form'));

    editor.addEventListener('focus', () => {
      saveRichTextSelection(field);
      updateRichTextToolbar(field);
    });
    editor.addEventListener('keyup', () => {
      saveRichTextSelection(field);
      updateRichTextToolbar(field);
    });
    editor.addEventListener('mouseup', () => {
      saveRichTextSelection(field);
      updateRichTextToolbar(field);
    });
    editor.addEventListener('blur', () => {
      saveRichTextSelection(field);
      syncRichTextField(field);
    });
    editor.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.shiftKey) return;

      const range = getRichTextRange(field);
      if (!range || !range.collapsed) return;

      const quote = closestRichTextElement(range.startContainer, 'blockquote');
      if (!quote) return;

      const quoteText = (quote.textContent || '').replace(/\s+/g, ' ').trim();
      if (quoteText === '' || isRangeAtEndOfElement(range, quote)) {
        event.preventDefault();
        const paragraph = createRichTextParagraph();
        quote.insertAdjacentElement('afterend', paragraph);
        placeCaretAtNodeStart(paragraph);
        saveRichTextSelection(field);
        syncRichTextField(field);
        updateRichTextToolbar(field);
      }
    });
    editor.addEventListener('input', () => {
      saveRichTextSelection(field);
      syncRichTextField(field);
      updateRichTextToolbar(field);
    });

    editor.addEventListener('paste', (event) => {
      event.preventDefault();
      const text = (event.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });
  });
}

function renderCatalogRepeater(editor, name, items) {
  const repeater = editor.querySelector(`[data-editor-list="${name}"]`);
  if (!repeater) return;

  const list = repeater.querySelector('.admin-repeater-list');
  if (!list) return;

  const configs = {
    'size-choices': { templateId: 'tpl-product-size-choice', token: '__PRODUCT_SIZE_INDEX__' },
    'metal-options': { templateId: 'tpl-product-detail-option', token: '__PRODUCT_METAL_INDEX__' },
    'band-options': { templateId: 'tpl-product-band-option', token: '__PRODUCT_BAND_INDEX__' },
    'diamond-rows': { templateId: 'tpl-product-diamond-row', token: '__PRODUCT_DIAMOND_INDEX__' },
  };

  const config = configs[name];
  if (!config) return;

  list.innerHTML = '';
  const rows = Array.isArray(items) ? items : [];
  repeater.dataset.nextIndex = String(rows.length);

  rows.forEach((item, index) => {
    const node = createRepeaterNode(config.templateId, config.token, index);
    if (!node) return;

    if (name === 'color-choices') {
      setNodeValue(node, 'input[name$="[label]"]', item.label || '');
      setNodeValue(node, 'input[name$="[kicker]"]', item.kicker || '');
      setNodeValue(node, 'select[name$="[tone]"]', item.tone || 'classic');
    } else if (name === 'size-choices') {
      setNodeValue(node, 'input[name$="[label]"]', item.label || '');
      setNodeValue(node, 'input[name$="[caption]"]', item.caption || '');
    } else if (name === 'metal-options' || name === 'band-options') {
      setNodeValue(node, 'input[name$="[label]"]', item.label || '');
      const textArea = node.querySelector('textarea[name$="[description]"]');
      if (textArea) {
        textArea.value = item.description || '';
      }
    } else if (name === 'diamond-rows') {
      setNodeValue(node, 'select[name$="[shape]"]', item.shape || 'all');
      setNodeValue(node, 'input[name$="[title]"]', item.title || '');
      setNodeValue(node, 'input[name$="[carat]"]', item.carat || '');
      setNodeValue(node, 'input[name$="[price]"]', item.price ?? '0');
      setNodeValue(node, 'input[name$="[color]"]', item.color || '');
      setNodeValue(node, 'input[name$="[clarity]"]', item.clarity || '');
      setNodeValue(node, 'input[name$="[cut]"]', item.cut || '');
      setNodeValue(node, 'input[name$="[ratio]"]', item.ratio || '');
      setNodeValue(node, 'input[name$="[measurement]"]', item.measurement || '');
      setNodeValue(node, 'input[name$="[ref]"]', item.ref || '');
      setNodeValue(node, 'input[name$="[igi_certificate]"]', item.igi_certificate || '');
      setNodeValue(node, 'input[name$="[badge]"]', item.badge || '');
      setNodeValue(node, 'input[name$="[image]"]', item.image || '');
      setNodeValue(node, 'select[name$="[status]"]', item.status || 'active');
      const textArea = node.querySelector('textarea[name$="[description]"]');
      if (textArea) {
        textArea.value = item.description || '';
      }
    }

    list.appendChild(node);
  });
}

function applyCatalogProfile(form, profile) {
  if (!form || !profile) return;
  const editor = form.querySelector('[data-catalog-profile-editor]');
  if (!editor) return;

  setNodeValue(editor, 'input[name="product[option_color_label]"]', profile.option_color_label || '');
  setNodeValue(editor, 'input[name="product[option_size_label]"]', profile.option_size_label || '');

  renderCatalogRepeater(editor, 'size-choices', profile.option_size_choices || []);
  renderCatalogRepeater(editor, 'metal-options', profile.option_metal_options || []);
  renderCatalogRepeater(editor, 'band-options', profile.option_band_claw_metal_options || []);
  renderCatalogRepeater(editor, 'diamond-rows', profile.diamond_inventory || []);

  editor.querySelectorAll('input[name="product[styles][]"], input[name="product[diamondShapes][]"]').forEach((input) => {
    input.checked = false;
  });
}

// The visible Category dropdown is the single source of truth for a product's
// type. Its data-category-type-map (category key -> canonical product type) is
// mirrored into the hidden product[product_type] select on change, which in turn
// drives the Metal Matrix + attribute profile via the existing scope sync. This
// replaces the old buried "Advanced: internal product type" select that was never
// wired to the Category control, leaving the matrix stuck on Ring for every type.
function syncCategoryToProductType(scope = document) {
  scope.querySelectorAll('select[name="product[category_taxonomy]"][data-category-type-map]').forEach((categorySelect) => {
    const form = categorySelect.closest('form');
    if (!form) return;
    const typeSelect = form.querySelector('select[name="product[product_type]"]');
    if (!typeSelect) return;

    let map = {};
    try {
      map = JSON.parse(categorySelect.dataset.categoryTypeMap || '{}');
    } catch (_error) {
      map = {};
    }

    // Clearing the Category must clear the type too, and still re-sync — the old
    // early return left the previously chosen category's metals on screen.
    const mapped = map[categorySelect.value || ''] || '';
    if (typeSelect.value !== mapped) {
      typeSelect.value = mapped;
    }

    syncProductEditorScopes(form);
    syncCatalogProductEditors(form);
  });
}

function syncCatalogProductEditors(scope = document) {
  const profilesNode = document.getElementById('catalog-attribute-profiles');
  if (!profilesNode) return;

  let profiles = {};
  try {
    profiles = JSON.parse(profilesNode.textContent || '{}');
  } catch (_error) {
    profiles = {};
  }

  scope.querySelectorAll('form [data-catalog-profile-editor]').forEach((editor) => {
    const form = editor.closest('form');
    const select = form ? form.querySelector('select[name="product[product_type]"]') : null;
    if (!form || !select) return;

    const currentType = select.value || '';
    if (!form.dataset.catalogTypeInitialised) {
      form.dataset.catalogTypeInitialised = currentType;
      return;
    }

    if (form.dataset.catalogTypeInitialised === currentType) {
      return;
    }

    const profile = profiles[currentType];
    if (profile) {
      applyCatalogProfile(form, profile);
    }
    form.dataset.catalogTypeInitialised = currentType;
  });
}

document.addEventListener('change', (event) => {
  if (event.target.matches('[data-coupon-type]')) {
    syncCouponFields(event.target.closest('form') || document);
  }

  if (event.target.matches('select[name="product[product_type]"]')) {
    syncProductEditorScopes(event.target.closest('form') || document);
    syncCatalogProductEditors(event.target.closest('form') || document);
  }

  if (event.target.matches('select[name="product[category_taxonomy]"]')) {
    syncCategoryToProductType(event.target.closest('form') || document);
  }

  if (!event.target.matches('input[type="file"]') && event.target.closest('#attribute-profile form, #attribute-editor form')) {
    scheduleAttributeDraftSave(event.target.closest('form'));
  }
});

document.addEventListener('input', (event) => {
  // Some browsers fire `input` (not `change`) when a select value is restored or
  // set programmatically, so mirror the category handler here too.
  if (event.target.matches && event.target.matches('select[name="product[category_taxonomy]"]')) {
    syncCategoryToProductType(event.target.closest('form') || document);
  }

  if (event.target.closest('#attribute-profile form, #attribute-editor form')) {
    scheduleAttributeDraftSave(event.target.closest('form'));
  }
});

document.addEventListener('mousedown', (event) => {
  if (event.target.closest('[data-richtext-action]')) {
    event.preventDefault();
  }
});

document.addEventListener('pointerdown', (event) => {
  const button = event.target.closest('[data-richtext-action]');
  if (!button) return;
  event.preventDefault();
  button.dataset.richtextPointerHandled = '1';
  handleRichTextCommand(button);
});

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-richtext-action]');
  if (!button) return;

  if (button.dataset.richtextPointerHandled === '1') {
    delete button.dataset.richtextPointerHandled;
    return;
  }

  handleRichTextCommand(button);
});

document.addEventListener('selectionchange', () => {
  const selection = window.getSelection();
  if (!selection || selection.rangeCount === 0) return;

  const editor = selection.anchorNode instanceof Node
    ? (selection.anchorNode.nodeType === Node.ELEMENT_NODE ? selection.anchorNode : selection.anchorNode.parentElement)?.closest('[data-richtext-editor]')
    : null;

  if (!editor) return;

  const field = editor.closest('[data-richtext-field]');
  if (!field) return;

  saveRichTextSelection(field);
  updateRichTextToolbar(field);
});

// Initial sync. This must run again after DOMContentLoaded and after pageshow:
// browsers restore <select> values (soft reload, back/forward, bfcache) AFTER
// the parse-time pass, so a restored Category would otherwise leave the form
// showing the "choose a category" state while a category is visibly selected.
function syncAdminProductEditors() {
  resetMetalPriceAdjustmentButtons();
  syncMetalShapeMediaPickers();
  syncCouponFields();
  syncCategoryToProductType();
  syncProductEditorScopes();
  syncCatalogProductEditors();
}

syncAdminProductEditors();
clearOldAttributeDrafts();
restoreAttributeDrafts();
removeRetiredAttributeControls();
initRichTextFields();

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', syncAdminProductEditors);
}
window.addEventListener('pageshow', () => {
  document.querySelectorAll('#attribute-profile form, #attribute-editor form').forEach((form) => {
    submittingAttributeForms.delete(form);
  });
  syncAdminProductEditors();
});

function syncAdminAnchorNav() {
  const nav = document.querySelector('.admin-anchor-nav');
  if (!nav) return;

  const links = [...nav.querySelectorAll('a[href^="#"]')];
  if (!links.length) return;

  const sections = links
    .map((link) => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);

  const setActive = () => {
    let activeId = '';
    for (const section of sections) {
      const rect = section.getBoundingClientRect();
      if (rect.top <= 140 && rect.bottom >= 140) {
        activeId = `#${section.id}`;
        break;
      }
    }

    links.forEach((link) => {
      link.classList.toggle('is-active', link.getAttribute('href') === activeId);
    });
  };

  links.forEach((link) => {
    link.addEventListener('click', (event) => {
      const targetSelector = link.getAttribute('href') || '';
      const target = targetSelector.startsWith('#') ? document.querySelector(targetSelector) : null;
      if (target) {
        event.preventDefault();
        const top = target.getBoundingClientRect().top + window.scrollY - 110;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        target.classList.remove('admin-target-flash');
        window.requestAnimationFrame(() => target.classList.add('admin-target-flash'));
        window.setTimeout(() => target.classList.remove('admin-target-flash'), 1400);
      }
      links.forEach((item) => item.classList.remove('is-active'));
      link.classList.add('is-active');
    });
  });

  window.addEventListener('scroll', setActive, { passive: true });
  setActive();
}

syncAdminAnchorNav();

// Mirrors order_tracking_statuses() in includes/content.php — keep in sync.
const ORDER_TRACKING_STATUSES = ['shipped', 'out-for-delivery', 'delivered'];

function syncOrderStatusForms(scope = document) {
  scope.querySelectorAll('[data-order-status-form]').forEach((form) => {
    const select = form.querySelector('[data-order-status-select]');
    const field = form.querySelector('[data-order-tracking-field]');
    if (!select || !field) return;
    field.hidden = !ORDER_TRACKING_STATUSES.includes(select.value);
  });
}

document.addEventListener('change', (event) => {
  const select = event.target.closest?.('[data-order-status-select]');
  if (!select) return;
  const form = select.closest('[data-order-status-form]');
  const field = form?.querySelector('[data-order-tracking-field]');
  if (!field) return;
  field.hidden = !ORDER_TRACKING_STATUSES.includes(select.value);
  if (!field.hidden) {
    field.querySelector('input')?.focus();
  }
});

syncOrderStatusForms();
window.addEventListener('pageshow', () => syncOrderStatusForms());
