// The load test's Livewire helpers (scripts/loadtest/livewire.js, #176).
// They have no k6 imports, so they run under node:test too.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readPage, updateBody, nextSnapshot, UPDATE_HEADERS } from '../../../scripts/loadtest/livewire.js';

const snapshot = (name, extra = {}) => JSON.stringify({ data: { count: 0, ...extra }, memo: { id: `id-${name}`, name, path: 'vote' }, checksum: 'abc' });
const escape = (text) => text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const tricky = 'it\'s <b>bold</b> & "quoted"';
const html = `<!DOCTYPE html><html><body>
<div wire:snapshot="${escape(snapshot('vote', { question: tricky }))}" wire:id="id-vote">
  <div wire:snapshot="${escape(snapshot('question-card'))}" wire:id="id-card"></div>
</div>
<div wire:snapshot="not json" wire:id="broken"></div>
<script src="/livewire/livewire.js?id=1" data-csrf="csrf-token-123" data-update-uri="/livewire/update"></script>
</body></html>`;

test('readPage finds the CSRF token, the update URI and every readable component snapshot', () => {
    const page = readPage(html);

    assert.equal(page.csrf, 'csrf-token-123');
    assert.equal(page.updateUri, '/livewire/update');
    assert.deepEqual(page.components.map((component) => component.name), ['vote', 'question-card']);
    // The snapshot comes back exactly as Livewire wrote it, unescaped.
    assert.equal(page.components[0].snapshot, snapshot('vote', { question: tricky }));
});

test('readPage copes with a page without Livewire', () => {
    assert.deepEqual(readPage('<html></html>'), { csrf: null, updateUri: '/livewire/update', components: [] });
});

test('updateBody is the payload Livewire 3 posts: a $refresh call by default', () => {
    assert.deepEqual(JSON.parse(updateBody('t', 'SNAP')), {
        _token: 't',
        components: [{ snapshot: 'SNAP', updates: {}, calls: [{ path: '', method: '$refresh', params: [] }] }],
    });
    assert.equal(JSON.parse(updateBody('t', 'SNAP', 'upvote')).components[0].calls[0].method, 'upvote');
    assert.equal(UPDATE_HEADERS['X-Livewire'], '');
});

test('nextSnapshot reads the new snapshot from a response, or gives null', () => {
    const response = (value) => ({ json: (path) => { assert.equal(path, 'components.0.snapshot'); return value; } });

    assert.equal(nextSnapshot(response('NEW')), 'NEW');
    assert.equal(nextSnapshot(response(undefined)), null);
    assert.equal(nextSnapshot({ json: () => { throw new Error('not json'); } }), null);
});
