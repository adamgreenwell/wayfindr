<?php

declare(strict_types=1);

use App\Support\Ai\Evaluation\GroundedAnswerPhraseMatcher;

test('phrase matching preserves accents and whole-word boundaries', function (): void {
    $matcher = new GroundedAnswerPhraseMatcher;

    expect($matcher->containsPhrase($matcher->normalize('Der Link bleibt GÜLTIG.'), "gu\u{0308}ltig"))->toBeTrue()
        ->and($matcher->containsPhrase($matcher->normalize('Der Link ist ungültig.'), 'gültig'))->toBeFalse()
        ->and($matcher->containsPhrase($matcher->normalize('Die Gültigkeit endet.'), 'gültig'))->toBeFalse()
        ->and($matcher->containsPhrase($matcher->normalize('Der Link bleibt gultig.'), 'gültig'))->toBeFalse();
});

test('combining marks that cannot compose remain part of their word', function (): void {
    $matcher = new GroundedAnswerPhraseMatcher;

    expect($matcher->normalize("a\u{20DD}bc"))->toBe("a\u{20DD}bc")
        ->and($matcher->containsPhrase($matcher->normalize("a\u{20DD}bc"), 'bc'))->toBeFalse();
});

test('invalid Unicode is rejected without echoing the phrase', function (): void {
    expect(fn (): string => (new GroundedAnswerPhraseMatcher)->normalize("private\xFFvalue"))
        ->toThrow(RuntimeException::class, 'Evaluation phrase normalization requires valid Unicode.');
});
