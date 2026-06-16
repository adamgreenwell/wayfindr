<script>
    (function () {
        var templatePickers = document.querySelectorAll('[data-template-picker]');
        var forms = document.querySelectorAll('[data-reply-composer]');

        templatePickers.forEach(function (templatePicker) {
            var templateTarget = templatePicker.dataset.target
                ? document.querySelector(templatePicker.dataset.target)
                : null;
            var replyShell = templatePicker.closest('[data-reply-shell]');
            var previewEmpty = replyShell
                ? replyShell.querySelector('[data-template-preview-empty]')
                : null;
            var previewItems = replyShell
                ? replyShell.querySelectorAll('[data-template-preview-item]')
                : [];

            function updateTemplatePreview(templateKey) {
                var hasPreview = false;

                previewItems.forEach(function (previewItem) {
                    var isSelected = previewItem.getAttribute('data-template-preview-item') === templateKey;

                    previewItem.hidden = ! isSelected;
                    hasPreview = hasPreview || isSelected;
                });

                if (previewEmpty) {
                    previewEmpty.hidden = hasPreview;
                }
            }

            templatePicker.addEventListener('change', function () {
                var body = templatePicker.selectedOptions[0]?.dataset.body || '';
                var selectedTemplate = templatePicker.value || '';

                updateTemplatePreview(selectedTemplate);

                if (! body || ! templateTarget) {
                    return;
                }

                templateTarget.value = body;
                templateTarget.focus();
            });
        });

        forms.forEach(function (form) {
            var submit = form.querySelector('[data-reply-submit]');
            var body = form.querySelector('[data-reply-body]');
            var status = form.querySelector('[data-reply-status]');
            var submittingLabel = form.getAttribute('data-submitting-label') || 'Sending...';

            if (body && body.hasAttribute('data-shortcut-submit')) {
                body.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' || (! event.metaKey && ! event.ctrlKey)) {
                        return;
                    }

                    if (form.getAttribute('data-submitting') === 'true' || body.value.trim() === '') {
                        return;
                    }

                    event.preventDefault();

                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submit || undefined);

                        return;
                    }

                    if (submit) {
                        submit.click();
                    }
                });
            }

            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-submitting') === 'true') {
                    event.preventDefault();

                    return;
                }

                form.setAttribute('data-submitting', 'true');
                form.setAttribute('aria-busy', 'true');

                if (submit) {
                    submit.disabled = true;
                    submit.textContent = submittingLabel;
                }

                if (body) {
                    body.readOnly = true;
                }

                if (status) {
                    status.textContent = submittingLabel;
                }
            });
        });
    })();
</script>
