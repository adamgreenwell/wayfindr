<script>
(() => {
    document.querySelectorAll('[data-assist-rail]').forEach(rail => {
        if (rail.dataset.initialized === 'true') return;
        rail.dataset.initialized = 'true';
        const key = rail.dataset.preferenceKey;
        let saved = null;
        try { saved = window.localStorage.getItem(key); } catch (error) { /* Browser storage is optional. */ }
        rail.open = saved === 'open' || (saved !== 'closed' && window.matchMedia('(min-width: 1101px)').matches);
        let lastOpen = rail.open;
        rail.addEventListener('toggle', () => {
            if (rail.open === lastOpen) return;
            lastOpen = rail.open;
            try { window.localStorage.setItem(key, rail.open ? 'open' : 'closed'); } catch (error) { /* Native disclosure stays usable. */ }
        });
    });
})();
</script>
