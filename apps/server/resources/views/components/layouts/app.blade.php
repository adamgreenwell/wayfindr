@props([
    'title' => config('app.name', 'Wayfindr'),
    // A document title that is the USER's words -- an article's title, a
    // conversation's subject -- is not the dashboard's language. Pass '' for
    // HTML's "unknown"; leave null when the title is our own copy.
    //
    // `lang` is a global attribute and applies to `<title>` like anything else.
    // Support in assistive technology is uneven, but the alternative is a
    // document title that positively claims the wrong language, which is worse
    // than one that declines to say.
    'titleLang' => null,
    'agent' => null,
    'account' => null,
    // A deeper crumb for surfaces that have their own sections, so the bar can
    // say "Operator > Backups" rather than stopping at the rail item.
    'crumb' => null,
])

<!DOCTYPE html>
{{-- The whole document's language.

     This was split for a while: the root stated the SHELL's language and
     `<main>` the page's, because the shell was English inside pages that were
     not. The shell is extracted now, so on a surface that has been extracted
     every word here is the agent's language, and on one that has not the locale
     is English and so is everything -- see DashboardLanguage::EXTRACTED_ROUTES.
     There is no longer a mixed document to describe, so there is one attribute
     again.

     The recorded exceptions still carry their own `lang`: they are English
     inside German pages by design, and they say so. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Built inline so a title with no language declaration renders exactly
         `<title>`, not `<title >`. Only the attribute is unescaped; its value
         is escaped on the way in. --}}
    <title{!! $titleLang !== null ? ' lang="'.e(str_replace('_', '-', $titleLang)).'"' : '' !!}>{{ $title }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" data-agent-alert-favicon>
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('wayfindr:theme');

                if (stored === 'light' || stored === 'dark') {
                    document.documentElement.setAttribute('data-wf-theme', stored);
                }
            } catch (error) {
                // Private mode and blocked storage both throw here. Falling
                // through leaves the OS preference in charge, which is the
                // right answer when we cannot remember a choice.
            }
        })();
    </script>
    <style>
        /* IBM Plex, served from this install (ADR 0014). Never a third-party
           request: Wayfindr runs on localhost, bare IPs and air-gapped networks,
           where a CDN font does not fail loudly -- it silently renders the system
           stack. Provenance and hashes: public/fonts/README.md.

           `swap` because an agent reading a queue must not wait on a typeface.
           These faces are declared but unused until the shell consumes
           --wf-font-*, and a declared-but-unused @font-face is never fetched. */
        @font-face {
            font-family: 'IBM Plex Sans';
            src: url('{{ asset('fonts/IBMPlexSans-Regular.woff2') }}') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'IBM Plex Sans';
            src: url('{{ asset('fonts/IBMPlexSans-Medium.woff2') }}') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'IBM Plex Sans';
            src: url('{{ asset('fonts/IBMPlexSans-SemiBold.woff2') }}') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'IBM Plex Sans Condensed';
            src: url('{{ asset('fonts/IBMPlexSansCondensed-SemiBold.woff2') }}') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'IBM Plex Mono';
            src: url('{{ asset('fonts/IBMPlexMono-Regular.woff2') }}') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'IBM Plex Mono';
            src: url('{{ asset('fonts/IBMPlexMono-Medium.woff2') }}') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }

        /* wayfindr:tokens:start */
        /* Generated from packages/design-tokens/tokens.json by scripts/generate-design-tokens.php. Do not edit by hand -- run `make design-tokens`. */
        :root {
            --wf-paper: #F1F1EE;
            --wf-surface: #FFFFFF;
            --wf-surface-2: #E9E9E4;
            --wf-ink: #16181A;
            --wf-ink-invert: var(--wf-brand-ink-configured,#F1F1EE);
            --wf-muted: #6A6E71;
            --wf-rule: #DCDCD6;
            --wf-rule-firm: #C4C4BD;
            --wf-brand: var(--wf-brand-configured,#0D6F68);
            --wf-signal-rest: #8C9194;
            --wf-signal-go: #1E7A4C;
            --wf-signal-hold: #C98A06;
            --wf-signal-stop: #C3352B;
            --wf-site-red: #C3352B;
            --wf-site-blue: #2D4EA2;
            --wf-site-ochre: #C98A06;
            --wf-site-pine: #1E7A4C;
            --wf-site-violet: #6B4E9B;
            --wf-site-rust: #B5542A;
            --wf-font-sans: "IBM Plex Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            --wf-font-cond: "IBM Plex Sans Condensed", "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif;
            --wf-font-mono: "IBM Plex Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            --wf-text-display: 2.05rem;
            --wf-text-title: 1.3rem;
            --wf-text-body: 0.97rem;
            --wf-text-ui: 0.875rem;
            --wf-text-label: 0.75rem;
            --wf-text-code: 0.86rem;
            --wf-space-1: 4px;
            --wf-space-2: 8px;
            --wf-space-3: 12px;
            --wf-space-4: 16px;
            --wf-space-5: 24px;
            --wf-space-6: 32px;
            --wf-space-7: 48px;
            --wf-radius: 2px;
            --wf-radius-full: 999px;
            --wf-border: 1px;
            --wf-rail: 3px;
            --wf-row-min: 34px;
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-wf-theme="light"]) {
                --wf-paper: #141517;
                --wf-surface: #1B1D20;
                --wf-surface-2: #24272A;
                --wf-ink: #ECECE8;
                --wf-ink-invert: var(--wf-brand-ink-configured-dark,#16181A);
                --wf-muted: #9BA0A3;
                --wf-rule: #2E3134;
                --wf-rule-firm: #3D4145;
                --wf-brand: var(--wf-brand-configured-dark,#3FA69D);
                --wf-signal-rest: #7E8386;
                --wf-signal-go: #4CA97A;
                --wf-signal-hold: #E0A72A;
                --wf-signal-stop: #E2685C;
                --wf-site-red: #D54C43;
                --wf-site-blue: #5578D0;
                --wf-site-ochre: #A57105;
                --wf-site-pine: #238C57;
                --wf-site-violet: #896EB6;
                --wf-site-rust: #C65C2E;
            }
        }

        :root[data-wf-theme="dark"] {
            --wf-paper: #141517;
            --wf-surface: #1B1D20;
            --wf-surface-2: #24272A;
            --wf-ink: #ECECE8;
            --wf-ink-invert: var(--wf-brand-ink-configured-dark,#16181A);
            --wf-muted: #9BA0A3;
            --wf-rule: #2E3134;
            --wf-rule-firm: #3D4145;
            --wf-brand: var(--wf-brand-configured-dark,#3FA69D);
            --wf-signal-rest: #7E8386;
            --wf-signal-go: #4CA97A;
            --wf-signal-hold: #E0A72A;
            --wf-signal-stop: #E2685C;
            --wf-site-red: #D54C43;
            --wf-site-blue: #5578D0;
            --wf-site-ochre: #A57105;
            --wf-site-pine: #238C57;
            --wf-site-violet: #896EB6;
            --wf-site-rust: #C65C2E;
        }
        /* wayfindr:tokens:end */
        /* The legacy palette, repointed at the tokens above (ADR 0014).
           The ~140 hand-rolled classes below still name --bg, --surface,
           --accent and so on. Rebinding them in one place moves the whole
           application onto the new palette and into dark mode without
           touching those classes, which the later steps rewrite anyway.

           --accent stays the brand hue here because the legacy components use
           it for tints, focus rings and selected states, where a hue still
           carries meaning. Primary BUTTONS move to ink below, which is the
           change that actually reads. */
        :root {
            /* Both, so "Auto" hands native controls to the OS. The explicit
               choices below pin it, or a dark-mode agent choosing Light keeps
               dark scrollbars and select popups. */
            color-scheme: light dark;
            --bg: var(--wf-paper);
            --surface: var(--wf-surface);
            --surface-muted: var(--wf-surface-2);
            --text: var(--wf-ink);
            --muted: var(--wf-muted);
            --border: var(--wf-rule);
            --accent: var(--wf-brand);
            --accent-strong: var(--wf-brand);
            --danger: var(--wf-signal-stop);
        }

        /* An explicit choice must pin the native controls too, or an agent on a
           dark OS who picks Light keeps dark scrollbars, checkboxes and select
           popups -- the exact case the toggle exists for. */
        :root[data-wf-theme="light"] {
            color-scheme: light;
        }

        :root[data-wf-theme="dark"] {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: var(--wf-font-sans);
            font-size: var(--wf-text-body);
            line-height: 1.5;
        }

        a {
            color: var(--accent-strong);
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        /* ── Application shell (ADR 0014) ────────────────────────────────
           A persistent rail and a fixed frame, rather than a centred document.
           The rail is zoned -- daily work at the top, configuration and
           identity pinned to the bottom -- so "Conversations" and "Operator"
           stop ranking equally, which is what nine identical pills did. */

        .wf-app {
            display: grid;
            grid-template-columns: 236px minmax(0, 1fr);
            min-height: 100vh;
        }

        .wf-rail {
            display: flex;
            flex-direction: column;
            gap: var(--wf-space-5);
            padding: var(--wf-space-4) 0;
            background: var(--wf-surface);
            border-right: var(--wf-border) solid var(--wf-rule);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .wf-mark {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 var(--wf-space-4);
            color: var(--wf-ink);
            text-decoration: none;
        }

        /* Three flat planes. The brand mark is the one place these hues appear
           without carrying data, and it is deliberately small. */
        .wf-mark-planes {
            display: flex;
            flex: none;
        }

        .wf-mark-planes i {
            display: block;
            width: 7px;
            height: 18px;
        }

        .wf-mark-planes i:nth-child(1) { background: var(--wf-site-red); }
        .wf-mark-planes i:nth-child(2) { background: var(--wf-site-ochre); }
        .wf-mark-planes i:nth-child(3) { background: var(--wf-brand); }

        .wf-mark-name {
            font-family: var(--wf-font-mono);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .wf-nav {
            display: flex;
            flex-direction: column;
            gap: 1px;
            padding: 0 var(--wf-space-2);
        }

        .wf-nav-heading {
            font-family: var(--wf-font-cond);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--wf-muted);
            padding: var(--wf-space-2) var(--wf-space-3) var(--wf-space-1);
            margin: 0;
        }

        .wf-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px var(--wf-space-3);
            border-radius: var(--wf-radius);
            color: var(--wf-muted);
            font-size: var(--wf-text-ui);
            font-weight: 500;
            text-decoration: none;
        }

        .wf-nav-link:hover {
            background: var(--wf-surface-2);
            color: var(--wf-ink);
        }

        .wf-nav-link[aria-current="page"] {
            background: var(--wf-surface-2);
            color: var(--wf-ink);
            font-weight: 600;
            box-shadow: inset var(--wf-rail) 0 0 var(--wf-brand);
        }

        .wf-nav-link:focus-visible,
        .wf-mark:focus-visible,
        .wf-identity:focus-visible {
            outline: 2px solid var(--wf-brand);
            outline-offset: -2px;
        }

        .wf-icon {
            display: block;
            flex: none;
        }

        .wf-rail-foot {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: var(--wf-space-3);
        }

        .wf-theme {
            display: flex;
            gap: 1px;
            margin: 0 var(--wf-space-3);
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            overflow: hidden;
        }

        .wf-theme button {
            flex: 1;
            appearance: none;
            border: 0;
            background: var(--wf-surface);
            color: var(--wf-muted);
            font-family: var(--wf-font-cond);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 5px 0;
            cursor: pointer;
        }

        .wf-theme button:hover {
            color: var(--wf-ink);
        }

        .wf-theme button[aria-pressed="true"] {
            background: var(--wf-ink);
            color: var(--wf-ink-invert);
        }

        .wf-identity {
            display: flex;
            flex-direction: column;
            gap: 1px;
            padding: var(--wf-space-2) var(--wf-space-3);
            margin: 0 var(--wf-space-2);
            border-radius: var(--wf-radius);
            text-decoration: none;
            min-width: 0;
        }

        .wf-identity:hover {
            background: var(--wf-surface-2);
        }

        .wf-identity-name {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--wf-ink);
        }

        .wf-identity-sub {
            font-size: 11px;
            color: var(--wf-muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wf-signout {
            margin: 0 var(--wf-space-2);
        }

        .wf-signout button {
            width: 100%;
            appearance: none;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            background: transparent;
            color: var(--wf-muted);
            font-size: 12px;
            font-weight: 500;
            padding: 6px 0;
            cursor: pointer;
        }

        .wf-signout button:hover {
            color: var(--wf-ink);
            border-color: var(--wf-rule-firm);
        }

        .wf-main {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Location lives here; navigation lives in the rail. Two jobs, two
           places -- the old topbar did both and did neither well. */
        .wf-topbar {
            display: flex;
            align-items: center;
            gap: var(--wf-space-4);
            min-height: 52px;
            padding: 0 var(--wf-space-5);
            background: var(--wf-surface);
            border-bottom: var(--wf-border) solid var(--wf-rule);
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .wf-crumbs {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
            font-size: var(--wf-text-ui);
            color: var(--wf-muted);
        }

        .wf-crumbs a {
            color: var(--wf-muted);
            text-decoration: none;
        }

        .wf-crumbs a:hover {
            color: var(--wf-ink);
        }

        .wf-crumb-current {
            color: var(--wf-ink);
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wf-topbar-search {
            margin-left: auto;
            display: flex;
            gap: var(--wf-space-2);
            align-items: center;
        }

        .wf-topbar-search input {
            width: 232px;
            max-width: 40vw;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            background: var(--wf-paper);
            color: var(--wf-ink);
            padding: 5px var(--wf-space-2);
            font-size: 12.5px;
        }

        .wf-topbar-search input:focus {
            outline: 2px solid var(--wf-brand);
            outline-offset: -1px;
        }

        .wf-topbar-search button {
            appearance: none;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            background: transparent;
            color: var(--wf-muted);
            font-size: 12.5px;
            font-weight: 500;
            padding: 5px var(--wf-space-3);
            cursor: pointer;
        }

        .wf-topbar-search button:hover {
            color: var(--wf-ink);
            border-color: var(--wf-rule-firm);
        }

        .wf-command-trigger,
        .wf-command-dismiss {
            appearance: none;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            background: transparent;
            color: var(--wf-muted);
            cursor: pointer;
        }

        .wf-command-trigger {
            display: inline-flex;
            align-items: center;
            gap: var(--wf-space-2);
            flex: none;
            padding: 5px var(--wf-space-2);
            font-size: 12.5px;
            font-weight: 500;
            white-space: nowrap;
        }

        .wf-command-trigger:hover,
        .wf-command-trigger:focus-visible,
        .wf-command-dismiss:hover,
        .wf-command-dismiss:focus-visible {
            color: var(--wf-ink);
            border-color: var(--wf-rule-firm);
        }

        .wf-command-trigger:focus-visible,
        .wf-command-dismiss:focus-visible {
            outline: 2px solid var(--wf-brand);
            outline-offset: 2px;
        }

        .wf-command-trigger kbd,
        .wf-command-item kbd,
        .wf-shortcut-row kbd {
            border: var(--wf-border) solid var(--wf-rule-firm);
            border-radius: 3px;
            background: var(--wf-surface-2);
            color: var(--wf-muted);
            padding: 1px 5px;
            font-family: var(--wf-font-mono);
            font-size: 10.5px;
            line-height: 1.5;
        }

        .wf-command-dialog {
            width: min(560px, calc(100vw - 32px));
            max-height: min(680px, calc(100vh - 32px));
            margin: auto;
            padding: 0;
            overflow: hidden;
            border: var(--wf-border) solid var(--wf-rule-firm);
            border-radius: calc(var(--wf-radius) * 2);
            background: var(--wf-surface);
            color: var(--wf-ink);
            box-shadow: 0 24px 80px rgba(19, 25, 25, 0.24);
        }

        .wf-command-dialog[open] {
            display: flex;
            flex-direction: column;
        }

        .wf-command-dialog::backdrop {
            background: rgba(19, 25, 25, 0.42);
        }

        .wf-command-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--wf-space-4);
            padding: var(--wf-space-4) var(--wf-space-5) var(--wf-space-3);
            border-bottom: var(--wf-border) solid var(--wf-rule);
        }

        .wf-command-header .meta-label {
            margin: 0 0 var(--wf-space-1);
        }

        .wf-command-header h2 {
            margin: 0;
            font-size: 1.2rem;
        }

        .wf-command-dismiss {
            width: 30px;
            height: 30px;
            flex: none;
            padding: 0;
            font-size: 20px;
            line-height: 1;
        }

        .wf-command-search {
            margin: 0;
            padding: var(--wf-space-4) var(--wf-space-5);
        }

        .wf-command-search input {
            width: 100%;
            font-size: 16px;
        }

        .wf-command-groups {
            min-height: 0;
            overflow-y: auto;
            padding: 0 var(--wf-space-5) var(--wf-space-5);
        }

        .wf-command-group + .wf-command-group {
            margin-top: var(--wf-space-4);
        }

        .wf-command-group h3 {
            margin: 0 0 var(--wf-space-2);
            color: var(--wf-muted);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .wf-command-list {
            display: flex;
            flex-direction: column;
            gap: var(--wf-space-1);
        }

        .wf-command-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--wf-space-3);
            width: 100%;
            appearance: none;
            border: var(--wf-border) solid transparent;
            border-radius: var(--wf-radius);
            background: transparent;
            color: var(--wf-ink);
            padding: 8px var(--wf-space-3);
            font: inherit;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
        }

        .wf-command-item:hover,
        .wf-command-item:focus-visible {
            border-color: var(--wf-rule);
            background: var(--wf-surface-2);
            outline: none;
        }

        .wf-command-item[aria-current="page"] {
            box-shadow: inset 3px 0 0 var(--wf-brand);
        }

        .wf-command-item[hidden],
        .wf-command-group[hidden] {
            display: none;
        }

        .wf-command-current {
            color: var(--wf-muted);
            font-size: 11px;
        }

        .wf-command-empty {
            margin: 0 var(--wf-space-5) var(--wf-space-5);
        }

        .wf-shortcut-content {
            min-height: 0;
            overflow-y: auto;
            padding: var(--wf-space-4) var(--wf-space-5) var(--wf-space-5);
        }

        .wf-shortcut-description {
            margin: 0 0 var(--wf-space-4);
            color: var(--wf-muted);
        }

        .wf-shortcut-group + .wf-shortcut-group {
            margin-top: var(--wf-space-4);
        }

        .wf-shortcut-group h3 {
            margin: 0 0 var(--wf-space-2);
            color: var(--wf-muted);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .wf-shortcut-list {
            margin: 0;
        }

        .wf-shortcut-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--wf-space-4);
            padding: 9px var(--wf-space-3);
            border-top: var(--wf-border) solid var(--wf-rule);
        }

        .wf-shortcut-row:first-child {
            border-top: 0;
        }

        .wf-shortcut-row dt,
        .wf-shortcut-row dd {
            margin: 0;
        }

        .wf-shortcut-row[hidden],
        .wf-shortcut-group[hidden] {
            display: none;
        }

        /* Full bleed rather than a 1120px column, but capped so a line of body
           text does not run the width of an ultrawide display. Left-aligned
           inside the cap on purpose: asymmetry is the point. */
        .page {
            width: auto;
            max-width: 1360px;
            margin: 0;
            padding: var(--wf-space-5) var(--wf-space-5) var(--wf-space-7);
        }

        /* ── Site colour (ADR 0014) ───────────────────────────────────────
           One operator choice, three surfaces. Every consumer resolves the
           stored key through --wf-site-<key>, so a hue retuned in tokens.json
           reaches all of them and the dark variants apply for free. */
        .wf-site-dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            flex: none;
            vertical-align: -1px;
        }

        .wf-color-picker {
            display: flex;
            flex-wrap: wrap;
            gap: var(--wf-space-2);
            margin-top: var(--wf-space-2);
        }

        .wf-color-option {
            position: relative;
        }

        /* The input stays in the layout and keeps its focus ring via the label
           below; opacity rather than display:none, so it remains focusable and
           announced as the radio it is. */
        .wf-color-option input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }

        .wf-color-swatch {
            display: flex;
            align-items: center;
            gap: var(--wf-space-2);
            padding: 6px 12px 6px 8px;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
        }

        .wf-color-swatch i {
            display: block;
            width: 14px;
            height: 14px;
            flex: none;
        }

        .wf-color-option input:checked + .wf-color-swatch {
            border-color: var(--wf-ink);
            box-shadow: inset 0 0 0 1px var(--wf-ink);
        }

        .wf-color-option input:focus-visible + .wf-color-swatch {
            outline: 2px solid var(--wf-brand);
            outline-offset: 2px;
        }

        /* ── Queues (ADR 0014) ────────────────────────────────────────────
           The old queue put three bands of chrome above the first row and gave
           each row ~130px, so three conversations fitted a screen. Lanes now
           carry their own counts (which deletes the separate snapshot band),
           filters collapse to one line, and a row states only what is true:
           a resting presence or an unavailable cobrowse says nothing, so it
           shows nothing. */

        .wf-lanes {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            border-bottom: var(--wf-border) solid var(--wf-rule-firm);
        }

        .wf-lane {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            margin-bottom: -1px;
            border-bottom: 2px solid transparent;
            color: var(--wf-muted);
            font-size: var(--wf-text-ui);
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
        }

        .wf-lane:hover {
            color: var(--wf-ink);
        }

        .wf-lane[aria-current="page"] {
            color: var(--wf-ink);
            font-weight: 600;
            border-bottom-color: var(--wf-ink);
        }

        .wf-lane-count {
            font-family: var(--wf-font-mono);
            font-size: 11px;
            font-variant-numeric: tabular-nums;
            color: var(--wf-muted);
        }

        /* Red on the two lanes that mean somebody is waiting, and only when
           the count is not zero. Everything else stays neutral -- a queue
           where every number is coloured has no signal in it. */
        .wf-lane-count[data-tone="waiting"] {
            color: var(--wf-signal-stop);
            font-weight: 600;
        }

        /* A second row of lanes for a queue with more than one axis. Tickets
           have both "which tickets" (status, owner) and "what needs doing"
           (next step), and flattening them into one row lost which was which. */
        .wf-lanes-secondary {
            border-bottom: 0;
            padding-top: var(--wf-space-1);
        }

        .wf-lanes-secondary .wf-lane[aria-current="page"] {
            border-bottom-color: var(--wf-brand);
        }

        .wf-lane-divider {
            align-self: center;
            width: var(--wf-border);
            height: 16px;
            margin: 0 var(--wf-space-2);
            background: var(--wf-rule-firm);
        }

        .wf-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: var(--wf-space-3);
            padding: var(--wf-space-3) 0 var(--wf-space-4);
        }

        .wf-filter {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .wf-filter > label {
            font-family: var(--wf-font-cond);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--wf-muted);
        }

        .wf-filter input,
        .wf-filter select {
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            background: var(--wf-surface);
            color: var(--wf-ink);
            padding: 5px 8px;
            font-size: 12.5px;
        }

        .wf-filter input:focus,
        .wf-filter select:focus {
            outline: 2px solid var(--wf-brand);
            outline-offset: -1px;
        }

        .wf-filter-search input {
            width: 280px;
            max-width: 100%;
        }

        .wf-filter-actions {
            display: flex;
            align-items: center;
            gap: var(--wf-space-2);
        }

        .wf-filter-actions .button {
            min-height: 30px;
            padding: 0 12px;
            font-size: 12.5px;
        }

        .wf-filter-help {
            font-size: 11px;
            color: var(--wf-muted);
        }

        .wf-queue-summary {
            margin: 0 0 var(--wf-space-3);
            font-size: 12.5px;
            color: var(--wf-muted);
        }

        .wf-queue-summary strong {
            color: var(--wf-ink);
        }

        .wf-bulk-result {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: var(--wf-space-3);
            justify-content: space-between;
        }

        .wf-bulk-result form {
            margin: 0;
        }

        .wf-bulk-toolbar {
            align-items: center;
            background: var(--wf-surface-2);
            border: var(--wf-border) solid var(--wf-rule);
            border-bottom: 0;
            display: flex;
            flex-wrap: wrap;
            gap: var(--wf-space-2);
            padding: var(--wf-space-3);
        }

        .wf-bulk-toolbar > strong {
            min-width: 92px;
        }

        .wf-bulk-toolbar > label:not(.sr-only) {
            color: var(--wf-muted);
            font-family: var(--wf-font-cond);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .wf-bulk-toolbar select {
            background: var(--wf-surface);
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            color: var(--wf-ink);
            min-height: 30px;
            padding: 4px 8px;
        }

        .wf-queue .wf-queue-select {
            padding-left: 12px;
            padding-right: 4px;
            text-align: center;
            width: 34px;
        }

        .wf-queue [data-queue-bulk-row][data-selected] td {
            background: color-mix(in srgb, var(--wf-brand) 9%, var(--wf-surface));
        }

        .wf-queue [data-agent-shortcut-row][data-shortcut-active] td:first-child {
            box-shadow: inset 3px 0 0 var(--wf-brand);
        }

        .wf-bulk-caution {
            background: var(--wf-surface-2);
            border-left: 3px solid var(--wf-brand);
            color: var(--wf-muted);
            margin: 0 0 var(--wf-space-4);
            padding: var(--wf-space-3);
        }

        .wf-bulk-confirm-actions {
            display: flex;
            flex-wrap: wrap;
            gap: var(--wf-space-2);
            margin-top: var(--wf-space-4);
        }

        .wf-bulk-confirm-actions form {
            margin: 0;
        }

        @media (max-width: 700px) {
            .wf-queue.wf-bulk-review-table,
            .wf-queue.wf-bulk-review-table tbody,
            .wf-queue.wf-bulk-review-table tr,
            .wf-queue.wf-bulk-review-table td {
                display: block;
                width: 100%;
            }

            .wf-queue.wf-bulk-review-table thead {
                clip: rect(0 0 0 0);
                clip-path: inset(50%);
                height: 1px;
                overflow: hidden;
                position: absolute;
                white-space: nowrap;
                width: 1px;
            }

            .wf-queue.wf-bulk-review-table tr {
                border: var(--wf-border) solid var(--wf-rule);
                border-left: 3px solid var(--wf-brand);
                margin-bottom: var(--wf-space-3);
                padding: var(--wf-space-2) var(--wf-space-3);
            }

            .wf-queue.wf-bulk-review-table tbody td,
            .wf-queue.wf-bulk-review-table tbody .wf-queue-subject {
                align-items: baseline;
                border: 0;
                display: grid;
                gap: var(--wf-space-2);
                grid-template-columns: minmax(72px, 0.35fr) minmax(0, 1fr);
                min-width: 0;
                padding: 5px 0;
            }

            .wf-queue.wf-bulk-review-table td::before {
                color: var(--wf-muted);
                content: attr(data-label);
                font-family: var(--wf-font-cond);
                font-size: 10.5px;
                font-weight: 600;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }
        }

        .wf-queue {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        .wf-queue thead th {
            padding: 6px 10px;
            border-bottom: var(--wf-border) solid var(--wf-rule-firm);
            font-family: var(--wf-font-cond);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--wf-muted);
            text-align: left;
            white-space: nowrap;
        }

        .wf-queue tbody td {
            padding: 7px 10px;
            border-bottom: var(--wf-border) solid var(--wf-rule);
            vertical-align: middle;
        }

        .wf-queue tbody tr:hover td {
            background: var(--wf-surface-2);
        }

        /* The site rail. Same device as the transcript chip and the widget's
           panel edge, all resolving the same token. */
        .wf-queue-subject {
            border-left: var(--wf-rail) solid var(--wf-row-site, var(--wf-rule-firm));
            min-width: 220px;
        }

        .wf-queue-subject a {
            color: var(--wf-ink);
            font-weight: 600;
            text-decoration: none;
        }

        .wf-queue-subject a:hover {
            text-decoration: underline;
        }

        .wf-queue-preview {
            display: block;
            margin-top: 2px;
            color: var(--wf-muted);
            font-size: 11.5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            /* Narrow by default: several of these sit in the same row, and at
               52ch each they pushed the last two columns of the ticket queue
               off the edge entirely. The columns that carry real prose opt
               into more width below. */
            max-width: 30ch;
        }

        .wf-queue-subject .wf-queue-preview,
        .ticket-activity-preview .wf-queue-preview {
            max-width: 44ch;
        }

        .wf-queue-cobrowse {
            color: var(--wf-muted);
            white-space: nowrap;
        }

        .wf-queue-cobrowse[data-tone="live"] {
            color: var(--wf-signal-go);
        }

        .wf-queue-cobrowse[data-tone="attention"] {
            color: var(--wf-signal-hold);
        }

        .wf-queue-site {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--wf-muted);
            text-decoration: none;
            white-space: nowrap;
        }

        .wf-queue-site:hover {
            color: var(--wf-ink);
        }

        .wf-queue-state {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .wf-queue-state i {
            width: 7px;
            height: 7px;
            flex: none;
            border-radius: var(--wf-radius-full);
            background: var(--wf-signal-rest);
        }

        .wf-queue-state[data-tone="waiting"] {
            color: var(--wf-signal-stop);
            font-weight: 500;
        }

        .wf-queue-state[data-tone="waiting"] i {
            background: var(--wf-signal-stop);
        }

        /* Marks appear only when something is true. A quiet visitor and an
           unavailable cobrowse are the resting states of nearly every row, and
           printing them on all of them is how the old queue filled 130px with
           nothing. */
        .wf-queue-marks {
            display: inline-flex;
            align-items: center;
            gap: var(--wf-space-2);
            margin-left: var(--wf-space-2);
        }

        .wf-queue-mark {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: var(--wf-muted);
            white-space: nowrap;
        }

        .wf-queue-mark i {
            width: 6px;
            height: 6px;
            flex: none;
            border-radius: var(--wf-radius-full);
        }

        .wf-queue-mark[data-tone="live"] {
            color: var(--wf-signal-go);
        }

        .wf-queue-mark[data-tone="live"] i {
            background: var(--wf-signal-go);
        }

        .wf-queue-mark[data-tone="attention"] {
            color: var(--wf-signal-hold);
        }

        .wf-queue-mark[data-tone="attention"] i {
            background: var(--wf-signal-hold);
        }

        /* Unread is red TEXT with a dot, not a filled badge. On a queue where
           most rows are unread, a filled badge on every one of them is not a
           signal -- it is a wall. The dot matches the attention state beside
           it, so the two read as one language. */
        .wf-queue-unread {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--wf-signal-stop);
            font-weight: 500;
            white-space: nowrap;
        }

        .wf-queue-unread::before {
            content: "";
            width: 7px;
            height: 7px;
            flex: none;
            border-radius: var(--wf-radius-full);
            background: var(--wf-signal-stop);
        }

        /* The copy control is useful but must not outweigh the subject above
           it. In a queue row it is a quiet mono chip, not a button. */
        .wf-queue-preview .support-reference code {
            font-size: 11px;
        }

        .wf-queue-preview .support-reference-copy {
            font-size: 10px;
            padding: 0 4px;
            min-height: 0;
        }

        .wf-queue-when {
            font-variant-numeric: tabular-nums;
            color: var(--wf-muted);
            white-space: nowrap;
            width: 138px;
        }

        /* The wait label is prose ("Waiting on reply for 2 minutes"), so it has
           to be clamped to its column or it widens the whole table and pushes
           the last cells off the edge. */
        .wf-queue-when .wf-queue-preview {
            max-width: 130px;
        }

        .wf-queue-code {
            font-family: var(--wf-font-mono);
            font-size: 11px;
            color: var(--wf-muted);
            text-decoration: none;
            white-space: nowrap;
        }

        .wf-queue-code:hover {
            color: var(--wf-ink);
        }

        .wf-queue-assignee {
            color: var(--wf-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .wf-queue-assignee[data-unassigned="true"] {
            color: var(--wf-signal-hold);
        }

        /* ── Context sidebar (ADR 0014) ───────────────────────────────────
           The rail says which part of the product you are in. This says which
           part of THIS object -- the operator console has seven sections and
           used to navigate them with a single "back" link at the top of each
           page, outside the application shell entirely. */
        .wf-context {
            display: grid;
            grid-template-columns: 188px minmax(0, 1fr);
            gap: var(--wf-space-6);
            align-items: start;
        }

        .wf-context-nav {
            display: flex;
            flex-direction: column;
            gap: 1px;
            position: sticky;
            top: calc(52px + var(--wf-space-5));
        }

        .wf-context-heading {
            margin: 0 0 var(--wf-space-2);
            padding: 0 var(--wf-space-3);
            font-family: var(--wf-font-cond);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--wf-muted);
        }

        .wf-context-link {
            padding: 6px var(--wf-space-3);
            border-radius: var(--wf-radius);
            color: var(--wf-muted);
            font-size: var(--wf-text-ui);
            font-weight: 500;
            text-decoration: none;
        }

        .wf-context-link:hover {
            background: var(--wf-surface-2);
            color: var(--wf-ink);
        }

        .wf-context-link[aria-current="page"] {
            background: var(--wf-surface-2);
            color: var(--wf-ink);
            font-weight: 600;
            box-shadow: inset var(--wf-rail) 0 0 var(--wf-brand);
        }

        .wf-context-link:focus-visible {
            outline: 2px solid var(--wf-brand);
            outline-offset: -2px;
        }

        .wf-context-body {
            min-width: 0;
        }

        /* The first card in the body already carries the top margin the
           sections below it use, which pushed it out of line with the nav. */
        .wf-context-body > .section:first-child,
        .wf-context-body > .page-header + .section {
            margin-top: var(--wf-space-4);
        }

        /* ── Queue switcher (ADR 0014) ────────────────────────────────────
           The reference platforms put this in the breadcrumb; here it sits with
           the page title, which is the same affordance without threading a slot
           through the shared layout for one screen. */
        .wf-switcher {
            display: inline-flex;
            align-items: stretch;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            background: var(--wf-surface);
        }

        .wf-switcher-step {
            display: flex;
            align-items: center;
            padding: 0 var(--wf-space-3);
            color: var(--wf-muted);
            font-size: 13px;
            text-decoration: none;
        }

        .wf-switcher-step:hover {
            background: var(--wf-surface-2);
            color: var(--wf-ink);
        }

        .wf-switcher-step[data-disabled="true"] {
            opacity: 0.35;
        }

        .wf-switcher-list {
            position: relative;
            border-left: var(--wf-border) solid var(--wf-rule);
            border-right: var(--wf-border) solid var(--wf-rule);
        }

        .wf-switcher-list > summary {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px var(--wf-space-3);
            cursor: pointer;
            list-style: none;
            font-family: var(--wf-font-mono);
            font-size: 11.5px;
            font-variant-numeric: tabular-nums;
            color: var(--wf-muted);
            white-space: nowrap;
        }

        .wf-switcher-list > summary::-webkit-details-marker {
            display: none;
        }

        .wf-switcher-list[open] > summary {
            color: var(--wf-ink);
        }

        .wf-switcher-menu {
            position: absolute;
            right: 0;
            z-index: 10;
            width: 340px;
            max-height: 320px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            margin-top: var(--wf-space-1);
            border: var(--wf-border) solid var(--wf-rule-firm);
            border-radius: var(--wf-radius);
            background: var(--wf-surface);
        }

        .wf-switcher-item {
            padding: 7px var(--wf-space-3);
            border-bottom: var(--wf-border) solid var(--wf-rule);
            color: var(--wf-muted);
            font-size: 12.5px;
            text-decoration: none;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wf-switcher-item:last-child {
            border-bottom: 0;
        }

        .wf-switcher-item:hover {
            background: var(--wf-surface-2);
            color: var(--wf-ink);
        }

        .wf-switcher-item[aria-current="true"] {
            color: var(--wf-ink);
            font-weight: 600;
            box-shadow: inset var(--wf-rail) 0 0 var(--wf-brand);
        }

        @media (max-width: 900px) {
            .wf-app {
                grid-template-columns: minmax(0, 1fr);
            }

            .wf-rail {
                position: static;
                height: auto;
                flex-direction: row;
                align-items: center;
                gap: var(--wf-space-3);
                overflow-x: auto;
                border-right: 0;
                border-bottom: var(--wf-border) solid var(--wf-rule);
                padding: var(--wf-space-2) var(--wf-space-3);
            }

            .wf-nav {
                flex-direction: row;
                padding: 0;
                gap: var(--wf-space-1);
            }

            .wf-nav-heading,
            .wf-identity-sub {
                display: none;
            }

            /* Hiding this outright left a phone with no way to change the
               theme, or to clear a stored choice by going back to Auto -- while
               the stored choice kept being applied. It shrinks instead. */
            .wf-theme {
                margin: 0;
                flex: none;
            }

            .wf-theme button {
                padding: 5px 7px;
                font-size: 10px;
            }

            /* Visually hidden, NOT display:none. The icon beside it is
               aria-hidden, so removing the label outright leaves every nav
               link with no accessible name at all on a phone. */
            .wf-nav-link span {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            /* The rail already says where you are, and the account name wrapped
               to three lines in a 52px bar. Keep the page, drop the ancestry. */
            .wf-crumbs a,
            .wf-crumbs .wf-icon {
                display: none;
            }

            .wf-nav-link[aria-current="page"] {
                box-shadow: inset 0 calc(var(--wf-rail) * -1) 0 var(--wf-brand);
            }

            .wf-rail-foot {
                margin-top: 0;
                flex-direction: row;
                align-items: center;
                gap: var(--wf-space-2);
            }

            .wf-signout,
            .wf-identity {
                margin: 0;
            }

            .page {
                padding: var(--wf-space-4) var(--wf-space-4) var(--wf-space-6);
            }

            /* 188px of sidebar beside the content leaves ~130px for a form on a
               375px phone. The sections become a scrolling row above the body. */
            .wf-context {
                grid-template-columns: minmax(0, 1fr);
                gap: var(--wf-space-4);
            }

            .wf-context-nav {
                position: static;
                flex-direction: row;
                gap: var(--wf-space-1);
                overflow-x: auto;
                border-bottom: var(--wf-border) solid var(--wf-rule);
                padding-bottom: var(--wf-space-2);
            }

            .wf-context-heading {
                display: none;
            }

            .wf-context-link {
                white-space: nowrap;
            }

            .wf-context-link[aria-current="page"] {
                box-shadow: inset 0 calc(var(--wf-rail) * -1) 0 var(--wf-brand);
            }

            .wf-topbar-search input {
                width: 140px;
            }

            .wf-topbar {
                gap: var(--wf-space-2);
                padding-inline: var(--wf-space-3);
            }

            .wf-command-trigger {
                padding-inline: 7px;
            }

            .wf-command-trigger-label {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            .wf-command-dialog {
                width: calc(100vw - 24px);
                max-height: calc(100vh - 24px);
            }

            .wf-command-header,
            .wf-command-search {
                padding-inline: var(--wf-space-4);
            }

            .wf-command-groups,
            .wf-shortcut-content {
                padding-inline: var(--wf-space-4);
            }
        }

        .auth-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .panel {
            width: min(100%, 420px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 28px;
        }

        .panel h1,
        .page h1 {
            margin: 0;
            font-size: 1.75rem;
            line-height: 1.2;
        }

        .lede {
            margin: 8px 0 0;
            color: var(--muted);
        }

        .page-header__back {
            display: inline-block;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
        }

        .page-header__back::before {
            content: "\2190\00a0";
        }

        .page-header__back:hover {
            color: var(--accent-strong);
        }

        .page-header__bar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .page-header__heading {
            min-width: 0;
        }

        .page-header__actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .field {
            margin-top: 18px;
        }

        /* A fieldset groups the radios for assistive technology, but its UA
           border and padding are a browser default rather than a choice, and
           its intrinsic min-width breaks flex and grid parents. */
        fieldset.field {
            border: 0;
            padding: 0;
            min-width: 0;
        }

        .field legend {
            padding: 0;
            margin-bottom: 6px;
            font-size: 0.9rem;
            font-weight: 650;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
            font-weight: 650;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 11px 12px;
            background: var(--wf-surface);
            color: var(--text);
        }

        .field textarea {
            min-height: 132px;
            resize: vertical;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: 3px solid color-mix(in srgb, var(--accent) 22%, transparent);
            border-color: var(--accent);
        }

        .field-error {
            margin: 6px 0 0;
            color: var(--danger);
            font-size: 0.9rem;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .field-help {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .check-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            color: var(--muted);
        }

        .check-row input {
            width: 16px;
            height: 16px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 0 16px;
            background: var(--wf-ink);
            color: var(--wf-ink-invert);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .button:hover {
            background: color-mix(in srgb, var(--wf-ink) 82%, var(--wf-paper));
        }

        .button.secondary {
            background: transparent;
            color: var(--text);
            border-color: var(--border);
        }

        .button.secondary:hover {
            background: var(--surface-muted);
        }

        .button:disabled,
        .button:disabled:hover {
            background: var(--surface-muted);
            border-color: var(--border);
            color: var(--muted);
            cursor: not-allowed;
        }

        .button.danger {
            background: transparent;
            border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
            color: var(--danger);
        }

        .button.danger:hover {
            background: color-mix(in srgb, var(--danger) 8%, var(--surface));
        }

        .button.full {
            width: 100%;
            margin-top: 22px;
        }

        .text-link {
            color: var(--accent-strong);
            font-weight: 700;
            text-decoration: none;
        }

        .text-link:hover {
            text-decoration: underline;
        }

        .section {
            margin-top: 28px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .section[id] {
            scroll-margin-top: 96px;
        }

        .tabs__list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 28px;
            border-bottom: 1px solid var(--border);
        }

        .tabs__tab {
            /* Matches .section[id]: clears the sticky 52px topbar with room to
               spare, so a deep-linked tab is visible rather than tucked under
               the header. */
            scroll-margin-top: 96px;
            appearance: none;
            background: none;
            border: 0;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            padding: 10px 14px;
            font: inherit;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tabs__tab:hover {
            color: var(--text);
        }

        .tabs__tab[aria-selected="true"] {
            color: var(--accent-strong);
            border-bottom-color: var(--accent);
        }

        .tabs__tab:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
            border-radius: 4px;
        }

        .tabs__badge {
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 4px 8px;
            border-radius: 999px;
            background: var(--surface-muted);
            border: 1px solid var(--border);
            color: var(--muted);
        }

        .tabs__tab[aria-selected="true"] .tabs__badge {
            color: var(--accent-strong);
        }

        .tab-panel[hidden] {
            display: none;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .section-header h2 {
            margin: 0;
            font-size: 1rem;
        }

        .section-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: flex-end;
        }

        .section-actions .lede {
            margin-top: 0;
        }

        .table-wrap {
            overflow-x: auto;
        }

        /*
         * The first chart in the product, and deliberately the plainest thing
         * that answers the question (ADR 0014). Bars are divs rather than SVG:
         * the shape is one rectangle per day, and CSS already knows how to lay
         * that out responsively without a viewBox to keep in sync.
         *
         * Opened is filled, closed is outlined -- the pair stays distinguishable
         * in monochrome, at a glance, and for a reader who does not separate the
         * two hues.
         */
        .chart-scroll {
            overflow-x: auto;
        }

        .chart {
            display: flex;
            align-items: flex-end;
            gap: var(--wf-space-1);
            min-height: 140px;
            padding-top: var(--wf-space-3);
            border-bottom: 1px solid var(--wf-rule-firm);
        }

        .chart__day {
            flex: 1 0 14px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            height: 140px;
        }

        .chart__bars {
            display: flex;
            align-items: flex-end;
            gap: 1px;
            width: 100%;
            height: 100%;
        }

        .chart__bar {
            flex: 1;
            /* A day with one conversation must not vanish into the axis. */
            min-height: 1px;
        }

        .chart__bar--opened {
            background: var(--wf-brand);
        }

        .chart__bar--closed {
            border: 1px solid var(--wf-brand);
            border-bottom: 0;
            background: transparent;
        }

        /* ...but a day with none must draw nothing. Without this the minimum
           above leaves a sliver on every empty day, so a quiet week reads as a
           busy one.

           Deliberately doubled up on the block class, and deliberately after
           the variant rules: at equal specificity `--closed` came later and put
           its 1px border back, so the sliver survived a fix that looked
           correct in the markup. */
        .chart__bar.chart__bar--none {
            min-height: 0;
            border: 0;
        }

        .chart-legend {
            display: flex;
            align-items: center;
            gap: var(--wf-space-2);
            font-size: var(--wf-text-ui);
            color: var(--wf-muted);
        }

        .chart-key {
            display: inline-block;
            width: 12px;
            height: 12px;
        }

        .chart-key--opened {
            background: var(--wf-brand);
        }

        .chart-key--closed {
            border: 1px solid var(--wf-brand);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            white-space: nowrap;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        th {
            color: var(--muted);
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .table-note {
            color: var(--muted);
            display: block;
            font-size: 0.82rem;
            margin-top: 4px;
            white-space: nowrap;
        }

        .queue-activity-preview,
        .ticket-activity-preview {
            max-width: 340px;
            min-width: 260px;
            white-space: normal;
        }

        .queue-activity-preview .lede,
        .ticket-activity-preview .lede {
            margin-top: 4px;
        }

        .empty {
            padding: 20px;
            color: var(--muted);
        }

        .status-message {
            margin: 20px 0 0;
            color: var(--accent-strong);
            font-weight: 700;
        }

        /* Closing the desk early is an operational act, not configuration, so
           it sits above the schedule form and is separated from it rather than
           reading as its first field. */
        .desk-closure {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 16px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--wf-border);
        }

        .desk-closure-state {
            flex: 1 1 16rem;
            margin: 0;
            color: var(--wf-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .desk-closure-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .section-form {
            padding: 20px;
        }

        .section-form .field:first-child {
            margin-top: 0;
        }

        .section-form .button {
            margin-top: 16px;
        }

        .automation-rule-basics {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(220px, 1fr) minmax(120px, 0.5fr);
            gap: 16px;
        }

        .automation-rule-basics .field {
            margin-top: 0;
        }

        .automation-builder {
            min-width: 0;
            margin-top: 24px;
            border: 0;
            border-top: 1px solid var(--border);
            padding: 20px 0 0;
        }

        .automation-builder > legend {
            padding: 0 8px 0 0;
            font-weight: 700;
        }

        .automation-builder-row {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(160px, 0.8fr) minmax(220px, 1.4fr) auto;
            align-items: end;
            gap: 12px;
            padding: 16px;
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface-muted);
        }

        .automation-builder-row .field,
        .automation-builder-row .button {
            margin-top: 0;
        }

        .automation-row-actions {
            display: grid;
            gap: 8px;
        }

        .automation-builder-row textarea {
            min-height: 76px;
        }

        .automation-enabled {
            margin-top: 24px;
        }

        .automation-preview-result {
            padding: 20px;
            border-top: 1px solid var(--border);
        }

        .automation-definition-list {
            margin: 8px 0 0;
            padding-left: 22px;
            white-space: normal;
        }

        .automation-definition-list li + li {
            margin-top: 6px;
        }

        .automation-log-details {
            min-width: 260px;
            white-space: normal;
        }

        /* The 1px gaps used to be filled by the grid's own background, which
           painted every empty cell of a partial last row as a solid grey void.
           Each item draws its own hairline ring instead: neighbours overlap
           harmlessly inside the gap, and an empty cell is simply empty. This
           works at any column count, which matters because the grid collapses
           to one column on narrow viewports. */
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1px;
            background: var(--surface);
            border-top: 1px solid var(--border);
        }

        .meta-item {
            background: var(--surface);
            padding: 16px 20px;
            box-shadow: 0 0 0 1px var(--border);
        }

        .meta-label {
            color: var(--muted);
            display: block;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .meta-value {
            display: block;
            font-weight: 700;
            margin-top: 4px;
            overflow-wrap: anywhere;
        }

        .meta-item input,
        .meta-item select,
        .meta-item textarea {
            width: 100%;
            margin-top: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 11px;
            background: var(--wf-surface);
            color: var(--text);
        }

            .meta-item .button {
                margin: 8px 8px 0 0;
            }

            .management-list {
                display: grid;
            }

            .management-link {
                align-items: center;
                border-bottom: 1px solid var(--border);
                color: var(--text);
                display: grid;
                gap: 16px;
                grid-template-columns: minmax(0, 1fr) auto;
                padding: 18px 20px;
                text-decoration: none;
            }

            .management-link:last-child {
                border-bottom: 0;
            }

            .management-link:hover {
                background: var(--surface-muted);
            }

            .management-link strong,
            .management-link span {
                display: block;
            }

            .management-link .lede {
                margin-top: 4px;
            }

            .management-action {
                color: var(--accent-strong);
                font-weight: 700;
                white-space: nowrap;
            }

            .compact-form {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
        }

        .compact-form input,
        .compact-form select {
            width: auto;
            min-width: 120px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 9px 10px;
            background: var(--wf-surface);
            color: var(--text);
        }

        .compact-form .button {
            min-height: 38px;
        }

        .external-issue-retry-form {
            margin-top: 12px;
        }

        .support-lookup-form {
            flex: 0 1 220px;
            flex-wrap: nowrap;
            max-width: 220px;
            min-width: min(100%, 220px);
        }

        .support-lookup-form input {
            flex: 1 1 145px;
            min-width: 145px;
            width: 145px;
        }

        .realtime-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }

        .realtime-note {
            border-top: 1px solid var(--border);
            margin: 0;
        }

        .readiness-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .system-identity-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .readiness-list {
            display: grid;
            /* An implicit auto track sizes to max-content, and grid items
               default to min-width:auto, so one long command stretched the
               whole list past its card. minmax(0, 1fr) lets the track stay at
               the container width and the code block scroll inside it. */
            grid-template-columns: minmax(0, 1fr);
        }

        .readiness-check {
            border-bottom: 1px solid var(--border);
            padding: 18px 20px;
        }

        .readiness-check:last-child {
            border-bottom: 0;
        }

        .readiness-check-main {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .readiness-check h3 {
            margin: 0;
            font-size: 1rem;
        }

        .readiness-check p {
            margin: 6px 0 0;
        }

        .readiness-status {
            border: 1px solid var(--border);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 10px;
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .readiness-status[data-status="ready"] {
            background: color-mix(in srgb, var(--accent) 10%, var(--surface));
            border-color: color-mix(in srgb, var(--accent) 38%, var(--border));
            color: var(--accent-strong);
        }

        .readiness-status[data-status="attention"] {
            background: color-mix(in srgb, var(--danger) 8%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 36%, var(--border));
            color: var(--danger);
        }

        .break-glass-banner {
            background: color-mix(in srgb, var(--danger) 5%, var(--surface));
            border-color: color-mix(in srgb, var(--danger) 32%, var(--border));
        }

        .break-glass-banner h2 {
            color: var(--danger);
        }

        .readiness-status[data-status="manual"] {
            background: color-mix(in srgb, var(--wf-signal-hold) 14%, var(--wf-surface));
            border-color: color-mix(in srgb, var(--wf-signal-hold) 45%, var(--wf-rule));
            color: color-mix(in srgb, var(--wf-signal-hold) 72%, var(--wf-ink));
        }

        .readiness-action {
            color: var(--text);
            font-weight: 650;
        }

        .readiness-commands {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .readiness-command {
            min-width: 0;
            align-items: center;
            display: inline-flex;
            gap: 6px;
            max-width: 100%;
        }

        .readiness-command code {
            overflow-x: auto;
            white-space: nowrap;
            /* Without this the code element cannot shrink below its content
               and a long command overflows the card instead of scrolling. */
            min-width: 0;
        }

        .notice-copy {
            padding: 20px;
            color: var(--muted);
        }

        .notice-copy p {
            margin: 0;
        }

        .notice-copy p + p {
            margin-top: 8px;
        }

        .notice-copy-bordered {
            border-bottom: 1px solid var(--border);
        }

        .notice-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .health-action {
            display: block;
            margin-top: 6px;
            width: fit-content;
        }

        .notice-list {
            display: grid;
            gap: 8px;
            margin-top: 16px;
            color: var(--muted);
        }

        .notice-list p {
            margin: 0;
        }

        .filter-summary {
            align-items: flex-start;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 16px;
            justify-content: space-between;
            padding: 16px 20px;
        }

        .filter-summary strong {
            display: block;
        }

        .filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .filter-chip {
            align-items: center;
            background: var(--surface-muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text);
            display: inline-flex;
            font-size: 0.86rem;
            font-weight: 700;
            gap: 8px;
            min-height: 34px;
            padding: 0 12px;
            text-decoration: none;
            white-space: nowrap;
        }

        .filter-chip:hover {
            border-color: color-mix(in srgb, var(--accent) 34%, var(--border));
            color: var(--accent-strong);
        }

        .filter-chip[aria-current="page"] {
            background: color-mix(in srgb, var(--accent) 11%, var(--surface));
            border-color: color-mix(in srgb, var(--accent) 48%, var(--border));
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 12%, transparent);
            color: var(--accent-strong);
        }

        .filter-chip-clear {
            background: var(--surface);
            color: var(--muted);
        }

        .ticket-label-list {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .ticket-label-chip {
            min-height: 30px;
        }

        code {
            background: var(--surface-muted);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text);
            padding: 1px 4px;
        }

        .support-reference {
            align-items: center;
            display: inline-flex;
            gap: 6px;
            white-space: nowrap;
        }

        .support-reference-copy {
            align-items: center;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--muted);
            cursor: pointer;
            display: inline-flex;
            font-size: 0.76rem;
            font-weight: 700;
            min-height: 28px;
            padding: 0 8px;
        }

        .support-reference-copy:hover,
        .support-reference-copy:focus-visible {
            background: var(--surface-muted);
            color: var(--accent-strong);
            outline: none;
        }

        .support-reference-copy[data-copy-state="copied"] {
            border-color: color-mix(in srgb, var(--accent) 38%, var(--border));
            color: var(--accent-strong);
        }

        .code-block {
            margin: 0;
            overflow-x: auto;
            padding: 18px 20px;
            border-top: 1px solid var(--border);
            background: var(--surface-muted);
            color: var(--text);
            font-size: 0.88rem;
            line-height: 1.55;
            white-space: pre;
        }

        .code-block code {
            display: block;
            padding: 0;
            border: 0;
            background: transparent;
        }

        /* The two backgrounds below stay literally white on purpose. This frame
           renders a replay of the VISITOR'S page, which is their design, not
           ours -- tinting it with our surface token would repaint a customer's
           site dark and misrepresent what the visitor is looking at. */
        .cobrowse-preview-frame {
            background: var(--surface-muted);
            border-top: 1px solid var(--border);
            padding: 16px;
        }

        .cobrowse-preview-scale {
            height: 360px;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #ffffff;
        }

        .cobrowse-preview {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #ffffff;
            transform-origin: 0 0;
        }

        .live-update {
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 20px;
        }

        .live-update strong {
            display: block;
        }

        .live-update .lede {
            margin-top: 2px;
        }

        .live-update[data-state="available"] {
            background: var(--surface-muted);
        }

        .live-update[data-state="pending"] {
            background: color-mix(in srgb, var(--accent) 6%, var(--surface));
        }

        .live-update[data-state="fulfilled"] {
            background: color-mix(in srgb, var(--accent) 10%, var(--surface));
        }

        .live-update[data-state="delayed"] {
            background: color-mix(in srgb, var(--wf-signal-hold) 12%, var(--wf-surface));
        }

        .live-update[data-state="exhausted"] {
            background: color-mix(in srgb, var(--wf-signal-hold) 12%, var(--wf-surface));
        }

        .live-update[data-state="expired"] {
            background: color-mix(in srgb, var(--danger) 7%, var(--surface));
        }

        [hidden] {
            display: none !important;
        }

        /* ── The transcript (ADR 0014) ─────────────────────────────────────
           The widget has always rendered this as a conversation -- agent
           replies to one side, in their own bubble. The agent's own view
           stacked both sides full width under a header reading "Messages ·
           3 total", so the two halves of the same exchange used opposite
           metaphors and only the agent got the log table.

           .message-card is deliberately NOT included here: tickets use it for
           notes and updates, which are cards, not dialogue. */
        .message-list {
            display: flex;
            flex-direction: column;
            gap: var(--wf-space-3);
            padding: var(--wf-space-5);
        }

        .message-card {
            border: var(--wf-border) solid var(--border);
            border-radius: 8px;
            padding: 14px;
        }

        .message-card.agent-message {
            background: var(--surface-muted);
        }

        .message {
            max-width: 74%;
            min-width: 0;
            border: var(--wf-border) solid var(--wf-rule);
            border-radius: var(--wf-radius);
            padding: 9px 12px;
            background: var(--wf-surface);
        }

        /* The visitor sits left and carries the site's colour: on a desk
           covering many sites, whose customer is speaking is the first thing
           an agent needs. */
        .message.visitor {
            align-self: flex-start;
            border-left: var(--wf-rail) solid var(--wf-conversation-site, var(--wf-rule-firm));
        }

        /* The agent sits right and recedes. An agent re-reading a thread is
           looking for what the visitor said; their own replies are context. */
        .message.agent {
            align-self: flex-end;
            background: var(--wf-surface-2);
        }

        .message.grouped {
            margin-top: calc(var(--wf-space-3) * -1 + 2px);
        }

        .message.agent .message-meta {
            justify-content: flex-end;
        }

        /* A message with neither text nor an attachment used to render as an
           empty bordered box with a timestamp, which reads as a rendering bug
           rather than as what it is. */
        .message-empty {
            margin: 0;
            color: var(--wf-muted);
            font-style: italic;
        }

        .empty-state {
            color: var(--muted);
        }

        .empty-state strong {
            color: var(--text);
            display: block;
        }

        .empty-state-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .empty-state-actions .button {
            margin-top: 0;
        }

        .message-meta {
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            font-size: 0.85rem;
            gap: 8px;
            justify-content: space-between;
        }

        .message-time {
            white-space: nowrap;
        }

        .message-status-line {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .message-seen {
            color: var(--accent-strong);
            font-weight: 700;
            white-space: nowrap;
        }

        .message-body {
            margin: 10px 0 0;
            white-space: pre-wrap;
        }

        .message.grouped .message-body {
            margin-top: 6px;
        }

        .message .button {
            margin-top: 12px;
        }

        .message-attachments {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .message-attachment {
            text-decoration: none;
        }

        .message-attachment-image {
            border: 1px solid var(--border);
            border-radius: 8px;
            display: block;
            max-height: 240px;
            max-width: 100%;
        }

        .message-attachment-file {
            align-items: center;
            background: var(--surface-muted);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            display: inline-flex;
            font-size: 0.9rem;
            gap: 8px;
            padding: 8px 10px;
        }

        .message-attachment-file:hover {
            border-color: var(--accent-strong);
        }

        .message-attachment-name {
            font-weight: 600;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .reply-attachments-field {
            display: grid;
            gap: 10px;
        }

        .reply-attach-button {
            justify-self: start;
        }

        .reply-attachments {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .reply-attachments[hidden] {
            display: none;
        }

        .reply-attach-chip {
            align-items: center;
            background: var(--surface-muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text);
            display: inline-flex;
            font-size: 0.82rem;
            gap: 8px;
            max-width: 100%;
            padding: 4px 6px 4px 12px;
        }

        .reply-attach-chip--error {
            background: var(--surface);
            border-color: color-mix(in srgb, var(--wf-signal-stop) 55%, var(--wf-rule));
            color: var(--wf-signal-stop);
        }

        .reply-attach-chip-name {
            font-weight: 600;
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .reply-attach-chip-state {
            color: var(--muted);
            font-size: 0.75rem;
        }

        .reply-attach-chip--error .reply-attach-chip-state {
            color: var(--wf-signal-stop);
        }

        .reply-attach-chip-remove {
            background: transparent;
            border: 0;
            color: var(--muted);
            cursor: pointer;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1;
            padding: 0 2px;
        }

        .reply-attach-chip-remove:hover {
            color: var(--wf-signal-stop);
        }

        .details-disclosure {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-muted);
        }

        .details-disclosure__summary {
            color: var(--muted);
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 700;
            padding: 10px 14px;
        }

        .details-disclosure__summary:hover {
            color: var(--text);
        }

        .details-disclosure[open] .details-disclosure__summary {
            border-bottom: 1px solid var(--border);
            color: var(--text);
        }

        .details-disclosure__body {
            display: grid;
            gap: 14px;
            padding: 14px;
        }

        .section-form-row {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-form-row .section-form {
            margin: 0;
        }

        .reply-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(260px, 340px);
            background: var(--surface);
            border-top: var(--wf-border) solid var(--border);
        }

        .reply-workspace > * + * {
            border-left: var(--wf-border) solid var(--border);
        }

        .reply-workspace .section-form {
            background: var(--surface);
        }

        /* The gap used to be painted by this element's own background, so the
           three cells the content did not fill rendered as one solid grey block
           beside it -- the same defect .meta-grid had. Items draw their own
           hairline instead, and the strip takes only the width it needs. */
        .reply-context-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 1px;
            overflow: hidden;
            margin-bottom: 18px;
            border: var(--wf-border) solid var(--border);
            border-radius: 6px;
            background: var(--surface);
        }

        .reply-context-item {
            min-width: 0;
            flex: 1 1 200px;
            padding: 12px;
            background: var(--surface-muted);
            box-shadow: 0 0 0 1px var(--border);
        }

        .reply-assist {
            background: var(--surface-muted);
            padding: 20px;
        }

        .reply-assist h3 {
            margin: 0;
            font-size: 1rem;
        }

        .reply-template-preview {
            margin-top: 14px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface);
        }

        .reply-template-preview p {
            margin: 8px 0 0;
            color: var(--muted);
            white-space: pre-wrap;
        }

        .timeline-list {
            display: grid;
            gap: 0;
            padding: 0;
        }

        .timeline-item {
            border-bottom: 1px solid var(--border);
            padding: 18px 20px;
        }

        .timeline-item:last-child {
            border-bottom: 0;
        }

        .timeline-content {
            border-left: 4px solid var(--border);
            padding-left: 14px;
        }

        .timeline-item.visitor-message .timeline-content {
            border-color: var(--accent);
        }

        .timeline-item.agent-message .timeline-content {
            border-color: var(--accent-strong);
            background: color-mix(in srgb, var(--surface-muted) 65%, transparent);
            border-radius: 0 6px 6px 0;
            padding-bottom: 12px;
            padding-top: 12px;
        }

        .timeline-item.internal-note .timeline-content {
            border-color: var(--wf-signal-hold);
        }

        .operator-activity-details {
            margin-top: 14px;
        }

        .operator-activity-details > .meta-label {
            margin-bottom: 8px;
        }

        .timeline-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
            color: var(--muted);
            font-size: 0.85rem;
        }

        @media (max-width: 1100px) {
            .topbar-inner {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "main"
                    "nav"
                    "actions";
                padding: 16px 0;
            }

            .app-nav {
                border-top: 0;
                margin-top: 0;
                padding-top: 0;
            }

            .app-nav,
            .topbar-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 640px) {
            /* Phones get thumb-scrollable single rows for the primary nav and
               workspace tabs instead of multi-row wrapping that pushes the
               work below the fold. */
            .app-nav {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding-bottom: 8px;
            }

            .app-nav::-webkit-scrollbar {
                display: none;
            }

            .app-nav-link {
                white-space: nowrap;
            }

            .tabs__list {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .tabs__list::-webkit-scrollbar {
                display: none;
            }

            .tabs__tab {
                white-space: nowrap;
                padding: 10px 10px;
            }

            .topbar-actions {
                flex-wrap: wrap;
            }

            .topbar-actions input {
                flex: 1 1 160px;
                min-width: 0;
                max-width: 100%;
            }

            .section-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .section-actions {
                justify-content: flex-start;
            }

            .meta-grid {
                grid-template-columns: 1fr;
            }

            .automation-rule-basics,
            .automation-builder-row {
                grid-template-columns: 1fr;
            }

            .management-link {
                grid-template-columns: 1fr;
            }

            .filter-summary {
                flex-direction: column;
            }

            .filter-chips {
                justify-content: flex-start;
            }

            .reply-workspace,
            .reply-context-strip {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    @if ($agent && $account)
        @php
            $workItems = [];

            if ($agent->hasAnyAccountPermission(\App\Enums\AccountPermission::ViewConversations, \App\Enums\AccountPermission::ManageTickets)) {
                $workItems[] = ['label' => __('nav.items.dashboard'), 'icon' => 'dashboard', 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')];
                $workItems[] = ['label' => __('nav.items.visitors'), 'icon' => 'visitors', 'href' => route('dashboard.visitors.index'), 'active' => request()->routeIs('dashboard.visitors.*')];
            }

            if ($agent->hasAccountPermission(\App\Enums\AccountPermission::ViewConversations)) {
                $workItems[] = ['label' => __('nav.items.conversations'), 'icon' => 'conversations', 'href' => route('dashboard.conversations.index'), 'active' => request()->routeIs('dashboard.conversations.*')];
            }

            if ($agent->hasAccountPermission(\App\Enums\AccountPermission::ManageTickets)) {
                $workItems[] = ['label' => __('nav.items.tickets'), 'icon' => 'tickets', 'href' => route('dashboard.tickets.index'), 'active' => request()->routeIs('dashboard.tickets.*')];
            }

            if ($agent->hasAccountPermission(\App\Enums\AccountPermission::ViewAlerts)) {
                $workItems[] = ['label' => __('nav.items.alerts'), 'icon' => 'alerts', 'href' => route('dashboard.alerts.index'), 'active' => request()->routeIs('dashboard.alerts.*')];
            }

            // Reporting is account-wide by nature -- it aggregates across every
            // site an agent can see -- so it requires the account-wide report
            // permission, separate from support queue access.
            if ($agent->hasAccountPermission(\App\Enums\AccountPermission::ViewReports)) {
                $workItems[] = ['label' => __('nav.items.reports'), 'icon' => 'reports', 'href' => route('dashboard.reports.index'), 'active' => request()->routeIs('dashboard.reports.*')];
            }

            $manageItems = [];

            if ($agent->hasAnyAccountPermission(
                \App\Enums\AccountPermission::ManageSites,
                \App\Enums\AccountPermission::ManageSiteAccess,
                \App\Enums\AccountPermission::ManagePrivacySettings,
                \App\Enums\AccountPermission::ManageIntegrations,
                \App\Enums\AccountPermission::ViewAudit,
                \App\Enums\AccountPermission::ViewConversations,
                \App\Enums\AccountPermission::ManageTickets,
            )) {
                $manageItems[] = ['label' => __('nav.items.sites'), 'icon' => 'sites', 'href' => route('dashboard.sites.index'), 'active' => request()->routeIs('dashboard.sites.*')];
            }

            $manageItems[] = ['label' => __('nav.items.account'), 'icon' => 'account', 'href' => route('dashboard.account.show'), 'active' => request()->routeIs('dashboard.account.*')];

            if ($agent->isPlatformOperator()) {
                $manageItems[] = ['label' => __('nav.items.operator'), 'icon' => 'operator', 'href' => route('operator.dashboard'), 'active' => request()->routeIs('operator.*')];
            }

            // The breadcrumb names where you are. The active navigation item is
            // the honest answer when there is one; the page title covers the
            // screens that sit outside the rail, like Profile.
            // A rail label or, for the screens outside the rail like Profile,
            // the page title. Both are now in the document's own language --
            // the rail was the reason this needed a `lang` of its own, and the
            // rail is extracted.
            $commandNavigationItems = collect($workItems)->concat($manageItems)->values();
            $currentLabel = $commandNavigationItems->firstWhere('active')['label'] ?? $title;
        @endphp

        <div class="wf-app">
            <aside class="wf-rail">
                <a class="wf-mark" href="{{ route('dashboard') }}">
                    <span class="wf-mark-planes" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span class="wf-mark-name">Wayfindr</span>
                </a>

                <nav class="wf-nav" aria-label="{{ __('nav.regions.primary') }}">
                    <p class="wf-nav-heading">{{ __('nav.groups.work') }}</p>
                    @foreach ($workItems as $item)
                        <a class="wf-nav-link" href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach

                    <p class="wf-nav-heading">{{ __('nav.groups.manage') }}</p>
                    @foreach ($manageItems as $item)
                        <a class="wf-nav-link" href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="wf-rail-foot">
                    <div class="wf-theme" role="group" aria-label="{{ __('nav.regions.theme') }}" data-wf-theme-group>
                        <button type="button" data-wf-theme-set="system" aria-pressed="true">{{ __('nav.theme.system') }}</button>
                        <button type="button" data-wf-theme-set="light" aria-pressed="false">{{ __('nav.theme.light') }}</button>
                        <button type="button" data-wf-theme-set="dark" aria-pressed="false">{{ __('nav.theme.dark') }}</button>
                    </div>

                    <a class="wf-identity" href="{{ route('dashboard.profile.show') }}">
                        <span class="wf-identity-name" lang="">{{ $agent->name }}</span>
                        <span class="wf-identity-sub" lang="">{{ $account->name }}</span>
                    </a>

                    <form class="wf-signout" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">{{ __('nav.sign_out') }}</button>
                    </form>
                </div>
            </aside>

            <div class="wf-main">
                <header class="wf-topbar">
                    <nav class="wf-crumbs" aria-label="{{ __('nav.regions.breadcrumb') }}">
                        <a href="{{ route('dashboard') }}" lang="">{{ $account->name }}</a>
                        <x-icon name="chevron-right" :size="13" />
                        @if ($crumb)
                            <a href="{{ route('operator.dashboard') }}">{{ $currentLabel }}</a>
                            <x-icon name="chevron-right" :size="13" />
                            <span class="wf-crumb-current">{{ $crumb }}</span>
                        @else
                            <span class="wf-crumb-current">{{ $currentLabel }}</span>
                        @endif
                    </nav>

                    <form class="wf-topbar-search" method="GET" action="{{ route('dashboard.support-code.lookup') }}" aria-label="{{ __('nav.regions.search') }}">
                        <label class="sr-only" for="shell_support_code">{{ __('nav.search.label') }}</label>
                        <input id="shell_support_code" name="support_code" type="search" placeholder="{{ __('nav.search.placeholder') }}" autocomplete="off" data-agent-shortcut-search>
                        <button type="submit">{{ __('nav.search.submit') }}</button>
                    </form>

                    <x-agent-command-palette :navigation-items="$commandNavigationItems" />
                    <x-agent-shortcut-reference />
                </header>

                <main class="page">
                    <x-active-break-glass-banner :agent="$agent" :account="$account" />

                    @if (session('support_code_lookup_result'))
                        <p class="status-message">{{ session('support_code_lookup_result') }}</p>
                    @endif

                    @if (session('support_code_lookup_status'))
                        <div class="empty empty-state" role="status">
                            <strong>{{ session('support_code_lookup_status') }}</strong>
                            <p>{{ __('nav.search.help') }}</p>
                            <p>{{ __('nav.search.scope') }}</p>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>
    @else
        {{ $slot }}
    @endif
    <script>
        (function () {
            function fallbackCopy(value) {
                var textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', 'readonly');
                textarea.style.position = 'fixed';
                textarea.style.top = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();

                try {
                    document.execCommand('copy');
                } finally {
                    document.body.removeChild(textarea);
                }
            }

            function markCopied(button) {
                var defaultLabel = button.getAttribute('data-copy-default-label') || 'Copy';
                var successLabel = button.getAttribute('data-copy-success-label') || 'Copied';

                button.textContent = successLabel;
                button.setAttribute('data-copy-state', 'copied');

                window.setTimeout(function () {
                    button.textContent = defaultLabel;
                    button.removeAttribute('data-copy-state');
                }, 1800);
            }

            function copyValue(value) {
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    return Promise.race([
                        navigator.clipboard.writeText(value),
                        new Promise(function (_resolve, reject) {
                            window.setTimeout(function () {
                                reject(new Error('Clipboard write timed out.'));
                            }, 250);
                        }),
                    ]).catch(function () {
                        fallbackCopy(value);
                    });
                }

                fallbackCopy(value);

                return Promise.resolve();
            }

            document.addEventListener('click', function (event) {
                var button = event.target.closest('[data-copy-value]');

                if (! button) {
                    return;
                }

                var value = button.getAttribute('data-copy-value') || '';

                if (! value) {
                    return;
                }

                copyValue(value).then(function () {
                    markCopied(button);
                });
            });
        })();
    </script>
    <script>
        (function () {
            var group = document.querySelector('[data-wf-theme-group]');

            if (! group) {
                return;
            }

            var buttons = group.querySelectorAll('[data-wf-theme-set]');

            function stored() {
                try {
                    return localStorage.getItem('wayfindr:theme') || 'system';
                } catch (error) {
                    return 'system';
                }
            }

            function apply(value) {
                if (value === 'dark' || value === 'light') {
                    document.documentElement.setAttribute('data-wf-theme', value);
                } else {
                    // Removing the attribute is what hands control back to the
                    // OS preference. Setting it to "system" would match neither
                    // selector and strand the page on the light palette.
                    document.documentElement.removeAttribute('data-wf-theme');
                }

                buttons.forEach(function (button) {
                    button.setAttribute(
                        'aria-pressed',
                        button.getAttribute('data-wf-theme-set') === value ? 'true' : 'false'
                    );
                });
            }

            apply(stored());

            group.addEventListener('click', function (event) {
                var button = event.target.closest('[data-wf-theme-set]');

                if (! button) {
                    return;
                }

                var value = button.getAttribute('data-wf-theme-set');

                try {
                    localStorage.setItem('wayfindr:theme', value);
                } catch (error) {
                    // Unremembered but still applied for this page.
                }

                apply(value);
            });
        })();
    </script>
    @if ($agent && $account)
        <x-agent-push-ownership-guard :status-endpoint="route('dashboard.profile.push-subscription.status')" />
        @if ($agentAlertRealtimeConfig = \App\Support\AgentAlertRealtimeConfig::forAgent($agent))
            <x-agent-alert-stream :config="$agentAlertRealtimeConfig" />
        @endif
        <x-agent-shortcut-script />
    @endif
</body>
</html>
