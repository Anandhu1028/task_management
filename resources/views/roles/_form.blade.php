@php
    $roleFormHasOldInput = old('role_form') === $formType
        && ($formType === 'create' || (string) old('role_id') === (string) $role->id);
    $selectedPermissions = $roleFormHasOldInput
        ? (array) old('permissions', [])
        : ($role?->permissions->pluck('name')->all() ?? []);
@endphp

<div class="modal fade tf-role-modal" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ $formAction }}">
                @csrf
                @if ($role)
                    @method('PATCH')
                    <input type="hidden" name="role_id" value="{{ $role->id }}">
                @endif
                <input type="hidden" name="role_form" value="{{ $formType }}">

                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="{{ $modalId }}Label">{{ $role ? 'Edit role' : 'Create role' }}</h2>
                        <p class="tf-role-modal__subtitle">Choose a clear name and the access this role needs.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    @if ($errors->any() && $roleFormHasOldInput)
                        <div class="tf-role-modal__error" role="alert">{{ $errors->first() }}</div>
                    @endif

                    <label class="tf-role-field" for="{{ $modalId }}Name">
                        <span>Role name</span>
                        <input
                            id="{{ $modalId }}Name"
                            type="text"
                            name="name"
                            value="{{ $roleFormHasOldInput ? old('name') : ($role?->name ?? '') }}"
                            maxlength="255"
                            required
                            autocomplete="off"
                            placeholder="For example, Project Coordinator"
                        >
                    </label>

                    <div class="tf-role-permissions-heading">
                        <div>
                            <h3>Permissions</h3>
                            <p>Select the actions this role can perform.</p>
                        </div>
                        <span>Role administration access is reserved for Admin.</span>
                    </div>

                    <div class="tf-role-permission-groups">
                        @foreach ($permissionGroups as $groupName => $permissions)
                            <fieldset class="tf-role-permission-group">
                                <legend>{{ $groupName }}</legend>
                                <div class="tf-role-permission-group__items">
                                    @foreach ($permissions as $permission)
                                        <label class="tf-role-permission-option">
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value="{{ $permission['name'] }}"
                                                @checked(in_array($permission['name'], $selectedPermissions, true))
                                            >
                                            <span>{{ $permission['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="tf-btn tf-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="tf-btn tf-btn--primary">
                        <i class="bi {{ $role ? 'bi-check2' : 'bi-shield-plus' }}" aria-hidden="true"></i>
                        {{ $role ? 'Save changes' : 'Create role' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
