<x-layouts.app title="Agent Login">
    <main class="auth-page">
        <section class="panel" aria-labelledby="login-heading">
            <h1 id="login-heading">Agent Login</h1>
            <p class="lede">Sign in to your Wayfindr support workspace.</p>

            {{-- The shape forgot-password.blade.php next door already uses.
                 PasswordResetController redirects here with a status after a
                 successful reset, and this page rendered no flash region at all,
                 so the confirmation was discarded: you land on sign-in with no
                 word that the reset worked, and no way to tell which password to
                 type. --}}
            @if (session('status'))
                <div class="notice-copy">
                    {{-- `__()` returns a non-key string unchanged, so this costs
                         nothing for the literals PasswordResetController flashes
                         and stops a raw key ever reaching the page. The rule is
                         in docs/product/dashboard-language.md: a flash belongs to
                         the destination, not the controller. --}}
                    <p>{{ __(session('status')) }}</p>
                </div>
            @endif

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
                        <p class="field-error">{{ __($message) }}</p>
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
                        <p class="field-error">{{ __($message) }}</p>
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
                        {{-- NOT `organization`: that is the token for a company
                             name, and browsers autofill "Acme Corp" into a field
                             whose own rule is /^[a-z0-9]+(?:-[a-z0-9]+)*$/ -- so
                             the autofill is rejected by the validator that
                             follows it. HTML has no token for a tenant slug.
                             (`organization` is right one view over, on
                             setup/create's free-text account name.) --}}
                        autocomplete="off"
                        value="{{ old('account_slug') }}"
                        required
                    >
                    @error('account_slug')
                        <p class="field-error">{{ __($message) }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">Sign in with SSO</button>
            </form>
        </section>
    </main>
</x-layouts.app>
