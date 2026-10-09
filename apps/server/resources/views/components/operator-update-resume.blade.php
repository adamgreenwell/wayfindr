<p class="notice-copy" data-update-resume hidden>
    {{ __('operator_updates.connection.background_notice') }}
    <a href="{{ route('operator.updates.index') }}">{{ __('operator_updates.actions.open_progress') }}</a>
</p>
<script>
(function () {
    const banner = document.querySelector('[data-update-resume]');
    if (!banner) return;
    const actor = @json((int) auth()->id());
    const key = 'wayfindr:update:' + actor;
    const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    try {
        const saved = JSON.parse(localStorage.getItem(key));
        // A bookmark is only a link. No saved browser fact enables an action
        // or reports an outcome; the Updates page re-reads the exact host run.
        if (saved?.schema === 1 && (uuid.test(saved.operation_id || '') || saved.pending)) banner.hidden = false;
    } catch (error) { /* the host can still identify its active run */ }
    if (!@json(config('wayfindr.updates.helper_enabled') === true)) return;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    fetch(@json(route('operator.updates.status')), {
        headers: { Accept: 'application/json' }, credentials: 'same-origin',
        cache: 'no-store', redirect: 'error', signal: controller.signal,
    }).then(async (response) => {
        if (!response.ok) return;
        const data = await response.json();
        if (data.schema === 1 && uuid.test(data.snapshot?.active_operation || '')) banner.hidden = false;
    }).catch(() => { /* downtime leaves an existing bookmark intact */ })
      .finally(() => clearTimeout(timeout));
})();
</script>
