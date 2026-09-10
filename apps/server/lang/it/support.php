<?php

/*
 * Drafted from lang/en/support.php.
 * Reviewed per docs/product/translation-policy.md section 9.
 *
 * Machine output against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md. Every value here
 * started as a proposal: the pipeline optimises for a diff somebody can
 * check, not for a translation nobody has to.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * No plural strings in this catalogue.
 * /
 */
return [
    'copy' => 'Copia',
    'copied' => 'Copiato',
    'copy_code' => 'Copia codice di supporto',
    'copy_code_for' => 'Copia il codice di supporto :code',
    'open_record' => 'Apri il record di supporto :code',
];
