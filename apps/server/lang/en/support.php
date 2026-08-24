<?php

/**
 * The support-code reference control, shared by every queue and detail page.
 *
 * A shared VIEW component can use the catalogue directly, unlike a shared model
 * or support class: a view is only ever rendered inside a request, and the
 * locale is scoped per request to surfaces that have been extracted. So this
 * renders German on the conversation queue and English on the ticket queue
 * beside it, which is exactly right while the extraction is half done.
 */
return [
    'copy' => 'Copy',
    'copied' => 'Copied',
    'copy_code' => 'Copy support code',
    'copy_code_for' => 'Copy support code :code',
    'open_record' => 'Open support record :code',
];
