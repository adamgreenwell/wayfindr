<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\WidgetAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The widget's accent was a fixed Wayfindr teal in 22 rules while the site's
 * own colour painted a single 3px border. This is what an operator may set --
 * and it is deliberately NOT the ADR 0014 site colour, which exists so an agent
 * can tell sites apart in a queue rather than so a visitor recognises a brand.
 */
function appearanceFor(array $config): WidgetAppearance
{
    return WidgetAppearance::for(new Site(['settings' => ['appearance' => $config]]));
}

test('a site that configures nothing looks exactly as it always did', function (): void {
    $appearance = WidgetAppearance::for(new Site(['settings' => []]));

    expect($appearance->accent)->toBeNull()
        ->and($appearance->accentLight)->toBeNull()
        ->and($appearance->greeting)->toBeNull()
        ->and($appearance->position)->toBe('right');
});

test('one brand colour becomes a rendering per theme', function (): void {
    // Wayfindr does this for its own accent -- #0D6F68 light, #3FA69D dark --
    // and an operator gets one field, not two.
    $appearance = appearanceFor(['accent' => '#7C3AED']);

    expect($appearance->accentLight)->toBe('#7C3AED', 'already clear on white, so untouched')
        ->and($appearance->accentDark)->not->toBe($appearance->accentLight)
        ->and($appearance->accentDark)->toStartWith('#');
});

test('a colour too pale for one theme is adjusted for that theme only', function (): void {
    $appearance = appearanceFor(['accent' => '#FFFF00']);

    // Yellow is invisible on white and perfect on near-black.
    expect($appearance->accentDark)->toBe('#FFFF00')
        ->and($appearance->accentLight)->not->toBe('#FFFF00');
});

test('an adjusted colour stays the same hue, rather than becoming a default', function (): void {
    // A deep navy on a dark panel should become a lighter NAVY. Replacing it
    // with a stock blue would be a widget that ignores what it was told.
    $appearance = appearanceFor(['accent' => '#001F5B']);

    $hue = fn (string $hex) => (function (array $c) {
        [$r, $g, $b] = $c;

        return $b > $r && $b > $g;
    })([hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]);

    expect($hue($appearance->accentDark))->toBeTrue('still blue-dominant after lightening');
});

test('white and black do not divide by zero', function (): void {
    // PHP's `/` returns an INT when the division comes out whole, so
    // hexdec('FF') / 255 is int(1) and a `=== 0.0` guard never fired for a
    // greyscale colour. Both extremes crashed.
    foreach (['#FFFFFF', '#000000', '#808080'] as $hex) {
        $appearance = appearanceFor(['accent' => $hex]);

        expect($appearance->accentLight)->toMatch('/^#[0-9A-F]{6}$/')
            ->and($appearance->accentDark)->toMatch('/^#[0-9A-F]{6}$/');
    }
});

test('the text on an accent is derived, never chosen', function (): void {
    // An operator picking both a colour and the text on it is an operator who
    // can pick an unreadable pair.
    expect(appearanceFor(['accent' => '#000080'])->accentInkLight)->toBe('#FFFFFF')
        ->and(appearanceFor(['accent' => '#FFFF00'])->accentInkDark)->toBe('#141517');
});

test('a brand hex is not refused for being a brand', function (): void {
    // The first version of this rejected #7C3AED, and rejected Wayfindr's own
    // #0D6F68 with it. A form that calls an operator's brand invalid is wrong
    // about the operator, not the colour.
    foreach (['#7C3AED', '#0D6F68', '#FFFF00', '#000000', '7C3AED', '#7C3'] as $hex) {
        expect(WidgetAppearance::accentRejection($hex))->toBeNull("{$hex} should be usable");
    }

    expect(WidgetAppearance::accentRejection('nonsense'))->not->toBeNull();
});

test('shorthand and a missing hash are read as what they obviously mean', function (): void {
    expect(appearanceFor(['accent' => '#7C3'])->accent)->toBe('#77CC33')
        ->and(appearanceFor(['accent' => '7c3aed'])->accent)->toBe('#7C3AED');
});

test('an unknown position falls back rather than breaking the layout', function (): void {
    expect(appearanceFor(['position' => 'left'])->position)->toBe('left')
        ->and(appearanceFor(['position' => 'upside-down'])->position)->toBe('right')
        ->and(appearanceFor(['position' => ['left']])->position)->toBe('right');
});

