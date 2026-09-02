<?php

/*
 * Shared paginator copy. The framework's stock view builds its result summary
 * from five English-as-key fragments, so an extracted paginated surface cannot
 * translate it without replacing that view. `resources/views/vendor/pagination`
 * keeps the sentence whole and lets each language choose its own word order.
 */
return [
    'navigation' => 'Pagination navigation',
    'previous' => 'Previous',
    'next' => 'Next',
    'summary' => 'Showing :first to :last of :total results',
    'go_to_page' => 'Go to page :page',
];
