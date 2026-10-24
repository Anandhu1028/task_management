@extends('layouts.app')

@section('title', $company->name)

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>{{ $company->name }}</h1>
            <p>Company overview and associated user accounts.</p>
        </div>
        <div class="tf-page-head__actions">
            <a href="{{ route('companies.index') }}" class="tf-btn tf-btn--ghost">
                <i class="bi bi-arrow-left"></i>
                Companies
            </a>
            @can('update', $company)
                <a href="{{ route('companies.edit', $company) }}" class="tf-btn tf-btn--primary">
                    <i class="bi bi-pencil"></i>
                    Edit Company
                </a>
            @endcan
        </div>
    </header>

    <nav class="tf-management__breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        <i class="bi bi-chevron-right"></i>
        <a href="{{ route('companies.index') }}">Company Management</a>
        <i class="bi bi-chevron-right"></i>
        <span>{{ $company->name }}</span>
    </nav>

    <section class="tf-management__stats">
        <article class="tf-card tf-management__stat">
            <span class="tf-management__detail-label">Status</span>
            <span><span class="tf-management__status tf-management__status--{{ $company->status }}">{{ ucfirst($company->status) }}</span></span>
        </article>
        <article class="tf-card tf-management__stat">
            <span class="tf-management__detail-label">Total Users</span>
            <strong>{{ $company->users_count }}</strong>
        </article>
        <article class="tf-card tf-management__stat">
            <span class="tf-management__detail-label">Active Users</span>
            <strong>{{ $company->active_users_count }}</strong>
        </article>
        <article class="tf-card tf-management__stat">
            <span class="tf-management__detail-label">Inactive Users</span>
            <strong>{{ $company->inactive_users_count }}</strong>
        </article>
    </section>

    <section class="tf-card mb-3">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-building tf-card__icon"></i>
                <div>
                    <h2>Company details</h2>
                    <p>Created {{ $company->created_at?->format('d M Y') }} · Updated {{ $company->updated_at?->format('d M Y') }}</p>
                </div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12">
                <div class="tf-management__detail-label">Description</div>
                <div class="tf-management__detail-value fw-normal">{{ $company->description ?: 'No description provided.' }}</div>
            </div>
        </div>
    </section>

    <section class="tf-card tf-management__table-card">
        <div class="tf-management__table-heading">
            <div>
                <h2>Users in this company</h2>
                <p>{{ $companyUsers->total() }} user{{ $companyUsers->total() === 1 ? '' : 's' }} associated with this company</p>
            </div>
        </div>
        <div class="tf-table-wrap">
            <table class="tf-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Employee ID</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($companyUsers as $user)
                        <tr>
                            <td><a class="tf-management__primary" href="{{ route('users.show', $user) }}">{{ $user->name }}</a></td>
                            <td>{{ $user->employee_id }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->department?->name ?? '—' }}</td>
                            <td><span class="tf-management__status tf-management__status--{{ $user->status }}">{{ ucfirst($user->status) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @include('components.empty-state', [
                                    'icon' => 'bi-people',
                                    'title' => 'No users yet',
                                    'message' => 'Users assigned to this company will appear here.'
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="tf-management__table-footer">
            <span>Showing {{ $companyUsers->firstItem() ?? 0 }}–{{ $companyUsers->lastItem() ?? 0 }} of {{ $companyUsers->total() }}</span>
            {{ $companyUsers->links('pagination::bootstrap-5') }}
        </footer>
    </section>
@endsection
