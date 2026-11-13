<x-layouts.site :site="$site" :title="__('rating_templates.document_title')" :agent="$agent" :account="$account">
            <x-page-header :subtitle="__('rating_templates.subtitle')">
                <x-slot:title-content>
                    {!! __('rating_templates.title', ['site' => '<span lang="">'.e($site->name).'</span>']) !!}
                </x-slot:title-content>
            </x-page-header>

            <style>
                /* The per-language wording sits in table cells, outside any
                   .field, so it takes the field input look explicitly and keeps
                   a readable minimum width on narrow screens. */
                .rating-template-input {
                    width: 100%;
                    min-width: 12rem;
                    border: 1px solid var(--border);
                    border-radius: 6px;
                    padding: 8px 10px;
                    background: var(--wf-surface);
                    color: var(--text);
                    font-size: 1rem;
                }
            </style>

            @if (session('status'))
                <p class="status-message" role="status">{{ __(session('status'), ['version' => session('published_version')]) }}</p>
            @endif

            <section class="section" aria-labelledby="rating-template-draft-heading">
                <div class="section-header">
                    <div>
                        <h2 id="rating-template-draft-heading">{{ __('rating_templates.draft.heading') }}</h2>
                        <p class="lede">{{ __('rating_templates.draft.lede') }}</p>
                    </div>
                </div>

                @if (! $canUpdate)
                    <div class="notice-copy"><p>{{ __('rating_templates.restricted') }}</p></div>
                @else
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.rating-template.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <fieldset class="field">
                            <legend>{{ __('rating_templates.scale.legend') }}</legend>
                            @foreach (\App\Support\Sites\RatingTemplateDefinition::SCALES as $scale)
                                <label class="check-row" for="draft_scale_{{ $scale }}">
                                    <input type="radio" id="draft_scale_{{ $scale }}" name="draft[scale]" value="{{ $scale }}"
                                        @checked(old('draft.scale', $draft->scale()) === $scale)>
                                    {{ __('rating_templates.scale.'.$scale) }}
                                </label>
                            @endforeach
                            <p class="field-help">{{ __('rating_templates.scale.help') }}</p>
                            @error('draft.scale')<p class="field-error">{{ $message }}</p>@enderror
                        </fieldset>

                        <div class="table-wrap">
                            <table>
                                <caption>{{ __('rating_templates.questions.caption') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('rating_templates.questions.question') }}</th>
                                        <th scope="col">{{ __('rating_templates.questions.asked') }}</th>
                                        @foreach (\App\Support\Sites\RatingTemplateDefinition::LOCALES as $locale)
                                            <th scope="col">{{ __('rating_templates.locales.'.$locale) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['overall', 'agent', 'comment'] as $question)
                                        <tr>
                                            <th scope="row">{{ __('rating_templates.questions.'.$question) }}</th>
                                            <td>
                                                @if ($question === 'overall')
                                                    {{ __('rating_templates.questions.always') }}
                                                @else
                                                    <input type="hidden" name="draft[{{ $question }}][enabled]" value="0">
                                                    <input type="checkbox" id="draft_{{ $question }}_enabled" name="draft[{{ $question }}][enabled]" value="1"
                                                        aria-label="{{ __('rating_templates.questions.ask', ['question' => __('rating_templates.questions.'.$question)]) }}"
                                                        @checked(old('draft.'.$question.'.enabled', ($draft->toArray()[$question]['enabled'] ?? false) ? '1' : '0') === '1')>
                                                @endif
                                            </td>
                                            @foreach (\App\Support\Sites\RatingTemplateDefinition::LOCALES as $locale)
                                                <td>
                                                    <input class="rating-template-input" type="text" lang="{{ $locale }}" maxlength="{{ \App\Support\Sites\RatingTemplateDefinition::TEXT_MAX }}"
                                                        name="draft[{{ $question }}][text][{{ $locale }}]"
                                                        aria-label="{{ __('rating_templates.questions.wording', ['question' => __('rating_templates.questions.'.$question), 'language' => __('rating_templates.locales.'.$locale)]) }}"
                                                        value="{{ old('draft.'.$question.'.text.'.$locale, $draft->toArray()[$question]['text'][$locale] ?? '') }}">
                                                    @error('draft.'.$question.'.text.'.$locale)<p class="field-error">{{ $message }}</p>@enderror
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="field-help">{{ __('rating_templates.questions.help') }}</p>

                        <div class="table-wrap">
                            <table>
                                <caption>{{ __('rating_templates.labels.caption') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('rating_templates.labels.answer') }}</th>
                                        @foreach (\App\Support\Sites\RatingTemplateDefinition::LOCALES as $locale)
                                            <th scope="col">{{ __('rating_templates.locales.'.$locale) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['good', 'ok', 'bad', '1', '2', '3', '4', '5', 'not_applicable'] as $choice)
                                        <tr>
                                            <th scope="row">{{ __('rating_templates.labels.choices.'.$choice) }}</th>
                                            @foreach (\App\Support\Sites\RatingTemplateDefinition::LOCALES as $locale)
                                                <td>
                                                    <input class="rating-template-input" type="text" lang="{{ $locale }}" maxlength="{{ \App\Support\Sites\RatingTemplateDefinition::LABEL_MAX }}"
                                                        name="draft[labels][{{ $choice }}][{{ $locale }}]"
                                                        aria-label="{{ __('rating_templates.labels.wording', ['answer' => __('rating_templates.labels.choices.'.$choice), 'language' => __('rating_templates.locales.'.$locale)]) }}"
                                                        value="{{ old('draft.labels.'.$choice.'.'.$locale, $draft->toArray()['labels'][$choice][$locale] ?? '') }}">
                                                    @error('draft.labels.'.$choice.'.'.$locale)<p class="field-error">{{ $message }}</p>@enderror
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="field-help">{{ __('rating_templates.labels.help') }}</p>

                        <button class="button" type="submit">{{ __('rating_templates.draft.save') }}</button>
                    </form>
                @endif
            </section>

            <section class="section" aria-labelledby="rating-template-preview-heading">
                <div class="section-header">
                    <div>
                        <h2 id="rating-template-preview-heading">{{ __('rating_templates.preview.heading') }}</h2>
                        <p class="lede">{{ __('rating_templates.preview.lede') }}</p>
                    </div>
                </div>
                {{-- The saved draft as a visitor reading English would see it. Authored
                     text, so it is escaped and marked as content of its own language. --}}
                <div class="notice-copy" lang="en" data-rating-template-preview>
                    <p><strong>{{ $draft->text('overall') }}</strong></p>
                    <p>
                        @foreach ($draft->scores() as $choice)
                            <span class="badge">{{ $draft->label($choice) }}</span>
                        @endforeach
                    </p>
                    @if ($draft->asksAboutAgent())
                        <p><strong>{{ str_replace('{name}', 'Ada', $draft->text('agent')) }}</strong></p>
                        <p>
                            @foreach ($draft->agentScores() as $choice)
                                <span class="badge">{{ $draft->label($choice) }}</span>
                            @endforeach
                        </p>
                    @endif
                    @if ($draft->asksForComment())
                        <p>{{ $draft->text('comment') }}</p>
                    @endif
                </div>
                <p class="field-help">{{ __('rating_templates.preview.fallback') }}</p>
            </section>

            <section class="section" aria-labelledby="rating-template-publish-heading">
                <div class="section-header">
                    <div>
                        <h2 id="rating-template-publish-heading">{{ __('rating_templates.publish.heading') }}</h2>
                        <p class="lede">{{ __('rating_templates.publish.lede') }}</p>
                    </div>
                </div>
                @if ($canUpdate)
                    <form method="POST" action="{{ route('dashboard.sites.rating-template.publish', $site) }}">
                        @csrf
                        <button class="button" type="submit">{{ __('rating_templates.publish.button', ['version' => ($versions->first()?->version ?? 0) + 1]) }}</button>
                        @error('publish')<p class="field-error">{{ $message }}</p>@enderror
                        @error('draft.overall.text.en')<p class="field-error">{{ $message }}</p>@enderror
                    </form>
                @endif
                <p class="field-help">{{ __('rating_templates.publish.select', ['settings' => __('site_settings.rating.heading')]) }}</p>

                @if ($versions->isEmpty())
                    <p class="empty">{{ __('rating_templates.versions.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <caption>{{ __('rating_templates.versions.caption') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('rating_templates.versions.version') }}</th>
                                    <th scope="col">{{ __('rating_templates.versions.question') }}</th>
                                    <th scope="col">{{ __('rating_templates.versions.scale') }}</th>
                                    <th scope="col">{{ __('rating_templates.versions.published') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($versions as $version)
                                    <tr>
                                        <th scope="row">{{ __('rating_templates.versions.number', ['version' => $version->version]) }}</th>
                                        <td lang="en">{{ $version->definition()->text('overall') }}</td>
                                        <td>{{ __('rating_templates.scale.'.$version->definition()->scale()) }}</td>
                                        <td>
                                            {{ \App\Support\ReaderClock::moment($version->published_at)->isoFormat('LL') }}
                                            @if ($version->publishedBy)
                                                · <span lang="">{{ $version->publishedBy->name }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.site>
