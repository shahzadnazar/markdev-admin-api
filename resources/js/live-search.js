/**
 * Live search for the admin filter bars.
 *
 * Typing narrows the list as you go; the Filter button still does exactly what
 * it always did. Both work, and neither knows about the other — the button is
 * an ordinary form submit this file never touches.
 *
 * Only the results table is re-fetched, so the page never reloads and the
 * caret stays where the admin left it. The server renders the same partial the
 * page already includes, asked for with `partial=1`, so there is one definition
 * of a row rather than a second one in JavaScript that would drift.
 *
 * This began as a script inside the students screen. Copying it to nineteen
 * more would have been nineteen copies of a rule to keep in step, so it is one
 * file driven by markup instead:
 *
 *   <form data-live-search="#notes-results"> … </form>
 *   <div id="notes-results"> @include('admin.notes._results') </div>
 *
 * Everything is delegated from `document`, so a container that has just been
 * replaced needs no rebinding — which is the whole reason the pagination links
 * inside it keep working after the first keystroke.
 */

/** How long to wait after the last keystroke. Long enough not to fire on every letter. */
const DEBOUNCE_MS = 300;

/** Per-form state: the pending timer and the request currently in flight. */
const state = new WeakMap();

function stateFor(form) {
    if (! state.has(form)) {
        state.set(form, { timer: null, inflight: null });
    }

    return state.get(form);
}

/** The results container a form drives, if it is on the page. */
function resultsFor(form) {
    const selector = form.getAttribute('data-live-search');

    return selector ? document.querySelector(selector) : null;
}

/**
 * The URL this form's current values describe.
 *
 * Empty fields are left out so the address bar stays readable, and `page` is
 * dropped: narrowing a search puts you back on page one, and keeping the old
 * number is how a search lands on an empty page.
 */
function urlFor(form) {
    // getAttribute, not form.action: a named control shadows the form property
    // of the same name, and the audit log filters on a field called `action`.
    // `form.action` there returns the <select>, and the URL built from it was a
    // 404 — one of those bugs that only exists on the one screen that has it.
    const url = new URL(form.getAttribute('action') || window.location.href, window.location.origin);
    const params = new URLSearchParams();

    for (const [key, value] of new FormData(form)) {
        // `page` in any of its spellings: a paginator can be given its own
        // name (`articles_page`), and carrying the old number into a narrowed
        // search is how a search lands on an empty page.
        if (key === 'partial' || key === 'page' || key.endsWith('_page')) {
            continue;
        }

        if (typeof value === 'string' && value.trim() !== '') {
            params.append(key, value);
        }
    }

    url.search = params.toString();

    return url;
}

/**
 * Fetch and swap.
 *
 * @param {HTMLFormElement} form
 * @param {string|URL|null} pageUrl a pagination link, when a page was clicked
 */
function render(form, pageUrl = null) {
    const results = resultsFor(form);

    if (! results) {
        return;
    }

    const own = stateFor(form);
    const target = pageUrl ? new URL(pageUrl, window.location.origin) : urlFor(form);

    const fetchUrl = new URL(target);
    fetchUrl.searchParams.set('partial', '1');

    if (own.inflight) {
        own.inflight.abort();
    }

    own.inflight = new AbortController();
    results.classList.add('opacity-50');

    fetch(fetchUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: own.inflight.signal,
        credentials: 'same-origin',
    })
        .then((response) => {
            if (! response.ok) {
                throw new Error(String(response.status));
            }

            return response.text();
        })
        .then((html) => {
            results.innerHTML = html;
            // replaceState, not pushState: a keystroke is not a page the back
            // button should have to walk through. The URL still holds the
            // search, so a reload or a copied link shows the same list.
            window.history.replaceState(null, '', target);
        })
        .catch((error) => {
            // A superseded keystroke is not a failure. Anything else — a lost
            // connection, an expired session, a 500 — falls back to a normal
            // page load, which shows the real error instead of a list that has
            // quietly stopped updating.
            if (error.name !== 'AbortError') {
                form.submit();
            }
        })
        .finally(() => {
            results.classList.remove('opacity-50');
        });
}

function schedule(form) {
    const own = stateFor(form);

    clearTimeout(own.timer);
    own.timer = setTimeout(() => render(form), DEBOUNCE_MS);
}

/** The form a live-search event belongs to, or null if it is not one of ours. */
function liveFormFor(target) {
    if (! (target instanceof Element)) {
        return null;
    }

    const form = target.closest('form[data-live-search]');

    return form && resultsFor(form) ? form : null;
}

document.addEventListener('input', (event) => {
    const target = event.target;

    // Text only. Selects, dates and checkboxes still wait for the Filter
    // button, which is the behaviour every one of these screens already had —
    // this adds typing, it does not take anything away.
    if (! (target instanceof HTMLInputElement)) {
        return;
    }

    if (target.type !== 'search' && target.type !== 'text') {
        return;
    }

    const form = liveFormFor(target);

    if (form) {
        schedule(form);
    }
});

// A search input's native clear button fires `search` rather than `input` in
// some browsers, and clearing the box is exactly when the full list should
// come back.
document.addEventListener('search', (event) => {
    const form = liveFormFor(event.target);

    if (form) {
        schedule(form);
    }
});

// Pagination lives inside the swapped markup, so it is delegated too:
// following the link normally would reload the page and lose the caret.
document.addEventListener('click', (event) => {
    const link = event.target instanceof Element
        ? event.target.closest('[data-pagination] a[href]')
        : null;

    if (! link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return;
    }

    const container = link.closest('[id]');
    const form = container
        ? document.querySelector(`form[data-live-search="#${CSS.escape(container.id)}"]`)
        : null;

    if (! form) {
        return;
    }

    event.preventDefault();
    clearTimeout(stateFor(form).timer);
    render(form, link.href);
});
