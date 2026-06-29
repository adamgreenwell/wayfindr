<x-layouts.app title="Reply templates" :agent="$agent" :account="$account">
            <x-page-header title="Reply templates" subtitle="Manage account-wide helper replies for common visitor updates." :back-href="route('dashboard.account.show')" back-label="Back to account" />

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @error('body')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <section class="section" aria-labelledby="reply-template-standards-heading">
                <div class="section-header">
                    <h2 id="reply-template-standards-heading">Template standards</h2>
                    <span class="lede">Reusable, safe, and still human.</span>
                </div>

                <div class="notice-copy">
                    <p>Treat templates as calm starting points, not scripts agents must send unchanged.</p>
                    <p>Use templates for acknowledgements, status updates, next steps, and common clarification requests.</p>
                    <p>Keep visitor-visible templates free of passwords, payment details, private handoff notes, and promises your team cannot keep.</p>
                </div>
            </section>

            <section class="section" aria-labelledby="new-reply-template-heading">
                <div class="section-header">
                    <h2 id="new-reply-template-heading">Create template</h2>
                    <span class="lede">Short, reusable, and still editable before send.</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.reply-templates.store') }}">
                    @csrf

                    <div class="field">
                        <label for="new-template-name">Template name</label>
                        <input id="new-template-name" name="name" type="text" value="{{ old('name') }}" maxlength="80" placeholder="Billing follow-up" required>
                    </div>

                    <div class="field">
                        <label for="new-template-body">Reply body</label>
                        <textarea id="new-template-body" name="body" rows="4" maxlength="4000" placeholder="Thanks for the update. I am checking this now and will follow up shortly." required>{{ old('body') }}</textarea>
                    </div>

                    <button class="button" type="submit">Create template</button>
                </form>
            </section>

            <section class="section" aria-labelledby="reply-templates-heading">
                <div class="section-header">
                    <h2 id="reply-templates-heading">Templates</h2>
                    <span class="lede">{{ $replyTemplates->count() }} total</span>
                </div>

                @if ($replyTemplates->isEmpty())
                    <div class="empty empty-state">
                        <strong>No managed reply templates yet.</strong>
                        Built-in helpers stay available in reply composers until your team adds account templates. Add one when agents keep rewriting the same calm, useful answer.
                        <div class="empty-state-actions">
                            <a class="button secondary" href="#new-reply-template-heading">Create the first template</a>
                        </div>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Template</th>
                                    <th scope="col">Body</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Manage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($replyTemplates as $replyTemplate)
                                    <tr>
                                        <td><strong>{{ $replyTemplate->name }}</strong></td>
                                        <td>{{ \Illuminate\Support\Str::limit($replyTemplate->body, 120) }}</td>
                                        <td>{{ $replyTemplate->is_active ? 'Active' : 'Archived' }}</td>
                                        <td>
                                            <form class="section-form" method="POST" action="{{ route('dashboard.account.reply-templates.update', $replyTemplate) }}">
                                                @csrf
                                                @method('PUT')
                                                <div class="field">
                                                    <label for="reply-template-{{ $replyTemplate->id }}-name">Name</label>
                                                    <input id="reply-template-{{ $replyTemplate->id }}-name" name="name" value="{{ old('name', $replyTemplate->name) }}" maxlength="80" required>
                                                </div>
                                                <div class="field">
                                                    <label for="reply-template-{{ $replyTemplate->id }}-body">Body</label>
                                                    <textarea id="reply-template-{{ $replyTemplate->id }}-body" name="body" rows="3" maxlength="4000" required>{{ old('body', $replyTemplate->body) }}</textarea>
                                                </div>
                                                <button class="button secondary" type="submit">Save template</button>
                                            </form>

                                            @if ($replyTemplate->is_active)
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.reply-templates.archive', $replyTemplate) }}">
                                                    @csrf
                                                    <button class="button danger" type="submit">Archive</button>
                                                </form>
                                            @else
                                                <span class="lede">Archived templates stay out of reply helpers.</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
