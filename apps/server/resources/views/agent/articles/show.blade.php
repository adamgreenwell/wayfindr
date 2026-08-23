<x-layouts.app title="{{ $article->title }}" :agent="$agent" :account="$account">
            <x-page-header :title="$article->title" subtitle="Edit the answer, then decide who can see it." :back-href="route('dashboard.account.articles.index')" back-label="Back to articles" />

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            @error('title')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @error('body')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <section class="section" aria-labelledby="article-state-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-state-heading">Who can see this</h2>
                        <p class="lede">
                            @if ($article->isPublished())
                                Visitors can find this in the widget when they search.
                            @else
                                A draft. Only this account can see it.
                            @endif
                        </p>
                    </div>
                    <span class="readiness-status" data-status="{{ $article->isPublished() ? 'ready' : 'manual' }}">
                        {{ $article->isPublished() ? 'Published' : 'Draft' }}
                    </span>
                </div>

                <div class="desk-closure">
                    <p class="desk-closure-state">
                        Referred to as <code>{{ $article->slug }}</code>, which stays the same if you retitle it —
                        so a link an agent already sent keeps working.
                    </p>

                    <form method="POST" action="{{ route('dashboard.account.articles.publish', $article) }}">
                        @csrf
                        <button class="button" type="submit">{{ $article->isPublished() ? 'Unpublish' : 'Publish' }}</button>
                    </form>
                </div>
            </section>

            <section class="section" aria-labelledby="article-edit-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-edit-heading">The answer</h2>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.articles.update', $article) }}">
                    @csrf
                    @method('PUT')

                    <div class="field">
                        <label for="article_title">Title</label>
                        <input type="text" id="article_title" name="title" maxlength="160" required
                            value="{{ old('title', $article->title) }}">
                    </div>

                    <div class="field">
                        <label for="article_body">Body</label>
                        <textarea id="article_body" name="body" rows="14" maxlength="20000" required>{{ old('body', $article->body) }}</textarea>
                    </div>

                    <button class="button" type="submit">Save article</button>
                </form>
            </section>

            <section class="section" aria-labelledby="article-preview-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-preview-heading">What a visitor sees</h2>
                        <p class="lede">Built from the same blocks the widget builds, so this is the article rather than an impression of it.</p>
                    </div>
                </div>

                <div class="notice-copy article-preview">
                    @foreach ($blocks as $block)
                        @if ($block['type'] === 'heading')
                            <h3>{{ $block['text'] }}</h3>
                        @elseif ($block['type'] === 'paragraph')
                            <p>@include('agent.articles.partials.spans', ['spans' => $block['spans']])</p>
                        @elseif ($block['type'] === 'list')
                            <ul>
                                @foreach ($block['items'] as $item)
                                    <li>@include('agent.articles.partials.spans', ['spans' => $item])</li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="article-delete-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-delete-heading">Delete</h2>
                        <p class="lede">Removes the article outright. Unpublishing is the reversible option.</p>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.articles.destroy', $article) }}">
                    @csrf
                    @method('DELETE')
                    <button class="button danger" type="submit">Delete this article</button>
                </form>
            </section>
</x-layouts.app>
