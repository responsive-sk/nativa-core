                for (var r = 0; r < recent.length; r++) {
                    commands.unshift({ label: recent[r].label, href: recent[r].href, icon: recent[r].icon });
                }
                function openPalette() {
                    overlay.classList.add('open');
                    input.value = '';
                    filterCommands('');
                    setTimeout(function() { input.focus(); }, 50);
                }
                function closePalette() {
                    overlay.classList.remove('open');
                    input.value = '';
                }
                function filterCommands(q) {
                    var lower = q.toLowerCase();
                    var html = '';
                    var recentCount = recent.length;
                    if ('' === lower && recentCount > 0) {
                        html += '<div class="m-palette-group">Recent</div>';
                        for (var r = 0; r < recentCount; r++) {
                            html += '<a class="m-palette-item" href="' + recent[r].href + '">'
                                + '<span class="m-palette-item__icon">' + recent[r].icon + '</span>'
                                + '<span class="m-palette-item__label">' + recent[r].label + '</span>'
                                + '</a>';
                        }
                        html += '<div class="m-palette-group">Navigation</div>';
                    }
                    for (var i = 0; i < commands.length; i++) {
                        var c = commands[i];
                        if ('' === lower || c.label.toLowerCase().indexOf(lower) !== -1) {
                            html += '<a class="m-palette-item" href="' + c.href + '">'
                                + '<span class="m-palette-item__icon">' + c.icon + '</span>'
                                + '<span class="m-palette-item__label">' + c.label + '</span>'
                                + '</a>';
                        }
                    }
                    list.innerHTML = html || '<div class="m-palette-empty">No matches</div>';
                }
                document.addEventListener('keydown', function(e) {
                    var isMac = navigator.platform.toUpperCase().indexOf('MAC') !== -1;
                    var mod = isMac ? e.metaKey : e.ctrlKey;
                    if (mod && 'k' === e.key) {
                        e.preventDefault();
                        if (overlay.classList.contains('open')) { closePalette(); } else { openPalette(); }
                        return;
                    }
                    if (e.key === 'Escape' && overlay.classList.contains('open')) {
                        closePalette();
                        e.preventDefault();
                        return;
                    }
                });
                input.addEventListener('input', function() {
                    filterCommands(input.value);
                });
                input.addEventListener('keydown', function(e) {
                    var items = list.querySelectorAll('.m-palette-item');
                    var active = list.querySelector('.m-palette-item.active');
                    var idx = -1;
                    for (var i = 0; i < items.length; i++) {
                        if (items[i] === active) { idx = i; break; }
                    }
                    if ('ArrowDown' === e.key) {
                        e.preventDefault();
                        if (active) active.classList.remove('active');
                        var next = idx < items.length - 1 ? idx + 1 : 0;
                        if (items[next]) items[next].classList.add('active');
                    } else if ('ArrowUp' === e.key) {
                        e.preventDefault();
                        if (active) active.classList.remove('active');
                        var prev = idx > 0 ? idx - 1 : items.length - 1;
                        if (items[prev]) items[prev].classList.add('active');
                    } else if ('Enter' === e.key && active) {
                        e.preventDefault();
                        window.location.href = active.getAttribute('href');
                    }
                });
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) closePalette();
                });
            })();
            /* Mobile table scroll shadow */
            (function() {
                function initScrollShadows() {
                    document.querySelectorAll('.m-table-scroll').forEach(function(el) {
                        el.classList.add('m-table-scroll--shadow');
                        function check() {
                            var atEnd = el.scrollWidth - el.scrollLeft <= el.clientWidth + 2;
                            el.classList.toggle('is-scrollable', el.scrollWidth > el.clientWidth);
                            el.classList.toggle('is-scrolled-end', atEnd);
                        }
                        el.addEventListener('scroll', check);
                        check();
                    });
                }
                initScrollShadows();
                document.body.addEventListener('htmx:afterSwap', function() {
                    initScrollShadows();
                });
            })();
        })();
    