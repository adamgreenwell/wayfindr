<x-layouts.app title="Add Site" :agent="$agent" :account="$account">
            <x-page-header title="Add site" :subtitle="'Create a new Wayfindr install target for '.$account->name.'.'" :back-href="route('dashboard')" back-label="Back to dashboard" />

            <section class="section" aria-labelledby="new-site-heading">
                <div class="section-header">
                    <h2 id="new-site-heading">Site details</h2>
                    <span class="lede">Public key generated automatically</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.sites.store') }}">
                    @csrf

                    <div class="field">
                        <label for="name">Site name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus>
                        @error('name')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="domain">Domain</label>
                        <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="wayfindr.cc">
                        @error('domain')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <p class="field-help">
                        You can paste a full URL here. Wayfindr stores the host name so the install target stays tidy.
                    </p>

                    <button class="button" type="submit">Create site</button>
                </form>
            </section>
</x-layouts.app>
