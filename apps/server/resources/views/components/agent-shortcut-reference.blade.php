@php
    $generalShortcuts = [
        'palette' => __('nav.shortcuts.actions.palette'),
        'reference' => __('nav.shortcuts.actions.reference'),
    ];
    $pageShortcuts = [
        'next' => __('nav.commands.actions.next'),
        'previous' => __('nav.commands.actions.previous'),
        'open' => __('nav.commands.actions.open'),
        'claim' => __('nav.commands.actions.claim'),
        'reply' => __('nav.commands.actions.reply'),
        'close' => __('nav.commands.actions.close'),
        'search' => __('nav.commands.actions.search'),
    ];
@endphp

<dialog
    class="wf-command-dialog wf-shortcut-dialog"
    aria-labelledby="agent-shortcut-reference-title"
    aria-describedby="agent-shortcut-reference-description"
    aria-modal="true"
    data-agent-shortcut-reference
>
    <div class="wf-command-header">
        <div>
            <p class="meta-label">{{ __('nav.shortcuts.eyebrow') }}</p>
            <h2 id="agent-shortcut-reference-title">{{ __('nav.shortcuts.title') }}</h2>
        </div>
        <button class="wf-command-dismiss" type="button" aria-label="{{ __('nav.shortcuts.dismiss') }}" data-shortcut-reference-close>&times;</button>
    </div>

    <div class="wf-shortcut-content">
        <p class="wf-shortcut-description" id="agent-shortcut-reference-description">{{ __('nav.shortcuts.description') }}</p>

        <section class="wf-shortcut-group" aria-labelledby="agent-shortcut-general" data-shortcut-group>
            <h3 id="agent-shortcut-general">{{ __('nav.shortcuts.groups.general') }}</h3>
            <dl class="wf-shortcut-list">
                @foreach ($generalShortcuts as $action => $label)
                    <div class="wf-shortcut-row" data-shortcut-row data-shortcut-action="{{ $action }}">
                        <dt>{{ $label }}</dt>
                        <dd><kbd data-shortcut-key></kbd></dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="wf-shortcut-group" aria-labelledby="agent-shortcut-page" data-shortcut-group>
            <h3 id="agent-shortcut-page">{{ __('nav.shortcuts.groups.page') }}</h3>
            <dl class="wf-shortcut-list">
                @foreach ($pageShortcuts as $action => $label)
                    <div class="wf-shortcut-row" data-shortcut-row data-shortcut-action="{{ $action }}">
                        <dt>{{ $label }}</dt>
                        <dd><kbd data-shortcut-key></kbd></dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>
</dialog>

<script>
    (function () {
        var dialog = document.querySelector('[data-agent-shortcut-reference]');
        var closeButton = dialog ? dialog.querySelector('[data-shortcut-reference-close]') : null;
        var opener = null;

        if (! dialog || ! closeButton) {
            return;
        }

        function shortcutRows() {
            return Array.from(dialog.querySelectorAll('[data-shortcut-row]'));
        }

        function syncShortcutRows() {
            var registry = window.WayfindrAgentShortcuts;

            if (! registry) {
                return;
            }

            shortcutRows().forEach(function (row) {
                var action = row.dataset.shortcutAction;
                var shortcut = registry.keys[action] || '';
                var key = row.querySelector('[data-shortcut-key]');

                row.hidden = ! shortcut || ! registry.available(action);
                key.textContent = shortcut;
            });

            dialog.querySelectorAll('[data-shortcut-group]').forEach(function (group) {
                group.hidden = ! group.querySelector('[data-shortcut-row]:not([hidden])');
            });
        }

        function openReference() {
            if (dialog.open) {
                return;
            }

            opener = document.activeElement;
            syncShortcutRows();
            dialog.showModal();
            closeButton.focus();
        }

        function closeReference() {
            dialog.close();
        }

        closeButton.addEventListener('click', closeReference);

        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeReference();
        });

        dialog.addEventListener('close', function () {
            if (opener && document.contains(opener) && typeof opener.focus === 'function') {
                opener.focus();
            }
        });

        document.addEventListener('wayfindr:agent-shortcuts-ready', syncShortcutRows);
        document.addEventListener('wayfindr:agent-shortcut-reference-open', openReference);
        syncShortcutRows();
    })();
</script>
