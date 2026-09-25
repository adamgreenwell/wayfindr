<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Every dashboard page scrolled sideways on a phone (#1038). Measured at a
 * 375px viewport with a real browser, 141 of 141 page renders across en, de
 * and it were wider than the screen -- 478px everywhere, 1,263-1,631px on the
 * account overview. The issue blamed bare tables, but every table already sat
 * in a scrolling .table-wrap; the width came from five things beside them.
 *
 * What these tests can see is that each rule is in the rendered stylesheet
 * and that the markup it exists for is on the page. What they cannot see is
 * the width itself: that scrollWidth equals clientWidth at 375px is a layout
 * result, and only a browser measurement proves it.
 */

/**
 * The layout's stylesheet as rules: media condition, selector list, and the
 * declarations in force (last one wins). Comments are stripped first -- several
 * quote CSS, braces included.
 *
 * @return list<array{media: ?string, selectors: list<string>, declarations: array<string, string>}>
 */
function phoneWidthPageOverflowRules(string $html): array
{
    preg_match_all('#<style>(.*?)</style>#s', $html, $blocks);
    $css = preg_replace('#/\*.*?\*/#s', '', implode("\n", $blocks[1]));

    $rules = [];
    $walk = function (string $css, ?string $media) use (&$walk, &$rules): void {
        $position = 0;
        $length = strlen($css);

        while ($position < $length) {
            $open = strpos($css, '{', $position);

            if ($open === false) {
                return;
            }

            $prelude = trim(substr($css, $position, $open - $position));
            $depth = 1;
            $cursor = $open + 1;

            while ($depth > 0 && $cursor < $length) {
                $depth += match ($css[$cursor]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $cursor++;
            }

            $body = substr($css, $open + 1, $cursor - $open - 2);

            if (str_starts_with($prelude, '@media')) {
                $walk($body, $prelude);
            } elseif (! str_starts_with($prelude, '@')) {
                $declarations = [];

                foreach (explode(';', $body) as $declaration) {
                    if (str_contains($declaration, ':')) {
                        [$property, $value] = explode(':', $declaration, 2);
                        $declarations[strtolower(trim($property))] = trim($value);
                    }
                }

                $rules[] = [
                    'media' => $media,
                    'selectors' => array_map(fn (string $selector): string => preg_replace('/\s+/', ' ', trim($selector)), explode(',', $prelude)),
                    'declarations' => $declarations,
                ];
            }

            $position = $cursor;
        }
    };
    $walk($css, null);

    return $rules;
}

/**
 * The value a property takes from rules naming this selector, under this media
 * condition (null for none), in source order -- so a later rule wins.
 */
function phoneWidthPageOverflowValue(array $rules, string $selector, string $property, ?string $media = null): ?string
{
    $value = null;

    foreach ($rules as $rule) {
        $mediaMatches = $media === null ? $rule['media'] === null : str_contains((string) $rule['media'], $media);

        if ($mediaMatches && in_array($selector, $rule['selectors'], true) && array_key_exists($property, $rule['declarations'])) {
            $value = $rule['declarations'][$property];
        }
    }

    return $value;
}

function phoneWidthPageOverflowXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    return new DOMXPath($document);
}

function phoneWidthPageOverflowClass(string $class): string
{
    return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
}

function phoneWidthPageOverflowOwner(): User
{
    $account = Account::factory()->create(['name' => 'Narrow Desk']);

    return User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Nia Owner',
    ]);
}

test('the collapsed rail contains its hidden link labels', function (): void {
    $html = (string) $this->actingAs(phoneWidthPageOverflowOwner())
        ->get(route('dashboard.profile.show'))->assertOk()->getContent();
    $rules = phoneWidthPageOverflowRules($html);

    // What escaped: each label is hidden with position: absolute below 900px.
    expect(phoneWidthPageOverflowValue($rules, '.wf-nav-link span', 'position', '(max-width: 900px)'))
        ->toBe('absolute', 'the rail labels are no longer absolutely positioned; this guard is checking nothing')
        ->and(phoneWidthPageOverflowValue($rules, '.wf-rail', 'overflow-x', '(max-width: 900px)'))
        ->toBe('auto', 'the collapsed rail no longer scrolls its own row');

    expect(phoneWidthPageOverflowValue($rules, '.wf-rail', 'position', '(max-width: 900px)'))
        ->toBe('relative', 'the collapsed rail is not positioned, so its hidden labels resolve against the page, escape its overflow, and widen every page on a phone');
});

