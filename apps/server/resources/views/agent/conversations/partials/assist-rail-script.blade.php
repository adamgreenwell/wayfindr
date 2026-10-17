<script>
(() => {
    document.querySelectorAll('[data-assist-rail]').forEach(rail => {
        if (rail.dataset.initialized === 'true') return;
        rail.dataset.initialized = 'true';
        rail.querySelectorAll('[data-assist-section]').forEach(section => {
            const resting = section.querySelector('[data-assist-resting]');
            if (!resting) return;
            const update = () => {
                resting.hidden = Array.from(section.children).some(child => child.matches(
                    '[data-ai-assist], [data-copilot-summary], [data-copilot-reply-draft], [data-copilot-knowledge-suggestion]'
                ));
            };
            update();
            // Observe only card replacement/removal, not generated output edits.
            new MutationObserver(update).observe(section, {childList:true});
        });
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
