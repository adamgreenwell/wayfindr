<?php

use App\Support\Mail\QuotedText;

/**
 * Mail clients quote the whole preceding thread beneath every reply, so a
 * transcript that stores what arrived doubles on each exchange. There is no
 * standard for finding the boundary, so the rule is to cut at the earliest
 * UNAMBIGUOUS one: showing too much is a readability problem, cutting a
 * sentence somebody wrote is a correctness one.
 */
test('the quoted thread is dropped, whichever client produced it', function (string $body, string $expected): void {
    expect(QuotedText::strip($body))->toBe($expected);
})->with([
    'gmail and apple mail' => [
        "Thanks, that worked.\n\nOn Tue, 12 Aug 2026 at 09:14, Support <s@x.test> wrote:\n> Have you tried restarting?",
        'Thanks, that worked.',
    ],
    'outlook original-message rule' => [
        "It is still broken.\n\n-----Original Message-----\nFrom: Support\nDo the thing.",
        'It is still broken.',
    ],
    'outlook header block' => [
        "Still broken.\n\nFrom: Support <s@x.test>\nSent: Tuesday\nTo: Me\nSubject: Re: Order\n\nDo the thing.",
        'Still broken.',
    ],
    'a bare quoted run with no attribution' => [
        "Yes please.\n\n> Shall we refund it?",
        'Yes please.',
    ],
    'a forwarded block' => [
        "Passing this on.\n\n---------- Forwarded message ----------\nFrom: Someone",
        'Passing this on.',
    ],
]);

test('a signature goes with it, but only on the delimiter clients agree on', function (): void {
    expect(QuotedText::strip("Please refund.\n\n--\nAvery Lane\nNorthwind Ltd"))->toBe('Please refund.');

    // NOT a signature: a line of dashes inside a sentence, or a longer rule.
    expect(QuotedText::strip("Two options:\n\n- refund\n- replace"))
        ->toBe("Two options:\n\n- refund\n- replace");
});

test('an ordinary sentence that looks like a boundary is left alone', function (string $body): void {
    // Both loose patterns match real prose. Each case deliberately puts a line
    // BEFORE the suspicious one: without that, dropping a guard cuts the whole
    // message, the all-quoted fallback restores it, and the test passes while
    // the guard is gone. The fallback was masking exactly what this asserts.
    expect(QuotedText::strip($body))->toBe($body);
})->with([
    'an On-date sentence with no attribution following' => [
        "Quick question.\n\nOn Tuesday we shipped the order and it arrived Friday. Can you confirm?",
    ],
    'a From: sentence with no header block following' => [
        "One more thing.\n\nFrom: the warehouse, they said it left on Monday. Is that right?",
    ],
    'prose mentioning the original message' => [
        "Hello again.\n\nI read the original message and it still does not explain the charge.",
    ],
]);

test('a message that is entirely quoted is kept rather than emptied', function (): void {
    // The guess was wrong somewhere. A message that reads oddly beats one that
    // reaches the agent blank.
    $body = "> everything here is quoted\n> and nothing else";

    expect(QuotedText::strip($body))->toBe($body);
});

test('a message with no history at all is untouched', function (): void {
    $body = "Just a plain message.\n\nWith two paragraphs.";

    expect(QuotedText::strip($body))->toBe($body);
});

test('trailing whitespace does not survive the cut', function (): void {
    expect(QuotedText::strip("Thanks.\n\n\n\nOn Tue, someone wrote:\n> hi"))->toBe('Thanks.');
});
