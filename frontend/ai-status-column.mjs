export function registerStatusColumn(Column,navigation){
// Use Nextcloud's column API. Batch visible nodes; never request analysis bodies here.
const cache = new Map()
const pending = new Map()
const visible = new Map()
let timer, inFlight = false
const labels = { done: 'Bearbeitet', pending: 'Offen', error: 'Fehler', changed: 'Älterer Dateistand', publishing: 'Speichern ausstehend', unselected: 'Nicht ausgewählt' }
const tr = (key, values) => window.MediaAIi18n?.t(key, values) || key
const renderStatus = (element, item) => {
  element.replaceChildren()
  const badge = document.createElement('span')
  badge.className = 'mai-file-status'
  badge.dataset.state = item?.status || 'unselected'
  badge.textContent = tr(labels[item?.status] || 'Nicht ausgewählt')
  if (item?.counts) {
    badge.title = tr('Auswahl: {done} von {total} bearbeitet', { done: item.counts.done + item.counts.changed, total: item.counts.total })
    badge.textContent = `${item.counts.done + item.counts.changed} / ${item.counts.total}`
  }
  element.append(badge)
}
async function flush() {
  if (inFlight || !pending.size) return
  inFlight = true
  const batch = [...pending.entries()].slice(0, 100)
  for (const [id] of batch) pending.delete(id)
  try {
    const response = await fetch(OC.linkToOCS('apps/nextgallery/ai/api/v1', 2) + 'statuses?format=json', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'OCS-APIRequest': 'true', requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_ids: batch.map(([id]) => id) }),
    })
    if (!response.ok) throw Error('HTTP ' + response.status)
    const result = (await response.json()).ocs.data.statuses
    for (const [id, elements] of batch) {
      const item = result[id] || { status: 'unselected' }
      cache.set(id, { item, at: Date.now() })
      for (const element of elements) if (element.isConnected) renderStatus(element, item)
    }
    while (cache.size > 2000) cache.delete(cache.keys().next().value)
  } catch {
    for (const [, elements] of batch) for (const element of elements) {
      if (element.isConnected) element.textContent = tr('Status nicht verfügbar')
    }
  } finally {
    inFlight = false
    if (pending.size) timer = setTimeout(flush, 60)
  }
}
const column = new Column({
  id: 'media-ai-status', title: 'Media AI',
  render(node) {
    const element = document.createElement('span')
    const id = Number(node.fileid)
    if (!id || (node.type !== 'folder' && !/^(image|video)\//.test(node.mime))) return element
    if (!visible.has(id)) visible.set(id, new Set())
    visible.get(id).add(element)
    const stored = cache.get(id)
    if (stored && Date.now() - stored.at < 20000) renderStatus(element, stored.item)
    else {
      element.textContent = tr('Laden …')
      if (!pending.has(id)) pending.set(id, new Set())
      pending.get(id).add(element)
      clearTimeout(timer)
      timer = setTimeout(flush, 40)
    }
    return element
  },
})
function register() {
  for (const view of navigation.views) {
    if (['trashbin', 'public', 'sharingout', 'sharingin'].includes(view.id)) continue
    if (!(view.columns || []).some(item => item.id === column.id)) {
      // Nextcloud 32 exposes columns as a getter and leaves it undefined for core views.
      // Keep each view and its native columns; add an own property with the extra column.
      Object.defineProperty(view, 'columns', { value: [...(view.columns || []), column], configurable: true })
    }
  }
}
navigation.addEventListener('update', register)
register()

setInterval(() => {
  if (document.hidden) return
  for (const [id, elements] of visible) {
    const connected = new Set([...elements].filter(element => element.isConnected))
    if (!connected.size) { visible.delete(id); continue }
    visible.set(id, connected)
    if (Date.now() - (cache.get(id)?.at || 0) >= 20000) pending.set(id, connected)
  }
  flush()
}, 10000)
const rerenderVisible = () => {
  for (const [id, elements] of visible) {
    const stored = cache.get(id)
    if (!stored) continue
    for (const element of elements) if (element.isConnected) renderStatus(element, stored.item)
  }
}
const loadPreferences = () => fetch(OC.generateUrl('/apps/nextgallery/ai/preferences'), { credentials: 'same-origin' })
  .then(response => response.ok ? response.json() : null)
  .then(preference => {
    if (!preference?.language) return
    if (window.MediaAIi18n) window.MediaAIi18n.language = preference.language
    else window.MediaAIi18n = { language: preference.language }
    rerenderVisible()
  })
  .catch(() => {})
window.addEventListener('media-ai-i18n-ready', rerenderVisible)
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadPreferences, { once: true })
else loadPreferences()

}
