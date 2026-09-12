{{-- Laravel's built-in 429 is 6,602 bytes whose entire visible text is "Too
     Many Requests 429 Too Many Requests" -- no product name, no link, no way
     back, and no use made of the Retry-After it just computed.

     Handler::registerErrorViewPaths() puts resources/views/errors ahead of the
     framework's, and getHttpExceptionView() checks `errors::429` first, so this
     one file answers EVERY HTML 429 in the product. That is the constraint on
     the copy: the pre-auth routes (the two-factor challenge, the reset request,
     the reset itself) and authenticated ones (two-factor confirmation, recovery
     code regeneration, two-factor disablement, the operator AI test) all land
     here. So it says only what is true of all of them, and sends the reader
     somewhere that exists for whoever they are. --}}
<x-layouts.app title="Too many attempts">
    <main class="auth-page">
        <section class="panel" aria-labelledby="throttled-heading">
            <h1 id="throttled-heading">Too many attempts</h1>
            <p class="lede">Wait a moment, then try again.</p>

            @php($retryAfter = (int) ($exception?->getHeaders()['Retry-After'] ?? 0))
            @php($retryMinutes = max(1, (int) ceil($retryAfter / 60)))

            <div class="notice-copy">
                @if ($retryAfter > 0)
                    {{-- The framework already worked this out and put it in a
                         header nobody reads. Minutes, because a person waiting
                         does not count in seconds. --}}
                    <p>Try again in about {{ $retryMinutes }} {{ $retryMinutes === 1 ? 'minute' : 'minutes' }}.</p>
                @else
                    <p>Try again shortly.</p>
                @endif

                {{-- True on every route that reaches this page: the limit counts
                     attempts, and hitting it changes nothing about the account.
                     Deliberately says nothing about whether the attempt itself
                     was cancelled -- that depends on the action, and this view
                     cannot know which one sent the reader here. --}}
                <p>This limit counts how often the attempt can be made. It does not lock your account or change anything you have already saved.</p>
            </div>

            {{-- An authenticated agent who hit a throttled profile or operator
                 form is already signed in; offering them sign-in is no way out. --}}
            @auth
                <p><a class="text-link" href="{{ route('dashboard') }}">Back to the dashboard</a></p>
            @else
                <p><a class="text-link" href="{{ route('login') }}">Back to sign in</a></p>
            @endauth
        </section>
    </main>
</x-layouts.app>
