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
            var typingUrl = form.getAttribute('data-typing-url') || '';
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var typingThrottleMs = 5000;
            var lastTypingSignalAt = 0;

            // Optional attachment controls (only the conversation reply form has
            // these; the ticket composer does not).
            var fileInput = form.querySelector('[data-reply-file-input]');
            var attachButton = form.querySelector('[data-reply-attach]');
            var attachmentsList = form.querySelector('[data-reply-attachments]');
            var attachmentsUrl = form.getAttribute('data-attachments-url') || '';
            var uploadingCount = 0;

            function formatBytes(bytes) {
                bytes = Number(bytes) || 0;

                if (bytes >= 1048576) {
                    return (bytes / 1048576).toFixed(1) + ' MB';
                }

                if (bytes >= 1024) {
                    return Math.round(bytes / 1024) + ' KB';
                }

                return bytes + ' B';
            }

            function hasReadyAttachments() {
                return Boolean(attachmentsList && attachmentsList.querySelector('input[name="attachment_ids[]"]'));
            }

            function refreshSubmitState() {
                if (! submit) {
                    return;
                }

                // Block send while an upload is still in flight so a reply can
                // never go out referencing a half-uploaded file.
                submit.disabled = form.getAttribute('data-submitting') === 'true' || uploadingCount > 0;
            }

            function reportTyping(isTyping, options) {
                options = options || {};

                if (! typingUrl) {
                    return;
                }

                var nowMs = Date.now();

                if (isTyping) {
                    if (! options.force && nowMs - lastTypingSignalAt < typingThrottleMs) {
                        return;
                    }

                    lastTypingSignalAt = nowMs;
                } else {
                    lastTypingSignalAt = 0;
                }

                var payload = new URLSearchParams();
                payload.set('is_typing', isTyping ? '1' : '0');

                fetch(typingUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                    },
                    body: payload.toString(),
                }).catch(function () {
                    // Typing is a disposable hint; reply submission remains the source of truth.
                });
            }

            if (body && body.hasAttribute('data-shortcut-submit')) {
                body.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' || (! event.metaKey && ! event.ctrlKey)) {
                        return;
                    }

                    if (form.getAttribute('data-submitting') === 'true' || uploadingCount > 0) {
                        return;
                    }

                    // A reply needs text or a ready attachment.
                    if (body.value.trim() === '' && ! hasReadyAttachments()) {
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

            if (body) {
                body.addEventListener('input', function () {
                    reportTyping(body.value.trim() !== '');
                });
            }

            function uploadReplyFile(file) {
                var chip = document.createElement('li');
                chip.className = 'reply-attach-chip reply-attach-chip--uploading';

                var nameEl = document.createElement('span');
                nameEl.className = 'reply-attach-chip-name';
                nameEl.textContent = file.name || 'attachment';
                chip.appendChild(nameEl);

                var stateEl = document.createElement('span');
                stateEl.className = 'reply-attach-chip-state';
                stateEl.textContent = 'Uploading…';
                chip.appendChild(stateEl);

                // Release this upload's hold on the in-flight count exactly once,
                // whether it is settled by completion or by the agent removing the
                // chip mid-flight — so send is never left disabled by an orphaned
                // upload.
                var pending = true;
                var removed = false;

                function settleUpload() {
                    if (! pending) {
                        return;
                    }

                    pending = false;
                    uploadingCount -= 1;
                    refreshSubmitState();
                }

                // Free a stored upload so it stops counting against the
                // conversation quota. Best-effort; the retention sweep is the
                // backstop.
                function deleteUploaded(attachmentId) {
                    fetch(attachmentsUrl + '/' + encodeURIComponent(attachmentId), {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                        },
                    }).catch(function () {});
                }

                var removeEl = document.createElement('button');
                removeEl.type = 'button';
                removeEl.className = 'reply-attach-chip-remove';
                removeEl.setAttribute('aria-label', 'Remove ' + (file.name || 'attachment'));
                removeEl.textContent = '×';
                removeEl.addEventListener('click', function () {
                    if (form.getAttribute('data-submitting') === 'true') {
                        return;
                    }

                    removed = true;
                    settleUpload();

                    var hidden = chip.querySelector('input[name="attachment_ids[]"]');

                    if (hidden && hidden.value) {
                        deleteUploaded(hidden.value);
                    }

                    chip.remove();

                    if (attachmentsList.children.length === 0) {
                        attachmentsList.hidden = true;
                    }
                });
                chip.appendChild(removeEl);

                attachmentsList.appendChild(chip);
                attachmentsList.hidden = false;

                uploadingCount += 1;
                refreshSubmitState();

                var payload = new FormData();
                payload.append('file', file);

                fetch(attachmentsUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                    },
                    body: payload,
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                }).then(function (result) {
                    settleUpload();

                    var attachment = result.ok && result.data && result.data.data
                        ? result.data.data.attachment
                        : null;

                    if (removed) {
                        // Removed while uploading — delete the orphan if it landed.
                        if (attachment && attachment.id) {
                            deleteUploaded(attachment.id);
                        }

                        return;
                    }

                    if (! attachment || ! attachment.id) {
                        chip.className = 'reply-attach-chip reply-attach-chip--error';
                        stateEl.textContent = (result.data && result.data.message)
                            ? result.data.message
                            : 'That file could not be attached.';

                        return;
                    }

                    chip.className = 'reply-attach-chip reply-attach-chip--ready';
                    stateEl.textContent = formatBytes(attachment.size_bytes);

                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'attachment_ids[]';
                    hidden.value = attachment.id;
                    chip.appendChild(hidden);
                }).catch(function () {
                    settleUpload();

                    if (removed) {
                        return;
                    }

                    chip.className = 'reply-attach-chip reply-attach-chip--error';
                    stateEl.textContent = 'That file could not be attached.';
                });
            }

            if (fileInput && attachButton && attachmentsList && attachmentsUrl) {
                attachButton.addEventListener('click', function () {
                    if (form.getAttribute('data-submitting') === 'true') {
                        return;
                    }

                    fileInput.click();
                });

                fileInput.addEventListener('change', function () {
                    Array.prototype.slice.call(fileInput.files || []).forEach(uploadReplyFile);
                    // Reset so re-picking the same file fires change again.
                    fileInput.value = '';
                });
            }

            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-submitting') === 'true') {
                    event.preventDefault();

                    return;
                }

                // Don't send while an attachment is still uploading — its hidden
                // attachment_ids[] input isn't in the form yet.
                if (uploadingCount > 0) {
                    event.preventDefault();

                    if (status) {
                        status.textContent = 'Waiting for uploads to finish…';
                    }

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

                reportTyping(false, { force: true });
            });
        });
    })();
</script>
