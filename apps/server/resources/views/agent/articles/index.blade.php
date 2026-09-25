<x-layouts.account :title="__('articles.title')">
            <x-page-header :title="__('articles.title')" :subtitle="__('articles.subtitle')" />

            @if (session('status'))
                {{-- A catalogue key rather than a sentence -- see AgentArticleController. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            <section class="section" aria-labelledby="new-article-heading">
                <div class="section-header">
                    <div>
                        <h2 id="new-article-heading">{{ __('articles.write.heading') }}</h2>
                        <p class="lede">{{ __('articles.write.lede') }}</p>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.articles.store') }}">
                    @csrf

                    <div class="field">
                        <label for="article_title">{{ __('articles.write.title_label') }}</label>
                        {{-- The same reset the detail page's editor carries: what
                             the agent types here is the ARTICLE, written for
                             visitors, not a sentence in the language they read the
                             dashboard in.

                             The placeholder is our copy and is translated, so it
                             inherits the reset too. That is the accepted cost of a
                             control carrying one language for both: the value is
                             read back on every keystroke and outlives the hint,
                             which is shown only while the field is empty. --}}
                        <input type="text" id="article_title" name="title" maxlength="160" required lang=""
                            value="{{ old('title') }}" placeholder="{{ __('articles.write.title_placeholder') }}"
                            @error('title') aria-invalid="true" aria-describedby="article_title-error" @enderror>
                        @error('title')<p id="article_title-error" class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="article_body">{{ __('articles.write.body_label') }}</label>
                        <textarea id="article_body" name="body" rows="8" maxlength="20000" required lang=""
                            placeholder="{{ __('articles.write.body_placeholder') }}"
                            aria-describedby="article_body-help @error('body') article_body-error @enderror"
                            @error('body') aria-invalid="true" @enderror></textarea>
                        {{-- `##` and `-` are pure syntax and pass through. The link
                             and emphasis examples are not: their brackets are syntax
                             but the words inside them tell the reader what goes there,
                             so those come from the catalogue. --}}
                        <p id="article_body-help" class="field-help">
                            {!! __('articles.write.markup_hint', [
                                'headings' => '<code>##</code>',
                                'bullets' => '<code>-</code>',
                                'links' => '<code>'.e(__('articles.write.markup_links')).'</code>',
                                'emphasis' => '<code>'.e(__('articles.write.markup_emphasis')).'</code>',
                            ]) !!}
                        </p>
                        @error('body')<p id="article_body-error" class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <button class="button" type="submit">{{ __('articles.write.submit') }}</button>
                </form>
            </section>

            <section class="section" aria-labelledby="article-list-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-list-heading">{{ __('articles.list.heading') }}</h2>
                        <p class="lede">{{ __('articles.list.lede') }}</p>
                    </div>
                    {{-- A count, not a state: plain lede like every other list
                         in the account area. The state pill is kept for what
                         IS a state -- published or draft, on each row. --}}
                    <span class="lede">{{ trans_choice('articles.list.count', $articles->count(), ['count' => \App\Support\ReaderNumber::count($articles->count())]) }}</span>
                </div>

                {{-- Nothing to search on a brand-new account. Kept whenever a
                     search is active, so a search that matched nothing can
                     still be changed or cleared. --}}
                @if ($articles->isNotEmpty() || $articleSearch !== '')
                    <form class="section-form" method="GET" action="{{ route('dashboard.account.articles.index') }}">
                        <div class="field">
                            <label for="article_search">{{ __('articles.list.search_label') }}</label>
                            {{-- The LABEL above stays German; what is typed into the
                                 field is a search for the account's own words. --}}
                            <input type="search" id="article_search" name="article_search" maxlength="120" lang=""
                                value="{{ $articleSearch }}" placeholder="{{ __('articles.list.search_placeholder') }}">
                        </div>

                        <button class="button secondary" type="submit">{{ __('articles.list.search_submit') }}</button>
                    </form>
                @endif

                @if ($articles->isEmpty() && $articleSearch !== '')
                    {{-- The sentence is ours and stays German; the term
                         quoted inside it is whatever the agent typed,
                         which is the account's language. The render audit
                         structurally cannot see this -- interpolated data
                         is excused from the translation check. --}}
                    <p class="empty">{!! __('articles.list.no_match', ['search' => '<span lang="">'.e($articleSearch).'</span>']) !!}</p>
                @elseif ($articles->isEmpty())
                    <div class="empty empty-state">
                        <strong>{{ __('articles.empty.heading') }}</strong>
                        {{ __('articles.empty.body') }}
                        <div class="empty-state-actions">
                            <a class="button secondary" href="#new-article-heading">{{ __('articles.empty.action') }}</a>
                        </div>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('articles.list.column_article') }}</th>
                                    <th scope="col">{{ __('articles.list.column_state') }}</th>
                                    <th scope="col">{{ __('articles.list.column_edited') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($articles as $article)
                                    <tr>
                                        <td>
                                            {{-- The title is the account's own words, in whatever
                                                 language it writes for its visitors. --}}
                                            <a href="{{ route('dashboard.account.articles.show', $article) }}" lang="">{{ $article->title }}</a>
                                        </td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ $article->isPublished() ? 'ready' : 'manual' }}">
                                                {{ $article->isPublished() ? __('articles.state.published') : __('articles.state.draft') }}
                                            </span>
                                        </td>
                                        <td>{{ $article->updated_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.account>
