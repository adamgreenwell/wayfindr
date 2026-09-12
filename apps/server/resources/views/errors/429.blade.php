{{-- Laravel's built-in 429 is 6,602 bytes whose entire visible text is "Too
     Many Requests 429 Too Many Requests" -- no product name, no link, no way
     back. Three pre-auth routes throttle (the two-factor challenge, the reset
     request, and the reset itself), so the person most likely to meet this page
     is someone locked out and already anxious about it.

     Handler::registerErrorViewPaths() puts resources/views/errors ahead of the
     framework's, and getHttpExceptionView() checks `errors::429` first, so this
     one file covers all three routes. --}}
<x-layouts.app title="Too many attempts">
    <main class="auth-page">
        <section class="panel" aria-labelledby="throttled-heading">
            <h1 id="throttled-heading">Too many attempts</h1>
            <p class="lede">Wait a moment, then try again.</p>

            @php($retryAfter = (int) ($exception?->getHeaders()['Retry-After'] ?? 0))

            <div class="notice-copy">
                @if ($retryAfter > 0)
                    {{-- The framework already computed this and put it in a header
                         nobody reads. Minutes, because a person waiting does not
                         count in seconds. --}}
                    <p>Try again in about {{ max(1, (int) ceil($retryAfter / 60)) }} {{ max(1, (int) ceil($retryAfter / 60)) === 1 ? 'minute' : 'minutes' }}.</p>
                @else
                    <p>Try again shortly.</p>
                @endif

                {{-- The thing a locked-out person actually wants to know. --}}
                <p>Nothing has been cancelled, and your account is not locked. The limit is on how often this can be attempted.</p>
            </div>

            <p><a class="text-link" href="{{ route('login') }}">Back to sign in</a></p>
        </section>
    </main>
</x-layouts.app>
