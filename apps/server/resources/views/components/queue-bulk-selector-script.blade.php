@once
    <script>
        document.querySelectorAll('[data-queue-bulk-form]').forEach(function (form) {
            var boxes = Array.from(form.querySelectorAll('[data-queue-select]'));
            var selectAll = form.querySelector('[data-queue-select-all]');
            var count = form.querySelector('[data-queue-selected-count]');
            var action = form.querySelector('[data-queue-bulk-action]');
            var values = Array.from(form.querySelectorAll('[data-queue-bulk-value]'));
            var valueLabels = Array.from(form.querySelectorAll('[data-queue-bulk-value-label]'));
            var review = form.querySelector('[data-queue-bulk-review]');
            var clear = form.querySelector('[data-queue-bulk-clear]');
            var noValueActions = (form.dataset.queueBulkNoValueActions || '').split(',').filter(Boolean);
            var lastBox = null;

            function activeValue() {
                return values.find(function (select) {
                    return ! select.disabled;
                });
            }

            function update() {
                var selected = boxes.filter(function (box) { return box.checked; });
                var number = selected.length;
                var value = activeValue();
                var actionReady = action.value !== ''
                    && (noValueActions.includes(action.value) || (value && value.value !== ''));

                count.textContent = number === 0
                    ? count.dataset.none
                    : (number === 1
                        ? count.dataset.one
                        : count.dataset.many.replace('__COUNT__', String(number)));
                selectAll.checked = number === boxes.length && boxes.length > 0;
                selectAll.indeterminate = number > 0 && number < boxes.length;
                review.disabled = number === 0 || ! actionReady;
                clear.disabled = number === 0;

                boxes.forEach(function (box) {
                    box.closest('[data-queue-bulk-row]').toggleAttribute('data-selected', box.checked);
                });
            }

            action.addEventListener('change', function () {
                values.forEach(function (select) {
                    var active = select.dataset.queueBulkValue === action.value;
                    select.disabled = ! active;
                    select.hidden = ! active;
                });
                valueLabels.forEach(function (label) {
                    label.hidden = label.dataset.queueBulkValueLabel !== action.value;
                });
                update();
            });

            values.forEach(function (select) {
                select.addEventListener('change', update);
            });

            boxes.forEach(function (box, index) {
                box.addEventListener('click', function (event) {
                    if (event.shiftKey && lastBox) {
                        var lastIndex = boxes.indexOf(lastBox);
                        var from = Math.min(lastIndex, index);
                        var to = Math.max(lastIndex, index);

                        boxes.slice(from, to + 1).forEach(function (rangeBox) {
                            rangeBox.checked = box.checked;
                        });
                    }

                    lastBox = box;
                    update();
                });
            });

            selectAll.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = selectAll.checked; });
                lastBox = null;
                update();
            });

            clear.addEventListener('click', function () {
                boxes.forEach(function (box) { box.checked = false; });
                lastBox = null;
                update();
            });

            update();
        });
    </script>
@endonce
