{{-- Laravel's built-in 429 is 6,602 bytes whose entire visible text is "Too
     Many Requests 429 Too Many Requests" -- no product name, no link, no way
     back, and no use made of the Retry-After it just computed.

     Handler::registerErrorViewPaths() puts resources/views/errors ahead of the
     framework's, and getHttpExceptionView() checks `errors::429` first, so this
     one file answers EVERY html 429 in the product. Two consequences, both
     load-bearing:

     1. The copy says only what is true of every route that reaches it. The
        pre-auth ones (the two-factor challenge, the reset request, the reset
        itself) and the authenticated ones (two-factor confirmation, recovery
        code regeneration, two-factor disablement, the operator AI test) all
        land here, so it cannot claim the attempt was or was not cancelled --
        that depends on the action, which this view cannot know.

     2. The copy is TRANSLATED. Those authenticated routes are extracted, so
        SetDashboardLocale has already resolved the agent's own language and
        x-layouts.app renders `<html lang="de">`; hard-coded English inside that
        makes a screen reader pronounce English with German phonetics. Before
        sign-in the same keys resolve to English, because those routes are not
        extracted -- so both audiences get one language throughout. --}}
<x-layouts.app :title="__('errors.throttled.document_title')">
    <main class="auth-page">
        <section class="panel" aria-labelledby="throttled-heading">
            <h1 id="throttled-heading">{{ __('errors.throttled.heading') }}</h1>
            <p class="lede">{{ __('errors.throttled.lede') }}</p>

            @php($retryAfter = (int) ($exception?->getHeaders()['Retry-After'] ?? 0))

            <div class="notice-copy">
                @if ($retryAfter > 0)
                    {{-- The framework already worked this out and put it in a
                         header nobody reads. Minutes, because a person waiting
                         does not count in seconds. --}}
                    <p>{{ trans_choice('errors.throttled.retry_in', max(1, (int) ceil($retryAfter / 60))) }}</p>
                @else
                    <p>{{ __('errors.throttled.retry_soon') }}</p>
                @endif

                <p>{{ __('errors.throttled.scope') }}</p>
            </div>

            {{-- An authenticated agent who hit a throttled profile or operator
                 form is already signed in; offering them sign-in is no way out. --}}
            @auth
                <p><a class="text-link" href="{{ route('dashboard') }}">{{ __('errors.throttled.back_dashboard') }}</a></p>
            @else
                <p><a class="text-link" href="{{ route('login') }}">{{ __('errors.throttled.back_sign_in') }}</a></p>
            @endauth
        </section>
    </main>
</x-layouts.app>
