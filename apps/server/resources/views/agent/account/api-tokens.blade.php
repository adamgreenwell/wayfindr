<x-layouts.app :title="__('api_tokens.title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('api_tokens.title')" :subtitle="__('api_tokens.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('api_tokens.back')">
        <x-slot:actions>
            {{-- Usable, not merely un-revoked. A token past its expiry is refused
                 at authentication and labelled Expired in the table below, so
                 counting it as active would contradict the same page. --}}
            <span class="lede">{{ __('api_tokens.active', ['count' => \App\Support\ReaderNumber::count($tokens->filter->isUsable()->count())]) }}</span>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        {{-- A KEY rather than a sentence: the request that redirected and the
             request that renders are different requests, and the agent's
             language is resolved per request. --}}
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    @if ($issuedToken)
        <section class="section" aria-labelledby="api-token-issued-heading">
            <div class="section-header">
                <h2 id="api-token-issued-heading">{{ __('api_tokens.issued.heading') }}</h2>
                <span class="lede">{{ __('api_tokens.issued.once') }}</span>
            </div>
            <div class="notice-copy" data-state="warning">
                <p>{{ __('api_tokens.issued.hashed') }}</p>
                <p><code>{{ $issuedToken }}</code></p>
                <p>
                    {{-- The header is a WIRE FORMAT, not copy: it is sent
                         verbatim or the request fails. It is passed in rather
                         than translated, and is identical in every language on
                         purpose -- the same reasoning as an IANA zone name. --}}
                    {!! __('api_tokens.issued.send_as', ['header' => '<code>Authorization: Bearer &lt;token&gt;</code>']) !!}
                </p>
            </div>
        </section>
    @endif

    <section class="section" aria-labelledby="api-token-list-heading">
        <div class="section-header">
            <h2 id="api-token-list-heading">{{ __('api_tokens.list.heading') }}</h2>
            <span class="lede">{{ trans_choice('api_tokens.list.total', $tokens->count(), ['count' => \App\Support\ReaderNumber::count($tokens->count())]) }}</span>
        </div>

        @if ($tokens->isEmpty())
            <div class="notice-copy">
                <p>{{ __('api_tokens.list.empty') }}</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('api_tokens.list.column_name') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_token') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_reaches') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_last_used') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_state') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>
                                    {{-- The token's name, the sites it reaches and the
                                         agent who issued it are the account's own words.
                                         `lang=""` is HTML's "unknown": the page around
                                         them is German, these are not. --}}
                                    <strong lang="">{{ $token->name }}</strong>
                                    {{-- Two whole sentences rather than one with an
                                         optional tail. German puts the issuing agent
                                         somewhere English does not, and a sentence
                                         assembled by concatenation cannot move it. --}}
                                    <span class="lede">
                                        @if ($token->createdBy)
                                            {!! __('api_tokens.list.created_by', [
                                                'when' => e($token->created_at->diffForHumans()),
                                                'name' => '<span lang="">'.e($token->createdBy->name).'</span>',
                                            ]) !!}
                                        @else
                                            {{ __('api_tokens.list.created', ['when' => $token->created_at->diffForHumans()]) }}
                                        @endif
                                    </span>
                                </td>
                                <td><code lang="">{{ $token->displayHint() }}</code></td>
                                <td>
                                    @php
                                        // Site access restricts an admin too, so a token can reach sites
                                        // its viewer cannot -- and naming those here would leak exactly
                                        // what site access hides. Named where the viewer supports them,
                                        // acknowledged without names where they do not.
                                        $namedSites = $token->sites->whereIn('id', $visibleSiteIds);
                                        $hiddenSiteCount = $token->sites->count() - $namedSites->count();

                                        // Abilities ARE translated, unlike the header above. The `read`
                                        // slug is what the API takes, but nobody types it -- it is a
                                        // checkbox on this very page, and this column exists so an admin
                                        // can see at a glance what a token may do. An unknown slug falls
                                        // back to itself rather than rendering a missing-key path.
                                        $abilityLabels = collect($token->abilities)
                                            ->map(fn (string $ability): string => \Illuminate\Support\Facades\Lang::has('api_tokens.abilities.'.$ability)
                                                ? __('api_tokens.abilities.'.$ability)
                                                : $ability)
                                            ->join(', ');
                                    @endphp
                                    @if ($token->restricts_sites && $token->sites->isEmpty())
                                        {{-- Restricted, and every site it named has been purged. Reaches
                                             nothing -- the opposite of what an empty relationship means
                                             for an unrestricted token. --}}
                                        <span class="lede">{{ __('api_tokens.reaches.purged') }}</span>
                                    @elseif ($token->sites->isEmpty())
                                        <span class="lede">{{ __('api_tokens.reaches.every_site') }}</span>
                                    @else
                                        <span class="lede">
                                            <span lang="">{{ $namedSites->pluck('name')->join(', ') }}</span>{{ $namedSites->isNotEmpty() && $hiddenSiteCount > 0 ? ', ' : '' }}{{ $hiddenSiteCount > 0 ? __('api_tokens.reaches.unsupported') : '' }}
                                        </span>
                                    @endif
                                    <span class="lede">{{ $token->abilities === [] ? __('api_tokens.reaches.no_abilities') : $abilityLabels }}</span>
                                </td>
                                <td>
                                    {{-- The figure that separates a live token from a forgotten one. --}}
                                    @if ($token->last_used_at)
                                        {{ $token->last_used_at->diffForHumans() }}
                                    @else
                                        <span class="lede">{{ __('api_tokens.last_used.never') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($token->isRevoked())
                                        {{ __('api_tokens.state.revoked', ['when' => $token->revoked_at->diffForHumans()]) }}
                                    @elseif ($token->isExpired())
                                        {{ __('api_tokens.state.expired', ['when' => $token->expires_at->diffForHumans()]) }}
                                    @elseif ($token->expires_at)
                                        {{ __('api_tokens.state.expires', ['when' => $token->expires_at->diffForHumans()]) }}
                                    @else
                                        {{ __('api_tokens.state.active') }}
                                    @endif
                                </td>
                                <td>
                                    @unless ($token->isRevoked())
                                        <form method="POST" action="{{ route('dashboard.account.api-tokens.destroy', $token) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button secondary" type="submit">{{ __('api_tokens.list.revoke') }}</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="lede">{{ __('api_tokens.list.revoking_keeps') }}</p>
        @endif
    </section>

    <section class="section" aria-labelledby="api-token-create-heading">
        <div class="section-header">
            <h2 id="api-token-create-heading">{{ __('api_tokens.create.heading') }}</h2>
            <span class="lede">{{ __('api_tokens.create.read_only') }}</span>
        </div>

            <form class="section-form" method="POST" action="{{ route('dashboard.account.api-tokens.store') }}">
                @csrf

                <div class="field">
                    <label for="api_token_name">{{ __('api_tokens.create.name_label') }}</label>
                    <input type="text" id="api_token_name" name="name" maxlength="120" required
                        placeholder="{{ __('api_tokens.create.name_placeholder') }}" value="{{ old('name') }}">
                    <p class="field-help">{{ __('api_tokens.create.name_help') }}</p>
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="api_token_abilities">{{ __('api_tokens.create.abilities_label') }}</label>
                    <label for="api_token_read">
                        <input type="checkbox" id="api_token_read" name="abilities[]" value="read" checked>
                        {{ __('api_tokens.create.ability_read') }}
                    </label>
                    <p class="field-help">{{ __('api_tokens.create.abilities_help') }}</p>
                </div>

                <div class="field">
                    <label for="api_token_expires">{{ __('api_tokens.create.expires_label') }}</label>
                    <input type="number" id="api_token_expires" name="expires_in_days" min="1" max="730"
                        placeholder="90" value="{{ old('expires_in_days') }}">
                    <p class="field-help">{{ __('api_tokens.create.expires_help') }}</p>
                    @error('expires_in_days')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                @if ($sites->isNotEmpty())
                    <div class="field">
                        <label for="api_token_sites">{{ __('api_tokens.create.sites_label') }}</label>
                        @foreach ($sites as $site)
                            <label for="api_token_site_{{ $site->id }}">
                                <input type="checkbox" id="api_token_site_{{ $site->id }}" name="site_ids[]" value="{{ $site->id }}">
                                <span lang="">{{ $site->name }}</span>
                            </label>
                        @endforeach
                        <p class="field-help">
                            {{-- Said where the decision is made rather than only in the docs. A token is
                                 always pinned to a list: it cannot reach further than the person issuing
                                 it, and it does not widen later as the account grows. --}}
                            {!! __('api_tokens.create.sites_help', ['today' => '<strong>'.e(__('api_tokens.create.sites_help_today')).'</strong>']) !!}
                        </p>
                    </div>
                @endif

                <button class="button" type="submit">{{ __('api_tokens.create.submit') }}</button>
            </form>

        <div class="notice-copy">
            <p>{!! __('api_tokens.accountability', ['who' => '<em>'.e(__('api_tokens.accountability_who')).'</em>']) !!}</p>
        </div>
    </section>
</x-layouts.app>
