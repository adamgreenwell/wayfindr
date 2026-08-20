<x-layouts.operator title="Operator access">
    <h1>Operator access</h1>
    <p class="lede">
        You cannot see any account's conversations or tickets by default. Ask here when you
        need to, for one conversation, one site or one account. The account sees your reason,
        approves or denies it, and can end it at any point. Access is read-only and expires on
        its own, and every page you open is recorded for them.
    </p>

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    <section class="section" aria-labelledby="break-glass-request-heading">
        <div class="section-header">
            <h2 id="break-glass-request-heading">Ask for access</h2>
            <span class="lede">Ask for the least that answers your question</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.break-glass.store') }}">
            @csrf
            <div class="meta-grid">
                <div class="meta-item">
                    <label class="meta-label" for="scope_type">What do you need to see?</label>
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
                    <label class="meta-label" for="support_code">Support code</label>
                    <input id="support_code" name="support_code" type="text" value="{{ old('support_code') }}" placeholder="WF-XXXXXX">
                    <p class="field-help">Fill this in if you chose one conversation.</p>
                    @error('support_code')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="site_id">Site</label>
                    <select id="site_id" name="site_id">
                        <option value="">Choose a site</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected((int) old('site_id') === $site->id)>{{ $site->name }} — {{ $site->account?->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">Fill this in if you chose one site.</p>
                    @error('site_id')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="account_id">Account</label>
                    <select id="account_id" name="account_id">
                        <option value="">Choose an account</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">Fill this in if you chose an entire account.</p>
                    @error('account_id')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="requested_minutes">How long do you need it?</label>
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
                <label class="meta-label" for="reason">Why do you need it?</label>
                <textarea id="reason" name="reason" rows="3" required maxlength="1000" placeholder="What are you investigating, and why does answering it need this account's content?">{{ old('reason') }}</textarea>
                @error('reason')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="button" type="submit">Request access</button>
        </form>
    </section>

    <section class="section" aria-labelledby="break-glass-grants-heading">
        <div class="section-header">
            <h2 id="break-glass-grants-heading">Your requests</h2>
            <span class="lede">{{ $ownGrants->count() }} recent</span>
        </div>

        @if ($ownGrants->isEmpty())
            <div class="notice-copy">
                <p>You have not asked for access to any account yet. Support data is closed to operators until an account opens it.</p>
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
</x-layouts.operator>
