<x-layouts.app title="Agent Login">
    <main class="auth-page">
        <section class="panel" aria-labelledby="login-heading">
            <h1 id="login-heading">Agent Login</h1>
            <p class="lede">Sign in to your Wayfindr support workspace.</p>

            <form method="POST" action="{{ route('login.store') }}">
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
                    >
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">Password</label>
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
                    Remember this browser
                </label>

                <button class="button full" type="submit">Sign in</button>
            </form>

            <p><a class="text-link" href="{{ route('password.request') }}">Forgotten your password?</a></p>

            <hr>

            <h2>Single sign-on</h2>
            <p class="lede">Use your organization's identity provider with your Wayfindr account slug.</p>

            <form method="POST" action="{{ route('oidc.redirect') }}">
                @csrf

                <div class="field">
                    <label for="account_slug">Account slug</label>
                    <input
                        id="account_slug"
                        name="account_slug"
                        type="text"
                        autocomplete="organization"
                        value="{{ old('account_slug') }}"
                        required
                    >
                    @error('account_slug')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">Sign in with SSO</button>
            </form>
        </section>
    </main>
</x-layouts.app>
