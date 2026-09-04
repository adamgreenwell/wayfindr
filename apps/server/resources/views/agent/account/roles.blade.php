<x-layouts.app :title="__('account_roles.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('account_roles.heading')" :subtitle="__('account_roles.subtitle')">
        <x-slot:actions>
            <a class="button secondary" href="{{ route('dashboard.account.show') }}">{{ __('account_roles.back') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    @error('role')
        <p class="field-error">{{ $message }}</p>
    @enderror

    <section class="section" aria-labelledby="create-role-heading">
        <div class="section-header">
            <h2 id="create-role-heading">{{ __('account_roles.create.heading') }}</h2>
            <span class="lede">{{ __('account_roles.create.lede') }}</span>
        </div>
        <form class="section-form" method="POST" action="{{ route('dashboard.account.roles.store') }}">
            @csrf
            <div class="field">
                <label for="new-role-name">{{ __('account_roles.fields.name') }}</label>
                <input id="new-role-name" name="name" maxlength="80" value="{{ old('name') }}" lang="" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            @foreach ($permissionGroups as $group => $permissions)
                <fieldset class="section-form">
                    <legend><strong>{{ __('account_roles.groups.'.$group) }}</strong></legend>
                    @foreach ($permissions as $permission)
                        <label class="check-row" for="new-permission-{{ $permission->value }}">
                            <input id="new-permission-{{ $permission->value }}" name="permissions[]" type="checkbox" value="{{ $permission->value }}" @checked(in_array($permission->value, old('permissions', []), true))>
                            <span><strong>{{ __('account_roles.permissions.'.$permission->value.'.label') }}</strong><span class="lede">{{ __('account_roles.permissions.'.$permission->value.'.detail') }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
            @error('permissions')<p class="field-error">{{ $message }}</p>@enderror
            @error('permissions.*')<p class="field-error">{{ $message }}</p>@enderror
            <button class="button" type="submit">{{ __('account_roles.create.submit') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="existing-roles-heading">
        <div class="section-header">
            <h2 id="existing-roles-heading">{{ __('account_roles.existing.heading') }}</h2>
            <span class="lede">{{ trans_choice('account_roles.existing.count', $roles->count(), ['count' => \App\Support\ReaderNumber::count($roles->count())]) }}</span>
        </div>
        @if ($roles->isEmpty())
            <p class="empty-state">{{ __('account_roles.existing.empty') }}</p>
        @else
            @foreach ($roles as $role)
                <article class="section" id="role-{{ $role->id }}">
                    <div class="section-header">
                        <h3 lang="">{{ $role->name }}</h3>
                        <span class="lede">{{ trans_choice('account_roles.existing.assigned', $role->users_count, ['count' => \App\Support\ReaderNumber::count($role->users_count)]) }}</span>
                    </div>
                    <form class="section-form" method="POST" action="{{ route('dashboard.account.roles.update', $role) }}">
                        @csrf
                        @method('PUT')
                        <div class="field">
                            <label for="role-{{ $role->id }}-name">{{ __('account_roles.fields.name') }}</label>
                            <input id="role-{{ $role->id }}-name" name="name" maxlength="80" value="{{ $role->name }}" lang="" required>
                        </div>
                        @foreach ($permissionGroups as $group => $permissions)
                            <fieldset class="section-form">
                                <legend><strong>{{ __('account_roles.groups.'.$group) }}</strong></legend>
                                @foreach ($permissions as $permission)
                                    <label class="check-row" for="role-{{ $role->id }}-permission-{{ $permission->value }}">
                                        <input id="role-{{ $role->id }}-permission-{{ $permission->value }}" name="permissions[]" type="checkbox" value="{{ $permission->value }}" @checked($role->hasPermission($permission))>
                                        <span><strong>{{ __('account_roles.permissions.'.$permission->value.'.label') }}</strong><span class="lede">{{ __('account_roles.permissions.'.$permission->value.'.detail') }}</span></span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                        <button class="button" type="submit">{{ __('account_roles.existing.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.account.roles.destroy', $role) }}">
                        @csrf
                        @method('DELETE')
                        <button class="button danger" type="submit" @disabled($role->users_count > 0)>{{ __('account_roles.existing.delete') }}</button>
                    </form>
                </article>
            @endforeach
        @endif
    </section>
</x-layouts.app>
