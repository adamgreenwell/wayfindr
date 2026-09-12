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
                    aria-describedby="@error('account_name') account_name-error @enderror"
                    @error('account_name') aria-invalid="true" @enderror
                    >
                    @error('account_name')
                        <p id="account_name-error" class="field-error">{{ $message }}</p>
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
                        aria-describedby="@error('agent_name') agent_name-error @enderror"
                        @error('agent_name') aria-invalid="true" @enderror
                        required
                    >
                    @error('agent_name')
                        <p id="agent_name-error" class="field-error">{{ $message }}</p>
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
                        aria-describedby="@error('agent_email') agent_email-error @enderror"
                        @error('agent_email') aria-invalid="true" @enderror
                        required
                    >
                    @error('agent_email')
                        <p id="agent_email-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        aria-describedby="password-help @error('password') password-error @enderror"
                        @error('password') aria-invalid="true" @enderror
                        required
                    >
                    {{-- The rule, stated before you can fail it. Neither password
                         screen said what was wanted until it rejected you. --}}
                    <p id="password-help" class="field-help">At least 12 characters. Longer is better than more complicated.</p>
                    @error('password')
                        <p id="password-error" class="field-error">{{ $message }}</p>
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
                        aria-describedby="@error('site_name') site_name-error @enderror"
                        @error('site_name') aria-invalid="true" @enderror
                        required
                    >
                    @error('site_name')
                        <p id="site_name-error" class="field-error">{{ $message }}</p>
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
                        aria-describedby="@error('site_domain') site_domain-error @enderror"
                        @error('site_domain') aria-invalid="true" @enderror
                    >
                    <p class="field-help">Optional. You can connect more sites later.</p>
                    @error('site_domain')
                        <p id="site_domain-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">Create workspace</button>
            </form>
        </section>
    </main>
</x-layouts.app>
