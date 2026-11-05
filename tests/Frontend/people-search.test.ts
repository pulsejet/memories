import assert from 'node:assert/strict';
import { parsePeopleSearch as parse, resolvePeopleSearch as resolve } from '../../src/services/people-search';
const people = [
  { name: 'Thomas', cluster_id: 1 },
  { name: 'Maud', cluster_id: 2 },
  { name: 'Jean-Pierre', cluster_id: 3 },
];
assert.equal(parse('plage'), null);
assert.deepEqual(resolve(parse('Thomas+Maud')!, people), { primary: people[0], filters: ['2'] });
assert.deepEqual(resolve(parse('thomas - Maud')!, people).filters, ['-2']);
assert.deepEqual(resolve(parse('"Jean-Pierre"+Maud')!, people).filters, ['2']);
assert.deepEqual(resolve(parse('Thomas+Maud+Maud')!, people).filters, ['2']);
assert.throws(() => resolve(parse('Thomas-Thomas')!, people));
assert.throws(() => resolve(parse('Thomas+Inconnu')!, people));
assert.throws(() => resolve(parse('Thomas+Maud')!, [...people, people[1]]));
for (const q of ['Thomas+', 'Thomas++Maud', '-Maud', 'Thomas+"Maud', 'Thomas+""', 'Thomas+Maud-'])
  assert.throws(() => parse(q));
console.log('People search: 14 assertions passed');
