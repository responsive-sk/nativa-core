
        (function() {
            /* Theme toggle */
            var btn = document.querySelector('[data-theme-toggle]');
            if (btn) {
                btn.addEventListener('click', function() {
                    var html = document.documentElement;
                    var next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                    html.setAttribute('data-theme', next);
                    try { localStorage.setItem('nativa-theme', next); } catch (e) {}
                });
            }
            /* Mobile hamburger */
            var burger = document.getElementById('m-topnav-burger');
            var links = document.getElementById('m-topnav-links');
            if (burger && links) {
                burger.addEventListener('click', function() {
                    links.classList.toggle('open');
                });
            }
            /* Compact nav group dropdown toggle */
            document.querySelectorAll('[data-compact-group]').forEach(function(group) {
                var btn = group.querySelector('.m-nav__link--group');
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var isActive = group.classList.toggle('m-nav__group--active');
                        btn.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                        // Close other open groups.
                        document.querySelectorAll('.m-nav__group--active').forEach(function(other) {
                            if (other !== group) {
                                other.classList.remove('m-nav__group--active');
                                var otherBtn = other.querySelector('.m-nav__link--group');
                                if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
                            }
                        });
                        if (isActive) group.classList.add('m-nav__group--active');
                    });
                }
            });
            /* Close dropdowns when clicking outside */
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.m-nav__group')) {
                    document.querySelectorAll('.m-nav__group--active').forEach(function(g) {
                        g.classList.remove('m-nav__group--active');
                    });
                }
            });
            /* CSP-safe confirm dialogs */
            (function() {
                var dlg = document.getElementById('m-dialog');
                var titleEl = document.getElementById('m-dialog-title');
                var cancelBtn = document.getElementById('m-dialog-cancel');
                var confirmBtn = document.getElementById('m-dialog-confirm');
                if (!dlg || !cancelBtn || !confirmBtn) return;
                var pendingCallback = null;
                function openDialog(title, callback) {
                    titleEl.textContent = title;
                    pendingCallback = callback;
                    dlg.showModal();
                }
                cancelBtn.addEventListener('click', function() {
                    dlg.close();
                    pendingCallback = null;
                });
                confirmBtn.addEventListener('click', function() {
                    dlg.close();
                    if (pendingCallback) pendingCallback();
                    pendingCallback = null;
                });
                document.querySelectorAll('[data-confirm]').forEach(function(btn) {
                    if ('FORM' === btn.tagName) { return; }
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        var form = btn.closest('form');
                        openDialog(btn.getAttribute('data-confirm'), function() {
                            if (form) { form.submit(); } else { btn.click(); }
                        });
                    });
                });
                document.addEventListener('submit', function(e) {
                    var form = e.target;
                    if (!form || !form.getAttribute) { return; }
                    var message = form.getAttribute('data-confirm');
                    if (message && !window.confirm(message)) { e.preventDefault(); }
                });
                document.body.addEventListener('htmx:confirm', function(e) {
                    if (e.detail && e.detail.question) {
                        e.preventDefault();
                        openDialog(e.detail.question, function() {
                            e.detail.issueRequest();
                        });
                    }
                });
            })();
            /* Toast notifications */
            window.mToast = function(msg, type) {
                var el = document.createElement('div');
                el.className = 'm-toast m-toast--' + (type || 'ok');
                el.textContent = msg;
                document.body.appendChild(el);
                setTimeout(function() { el.remove(); }, 3000);
            };
            /* Copy to clipboard */
            document.querySelectorAll('[data-copy]').forEach(function(el) {
                el.addEventListener('click', function() {
                    var text = el.getAttribute('data-copy');
                    if (!text) return;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            el.classList.add('copied');
                            window.mToast('Copied!', 'ok');
                            setTimeout(function() { el.classList.remove('copied'); }, 1500);
                        });
                    }
                });
            });
            /* Command palette (Ctrl+K / Cmd+K) */
            (function() {
                var overlay = document.getElementById('m-palette-overlay');
                var input = document.getElementById('m-palette-input');
                var list = document.getElementById('m-palette-list');
                if (!overlay || !input || !list) return;
                var commands = [
                    { label: 'Dashboard', href: '/mark', icon: '📊' },
                    { label: 'Users', href: '/mark/users', icon: '👥' },
                    { label: 'Roles', href: '/mark/roles', icon: '🛡️' },
                    { label: 'Settings', href: '/mark/settings', icon: '⚙️' },
                    { label: 'Locales', href: '/mark/locales', icon: '🌐' },
                    { label: 'Media', href: '/mark/media', icon: '🖼️' },
                    { label: 'Pages', href: '/mark/pages', icon: '📄' },
                    { label: 'Blog', href: '/mark/articles', icon: '📰' },
                    { label: 'Articles', href: '/mark/articles', icon: '📝' },
                    { label: 'Categories', href: '/mark/categories', icon: '📂' },
                    { label: 'Tags', href: '/mark/tags', icon: '🏷️' },
                    { label: 'Comments', href: '/mark/comments', icon: '💬' },
                    { label: 'Audit', href: '/mark/audit', icon: '📋' },
                    { label: 'Logout', href: '/logout', icon: '🚪' }
                ];
                