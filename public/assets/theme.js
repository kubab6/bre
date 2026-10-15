(function () {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;

    function effective() {
        const saved = document.documentElement.dataset.theme;
        if (saved === 'dark' || saved === 'light') return saved;
        return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function updateLabel() {
        btn.textContent = effective() === 'dark' ? 'Hell' : 'Dunkel';
    }

    updateLabel();

    btn.addEventListener('click', () => {
        const next = effective() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('theme', next); } catch (e) {}
        updateLabel();
    });
})();
