<x-layouts.app title="Reset your password">
    <main class="auth-page">
        <section class="panel" aria-labelledby="forgot-heading">
            <h1 id="forgot-heading">Reset your password</h1>
            <p class="lede">We will email you a link to set a new one.</p>

            @if (session('status'))
                <p class="status-message" role="status">{{ session('status') }}</p>
            @endif

            <form class="auth-form" method="POST" action="{{ route('password.email') }}" aria-labelledby="forgot-heading">
                @csrf

                <div class="field">
                    <label for="email">Email</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        @error('email') aria-describedby="email-error" aria-invalid="true" @enderror
                    >
                    @error('email')
                        <p id="email-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">Email me a reset link</button>
            </form>

            <p class="field-help">
                Reset links are delivered with the rest of this install's mail. If none arrives and none of
                your colleagues receive mail either, ask your operator to check the mail settings.
            </p>

            <p class="auth-footer"><a class="text-link" href="{{ route('login') }}">Back to sign in</a></p>
        </section>
    </main>
</x-layouts.app>
