<?php

/**
 * How recently a visitor was seen.
 *
 * Its own catalogue because it is shared vocabulary rather than one page's
 * copy: the conversation queue names these states in a filter AND on every row,
 * and the visitors directory will name them again when it is extracted. Held in
 * two places they would drift the first time a translator improved one of them,
 * and the queue would show two different words for the same state on the same
 * screen.
 *
 * **Only extracted surfaces read it.** `Visitor::presenceLabel()` deliberately
 * returns English and answers with `presenceState()` instead, so the visitors
 * directory -- which has not been extracted -- is unaffected by this file
 * existing. A catalogue is reached by a surface translating a state, never by a
 * model handing one out.
 */
return [
    'any' => 'Any presence',
    'active' => 'Active recently',
    'recent' => 'Recently active',
    'quiet' => 'Quiet',
    'not_reported' => 'Not reported',
];
