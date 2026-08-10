<?php

declare(strict_types=1);

use App\Support\Release\ActionAdvice;
use App\Support\Release\ActionDisposition;
use App\Support\Release\UpgradeRequirements;

/**
 * The three states, and the consequences every site derives from them.
 *
 * This is the rule that had to be agreed by six sites (#647) and was fixed a
 * subset at a time through #648 and #649. It is asserted here once, on the
 * helper they all now consult, rather than re-derived in each of their tests.
 */
function declaredAction(string $release, string $dependsOn = 'code', string $id = 'thing'): array
{
    return ['release' => $release, 'id' => $id, 'depends_on_release' => $dependsOn];
}

test('an action for the target release is ordinary work', function (): void {
    // Nothing about it depends on a release being left behind, so it is
    // performable once the upgrade completes.
    expect(UpgradeRequirements::disposition(declaredAction('0.3.0'), '0.3.0', '0.2.0'))
        ->toBe(ActionDisposition::Performable);
});

test('an action needing no release code is ordinary work wherever it came from', function (): void {
    // `depends_on_release: none` is the whole point of the field: the work does
    // not need that release's code, so a skipped release strands nothing.
    expect(UpgradeRequirements::disposition(declaredAction('0.2.0', 'none'), '0.3.0', '0.1.0'))
        ->toBe(ActionDisposition::Performable);
});

test('an action for the running release is performable only until the pull', function (): void {
    // The install IS 0.2.0, so 0.2.0's code is present right now and the work can
    // still be done - but only until the pull replaces it.
    expect(UpgradeRequirements::disposition(declaredAction('0.2.0'), '0.3.0', '0.2.0'))
        ->toBe(ActionDisposition::PerformableNow);
});

test('an action for a skipped release is unreachable', function (): void {
    // The upgrade jumps straight past 0.2.0, so its code is never present and no
    // attestation about the work can be true.
    expect(UpgradeRequirements::disposition(declaredAction('0.2.0'), '0.3.0', '0.1.0'))
        ->toBe(ActionDisposition::Unreachable);
});

test('being newer than a release is not evidence of having run it', function (): void {
    // Direct jumps are supported, so a restored 0.4.0 install that originally
    // went 0.1.0 -> 0.4.0 never ran 0.2.0. Ordering must not credit it.
    expect(UpgradeRequirements::disposition(declaredAction('0.2.0'), '0.5.0', '0.4.0'))
        ->toBe(ActionDisposition::Unreachable);
});

test('an unrecorded origin is unreachable, not assumed reached', function (): void {
    // Nothing says the install ever ran it, and the recovery - stop at that
    // release - is real, so the conservative answer costs the operator a step
    // rather than silently dropping a requirement.
    expect(UpgradeRequirements::disposition(declaredAction('0.2.0'), '0.3.0', null))
        ->toBe(ActionDisposition::Unreachable);
});

test('a development origin does not order, so it cannot claim to have reached', function (): void {
    // `reached()` is equality on the canonical version; a development identity is
    // not that release, so it stays conservative.
    expect(UpgradeRequirements::disposition(declaredAction('0.2.0'), '0.3.0', '0.2.0-dev+abc'))
        ->toBe(ActionDisposition::Unreachable);
});

test('only unreachable work refuses an acknowledgement', function (): void {
    expect(ActionDisposition::Performable->acknowledgeable())->toBeTrue()
        // The install ran that release, so the attestation is a claim about the
        // past that could be true.
        ->and(ActionDisposition::PerformableNow->acknowledgeable())->toBeTrue()
        // Offering a key here would document the bypass the refusal warns about.
        ->and(ActionDisposition::Unreachable->acknowledgeable())->toBeFalse();
});

test('anything out of reach of the running code blocks migration whatever its phase', function (): void {
    // The case that makes phase insufficient. An after-start action normally
    // gates SERVING, but one needing a release the pull replaced can never be
    // performed - so letting it through would migrate, then refuse traffic
    // forever on work with no route to completion.
    expect(ActionDisposition::Unreachable->blocksMigration('after-start'))->toBeTrue()
        ->and(ActionDisposition::PerformableNow->blocksMigration('after-start'))->toBeTrue()
        // Ordinary after-start work is exactly what the serving gate is for.
        ->and(ActionDisposition::Performable->blocksMigration('after-start'))->toBeFalse()
        // And the phases that must precede the schema change still do.
        ->and(ActionDisposition::Performable->blocksMigration('before-pull'))->toBeTrue()
        ->and(ActionDisposition::Performable->blocksMigration('after-pull'))->toBeTrue();
});

test('the installer codes are the disposition, not a parallel vocabulary', function (): void {
    // scripts/self-host/install.sh classifies the same three states as STEP, NOW
    // and DO. The values are the contract that lets the differential test compare
    // the two implementations directly - see
    // scripts/test-self-host-classification.sh.
    expect(ActionDisposition::Performable->value)->toBe('DO')
        ->and(ActionDisposition::PerformableNow->value)->toBe('NOW')
        ->and(ActionDisposition::Unreachable->value)->toBe('STEP');
});

test('advice offers a key exactly when one would settle the action', function (): void {
    expect(ActionAdvice::for(declaredAction('0.2.0'), '0.3.0', '0.2.0')->acknowledgeKey)
        ->toBe('0.2.0/thing')
        ->and(ActionAdvice::for(declaredAction('0.2.0'), '0.3.0', '0.1.0')->acknowledgeKey)
        ->toBeNull();
});

test('advice explains itself for any work the pull puts out of reach', function (): void {
    // Both readers need this, not only the one with no key: an operator who did
    // NOT do the work cannot do it now either, so a bare key would leave them
    // with an instruction they cannot follow.
    $reachable = ActionAdvice::for(declaredAction('0.2.0'), '0.3.0', '0.2.0');
    $unreachable = ActionAdvice::for(declaredAction('0.2.0'), '0.3.0', '0.1.0');

    expect($reachable->unreachableLead)->toBe('Cannot be done now.')
        ->and($unreachable->unreachableLead)->toBe('Cannot be done now.')
        // And ordinary work says nothing of the kind.
        ->and(ActionAdvice::for(declaredAction('0.3.0'), '0.3.0', '0.2.0')->unreachableLead)
        ->toBeNull();

    // The remedies differ, and that difference is the whole of #649.
    expect(implode(' ', $reachable->remedyLines))->toContain('acknowledge it with the key above')
        ->and(implode(' ', $unreachable->remedyLines))->toContain('Install that release first')
        ->and(implode(' ', $unreachable->remedyLines))->toContain('Acknowledging will not clear this');
});

test('the key is always rendered before the recovery that refers to it', function (): void {
    // "the key above" has to refer to something already printed. Both message
    // sites render this list verbatim, so the order is structural.
    $lines = ActionAdvice::for(declaredAction('0.2.0'), '0.3.0', '0.2.0')->lines();

    expect($lines[0])->toBe('Acknowledge with: 0.2.0/thing')
        ->and(implode("\n", array_slice($lines, 1)))->toContain('key above');
});

test('unreachable advice renders no key at all', function (): void {
    $lines = ActionAdvice::for(declaredAction('0.2.0'), '0.3.0', '0.1.0')->lines();

    expect(implode("\n", $lines))->not->toContain('Acknowledge with:');
});
