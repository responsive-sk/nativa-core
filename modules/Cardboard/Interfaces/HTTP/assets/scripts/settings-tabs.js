(function() {
    var tabs = document.getElementById('settings-tabs');
    if (!tabs) return;
    var btns = tabs.querySelectorAll('.m-tabs__btn');
    var panels = tabs.querySelectorAll('.m-tab-panel');

    function activate(btn) {
        var target = btn.getAttribute('data-tab');
        if (!target) return false;

        btns.forEach(function(b) { b.classList.remove('m-tabs__btn--active'); });
        btn.classList.add('m-tabs__btn--active');
        panels.forEach(function(p) {
            p.style.display = p.getAttribute('data-tab-panel') === target ? 'block' : 'none';
        });

        return true;
    }

    // Deep link: /mark/settings?tab=design opens that panel. The links in the
    // bar carry the same href, so they work with JS disabled too — and the
    // module list page (a separate page) can point at a specific panel.
    var requested = new URLSearchParams(window.location.search).get('tab');
    var initial = requested
        ? tabs.querySelector('.m-tabs__btn[data-tab="' + requested + '"]')
        : null;

    if (!initial || !activate(initial)) {
        var activeBtn = tabs.querySelector('.m-tabs__btn--active');
        if (activeBtn) {
            activate(activeBtn);
        }
    }

    btns.forEach(function(btn) {
        btn.addEventListener('click', function(event) {
            // Modules is a real page: let the anchor navigate.
            if (!this.getAttribute('data-tab')) return;

            if (activate(this)) {
                event.preventDefault();
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '?tab=' + this.getAttribute('data-tab'));
                }
            }
        });
    });
})();
