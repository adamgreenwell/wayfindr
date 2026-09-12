<?php

use App\Support\Visitors\VisitorLabel;

test('the first filled candidate wins, in the order given', function (): void {
    expect(VisitorLabel::fromCandidates(['Priya Raman', 'priya@example.test', null, 'anon-1'], 'unknown'))
        ->toBe(['label' => 'Priya Raman', 'is_theirs' => true]);

    expect(VisitorLabel::fromCandidates([null, 'priya@example.test', null, 'anon-1'], 'unknown'))
        ->toBe(['label' => 'priya@example.test', 'is_theirs' => true]);

    expect(VisitorLabel::fromCandidates([null, null, 'customer-123', 'anon-1'], 'unknown'))
        ->toBe(['label' => 'customer-123', 'is_theirs' => true]);
});

test('the literal string zero is a name, not an absence', function (): void {
    // The bug this class exists to remove. `?:` is a truthiness test, so "0" was
    // skipped and a visitor the product accepted could not be named. Widget
    // bootstrap validates anonymous_id as required|string|max:255, so "0" is a
    // value that reaches the database.
    expect(VisitorLabel::fromCandidates([null, null, null, '0'], 'unknown'))
        ->toBe(['label' => '0', 'is_theirs' => true]);

    expect(VisitorLabel::fromCandidates(['0', 'priya@example.test'], 'unknown'))
        ->toBe(['label' => '0', 'is_theirs' => true]);
});

test('blank candidates are skipped, and a fallback is never the visitor own', function (): void {
    // Empty and whitespace-only strings are absences; the fallback is ours, so
    // is_theirs must be false or a view marks English copy with the visitor's
    // language.
    expect(VisitorLabel::fromCandidates([null, '', '   ', null], 'Unknown visitor'))
        ->toBe(['label' => 'Unknown visitor', 'is_theirs' => false]);

    expect(VisitorLabel::fromCandidates([], 'Unknown visitor'))
        ->toBe(['label' => 'Unknown visitor', 'is_theirs' => false]);
});

test('is_theirs cannot disagree with the label it was returned with', function (): void {
    // The property the four hand-written copies each promised in a comment.
    foreach ([
        ['Priya Raman'],
        ['0'],
        [null, 'priya@example.test'],
        [null, null, null, null],
        ['', null],
    ] as $candidates) {
        $result = VisitorLabel::fromCandidates($candidates, 'Unknown visitor');

        expect($result['is_theirs'])->toBe($result['label'] !== 'Unknown visitor');
    }
});
