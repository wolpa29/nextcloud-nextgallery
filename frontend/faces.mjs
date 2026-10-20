/**
 * NextGallery People (Faces) Frontend Module
 * Implements responsive cards, subject media grid, actions, pagination, review, and reset.
 */

export function buildSubjectsQuery({gallery, cursor, limit = 60, filter = 'all'} = {}) {
  const params = new URLSearchParams();
  if (gallery) params.set('gallery', gallery);
  if (cursor !== undefined && cursor !== null && cursor !== '') params.set('cursor', String(cursor));
  if (limit) params.set('limit', String(limit));
  if (filter && ['all', 'hidden', 'review', 'single', 'filtered'].includes(filter)) {
    params.set('filter', filter);
  } else {
    params.set('filter', 'all');
  }
  return params.toString();
}

export function buildActionPayload({action, target_id, detection_ids, rules_revision}) {
  const allowed = ['hide', 'show', 'merge', 'split', 'remove', 'resolve'];
  if (!allowed.includes(action)) {
    throw new Error(`Invalid action "${action}". Allowed: ${allowed.join(', ')}`);
  }
  if (typeof rules_revision !== 'number') {
    throw new Error('rules_revision must be an integer');
  }
  const payload = {
    action,
    rules_revision
  };
  if (target_id) {
    payload.target_id = String(target_id);
  }
  if (Array.isArray(detection_ids) && detection_ids.length > 0) {
    payload.detection_ids = detection_ids.map(String);
  }
  return payload;
}

export function buildResetPayload(confirmation) {
  if (confirmation !== 'faces') {
    throw new Error('Reset confirmation must be "faces"');
  }
  return { confirmation: 'faces' };
}

export function formatPhotoCount(n, tr = (k, v) => (v && v.count !== undefined ? k.replace('{count}', String(v.count)) : k), locale = 'de') {
  const num = Number(n) || 0;
  if (num === 1) return tr('1 Foto');
  const formatted = new Intl.NumberFormat(locale).format(num);
  return tr('{count} Fotos', { count: formatted }).replaceAll('{count}',formatted);
}

export function formatPeopleCount(n, tr = (k, v) => (v && v.count !== undefined ? k.replace('{count}', String(v.count)) : k), locale = 'de') {
  const num = Number(n) || 0;
  if (num === 1) return tr('1 Person');
  const formatted = new Intl.NumberFormat(locale).format(num);
  return tr('{count} Personen', { count: formatted }).replaceAll('{count}',formatted);
}

