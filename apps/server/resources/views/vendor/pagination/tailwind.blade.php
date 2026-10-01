{{--
    Every LengthAwarePaginator renders through this view: Laravel's default is
    `pagination::tailwind`, and this file overrides it. The stock markup was
    styled with Tailwind utilities, which this application does not ship, so
    its chevrons drew at the full width of their card and its phone and
    desktop layouts both showed at once. It is styled by the layout's
    `.wf-pager` rules instead. The chevrons carry their own size too, so they
    stay small even where those rules do not reach.

    The summary stays one translatable sentence, so each language picks its
    own word order (lang/{en,de,it}/pagination.php).
--}}
@if ($paginator->hasPages())
    <nav class="wf-pager" role="navigation" aria-label="{{ __('pagination.navigation') }}">
        <p class="wf-pager__summary">
            {!! __('pagination.summary', [
                'first' => '<strong>'.e(\App\Support\ReaderNumber::count($paginator->firstItem() ?? 0)).'</strong>',
                'last' => '<strong>'.e(\App\Support\ReaderNumber::count($paginator->lastItem() ?? 0)).'</strong>',
                'total' => '<strong>'.e(\App\Support\ReaderNumber::count($paginator->total())).'</strong>',
            ]) !!}
        </p>

        <div class="wf-pager__controls">
            @if ($paginator->onFirstPage())
                <span class="wf-pager__step" aria-disabled="true">
                    <svg class="wf-pager__chevron" width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    {{ __('pagination.previous') }}
                </span>
            @else
                <a class="wf-pager__step" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                    <svg class="wf-pager__chevron" width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    {{ __('pagination.previous') }}
                </a>
            @endif

            <span class="wf-pager__pages">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="wf-pager__gap" aria-disabled="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="wf-pager__page" aria-current="page">{{ \App\Support\ReaderNumber::count($page) }}</span>
                            @else
                                <a class="wf-pager__page" href="{{ $url }}" aria-label="{{ __('pagination.go_to_page', ['page' => \App\Support\ReaderNumber::count($page)]) }}">{{ \App\Support\ReaderNumber::count($page) }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>

            @if ($paginator->hasMorePages())
                <a class="wf-pager__step" href="{{ $paginator->nextPageUrl() }}" rel="next">
                    {{ __('pagination.next') }}
                    <svg class="wf-pager__chevron" width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                    </svg>
                </a>
            @else
                <span class="wf-pager__step" aria-disabled="true">
                    {{ __('pagination.next') }}
                    <svg class="wf-pager__chevron" width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                    </svg>
                </span>
            @endif
        </div>
    </nav>
@endif
