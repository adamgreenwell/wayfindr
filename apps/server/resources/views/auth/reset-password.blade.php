<x-layouts.app :title="__('signin.reset.title')">
    <main class="auth-page">
        <section class="panel" aria-labelledby="reset-heading">
            <h1 id="reset-heading">{{ __('signin.reset.title') }}</h1>
            <p class="lede">{{ __('signin.reset.lede') }}</p>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="field">
                    <label for="email">{{ __('signin.reset.email') }}</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        value="{{ old('email', $email) }}"
                        required
                    >
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">{{ __('signin.reset.password') }}</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                        autofocus
                    >
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">{{ __('signin.reset.confirm') }}</label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button class="button full" type="submit">{{ __('signin.reset.submit') }}</button>
            </form>
        </section>
    </main>
</x-layouts.app>
