@props(['navigationItems'])

@php
    $commandActions = [
        'next' => __('nav.commands.actions.next'),
        'previous' => __('nav.commands.actions.previous'),
        'open' => __('nav.commands.actions.open'),
        'claim' => __('nav.commands.actions.claim'),
        'reply' => __('nav.commands.actions.reply'),
        'close' => __('nav.commands.actions.close'),
        'search' => __('nav.commands.actions.search'),
        'reference' => __('nav.shortcuts.actions.reference'),
    ];
@endphp

<button
    class="wf-command-trigger"
    type="button"
    aria-haspopup="dialog"
    aria-controls="agent-command-palette"
    aria-expanded="false"
    data-command-palette-open
>
    <span class="wf-command-trigger-label">{{ __('nav.commands.open') }}</span>
    <kbd data-command-shortcut-for="palette" hidden></kbd>
</button>

<dialog
    class="wf-command-dialog"
    id="agent-command-palette"
    aria-labelledby="agent-command-palette-title"
    aria-modal="true"
    data-command-palette
>
    <div class="wf-command-header">
        <div>
            <p class="meta-label">{{ __('nav.commands.eyebrow') }}</p>
            <h2 id="agent-command-palette-title">{{ __('nav.commands.title') }}</h2>
        </div>
        <button class="wf-command-dismiss" type="button" aria-label="{{ __('nav.commands.dismiss') }}" data-command-palette-close>&times;</button>
    </div>

    <div class="field wf-command-search">
        <label for="agent-command-query">{{ __('nav.commands.search_label') }}</label>
        <input
            id="agent-command-query"
            type="search"
            placeholder="{{ __('nav.commands.search_placeholder') }}"
            autocomplete="off"
            data-command-query
        >
    </div>

    <div class="wf-command-groups" data-command-groups>
        <section class="wf-command-group" aria-labelledby="agent-command-actions" data-command-group>
            <h3 id="agent-command-actions">{{ __('nav.commands.groups.actions') }}</h3>
            <div class="wf-command-list">
                @foreach ($commandActions as $action => $label)
                    <button
                        class="wf-command-item"
                        type="button"
                        data-command-item
                        data-command-action="{{ $action }}"
                        data-command-label="{{ $label }}"
                    >
                        <span>{{ $label }}</span>
                        <kbd data-command-shortcut-for="{{ $action }}" hidden></kbd>
                    </button>
                @endforeach
            </div>
        </section>

        <section class="wf-command-group" aria-labelledby="agent-command-navigation" data-command-group>
            <h3 id="agent-command-navigation">{{ __('nav.commands.groups.navigation') }}</h3>
            <nav class="wf-command-list" aria-label="{{ __('nav.commands.groups.navigation') }}">
                @foreach ($navigationItems as $item)
                    <a
                        class="wf-command-item"
                        href="{{ $item['href'] }}"
                        data-command-item
                        data-command-label="{{ $item['label'] }}"
                        @if ($item['active']) aria-current="page" @endif
                    >
                        <span>{{ $item['label'] }}</span>
                        @if ($item['active'])
                            <span class="wf-command-current">{{ __('nav.commands.current') }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        </section>
    </div>

    <p class="empty wf-command-empty" data-command-empty hidden>{{ __('nav.commands.empty') }}</p>
</dialog>

<script>
    (function () {
        var dialog = document.querySelector('[data-command-palette]');
        var openButton = document.querySelector('[data-command-palette-open]');
        var closeButton = dialog ? dialog.querySelector('[data-command-palette-close]') : null;
        var query = dialog ? dialog.querySelector('[data-command-query]') : null;
        var empty = dialog ? dialog.querySelector('[data-command-empty]') : null;
        var opener = null;

        if (! dialog || ! openButton || ! closeButton || ! query || ! empty) {
            return;
        }

        function shortcuts() {
            return window.WayfindrAgentShortcuts || null;
        }

        function commandItems() {
            return Array.from(dialog.querySelectorAll('[data-command-item]'));
        }

        function visibleCommandItems() {
            return commandItems().filter(function (item) {
                return ! item.hidden;
            });
        }

        function syncShortcutLabels() {
            var registry = shortcuts();

            if (! registry) {
                return;
            }

            document.querySelectorAll('[data-command-shortcut-for]').forEach(function (label) {
                var shortcut = registry.keys[label.dataset.commandShortcutFor];

                label.textContent = shortcut || '';
                label.hidden = ! shortcut;
            });

            var paletteShortcut = registry.keys.palette;

            if (paletteShortcut) {
                openButton.setAttribute('aria-keyshortcuts', paletteShortcut);
            }
        }

        function filterCommands() {
            var registry = shortcuts();
            var needle = query.value.trim().toLocaleLowerCase();

            commandItems().forEach(function (item) {
                var action = item.dataset.commandAction;
                var available = ! action || (registry && registry.available(action));
                var label = (item.dataset.commandLabel || '').toLocaleLowerCase();
                var shortcut = action && registry ? (registry.keys[action] || '').toLocaleLowerCase() : '';

                item.hidden = ! available || (! label.includes(needle) && ! shortcut.includes(needle));
            });

            dialog.querySelectorAll('[data-command-group]').forEach(function (group) {
                group.hidden = ! group.querySelector('[data-command-item]:not([hidden])');
            });

            empty.hidden = visibleCommandItems().length > 0;
        }

        function openPalette() {
            opener = document.activeElement;
            query.value = '';
            syncShortcutLabels();
            filterCommands();
            dialog.showModal();
            openButton.setAttribute('aria-expanded', 'true');
            query.focus();
        }

        function closePalette() {
            dialog.close();
        }

        function moveFocus(direction) {
            var items = visibleCommandItems();

            if (items.length === 0) {
                return;
            }

            var currentIndex = items.indexOf(document.activeElement);
            var nextIndex = currentIndex === -1
                ? (direction > 0 ? 0 : items.length - 1)
                : (currentIndex + direction + items.length) % items.length;

            items[nextIndex].focus();
        }

        openButton.addEventListener('click', openPalette);
        closeButton.addEventListener('click', closePalette);
        query.addEventListener('input', filterCommands);

        dialog.addEventListener('close', function () {
            openButton.setAttribute('aria-expanded', 'false');

            if (opener && document.contains(opener) && typeof opener.focus === 'function') {
                opener.focus();
            }
        });

        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closePalette();

                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                moveFocus(event.key === 'ArrowDown' ? 1 : -1);
            }

            if (event.key === 'Home' && event.target !== query) {
                event.preventDefault();
                var firstItem = visibleCommandItems()[0];

                if (firstItem) {
                    firstItem.focus();
                }
            }

            if (event.key === 'End' && event.target !== query) {
                event.preventDefault();
                var items = visibleCommandItems();
                var lastItem = items[items.length - 1];

                if (lastItem) {
                    lastItem.focus();
                }
            }
        });

        dialog.querySelectorAll('[data-command-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                var registry = shortcuts();
                var action = button.dataset.commandAction;

                closePalette();

                window.setTimeout(function () {
                    if (registry) {
                        registry.run(action);
                    }
                }, 0);
            });
        });

        document.addEventListener('wayfindr:agent-shortcuts-ready', syncShortcutLabels);
        syncShortcutLabels();
    })();
</script>
