<x-layouts.app :title="__('reply_templates.title')" :agent="$agent" :account="$account">
            <x-page-header :title="__('reply_templates.title')" :subtitle="__('reply_templates.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('reply_templates.back')" />

            @if (session('status'))
                {{-- A catalogue key rather than a sentence, so it is translated in
                     the request that shows it -- see AgentReplyTemplateController. --}}
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @php
                // The create form and every row's edit form all post `name` and
                // `body`, so the flashed input cannot say which of them failed.
                // Each row form names its template, and only the form that was
                // submitted gets back the typed values, the messages and
                // aria-invalid. Without it one rejected edit -- or a rejected
                // create -- was painted into every row, where a Save on any
                // other row would send it as that row's content.
                $editingTemplateId = is_scalar(old('editing_template')) ? (string) old('editing_template') : '';
            @endphp

            <section class="section" aria-labelledby="reply-template-standards-heading">
                <div class="section-header">
                    <div>
                        <h2 id="reply-template-standards-heading">{{ __('reply_templates.standards.heading') }}</h2>
                        <p class="lede">{{ __('reply_templates.standards.lede') }}</p>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>{{ __('reply_templates.standards.calm') }}</p>
                    <p>{{ __('reply_templates.standards.use_for') }}</p>
                    <p>{{ __('reply_templates.standards.keep_out') }}</p>
                </div>
            </section>

            <section class="section" aria-labelledby="new-reply-template-heading">
                <div class="section-header">
                    <div>
                        <h2 id="new-reply-template-heading">{{ __('reply_templates.create.heading') }}</h2>
                        <p class="lede">{{ __('reply_templates.create.lede') }}</p>
                    </div>
                </div>

                <form class="section-form" method="POST" action="{{ route('dashboard.account.reply-templates.store') }}">
                    @csrf

                    <div class="field">
                        <label for="new-template-name">{{ __('reply_templates.create.name') }}</label>
                        <input id="new-template-name" name="name" type="text" value="{{ $editingTemplateId === '' ? old('name') : '' }}" maxlength="80" placeholder="{{ __('reply_templates.create.name_placeholder') }}" @if ($editingTemplateId === '') @error('name') aria-invalid="true" aria-describedby="new-template-name-error" autofocus @enderror @endif required>
                        @if ($editingTemplateId === '')
                            @error('name')<p id="new-template-name-error" class="field-error">{{ $message }}</p>@enderror
                        @endif
                    </div>

                    <div class="field">
                        <label for="new-template-body">{{ __('reply_templates.create.body') }}</label>
                        <textarea id="new-template-body" name="body" rows="4" maxlength="4000" placeholder="{{ __('reply_templates.create.body_placeholder') }}" @if ($editingTemplateId === '') @error('body') aria-invalid="true" aria-describedby="new-template-body-error" @unless ($errors->has('name')) autofocus @endunless @enderror @endif required>{{ $editingTemplateId === '' ? old('body') : '' }}</textarea>
                        @if ($editingTemplateId === '')
                            @error('body')<p id="new-template-body-error" class="field-error">{{ $message }}</p>@enderror
                        @endif
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
                                    @php
                                        $isEditingTemplate = $editingTemplateId === (string) $replyTemplate->id;
                                    @endphp
                                    <tr>
                                        <td><strong lang="">{{ $replyTemplate->name }}</strong></td>
                                        <td lang="">{{ \Illuminate\Support\Str::limit($replyTemplate->body, 120) }}</td>
                                        <td>{{ $replyTemplate->is_active ? __('reply_templates.list.active') : __('reply_templates.list.archived') }}</td>
                                        <td>
                                            {{-- The Body column already shows the text, so the editor
                                                 stays folded until someone opens this row -- and opens
                                                 itself when this row's save came back with an error. --}}
                                            <x-details-disclosure
                                                :open="$isEditingTemplate && $errors->hasAny(['name', 'body'])"
                                            >
                                                {{-- Named for its row, so twenty rows are not twenty
                                                     identical "Edit template" controls to a screen reader.
                                                     The name is the account's words, so it keeps its own
                                                     language inside the translated label. --}}
                                                <x-slot:summary>{!! __('reply_templates.manage.edit', ['name' => '<span lang="">'.e($replyTemplate->name).'</span>']) !!}</x-slot:summary>
                                                <form class="section-form" method="POST" action="{{ route('dashboard.account.reply-templates.update', $replyTemplate) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="editing_template" value="{{ $replyTemplate->id }}">
                                                    <div class="field">
                                                        <label for="reply-template-{{ $replyTemplate->id }}-name">{{ __('reply_templates.manage.name') }}</label>
                                                        <input id="reply-template-{{ $replyTemplate->id }}-name" name="name" value="{{ $isEditingTemplate ? old('name') : $replyTemplate->name }}" maxlength="80" @if ($isEditingTemplate) @error('name') aria-invalid="true" aria-describedby="reply-template-{{ $replyTemplate->id }}-name-error" autofocus @enderror @endif lang="" required>
                                                        @if ($isEditingTemplate)
                                                            @error('name')<p id="reply-template-{{ $replyTemplate->id }}-name-error" class="field-error">{{ $message }}</p>@enderror
                                                        @endif
                                                    </div>
                                                    <div class="field">
                                                        <label for="reply-template-{{ $replyTemplate->id }}-body">{{ __('reply_templates.manage.body') }}</label>
                                                        <textarea id="reply-template-{{ $replyTemplate->id }}-body" name="body" rows="3" maxlength="4000" @if ($isEditingTemplate) @error('body') aria-invalid="true" aria-describedby="reply-template-{{ $replyTemplate->id }}-body-error" @unless ($errors->has('name')) autofocus @endunless @enderror @endif lang="" required>{{ $isEditingTemplate ? old('body') : $replyTemplate->body }}</textarea>
                                                        @if ($isEditingTemplate)
                                                            @error('body')<p id="reply-template-{{ $replyTemplate->id }}-body-error" class="field-error">{{ $message }}</p>@enderror
                                                        @endif
                                                    </div>
                                                    <button class="button secondary" type="submit">{{ __('reply_templates.manage.save') }}</button>
                                                </form>
                                            </x-details-disclosure>

                                            {{-- Archiving is reversible, so it carries the same weight
                                                 as restoring rather than the danger styling this group
                                                 keeps for deletion. --}}
                                            @if ($replyTemplate->is_active)
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.reply-templates.archive', $replyTemplate) }}">
                                                    @csrf
                                                    <button class="button secondary" type="submit" aria-label="{{ __('reply_templates.manage.archive_named', ['name' => $replyTemplate->name]) }}">{{ __('reply_templates.manage.archive') }}</button>
                                                </form>
                                            @else
                                                <span class="lede">{{ __('reply_templates.manage.archived_note') }}</span>
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.reply-templates.restore', $replyTemplate) }}">
                                                    @csrf
                                                    <button class="button secondary" type="submit" aria-label="{{ __('reply_templates.manage.restore_named', ['name' => $replyTemplate->name]) }}">{{ __('reply_templates.manage.restore') }}</button>
                                                </form>
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
