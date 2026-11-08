/**
 * Shared theme toggle handler — one copy inlined by FrontendLayout for
 * every public theme.
 *
 * Wires EVERY `[data-theme-toggle]` instance, not just the first: a theme
 * chrome may render the control twice (moonshine puts it in both the
 * sidebar foot and the topbar actions). A single-match handler left the
 * second control dead, and pairing it with a second per-theme handler
 * double-flipped the first one — light → dark → light, so the toggle
 * appeared inert.
 */
(function() {
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
        btns[i].addEventListener('click', function() {
            var html = document.documentElement;
            var next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', next);
            try { localStorage.setItem('nativa-theme', next); } catch (e) {}
        });
    }
})();