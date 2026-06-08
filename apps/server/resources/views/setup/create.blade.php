<x-layouts.app title="Set up Wayfindr">
    <main class="auth-page">
        <section class="panel setup-panel" aria-labelledby="setup-heading">
            <h1 id="setup-heading">{{ $hasIncompleteBootstrapRecords ? 'Finish setting up Wayfindr' : 'Set up Wayfindr' }}</h1>
            <p class="lede">Create the first account, owner, and install site.</p>

            @if ($hasIncompleteBootstrapRecords)
                <div class="notice-copy notice-copy-bordered">
                    <p><strong>Some first-run records already exist, but no account owner has been created yet.</strong></p>
                    <p>Use this form to claim the install and reuse the earliest account and site records Wayfindr can find.</p>
                </div>
            @endif

            <form method="POST" action="{{ route('setup.store') }}">
                @csrf

                <div class="field">
                    <label for="account_name">Account name</label>
                    <input
                        id="account_name"
                        name="account_name"
                        type="text"
                        autocomplete="organization"
                        value="{{ old('account_name') }}"
                        required
                        autofocus
                    >
                    @error('account_name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="agent_name">Your name</label>
                    <input
                        id="agent_name"
                        name="agent_name"
                        type="text"
                        autocomplete="name"
                        value="{{ old('agent_name') }}"
                        required
                    >
                    @error('agent_name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="agent_email">Email</label>
                    <input
                        id="agent_email"
                        name="agent_email"
                        type="email"
                        autocomplete="email"
                        value="{{ old('agent_email') }}"
                        required
                    >
                    @error('agent_email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="site_name">Site name</label>
                    <input
                        id="site_name"
                        name="site_name"
                        type="text"
                        value="{{ old('site_name') }}"
                        required
                    >
                    @error('site_name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="site_domain">Site domain</label>
                    <input
                        id="site_domain"
                        name="site_domain"
                        type="text"
                        inputmode="url"
                        value="{{ old('site_domain') }}"
                        placeholder="docs.example.com"
                    >
                    <p class="field-help">Optional. You can connect more sites later.</p>
                    @error('site_domain')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">Create workspace</button>
            </form>
        </section>
    </main>
</x-layouts.app>
