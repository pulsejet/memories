import assert from 'node:assert/strict';
import {
  parsePhotoSearch as parse,
  resolvePhotoSearch as resolve,
  completionTerm,
  completePhotoSearch,
} from '../../src/services/photo-search';
const clusters = [
  { name: 'Alice', cluster_id: 1, cluster_type: 'recognize', user_id: 'alice' },
  { name: 'Bob', cluster_id: 2, cluster_type: 'recognize', user_id: 'alice' },
  { name: 'Paris', cluster_id: 10, cluster_type: 'places' },
  { name: 'Saint-Jean', cluster_id: 20, cluster_type: 'places' },
];
assert.deepEqual(resolve(parse('Alice+Bob lieu:Paris'), clusters), {
  primary: clusters[0],
  people: ['2'],
  places: ['10'],
});
assert.deepEqual(resolve(parse('Alice -lieu:Paris'), clusters).places, ['-10']);
assert.deepEqual(resolve(parse('lieu:Paris'), clusters), { primary: undefined, people: [], places: ['10'] });
assert.deepEqual(resolve(parse('lieu:"Saint-Jean"-lieu:Paris'), clusters).places, ['20', '-10']);
assert.throws(() => resolve(parse('Alice+Bob-Bob'), clusters));

assert.throws(() => resolve(parse('Alice lieu:Unknown'), clusters));
assert.throws(() => resolve(parse('Alice lieu:Paris'), [...clusters, clusters[2]]));
for (const text of ['Alice+', 'Alice++Bob', 'Alice lieu:', 'Alice+"Bob', 'Alice lieu:Paris+', 'Alice\nBob'])
  assert.throws(() => parse(text));
assert.deepEqual(completionTerm('Alice+Bo'), { start: 6, kind: 'person', query: 'Bo' });
assert.deepEqual(completionTerm('Alice lieu:Pa'), { start: 11, kind: 'place', query: 'Pa' });
assert.equal(completePhotoSearch('Alice+Bo', 'Bob'), 'Alice+Bob');
assert.equal(completePhotoSearch('Alice-lieu:Sa', 'Saint-Jean'), 'Alice-lieu:"Saint-Jean"');
assert.equal(completePhotoSearch('Alice lieu:"Saint-Je', 'Saint-Jean'), 'Alice lieu:"Saint-Jean"');
assert.equal(completePhotoSearch('Alice + Bo', 'Bob'), 'Alice + Bob');
console.log('Photo search parser, resolution and completion checks passed');
