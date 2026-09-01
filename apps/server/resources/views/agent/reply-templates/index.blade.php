<x-layouts.app :title="__('reply_templates.title')" :agent="$agent" :account="$account">
            <x-page-header :title="__('reply_templates.title')" :subtitle="__('reply_templates.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('reply_templates.back')" />

            @if (session('status'))
                {{-- A catalogue key rather than a sentence, so it is translated in
                     the request that shows it -- see AgentReplyTemplateController. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @error('name')
                <p class="field-error">{{ $message }}</p>
            @enderror

            @error('body')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <section class="section" aria-labelledby="reply-template-standards-heading">
                <div class="section-header">
                    <h2 id="reply-template-standards-heading">{{ __('reply_templates.standards.heading') }}</h2>
                    <span class="lede">{{ __('reply_templates.standards.lede') }}</span>
                </div>

                <div class="notice-copy">
                    <p>{{ __('reply_templates.standards.calm') }}</p>
                    <p>{{ __('reply_templates.standards.use_for') }}</p>
                    <p>{{ __('reply_templates.standards.keep_out') }}</p>
                </div>
            </section>

            <section class="section" aria-labelledby="new-reply-template-heading">
                <div class="section-header">
                    <h2 id="new-reply-template-heading">{{ __('reply_templates.create.heading') }}</h2>
                    <span class="lede">{{ __('reply_templates.create.lede') }}</span>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.reply-templates.store') }}">
                    @csrf

                    <div class="field">
                        <label for="new-template-name">{{ __('reply_templates.create.name') }}</label>
                        <input id="new-template-name" name="name" type="text" value="{{ old('name') }}" maxlength="80" placeholder="{{ __('reply_templates.create.name_placeholder') }}" required>
                    </div>

                    <div class="field">
                        <label for="new-template-body">{{ __('reply_templates.create.body') }}</label>
                        <textarea id="new-template-body" name="body" rows="4" maxlength="4000" placeholder="{{ __('reply_templates.create.body_placeholder') }}" required>{{ old('body') }}</textarea>
                    </div>

                    <button class="button" type="submit">{{ __('reply_templates.create.submit') }}</button>
                </form>
            </section>

            <section class="section" aria-labelledby="reply-templates-heading">
                <div class="section-header">
                    <h2 id="reply-templates-heading">{{ __('reply_templates.list.heading') }}</h2>
                    <span class="lede">{{ __('reply_templates.list.total', ['count' => \App\Support\ReaderNumber::count($replyTemplates->count())]) }}</span>
                </div>

                @if ($replyTemplates->isEmpty())
                    <div class="empty empty-state">
                        <strong>{{ __('reply_templates.empty.heading') }}</strong>
                        {{ __('reply_templates.empty.body') }}
                        <div class="empty-state-actions">
                            <a class="button secondary" href="#new-reply-template-heading">{{ __('reply_templates.empty.action') }}</a>
                        </div>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('reply_templates.list.column_template') }}</th>
                                    <th scope="col">{{ __('reply_templates.list.column_body') }}</th>
                                    <th scope="col">{{ __('reply_templates.list.column_status') }}</th>
                                    <th scope="col">{{ __('reply_templates.list.column_manage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($replyTemplates as $replyTemplate)
                                    <tr>
                                        <td><strong>{{ $replyTemplate->name }}</strong></td>
                                        <td>{{ \Illuminate\Support\Str::limit($replyTemplate->body, 120) }}</td>
                                        <td>{{ $replyTemplate->is_active ? __('reply_templates.list.active') : __('reply_templates.list.archived') }}</td>
                                        <td>
                                            <form class="section-form" method="POST" action="{{ route('dashboard.account.reply-templates.update', $replyTemplate) }}">
                                                @csrf
                                                @method('PUT')
                                                <div class="field">
                                                    <label for="reply-template-{{ $replyTemplate->id }}-name">{{ __('reply_templates.manage.name') }}</label>
                                                    <input id="reply-template-{{ $replyTemplate->id }}-name" name="name" value="{{ old('name', $replyTemplate->name) }}" maxlength="80" required>
                                                </div>
                                                <div class="field">
                                                    <label for="reply-template-{{ $replyTemplate->id }}-body">{{ __('reply_templates.manage.body') }}</label>
                                                    <textarea id="reply-template-{{ $replyTemplate->id }}-body" name="body" rows="3" maxlength="4000" required>{{ old('body', $replyTemplate->body) }}</textarea>
                                                </div>
                                                <button class="button secondary" type="submit">{{ __('reply_templates.manage.save') }}</button>
                                            </form>

                                            @if ($replyTemplate->is_active)
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.reply-templates.archive', $replyTemplate) }}">
                                                    @csrf
                                                    <button class="button danger" type="submit">{{ __('reply_templates.manage.archive') }}</button>
                                                </form>
                                            @else
                                                <span class="lede">{{ __('reply_templates.manage.archived_note') }}</span>
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
