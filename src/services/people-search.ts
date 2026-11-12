export type PersonTerm = { name: string; exclude: boolean };

/** Quoted names can contain operators, for example "Jean-Pierre"+Maud. */
export function parsePeopleSearch(value: string): PersonTerm[] | null {
  if (!/[+-]/.test(value)) return null;
  if (value.length > 500) throw new Error('syntax');
  const terms: PersonTerm[] = [];
  let offset = 0;
  let exclude = false;
  while (offset < value.length) {
    const match = /^\s*(?:"([^"\r\n]+)"|([^+\-"\r\n]+))\s*/.exec(value.slice(offset));
    if (!match) throw new Error('syntax');
    const name = (match[1] ?? match[2]).trim();
    if (!name || terms.length >= 9) throw new Error('syntax');
    terms.push({ name, exclude });
    offset += match[0].length;
    if (offset === value.length) break;
    const operator = value[offset++];
    if (operator !== '+' && operator !== '-') throw new Error('syntax');
    exclude = operator === '-';
    if (offset === value.length) throw new Error('syntax');
  }
  if (terms.length < 2) throw new Error('syntax');
  return terms;
}

export function resolvePeopleSearch<T extends { name: string; cluster_id: string | number }>(
  terms: PersonTerm[],
  people: T[],
): { primary: T; filters: string[] } {
  const normalize = (name: string) => name.normalize('NFC').trim().toLocaleLowerCase();
  const resolved = terms.map((term) => {
    const matches = people.filter((person) => normalize(person.name) === normalize(term.name));
    if (matches.length !== 1) throw new Error(term.name);
    const person = matches[0];
    if (!/^[1-9][0-9]{0,17}$/.test(String(person.cluster_id))) throw new Error(term.name);
    return { ...term, person };
  });
  const primary = resolved[0].person;
  const filters = new Set<string>();
  const signs = new Map<string, boolean>();
  for (const term of resolved) {
    const id = String(term.person.cluster_id);
    if (signs.has(id) && signs.get(id) !== term.exclude) throw new Error(term.name);
    signs.set(id, term.exclude);
    if (id !== String(primary.cluster_id)) filters.add((term.exclude ? '-' : '') + id);
  }
  return { primary, filters: [...filters] };
}