test('a table wrapper contains the hidden labels in its cells', function (): void {
    $owner = phoneWidthPageOverflowOwner();
    User::factory()->for($owner->account)->create(['account_role' => AccountRole::Agent, 'name' => 'Ari Agent']);

    $html = (string) $this->actingAs($owner)
        ->get(route('dashboard.account.show'))->assertOk()->getContent();
    $labels = phoneWidthPageOverflowXpath($html)->query(
        '//div['.phoneWidthPageOverflowClass('table-wrap').']//td//label['.phoneWidthPageOverflowClass('sr-only').']'
    );
    $rules = phoneWidthPageOverflowRules($html);

    expect($labels->length)->toBeGreaterThan(0, 'no visually hidden label rendered inside a table wrapper; this guard is checking nothing')
        ->and(phoneWidthPageOverflowValue($rules, '.sr-only', 'position'))
        ->toBe('absolute', '.sr-only is no longer absolutely positioned; this guard is checking nothing');

    expect(phoneWidthPageOverflowValue($rules, '.table-wrap', 'overflow-x'))->toBe('auto', 'tables no longer scroll inside their wrapper')
        ->and(phoneWidthPageOverflowValue($rules, '.table-wrap', 'position'))
        ->toBe('relative', 'the table wrapper is not positioned, so a hidden label in a scrolled cell escapes it and widens the page to the table');
});

test('a next-step note on the home page wraps instead of setting the row width', function (): void {
    $owner = phoneWidthPageOverflowOwner();
    $site = Site::factory()->for($owner->account)->create(['name' => 'Narrow Docs']);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-NARROW1']);
    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Is anyone there?',
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    $html = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
    $notes = phoneWidthPageOverflowXpath($html)->query(
        '//a['.phoneWidthPageOverflowClass('management-link').']//span['.phoneWidthPageOverflowClass('table-note').']'
    );
    $rules = phoneWidthPageOverflowRules($html);

    expect($notes->length)->toBeGreaterThan(0, 'no next-step note rendered in a management row; this guard is checking nothing')
        ->and(phoneWidthPageOverflowValue($rules, '.table-note', 'white-space'))
        ->toBe('nowrap', '.table-note is no longer nowrap; this guard is checking nothing');

    expect(phoneWidthPageOverflowValue($rules, '.management-link .table-note', 'white-space'))
        ->toBe('normal', 'a management row keeps .table-note nowrap, so its longest sentence sets the row width and the page scrolls sideways');
});

test('a readiness row breaks a long word in its text, not in what sits beside it', function (): void {
    $owner = phoneWidthPageOverflowOwner();
    $owner->forceFill(['platform_role' => PlatformRole::Operator])->save();

    $html = (string) $this->actingAs($owner)->get(route('operator.onboarding'))->assertOk()->getContent();
    $rows = phoneWidthPageOverflowXpath($html)->query(
        '//div['.phoneWidthPageOverflowClass('readiness-check-main').'][*[1][self::div]][span['.phoneWidthPageOverflowClass('readiness-status').']]'
    );
    $rules = phoneWidthPageOverflowRules($html);

    expect($rows->length)->toBeGreaterThan(0, 'no readiness row with a text column and a status chip rendered; this guard is checking nothing')
        ->and(phoneWidthPageOverflowValue($rules, '.readiness-status', 'white-space'))
        ->toBe('nowrap', 'the status chip is no longer nowrap; this guard is checking nothing');

    expect(phoneWidthPageOverflowValue($rules, '.readiness-check-main > :first-child', 'overflow-wrap'))
        ->toBe('anywhere', 'a readiness row cannot break a word longer than its text column, so a German label beside a status chip pushes the page sideways');

    // On the row it is inherited by the button that takes the chip's slot on
    // onboarding, which then shrinks and wraps its label at desktop widths.
    expect(phoneWidthPageOverflowValue($rules, '.readiness-check-main', 'overflow-wrap'))
        ->toBeNull('overflow-wrap is set on the whole readiness row, so the button beside the text inherits it and shrinks');
});

test('a generated value in a notice breaks instead of widening the page', function (): void {
    $owner = phoneWidthPageOverflowOwner();
    ExternalIssueProviderConnection::factory()->for($owner->account)->create(['provider' => 'github']);

    $html = (string) $this->actingAs($owner)
        ->get(route('dashboard.account.integrations'))->assertOk()->getContent();
    $urls = phoneWidthPageOverflowXpath($html)->query(
        '//div['.phoneWidthPageOverflowClass('notice-copy').']/p/code[contains(., "/integrations/github/webhook/")]'
    );
    $rules = phoneWidthPageOverflowRules($html);

    expect($urls->length)->toBe(1, 'the generated webhook URL did not render in a notice line; this guard is checking nothing');

    foreach (['.notice-copy > p', '.notice-copy li', '.notice-list > p'] as $line) {
        expect(phoneWidthPageOverflowValue($rules, $line, 'overflow-wrap'))
            ->toBe('anywhere', "`{$line}` cannot break a run with no spaces, so a webhook URL, shown-once secret or setting widens the page to its length");
    }
});
