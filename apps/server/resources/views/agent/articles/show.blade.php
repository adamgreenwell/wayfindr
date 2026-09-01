<x-layouts.app title="{{ $article->title }}" :agent="$agent" :account="$account">
            {{-- `title-lang=""` is HTML's "unknown". The article is written for
                 VISITORS, so its language is whatever the account writes in --
                 not the language this admin reads the dashboard in. Without
                 this, a screen reader pronounces English article prose with
                 German phonetics on a German dashboard. --}}
            <x-page-header :title="$article->title" title-lang="" :subtitle="__('articles.detail.subtitle')" :back-href="route('dashboard.account.articles.index')" :back-label="__('articles.back_to_articles')" />

            @if (session('status'))
                {{-- A catalogue key rather than a sentence -- see AgentArticleController. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
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
                        <h2 id="article-state-heading">{{ __('articles.detail.visibility_heading') }}</h2>
                        <p class="lede">
                            @if ($article->isPublished())
                                {{ __('articles.detail.visible') }}
                            @else
                                {{ __('articles.detail.hidden') }}
                            @endif
                        </p>
                    </div>
                    <span class="readiness-status" data-status="{{ $article->isPublished() ? 'ready' : 'manual' }}">
                        {{ $article->isPublished() ? __('articles.state.published') : __('articles.state.draft') }}
                    </span>
                </div>

                <div class="desk-closure">
                    <p class="desk-closure-state">
                        {!! __('articles.detail.slug', ['slug' => '<code lang="">'.e($article->slug).'</code>']) !!}
                    </p>

                    <form method="POST" action="{{ route('dashboard.account.articles.publish', $article) }}">
                        @csrf
                        <button class="button" type="submit">{{ $article->isPublished() ? __('articles.detail.unpublish') : __('articles.detail.publish') }}</button>
                    </form>
                </div>
            </section>

            <section class="section" aria-labelledby="article-edit-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-edit-heading">{{ __('articles.detail.edit_heading') }}</h2>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.articles.update', $article) }}">
                    @csrf
                    @method('PUT')

                    <div class="field">
                        <label for="article_title">{{ __('articles.write.title_label') }}</label>
                        <input type="text" id="article_title" name="title" maxlength="160" required
                            lang="" value="{{ old('title', $article->title) }}">
                    </div>

                    <div class="field">
                        <label for="article_body">{{ __('articles.write.body_label') }}</label>
                        <textarea id="article_body" name="body" rows="14" maxlength="20000" required lang="">{{ old('body', $article->body) }}</textarea>
                    </div>

                    <button class="button" type="submit">{{ __('articles.detail.save') }}</button>
                </form>
            </section>

            <section class="section" aria-labelledby="article-preview-heading">
                <div class="section-header">
                    <div>
                        <h2 id="article-preview-heading">{{ __('articles.detail.preview_heading') }}</h2>
                        <p class="lede">{{ __('articles.detail.preview_lede') }}</p>
                    </div>
                </div>

                {{-- The entire preview is the article, so the reset goes on the
                     region rather than on each block inside it. --}}
                <div class="notice-copy article-preview" lang="">
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
                        <h2 id="article-delete-heading">{{ __('articles.detail.delete_heading') }}</h2>
                        <p class="lede">{{ __('articles.detail.delete_lede') }}</p>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.articles.destroy', $article) }}">
                    @csrf
                    @method('DELETE')
                    <button class="button danger" type="submit">{{ __('articles.detail.delete') }}</button>
                </form>
            </section>
</x-layouts.app>
