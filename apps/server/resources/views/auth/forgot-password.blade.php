<x-layouts.app :title="__('signin.forgot.title')">
    <main class="auth-page">
        <section class="panel" aria-labelledby="forgot-heading">
            <h1 id="forgot-heading">{{ __('signin.forgot.title') }}</h1>
            <p class="lede">{{ __('signin.forgot.lede') }}</p>

            @if (session('status'))
                <div class="notice-copy">
                    <p>{{ session('status') }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="field">
                    <label for="email">{{ __('signin.forgot.email') }}</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                    >
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">{{ __('signin.forgot.submit') }}</button>
            </form>

            <p class="field-help">
                Reset links are delivered with the rest of this install's mail. If none arrives and none of
                your colleagues receive mail either, ask your operator to check the mail settings.
            </p>

            <p><a class="text-link" href="{{ route('login') }}">{{ __('signin.forgot.back') }}</a></p>
        </section>
    </main>
</x-layouts.app>
