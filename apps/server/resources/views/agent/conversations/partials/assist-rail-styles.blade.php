<style>
    .conversation-assist-workspace {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(272px, 320px);
        gap: 16px;
        align-items: start;
    }
    .conversation-assist-workspace:has([data-assist-rail]:not([open])) {
        grid-template-columns: minmax(0, 1fr) 176px;
    }
    .conversation-assist-main { min-width: 0; }
    [data-assist-rail][data-initialized="true"] [data-assist-card-position] { display: none; }
    .conversation-assist-main > .section:first-child { margin-top: 0; }
    .conversation-assist-main .reply-workspace { grid-template-columns: minmax(0, 1fr); }
    .conversation-assist-main .reply-workspace > * + * {
        border-left: 0;
        border-top: var(--wf-border) solid var(--border);
    }
    .conversation-assist-rail {
        min-width: 0;
        position: sticky;
        top: 16px;
        border: var(--wf-border) solid var(--border);
        background: var(--surface);
        font-size: .875rem;
    }
    .conversation-assist-toggle {
        padding: 16px;
        cursor: pointer;
    }
    .conversation-assist-toggle > .lede { display: block; margin-top: 4px; }
    .conversation-assist-rail :is(a,button,summary,[tabindex]):focus-visible { outline: 2px solid var(--text); outline-offset: 3px; }
    .conversation-assist-content { max-height: 72dvh; overflow-y: auto; overscroll-behavior: contain; }
    .conversation-assist-section, .conversation-assist-actions, .conversation-assist-foot {
        border-top: var(--wf-border) solid var(--border);
        padding: 16px;
    }
    .conversation-assist-section h2, .conversation-assist-section h3 {
        margin: 0 0 8px;
        font-size: .9375rem;
    }
    .conversation-assist-section > .section, .conversation-assist-section > .reply-template-preview {
        margin: 0;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
    }
    .conversation-assist-section .section-header { padding: 0; border: 0; }
    .conversation-assist-rail .button { border-radius: 0; }
    .conversation-assist-rail [data-assist-section="reply"] [data-ai-use],
    .conversation-assist-rail [data-assist-section="reply"] [data-copilot-reply-draft-use] {
        background: var(--text);
        color: var(--surface);
        border-color: var(--text);
    }
    .conversation-assist-rail .readiness-status[data-status="manual"] {
        font-size: .75rem;
        padding: 0;
        border-color: transparent;
        background: transparent;
        color: var(--muted);
    }
    .assist-draft-review pre { white-space: pre-wrap; overflow-wrap: anywhere; font: inherit; max-height: 200px; overflow: auto; padding: 8px; border: var(--wf-border) solid var(--border); }
    .assist-draft-review h4 { margin: 12px 0 4px; font-size: .875rem; }
    .assist-draft-review [data-assist-review-apply] { background: var(--text); color: var(--surface); border-color: var(--text); }
    .assist-freshness { font-size: .75rem; color: var(--muted); }
    .assist-freshness a { display: block; }
    @media (prefers-reduced-motion: reduce) { .conversation-assist-rail *, .conversation-assist-rail *::before, .conversation-assist-rail *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; } }
    .assist-task-data-use { margin-top: 12px; }
    .assist-task-data-use > summary { cursor: pointer; color: var(--muted); }
    .conversation-assist-foot p { margin: 0; }
    .conversation-assist-foot p + p { margin-top: 8px; }
    @media (max-width: 1100px) {
        .conversation-assist-workspace, .conversation-assist-workspace:has([data-assist-rail]:not([open])) {
            grid-template-columns: minmax(0, 1fr);
        }
        .conversation-assist-rail { position: static; grid-row: 1; }
        .conversation-assist-content { max-height: 55dvh; }
    }
</style>
