{{--
    Live search for the admin filter bars.

    Typing narrows the list as you go; the Filter button still does exactly
    what it always did. Both work, and neither knows about the other — the
    button is an ordinary form submit this script never touches.

    Only the results table is re-fetched, so the page never reloads and the
    caret stays where the admin left it. The server renders the same partial
    the page already includes, asked for with `partial=1`, so there is one
    definition of a row rather than a second one in JavaScript that would
    drift.

    Inline rather than in the Vite bundle, deliberately. `public/build` is
    gitignored, so a bundled script only reaches a machine that has run
    `npm run build` since the pull — and until it does, typing does nothing
    and the box appears to need Enter. Attendance and the students screen
    already carry their behaviour inline for the same reason. The cost is a
    few unminified KB on each admin page; the benefit is that pulling the
    code is enough.

    Driven by markup, so a screen opts in without touching this file:

        <form data-live-search="#notes-results"> … </form>
        <div id="notes-results"> @include('admin.notes._results') </div>

    Everything is delegated from `document`, so a container that has just been
    replaced needs no rebinding — which is the whole reason the pagination
    links inside it keep working after the first keystroke.
--}}
<script>
    (() => {
        /** How long to wait after the last keystroke, in ms. */
        const DEBOUNCE = 220;

        /** Per-form state: the pending timer and the request in flight. */
        const state = new WeakMap();

        const stateFor = (form) => {
            if (! state.has(form)) state.set(form, { timer: null, inflight: null });
            return state.get(form);
        };

        const resultsFor = (form) => {
            const selector = form.getAttribute('data-live-search');
            return selector ? document.querySelector(selector) : null;
        };

        /**
         * The URL this form's current values describe.
         *
         * Empty fields are left out so the address bar stays readable, and the
         * page number is dropped: narrowing a search puts you back on page one,
         * and keeping the old number is how a search lands on an empty page.
         */
        const urlFor = (form) => {
            // getAttribute, not form.action: a named control shadows the form
            // property of the same name, and the audit log filters on a field
            // called `action`. form.action there returns the <select>, and the
            // URL built from it 404s.
            const url = new URL(form.getAttribute('action') || window.location.href, window.location.origin);
            const params = new URLSearchParams();

            for (const [key, value] of new FormData(form)) {
                // `page` in any of its spellings — a paginator can be given its
                // own name, like `articles_page`.
                if (key === 'partial' || key === 'page' || key.endsWith('_page')) continue;
                if (typeof value === 'string' && value.trim() !== '') params.append(key, value);
            }

            url.search = params.toString();
            return url;
        };

        const render = (form, pageUrl = null) => {
            const results = resultsFor(form);
            if (! results) return;

            const own = stateFor(form);
            const target = pageUrl ? new URL(pageUrl, window.location.origin) : urlFor(form);
            const fetchUrl = new URL(target);
            fetchUrl.searchParams.set('partial', '1');

            if (own.inflight) own.inflight.abort();
            own.inflight = new AbortController();
            results.classList.add('opacity-50');

            fetch(fetchUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: own.inflight.signal,
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (! response.ok) throw new Error(String(response.status));
                    return response.text();
                })
                .then((html) => {
                    results.innerHTML = html;
                    // replaceState, not pushState: a keystroke is not a page the
                    // back button should have to walk through. The URL still
                    // holds the search, so a reload or a copied link shows the
                    // same list.
                    window.history.replaceState(null, '', target);
                })
                .catch((error) => {
                    // A superseded keystroke is not a failure. Anything else — a
                    // lost connection, an expired session, a 500 — falls back to
                    // a normal page load, which shows the real error instead of
                    // a list that has quietly stopped updating.
                    if (error.name !== 'AbortError') form.submit();
                })
                .finally(() => results.classList.remove('opacity-50'));
        };

        const schedule = (form) => {
            const own = stateFor(form);
            // Dimmed from the keystroke, not from the fetch: the wait below is
            // long enough to read as nothing happening, and an admin who thinks
            // nothing happened reaches for the button.
            resultsFor(form)?.classList.add('opacity-50');
            clearTimeout(own.timer);
            own.timer = setTimeout(() => render(form), DEBOUNCE);
        };

        /** The form a live-search event belongs to, or null if it is not one of ours. */
        const liveFormFor = (target) => {
            if (! (target instanceof Element)) return null;
            const form = target.closest('form[data-live-search]');
            return form && resultsFor(form) ? form : null;
        };

        document.addEventListener('input', (event) => {
            const target = event.target;
            // Text only. Dropdowns, dates and checkboxes still wait for the
            // Filter button, which is what every one of these screens already
            // did — this adds typing, it takes nothing away.
            if (! (target instanceof HTMLInputElement)) return;
            if (target.type !== 'search' && target.type !== 'text') return;

            const form = liveFormFor(target);
            if (form) schedule(form);
        });

        // A search input's native clear button fires `search` rather than
        // `input` in some browsers, and clearing the box is exactly when the
        // full list should come back.
        document.addEventListener('search', (event) => {
            const form = liveFormFor(event.target);
            if (form) schedule(form);
        });

        // Pagination lives inside the swapped markup, so it is delegated too:
        // following the link normally would reload the page and lose the caret.
        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element
                ? event.target.closest('[data-pagination] a[href]')
                : null;
            if (! link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

            const container = link.closest('[id]');
            const form = container
                ? document.querySelector(`form[data-live-search="#${CSS.escape(container.id)}"]`)
                : null;
            if (! form) return;

            event.preventDefault();
            clearTimeout(stateFor(form).timer);
            render(form, link.href);
        });
    })();
</script>
