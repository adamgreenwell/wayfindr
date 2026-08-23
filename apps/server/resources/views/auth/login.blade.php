<x-layouts.app :title="__('signin.login.title')">
    <main class="auth-page">
        <section class="panel" aria-labelledby="login-heading">
            <h1 id="login-heading">{{ __('signin.login.title') }}</h1>
            <p class="lede">{{ __('signin.login.lede') }}</p>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <div class="field">
                    <label for="email">{{ __('signin.login.email') }}</label>
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

                <div class="field">
                    <label for="password">{{ __('signin.login.password') }}</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <label class="check-row" for="remember">
                    <input id="remember" name="remember" type="checkbox" value="1">
                    {{ __('signin.login.remember') }}
                </label>

                <button class="button full" type="submit">{{ __('signin.login.submit') }}</button>
            </form>

            <p><a class="text-link" href="{{ route('password.request') }}">{{ __('signin.login.forgot') }}</a></p>
        </section>
    </main>
</x-layouts.app>