export function createPeopleController({
  root,
  dialogRoot = (typeof document !== 'undefined' ? document : root),
  api,
  base = '',
  getGallery = () => 'default',
  tr: translate = (k) => k,
  locale = 'de',
  openViewer = () => {},
  imageURL = async () => null,
  previewUrl = (detectionId) => `${base}/api/v1/subjects/preview/${detectionId}`
}) {
  if (!root) return null;
  const tr=(key,values={})=>translate(key,values).replace(/\{(\w+)\}/g,(match,name)=>Object.hasOwn(values,name)?String(values[name]):match);

  const $ = (id) => {
    if (!root) return null;
    let el = root.querySelector('#' + id);
    if (!el && id.startsWith('ch-people-')) el = root.querySelector('#' + id.replace('ch-people-', 'ch-person-'));
    if (!el && id.startsWith('ch-person-')) el = root.querySelector('#' + id.replace('ch-person-', 'ch-people-'));
    return el;
  };
  const $$ = (sel) => root ? root.querySelectorAll(sel) : [];
  const $d = (id) => {
    let el = dialogRoot ? dialogRoot.querySelector('#' + id) : null;
    if (!el && root) el = root.querySelector('#' + id);
    return el;
  };

  let filter = 'all';
  let subjects = [];
  let cursor = null;
  let more = false;
  let rulesRevision = 0;
  let epoch = '';
  let generation = 0;
  let loading = false;
  let selectedSubject = null;
  let subjectMedia = [];
  let subjectCursor = null;
  let subjectMore = false;
  let selectedDetections = new Map(); // itemId => string[] (all detection IDs for that item)
  let currentGallery = getGallery();
  let activeGeneration = 0;
  let currentAbort = null;

  function setLoading(isLoading) {
    loading = isLoading;
    const loadEl = $('ch-people-loading');
    if (loadEl) loadEl.hidden = !isLoading;
    const refreshBtn = $('ch-people-refresh');
    if (refreshBtn) refreshBtn.disabled = isLoading;
    const resetBtn = $('ch-people-reset');
    if (resetBtn) resetBtn.disabled = isLoading;
    const moreBtn = $('ch-people-more');
    if (moreBtn) moreBtn.disabled = isLoading;
    $$('[data-people-filter]').forEach((b) => { b.disabled = isLoading; });
  }

  function showError(msg) {
    const el = $('ch-people-error');
    if (!el) return;
    if (msg) {
      el.textContent = typeof msg === 'string' ? msg : (msg.message || tr('Die Anfrage konnte nicht abgeschlossen werden.'));
      el.hidden = false;
    } else {
      el.textContent = '';
      el.hidden = true;
    }
  }

  function clearError() {
    showError(null);
  }

  async function loadSubjects(reset = true) {
    if (loading && !reset) return;
    if (currentAbort) {
      currentAbort.abort();
    }
    currentAbort = new AbortController();
    const signal = currentAbort.signal;
    const reqGen = ++activeGeneration;
    const reqGallery = getGallery();

    setLoading(true);
    clearError();

    if (reset) {
      cursor = null;
      subjects = [];
      const grid = $('ch-people-grid');
      if (grid) grid.replaceChildren();
    }

    try {
      const query = buildSubjectsQuery({
        gallery: reqGallery,
        cursor: reset ? '' : cursor,
        limit: 60,
        filter
      });

      const data = await api('subjects?' + query, { signal });
      if (reqGen !== activeGeneration || reqGallery !== getGallery()) return;

      const newItems = Array.isArray(data.items) ? data.items : [];
      subjects = reset ? newItems : [...subjects, ...newItems];
      cursor = data.next ?? null;
      more = Boolean(data.more);
      if (typeof data.rules_revision === 'number') rulesRevision = data.rules_revision;
      if (data.epoch) epoch = data.epoch;
      if (typeof data.generation === 'number') generation = data.generation;

      renderSubjectsList();
    } catch (err) {
      if (err.name !== 'AbortError' && reqGen === activeGeneration && reqGallery === getGallery()) {
        showError(err);
      }
    } finally {
      if (reqGen === activeGeneration) {
        setLoading(false);
      }
    }
  }

  function renderSubjectsList() {
    const listWrap = $('ch-people-list-container');
    const detailWrap = $('ch-people-detail');
    const emptyState = $('ch-people-empty');
    const grid = $('ch-people-grid');
    const moreWrap = $('ch-people-more-wrap');

    if (detailWrap) detailWrap.hidden = true;
    if (listWrap) listWrap.hidden = false;

    if (!grid) return;
    grid.replaceChildren();

    if (subjects.length === 0) {
      if (emptyState) {
        emptyState.hidden = false;
        const hint = $('ch-people-empty-hint');
        if (hint) {
          hint.textContent = filter === 'all'
            ? tr('Starte die Personenerkennung in der KI-Verarbeitung, um Fotos nach Personen zu sortieren.')
            : filter === 'filtered'
            ? tr('Keine gefilterten Gesichter gefunden')
            : tr('Keine Personen in dieser Ansicht.');
        }
      }
      if (moreWrap) moreWrap.hidden = true;
      return;
    }

    if (emptyState) emptyState.hidden = true;

    for (const subject of subjects) {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'ch-person-card';
      card.dataset.id = subject.id;
      card.setAttribute('role', 'listitem');
      card.setAttribute('aria-label', formatPhotoCount(subject.photo_count, tr, locale));

      const thumbWrap = document.createElement('div');
      thumbWrap.className = 'ch-person-thumb-wrap';

      if (subject.cover_id) {
        const img = document.createElement('img');
        img.className = 'ch-person-thumb';
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.src = previewUrl(subject.cover_id);
        img.onerror = () => {
          img.replaceWith(createGenericPersonIcon());
        };
        thumbWrap.append(img);
      } else {
        thumbWrap.append(createGenericPersonIcon());
      }

      // Filtered badge
      if (subject.filtered) {
        const filtBadge = document.createElement('span');
        filtBadge.className = 'ch-person-badge ch-person-badge-filtered';
        filtBadge.textContent = tr('Gefiltert');
        thumbWrap.append(filtBadge);
      }

      // Review badge
      if (subject.review_count && subject.review_count > 0) {
        const revBadge = document.createElement('span');
        revBadge.className = 'ch-person-badge ch-person-badge-review';
        revBadge.textContent = tr('{count} zu prüfen', { count: subject.review_count });
        thumbWrap.append(revBadge);
      }

      // Hidden badge
      if (subject.hidden) {
        const hideBadge = document.createElement('span');
        hideBadge.className = 'ch-person-badge ch-person-badge-hidden';
        hideBadge.textContent = tr('Ausgeblendet');
        thumbWrap.append(hideBadge);
      }

      card.append(thumbWrap);

      // Photo count ONLY - NO NAMES as required by contract
      const countLabel = document.createElement('span');
      countLabel.className = 'ch-person-count';
      countLabel.textContent = formatPhotoCount(subject.photo_count, tr, locale);
      card.append(countLabel);

      card.onclick = () => openSubject(subject);
      grid.append(card);
    }

    if (moreWrap) {
      moreWrap.hidden = !more;
    }
  }

  function createGenericPersonIcon() {
    const span = document.createElement('span');
    span.className = 'ch-person-placeholder';
    span.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M6 21v-2a6 6 0 0 1 12 0v2"/></svg>';
    return span;
  }

  async function openSubject(subject) {
    selectedSubject = subject;
    selectedDetections.clear();
    clearError();

    const listWrap = $('ch-people-list-container');
    const detailWrap = $('ch-people-detail');
    if (listWrap) listWrap.hidden = true;
    if (detailWrap) detailWrap.hidden = false;

    // Update header info
    const title = $('ch-people-detail-title');
    if (title) title.textContent = tr('Person');

    const count = $('ch-people-detail-count');
    if (count) count.textContent = formatPhotoCount(subject.photo_count, tr, locale);

    const hideBtn = $('ch-people-toggle-hide');
    if (hideBtn) {
      hideBtn.textContent = subject.hidden ? tr('Einblenden') : tr('Ausblenden');
    }

    const isFiltered = Boolean(subject.filtered || filter === 'filtered');
    const hasReview = Boolean(subject.review_count && subject.review_count > 0);
    const resolveBtn = $('ch-people-resolve');
    if (resolveBtn) {
      resolveBtn.hidden = !(hasReview || isFiltered);
      resolveBtn.textContent = isFiltered ? tr('Bestätigen') : tr('Überprüfung auflösen');
    }

    const filterNote = $('ch-people-detail-filter-note');
    if (filterNote) {
      filterNote.hidden = !isFiltered;
    }

    updateSelectionBar();
    await loadSubjectMedia(true);
  }

  async function loadSubjectMedia(reset = true) {
    if (!selectedSubject) return;
    if (loading && !reset) return;
    if (currentAbort) {
      currentAbort.abort();
    }
    currentAbort = new AbortController();
    const signal = currentAbort.signal;
    const reqGen = ++activeGeneration;
    const reqGallery = getGallery();

    setLoading(true);
    clearError();

    if (reset) {
      subjectCursor = null;
      subjectMedia = [];
      const grid = $('ch-people-media-grid');
      if (grid) grid.replaceChildren();
    }

    try {
      const q = new URLSearchParams({
        gallery: reqGallery,
        limit: '60',
        filter: filter || 'all'
      });
      if (!reset && subjectCursor) q.set('cursor', String(subjectCursor));

      const data = await api(`subjects/${encodeURIComponent(selectedSubject.id)}/media?` + q.toString(), { signal });
      if (reqGen !== activeGeneration || reqGallery !== getGallery()) return;

      const newItems = Array.isArray(data.items) ? data.items : [];
      subjectMedia = reset ? newItems : [...subjectMedia, ...newItems];
      subjectCursor = data.next ?? null;
      subjectMore = Boolean(data.more);
      if (typeof data.rules_revision === 'number') rulesRevision = data.rules_revision;

      renderSubjectMedia();
    } catch (err) {
      if (err.name !== 'AbortError' && reqGen === activeGeneration && reqGallery === getGallery()) {
        showError(err);
      }
    } finally {
      if (reqGen === activeGeneration) {
        setLoading(false);
      }
    }
  }

  function renderSubjectMedia() {
    const grid = $('ch-people-media-grid');
    const moreWrap = $('ch-people-media-more-wrap');
    if (!grid) return;

    grid.replaceChildren();

    for (const item of subjectMedia) {
      const tile = document.createElement('div');
      tile.className = 'ch-person-media-tile';
      tile.dataset.id = String(item.id);

      const isSelected = selectedDetections.has(item.id);
      if (isSelected) tile.classList.add('is-selected');

      const check = document.createElement('input');
      check.type = 'checkbox';
      check.className = 'ch-person-media-select';
      check.checked = isSelected;
      check.setAttribute('aria-label', tr('Foto auswählen'));
      check.onclick = (e) => {
        e.stopPropagation();
        toggleSelectMedia(item);
      };
      tile.append(check);

      const img = document.createElement('img');
      img.alt = item.name || '';
      img.loading = 'lazy';
      img.decoding = 'async';

      imageURL(item).then((url) => {
        if (url && tile.isConnected) img.src = url;
      }).catch(() => {});

      img.onclick = () => {
        if (selectedDetections.size > 0) {
          toggleSelectMedia(item);
        } else {
          openViewer(item, subjectMedia);
        }
      };
      tile.append(img);

      grid.append(tile);
    }

    if (moreWrap) {
      moreWrap.hidden = !subjectMore;
    }
  }

  function toggleSelectMedia(item) {
    if (selectedDetections.has(item.id)) {
      selectedDetections.delete(item.id);
    } else {
      // Honest detection IDs: select all detection IDs belonging to this file for this subject
      const detIds = Array.isArray(item.face_detection_ids)
        ? item.face_detection_ids.filter(Boolean)
        : (item.detection_id ? [item.detection_id] : []);
      if (detIds.length > 0) {
        selectedDetections.set(item.id, detIds);
      } else {
        // Never fallback to file_id as detection_id
        console.warn('Item has no face detection IDs', item.id);
        return;
      }
    }
    updateSelectionBar();
    renderSubjectMedia();
  }

  function updateSelectionBar() {
    const bar = $('ch-people-selection-bar');
    const countLabel = $('ch-people-selection-count');
    if (!bar) return;

    const count = selectedDetections.size;
    bar.hidden = count === 0;
    if (countLabel) {
      countLabel.textContent = formatPhotoCount(count, tr, locale) + ' ' + tr('ausgewählt');
    }
  }

  function clearSelection() {
    selectedDetections.clear();
    updateSelectionBar();
    renderSubjectMedia();
  }

  async function performSubjectAction(action, { target_id, detection_ids, confirmTitle, confirmText, confirmOkLabel, isDestructive = false } = {}) {
    if (!selectedSubject) return;

    if (isDestructive && confirmTitle) {
      const ok = await showActionConfirm(confirmTitle, confirmText, confirmOkLabel || tr('Bestätigen'), true);
      if (!ok) return; // Fail closed if cancelled or dialog missing
    }

    clearError();
    try {
      const payload = buildActionPayload({
        action,
        target_id,
        detection_ids,
        rules_revision: rulesRevision
      });

      await api(`subjects/${encodeURIComponent(selectedSubject.id)}/action`, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      // After action, refresh view
      selectedDetections.clear();
      if (['merge', 'hide'].includes(action) || (action === 'resolve' && filter === 'filtered')) {
        await backToList();
      } else {
        await loadSubjectMedia(true);
      }
    } catch (err) {
      showError(err);
      if (err.retryable || err.status === 409) {
        // rules_revision conflict: reload to synchronize authoritative state
        if (selectedSubject) await loadSubjectMedia(true);
        else await loadSubjects(true);
      }
    }
  }

  async function backToList() {
    selectedSubject = null;
    selectedDetections.clear();
    clearError();
    const listWrap = $('ch-people-list-container');
    const detailWrap = $('ch-people-detail');
    if (detailWrap) detailWrap.hidden = true;
    if (listWrap) listWrap.hidden = false;
    const filterNote = $('ch-people-detail-filter-note');
    if (filterNote) filterNote.hidden = true;
    await loadSubjects(true); // reset=true prevents stale groups and duplicates
  }

  function showActionConfirm(title, text, okLabel, isDanger = false) {
    const dialog = $d('ch-people-action-dialog');
    if (!dialog) {
      console.error('Confirmation dialog not found; failing closed');
      return Promise.resolve(false); // Fail closed!
    }

    const titleEl = $d('ch-people-action-title');
    const textEl = $d('ch-people-action-text');
    const okBtn = $d('ch-people-action-ok');
    const cancelBtn = $d('ch-people-action-cancel');
    if (!titleEl || !textEl || !okBtn || !cancelBtn) {
      console.error('Confirmation dialog elements missing; failing closed');
      return Promise.resolve(false);
    }

    titleEl.textContent = title;
    textEl.textContent = text;
    okBtn.textContent = okLabel;
    okBtn.classList.toggle('ch-danger', isDanger);
    okBtn.classList.toggle('ch-primary', !isDanger);

    return new Promise((resolve) => {
      let settled = false;
      const done = (val) => {
        if (settled) return;
        settled = true;
        dialog.close();
        resolve(val);
      };
      okBtn.onclick = () => done(true);
      cancelBtn.onclick = () => done(false);
      dialog.oncancel = (e) => {
        e.preventDefault();
        done(false);
      };
      dialog.showModal();
      cancelBtn.focus();
    });
  }

  // Hook toolbar filter buttons and select
  $$('[data-people-filter]').forEach((btn) => {
    btn.onclick = () => {
      const targetFilter = btn.dataset.peopleFilter;
      if (filter === targetFilter) return;
      filter = targetFilter;
      $$('[data-people-filter]').forEach((b) => b.classList.toggle('active', b === btn));
      const sel = $('ch-people-filter');
      if (sel) sel.value = filter;
      loadSubjects(true);
    };
  });

  const filterSelect = $('ch-people-filter');
  if (filterSelect) {
    filterSelect.onchange = () => {
      const targetFilter = filterSelect.value;
      if (filter === targetFilter) return;
      filter = targetFilter;
      $$('[data-people-filter]').forEach((b) => b.classList.toggle('active', b.dataset.peopleFilter === filter));
      loadSubjects(true);
    };
  }

  const refreshBtn = $('ch-people-refresh');
  if (refreshBtn) {
    refreshBtn.onclick = () => {
      if (selectedSubject) {
        loadSubjectMedia(true);
      } else {
        loadSubjects(true);
      }
    };
  }

  const moreBtn = $('ch-people-more');
  if (moreBtn) {
    moreBtn.onclick = () => {
      if (more && !loading) {
        loadSubjects(false);
      }
    };
  }

  const mediaMoreBtn = $('ch-people-media-more');
  if (mediaMoreBtn) {
    mediaMoreBtn.onclick = () => {
      if (subjectMore && !loading) {
        loadSubjectMedia(false);
      }
    };
  }

  const backBtn = $('ch-people-back');
  if (backBtn) {
    backBtn.onclick = () => backToList();
  }

  // Toggle hide/show
  const hideBtn = $('ch-people-toggle-hide');
  if (hideBtn) {
    hideBtn.onclick = async () => {
      if (!selectedSubject) return;
      const action = selectedSubject.hidden ? 'show' : 'hide';
      await performSubjectAction(action);
    };
  }

  // Resolve review
  const resolveBtn = $('ch-people-resolve');
  if (resolveBtn) {
    resolveBtn.onclick = async () => {
      await performSubjectAction('resolve');
    };
  }

  // Merge person
  const mergeBtn = $('ch-people-merge');
  if (mergeBtn) {
    mergeBtn.onclick = () => openMergeDialog();
  }

  async function openMergeDialog() {
    if (!selectedSubject) return;
    const dialog = $d('ch-people-merge-dialog');
    const container = $d('ch-people-merge-targets');
    const okBtn = $d('ch-people-merge-ok');
    const cancelBtn = $d('ch-people-merge-cancel');
    const searchInput = $d('ch-people-merge-search');
    const moreWrap = $d('ch-people-merge-more-wrap');
    const moreBtn = $d('ch-people-merge-more');
    if (!dialog || !container || !okBtn) {
      console.error('Merge dialog not found; failing closed');
      return;
    }

    let mergeFilter = 'all';
    let mergeCursor = null;
    let mergeMore = false;
    let mergeTargets = [];
    let selectedTargetId = null;
    let mergeLoading = false;

    okBtn.disabled = true;
    if (searchInput) searchInput.value = '';

    const filterBtns = dialog.querySelectorAll('[data-merge-filter]');
    filterBtns.forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.mergeFilter === mergeFilter);
      btn.onclick = () => {
        if (mergeFilter === btn.dataset.mergeFilter) return;
        mergeFilter = btn.dataset.mergeFilter;
        filterBtns.forEach((b) => b.classList.toggle('active', b === btn));
        loadMergeTargets(true);
      };
    });

    async function loadMergeTargets(reset = true) {
      if (mergeLoading && !reset) return;
      mergeLoading = true;
      if (reset) {
        mergeCursor = null;
        mergeTargets = [];
        container.replaceChildren();
        const loadingP = document.createElement('p');
        loadingP.className = 'ch-hint';
        loadingP.textContent = tr('Personen werden geladen …');
        container.append(loadingP);
      }

      try {
        const gallery = getGallery();
        const query = buildSubjectsQuery({
          gallery,
          cursor: reset ? '' : mergeCursor,
          limit: 60,
          filter: mergeFilter
        });
        const data = await api('subjects?' + query);
        const items = Array.isArray(data.items) ? data.items : [];
        const filtered = items.filter((s) => s.id !== selectedSubject.id);
        mergeTargets = reset ? filtered : [...mergeTargets, ...filtered];
        mergeCursor = data.next ?? null;
        mergeMore = Boolean(data.more);
        renderMergeTargets();
      } catch (err) {
        container.replaceChildren();
        const errP = document.createElement('p');
        errP.className = 'ch-error-banner';
        errP.textContent = err.message || tr('Fehler beim Laden');
        container.append(errP);
      } finally {
        mergeLoading = false;
      }
    }

    function renderMergeTargets() {
      container.replaceChildren();
      const q = (searchInput?.value || '').trim().toLowerCase();
      const visibleTargets = mergeTargets.filter((t) => {
        if (!q) return true;
        const countText = formatPhotoCount(t.photo_count, tr, locale).toLowerCase();
        return countText.includes(q) || String(t.photo_count).includes(q);
      });

      if (visibleTargets.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'ch-hint';
        empty.textContent = tr('Keine Personen gefunden');
        container.append(empty);
      } else {
        for (const target of visibleTargets) {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'ch-people-merge-choice';
          if (target.id === selectedTargetId) item.classList.add('active');

          const thumb = document.createElement('div');
          thumb.className = 'ch-person-thumb-wrap';
          if (target.cover_id) {
            const img = document.createElement('img');
            img.className = 'ch-person-thumb';
            img.alt = '';
            img.loading = 'lazy';
            img.src = previewUrl(target.cover_id);
            img.onerror = () => img.replaceWith(createGenericPersonIcon());
            thumb.append(img);
          } else {
            thumb.append(createGenericPersonIcon());
          }
          item.append(thumb);

          // Human readable label - NEVER show code UUID string
          const count = document.createElement('span');
          count.className = 'ch-person-count';
          count.textContent = formatPhotoCount(target.photo_count, tr, locale);
          item.append(count);

          if (target.review_count && target.review_count > 0) {
            const badge = document.createElement('span');
            badge.className = 'ch-person-badge ch-person-badge-review';
            badge.textContent = tr('{count} zu prüfen', { count: target.review_count });
            item.append(badge);
          }

          item.onclick = () => {
            container.querySelectorAll('.ch-people-merge-choice').forEach((b) => b.classList.remove('active'));
            item.classList.add('active');
            selectedTargetId = target.id;
            okBtn.disabled = false;
          };
          container.append(item);
        }
      }

      if (moreWrap) {
        moreWrap.hidden = !mergeMore;
      }
    }

    if (searchInput) {
      searchInput.oninput = () => renderMergeTargets();
    }
    if (moreBtn) {
      moreBtn.onclick = () => {
        if (mergeMore && !mergeLoading) loadMergeTargets(false);
      };
    }

    let settled = false;
    const done = async (confirmed) => {
      if (settled) return;
      settled = true;
      dialog.close();
      if (confirmed && selectedTargetId) {
        await performSubjectAction('merge', {
          target_id: selectedTargetId,
          confirmTitle: tr('Person zusammenführen?'),
          confirmText: tr('Diese Person wird mit der ausgewählten Person zusammengelegt.'),
          confirmOkLabel: tr('Zusammenführen'),
          isDestructive: true
        });
      }
    };

    okBtn.onclick = () => done(true);
    if (cancelBtn) cancelBtn.onclick = () => done(false);
    dialog.oncancel = (e) => {
      e.preventDefault();
      done(false);
    };

    await loadMergeTargets(true);
    dialog.showModal();
    if (cancelBtn) cancelBtn.focus();
  }

  // Selection actions: Split, Remove, Resolve, Clear
  function getSelectedDetectionIds() {
    const ids = [];
    for (const detIds of selectedDetections.values()) {
      if (Array.isArray(detIds)) {
        ids.push(...detIds);
      } else if (typeof detIds === 'string' && detIds) {
        ids.push(detIds);
      }
    }
    return ids;
  }

  const splitBtn = $('ch-people-split-selected');
  if (splitBtn) {
    splitBtn.onclick = async () => {
      const ids = getSelectedDetectionIds();
      if (ids.length === 0) return;
      await performSubjectAction('split', {
        detection_ids: ids,
        confirmTitle: tr('Ausgewählte Fotos abtrennen?'),
        confirmText: tr('Die ausgewählten Fotos werden einer neuen Person zugeordnet.'),
        confirmOkLabel: tr('Abtrennen'),
        isDestructive: false
      });
    };
  }

  const removeBtn = $('ch-people-remove-selected');
  if (removeBtn) {
    removeBtn.onclick = async () => {
      const ids = getSelectedDetectionIds();
      if (ids.length === 0) return;
      await performSubjectAction('remove', {
        detection_ids: ids,
        confirmTitle: tr('Ausgewählte Fotos entfernen?'),
        confirmText: tr('Die ausgewählten Fotos werden von dieser Person entfernt.'),
        confirmOkLabel: tr('Entfernen'),
        isDestructive: true
      });
    };
  }

  const resolveSelectedBtn = $('ch-people-resolve-selected');
  if (resolveSelectedBtn) {
    resolveSelectedBtn.onclick = async () => {
      const ids = getSelectedDetectionIds();
      if (ids.length === 0) return;
      await performSubjectAction('resolve', {
        detection_ids: ids
      });
    };
  }

  const clearSelectedBtn = $('ch-people-clear-selected');
  if (clearSelectedBtn) {
    clearSelectedBtn.onclick = () => clearSelection();
  }

  // Reset Faces Dialog
  const resetBtn = $('ch-people-reset');
  if (resetBtn) {
    resetBtn.onclick = () => openResetDialog();
  }

  function openResetDialog() {
    const dialog = $d('ch-people-reset-dialog');
    const input = $d('ch-people-reset-confirm-input');
    const okBtn = $d('ch-people-reset-ok');
    const cancelBtn = $d('ch-people-reset-cancel');
    if (!dialog || !input || !okBtn) {
      console.error('Reset dialog not found; failing closed');
      return;
    }

    input.value = '';
    okBtn.disabled = true;

    input.oninput = () => {
      okBtn.disabled = input.value.trim().toLowerCase() !== 'faces';
    };

    let settled = false;
    const done = async (confirmed) => {
      if (settled) return;
      settled = true;
      dialog.close();
      if (confirmed) {
        clearError();
        try {
          const payload = buildResetPayload('faces');
          await api('subjects/reset', {
            method: 'POST',
            body: JSON.stringify(payload)
          });
          selectedSubject = null;
          selectedDetections.clear();
          await loadSubjects(true);
        } catch (err) {
          showError(err);
        }
      }
    };

    okBtn.onclick = () => done(true);
    if (cancelBtn) cancelBtn.onclick = () => done(false);
    dialog.oncancel = (e) => {
      e.preventDefault();
      done(false);
    };
    dialog.showModal();
    input.focus();
  }

  function onGalleryChange(newGallery) {
    currentGallery = newGallery || getGallery();
    activeGeneration++;
    if (currentAbort) {
      currentAbort.abort();
      currentAbort = null;
    }
    selectedSubject = null;
    subjectMedia = [];
    selectedDetections.clear();
    cursor = null;
    subjects = [];
    loading = false;
    clearError();
    const listWrap = $('ch-people-list-container');
    const detailWrap = $('ch-people-detail');
    if (detailWrap) detailWrap.hidden = true;
    if (listWrap) listWrap.hidden = false;
    loadSubjects(true);
  }

  return {
    show: () => {
      root.hidden = false;
      const gallery = getGallery();
      if (gallery !== currentGallery) {
        onGalleryChange(gallery);
      } else if (selectedSubject) {
        if (subjectMedia.length === 0) {
          loadSubjectMedia(true);
        }
      } else if (subjects.length === 0) {
        loadSubjects(true);
      }
    },
    hide: () => {
      root.hidden = true;
      activeGeneration++;
      if (currentAbort) {
        currentAbort.abort();
        currentAbort = null;
      }
      loading = false;
    },
    refresh: () => {
      if (selectedSubject) {
        loadSubjectMedia(true);
      } else {
        loadSubjects(true);
      }
    },
    openSubject: (subject) => {
      openSubject(subject);
    },
    closeDetail: () => {
      backToList();
    },
    setFilter: (f) => {
      filter = f;
      $$('[data-people-filter]').forEach((b) => b.classList.toggle('active', b.dataset.peopleFilter === filter));
      const sel = $('ch-people-filter');
      if (sel) sel.value = filter;
      subjects = [];
      cursor = null;
      loadSubjects(true);
    },
    getFilter: () => filter,
    getRulesRevision: () => rulesRevision,
    getSelectedSubject: () => selectedSubject,
    onGalleryChange,
    getState: () => ({
      filter,
      subjectCount: subjects.length,
      selectedSubject: selectedSubject ? selectedSubject.id : null,
      rulesRevision,
      epoch,
      generation,
      gallery: currentGallery
    })
  };
}
