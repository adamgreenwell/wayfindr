<x-layouts.app :title="__('visitor_erasure.page.title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('visitor_erasure.page.heading')" :back-href="route('dashboard.visitors.show', $visitor)" :back-label="__('visitor_erasure.page.back')">
        <p class="lede"><span lang="">{{ $visitor->site->name }}</span> · @if ($identity['is_theirs'])<span lang="">{{ $identity['label'] }}</span>@else<span>{{ $identity['label'] }}</span>@endif</p>
    </x-page-header>

    <p class="lede">{{ __('visitor_erasure.page.lede') }}</p>

    <section class="section" aria-labelledby="erasure-deleted-heading">
        <div class="section-header">
            <h2 id="erasure-deleted-heading">{{ __('visitor_erasure.deleted.heading') }}</h2>
        </div>

        <p>{{ __('visitor_erasure.deleted.identity') }}</p>

        <div class="meta-grid">
            @foreach (['conversations', 'messages', 'attachments', 'notes', 'ratings', 'cobrowse_sessions'] as $kind)
                <div class="meta-item">
                    <span class="meta-label">{{ __('visitor_erasure.deleted.'.$kind) }}</span>
                    <span class="meta-value" data-erasure-count="{{ $kind }}">{{ $summary['counts'][$kind] }}</span>
                </div>
            @endforeach
        </div>

        <p class="lede">{{ __('visitor_erasure.deleted.also') }}</p>
    </section>

    <section class="section" aria-labelledby="erasure-tickets-heading">
        <div class="section-header">
            <h2 id="erasure-tickets-heading">{{ __('visitor_erasure.tickets.heading') }}</h2>
        </div>

        @if ($summary['tickets']->isEmpty())
            <div class="empty empty-state">{{ __('visitor_erasure.tickets.empty') }}</div>
        @else
            <p>{{ __('visitor_erasure.tickets.body') }}</p>
            <ul>
                @foreach ($summary['tickets'] as $ticket)
                    <li data-erasure-ticket="{{ $ticket->id }}">#{{ $ticket->id }} · <span lang="">{{ $ticket->subject }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="section" aria-labelledby="erasure-unreachable-heading">
        <div class="section-header">
            <h2 id="erasure-unreachable-heading">{{ __('visitor_erasure.unreachable.heading') }}</h2>
        </div>

        <ul>
            <li>{{ __('visitor_erasure.unreachable.backups') }}</li>
            @if ($summary['external_issue_urls'] !== [])
                <li>
                    {{ __('visitor_erasure.unreachable.external') }}
                    <ul>
                        @foreach ($summary['external_issue_urls'] as $url)
                            <li><a href="{{ $url }}" rel="noreferrer noopener" target="_blank" lang="">{{ $url }}</a></li>
                        @endforeach
                    </ul>
                </li>
            @endif
            <li>{{ __('visitor_erasure.unreachable.mail') }}</li>
            <li>{{ __('visitor_erasure.unreachable.ai') }}</li>
            <li>{{ __('visitor_erasure.unreachable.api') }}</li>
            <li>{{ __('visitor_erasure.unreachable.future') }}</li>
        </ul>
    </section>

    <section class="section" aria-labelledby="erasure-confirm-heading">
        <div class="section-header">
            <h2 id="erasure-confirm-heading">{{ __('visitor_erasure.section.heading') }}</h2>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.visitors.erasure.store', $visitor) }}">
            @csrf
            <div class="field">
                <label for="erasure-confirmation">{!! __('visitor_erasure.form.confirm', ['word' => '<code lang="">'.e($confirmationWord).'</code>']) !!}</label>
                <input id="erasure-confirmation" name="confirmation" type="text" autocomplete="off" spellcheck="false" required>
                @error('confirmation')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="erasure-current-password">{{ __('visitor_erasure.form.password') }}</label>
                <input id="erasure-current-password" name="current_password" type="password" autocomplete="current-password" required>
                @error('current_password')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="section-actions">
                <button class="button danger" type="submit">{{ __('visitor_erasure.form.submit') }}</button>
                <a class="button secondary" href="{{ route('dashboard.visitors.show', $visitor) }}">{{ __('visitor_erasure.page.back') }}</a>
            </div>
        </form>
    </section>
</x-layouts.app>
