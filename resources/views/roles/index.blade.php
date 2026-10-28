@extends('layouts.app')

@section('title', 'Role Management')

@section('content')
    <div class="tf-role-page">
        <header class="tf-page-head">
            <div>
                <h1>Role Management</h1>
                <p>Manage role access and the permissions available across TaskFlow.</p>
            </div>
            @can('roles.create')
                <div class="tf-page-head__actions">
                    <button type="button" class="tf-btn tf-btn--primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                        <i class="bi bi-shield-plus" aria-hidden="true"></i>
                        Create Role
                    </button>
                </div>
            @endcan
        </header>

        @if (session('error'))
            <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
        @endif

        <section class="tf-card tf-role-management" aria-label="Roles">
            <div class="tf-role-management__heading">
                <div>
                    <h2>All roles</h2>
                    <p>{{ $roles->count() }} {{ \Illuminate\Support\Str::plural('role', $roles->count()) }} configured</p>
                </div>
                <span class="tf-role-management__heading-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
            </div>

            <div class="tf-role-table-wrap">
                <table class="tf-role-table">
                    <thead>
                        <tr>
                            <th scope="col">Role</th>
                            <th scope="col">Permissions</th>
                            <th scope="col">Assigned users</th>
                            <th scope="col">Designations</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roles as $role)
                            <tr>
                                <td>
                                    <div class="tf-role-table__name">
                                        <span class="tf-role-table__icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                                        <span>
                                            <strong>{{ $role->name }}</strong>
                                            @if ($role->is_protected)
                                                <span class="tf-role-badge tf-role-badge--protected">Protected</span>
                                            @else
                                                <span class="tf-role-table__guard">Web access</span>
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="tf-role-count">{{ $role->permissions_count }}</span>
                                    <span class="tf-role-table__muted">permissions</span>
                                </td>
                                <td>{{ number_format($role->assigned_users_count) }}</td>
                                <td>{{ number_format($role->designations_count) }}</td>
                                <td>
                                    <div class="tf-role-table__actions">
                                        @if (! $role->is_protected)
                                            @can('roles.update')
                                                <button
                                                    type="button"
                                                    class="tf-role-icon-button"
                                                    aria-label="Edit {{ $role->name }}"
                                                    title="Edit role"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editRoleModal-{{ $role->id }}"
                                                >
                                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                </button>
                                            @endcan
                                            @can('roles.delete')
                                                @if ($role->assigned_users_count === 0 && $role->designations_count === 0)
                                                    <form method="POST" action="{{ route('roles.destroy', $role) }}" onsubmit="return confirm('Delete this role? This action cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="tf-role-icon-button tf-role-icon-button--danger" aria-label="Delete {{ $role->name }}" title="Delete role">
                                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                @else
                                                    <button
                                                        type="button"
                                                        class="tf-role-icon-button tf-role-icon-button--disabled"
                                                        aria-label="{{ $role->name }} is assigned and cannot be deleted"
                                                        title="Remove its user and designation assignments before deleting"
                                                        disabled
                                                    >
                                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                                    </button>
                                                @endif
                                            @endcan
                                        @else
                                            @can('roles.update')
                                                <button
                                                    type="button"
                                                    class="tf-role-icon-button tf-role-icon-button--disabled"
                                                    aria-label="{{ $role->name }} is a protected system role and cannot be edited"
                                                    title="Protected system role"
                                                    disabled
                                                >
                                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                </button>
                                            @endcan
                                            @can('roles.delete')
                                                <button
                                                    type="button"
                                                    class="tf-role-icon-button tf-role-icon-button--disabled"
                                                    aria-label="{{ $role->name }} is a protected system role and cannot be deleted"
                                                    title="Protected system role"
                                                    disabled
                                                >
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                                </button>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="tf-role-empty">
                                        <i class="bi bi-shield" aria-hidden="true"></i>
                                        <strong>No roles found</strong>
                                        <span>Create a role to define access for your team.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @can('roles.create')
            @include('roles._form', [
                'role' => null,
                'formType' => 'create',
                'modalId' => 'createRoleModal',
                'formAction' => route('roles.store'),
            ])
        @endcan

        @foreach ($roles->where('is_protected', false) as $role)
            @can('roles.update')
                @include('roles._form', [
                    'role' => $role,
                    'formType' => 'edit',
                    'modalId' => 'editRoleModal-'.$role->id,
                    'formAction' => route('roles.update', $role),
                ])
            @endcan
        @endforeach
    </div>

    @if ($errors->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const formType = @json(old('role_form'));
                    const roleId = @json(old('role_id'));
                    const modalId = formType === 'edit' ? `editRoleModal-${roleId}` : 'createRoleModal';
                    const modal = document.getElementById(modalId);

                    if (modal) {
                        bootstrap.Modal.getOrCreateInstance(modal).show();
                    }
                });
            </script>
        @endpush
    @endif
@endsection
