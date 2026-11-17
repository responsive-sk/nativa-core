
(function() {
    try {
        var theme = localStorage.getItem('nativa-theme');
        if (!theme) {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {}
})();
