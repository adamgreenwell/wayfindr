<script>
    (function () {
        var keys = Object.freeze({
            next: 'Alt+J',
            previous: 'Alt+K',
            open: 'Alt+O',
            claim: 'Alt+A',
            reply: 'Alt+R',
            close: 'Alt+X',
            search: 'Alt+/',
            palette: 'Alt+P',
            reference: '?'
        });
        var actionsByKey = Object.freeze({
            j: 'next',
            k: 'previous',
            o: 'open',
            a: 'claim',
            r: 'reply',
            x: 'close',
            '/': 'search',
            p: 'palette'
        });
        var macOptionActions = Object.freeze({
            '∆': 'next',
            '˚': 'previous',
            'ø': 'open',
            'å': 'claim',
            '®': 'reply',
            '≈': 'close',
            '÷': 'search',
            'π': 'palette'
        });
        var queue = document.querySelector('[data-agent-shortcut-queue]');
        var rows = queue ? Array.from(queue.querySelectorAll('[data-agent-shortcut-row]')) : [];
        var activeIndex = -1;
        var layoutMap = null;

        if (navigator.keyboard && typeof navigator.keyboard.getLayoutMap === 'function') {
            navigator.keyboard.getLayoutMap()
                .then(function (map) {
                    layoutMap = map;
                })
                .catch(function () {
                    // The character reported by the event remains the safe fallback.
                });
        }

        function markActiveRow(index) {
            rows.forEach(function (row, rowIndex) {
                row.toggleAttribute('data-shortcut-active', rowIndex === index);
            });
            activeIndex = index;
        }

        function actionTarget(action) {
            if (action === 'palette') {
                return document.querySelector('[data-command-palette-open]');
            }

            if (action === 'reference') {
                return document.querySelector('[data-agent-shortcut-reference]');
            }

            if (action === 'search') {
                return document.querySelector('[data-agent-shortcut-search-primary]')
                    || document.querySelector('[data-agent-shortcut-search]');
            }

            return document.querySelector('[data-agent-shortcut-' + action + ']');
        }

        function available(action) {
            if (action === 'next' || action === 'previous') {
                return rows.length > 0 || Boolean(actionTarget(action));
            }

            if (action === 'open') {
                return rows.some(function (row) {
                    return Boolean(row.querySelector('[data-agent-shortcut-open]'));
                }) || Boolean(actionTarget(action));
            }

            return Boolean(actionTarget(action));
        }

        function activateRow(index) {
            if (index < 0 || index >= rows.length) {
                return false;
            }

            markActiveRow(index);

            var link = rows[index].querySelector('[data-agent-shortcut-open]');

            if (! link) {
                return false;
            }

            link.focus({ preventScroll: true });
            rows[index].scrollIntoView({ block: 'nearest' });

            return true;
        }

        function move(direction) {
            if (rows.length > 0) {
                var nextIndex = activeIndex === -1
                    ? (direction > 0 ? 0 : rows.length - 1)
                    : activeIndex + direction;

                return activateRow(nextIndex);
            }

            var target = actionTarget(direction > 0 ? 'next' : 'previous');

            if (! target || ! target.href) {
                return false;
            }

            window.location.assign(target.href);

            return true;
        }

        function focusTarget(action) {
            var target = actionTarget(action);

            if (! target || typeof target.focus !== 'function') {
                return false;
            }

            var hiddenPanel = target.closest('[role="tabpanel"][hidden]');

            if (hiddenPanel && hiddenPanel.id) {
                var tab = document.querySelector('[role="tab"][aria-controls="' + hiddenPanel.id + '"]');

                if (tab) {
                    tab.click();
                }
            }

            target.focus({ preventScroll: true });
            target.scrollIntoView({ block: 'center' });

            if (action === 'search' && typeof target.select === 'function') {
                target.select();
            }

            return true;
        }

        function clickTarget(action) {
            var target = action === 'open' && activeIndex >= 0
                ? rows[activeIndex].querySelector('[data-agent-shortcut-open]')
                : actionTarget(action);

            if (! target || typeof target.click !== 'function') {
                return false;
            }

            if (action === 'claim' && target.dataset.agentShortcutValue && target.form) {
                var assignee = target.form.elements.namedItem('assignee_id');

                if (assignee) {
                    assignee.value = target.dataset.agentShortcutValue;
                }
            }

            target.click();

            return true;
        }

        function run(action) {
            if (action === 'reference') {
                if (! available(action)) {
                    return false;
                }

                document.dispatchEvent(new CustomEvent('wayfindr:agent-shortcut-reference-open'));

                return true;
            }

            if (action === 'next') {
                return move(1);
            }

            if (action === 'previous') {
                return move(-1);
            }

            if (action === 'reply' || action === 'search') {
                return focusTarget(action);
            }

            return clickTarget(action);
        }

        function eventOwnsText(event) {
            var target = event.target;

            return target instanceof Element
                && (target.matches('input, textarea, select')
                    || target.isContentEditable
                    || target.closest('[role="dialog"], [aria-modal="true"]'));
        }

        function actionForEvent(event) {
            var eventKey = typeof event.key === 'string' ? event.key.toLocaleLowerCase('en-US') : '';
            var action = actionsByKey[eventKey];
            var isPlainLayoutCharacter = /^[a-z0-9/]$/.test(eventKey);

            if (! action && ! isPlainLayoutCharacter && layoutMap && typeof event.code === 'string') {
                var layoutKey = layoutMap.get(event.code);

                if (typeof layoutKey === 'string') {
                    action = actionsByKey[layoutKey.toLocaleLowerCase('en-US')];
                }
            }

            return action || macOptionActions[event.key];
        }

        if (queue) {
            queue.addEventListener('focusin', function (event) {
                var row = event.target instanceof Element
                    ? event.target.closest('[data-agent-shortcut-row]')
                    : null;
                var index = rows.indexOf(row);

                if (index >= 0) {
                    markActiveRow(index);
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.defaultPrevented
                || event.repeat
                || event.isComposing
                || eventOwnsText(event)) {
                return;
            }

            var action = null;

            var isShortcutReferenceKey = event.key === '?'
                || (event.key === '/' && event.shiftKey);

            if (! event.ctrlKey && ! event.metaKey && ! event.altKey && isShortcutReferenceKey) {
                action = 'reference';
            } else {
                if (event.ctrlKey || event.metaKey || ! event.altKey) {
                    return;
                }

                action = actionForEvent(event);

                if (event.shiftKey && action !== 'search') {
                    return;
                }
            }

            if (action && run(action)) {
                event.preventDefault();
            }
        });

        window.WayfindrAgentShortcuts = Object.freeze({
            keys: keys,
            available: available,
            run: run
        });
        document.dispatchEvent(new CustomEvent('wayfindr:agent-shortcuts-ready'));
    })();
</script>
