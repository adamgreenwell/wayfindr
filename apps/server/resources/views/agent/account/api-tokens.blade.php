<x-layouts.app title="API tokens" :agent="$agent" :account="$account">
    <x-page-header title="API tokens" subtitle="Programmatic access to this account's support data, for integrations you or somebody else builds." :back-href="route('dashboard.account.show')" back-label="Back to account">
        <x-slot:actions>
            {{-- Usable, not merely un-revoked. A token past its expiry is refused
                 at authentication and labelled Expired in the table below, so
                 counting it as active contradicts the same page. --}}
            <span class="lede">{{ $tokens->filter->isUsable()->count() }} active</span>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    @if ($issuedToken)
        <section class="section" aria-labelledby="api-token-issued-heading">
            <div class="section-header">
                <h2 id="api-token-issued-heading">Copy this now</h2>
                <span class="lede">Shown once</span>
            </div>
            <div class="notice-copy" data-state="warning">
                <p>
                    This is the only time this token is shown. Wayfindr stores a hash of it, not the token
                    itself, so it cannot be recovered &mdash; if you lose it, revoke it and issue another.
                </p>
                <p><code>{{ $issuedToken }}</code></p>
                <p>
                    Send it as <code>Authorization: Bearer &lt;token&gt;</code>. Treat it like a password:
                    anyone holding it can read this account's conversations and tickets.
                </p>
            </div>
        </section>
    @endif

    <section class="section" aria-labelledby="api-token-list-heading">
        <div class="section-header">
            <h2 id="api-token-list-heading">Tokens</h2>
            <span class="lede">{{ $tokens->count() }} {{ $tokens->count() === 1 ? 'token' : 'tokens' }}</span>
        </div>

        @if ($tokens->isEmpty())
            <div class="notice-copy">
                <p>No tokens yet. Nothing outside this dashboard can read this account's support data.</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Token</th>
                            <th scope="col">Reaches</th>
                            <th scope="col">Last used</th>
                            <th scope="col">State</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>
                                    <strong>{{ $token->name }}</strong>
                                    <span class="lede">Created {{ $token->created_at->diffForHumans() }}{{ $token->createdBy ? ' by '.$token->createdBy->name : '' }}</span>
                                </td>
                                <td><code>{{ $token->displayHint() }}</code></td>
                                <td>
                                    @php
                                        // Site access restricts an admin too, so a token can reach sites
                                        // its viewer cannot -- and naming those here would leak exactly
                                        // what site access hides. Named where the viewer supports them,
                                        // acknowledged without names where they do not.
                                        $namedSites = $token->sites->whereIn('id', $visibleSiteIds);
                                        $hiddenSiteCount = $token->sites->count() - $namedSites->count();
                                    @endphp
                                    @if ($token->restricts_sites && $token->sites->isEmpty())
                                        {{-- Restricted, and every site it named has been purged. Reaches
                                             nothing -- the opposite of what an empty relationship means
                                             for an unrestricted token. --}}
                                        <span class="lede">No sites &mdash; every site it was limited to has been purged</span>
                                    @elseif ($token->sites->isEmpty())
                                        <span class="lede">Every site on this account</span>
                                    @else
                                        <span class="lede">
                                            {{ $namedSites->pluck('name')->join(', ') }}@if ($namedSites->isNotEmpty() && $hiddenSiteCount > 0), @endif@if ($hiddenSiteCount > 0)sites you do not support@endif
                                        </span>
                                    @endif
                                    <span class="lede">{{ $token->abilities === [] ? 'No abilities' : implode(', ', $token->abilities) }}</span>
                                </td>
                                <td>
                                    {{-- The figure that separates a live token from a forgotten one. --}}
                                    @if ($token->last_used_at)
                                        {{ $token->last_used_at->diffForHumans() }}
                                    @else
                                        <span class="lede">Never used</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($token->isRevoked())
                                        Revoked {{ $token->revoked_at->diffForHumans() }}
                                    @elseif ($token->isExpired())
                                        Expired {{ $token->expires_at->diffForHumans() }}
                                    @elseif ($token->expires_at)
                                        Expires {{ $token->expires_at->diffForHumans() }}
                                    @else
                                        Active
                                    @endif
                                </td>
                                <td>
                                    @unless ($token->isRevoked())
                                        <form method="POST" action="{{ route('dashboard.account.api-tokens.destroy', $token) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button secondary" type="submit">Revoke</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="lede">
                Revoking keeps the row. What it existed for and when it was last used is the part worth
                keeping after somebody turns it off.
            </p>
        @endif
    </section>

    <section class="section" aria-labelledby="api-token-create-heading">
        <div class="section-header">
            <h2 id="api-token-create-heading">Issue a token</h2>
            <span class="lede">Read-only for now</span>
        </div>

            <form class="section-form" method="POST" action="{{ route('dashboard.account.api-tokens.store') }}">
                @csrf

                <div class="field">
                    <label for="api_token_name">What is it for</label>
                    <input type="text" id="api_token_name" name="name" maxlength="120" required
                        placeholder="Reporting sync" value="{{ old('name') }}">
                    <p class="field-help">Written for whoever finds this row in a year and has to decide whether it is still needed.</p>
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="api_token_abilities">What it may do</label>
                    <label for="api_token_read">
                        <input type="checkbox" id="api_token_read" name="abilities[]" value="read" checked>
                        Read conversations, messages, tickets and visitors
                    </label>
                    <p class="field-help">Writing is not offered yet. When it is, it will be a separate ability rather than implied by this one.</p>
                </div>

                <div class="field">
                    <label for="api_token_expires">Expires after</label>
                    <input type="number" id="api_token_expires" name="expires_in_days" min="1" max="730"
                        placeholder="90" value="{{ old('expires_in_days') }}">
                    <p class="field-help">Days. Left empty the token never expires, which means it stops being anybody's job to notice it.</p>
                    @error('expires_in_days')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                @if ($sites->isNotEmpty())
                    <div class="field">
                        <label for="api_token_sites">Restrict to sites</label>
                        @foreach ($sites as $site)
                            <label for="api_token_site_{{ $site->id }}">
                                <input type="checkbox" id="api_token_site_{{ $site->id }}" name="site_ids[]" value="{{ $site->id }}">
                                {{ $site->name }}
                            </label>
                        @endforeach
                        <p class="field-help">
                            {{-- Said where the decision is made rather than only in the docs. A token is
                                 always pinned to a list: it cannot reach further than the person issuing
                                 it, and it does not widen later as the account grows. --}}
                            Tick none and the token reaches every site <strong>you support today</strong>.
                            A site created afterwards is not added to it &mdash; issue a new token when you
                            want one to cover more. An integration that watches one site should not be a
                            credential for all of them.
                        </p>
                    </div>
                @endif

                <button class="button" type="submit">Issue token</button>
            </form>

        <div class="notice-copy">
            <p>
                A token has no person behind it, so a read made with one cannot answer <em>who</em> read it the
                way a dashboard read can. That is why a token is limited by what it can reach rather than by
                who is holding it &mdash; and why an operator access grant never widens one.
            </p>
        </div>
    </section>
</x-layouts.app>
