
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
            /* Sidebar collapse toggle */
            var sb = document.getElementById('sidebar-toggle');
            var sidebar = document.getElementById('layout-sidebar');
            var page = document.getElementById('layout-page');
            var overlay = document.getElementById('sidebar-overlay');
            if (sb && sidebar) {
                sb.addEventListener('click', function() {
                    var collapsed = sidebar.classList.toggle('layout-sidebar--collapsed');
                    if (page) page.classList.toggle('layout-page--collapsed', collapsed);
                    try { localStorage.setItem('mark-sidebar', collapsed ? 'collapsed' : 'open'); } catch (e) {}
                });
                /* Restore persisted state */
                try {
                    if (localStorage.getItem('mark-sidebar') === 'collapsed') {
                        sidebar.classList.add('layout-sidebar--collapsed');
                        if (page) page.classList.add('layout-page--collapsed');
                    }
                } catch (e) {}
            }
            /* Mobile hamburger — open/close sidebar */
            var hamburger = document.getElementById('sidebar-hamburger');
            function openMobileSidebar() {
                if (sidebar) sidebar.classList.add('layout-sidebar--open');
                if (overlay) overlay.classList.add('sidebar-overlay--visible');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'true');
            }
            function closeMobileSidebar() {
                if (sidebar) sidebar.classList.remove('layout-sidebar--open');
                if (overlay) overlay.classList.remove('sidebar-overlay--visible');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
            }
            if (hamburger) {
                hamburger.addEventListener('click', function() {
                    if (sidebar && sidebar.classList.contains('layout-sidebar--open')) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                });
            }
            /* Mobile overlay — click closes sidebar */
            if (overlay) {
                overlay.addEventListener('click', function() {
                    closeMobileSidebar();
                });
            }
            /* Escape key closes sidebar on mobile */
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar && sidebar.classList.contains('layout-sidebar--open')) {
                    closeMobileSidebar();
                }
            });
            /* Sidebar group expand/collapse (toggle groups like MoonShine MenuGroup) */
            (function() {
                function initGroupToggles() {
                    document.querySelectorAll('[data-toggle-group]').forEach(function(btn) {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            var groupId = btn.getAttribute('data-toggle-group');
                            var group = btn.closest('.layout-sidebar__group');
                            if (!group) return;
                            var isExpanded = group.classList.toggle('is-expanded');
                            btn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                            try {
                                var expanded = JSON.parse(localStorage.getItem('mark-sidebar-groups') || '{}');
                                if (isExpanded) {
                                    expanded[groupId] = true;
                                } else {
                                    delete expanded[groupId];
                                }
                                localStorage.setItem('mark-sidebar-groups', JSON.stringify(expanded));
                            } catch (e) {}
                        });
                    });
                }
                initGroupToggles();
                document.body.addEventListener('htmx:afterSwap', function() {
                    initGroupToggles();
                });
            })();
            /* Bulk actions — select all + count */
            (function() {
                function updateBulkBar() {
                    var checks = document.querySelectorAll('.m-bulk__check:not(.m-bulk__select-all)');
                    var selectAll = document.querySelector('.m-bulk__select-all');
                    var bar = document.getElementById('bulk-bar');
                    var countEl = document.getElementById('bulk-count');
                    var checked = 0;
                    for (var i = 0; i < checks.length; i++) {
                        if (checks[i].checked) checked++;
                    }
                    if (selectAll) selectAll.checked = checks.length > 0 && checked === checks.length;
                    if (countEl) countEl.textContent = checked + ' selected';
                    if (bar) {
                        if (checked > 0) {
                            bar.classList.add('is-visible');
                        } else {
                            bar.classList.remove('is-visible');
                        }
                    }
                }
                /* Delegated on document — the select-all checkbox lives inside
                   the swapped #articles-content div, so a direct binding
                   would die on the first HTMX page/search change and the
                   bulk bar would stop working from page 2 on. */
                document.addEventListener('change', function(e) {
                    if (!e.target || !e.target.classList || !e.target.classList.contains('m-bulk__check')) {
                        return;
                    }
                    if (e.target.classList.contains('m-bulk__select-all')) {
                        var all = document.querySelectorAll('.m-bulk__check:not(.m-bulk__select-all)');
                        for (var k = 0; k < all.length; k++) {
                            all[k].checked = e.target.checked;
                        }
                    }
                    updateBulkBar();
                });
                /* Re-apply after HTMX swap */
                document.body.addEventListener('htmx:afterSwap', function() {
                    updateBulkBar();
                });
            })();
            /* Form validation — highlight required fields on submit */
            document.addEventListener('submit', function(e) {
                var form = e.target;
                if (!form || form.tagName !== 'FORM') return;
                var invalid = [];
                var fields = form.querySelectorAll('[required]');
                for (var i = 0; i < fields.length; i++) {
                    var f = fields[i];
                    var group = f.closest('.m-field');
                    if (!f.value || !f.value.trim()) {
                        if (group) group.classList.add('m-field--error');
                        invalid.push(f);
                    } else {
                        if (group) group.classList.remove('m-field--error');
                    }
                }
                if (invalid.length > 0) {
                    e.preventDefault();
                    invalid[0].focus();
                }
            }, true);
            /* Bulk actions — inject checked row ids into a bulk-bar form
               before it submits (the checkboxes live in the table,
               outside the bar's forms, so neither native serialization
               nor htmx value collection would include them). */
            window.mBulkInject = function(form) {
                if (!form) return;
                var olds = form.querySelectorAll('input[data-bulk-id]');
                for (var i = 0; i < olds.length; i++) olds[i].parentNode.removeChild(olds[i]);
                var checks = document.querySelectorAll('.m-bulk__check:checked');
                for (var j = 0; j < checks.length; j++) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'ids[]';
                    inp.setAttribute('data-bulk-id', '1');
                    inp.value = checks[j].value;
                    form.appendChild(inp);
                }
            };
            /* Capture-phase submit guard: bulk-bar forms always carry
               the selected ids, even without the confirm dialog. */
            document.addEventListener('submit', function(e) {
                var t = e.target;
                if (t && t.closest && t.closest('#bulk-bar') && typeof window.mBulkInject === 'function') {
                    window.mBulkInject(t);
                }
            }, true);
            /* Confirm dialog modal (replaces native confirm()) */
            (function() {
                var dlg = document.getElementById('m-dialog');
                var titleEl = document.getElementById('m-dialog-title');
                var cancelBtn = document.getElementById('m-dialog-cancel');
                var confirmBtn = document.getElementById('m-dialog-confirm');
                if (!dlg || !cancelBtn || !confirmBtn) return;
                var pendingTarget = null;
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
                /* data-confirm buttons — but never forms: a form carries its
                   own question to the submit delegation below, and a click
                   that opens the dialog AND a submit that asks natively is
                   two questions for one delete. */
                document.querySelectorAll('[data-confirm]').forEach(function(btn) {
                    if ('FORM' === btn.tagName) { return; }
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        var form = btn.closest('form');
                        if (form && typeof window.mBulkInject === 'function') {
                            window.mBulkInject(form);
                        }
                        openDialog(btn.getAttribute('data-confirm'), function() {
                            if (!form) { btn.click(); return; }
                            /* requestSubmit fires submit listeners (htmx,
                               validation); form.submit() bypasses them and
                               is only the legacy fallback. */
                            if (typeof form.requestSubmit === 'function') {
                                form.requestSubmit(btn);
                            } else {
                                form.submit();
                            }
                        });
                    });
                });
                /* data-confirm forms — exactly one native question per submit,
                   delegated from the document so HTMX-swapped rows keep asking
                   (a handler bound at render time dies with the swapped node)
                   and so the question never depends on HTMX being loaded:
                   dist/htmx.min.js is opt-in and a row delete confirmed only
                   through HTMX submits natively with no question at all
                   without it. */
                document.addEventListener('submit', function(e) {
                    var form = e.target;
                    if (!form || !form.getAttribute) { return; }
                    var message = form.getAttribute('data-confirm');
                    if (message && !window.confirm(message)) { e.preventDefault(); }
                });
                /* hx-confirm — intercept HTMX confirm event */
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
                