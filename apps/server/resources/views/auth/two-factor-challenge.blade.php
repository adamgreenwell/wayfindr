<x-layouts.app :title="__('two_factor.challenge.document_title')">
    <main class="auth-page">
        <section class="panel" aria-labelledby="two-factor-challenge-heading">
            <h1 id="two-factor-challenge-heading">{{ __('two_factor.challenge.heading') }}</h1>
            <p class="lede">{{ __('two_factor.challenge.lede') }}</p>

            <form method="POST" action="{{ route('two-factor.challenge.store') }}">
                @csrf

                <div class="field">
                    <label for="one_time_code">{{ __('two_factor.challenge.code') }}</label>
                    <input
                        id="one_time_code"
                        name="one_time_code"
                        autocomplete="one-time-code"
                        required
                        autofocus
                    >
                    <p class="field-help">{{ __('two_factor.challenge.help') }}</p>
                    @error('one_time_code')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="button full" type="submit">{{ __('two_factor.challenge.submit') }}</button>
            </form>

            <p><a class="text-link" href="{{ route('login') }}">{{ __('two_factor.challenge.back') }}</a></p>
        </section>
    </main>
</x-layouts.app>
