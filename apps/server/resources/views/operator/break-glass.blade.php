<x-layouts.app title="Break-glass access">
    <p><a class="text-link" href="{{ route('operator.dashboard') }}">Back to operator console</a></p>
    <h1>Break-glass access</h1>
    <p class="lede">
        Scoped, reasoned, time-bound, read-only. Every request and every view is visible to the account it touches.
    </p>

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    <section class="section" aria-labelledby="break-glass-request-heading">
        <div class="section-header">
            <h2 id="break-glass-request-heading">Request access</h2>
            <span class="lede">Narrowest scope that answers the question</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.break-glass.store') }}">
            @csrf
            <div class="meta-grid">
                <div class="meta-item">
                    <label class="meta-label" for="scope_type">Scope</label>
                    <select id="scope_type" name="scope_type" required>
                        <option value="conversation" @selected(old('scope_type', 'conversation') === 'conversation')>One conversation (support code)</option>
                        <option value="site" @selected(old('scope_type') === 'site')>One site</option>
                        <option value="account" @selected(old('scope_type') === 'account')>Entire account</option>
                    </select>
                    @error('scope_type')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="support_code">Support code (conversation scope)</label>
                    <input id="support_code" name="support_code" type="text" value="{{ old('support_code') }}" placeholder="WF-XXXXXX">
                    @error('support_code')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="site_id">Site (site scope)</label>
                    <select id="site_id" name="site_id">
                        <option value="">Choose a site</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected((int) old('site_id') === $site->id)>{{ $site->name }} — {{ $site->account?->name }}</option>
                        @endforeach
                    </select>
                    @error('site_id')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="account_id">Account (account scope)</label>
                    <select id="account_id" name="account_id">
                        <option value="">Choose an account</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    @error('account_id')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="requested_minutes">Duration</label>
                    <select id="requested_minutes" name="requested_minutes" required>
                        @foreach ($durationChoices as $minutes => $label)
                            <option value="{{ $minutes }}" @selected((int) old('requested_minutes', $defaultMinutes) === $minutes)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('requested_minutes')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="meta-item">
                <label class="meta-label" for="reason">Reason (shown to the account)</label>
                <textarea id="reason" name="reason" rows="2" required maxlength="1000" placeholder="What are you investigating, and why does it need customer content?">{{ old('reason') }}</textarea>
                @error('reason')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="button" type="submit">Request access</button>
        </form>
    </section>

    <section class="section" aria-labelledby="break-glass-grants-heading">
        <div class="section-header">
            <h2 id="break-glass-grants-heading">Your grants</h2>
            <span class="lede">{{ $ownGrants->count() }} recent</span>
        </div>

        @if ($ownGrants->isEmpty())
            <div class="notice-copy">
                <p>No break-glass requests yet. Access to customer support data always starts with one.</p>
            </div>
        @else
            <div class="management-list">
                @foreach ($ownGrants as $grant)
                    @php $hint = $approvalHints->get($grant->id); @endphp
                    <div class="management-link">
                        <span>
                            <strong>{{ $grant->scopeLabel() }} — {{ $grant->statusLabel() }}</strong>
                            <span class="lede">{{ $grant->account?->name }} · {{ $grant->reason }}</span>
                            <span class="lede">
                                Requested {{ $grant->created_at->diffForHumans() }}
                                @if ($grant->isActive())
                                    · expires {{ $grant->expires_at->diffForHumans() }}
                                @elseif ($grant->status === \App\Models\BreakGlassGrant::STATUS_REQUESTED && $hint && ! $hint['can_self_approve'])
                                    · waiting on {{ implode(', ', $hint['waiting_on']) ?: 'an account owner or admin' }}
                                @endif
                            </span>
                        </span>
                        <span class="compact-actions">
                            @if ($hint && $hint['can_self_approve'])
                                <form class="compact-form" method="POST" action="{{ route('operator.break-glass.approve', $grant) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Self-approve</button>
                                </form>
                            @endif
                            @if ($grant->isActive())
                                <a class="button" href="{{ route('operator.break-glass.show', $grant) }}">Open access</a>
                                <form class="compact-form" method="POST" action="{{ route('operator.break-glass.close', $grant) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Close now</button>
                                </form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
