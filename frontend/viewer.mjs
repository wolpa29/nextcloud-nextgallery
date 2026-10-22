/** A bounded, bidirectional cursor over timeline pages, independent of DOM blocks. */
export function createTimelinePager({request, groups, period, offset, itemId, seed, pageSize = 150, current = () => true}) {
 let group = groups.findIndex(entry => entry.key === period);
 if (group < 0) throw new Error('Gallery period is unavailable');
 const pages = new Map();
 const key = (g, off) => `${groups[g].key}:${off}`;
 let page = {
  items: seed,
  more: offset + pageSize < groups[group].count,
  next: offset + pageSize
 };
 let index = page.items.findIndex(item => item.id === itemId);
 if (index < 0) throw new Error('Gallery item is unavailable');
 pages.set(key(group, offset), page);

 async function read(g, off) {
  const id = key(g, off);
  if (pages.has(id)) return pages.get(id);
  const result = await request({period: groups[g].key, offset: off, limit: pageSize});
  if (!current()) return null;
  pages.set(id, result);
  // The viewer needs its nearby pages, not a second copy of the whole gallery.
  while (pages.size > 5) pages.delete(pages.keys().next().value);
  return result;
 }

 return {
  canMove(direction) {
   return direction < 0
    ? index > 0 || offset > 0 || group > 0
    : index < page.items.length - 1 || page.more || group < groups.length - 1;
  },
  async move(direction) {
   if (!current()) return null;
   const adjacent = index + direction;
   if (adjacent >= 0 && adjacent < page.items.length) {
    index = adjacent;
    return page.items[index];
   }
   let nextGroup = group, nextOffset = offset, nextPage = page;
   while (current()) {
    if (direction > 0) {
     if (nextPage.more) {
      const next = nextPage.next ?? nextOffset + pageSize;
      if (next <= nextOffset) throw new Error('Invalid gallery cursor');
      nextOffset = next;
     } else {
      nextGroup++;
      nextOffset = 0;
     }
    } else if (nextOffset > 0) {
     nextOffset = Math.max(0, nextOffset - pageSize);
    } else {
     nextGroup--;
     if (nextGroup < 0) return null;
     nextOffset = Math.floor(Math.max(0, groups[nextGroup].count - 1) / pageSize) * pageSize;
    }
    if (nextGroup >= groups.length) return null;
    nextPage = await read(nextGroup, nextOffset);
    if (!nextPage || !current()) return null;
    // Access revocation can leave a formerly indexed page empty. Keep paging.
    if (!nextPage.items.length) continue;
    group = nextGroup;
    offset = nextOffset;
    page = nextPage;
    index = direction > 0 ? 0 : page.items.length - 1;
    return page.items[index];
   }
   return null;
  }
 };
}
