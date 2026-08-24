<?php

/**
 * How recently a visitor was seen.
 *
 * Its own catalogue because it is shared vocabulary rather than one page's
 * copy: the conversation queue names these states in a filter AND on every row,
 * and the visitors directory names them again. Held in two places they would
 * drift the first time a translator improved one of them, and the queue would
 * show two different words for the same state on the same screen.
 *
 * A consequence worth stating: the visitors directory is not extracted yet, so
 * it renders these labels translated while the rest of that page is still
 * English. The alternative was duplicating them, which is worse.
 */
return [
    'any' => 'Any presence',
    'active' => 'Active recently',
    'recent' => 'Recently active',
    'quiet' => 'Quiet',
    'not_reported' => 'Not reported',
];
