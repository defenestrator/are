// Just enough of Livewire 3's wire protocol to do what a browser does when a
// page polls: read the component snapshots and the CSRF token from the page,
// then POST to the update URI with a $refresh (or another method) call, and
// keep the new snapshot for the next one.

/** Undo the HTML attribute escaping Blade applies to wire:snapshot. */
function unescapeHtml(text) {
    return text
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
}

/**
 * The page's Livewire state: the CSRF token, the update URI, and every
 * component snapshot as { name, snapshot }. The snapshot is the raw JSON
 * string Livewire expects back.
 */
export function readPage(html) {
    const csrf = /data-csrf="([^"]+)"/.exec(html);
    const updateUri = /data-update-uri="([^"]+)"/.exec(html);
    const components = [];
    const pattern = /wire:snapshot="([^"]+)"/g;
    let match;

    while ((match = pattern.exec(html)) !== null) {
        const snapshot = unescapeHtml(match[1]);
        try {
            components.push({ name: JSON.parse(snapshot).memo.name, snapshot });
        } catch (error) {
            // Not a snapshot we can read; skip it.
        }
    }

    return {
        csrf: csrf ? csrf[1] : null,
        updateUri: updateUri ? unescapeHtml(updateUri[1]) : '/livewire/update',
        components,
    };
}

/**
 * The request body for one component: a $refresh by default, or a call to a
 * method. In Livewire 3, $refresh is not a server method: the browser's
 * $wire.$refresh() is a plain commit with no calls, which re-renders the
 * component (livewire.esm.js: wireProperty("$refresh", ... $commit)).
 */
export function updateBody(csrf, snapshot, method, params) {
    const calls = !method || method === '$refresh' ? [] : [{ path: '', method, params: params || [] }];

    return JSON.stringify({ _token: csrf, components: [{ snapshot, updates: {}, calls }] });
}

export const UPDATE_HEADERS = { 'Content-Type': 'application/json', 'X-Livewire': '', Accept: 'application/json' };

/** The component's new snapshot from an update response, or null. */
export function nextSnapshot(response) {
    try {
        const snapshot = response.json('components.0.snapshot');

        return typeof snapshot === 'string' ? snapshot : null;
    } catch (error) {
        return null;
    }
}
