@props([
    'title' => config('app.name', 'Wayfindr'),
    'agent' => null,
    'account' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f7f7f3;
            --surface: #ffffff;
            --surface-muted: #f1f5f4;
            --text: #1d2523;
            --muted: #62706b;
            --border: #d8dfdc;
            --accent: #0d6f68;
            --accent-strong: #094f4b;
            --danger: #9d1c1c;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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

        .shell {
            min-height: 100vh;
        }

        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        .topbar-inner,
        .page {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
        }

        .topbar-inner {
            min-height: 72px;
            display: grid;
            grid-template-columns: minmax(150px, 0.7fr) auto minmax(440px, 1.3fr);
            align-items: center;
            gap: 16px;
        }

        .topbar-main {
            min-width: 0;
        }

        .brand,
        .brand-link {
            font-weight: 700;
        }

        .brand-link {
            color: var(--text);
            text-decoration: none;
        }

        .brand-link:hover {
            color: var(--accent-strong);
        }

        .topbar-context {
            margin-top: 2px;
        }

        .app-nav {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            justify-content: center;
        }

        .app-nav-link {
            border: 1px solid transparent;
            border-radius: 6px;
            color: var(--muted);
            font-weight: 700;
            padding: 8px 10px;
            text-decoration: none;
            white-space: nowrap;
        }

        .app-nav-link:hover {
            background: var(--surface-muted);
            color: var(--text);
        }

        .app-nav-link[aria-current="page"] {
            background: var(--surface-muted);
            border-color: var(--border);
            color: var(--accent-strong);
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }

        .topbar-actions form {
            margin: 0;
        }

        .page {
            padding: 32px 0;
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

        .field {
            margin-top: 18px;
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
            background: #ffffff;
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
            background: var(--accent);
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .button:hover {
            background: var(--accent-strong);
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

        .empty {
            padding: 20px;
            color: var(--muted);
        }

        .status-message {
            margin: 20px 0 0;
            color: var(--accent-strong);
            font-weight: 700;
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

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1px;
            background: var(--border);
            border-top: 1px solid var(--border);
        }

        .meta-item {
            background: var(--surface);
            padding: 16px 20px;
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
        .meta-item select {
            width: 100%;
            margin-top: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 11px;
            background: #ffffff;
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
            background: #ffffff;
            color: var(--text);
        }

        .compact-form .button {
            min-height: 38px;
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

        .readiness-status[data-status="manual"] {
            background: #fff8e4;
            border-color: #ead18b;
            color: #72540c;
        }

        .readiness-action {
            color: var(--text);
            font-weight: 650;
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

        .cobrowse-preview-frame {
            background: var(--surface-muted);
            border-top: 1px solid var(--border);
            padding: 16px;
        }

        .cobrowse-preview {
            display: block;
            width: 100%;
            min-height: 360px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #ffffff;
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
            background: #fff8e4;
        }

        .live-update[data-state="exhausted"] {
            background: #fff8e4;
        }

        .live-update[data-state="expired"] {
            background: color-mix(in srgb, var(--danger) 7%, var(--surface));
        }

        [hidden] {
            display: none !important;
        }

        .message-list {
            display: grid;
            gap: 14px;
            padding: 20px;
        }

        .message-card,
        .message {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 14px;
        }

        .message-card.agent-message,
        .message.agent {
            background: var(--surface-muted);
        }

        .message.grouped {
            margin-top: -8px;
        }

        .message.grouped .message-meta {
            justify-content: flex-end;
        }

        .empty-state {
            color: var(--muted);
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

        .reply-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(260px, 340px);
            gap: 1px;
            background: var(--border);
            border-top: 1px solid var(--border);
        }

        .reply-workspace .section-form {
            background: var(--surface);
        }

        .reply-context-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1px;
            overflow: hidden;
            margin-bottom: 18px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--border);
        }

        .reply-context-item {
            min-width: 0;
            padding: 12px;
            background: var(--surface-muted);
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
            border-color: #b8860b;
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
                padding: 16px 0;
            }

            .app-nav,
            .topbar-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 640px) {
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
            $navigationItems = [
                [
                    'label' => 'Dashboard',
                    'href' => route('dashboard'),
                    'active' => request()->routeIs('dashboard'),
                ],
                [
                    'label' => 'Conversations',
                    'href' => route('dashboard.conversations.index'),
                    'active' => request()->routeIs('dashboard.conversations.*'),
                ],
                [
                    'label' => 'Tickets',
                    'href' => route('dashboard.tickets.index'),
                    'active' => request()->routeIs('dashboard.tickets.*'),
                ],
                [
                    'label' => 'Sites',
                    'href' => route('dashboard.sites.index'),
                    'active' => request()->routeIs('dashboard.sites.*'),
                ],
                [
                    'label' => 'Account',
                    'href' => route('dashboard.account.show'),
                    'active' => request()->routeIs('dashboard.account.*'),
                ],
            ];

            if ($agent->isAdmin()) {
                array_splice($navigationItems, 4, 0, [[
                    'label' => 'Readiness',
                    'href' => route('dashboard.readiness.show'),
                    'active' => request()->routeIs('dashboard.readiness.*'),
                ]]);
            }
        @endphp

        <div class="shell">
            <header class="topbar">
                <div class="topbar-inner">
                    <div class="topbar-main">
                        <a class="brand-link" href="{{ route('dashboard') }}">Wayfindr</a>
                        <div class="lede topbar-context">{{ $agent->name }} - {{ $account->name }}</div>
                    </div>

                    <nav class="app-nav" aria-label="Primary navigation">
                        @foreach ($navigationItems as $navigationItem)
                            <a
                                class="app-nav-link"
                                href="{{ $navigationItem['href'] }}"
                                @if ($navigationItem['active']) aria-current="page" @endif
                            >
                                {{ $navigationItem['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="topbar-actions">
                        <form class="compact-form support-lookup-form" method="GET" action="{{ route('dashboard.support-code.lookup') }}" aria-label="Find support trail">
                            <label class="sr-only" for="shell_support_code">Support code, ticket, or visitor ID</label>
                            <input id="shell_support_code" name="support_code" type="search" placeholder="Support code or ticket" autocomplete="off">
                            <button class="button secondary" type="submit">Find</button>
                        </form>
                        <a class="button secondary" href="{{ route('dashboard.profile.show') }}">Profile</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="button secondary" type="submit">Sign out</button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="page">
                {{ $slot }}
            </main>
        </div>
    @else
        {{ $slot }}
    @endif
</body>
</html>
