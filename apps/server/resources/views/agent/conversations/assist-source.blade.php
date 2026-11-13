<x-layouts.account :title="__('ai_assist.sources.heading')">
    <x-page-header :title="__('ai_assist.sources.heading')" :subtitle="__('ai_assist.sources.article_guidance')"
        :back-href="route('dashboard.conversations.show', ['supportCode' => $conversation->support_code])"
        :back-label="__('ai_assist.sources.back')" />
    <section class="section" aria-labelledby="assist-source-title">
        <p class="field-help">{{ __('ai_assist.sources.keep_open') }}</p>
        <h2 id="assist-source-title" lang="">{{ $sourceTitle }}</h2>
        <div class="article-preview" lang="">
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
</x-layouts.account>
