@props([
    'id' => 'tabs',
    'label' => 'Workspace sections',
    'tabs' => [],
])

<div class="tabs" data-tabs id="{{ $id }}">
    <div class="tabs__list" role="tablist" aria-label="{{ $label }}">
        @foreach ($tabs as $tab)
            <button
                type="button"
                class="tabs__tab"
                role="tab"
                id="tab-{{ $tab['id'] }}"
                aria-controls="tab-panel-{{ $tab['id'] }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                tabindex="{{ $loop->first ? '0' : '-1' }}"
                data-tab="{{ $tab['id'] }}"
            >
                {{ $tab['label'] }}
                @if (! empty($tab['badge']))
                    <span class="tabs__badge">{{ $tab['badge'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{ $slot }}
</div>

@once
    <script>
        (function () {
            function activateTab(container, tabId, focusTab) {
                var buttons = container.querySelectorAll('[role="tab"]');
                var panels = container.querySelectorAll('[data-tab-panel]');

                buttons.forEach(function (button) {
                    var selected = button.dataset.tab === tabId;

                    button.setAttribute('aria-selected', selected ? 'true' : 'false');
                    button.tabIndex = selected ? 0 : -1;

                    if (selected && focusTab) {
                        button.focus();
                    }
                });

                panels.forEach(function (panel) {
                    panel.hidden = panel.dataset.tabPanel !== tabId;
                });

                window.dispatchEvent(new CustomEvent('wayfindr:tab-shown', {
                    detail: { tabs: container.id, panel: tabId },
                }));
            }

            function initTabs(container) {
                var buttons = Array.prototype.slice.call(container.querySelectorAll('[role="tab"]'));

                buttons.forEach(function (button, index) {
                    button.addEventListener('click', function () {
                        activateTab(container, button.dataset.tab, false);
                        // Keep the active tab addressable without scrolling the page.
                        if (window.history && window.history.replaceState) {
                            window.history.replaceState(null, '', '#tab-' + button.dataset.tab);
                        }
                    });

                    button.addEventListener('keydown', function (event) {
                        var targetIndex = null;

                        if (event.key === 'ArrowRight') {
                            targetIndex = (index + 1) % buttons.length;
                        } else if (event.key === 'ArrowLeft') {
                            targetIndex = (index - 1 + buttons.length) % buttons.length;
                        } else if (event.key === 'Home') {
                            targetIndex = 0;
                        } else if (event.key === 'End') {
                            targetIndex = buttons.length - 1;
                        }

                        if (targetIndex !== null) {
                            event.preventDefault();
                            activateTab(container, buttons[targetIndex].dataset.tab, true);
                        }
                    });
                });

                // Deep links: #tab-<id> selects a tab; an anchor to any element
                // inside a panel (old in-page heading links, shared URLs, CTAs
                // that jump between panels) selects the panel containing it,
                // then scrolls to the anchor. Resolved at load AND on every
                // hash change, so same-page links from one panel into another
                // reveal their target instead of pointing at a hidden element.
                function resolveHash() {
                    var hash = window.location.hash.replace(/^#/, '');

                    if (!hash) {
                        return;
                    }

                    var direct = hash.indexOf('tab-') === 0 ? hash.slice(4) : null;

                    if (direct && container.querySelector('[data-tab-panel="' + direct + '"]')) {
                        activateTab(container, direct, false);

                        return;
                    }

                    var anchor = document.getElementById(hash);
                    var panel = anchor ? anchor.closest('[data-tab-panel]') : null;

                    if (panel && container.contains(panel)) {
                        activateTab(container, panel.dataset.tabPanel, false);
                        anchor.scrollIntoView();
                    }
                }

                window.addEventListener('hashchange', resolveHash);
                resolveHash();
            }

            document.querySelectorAll('[data-tabs]').forEach(initTabs);
        })();
    </script>
@endonce
