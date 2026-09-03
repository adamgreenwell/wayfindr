<x-layouts.app :title="__('sites.create.document_title')" :agent="$agent" :account="$account">
    <x-page-header
        :title="__('sites.create.title')"
        :back-href="route('dashboard')"
        :back-label="__('sites.create.back')"
    >
        <x-slot:subtitleContent><x-translated-feedback :feedback="[
            'key' => 'sites.create.subtitle',
            'parameters' => ['account' => $account->name],
        ]" /></x-slot:subtitleContent>
    </x-page-header>

    <section class="section" aria-labelledby="new-site-heading">
        <div class="section-header">
            <h2 id="new-site-heading">{{ __('sites.create.details.heading') }}</h2>
            <span class="lede">{{ __('sites.create.details.public_key') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.sites.store') }}">
            @csrf

            <div class="field">
                <label for="name">{{ __('sites.create.fields.name') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus @if (filled(old('name'))) lang="" @endif>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="domain">{{ __('sites.create.fields.domain') }}</label>
                <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="wayfindr.cc" lang="">
                @error('domain')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <p class="field-help">{{ __('sites.create.fields.domain_help') }}</p>

            <button class="button" type="submit">{{ __('sites.create.submit') }}</button>
        </form>
    </section>
</x-layouts.app>
