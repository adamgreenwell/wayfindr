<x-layouts.app title="Account Audit" :agent="$agent" :account="$account">
            <x-page-header title="Account audit" subtitle="Search account-level activity without exposing raw event metadata." :back-href="route('dashboard.account.show')" back-label="Back to account">
                <x-slot:actions>
                    <span class="lede">{{ $auditEvents->count() }} shown</span>
                    <a class="button secondary" href="{{ route('dashboard.account.audit.export', $auditQuery) }}">Export CSV</a>
                </x-slot:actions>
            </x-page-header>

            <section class="section" aria-labelledby="audit-responsibility-heading">
                <div class="section-header">
                    <h2 id="audit-responsibility-heading">Export boundary</h2>
                    <span class="lede">Metadata stays private</span>
                </div>
                <div class="notice-copy">
                    <p>Audit exports include time, action, actor, subject, and site. Raw metadata is intentionally omitted so temporary passwords, tokens, and other sensitive values do not wander into spreadsheets.</p>
                    <p>Site-backed activity is limited to sites visible to your current support scope.</p>
                </div>
            </section>

            <section class="section" aria-labelledby="audit-filters-heading">
                <div class="section-header">
                    <h2 id="audit-filters-heading">Filters</h2>
                    <span class="lede">{{ $auditAction || $auditSearch || $auditSiteId ? 'Filtered' : 'All visible activity' }}</span>
                </div>
                <form class="section-form" method="GET" action="{{ route('dashboard.account.audit.index') }}">
                    <div class="meta-grid">
                        <div class="meta-item">
                            <label class="meta-label" for="audit_action">Action</label>
                            <select id="audit_action" name="audit_action">
                                <option value="">Any action</option>
                                @foreach ($auditActions as $actionValue => $actionLabel)
                                    <option value="{{ $actionValue }}" @selected($auditAction === $actionValue)>{{ $actionLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="meta-item">
                            <label class="meta-label" for="audit_site">Site</label>
                            <select id="audit_site" name="audit_site">
                                <option value="">Any visible site</option>
                                @foreach ($auditSites as $site)
                                    <option value="{{ $site->id }}" @selected($auditSiteId === $site->id)>{{ $site->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="meta-item">
                            <label class="meta-label" for="audit_search">Search</label>
                            <input id="audit_search" name="audit_search" type="search" value="{{ $auditSearch }}" placeholder="Actor, subject, site, action">
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Audit log</span>
                            <button class="button" type="submit">Apply filters</button>
                            <a class="button secondary" href="{{ route('dashboard.account.audit.index') }}">Clear filters</a>
                        </div>
                    </div>
                </form>
            </section>

            <section class="section" aria-labelledby="audit-events-heading">
                <div class="section-header">
                    <h2 id="audit-events-heading">Events</h2>
                    <span class="lede">{{ $auditEvents->count() }} shown</span>
                </div>

                @if ($auditEvents->isEmpty())
                    <p class="empty">No audit events match those filters.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">When</th>
                                    <th scope="col">Action</th>
                                    <th scope="col">Actor</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Site</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($auditEvents as $event)
                                    <tr>
                                        <td>{{ $event['occurred_at'] }}</td>
                                        <td>
                                            <strong>{{ $event['label'] }}</strong>
                                            <span class="lede">{{ $event['action'] }}</span>
                                        </td>
                                        <td>{{ $event['actor'] }}</td>
                                        <td>{{ $event['subject'] }}</td>
                                        <td>{{ $event['site'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
