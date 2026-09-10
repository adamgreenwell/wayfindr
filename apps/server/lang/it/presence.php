<?php

/*
 * Drafted from lang/en/presence.php.
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
    'any' => 'Qualsiasi presenza',
    'active' => 'Attivo ora',
    'recent' => 'Attivo di recente',
    'quiet' => 'Poco attivo',
    'not_reported' => 'Non segnalato',
];
