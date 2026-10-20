/**
 * NextGallery People (Faces) Frontend Module
 * Implements responsive cards, subject media grid, naming, custom cover,
 * unknown-people folder, context/bbox inspector, undoable merges, and shared sorting.
 */

export function buildSubjectsQuery({gallery, cursor, limit = 60, filter = 'all'} = {}) {
  const params = new URLSearchParams();
  if (gallery) params.set('gallery', gallery);
  if (cursor !== undefined && cursor !== null && cursor !== '') params.set('cursor', String(cursor));
  if (limit) params.set('limit', String(limit));
  if (filter && ['all', 'suggested', 'strangers', 'hidden', 'review', 'single', 'filtered'].includes(filter)) {
    params.set('filter', filter);
  } else {
    params.set('filter', 'all');
  }
  return params.toString();
}

export function buildActionPayload({action, target_id, detection_ids, rules_revision, label}) {
  const allowed = [
    'hide', 'show', 'merge', 'split', 'remove', 'resolve', 'dismiss',
    'rename', 'set_cover', 'stranger', 'unstranger'
  ];
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
  if (action === 'rename') {
    payload.label = label !== null && label !== undefined ? String(label) : '';
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
  return tr('{count} Fotos', { count: formatted }).replaceAll('{count}', formatted);
}

export function formatPeopleCount(n, tr = (k, v) => (v && v.count !== undefined ? k.replace('{count}', String(v.count)) : k), locale = 'de') {
  const num = Number(n) || 0;
  if (num === 1) return tr('1 Person');
  const formatted = new Intl.NumberFormat(locale).format(num);
  return tr('{count} Personen', { count: formatted }).replaceAll('{count}', formatted);
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
  const tr = (key, values = {}) => translate(key, values).replace(/\{(\w+)\}/g, (match, name) => Object.hasOwn(values, name) ? String(values[name]) : match);

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
  let filterCounts = null;
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
  let similarItems = [];
  let similarGeneration = 0;
  let showFaceBoxes = true;
  let lastUndoAction = null;

  function contextPreviewUrl(detectionId) {
    const u = previewUrl(detectionId);
    return u + (u.includes('?') ? '&' : '?') + 'context=1';
  }

  function subjectDisplayName(subject) {
    if (subject && typeof subject.label === 'string' && subject.label.trim() !== '') {
      return subject.label.trim();
    }
    return tr('Person');
  }

  function updateFilterExplainer() {
    const exp = $('ch-people-filter-explainer');
    if (!exp) return;
    const descriptions = {
      all: tr('Hauptansicht aller erkannten Personen (ab der eingestellten Mindestanzahl an Fotos oder mit Namen). Tipp: Zusammenführungen und Korrekturen verbessern zukünftige Vorschläge und Zuordnungen automatisch.'),
      suggested: tr('Personen, für die ähnliche Gesichter gefunden wurden. Öffne eine Person, um die Vorschläge im vollen Bildkontext zu prüfen und zusammenzuführen.'),
      strangers: tr('Sammelordner für Personen, die du nicht kennst (z. B. Passanten oder Hintergrundgäste). Sie bleiben hier gesammelt und halten deine Hauptansicht sauber.'),
      single: tr('Seltene Personen, die bisher nur auf wenigen Fotos (unter der Mindestanzahl) erkannt wurden. Du kannst sie mit bekannten Personen zusammenführen, benennen oder in „Unbekannte Personen“ verschieben.'),
      review: tr('Gesichter mit unsicherer Zuordnung. Prüfe die Fotos dieser Person und klicke auf „Überprüfung auflösen“ oder trenne falsche Fotos ab.'),
      filtered: tr('Sehr kleine oder unscharfe Gesichter im Hintergrund, die automatisch aussortiert wurden. Einzelne Treffer kannst du bei Bedarf bestätigen.'),
      hidden: tr('Ausgeblendete Personen, die in den normalen Ansichten nicht angezeigt werden.')
    };
    exp.textContent = descriptions[filter] || descriptions.all;
  }

  function updateFilterCountsUI() {
    if (!filterCounts) return;
    const baseLabels = {
      all: tr('Alle'),
      suggested: tr('Vorschläge'),
      strangers: tr('Unbekannte Personen'),
      review: tr('Überprüfung'),
      single: tr('Seltene Personen'),
      filtered: tr('Gefilterte Gesichter'),
      hidden: tr('Ausgeblendet')
    };
    const sel = $('ch-people-filter');
    if (sel && sel.children) {
      for (const opt of sel.children) {
        const key = opt.value;
        if (baseLabels[key] !== undefined && typeof filterCounts[key] === 'number') {
          opt.textContent = `${baseLabels[key]} (${filterCounts[key]})`;
        }
      }
    }
    $$('[data-people-filter]').forEach((btn) => {
      const key = btn.dataset?.peopleFilter;
      if (baseLabels[key] !== undefined && typeof filterCounts[key] === 'number') {
        const cnt = filterCounts[key];
        btn.textContent = cnt > 0 ? `${baseLabels[key]} (${cnt})` : baseLabels[key];
        if (key === 'review' || key === 'hidden') {
          btn.hidden = (cnt === 0 && filter !== key);
        }
      }
    });
  }

  function showUndoBar(undoConfig) {
    lastUndoAction = undoConfig;
    const bar = $('ch-people-undo-bar');
    const txt = $('ch-people-undo-text');
    if (!bar || !txt) return;
    if (!undoConfig) {
      bar.hidden = true;
      txt.textContent = '';
      return;
    }
    txt.textContent = undoConfig.text || tr('Aktion ausgeführt.');
    bar.hidden = false;
  }

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
    updateFilterExplainer();

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
      if (data.filter_counts && typeof data.filter_counts === 'object') {
        filterCounts = data.filter_counts;
        updateFilterCountsUI();
      }
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
            : filter === 'suggested'
            ? tr('Keine offenen Vorschläge. Neue Vorschläge entstehen nach dem nächsten Lauf der Personenerkennung.')
            : filter === 'strangers'
            ? tr('Noch keine Personen im Sammelordner „Unbekannte Personen“. Du kannst Personen über „Kenne ich nicht“ hierher verschieben.')
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
      const countStr = formatPhotoCount(subject.photo_count, tr, locale);
      const hasName = typeof subject.label === 'string' && subject.label.trim() !== '';
      card.setAttribute('aria-label', hasName ? `${subject.label.trim()} · ${countStr}` : countStr);

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

      // Stranger badge
      if (subject.stranger) {
        const strBadge = document.createElement('span');
        strBadge.className = 'ch-person-badge ch-person-badge-stranger';
        strBadge.textContent = tr('Unbekannt');
        thumbWrap.append(strBadge);
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

      if (hasName) {
        const nameLabel = document.createElement('strong');
        nameLabel.className = 'ch-person-name';
        nameLabel.textContent = subject.label.trim();
        card.append(nameLabel);
      }

      const countLabel = document.createElement('span');
      countLabel.className = 'ch-person-count';
      countLabel.textContent = countStr;
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

  function renderDetailHeader() {
    if (!selectedSubject) return;
    const title = $('ch-people-detail-title');
    if (title) {
      title.textContent = subjectDisplayName(selectedSubject);
    }
    const renameBtn = $('ch-people-rename-btn');
    if (renameBtn) {
      renameBtn.textContent = selectedSubject.label ? '✎ ' + tr('Umbenennen') : '✎ ' + tr('Benennen');
    }
    const avatarWrap = $('ch-people-detail-avatar');
    if (avatarWrap) {
      avatarWrap.replaceChildren();
      if (selectedSubject.cover_id) {
        const img = document.createElement('img');
        img.className = 'ch-person-thumb';
        img.alt = '';
        img.src = previewUrl(selectedSubject.cover_id);
        img.onerror = () => img.replaceWith(createGenericPersonIcon());
        avatarWrap.append(img);
      } else {
        avatarWrap.append(createGenericPersonIcon());
      }
    }
    const count = $('ch-people-detail-count');
    if (count) count.textContent = formatPhotoCount(selectedSubject.photo_count, tr, locale);

    const hideBtn = $('ch-people-toggle-hide');
    if (hideBtn) {
      hideBtn.textContent = selectedSubject.hidden ? tr('Einblenden') : tr('Ausblenden');
    }

    const strangerBtn = $('ch-people-toggle-stranger');
    if (strangerBtn) {
      strangerBtn.textContent = selectedSubject.stranger ? tr('Als bekannt markieren') : tr('Kenne ich nicht');
      strangerBtn.title = selectedSubject.stranger
        ? tr('Zurück in die Hauptansicht verschieben')
        : tr('Person in den Sammelordner „Unbekannte Personen“ verschieben');
    }

    const isFiltered = Boolean(selectedSubject.filtered || filter === 'filtered');
    const hasReview = Boolean(selectedSubject.review_count && selectedSubject.review_count > 0);
    const resolveBtn = $('ch-people-resolve');
    if (resolveBtn) {
      resolveBtn.hidden = !(hasReview || isFiltered);
      resolveBtn.textContent = isFiltered ? tr('Bestätigen') : tr('Überprüfung auflösen');
    }

    const filterNote = $('ch-people-detail-filter-note');
    if (filterNote) {
      filterNote.hidden = !isFiltered;
    }
  }

  function updateToggleBoxesBtn() {
    const btn = $('ch-people-toggle-boxes');
    if (!btn) return;
    btn.setAttribute('aria-pressed', String(showFaceBoxes));
    btn.classList.toggle('active', showFaceBoxes);
    btn.textContent = showFaceBoxes ? ('✓ ' + tr('Gesichtsrahmen an')) : tr('Gesichtsrahmen aus');
    btn.title = showFaceBoxes
      ? tr('Gesichtsrahmen auf Fotos ausblenden')
      : tr('Gesichtsrahmen auf Fotos einblenden');
    const grid = $('ch-people-media-grid');
    if (grid) grid.classList.toggle('show-face-boxes', showFaceBoxes);
  }

  async function openSubject(subject) {
    selectedSubject = { ...subject };
    selectedDetections.clear();
    clearError();

    const listWrap = $('ch-people-list-container');
    const detailWrap = $('ch-people-detail');
    if (listWrap) listWrap.hidden = true;
    if (detailWrap) detailWrap.hidden = false;

    const renameBar = $('ch-people-rename-bar');
    if (renameBar) renameBar.hidden = true;

    renderDetailHeader();
    updateToggleBoxesBtn();
    updateSelectionBar();
    similarItems = [];
    renderSimilar();
    await loadSubjectMedia(true);
    await loadSimilar();
  }

  // Suggestions only apply to the main person view, not to filtered, review or hidden lists.
  function similarApplies() {
    return Boolean(selectedSubject) && ['all', 'single', 'suggested'].includes(filter);
  }

  async function loadSimilar() {
    const reqGen = ++similarGeneration;
    if (!similarApplies()) {
      similarItems = [];
      renderSimilar();
      return;
    }
    const subjectId = selectedSubject.id;
    try {
      const q = new URLSearchParams({ gallery: getGallery() });
      const data = await api(`subjects/${encodeURIComponent(subjectId)}/similar?` + q.toString());
      if (reqGen !== similarGeneration || !selectedSubject || selectedSubject.id !== subjectId) return;
      similarItems = Array.isArray(data.items) ? data.items : [];
      if (typeof data.rules_revision === 'number') rulesRevision = data.rules_revision;
      const count = Number(data.subject?.photo_count);
      if (count > 0) {
        selectedSubject.photo_count = count;
        const countEl = $('ch-people-detail-count');
        if (countEl) countEl.textContent = formatPhotoCount(count, tr, locale);
      }
      if (data.subject?.label !== undefined) {
        selectedSubject.label = data.subject.label;
        renderDetailHeader();
      }
    } catch (err) {
      if (reqGen !== similarGeneration) return;
      similarItems = []; // Suggestions are optional; the person view stays usable.
    }
    renderSimilar();
  }

  function renderSimilar() {
    const section = $('ch-people-similar');
    const list = $('ch-people-similar-list');
    if (!section || !list) return;
    list.replaceChildren();
    section.hidden = similarItems.length === 0;
    for (const other of similarItems) {
      const item = document.createElement('div');
      item.className = 'ch-people-similar-item';
      item.setAttribute('role', 'listitem');
      item.dataset.id = other.id;

      const thumb = document.createElement('div');
      thumb.className = 'ch-person-thumb-wrap ch-person-thumb-clickable';
      thumb.title = tr('Klicken, um das ganze Bild mit markiertem Gesicht und alle Fotos dieser Person anzusehen');
      if (other.cover_id) {
        const img = document.createElement('img');
        img.className = 'ch-person-thumb';
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.src = previewUrl(other.cover_id);
        img.onerror = () => img.replaceWith(createGenericPersonIcon());
        thumb.append(img);
      } else {
        thumb.append(createGenericPersonIcon());
      }
      thumb.onclick = () => openInspectDialog(other, { canMerge: true, onMerge: () => mergeSuggestion(other) });
      item.append(thumb);

      if (other.label) {
        const nameEl = document.createElement('strong');
        nameEl.className = 'ch-person-name';
        nameEl.textContent = other.label;
        item.append(nameEl);
      }

      const count = document.createElement('span');
      count.className = 'ch-person-count';
      count.textContent = formatPhotoCount(other.photo_count, tr, locale);
      item.append(count);

      const actions = document.createElement('div');
      actions.className = 'ch-people-similar-actions';
      const merge = document.createElement('button');
      merge.type = 'button';
      merge.className = 'ch-button ch-primary';
      merge.textContent = tr('Zusammenführen');
      merge.onclick = () => mergeSuggestion(other);
      const dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'ch-button';
      dismiss.textContent = tr('Andere Person');
      dismiss.title = tr('Nicht mehr vorschlagen');
      dismiss.onclick = () => dismissSuggestion(other);
      const inspect = document.createElement('button');
      inspect.type = 'button';
      inspect.className = 'ch-button ch-people-inspect-btn';
      inspect.textContent = tr('Fotos & Kontext');
      inspect.title = tr('Vollen Bildbereich mit markiertem Gesicht und alle Fotos dieser Person anschauen');
      inspect.onclick = () => openInspectDialog(other, { canMerge: true, onMerge: () => mergeSuggestion(other) });
      actions.append(merge, dismiss, inspect);
      item.append(actions);
      list.append(item);
    }
  }

  async function suggestionAction(sourceId, payload) {
    clearError();
    try {
      const result = await api(`subjects/${encodeURIComponent(sourceId)}/action`, {
        method: 'POST',
        body: JSON.stringify(buildActionPayload({ ...payload, rules_revision: rulesRevision }))
      });
      if (typeof result?.rules_revision === 'number') rulesRevision = result.rules_revision;
      return result || true;
    } catch (err) {
      showError(err);
      return false;
    }
  }

  async function mergeSuggestion(other) {
    if (!selectedSubject) return;
    const otherDesc = other.label
      ? `„${other.label}“ (${formatPhotoCount(other.photo_count, tr, locale)})`
      : formatPhotoCount(other.photo_count, tr, locale);
    const ok = await showActionConfirm(
      tr('Person zusammenführen?'),
      tr('Die vorgeschlagene Person ({other}) wird mit dieser Person zusammengelegt. Die Erkennung lernt daraus für künftige Vorschläge.', { other: otherDesc }),
      tr('Zusammenführen'),
      false
    );
    if (!ok) return;
    // The suggestion moves into the open person, so this view and its ID remain valid.
    const res = await suggestionAction(other.id, { action: 'merge', target_id: selectedSubject.id });
    if (res && Array.isArray(res.merged_detection_ids) && res.merged_detection_ids.length > 0) {
      showUndoBar({
        targetId: selectedSubject.id,
        detectionIds: res.merged_detection_ids,
        text: tr('Vorschlag wurde zusammengeführt ({count}).', { count: formatPhotoCount(res.merged_detection_ids.length, tr, locale) })
      });
    }
    await loadSubjectMedia(true);
    await loadSimilar();
  }

  async function dismissSuggestion(other) {
    if (!selectedSubject) return;
    if (await suggestionAction(selectedSubject.id, { action: 'dismiss', target_id: other.id })) {
      similarItems = similarItems.filter((item) => item.id !== other.id);
      renderSimilar();
    } else {
      await loadSimilar();
    }
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
      if (data.subject && typeof data.subject === 'object') {
        if (data.subject.label !== undefined) selectedSubject.label = data.subject.label;
        if (data.subject.cover_id) selectedSubject.cover_id = data.subject.cover_id;
        if (data.subject.stranger !== undefined) selectedSubject.stranger = Boolean(data.subject.stranger);
        if (data.subject.hidden !== undefined) selectedSubject.hidden = Boolean(data.subject.hidden);
        renderDetailHeader();
      }

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

  async function setCoverDetection(detectionId) {
    if (!selectedSubject || !detectionId) return;
    clearError();
    try {
      const payload = buildActionPayload({
        action: 'set_cover',
        target_id: detectionId,
        rules_revision: rulesRevision
      });
      const res = await api(`subjects/${encodeURIComponent(selectedSubject.id)}/action`, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      if (typeof res?.rules_revision === 'number') rulesRevision = res.rules_revision;
      selectedSubject.cover_id = detectionId;
      renderDetailHeader();
      renderSubjectMedia();
    } catch (err) {
      showError(err);
    }
  }

  function renderSubjectMedia() {
    const grid = $('ch-people-media-grid');
    const moreWrap = $('ch-people-media-more-wrap');
    if (!grid) return;

    grid.classList.toggle('show-face-boxes', showFaceBoxes);
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

      // Render face bounding box overlays if coordinates are available
      const facesList = Array.isArray(item.faces) ? item.faces : [];
      const firstDetId = (Array.isArray(item.face_detection_ids) && item.face_detection_ids[0])
        || (facesList[0] && facesList[0].id)
        || null;
      const isCurrentCover = Boolean(
        selectedSubject?.cover_id && (
          (Array.isArray(item.face_detection_ids) && item.face_detection_ids.includes(selectedSubject.cover_id)) ||
          facesList.some((f) => f.id === selectedSubject.cover_id || f.is_cover)
        )
      );

      for (const f of facesList) {
        if (Array.isArray(f.box) && f.box.length === 4) {
          const [bx, by, bw, bh] = f.box;
          const boxEl = document.createElement('span');
          boxEl.className = 'ch-face-box-overlay';
          if (f.review) boxEl.classList.add('is-review');
          boxEl.style.left = `${Math.max(0, Math.min(100, bx * 100))}%`;
          boxEl.style.top = `${Math.max(0, Math.min(100, by * 100))}%`;
          boxEl.style.width = `${Math.max(2, Math.min(100, bw * 100))}%`;
          boxEl.style.height = `${Math.max(2, Math.min(100, bh * 100))}%`;
          tile.append(boxEl);
        }
      }

      if (isCurrentCover) {
        const covBadge = document.createElement('span');
        covBadge.className = 'ch-person-media-cover-badge';
        covBadge.textContent = '★ ' + tr('Titelbild');
        tile.append(covBadge);
      }

      if (firstDetId) {
        const quickBar = document.createElement('div');
        quickBar.className = 'ch-person-media-quick';

        if (!isCurrentCover) {
          const coverBtn = document.createElement('button');
          coverBtn.type = 'button';
          coverBtn.className = 'ch-person-media-quick-btn';
          coverBtn.textContent = '★ ' + tr('Titelbild');
          coverBtn.title = tr('Dieses Gesicht als Vorschaubild der Person festlegen');
          coverBtn.onclick = (e) => {
            e.stopPropagation();
            setCoverDetection(firstDetId);
          };
          quickBar.append(coverBtn);
        }

        const inspectBtn = document.createElement('button');
        inspectBtn.type = 'button';
        inspectBtn.className = 'ch-person-media-quick-btn';
        inspectBtn.textContent = tr('Ausschnitt');
        inspectBtn.title = tr('Volles Foto mit markiertem Gesichtsausschnitt ansehen');
        inspectBtn.onclick = (e) => {
          e.stopPropagation();
          openInspectDialog(selectedSubject, { initialDetectionId: firstDetId, initialMediaItem: item });
        };
        quickBar.append(inspectBtn);

        tile.append(quickBar);
      }

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
    const coverSelectedBtn = $('ch-people-cover-selected');
    if (!bar) return;

    const count = selectedDetections.size;
    bar.hidden = count === 0;
    if (countLabel) {
      countLabel.textContent = formatPhotoCount(count, tr, locale) + ' ' + tr('ausgewählt');
    }
    if (coverSelectedBtn) {
      coverSelectedBtn.hidden = count !== 1;
    }
  }

  function clearSelection() {
    selectedDetections.clear();
    updateSelectionBar();
    renderSubjectMedia();
  }

  async function performSubjectAction(action, { target_id, detection_ids, label, confirmTitle, confirmText, confirmOkLabel, isDestructive = false } = {}) {
    if (!selectedSubject) return null;

    if (isDestructive && confirmTitle) {
      const ok = await showActionConfirm(confirmTitle, confirmText, confirmOkLabel || tr('Bestätigen'), true);
      if (!ok) return null; // Fail closed if cancelled or dialog missing
    }

    clearError();
    try {
      const payload = buildActionPayload({
        action,
        target_id,
        detection_ids,
        label,
        rules_revision: rulesRevision
      });

      const sourceSubject = { ...selectedSubject };
      const res = await api(`subjects/${encodeURIComponent(selectedSubject.id)}/action`, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      if (typeof res?.rules_revision === 'number') {
        rulesRevision = res.rules_revision;
      }

      if (action === 'merge' && target_id) {
        const mergedIds = Array.isArray(res?.merged_detection_ids) ? res.merged_detection_ids : [];
        if (mergedIds.length > 0) {
          showUndoBar({
            targetId: target_id,
            detectionIds: mergedIds,
            text: tr('Personen wurden zusammengeführt ({count}).', { count: formatPhotoCount(mergedIds.length, tr, locale) })
          });
        }
      } else if (action === 'stranger') {
        showUndoBar({
          strangerGroupId: sourceSubject.id,
          text: tr('Person wurde in „Unbekannte Personen“ verschoben.')
        });
      }

      // After action, refresh view
      selectedDetections.clear();
      if (['merge', 'hide', 'stranger', 'unstranger'].includes(action) || (action === 'resolve' && filter === 'filtered')) {
        await backToList();
      } else if (action === 'rename') {
        selectedSubject.label = res?.label ?? (label ? label.trim() : null);
        renderDetailHeader();
      } else {
        await loadSubjectMedia(true);
        await loadSimilar();
      }
      return res || { ok: true };
    } catch (err) {
      showError(err);
      if (err.retryable || err.status === 409) {
        // rules_revision conflict: reload to synchronize authoritative state
        if (selectedSubject) await loadSubjectMedia(true);
        else await loadSubjects(true);
      }
      return null;
    }
  }

  async function performUndo() {
    if (!lastUndoAction) return;
    const undo = lastUndoAction;
    showUndoBar(null);
    clearError();
    try {
      if (undo.strangerGroupId) {
        const res = await api(`subjects/${encodeURIComponent(undo.strangerGroupId)}/action`, {
          method: 'POST',
          body: JSON.stringify(buildActionPayload({ action: 'unstranger', rules_revision: rulesRevision }))
        });
        if (typeof res?.rules_revision === 'number') rulesRevision = res.rules_revision;
      } else if (undo.targetId && Array.isArray(undo.detectionIds) && undo.detectionIds.length > 0) {
        const res = await api(`subjects/${encodeURIComponent(undo.targetId)}/action`, {
          method: 'POST',
          body: JSON.stringify(buildActionPayload({
            action: 'split',
            detection_ids: undo.detectionIds,
            rules_revision: rulesRevision
          }))
        });
        if (typeof res?.rules_revision === 'number') rulesRevision = res.rules_revision;
      }
      if (selectedSubject) {
        await loadSubjectMedia(true);
        await loadSimilar();
      } else {
        await loadSubjects(true);
      }
    } catch (err) {
      showError(err);
    }
  }

  async function backToList() {
    selectedSubject = null;
    similarItems = [];
    similarGeneration++;
    renderSimilar();
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

  // Context & Photo Inspector Dialog (#ch-people-inspect-dialog)
  async function openInspectDialog(subject, { canMerge = false, onMerge = null, initialDetectionId = null, initialMediaItem = null } = {}) {
    if (!subject) return;
    const dialog = $d('ch-people-inspect-dialog');
    const titleEl = $d('ch-people-inspect-title');
    const stageEl = $d('ch-people-inspect-stage');
    const photosEl = $d('ch-people-inspect-photos');
    const closeBtn = $d('ch-people-inspect-close');
    const mergeBtn = $d('ch-people-inspect-merge');
    if (!dialog || !stageEl || !photosEl) return;

    const dispName = subjectDisplayName(subject);
    const countStr = formatPhotoCount(subject.photo_count || 1, tr, locale);
    if (titleEl) {
      titleEl.textContent = `${dispName} · ${countStr}`;
    }
    if (mergeBtn) {
      mergeBtn.hidden = !canMerge;
      mergeBtn.onclick = () => {
        dialog.close();
        if (typeof onMerge === 'function') onMerge();
      };
    }
    if (closeBtn) {
      closeBtn.onclick = () => dialog.close();
    }

    function renderStageForDetection(detId, box = null, mediaItem = null) {
      stageEl.replaceChildren();
      const stageGrid = document.createElement('div');
      stageGrid.className = 'ch-people-inspect-comparison';

      // 1. Full context image with marked face bounding box + interactive zoom
      const fullCard = document.createElement('div');
      fullCard.className = 'ch-people-inspect-full-card';

      const fullCardHead = document.createElement('div');
      fullCardHead.className = 'ch-people-inspect-full-card-head';

      const fullLabel = document.createElement('span');
      fullLabel.className = 'ch-people-inspect-card-label';
      fullLabel.textContent = tr('Voller Bildbereich mit markiertem Gesicht');
      fullCardHead.append(fullLabel);

      const zoomBar = document.createElement('div');
      zoomBar.className = 'ch-people-inspect-zoom-bar';
      zoomBar.setAttribute('role', 'toolbar');
      zoomBar.setAttribute('aria-label', tr('Zoom-Steuerung'));

      const zoomOutBtn = document.createElement('button');
      zoomOutBtn.type = 'button';
      zoomOutBtn.className = 'ch-button ch-button-sm ch-people-inspect-zoom-btn';
      zoomOutBtn.textContent = '−';
      zoomOutBtn.title = tr('Verkleinern');
      zoomOutBtn.setAttribute('aria-label', tr('Verkleinern'));

      const zoomLevelBtn = document.createElement('button');
      zoomLevelBtn.type = 'button';
      zoomLevelBtn.className = 'ch-button ch-button-sm ch-people-inspect-zoom-badge';
      zoomLevelBtn.textContent = '100%';
      zoomLevelBtn.title = tr('Auf 100% zurücksetzen');

      const zoomInBtn = document.createElement('button');
      zoomInBtn.type = 'button';
      zoomInBtn.className = 'ch-button ch-button-sm ch-people-inspect-zoom-btn';
      zoomInBtn.textContent = '+';
      zoomInBtn.title = tr('Vergrößern');
      zoomInBtn.setAttribute('aria-label', tr('Vergrößern'));

      const zoomFocusBtn = document.createElement('button');
      zoomFocusBtn.type = 'button';
      zoomFocusBtn.className = 'ch-button ch-button-sm ch-people-inspect-zoom-face';
      zoomFocusBtn.textContent = tr('Fokus Gesicht');
      zoomFocusBtn.title = tr('Auf das erkannte Gesicht zoomen');

      const zoomResetBtn = document.createElement('button');
      zoomResetBtn.type = 'button';
      zoomResetBtn.className = 'ch-button ch-button-sm ch-people-inspect-zoom-btn';
      zoomResetBtn.textContent = '⟲';
      zoomResetBtn.title = tr('Ansicht zurücksetzen');
      zoomResetBtn.setAttribute('aria-label', tr('Ansicht zurücksetzen'));

      zoomBar.append(zoomOutBtn, zoomLevelBtn, zoomInBtn, zoomFocusBtn, zoomResetBtn);
      fullCardHead.append(zoomBar);
      fullCard.append(fullCardHead);

      const fullImgWrap = document.createElement('div');
      fullImgWrap.className = 'ch-people-inspect-full-wrap';
      fullImgWrap.tabIndex = 0;
      fullImgWrap.setAttribute('role', 'region');
      fullImgWrap.setAttribute('aria-label', tr('Voller Bildbereich'));

      const imgHolder = document.createElement('div');
      imgHolder.className = 'ch-people-inspect-img-holder';

      const fullImg = document.createElement('img');
      fullImg.className = 'ch-people-inspect-full-img';
      fullImg.alt = mediaItem?.name || tr('Voller Bildbereich');
      if (detId) {
        fullImg.src = contextPreviewUrl(detId);
      }
      imgHolder.append(fullImg);

      function attachOverlay(b) {
        if (Array.isArray(b) && b.length === 4) {
          imgHolder.querySelectorAll('.ch-face-box-overlay').forEach((el) => el.remove());
          const [bx, by, bw, bh] = b;
          const overlay = document.createElement('span');
          overlay.className = 'ch-face-box-overlay is-prominent';
          overlay.style.left = `${Math.max(0, Math.min(100, bx * 100))}%`;
          overlay.style.top = `${Math.max(0, Math.min(100, by * 100))}%`;
          overlay.style.width = `${Math.max(2, Math.min(100, bw * 100))}%`;
          overlay.style.height = `${Math.max(2, Math.min(100, bh * 100))}%`;
          imgHolder.append(overlay);
        }
      }

      if (mediaItem) {
        imageURL(mediaItem).then((url) => {
          if (url && fullImgWrap.isConnected) {
            fullImg.src = url;
            attachOverlay(box || mediaItem?.faces?.[0]?.box || subject?.cover_box);
          }
        }).catch(() => {});
      }

      // Zoom & Pan state
      let currentZoom = 1;
      let panX = 0;
      let panY = 0;
      let isDragging = false;
      let dragStartX = 0;
      let dragStartY = 0;

      function updateTransform(animate = true) {
        imgHolder.style.transition = animate ? 'transform 0.15s ease-out' : 'none';
        imgHolder.style.transform = `translate(${Math.round(panX)}px, ${Math.round(panY)}px) scale(${currentZoom.toFixed(3)})`;
        zoomLevelBtn.textContent = `${Math.round(currentZoom * 100)}%`;
        fullImgWrap.classList.toggle('is-zoomed', currentZoom > 1.01);
        zoomOutBtn.disabled = currentZoom <= 1.001;
        zoomInBtn.disabled = currentZoom >= 4.99;
      }

      function clampPan() {
        if (currentZoom <= 1.001) {
          panX = 0;
          panY = 0;
          return;
        }
        const w = imgHolder.offsetWidth || fullImg.offsetWidth || 300;
        const h = imgHolder.offsetHeight || fullImg.offsetHeight || 300;
        const vw = fullImgWrap.clientWidth || 300;
        const vh = fullImgWrap.clientHeight || 300;
        const maxPanX = Math.max(0, (w * currentZoom - vw) / 2) + 120;
        const maxPanY = Math.max(0, (h * currentZoom - vh) / 2) + 120;
        panX = Math.max(-maxPanX, Math.min(maxPanX, panX));
        panY = Math.max(-maxPanY, Math.min(maxPanY, panY));
      }

      function setZoom(newZoom) {
        const clampedZoom = Math.max(1, Math.min(5, newZoom));
        if (clampedZoom <= 1.001) {
          currentZoom = 1;
          panX = 0;
          panY = 0;
        } else {
          currentZoom = clampedZoom;
          clampPan();
        }
        updateTransform(true);
      }

      function getEffectiveBox() {
        if (Array.isArray(box) && box.length === 4) return box;
        if (Array.isArray(mediaItem?.faces?.[0]?.box) && mediaItem.faces[0].box.length === 4) return mediaItem.faces[0].box;
        if (Array.isArray(subject?.cover_box) && subject.cover_box.length === 4) return subject.cover_box;
        return null;
      }

      function focusOnFace() {
        const b = getEffectiveBox();
        if (!b) {
          setZoom(2.2);
          return;
        }
        const [bx, by, bw, bh] = b;
        currentZoom = 2.6;
        const w = imgHolder.offsetWidth || fullImg.offsetWidth || 360;
        const h = imgHolder.offsetHeight || fullImg.offsetHeight || 360;
        const faceCenterX = bx + bw / 2;
        const faceCenterY = by + bh / 2;
        const deltaNormX = faceCenterX - 0.5;
        const deltaNormY = faceCenterY - 0.5;
        panX = -deltaNormX * w * currentZoom;
        panY = -deltaNormY * h * currentZoom;
        clampPan();
        updateTransform(true);
      }

      if (!getEffectiveBox()) {
        zoomFocusBtn.disabled = true;
        zoomFocusBtn.title = tr('Kein Gesichtsrahmen verfügbar');
      }

      zoomOutBtn.onclick = () => setZoom(currentZoom / 1.35);
      zoomInBtn.onclick = () => setZoom(currentZoom * 1.35);
      zoomLevelBtn.onclick = () => setZoom(1);
      zoomResetBtn.onclick = () => setZoom(1);
      zoomFocusBtn.onclick = () => focusOnFace();

      fullImg.addEventListener('load', () => {
        clampPan();
        updateTransform(false);
      });

      fullImgWrap.addEventListener('wheel', (e) => {
        e.preventDefault();
        const factor = e.deltaY < 0 ? 1.18 : (1 / 1.18);
        setZoom(currentZoom * factor);
      }, { passive: false });

      fullImgWrap.addEventListener('dblclick', (e) => {
        e.preventDefault();
        if (currentZoom > 1.2) {
          setZoom(1);
        } else {
          focusOnFace();
        }
      });

      fullImgWrap.addEventListener('pointerdown', (e) => {
        if (currentZoom <= 1.001) return;
        isDragging = true;
        dragStartX = e.clientX - panX;
        dragStartY = e.clientY - panY;
        fullImgWrap.classList.add('is-dragging');
        try { fullImgWrap.setPointerCapture(e.pointerId); } catch {}
      });

      fullImgWrap.addEventListener('pointermove', (e) => {
        if (!isDragging) return;
        panX = e.clientX - dragStartX;
        panY = e.clientY - dragStartY;
        clampPan();
        updateTransform(false);
      });

      const endDrag = (e) => {
        if (!isDragging) return;
        isDragging = false;
        fullImgWrap.classList.remove('is-dragging');
        try { fullImgWrap.releasePointerCapture(e.pointerId); } catch {}
        clampPan();
        updateTransform(true);
      };
      fullImgWrap.addEventListener('pointerup', endDrag);
      fullImgWrap.addEventListener('pointercancel', endDrag);

      fullImgWrap.addEventListener('keydown', (e) => {
        if (e.key === '+' || e.key === '=') {
          e.preventDefault();
          setZoom(currentZoom * 1.25);
        } else if (e.key === '-' || e.key === '_') {
          e.preventDefault();
          setZoom(currentZoom / 1.25);
        } else if (e.key === '0') {
          e.preventDefault();
          setZoom(1);
        } else if (e.key === 'f' || e.key === 'F') {
          e.preventDefault();
          focusOnFace();
        } else if (currentZoom > 1.001) {
          const step = 40;
          if (e.key === 'ArrowLeft') { panX += step; clampPan(); updateTransform(false); e.preventDefault(); }
          else if (e.key === 'ArrowRight') { panX -= step; clampPan(); updateTransform(false); e.preventDefault(); }
          else if (e.key === 'ArrowUp') { panY += step; clampPan(); updateTransform(false); e.preventDefault(); }
          else if (e.key === 'ArrowDown') { panY -= step; clampPan(); updateTransform(false); e.preventDefault(); }
        }
      });

      updateTransform(false);

      fullImgWrap.append(imgHolder);
      fullCard.append(fullImgWrap);

      // 2. Close-up face crop
      const cropCard = document.createElement('div');
      cropCard.className = 'ch-people-inspect-crop-card';
      const cropLabel = document.createElement('span');
      cropLabel.className = 'ch-people-inspect-card-label';
      cropLabel.textContent = tr('Gesichtsausschnitt');
      cropCard.append(cropLabel);
      if (detId) {
        const cropImg = document.createElement('img');
        cropImg.className = 'ch-people-inspect-crop-img';
        cropImg.alt = '';
        cropImg.src = previewUrl(detId);
        cropCard.append(cropImg);
      }

      stageGrid.append(fullCard, cropCard);
      stageEl.append(stageGrid);
    }

    const startDetId = initialDetectionId || subject.cover_id || null;
    const startBox = (initialMediaItem?.faces?.[0]?.box) || subject.cover_box || null;
    renderStageForDetection(startDetId, startBox, initialMediaItem);

    photosEl.replaceChildren();
    const loadingHint = document.createElement('p');
    loadingHint.className = 'ch-hint';
    loadingHint.textContent = tr('Fotos werden geladen …');
    photosEl.append(loadingHint);

    dialog.showModal();

    try {
      const q = new URLSearchParams({ gallery: getGallery(), limit: '40', filter: 'all' });
      const data = await api(`subjects/${encodeURIComponent(subject.id)}/media?` + q.toString());
      const items = Array.isArray(data?.items) ? data.items : [];
      photosEl.replaceChildren();
      if (items.length === 0) {
        const emptyP = document.createElement('p');
        emptyP.className = 'ch-hint';
        emptyP.textContent = tr('Keine weiteren Fotos gefunden.');
        photosEl.append(emptyP);
      } else {
        if (!initialMediaItem && items[0]) {
          const f0 = items[0].faces?.[0];
          renderStageForDetection(f0?.id || startDetId, f0?.box || startBox, items[0]);
        }
        for (const mItem of items) {
          const thumbBtn = document.createElement('button');
          thumbBtn.type = 'button';
          thumbBtn.className = 'ch-people-inspect-photo-btn';
          thumbBtn.title = mItem.name || tr('Foto im Detail ansehen');
          const tImg = document.createElement('img');
          tImg.alt = mItem.name || '';
          tImg.loading = 'lazy';
          imageURL(mItem).then((u) => {
            if (u && thumbBtn.isConnected) tImg.src = u;
          }).catch(() => {});
          const fInfo = Array.isArray(mItem.faces) && mItem.faces[0] ? mItem.faces[0] : null;
          if (!tImg.src && (fInfo?.id || mItem.face_detection_ids?.[0])) {
            tImg.src = previewUrl(fInfo?.id || mItem.face_detection_ids[0]);
          }
          thumbBtn.append(tImg);
          thumbBtn.onclick = () => {
            photosEl.querySelectorAll('.ch-people-inspect-photo-btn').forEach((b) => b.classList.remove('active'));
            thumbBtn.classList.add('active');
            renderStageForDetection(
              fInfo?.id || mItem.face_detection_ids?.[0] || startDetId,
              fInfo?.box || null,
              mItem
            );
          };
          photosEl.append(thumbBtn);
        }
      }
    } catch {
      photosEl.replaceChildren();
    }
  }

  // Shared Sorting Dialog (#ch-people-share-dialog)
  async function openShareSortingDialog() {
    const dialog = $d('ch-people-share-dialog');
    const toggle = $d('ch-people-share-toggle');
    const statusEl = $d('ch-people-share-status');
    const listEl = $d('ch-people-share-list');
    const closeBtn = $d('ch-people-share-close');
    if (!dialog) return;

    if (closeBtn) closeBtn.onclick = () => dialog.close();
    if (statusEl) statusEl.textContent = tr('Lade Freigabestatus …');
    if (listEl) listEl.replaceChildren();
    dialog.showModal();

    async function refreshSharedSorting() {
      try {
        const q = new URLSearchParams({ gallery: getGallery() });
        const data = await api('subjects/shared-sorting?' + q.toString());
        if (toggle) {
          toggle.checked = Boolean(data?.sharing_enabled);
        }
        const available = Array.isArray(data?.available) ? data.available : [];
        if (listEl) listEl.replaceChildren();
        if (available.length === 0) {
          if (statusEl) {
            statusEl.textContent = tr('Aktuell hat noch kein anderer Mitbenutzer dieser Galerie seine Personensortierung freigegeben.');
          }
        } else {
          if (statusEl) {
            statusEl.textContent = tr('Wähle eine freigegebene Sortierung aus, um Zusammenführungen, Namen und Titelbilder für gemeinsame Fotos zu übernehmen:');
          }
          for (const entry of available) {
            const row = document.createElement('div');
            row.className = 'ch-people-share-item';

            const info = document.createElement('div');
            info.className = 'ch-people-share-info';
            const title = document.createElement('strong');
            title.textContent = entry.display_name || entry.user_id;
            const meta = document.createElement('p');
            meta.className = 'ch-hint';
            const sampleNames = Array.isArray(entry.sample_labels) && entry.sample_labels.length > 0
              ? ` · ${tr('Namen')}: ${entry.sample_labels.join(', ')}`
              : '';
            meta.textContent = `${formatPeopleCount(entry.people_count || 0, tr, locale)} · ${formatPhotoCount(entry.shared_files || 0, tr, locale)}${sampleNames}`;
            info.append(title, meta);

            const adoptBtn = document.createElement('button');
            adoptBtn.type = 'button';
            adoptBtn.className = 'ch-button ch-primary';
            adoptBtn.textContent = tr('Sortierung übernehmen');
            adoptBtn.onclick = async () => {
              adoptBtn.disabled = true;
              try {
                const res = await api('subjects/shared-sorting', {
                  method: 'POST',
                  body: JSON.stringify({
                    mode: 'adopt',
                    source_user: entry.user_id,
                    gallery: getGallery()
                  })
                });
                if (typeof res?.rules_revision === 'number') rulesRevision = res.rules_revision;
                if (statusEl) {
                  statusEl.textContent = tr('Sortierung erfolgreich übernommen ({groups} Personen auf {files} Fotos synchronisiert).', {
                    groups: res?.groups_synced || 0,
                    files: res?.files_synced || 0
                  });
                }
                await loadSubjects(true);
              } catch (err) {
                if (statusEl) statusEl.textContent = err.message || tr('Übernahme fehlgeschlagen.');
              } finally {
                adoptBtn.disabled = false;
              }
            };

            row.append(info, adoptBtn);
            listEl.append(row);
          }
        }
      } catch (err) {
        if (statusEl) statusEl.textContent = err.message || tr('Freigabestatus konnte nicht geladen werden.');
      }
    }

    if (toggle) {
      toggle.onchange = async () => {
        toggle.disabled = true;
        try {
          const res = await api('subjects/shared-sorting', {
            method: 'POST',
            body: JSON.stringify({ mode: 'share', enabled: toggle.checked })
          });
          if (statusEl) {
            statusEl.textContent = res?.sharing_enabled
              ? tr('Deine Personensortierung ist jetzt für Mitbenutzer gemeinsamer Ordner freigegeben.')
              : tr('Freigabe deiner Personensortierung wurde beendet.');
          }
        } catch (err) {
          toggle.checked = !toggle.checked;
          if (statusEl) statusEl.textContent = err.message || tr('Einstellung konnte nicht gespeichert werden.');
        } finally {
          toggle.disabled = false;
        }
      };
    }

    await refreshSharedSorting();
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

  const helpToggleBtn = $('ch-people-help-toggle');
  if (helpToggleBtn) {
    helpToggleBtn.onclick = () => {
      const guide = $('ch-people-guide');
      if (!guide) return;
      guide.hidden = !guide.hidden;
      helpToggleBtn.setAttribute('aria-expanded', String(!guide.hidden));
    };
  }

  const shareSortingBtn = $('ch-people-share-sorting');
  if (shareSortingBtn) {
    shareSortingBtn.onclick = () => openShareSortingDialog();
  }

  const undoBtn = $('ch-people-undo-btn');
  if (undoBtn) {
    undoBtn.onclick = () => performUndo();
  }
  const undoCloseBtn = $('ch-people-undo-close');
  if (undoCloseBtn) {
    undoCloseBtn.onclick = () => showUndoBar(null);
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

  // Rename controls
  const renameBtn = $('ch-people-rename-btn');
  const renameBar = $('ch-people-rename-bar');
  const renameInput = $('ch-people-rename-input');
  const renameSave = $('ch-people-rename-save');
  const renameCancel = $('ch-people-rename-cancel');
  if (renameBtn && renameBar && renameInput) {
    renameBtn.onclick = () => {
      renameBar.hidden = !renameBar.hidden;
      if (!renameBar.hidden) {
        renameInput.value = selectedSubject?.label || '';
        renameInput.focus();
      }
    };
    if (renameCancel) {
      renameCancel.onclick = () => {
        renameBar.hidden = true;
      };
    }
    const submitRename = async () => {
      if (!selectedSubject) return;
      const newLabel = renameInput.value.trim();
      renameBar.hidden = true;
      await performSubjectAction('rename', { label: newLabel });
    };
    if (renameSave) {
      renameSave.onclick = () => submitRename();
    }
    renameInput.onkeydown = (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitRename();
      } else if (e.key === 'Escape') {
        renameBar.hidden = true;
      }
    };
  }

  const inspectSelfBtn = $('ch-people-inspect-self');
  if (inspectSelfBtn) {
    inspectSelfBtn.onclick = () => {
      if (selectedSubject) openInspectDialog(selectedSubject);
    };
  }

  const toggleBoxesBtn = $('ch-people-toggle-boxes');
  if (toggleBoxesBtn) {
    updateToggleBoxesBtn();
    toggleBoxesBtn.onclick = () => {
      showFaceBoxes = !showFaceBoxes;
      updateToggleBoxesBtn();
    };
  }

  // Toggle stranger ("Kenne ich nicht" / "Unbekannte Personen")
  const strangerBtn = $('ch-people-toggle-stranger');
  if (strangerBtn) {
    strangerBtn.onclick = async () => {
      if (!selectedSubject) return;
      const action = selectedSubject.stranger ? 'unstranger' : 'stranger';
      await performSubjectAction(action);
    };
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
    mergeBtn.onclick = () => openMergeDialog({ mode: 'merge_person' });
  }

  async function openMergeDialog({ mode = 'merge_person', detectionIds = null } = {}) {
    if (!selectedSubject) return;
    const dialog = $d('ch-people-merge-dialog');
    const titleEl = $d('ch-people-merge-title');
    const container = $d('ch-people-merge-targets');
    const selectionPreview = $d('ch-people-merge-selection-preview');
    const okBtn = $d('ch-people-merge-ok');
    const cancelBtn = $d('ch-people-merge-cancel');
    const searchInput = $d('ch-people-merge-search');
    const moreWrap = $d('ch-people-merge-more-wrap');
    const moreBtn = $d('ch-people-merge-more');
    if (!dialog || !container || !okBtn) {
      console.error('Merge dialog not found; failing closed');
      return;
    }

    const isMoveMode = mode === 'move_photos' && Array.isArray(detectionIds) && detectionIds.length > 0;
    if (titleEl) {
      titleEl.textContent = isMoveMode
        ? tr('Ausgewählte Fotos zu Person verschieben')
        : tr('Person zusammenführen');
    }
    okBtn.textContent = isMoveMode ? tr('Fotos verschieben') : tr('Zusammenführen');

    let mergeFilter = 'all';
    let mergeCursor = null;
    let mergeMore = false;
    let mergeTargets = [];
    let selectedTarget = null;
    let selectedTargetId = null;
    let mergeLoading = false;

    okBtn.disabled = true;
    if (searchInput) searchInput.value = '';

    function renderSelectionPreview() {
      if (!selectionPreview) return;
      selectionPreview.replaceChildren();
      if (!selectedTarget) {
        const hint = document.createElement('p');
        hint.className = 'ch-hint';
        hint.textContent = isMoveMode
          ? tr('Bitte oben eine Zielperson anklicken, der die ausgewählten Fotos zugeordnet werden sollen.')
          : tr('Bitte oben eine Person anklicken, mit der zusammengeführt werden soll.');
        selectionPreview.append(hint);
        return;
      }

      const bar = document.createElement('div');
      bar.className = 'ch-people-merge-compare-bar';

      const srcChip = document.createElement('div');
      srcChip.className = 'ch-people-merge-chip';
      if (selectedSubject.cover_id) {
        const sImg = document.createElement('img');
        sImg.src = previewUrl(selectedSubject.cover_id);
        sImg.alt = '';
        srcChip.append(sImg);
      }
      const sTxt = document.createElement('span');
      sTxt.textContent = isMoveMode
        ? formatPhotoCount(detectionIds.length, tr, locale)
        : `${subjectDisplayName(selectedSubject)} (${formatPhotoCount(selectedSubject.photo_count, tr, locale)})`;
      srcChip.append(sTxt);

      const arrow = document.createElement('span');
      arrow.className = 'ch-people-merge-arrow';
      arrow.textContent = '➔';

      const tgtChip = document.createElement('div');
      tgtChip.className = 'ch-people-merge-chip is-target';
      if (selectedTarget.cover_id) {
        const tImg = document.createElement('img');
        tImg.src = previewUrl(selectedTarget.cover_id);
        tImg.alt = '';
        tgtChip.append(tImg);
      }
      const tTxt = document.createElement('strong');
      tTxt.textContent = `${tr('Ausgewählt')}: ${subjectDisplayName(selectedTarget)} (${formatPhotoCount(selectedTarget.photo_count, tr, locale)})`;
      tgtChip.append(tTxt);

      const inspectTargetBtn = document.createElement('button');
      inspectTargetBtn.type = 'button';
      inspectTargetBtn.className = 'ch-button ch-button-sm';
      inspectTargetBtn.textContent = tr('Ganzes Bild & Fotos prüfen');
      inspectTargetBtn.onclick = () => openInspectDialog(selectedTarget, { canMerge: false });

      bar.append(srcChip, arrow, tgtChip, inspectTargetBtn);
      selectionPreview.append(bar);
    }

    renderSelectionPreview();

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
        const labelText = (t.label || '').toLowerCase();
        return labelText.includes(q) || countText.includes(q) || String(t.photo_count).includes(q);
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
          item.dataset.targetId = target.id;
          const isSelected = target.id === selectedTargetId;
          if (isSelected) item.classList.add('active');
          item.setAttribute('aria-pressed', String(isSelected));

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

          if (target.label) {
            const nameEl = document.createElement('strong');
            nameEl.className = 'ch-person-name';
            nameEl.textContent = target.label;
            item.append(nameEl);
          }

          // Human readable label - NEVER show code UUID string
          const count = document.createElement('span');
          count.className = 'ch-person-count';
          count.textContent = formatPhotoCount(target.photo_count, tr, locale);
          item.append(count);

          const checkBadge = document.createElement('span');
          checkBadge.className = 'ch-people-merge-selected-badge';
          checkBadge.textContent = '✓ ' + tr('Ausgewählt');
          checkBadge.hidden = !isSelected;
          item.append(checkBadge);

          if (target.review_count && target.review_count > 0) {
            const badge = document.createElement('span');
            badge.className = 'ch-person-badge ch-person-badge-review';
            badge.textContent = tr('{count} zu prüfen', { count: target.review_count });
            item.append(badge);
          }

          item.onclick = () => {
            selectedTarget = target;
            selectedTargetId = target.id;
            okBtn.disabled = false;
            container.querySelectorAll('.ch-people-merge-choice').forEach((b) => {
              const active = b === item;
              b.classList.toggle('active', active);
              b.setAttribute('aria-pressed', String(active));
              const sb = b.querySelector('.ch-people-merge-selected-badge');
              if (sb) sb.hidden = !active;
            });
            renderSelectionPreview();
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
        const targetLabel = selectedTarget
          ? `${subjectDisplayName(selectedTarget)} (${formatPhotoCount(selectedTarget.photo_count, tr, locale)})`
          : tr('der ausgewählten Person');
        if (isMoveMode) {
          await performSubjectAction('split', {
            target_id: selectedTargetId,
            detection_ids: detectionIds,
            isDestructive: false
          });
        } else {
          await performSubjectAction('merge', {
            target_id: selectedTargetId,
            confirmTitle: tr('Person zusammenführen?'),
            confirmText: tr('Diese Person wird mit {target} zusammengelegt. Du kannst dies anschließend oben rückgängig machen.', { target: targetLabel }),
            confirmOkLabel: tr('Zusammenführen'),
            isDestructive: true
          });
        }
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

  // Selection actions: Cover, Split, Move, Remove, Resolve, Clear
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

  const coverSelectedBtn = $('ch-people-cover-selected');
  if (coverSelectedBtn) {
    coverSelectedBtn.onclick = async () => {
      const ids = getSelectedDetectionIds();
      if (ids.length === 0) return;
      await setCoverDetection(ids[0]);
      clearSelection();
    };
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

  const moveSelectedBtn = $('ch-people-move-selected');
  if (moveSelectedBtn) {
    moveSelectedBtn.onclick = () => {
      const ids = getSelectedDetectionIds();
      if (ids.length === 0) return;
      openMergeDialog({ mode: 'move_photos', detectionIds: ids });
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
          showUndoBar(null);
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
    similarItems = [];
    similarGeneration++;
    renderSimilar();
    selectedDetections.clear();
    showUndoBar(null);
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

  updateFilterExplainer();

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
    openSubject: (subject) => openSubject(subject),
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
