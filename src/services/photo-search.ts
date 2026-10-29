export type SearchTerm = { name: string; exclude: boolean; kind: 'person' | 'place' };
type Cluster = { name: string; cluster_id: number | string; cluster_type: string; user_id?: string };

export function isPhotoExpression(value: string): boolean {
  return /[+-]|\b(?:lieu|place):/i.test(value);
}

export function parsePhotoSearch(value: string): SearchTerm[] {
  if (value.length > 1000) throw new Error('syntax');
  const terms: SearchTerm[] = [];
  let offset = 0;
  while (offset < value.length) {
    const head = /^\s*([+-]?)\s*(?:(lieu|place):\s*)?/i.exec(value.slice(offset))!;
    if (terms.length && !head[1] && !head[2]) throw new Error('syntax');
    offset += head[0].length;
    let name: string;
    if (value[offset] === '"') {
      const end = value.indexOf('"', offset + 1);
      if (end < 0) throw new Error('syntax');
      name = value.slice(offset + 1, end);
      offset = end + 1;
    } else {
      const tail = value.slice(offset);
      const end = tail.search(/[+-]|\s+(?=(?:lieu|place):)/i);
      name = end < 0 ? tail : tail.slice(0, end);
      offset += name.length;
      if (name.includes('"')) throw new Error('syntax');
    }
    name = name.trim();
    if (!name || /[\r\n]/.test(name) || terms.length >= 17) throw new Error('syntax');
    terms.push({ name, exclude: head[1] === '-', kind: head[2] ? 'place' : 'person' });
    if (!value.slice(offset).trim()) break;
  }
  if (!terms.length) throw new Error('syntax');
  return terms;
}

export function resolvePhotoSearch<T extends Cluster>(terms: SearchTerm[], clusters: T[]) {
  const normalize = (name: string) => name.normalize('NFC').trim().toLocaleLowerCase();
  const resolved = terms.map((term) => {
    const matches = clusters.filter(
      (c) =>
        c.cluster_type === (term.kind === 'person' ? 'recognize' : 'places') &&
        normalize(c.name) === normalize(term.name),
    );
    if (matches.length !== 1 || !/^[1-9][0-9]{0,17}$/.test(String(matches[0].cluster_id))) throw new Error(term.name);
    return { ...term, cluster: matches[0] };
  });
  const primary = resolved.find((term) => term.kind === 'person' && !term.exclude)?.cluster;
  if (!primary && resolved.some((term) => term.kind === 'person')) throw new Error('syntax');
  const people = new Set<string>();
  const places = new Set<string>();
  const signs = new Map<string, boolean>();
  for (const term of resolved) {
    const id = String(term.cluster.cluster_id);
    const key = `${term.kind}:${id}`;
    if (signs.has(key) && signs.get(key) !== term.exclude) throw new Error(term.name);
    signs.set(key, term.exclude);
    if (term.kind === 'person' && id === String(primary?.cluster_id)) continue;
    (term.kind === 'place' ? places : people).add((term.exclude ? '-' : '') + id);
  }
  if (people.size > 8 || places.size > 8) throw new Error('syntax');
  return { primary, people: [...people], places: [...places] };
}

export function completionTerm(value: string) {
  let start = 0;
  let quoted = false;
  for (let i = 0; i < value.length; i++) {
    if (value[i] === '"') quoted = !quoted;
    if (!quoted && (value[i] === '+' || value[i] === '-')) start = i + 1;
    if (!quoted && /\s/.test(value[i]) && /^(?:lieu|place):/i.test(value.slice(i + 1))) start = i + 1;
  }
  const tail = value.slice(start);
  const head = /^\s*(?:(lieu|place):\s*)?/i.exec(tail)!;
  return {
    start: start + head[0].length,
    kind: head[1] ? 'place' : 'person',
    query: tail.slice(head[0].length).replace(/^"|"$/g, ''),
  };
}

export function completePhotoSearch(value: string, name: string): string {
  const term = completionTerm(value);
  const quoted = /[+\-":]|\b(?:lieu|place):/i.test(name) ? `"${name}"` : name;
  return value.slice(0, term.start) + quoted;
}
