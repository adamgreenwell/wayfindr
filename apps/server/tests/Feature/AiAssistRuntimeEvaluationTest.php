<?php

declare(strict_types=1);

use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\Article;
use App\Models\ConversationDraftRefinement;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Grounding\ConversationGroundedReplyGuard;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\Refinement\DraftRefinementAction;
use App\Support\Ai\Refinement\DraftRefinementInput;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Refinement\DraftRefinementSafetyGuard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
require_once __DIR__.'/../Fixtures/draft-refinement.php';

uses(RefreshDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function assistRuntimeEvaluationCases(): array
{
    $suite = json_decode(file_get_contents(__DIR__.'/../Fixtures/ai-assist-runtime-evaluation.json'), true, 16, JSON_THROW_ON_ERROR);
    if ($suite['version'] !== 'assist-runtime-synthetic-v1' || count($suite['cases']) !== 25) {
        throw new LogicException('Review the declared qualification suite when its cases change.');
    }
    $cases = [];
    foreach ($suite['cases'] as $case) {
        if (isset($cases[$case['id']]) || ! in_array($case['kind'], ['reply', 'refinement'], true)) {
            throw new LogicException('Qualification cases must have unique identities and known recipes.');
        }
        $cases[$case['id']] = [$case];
    }

    return $cases;
}

/** Symbolic fixture citations resolve only to sources actually selected by the runtime. */
function assistRuntimeEvaluationProposal(AgentCopilotPrompt $prompt, array $case): string
{
    $input = json_decode($prompt->input, true, 12, JSON_THROW_ON_ERROR);
    $proposal = $case['proposal'];
    $proposal['citations'] = array_map(fn (string $ref): string => match ($ref) {
        'first' => $input['knowledge']['sources'][0]['ref'] ?? throw new LogicException('Expected selected fixture source.'),
        'unknown' => 'src-'.str_repeat('f', 24),
        default => throw new LogicException('Unknown fixture citation alias.'),
    }, $proposal['citations']);

    return json_encode($proposal, JSON_THROW_ON_ERROR);
}

test('the current guarded Assist recipes qualify each synthetic case without side effects', function (array $case): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    $w['conversation']->update(['subject' => 'Checkout guidance']);
    $w['message']->update(['body' => $case['question']]);
    $w['article']->delete();
    foreach ($case['articles'] as $index => $body) {
        Article::factory()->for($w['account'])->published()->create(['title' => 'Checkout guidance', 'slug' => $case['id'].'-'.$index, 'body' => $body]);
    }
    $captured = null;
    $output = function (AgentCopilotPrompt $prompt) use ($case, &$captured): string {
        $captured = ['instructions_sha256' => hash('sha256', $prompt->instructions), 'input_sha256' => hash('sha256', $prompt->input),
            'selected_sources' => count(json_decode($prompt->input, true, 12, JSON_THROW_ON_ERROR)['knowledge']['sources'])];

        return assistRuntimeEvaluationProposal($prompt, $case);
    };
    if ($case['kind'] === 'reply') {
        assistPreparationEnsure($w, 'initial');
        $provider = assistPreparationRun($w, assistPreparationProvider(replyOutput: $output));
        $state = app(AiAssistPreparation::class)->read($w['agent'], $w['conversation'], $w['purpose']);
        expect($state['output']['grounding']['decision'] ?? null)->toBe($case['expected_decision'], $case['id'].' grounding decision');
        if ($case['expected_decision'] === 'supported') {
            expect($state['output']['text'])->toContain($case['proposal']['reply'])
                ->and($state['output']['grounding']['sources'])->not->toBeEmpty();
        } elseif ($case['expected_state'] === 'ready') {
            expect($state['output']['grounding']['limitation'])->not->toBeNull()
                ->and($state['output']['grounding']['missing_information'])->not->toBeEmpty()
                ->and($state['output']['text'])->not->toBe($case['proposal']['reply']);
        } else {
            expect($state['output'])->toBeNull()->and($state['can_use'])->toBeFalse();
        }
    } else {
        $input = new DraftRefinementInput($case['original'], (string) Str::uuid(), DraftRefinementAction::from($case['action']), $case['tone'], $case['language']);
        $service = app(DraftRefinements::class);
        $receipt = $service->request($w['agent'], $w['conversation'], $input);
        $row = ConversationDraftRefinement::query()->where('generation', $receipt['generation'])->sole();
        $provider = refinementLifecycleProvider(output: $output);
        app()->instance(AgentCopilotProvider::class, $provider);
        app()->call([new GenerateConversationDraftRefinement($row->id, $row->generation), 'handle']);
        $state = $service->show($w['agent'], $w['conversation'], $receipt['generation'], $input->draftRevision);
        expect($row->refresh()->input)->toBeNull();
        if ($case['expected_state'] === 'ready') {
            expect($state['preview']['candidate'])->toBe($case['proposal']['candidate']);
        } else {
            expect($state['preview'])->toBeNull()->and($row->preview)->toBeNull()->and($row->failure_code)->toBe('invalid_output');
        }
    }
    expect($state['state'])->toBe($case['expected_state'], $case['id'].' runtime state')
        ->and($provider->calls)->toBe(1)->and(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and($w['conversation']->messages()->count())->toBe(1)->and($w['conversation']->tickets()->count())->toBe(0)
        ->and($captured)->not->toBeNull();
    Http::assertNothingSent();
    // Append only after every assertion. Filtered/failed runs can never emit complete=true.
    $GLOBALS['assist_runtime_qualification'][$case['id']] = ['kind' => $case['kind'], 'state' => $state['state'],
        'decision' => $state['output']['grounding']['decision'] ?? null, 'prompt' => $captured,
        'grounding_guard' => app(ConversationGroundedReplyGuard::class)->identity(),
        'refinement_guard' => app(DraftRefinementSafetyGuard::class)->identity()];
})->with(assistRuntimeEvaluationCases());

afterAll(function (): void {
    $destination = getenv('WAYFINDR_ASSIST_QUALIFICATION_OUTPUT');
    if (! is_string($destination) || $destination === '') {
        return;
    }
    $cases = $GLOBALS['assist_runtime_qualification'] ?? [];
    ksort($cases);
    $sources = [];
    foreach (['ai-assist-runtime-evaluation.json', 'ai-assist-preparation.php', 'draft-refinement.php'] as $file) {
        $sources[$file] = hash_file('sha256', __DIR__.'/../Fixtures/'.$file);
    }
    $sources['AiAssistRuntimeEvaluationTest.php'] = hash_file('sha256', __FILE__);
    foreach (['composer.lock', 'app/Support/Ai/ConversationReplyDraftPromptBuilder.php',
        'app/Support/Ai/ConversationTextContextSelector.php', 'app/Support/Ai/AiContextSanitizer.php',
        'app/Support/Ai/Grounding/ConversationGroundingSelector.php', 'app/Support/Ai/Grounding/GroundingSourceReference.php',
        'app/Support/Ai/Grounding/ConversationGroundedReplyGuard.php', 'app/Support/Ai/Grounding/ConversationGroundedReplyOutputParser.php',
        'app/Support/Ai/Refinement/ConversationDraftRefinementPromptBuilder.php', 'app/Support/Ai/Refinement/DraftRefinementSafetyGuard.php',
        'app/Support/Ai/Refinement/DraftRefinementOutputParser.php', 'app/Jobs/GenerateConversationCopilotReplyDraft.php',
        'app/Jobs/GenerateConversationDraftRefinement.php', 'lang/en/ai_assist.php', 'lang/de/ai_assist.php', 'lang/it/ai_assist.php'] as $file) {
        $sources[$file] = hash_file('sha256', dirname(__DIR__, 2).'/'.$file);
    }
    $report = ['schema' => 1, 'synthetic_only' => true, 'live_provider_quality' => false, 'php' => PHP_VERSION,
        'suite_identity' => hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR)), 'source_hashes' => $sources,
        'complete' => count($cases) === count(assistRuntimeEvaluationCases()), 'expected_cases' => count(assistRuntimeEvaluationCases()), 'cases' => $cases];
    if (file_put_contents($destination, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write the requested qualification receipt.');
    }
});
