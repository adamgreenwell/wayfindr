<x-layouts.app title="Articles" :agent="$agent" :account="$account">
            <x-page-header title="Articles" subtitle="Answers a visitor can find without asking." :back-href="route('dashboard.account.show')" back-label="Back to account" />

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            @error('title')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @error('body')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <section class="section" aria-labelledby="new-article-heading">
                <div class="section-header">
                    <div>
                        <h2 id="new-article-heading">Write an article</h2>
                        <p class="lede">Saved as a draft. Nothing reaches a visitor until you publish it.</p>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.articles.store') }}">
                    @csrf

                    <div class="field">
                        <label for="article_title">Title</label>
                        <input type="text" id="article_title" name="title" maxlength="160" required
                            value="{{ old('title') }}" placeholder="How refunds work">
                    </div>

                    <div class="field">
                        <label for="article_body">Body</label>
                        <textarea id="article_body" name="body" rows="8" maxlength="20000" required
                            placeholder="## Refunds&#10;&#10;We refund within **14 days**. Email [support](mailto:help@example.com)."></textarea>
                        <p class="field-hint">
                            Headings with <code>##</code>, bullets with <code>-</code>, links as
                            <code>[words](https://…)</code>, emphasis with <code>**bold**</code>.
                            Anything else is read as ordinary text.
                        </p>
                    </div>

                    <button class="button" type="submit">Create draft</button>
                </form>
            </section>

            <section class="section" aria-labelledby="article-list-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-list-heading">Everything written so far</h2>
                        <p class="lede">Drafts first, because they are the ones still wanting work.</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $articles->isEmpty() ? 'manual' : 'ready' }}">
                        {{ trans_choice(':count article|:count articles', $articles->count(), ['count' => $articles->count()]) }}
                    </span>
                </div>

                <form class="section-form" method="GET" action="{{ route('dashboard.account.articles.index') }}">
                    <div class="field">
                        <label for="article_search">Search</label>
                        <input type="search" id="article_search" name="article_search" maxlength="120"
                            value="{{ $articleSearch }}" placeholder="By title">
                    </div>

                    <button class="button secondary" type="submit">Search articles</button>
                </form>

                @if ($articles->isEmpty())
                    <div class="notice-copy">
                        <p>
                            @if ($articleSearch !== '')
                                No article title matches “{{ $articleSearch }}”.
                            @else
                                Nothing written yet. The first article is usually the question your desk answers most.
                            @endif
                        </p>
                    </div>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Article</th>
                                <th scope="col">State</th>
                                <th scope="col">Last edited</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($articles as $article)
                                <tr>
                                    <td>
                                        <a href="{{ route('dashboard.account.articles.show', $article) }}">{{ $article->title }}</a>
                                    </td>
                                    <td>
                                        <span class="readiness-status" data-status="{{ $article->isPublished() ? 'ready' : 'manual' }}">
                                            {{ $article->isPublished() ? 'Published' : 'Draft' }}
                                        </span>
                                    </td>
                                    <td>{{ $article->updated_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
</x-layouts.app>
