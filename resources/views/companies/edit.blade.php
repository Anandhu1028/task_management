@extends('layouts.app')

@section('title', 'Edit Company')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Edit Company</h1>
            <p>Update {{ $company->name }} and its operating status.</p>
        </div>
        <div class="tf-page-head__actions">
            <a href="{{ route('companies.show', $company) }}" class="tf-btn tf-btn--ghost">
                <i class="bi bi-arrow-left"></i>
                Company details
            </a>
        </div>
    </header>

    <nav class="tf-management__breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        <i class="bi bi-chevron-right"></i>
        <a href="{{ route('companies.index') }}">Company Management</a>
        <i class="bi bi-chevron-right"></i>
        <span>Edit Company</span>
    </nav>

    <section class="tf-card tf-management__form-card">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-building tf-card__icon"></i>
                <div>
                    <h2>Company details</h2>
                    <p>Update the company name, description, and status.</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('companies.update', $company) }}" class="tf-management__form">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="name">Company Name</label>
                    <input id="name" name="name" value="{{ old('name', $company->name) }}" class="form-control" required maxlength="255" autofocus>
                    @error('name') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea id="description" name="description" class="form-control" rows="4" maxlength="2000">{{ old('description', $company->description) }}</textarea>
                    @error('description') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select" required>
                        <option value="active" @selected(old('status', $company->status) === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $company->status) === 'inactive')>Inactive</option>
                    </select>
                    @error('status') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="tf-form-actions mt-4">
                <a href="{{ route('companies.show', $company) }}" class="tf-btn tf-btn--ghost">Cancel</a>
                <button type="submit" class="tf-btn tf-btn--primary">
                    <i class="bi bi-check-lg"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </section>
@endsection
