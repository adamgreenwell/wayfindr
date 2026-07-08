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
            // The active tab survives server round-trips (failed validation
            // redirects, filter links, refreshes): fragments never reach the
            // server, so the client remembers per page + container instead.
            // An agent who submits a form from a panel lands back on that
            // panel with the validation feedback visible.
            function tabStorageKey(container) {
                return 'wayfindr:tabs:' + window.location.pathname + ':' + container.id;
            }

            function rememberTab(container, tabId) {
                try {
                    window.sessionStorage.setItem(tabStorageKey(container), tabId);
                } catch (error) {
                    // Storage may be unavailable (private mode); tabs still work.
                }
            }

            function rememberedTab(container) {
                try {
                    return window.sessionStorage.getItem(tabStorageKey(container));
                } catch (error) {
                    return null;
                }
            }

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

                rememberTab(container, tabId);

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
                        return false;
                    }

                    var direct = hash.indexOf('tab-') === 0 ? hash.slice(4) : null;

                    if (direct && container.querySelector('[data-tab-panel="' + direct + '"]')) {
                        activateTab(container, direct, false);

                        return true;
                    }

                    var anchor = document.getElementById(hash);
                    var panel = anchor ? anchor.closest('[data-tab-panel]') : null;

                    if (panel && container.contains(panel)) {
                        activateTab(container, panel.dataset.tabPanel, false);
                        anchor.scrollIntoView();

                        return true;
                    }

                    return false;
                }

                window.addEventListener('hashchange', resolveHash);

                // Selection priority at load: an explicit hash wins, then the
                // remembered tab for this page, then the server-rendered
                // default. The memory is what carries the active tab across
                // full round-trips — failed validation redirects land the
                // agent back on the panel with the feedback, and filter links
                // that only change the query string keep their tab open.
                if (! resolveHash()) {
                    var remembered = rememberedTab(container);

                    if (remembered && container.querySelector('[data-tab-panel="' + remembered + '"]')) {
                        activateTab(container, remembered, false);
                    }
                }
            }

            document.querySelectorAll('[data-tabs]').forEach(initTabs);
        })();
    </script>
@endonce
