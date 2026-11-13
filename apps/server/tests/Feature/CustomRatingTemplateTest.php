<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\Site;
use App\Models\SiteRatingTemplate;
use App\Models\SiteRatingTemplateVersion;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\Sites\RatingTemplateDefinition;
use App\Support\Sites\SiteRatingPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A site's own rating template (#1157): drafts never reach visitors, published
 * versions never change, and answers on different questions or scales are never
 * combined. docs/product/satisfaction.md records the rules under test.
 */
function customTemplateWorld(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'public_key' => 'site_public_custom',
        'settings' => ['rating' => ['enabled' => true, 'template' => 'overall']],
    ]);
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    return compact('account', 'site', 'owner');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function customTemplateDraft(array $overrides = []): array
{
    return array_replace_recursive(RatingTemplateDefinition::starter()->toArray(), $overrides);
}

function customTemplatePublish($test, array $w, array $draft): SiteRatingTemplateVersion
{
    $test->actingAs($w['owner'])
        ->put(route('dashboard.sites.rating-template.update', $w['site']), ['draft' => $draft])
        ->assertSessionHasNoErrors();
    $test->actingAs($w['owner'])
        ->post(route('dashboard.sites.rating-template.publish', $w['site']))
        ->assertSessionHasNoErrors();

    return SiteRatingTemplateVersion::query()->where('site_id', $w['site']->id)->orderByDesc('version')->firstOrFail();
}

function customTemplateUse(array $w, string $template = 'custom'): void
{
    $w['site']->mutateSettings(function (array $settings) use ($template): array {
        $settings['rating']['template'] = $template;

        return $settings;
    });
}

/**
 * A closed conversation on the site, owned by a widget session, ready to rate.
 *
 * @return array{0: Conversation, 1: string}
 */
function customTemplateClosedConversation($test, array $w): array
{
    $visitor = Visitor::factory()->for($w['site'])->create(['anonymous_id' => 'anon-custom']);
    $conversation = Conversation::factory()->for($w['site'])->for($visitor)->create(['support_code' => 'WF-CUSTOM', 'status' => 'open']);
    app(ConversationLifecycleLog::class)->closed($conversation->fresh(), null, 'open');
    $conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    $token = $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_custom',
        'anonymous_id' => 'anon-custom',
    ])->assertSuccessful()->json('data.visitor.token');
    conversationOwnedBySession($conversation, $token);

    return [$conversation->fresh(), $token];
}

function customTemplateAnswer($test, Conversation $conversation, string $token, array $answer)
{
    return $test->postJson(route('conversations.rating.store', 'WF-CUSTOM'), array_filter($answer + [
        'site_public_key' => 'site_public_custom',
        'anonymous_id' => 'anon-custom',
        'visitor_token' => $token,
        'episode' => $conversation->currentCloseEpisodeToken(),
    ], fn (mixed $value): bool => $value !== null));
}

test('an admin edits a draft starting from the built-in questions, and saving it reaches no visitor', function (): void {
    $w = customTemplateWorld();

    $this->actingAs($w['owner'])
        ->get(route('dashboard.sites.rating-template.edit', $w['site']))
        ->assertOk()
        ->assertSee('value="How was your overall support experience?"', escape: false)
        ->assertSeeText('Nothing has been published yet.');

    $this->actingAs($w['owner'])
        ->put(route('dashboard.sites.rating-template.update', $w['site']), ['draft' => customTemplateDraft(['overall' => ['text' => ['en' => 'Did we sort it out?']]])])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SiteRatingTemplate::query()->firstOrFail()->definition()->text('overall'))->toBe('Did we sort it out?')
        ->and(SiteRatingTemplateVersion::query()->count())->toBe(0);
});

test('the draft is validated: English for what is asked, {name} in the agent question, bounded lengths', function (): void {
    $w = customTemplateWorld();
    $put = fn (array $draft) => $this->actingAs($w['owner'])->put(route('dashboard.sites.rating-template.update', $w['site']), ['draft' => $draft]);

    $put(customTemplateDraft(['overall' => ['text' => ['en' => '']]]))->assertSessionHasErrors('draft.overall.text.en');
    $put(customTemplateDraft(['agent' => ['enabled' => '1', 'text' => ['de' => 'Wie war die Hilfe?']]]))->assertSessionHasErrors('draft.agent.text.de');
    $put(customTemplateDraft(['labels' => ['good' => ['en' => str_repeat('x', 41)]]]))->assertSessionHasErrors('draft.labels.good.en');
    $put(customTemplateDraft(['scale' => 'ten']))->assertSessionHasErrors('draft.scale');
    $put(customTemplateDraft(['scale' => 'five', 'labels' => ['4' => ['en' => '']]]))->assertSessionHasErrors('draft.labels.4.en');

    // A question that is not asked needs no wording.
    $put(customTemplateDraft(['agent' => ['enabled' => '0', 'text' => ['en' => '']]]))->assertSessionHasNoErrors();
});