test('copy is trimmed, bounded, and empty means unset', function (): void {
    expect(appearanceFor(['greeting' => '  Hello there  '])->greeting)->toBe('Hello there')
        ->and(appearanceFor(['greeting' => '   '])->greeting)->toBeNull()
        ->and(mb_strlen(appearanceFor(['placeholder' => str_repeat('x', 400)])->placeholder))->toBe(120);
});

test('an admin sets the appearance and the widget is told about it', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_brand', 'settings' => []]);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.appearance.update', $site), [
            'widget_accent' => '#7C3AED',
            'widget_position' => 'left',
            'widget_greeting' => 'How can we help?',
            'widget_placeholder' => 'Ask us anything',
        ])
        ->assertRedirect();

    $payload = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_brand',
        'anonymous_id' => 'anon-brand',
    ])->assertSuccessful()->json('data.site.appearance');

    expect($payload['accent'])->toBe('#7C3AED')
        ->and($payload['accent_dark'])->not->toBe('#7C3AED', 'rendered for the dark panel too')
        ->and($payload['accent_ink'])->toBe('#FFFFFF')
        ->and($payload['position'])->toBe('left')
        ->and($payload['greeting'])->toBe('How can we help?')
        ->and($payload['placeholder'])->toBe('Ask us anything');
});

test('saving the appearance does not disturb the schedule beside it', function (): void {
    // The mirror of updateAvailability() preserving what it does not own.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => [
        'availability' => ['enabled' => true, 'timezone' => 'UTC', 'weekdays' => ['mon' => ['09:00', '17:00']]],
        'mask_selectors' => ['[data-secret]'],
    ]]);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.appearance.update', $site), ['widget_position' => 'right'])
        ->assertRedirect();

    $settings = $site->fresh()->settings;

    expect($settings['availability']['weekdays']['mon'])->toBe(['09:00', '17:00'])
        ->and($settings['mask_selectors'])->toBe(['[data-secret]'])
        ->and($settings['appearance']['position'])->toBe('right');
});

test('a colour that is not a colour is refused with something actionable', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.appearance.update', $site), [
            'widget_accent' => 'plum',
            'widget_position' => 'right',
        ])
        ->assertSessionHasErrors('widget_accent');

    expect($site->fresh()->settings['appearance'] ?? null)->toBeNull();
});

test('a plain agent cannot restyle what every visitor sees', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($agent)
        ->put(route('dashboard.sites.appearance.update', $site), ['widget_position' => 'left'])
        ->assertForbidden();

    expect($site->fresh()->settings['appearance'] ?? null)->toBeNull();
});

test('the site page offers the form and names both colours apart', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('What the widget looks like')
        ->assertSee('name="widget_accent"', false)
        ->assertSee('Wayfindr default')
        // The distinction is the whole point of having two.
        ->assertSee('how your desk tells sites apart');
});

test('text on the accent actually reaches the floor the class declares', function (): void {
    // MIN_INK_CONTRAST was declared and never enforced: picking whichever of
    // white or near-black contrasts better is not the same as reaching 4.5:1.
    // #777777 tops out at 4.478 against white, and was shipped as-is.
    $contrast = function (string $a, string $b): float {
        $luminance = function (string $hex): float {
            $channels = [];

            foreach ([1, 3, 5] as $offset) {
                $value = (float) (hexdec(substr($hex, $offset, 2)) / 255);
                $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }

            return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
        };

        $one = $luminance($a);
        $two = $luminance($b);

        return $one > $two ? ($one + 0.05) / ($two + 0.05) : ($two + 0.05) / ($one + 0.05);
    };

    // The mid-luminance band, where neither ink is comfortable.
    foreach (['#777777', '#787878', '#7A7A7A', '#808080', '#6E6E6E', '#8A8A8A'] as $hex) {
        $appearance = appearanceFor(['accent' => $hex]);

        expect($contrast($appearance->accentLight, $appearance->accentInkLight))
            ->toBeGreaterThanOrEqual(WidgetAppearance::MIN_INK_CONTRAST, "light text on {$hex}")
            ->and($contrast($appearance->accentDark, $appearance->accentInkDark))
            ->toBeGreaterThanOrEqual(WidgetAppearance::MIN_INK_CONTRAST, "dark text on {$hex}");
    }
});
