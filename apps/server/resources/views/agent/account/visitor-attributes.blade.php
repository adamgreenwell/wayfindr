<x-layouts.app :title="__('visitor_attributes.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('visitor_attributes.heading')" :subtitle="__('visitor_attributes.subtitle')">
        <x-slot:actions>
            <a class="button secondary" href="{{ route('dashboard.account.show') }}">{{ __('visitor_attributes.back') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <p class="status-message" role="status">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="attribute-boundary-heading">
        <div class="section-header">
            <h2 id="attribute-boundary-heading">{{ __('visitor_attributes.boundary.heading') }}</h2>
            <span class="lede">{{ __('visitor_attributes.boundary.lede') }}</span>
        </div>
        <div class="notice-copy notice-copy-bordered">
            <p>{{ __('visitor_attributes.boundary.body') }}</p>
            <p>{{ __('visitor_attributes.boundary.delete') }}</p>
        </div>
    </section>

    <section class="section" aria-labelledby="create-attribute-heading">
        <div class="section-header">
            <h2 id="create-attribute-heading">{{ __('visitor_attributes.create.heading') }}</h2>
            <span class="lede">{{ __('visitor_attributes.create.lede', ['count' => $maximumDefinitions]) }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.account.visitor-attributes.store') }}">
            @csrf
            <div class="meta-grid">
                <div class="field">
                    <label for="attribute-key">{{ __('visitor_attributes.fields.key') }}</label>
                    <input id="attribute-key" name="key" maxlength="64" pattern="[a-z][a-z0-9_]*" value="{{ old('editing_definition') ? '' : old('key') }}" placeholder="plan" aria-describedby="attribute-key-help @error('key') attribute-key-error @enderror" @error('key') aria-invalid="true" @enderror lang="" required>
                    <p id="attribute-key-help" class="field-help">{{ __('visitor_attributes.fields.key_help') }}</p>
                    @if (! old('editing_definition'))
                        @error('key')<p id="attribute-key-error" class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div class="field">
                    <label for="attribute-label">{{ __('visitor_attributes.fields.label') }}</label>
                    <input id="attribute-label" name="label" maxlength="80" value="{{ old('editing_definition') ? '' : old('label') }}" placeholder="{{ __('visitor_attributes.fields.label_placeholder') }}" @if (! old('editing_definition')) @error('label') aria-invalid="true" aria-describedby="attribute-label-error" @enderror @endif lang="" required>
                    @if (! old('editing_definition'))
                        @error('label')<p id="attribute-label-error" class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div class="field">
                    <label for="attribute-type">{{ __('visitor_attributes.fields.type') }}</label>
                    <select id="attribute-type" name="type" @if (! old('editing_definition')) @error('type') aria-invalid="true" aria-describedby="attribute-type-error" @enderror @endif required>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(! old('editing_definition') && old('type') === $type->value)>{{ __('visitor_attributes.types.'.$type->value) }}</option>
                        @endforeach
                    </select>
                    @if (! old('editing_definition'))
                        @error('type')<p id="attribute-type-error" class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>
            </div>
            <button class="button" type="submit" @disabled($definitions->count() >= $maximumDefinitions)>{{ __('visitor_attributes.create.submit') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="defined-attributes-heading">
        <div class="section-header">
            <h2 id="defined-attributes-heading">{{ __('visitor_attributes.existing.heading') }}</h2>
            <span class="lede">{{ trans_choice('visitor_attributes.existing.count', $definitions->count(), ['count' => \App\Support\ReaderNumber::count($definitions->count())]) }}</span>
        </div>

        @if ($definitions->isEmpty())
            <p class="empty-state">{{ __('visitor_attributes.existing.empty') }}</p>
        @else
            @foreach ($definitions as $definition)
                <article class="section" id="attribute-{{ $definition->id }}">
                    <div class="section-header">
                        <h3 id="attribute-{{ $definition->id }}-heading" lang="">{{ $definition->label }}</h3>
                        <span class="lede"><span lang="">{{ $definition->key }}</span> · {{ __('visitor_attributes.types.'.$definition->type->value) }}</span>
                    </div>
                    <form class="section-form" method="POST" action="{{ route('dashboard.account.visitor-attributes.update', $definition) }}" aria-labelledby="attribute-{{ $definition->id }}-heading">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="editing_definition" value="{{ $definition->id }}">
                        <div class="meta-grid">
                            <div class="field">
                                <label for="attribute-{{ $definition->id }}-key">{{ __('visitor_attributes.fields.key') }}</label>
                                <input id="attribute-{{ $definition->id }}-key" value="{{ $definition->key }}" aria-describedby="attribute-{{ $definition->id }}-key-help" lang="" disabled>
                                <p id="attribute-{{ $definition->id }}-key-help" class="field-help">{{ __('visitor_attributes.fields.immutable_key') }}</p>
                            </div>
                            <div class="field">
                                <label for="attribute-{{ $definition->id }}-label">{{ __('visitor_attributes.fields.label') }}</label>
                                <input id="attribute-{{ $definition->id }}-label" name="label" maxlength="80" value="{{ (string) old('editing_definition') === (string) $definition->id ? old('label') : $definition->label }}" @if ((string) old('editing_definition') === (string) $definition->id) @error('label') aria-invalid="true" aria-describedby="attribute-{{ $definition->id }}-label-error" @enderror @endif lang="" required>
                                @if ((string) old('editing_definition') === (string) $definition->id)
                                    @error('label')<p id="attribute-{{ $definition->id }}-label-error" class="field-error">{{ $message }}</p>@enderror
                                @endif
                            </div>
                            <div class="field">
                                <label for="attribute-{{ $definition->id }}-type">{{ __('visitor_attributes.fields.type') }}</label>
                                <select id="attribute-{{ $definition->id }}-type" name="type" aria-describedby="attribute-{{ $definition->id }}-type-help @if ((string) old('editing_definition') === (string) $definition->id) @error('type') attribute-{{ $definition->id }}-type-error @enderror @endif" @if ((string) old('editing_definition') === (string) $definition->id) @error('type') aria-invalid="true" @enderror @endif required>
                                    @foreach ($types as $type)
                                        <option value="{{ $type->value }}" @selected(((string) old('editing_definition') === (string) $definition->id ? old('type') : $definition->type->value) === $type->value)>{{ __('visitor_attributes.types.'.$type->value) }}</option>
                                    @endforeach
                                </select>
                                <p id="attribute-{{ $definition->id }}-type-help" class="field-help">{{ __('visitor_attributes.fields.type_help') }}</p>
                                @if ((string) old('editing_definition') === (string) $definition->id)
                                    @error('type')<p id="attribute-{{ $definition->id }}-type-error" class="field-error">{{ $message }}</p>@enderror
                                @endif
                            </div>
                        </div>
                        <button class="button" type="submit">{{ __('visitor_attributes.existing.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.account.visitor-attributes.destroy', $definition) }}" aria-labelledby="attribute-{{ $definition->id }}-heading">
                        @csrf
                        @method('DELETE')
                        <button class="button danger" type="submit">{{ __('visitor_attributes.existing.delete') }}</button>
                    </form>
                </article>
            @endforeach
        @endif
    </section>
</x-layouts.app>