test('only someone who may update the site edits or publishes its template', function (): void {
    $w = customTemplateWorld();
    $agent = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Agent]);
    $stranger = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($agent)->put(route('dashboard.sites.rating-template.update', $w['site']), ['draft' => customTemplateDraft()])->assertForbidden();
    $this->actingAs($agent)->post(route('dashboard.sites.rating-template.publish', $w['site']))->assertForbidden();
    $this->actingAs($stranger)->get(route('dashboard.sites.rating-template.edit', $w['site']))->assertNotFound();

    expect(SiteRatingTemplate::query()->count())->toBe(0);
});

test('publishing adds numbered versions that can never be rewritten', function (): void {
    $w = customTemplateWorld();

    $this->actingAs($w['owner'])->post(route('dashboard.sites.rating-template.publish', $w['site']))->assertSessionHasErrors('publish');

    $first = customTemplatePublish($this, $w, customTemplateDraft(['overall' => ['text' => ['en' => 'First wording?']]]));
    $second = customTemplatePublish($this, $w, customTemplateDraft(['overall' => ['text' => ['en' => 'Second wording?']]]));

    expect([$first->version, $second->version])->toBe([1, 2])
        ->and($first->fresh()->definition()->text('overall'))->toBe('First wording?');

    expect(fn () => $first->fresh()->forceFill(['definition' => ['scale' => 'five']])->save())->toThrow(LogicException::class);
});

test('your own template is chosen only once published, and a draft edit never changes the live prompt', function (): void {
    $w = customTemplateWorld();

    $this->actingAs($w['owner'])
        ->put(route('dashboard.sites.rating.update', $w['site']), ['rating_enabled' => '1', 'rating_template' => 'custom'])
        ->assertSessionHasErrors('rating_template');

    customTemplatePublish($this, $w, customTemplateDraft(['overall' => ['text' => ['en' => 'Published wording?']]]));

    $this->actingAs($w['owner'])
        ->put(route('dashboard.sites.rating.update', $w['site']), ['rating_enabled' => '1', 'rating_template' => 'custom'])
        ->assertSessionHasNoErrors();

    // Edited but not published: the prompt still asks version 1.
    $this->actingAs($w['owner'])
        ->put(route('dashboard.sites.rating-template.update', $w['site']), ['draft' => customTemplateDraft(['overall' => ['text' => ['en' => 'Unpublished wording?']]])]);

    $payload = SiteRatingPrompt::for($w['site']->fresh())->toPayload()['template'];

    expect($payload['key'])->toBe('custom')
        ->and($payload['version'])->toBe(1)
        ->and($payload['definition']['overall']['en'])->toBe('Published wording?');
});

test('an answer is stored against the version that was shown, even after a newer one is published', function (): void {
    $w = customTemplateWorld();
    $v1 = customTemplatePublish($this, $w, customTemplateDraft());
    customTemplateUse($w);
    [$conversation, $token] = customTemplateClosedConversation($this, $w);

    $v2 = customTemplatePublish($this, $w, customTemplateDraft(['scale' => 'five']));

    customTemplateAnswer($this, $conversation, $token, ['score' => 'good', 'template' => 'custom', 'template_version' => 1])->assertCreated();

    expect(ConversationRating::query()->firstOrFail()->only(['template_key', 'template_version', 'template_version_id', 'scale', 'score']))
        ->toBe(['template_key' => 'custom', 'template_version' => 1, 'template_version_id' => $v1->id, 'scale' => 'three', 'score' => 'good']);

    // A version that was never published on THIS site is not a question it asked.
    customTemplateAnswer($this, $conversation, $token, ['score' => 'good', 'template' => 'custom', 'template_version' => 9])
        ->assertUnprocessable()->assertJsonValidationErrors('template');

    expect($v2->version)->toBe(2);
});

