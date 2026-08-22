<x-layouts.app title="Choose a new password">
    <main class="auth-page">
        <section class="panel" aria-labelledby="reset-heading">
            <h1 id="reset-heading">Choose a new password</h1>
            <p class="lede">This also signs you out everywhere else.</p>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="field">
                    <label for="email">Email</label>
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
                    <label for="password">New password</label>
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
                    <label for="password_confirmation">Confirm new password</label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button class="button full" type="submit">Set new password</button>
            </form>
        </section>
    </main>
</x-layouts.app>
