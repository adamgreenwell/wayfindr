<x-layouts.app title="Choose a new password">
    <main class="auth-page">
        <section class="panel" aria-labelledby="reset-heading">
            <h1 id="reset-heading">Choose a new password</h1>
            <p class="lede">This also signs you out everywhere else.</p>

            @if($fragment ?? false)
                <p id="reset-status" role="status" aria-live="polite">Checking your reset link…</p>
                <noscript><p>JavaScript is required to use this private reset link. The password has not been changed.</p></noscript>
            @endif

            <form class="auth-form" method="POST" action="{{ route('password.update') }}" aria-labelledby="reset-heading"
                @if($fragment ?? false) data-password-reset-fragment @endif
                @if($alreadyAuthenticated ?? false) data-reset-authenticated @endif>
                @csrf
                @unless($fragment ?? false)
                    <input type="hidden" name="token" value="{{ $token }}">
                @endunless

                <div class="field">
                    <label for="email">Email</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        value="{{ ($fragment ?? false) ? '' : old('email', $email) }}"
                        @disabled($fragment ?? false)
                        @error('email') aria-describedby="email-error" aria-invalid="true" @enderror
                        required
                    >
                    @error('email')
                        <p id="email-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">New password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                        autofocus
                        aria-describedby="password-help @error('password') password-error @enderror"
                        @error('password') aria-invalid="true" @enderror
                        @disabled($fragment ?? false)
                    >
                    {{-- The rule, stated before you can fail it. Neither password
                         screen said what was wanted until it rejected you. --}}
                    <p id="password-help" class="field-help">At least 12 characters. Longer is better than more complicated.</p>
                    @error('password')
                        <p id="password-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        @disabled($fragment ?? false)
                    >
                </div>

                <button class="button full" type="submit" @disabled($fragment ?? false)>Set new password</button>
            </form>

            @if($fragment ?? false)
                <p><a class="text-link" href="{{ route('login') }}" data-reset-sign-in hidden>Sign in with your new password</a></p>
                <script src="/js/password-reset-fragment.js" defer></script>
            @endif

            {{-- The same footer anchor forgot-password and the two-factor
                 challenge already carry. This page rendered ZERO links, and its
                 own error on a stale token says "Request a new one" -- naming a
                 destination it gave you no way to reach. --}}
            <p class="auth-footer"><a class="text-link" href="{{ route('password.request') }}">Request a new reset link</a></p>
        </section>
    </main>
</x-layouts.app>
