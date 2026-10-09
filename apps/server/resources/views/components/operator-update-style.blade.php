<style>
    .update-release-pair { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .update-review-section > p, .update-review-section > [data-update-controls], .update-review-section > noscript { padding-inline: var(--wf-space-5); }
    [data-update-review] { padding: var(--wf-space-4) var(--wf-space-5); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--wf-space-4) var(--wf-space-5); }
    [data-update-review] > .update-facts, [data-update-review] > details, [data-update-review] > p { grid-column: 1 / -1; }
    [data-update-review] h3 { font-size: var(--wf-text-ui); margin: 0 0 var(--wf-space-2); }
    [data-update-review] p { margin: 0 0 var(--wf-space-2); font-size: var(--wf-text-ui); }
    [data-update-review] .update-facts { display: flex; gap: var(--wf-space-5); margin: 0; }
    .update-review-section > [data-update-open] { margin: var(--wf-space-4) var(--wf-space-5); }
    .update-release-pair .meta-value, .update-identity { font-family: var(--wf-font-mono); overflow-wrap: anywhere; }
    .update-actions { display: flex; flex-wrap: wrap; gap: var(--wf-space-3); margin-block: var(--wf-space-4); }
    .update-auth { border-top: var(--wf-border) solid var(--wf-rule); padding-top: var(--wf-space-4); margin-top: var(--wf-space-5); }
    .update-confirmation { display: flex; align-items: flex-start; gap: var(--wf-space-2); }
    .update-confirmation input { width: auto; margin-top: var(--wf-space-1); flex: 0 0 auto; }
    .update-dialog { width: min(760px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); }
    .update-dialog-body { overflow-y: auto; padding: var(--wf-space-5); }
    .update-stages { list-style: none; padding: 0; margin: 0 0 var(--wf-space-5); display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: var(--wf-space-2); }
    .update-stages li { color: var(--wf-muted); border-top: 2px solid var(--wf-rule); padding-top: var(--wf-space-3); font-size: var(--wf-text-label); }
    .update-stages li[data-state="current"] { color: var(--wf-ink); border-color: var(--wf-brand); font-weight: 600; }
    .update-stages li[data-state="confirmed"] { border-color: var(--wf-signal-go); }
    .update-stage-number { display: block; font-family: var(--wf-font-mono); margin-bottom: var(--wf-space-2); }
    .update-clock { display: flex; flex-wrap: wrap; gap: var(--wf-space-5); padding-block: var(--wf-space-3); border-block: var(--wf-border) solid var(--wf-rule); }
    .update-clock dt { color: var(--wf-muted); font-size: var(--wf-text-label); }
    .update-clock dd { margin: var(--wf-space-1) 0 0; font-variant-numeric: tabular-nums; font-size: var(--wf-text-ui); }
    .update-facts div { margin-bottom: var(--wf-space-3); }
    .update-facts dt { color: var(--wf-muted); font-size: var(--wf-text-label); }
    .update-facts dd { margin: var(--wf-space-1) 0 0; overflow-wrap: anywhere; font-family: var(--wf-font-mono); }
    .update-events { padding-left: var(--wf-space-5); font-size: var(--wf-text-ui); }
    .update-events li { margin-block: var(--wf-space-2); }
    .update-review-list { padding-left: var(--wf-space-5); }
    .update-review-list li { margin-block: var(--wf-space-3); }
    [data-update-outcome][data-outcome="succeeded"] { color: var(--wf-signal-go); }
    [data-update-outcome][data-outcome="recovery_required"] { color: var(--wf-signal-stop); }
    [data-operator-updates] [hidden] { display: none !important; }
    @media (max-width: 600px) {
        [data-update-review] { grid-template-columns: 1fr; padding-inline: var(--wf-space-4); }
        .update-review-section > p, .update-review-section > [data-update-controls] { padding-inline: var(--wf-space-4); }
        .update-stages { grid-template-columns: repeat(3, minmax(0, 1fr)); row-gap: var(--wf-space-4); }
        .update-dialog-body { padding: var(--wf-space-4); }
        .update-release-pair { grid-template-columns: 1fr; }
    }
    @media (prefers-reduced-motion: reduce) {
        .update-dialog *, [data-operator-updates] * { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
    }
</style>