test('answers must be on the scale that was shown', function (): void {
    $w = customTemplateWorld();
    customTemplatePublish($this, $w, customTemplateDraft(['scale' => 'five']));
    customTemplateUse($w);
    [$conversation, $token] = customTemplateClosedConversation($this, $w);

    customTemplateAnswer($this, $conversation, $token, ['score' => 'good', 'template' => 'custom', 'template_version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('score');
    customTemplateAnswer($this, $conversation, $token, ['score' => '5', 'template' => 'overall', 'template_version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('score');

    customTemplateAnswer($this, $conversation, $token, ['score' => '4', 'template' => 'custom', 'template_version' => 1])->assertCreated();

    expect(ConversationRating::query()->firstOrFail()->only(['score', 'scale']))->toBe(['score' => '4', 'scale' => 'five']);
});

test('a template that does not ask for a comment does not store one', function (): void {
    $w = customTemplateWorld();
    customTemplatePublish($this, $w, customTemplateDraft(['comment' => ['enabled' => '0']]));
    customTemplateUse($w);
    [$conversation, $token] = customTemplateClosedConversation($this, $w);

    customTemplateAnswer($this, $conversation, $token, ['score' => 'bad', 'comment' => 'Sneaked in.', 'template' => 'custom', 'template_version' => 1])->assertCreated();

    expect(ConversationRating::query()->firstOrFail()->comment)->toBeNull();
});

test('each version is reported on its own, and none of it reaches the built-in figure', function (): void {
    $w = customTemplateWorld();
    $three = customTemplatePublish($this, $w, customTemplateDraft(['overall' => ['text' => ['en' => '<b>Sorted?</b>']]]));
    $five = customTemplatePublish($this, $w, customTemplateDraft(['scale' => 'five']));
    $closedAt = now()->subDay()->startOfHour();
    $conversation = Conversation::factory()->for($w['site'])->create();
    $row = fn (int $event, array $values) => ConversationRating::query()->forceCreate($values + [
        'conversation_id' => $conversation->id, 'site_id' => $w['site']->id,
        'episode_event_id' => $event, 'episode_closed_at' => $closedAt, 'rated_at' => $closedAt,
    ]);

    // Built-in: one good.
    $row(1, ['score' => 'good']);
    // Custom three-choice: one bad, with a skipped agent answer.
    $row(2, ['score' => 'bad', 'template_key' => 'custom', 'template_version' => 1, 'template_version_id' => $three->id, 'scale' => 'three', 'agent_name' => 'Ada', 'agent_score' => null]);
    // Custom five-star: a 5 and a 2.
    $row(3, ['score' => '5', 'template_key' => 'custom', 'template_version' => 2, 'template_version_id' => $five->id, 'scale' => 'five']);
    $row(4, ['score' => '2', 'template_key' => 'custom', 'template_version' => 2, 'template_version_id' => $five->id, 'scale' => 'five']);

    $report = new SupportReport(ReportingScope::for($w['account'], $w['owner']), ReportingWindow::ofDays(7));

    expect($report->satisfaction())->toMatchArray(['good' => 1, 'bad' => 0, 'answered' => 1, 'positive' => 100.0]);

    $custom = collect($report->customTemplates())->keyBy('version');

    expect($custom[1]['distribution'])->toBe(['good' => 0, 'ok' => 0, 'bad' => 1])
        ->and($custom[1]['positive'])->toBe(0.0)
        ->and($custom[1]['average'])->toBeNull()
        ->and($custom[1]['agent'])->toMatchArray(['asked' => 1, 'answered' => 0, 'skipped' => 1, 'positive' => null])
        ->and($custom[2]['distribution'])->toBe(['1' => 0, '2' => 1, '3' => 0, '4' => 0, '5' => 1])
        ->and($custom[2]['positive'])->toBe(50.0)
        ->and($custom[2]['average'])->toBe(3.5);

    $this->actingAs($w['owner'])
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSeeText('your own template, version 1')
        ->assertSeeText('your own template, version 2')
        ->assertSee('&lt;b&gt;Sorted?&lt;/b&gt;', escape: false)
        ->assertDontSee('<b>Sorted?</b>', escape: false);
});

test('switching back to a built-in question keeps every version and answer', function (): void {
    $w = customTemplateWorld();
    $version = customTemplatePublish($this, $w, customTemplateDraft());
    customTemplateUse($w);
    [$conversation, $token] = customTemplateClosedConversation($this, $w);
    customTemplateAnswer($this, $conversation, $token, ['score' => 'ok', 'template' => 'custom', 'template_version' => 1])->assertCreated();

    $this->actingAs($w['owner'])
        ->put(route('dashboard.sites.rating.update', $w['site']), ['rating_enabled' => '1', 'rating_template' => 'overall'])
        ->assertSessionHasNoErrors();

    expect(SiteRatingPrompt::for($w['site']->fresh())->template->key)->toBe('overall')
        ->and($version->fresh())->not->toBeNull()
        ->and(ConversationRating::query()->firstOrFail()->template_version_id)->toBe($version->id);
});

test('the site settings offer your own template only once one is published, previewing its questions', function (): void {
    $w = customTemplateWorld();

    $this->actingAs($w['owner'])
        ->get(route('dashboard.sites.show', $w['site']))
        ->assertOk()
        ->assertSee('id="rating_template_custom" name="rating_template" value="custom"'."\n".'                                        disabled', escape: false)
        ->assertSeeText('Publish a version of your own template before choosing it.');

    customTemplatePublish($this, $w, customTemplateDraft(['overall' => ['text' => ['en' => 'Did that work?']]]));

    $this->actingAs($w['owner'])
        ->get(route('dashboard.sites.show', $w['site']))
        ->assertOk()
        ->assertSeeText('Your own template (version 1)')
        ->assertSeeText('Did that work?');
});

test('the editor page renders in German for a German admin', function (): void {
    $w = customTemplateWorld();
    $w['owner']->forceFill(['locale' => 'de'])->save();

    $this->actingAs($w['owner'])
        ->get(route('dashboard.sites.rating-template.edit', $w['site']))
        ->assertOk()
        ->assertSee('<html lang="de">', escape: false)
        ->assertSeeText('Entwurf speichern');
});

test('each section names its site, because every site numbers its versions from one', function (): void {
    $w = customTemplateWorld();
    $w['site']->forceFill(['name' => 'Docs Site'])->save();
    $other = Site::factory()->for($w['account'])->create(['name' => 'Shop Site', 'settings' => ['rating' => ['enabled' => true]]]);
    $closedAt = now()->subDay()->startOfHour();

    foreach ([[$w['site'], 1], [$other, 2]] as [$site, $event]) {
        $version = SiteRatingTemplateVersion::query()->create([
            'site_id' => $site->id, 'version' => 1, 'definition' => customTemplateDraft(), 'published_at' => now(),
        ]);
        ConversationRating::query()->forceCreate([
            'conversation_id' => Conversation::factory()->for($site)->create()->id, 'site_id' => $site->id,
            'episode_event_id' => $event, 'episode_closed_at' => $closedAt, 'rated_at' => $closedAt, 'score' => 'good',
            'template_key' => 'custom', 'template_version' => 1, 'template_version_id' => $version->id, 'scale' => 'three',
        ]);
    }

    $this->actingAs($w['owner'])
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSeeText('Docs Site — your own template, version 1')
        ->assertSeeText('Shop Site — your own template, version 1');
});

test('a period with only custom answers does not claim that nobody answered', function (): void {
    $w = customTemplateWorld();
    $version = customTemplatePublish($this, $w, customTemplateDraft());
    $closedAt = now()->subDay()->startOfHour();
    $conversation = Conversation::factory()->for($w['site'])->create();
    AuditEvent::query()->forceCreate([
        'account_id' => $w['account']->id, 'site_id' => $w['site']->id, 'action' => ConversationLifecycleLog::CLOSED,
        'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id, 'occurred_at' => $closedAt, 'metadata' => [],
    ]);
    ConversationRating::query()->forceCreate([
        'conversation_id' => $conversation->id, 'site_id' => $w['site']->id, 'episode_event_id' => 1,
        'episode_closed_at' => $closedAt, 'rated_at' => $closedAt, 'score' => 'ok',
        'template_key' => 'custom', 'template_version' => 1, 'template_version_id' => $version->id, 'scale' => 'three',
    ]);

    $this->actingAs($w['owner'])
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSeeText('Nobody answered the built-in question in this period.')
        ->assertDontSeeText('Nobody answered in this period.');
});
