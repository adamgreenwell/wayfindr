<x-layouts.app title="Operator access" :agent="$agent" :account="$account">
            <x-page-header title="Operator access" subtitle="Break-glass requests and grants touching this account — approve, deny, or revoke." :back-href="route('dashboard.account.show')" back-label="Back to account">
                <x-slot:actions>
                    <span class="lede">{{ $activeGrants->count() }} active</span>
                </x-slot:actions>
            </x-page-header>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="break-glass-pending-heading">
                <div class="section-header">
                    <h2 id="break-glass-pending-heading">Awaiting your approval</h2>
                    <span class="lede">{{ $pendingGrants->count() }} pending</span>
                </div>

                @if ($pendingGrants->isEmpty())
                    <div class="notice-copy">
                        <p>No pending requests. A platform operator can only reach this account's support content through a request on this page.</p>
                    </div>
                @else
                    <div class="management-list">
                        @foreach ($pendingGrants as $grant)
                            <div class="management-link">
                                <span>
                                    <strong>{{ $grant->scopeLabel() }} · {{ $grant->requested_minutes }} min · read-only</strong>
                                    <span class="lede">{{ $grant->requester?->name ?? 'Former operator' }}: {{ $grant->reason }}</span>
                                    <span class="lede">Requested {{ $grant->created_at->diffForHumans() }}</span>
                                </span>
                                <span class="compact-actions">
                                    <form class="compact-form" method="POST" action="{{ route('dashboard.account.break-glass.approve', $grant) }}">
                                        @csrf
                                        <button class="button" type="submit">Approve</button>
                                    </form>
                                    <form class="compact-form" method="POST" action="{{ route('dashboard.account.break-glass.deny', $grant) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">Deny</button>
                                    </form>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="break-glass-active-heading">
                <div class="section-header">
                    <h2 id="break-glass-active-heading">Active grants</h2>
                    <span class="lede">{{ $activeGrants->count() }} open</span>
                </div>

                @if ($activeGrants->isEmpty())
                    <div class="notice-copy">
                        <p>No operator can see this account's support content right now.</p>
                    </div>
                @else
                    <div class="management-list">
                        @foreach ($activeGrants as $grant)
                            <div class="management-link">
                                <span>
                                    <strong>{{ $grant->scopeLabel() }} — expires {{ $grant->expires_at->diffForHumans() }}</strong>
                                    <span class="lede">{{ $grant->requester?->name ?? 'Former operator' }}: {{ $grant->reason }}</span>
                                    <span class="lede">
                                        {{ $grant->self_approved ? 'Self-approved (no other admin existed)' : 'Approved by '.($grant->approver?->name ?? 'a former admin') }}
                                        {{ $grant->approved_at?->diffForHumans() }}
                                    </span>
                                </span>
                                <span class="compact-actions">
                                    <form class="compact-form" method="POST" action="{{ route('dashboard.account.break-glass.close', $grant) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">Revoke now</button>
                                    </form>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="break-glass-history-heading">
                <div class="section-header">
                    <h2 id="break-glass-history-heading">Past grants</h2>
                    <span class="lede">{{ $pastGrants->count() }} shown</span>
                </div>

                @if ($pastGrants->isEmpty())
                    <div class="notice-copy">
                        <p>No past grants.</p>
                    </div>
                @else
                    <div class="management-list">
                        @foreach ($pastGrants as $grant)
                            <div class="management-link">
                                <span>
                                    <strong>{{ $grant->scopeLabel() }} — {{ $grant->statusLabel() }}</strong>
                                    <span class="lede">{{ $grant->requester?->name ?? 'Former operator' }}: {{ $grant->reason }}</span>
                                    <span class="lede">Requested {{ $grant->created_at->diffForHumans() }}{{ $grant->self_approved ? ' · self-approved' : '' }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
</x-layouts.app>
