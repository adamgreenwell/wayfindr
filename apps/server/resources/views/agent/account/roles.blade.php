<x-layouts.account :title="__('account_roles.document_title')">
    <x-page-header :title="__('account_roles.heading')" :subtitle="__('account_roles.subtitle')">
        {{-- What a role does and does not decide. This was the account
             overview's "Role boundary" card, where most of the people reading
             it could not act on it; the subtitle above already says that site
             assignments decide visibility, so only the rules it does not cover
             moved here. --}}
        <p class="lede">{{ __('account_roles.boundary.changes') }}</p>
        <p class="lede">{{ __('account_roles.boundary.suspension') }}</p>
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
            {{-- Old input and the `name`/`permissions` errors belong here only
                 when the create form sent them. An edit that fails flashes the
                 same keys, and they belong to that role's own form below. --}}
            <div class="field">
                <label for="new-role-name">{{ __('account_roles.fields.name') }}</label>
                <input id="new-role-name" name="name" maxlength="80" value="{{ $failedRoleId === null ? old('name') : '' }}" lang="" required>
                @if ($failedRoleId === null)
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                @endif
            </div>
            @foreach ($permissionGroups as $group => $permissions)
                <fieldset class="section-form">
                    <legend><strong>{{ __('account_roles.groups.'.$group) }}</strong></legend>
                    @foreach ($permissions as $permission)
                        <label class="check-row" for="new-permission-{{ $permission->value }}">
                            <input id="new-permission-{{ $permission->value }}" name="permissions[]" type="checkbox" value="{{ $permission->value }}" @checked($failedRoleId === null && in_array($permission->value, old('permissions', []), true))>
                            <span><strong>{{ __('account_roles.permissions.'.$permission->value.'.label') }}</strong><span class="lede">{{ __('account_roles.permissions.'.$permission->value.'.detail') }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
            @if ($failedRoleId === null)
                @error('permissions')<p class="field-error">{{ $message }}</p>@enderror
                @error('permissions.*')<p class="field-error">{{ $message }}</p>@enderror
            @endif
            <button class="button" type="submit">{{ __('account_roles.create.submit') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="existing-roles-heading">
        <div class="section-header">
            <h2 id="existing-roles-heading">{{ __('account_roles.existing.heading') }}</h2>
            <span class="lede">{{ trans_choice('account_roles.existing.count', $roles->count(), ['count' => \App\Support\ReaderNumber::count($roles->count())]) }}</span>
        </div>
        @if ($roles->isEmpty())
            <div class="empty empty-state">
                <strong>{{ __('account_roles.existing.empty') }}</strong>
                <div class="empty-state-actions">
                    <a class="button secondary" href="#create-role-heading">{{ __('account_roles.existing.empty_action') }}</a>
                </div>
            </div>
        @else
            @foreach ($roles as $role)
                @php($roleEditFailed = $failedRoleId === $role->id)
                {{-- One item in this card, not a card of its own: .section here
                     drew a bordered card 28px down inside the card listing it. --}}
                <article class="section-item" id="role-{{ $role->id }}">
                    <div class="section-header">
                        <h3 lang="">{{ $role->name }}</h3>
                        <span class="lede">{{ trans_choice('account_roles.existing.assigned', $role->users_count, ['count' => \App\Support\ReaderNumber::count($role->users_count)]) }}</span>
                    </div>
                    <div class="section-item-body">
                        {{-- Nineteen permissions, each with its explanation, once per
                             role: collapsed, the roster reads one line per role. It
                             opens for the role a save just came back to, and for one
                             whose save failed -- a closed disclosure would hide the
                             error and the change would look silently lost. --}}
                        <x-details-disclosure :summary="__('account_roles.existing.edit')" :open="$openRoleId === $role->id">
                            <form class="section-form" method="POST" action="{{ route('dashboard.account.roles.update', $role) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="role_id" value="{{ $role->id }}">
                                <div class="field">
                                    <label for="role-{{ $role->id }}-name">{{ __('account_roles.fields.name') }}</label>
                                    {{-- autofocus scrolls a failed edit into view: the page
                                         reloads at the top, and this form is further down. --}}
                                    <input id="role-{{ $role->id }}-name" name="name" maxlength="80" value="{{ $roleEditFailed ? old('name') : $role->name }}" lang="" required @if ($roleEditFailed) autofocus @endif>
                                    @if ($roleEditFailed)
                                        @error('name')<p class="field-error">{{ $message }}</p>@enderror
                                    @endif
                                </div>
                                @foreach ($permissionGroups as $group => $permissions)
                                    <fieldset class="section-form">
                                        <legend><strong>{{ __('account_roles.groups.'.$group) }}</strong></legend>
                                        @foreach ($permissions as $permission)
                                            <label class="check-row" for="role-{{ $role->id }}-permission-{{ $permission->value }}">
                                                <input id="role-{{ $role->id }}-permission-{{ $permission->value }}" name="permissions[]" type="checkbox" value="{{ $permission->value }}" @checked($roleEditFailed ? in_array($permission->value, old('permissions', []), true) : $role->hasPermission($permission))>
                                                <span><strong>{{ __('account_roles.permissions.'.$permission->value.'.label') }}</strong><span class="lede">{{ __('account_roles.permissions.'.$permission->value.'.detail') }}</span></span>
                                            </label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                                @if ($roleEditFailed)
                                    @error('permissions')<p class="field-error">{{ $message }}</p>@enderror
                                    @error('permissions.*')<p class="field-error">{{ $message }}</p>@enderror
                                @endif
                                <button class="button" type="submit">{{ __('account_roles.existing.save') }}</button>
                            </form>
                        </x-details-disclosure>
                        <form method="POST" action="{{ route('dashboard.account.roles.destroy', $role) }}">
                            @csrf
                            @method('DELETE')
                            {{-- The server refuses a delete for two reasons, not one: people still
                                 holding the role, and single sign-on claim mappings still naming it
                                 (AgentAccountCustomRoleController::destroy). The button used to test
                                 only the first, so a role with no people but a live SSO mapping
                                 offered an enabled danger button that failed validation. Both
                                 conditions are shown rather than only disabling, because a dead
                                 control with no stated reason is the same defect wearing a
                                 different hat. --}}
                            @php($blockedByPeople = $role->users_count > 0)
                            @php($blockedByMapping = $role->oidc_role_mappings_count > 0)
                            <button class="button danger" type="submit" @disabled($blockedByPeople || $blockedByMapping)>{{ __('account_roles.existing.delete') }}</button>
                            @if ($blockedByPeople)
                                <span class="lede">{{ __('account_roles.errors.assigned') }}</span>
                            @elseif ($blockedByMapping)
                                <span class="lede">{{ __('account_roles.errors.oidc_mapped') }}</span>
                            @endif
                        </form>
                    </div>
                </article>
            @endforeach
        @endif
    </section>
</x-layouts.account>
