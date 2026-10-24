@extends('layouts.app')

@section('title', 'Add Company')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Add Company</h1>
            <p>Create a company and set its current operating status.</p>
        </div>
        <div class="tf-page-head__actions">
            <a href="{{ route('companies.index') }}" class="tf-btn tf-btn--ghost">
                <i class="bi bi-arrow-left"></i>
                Back to companies
            </a>
        </div>
    </header>

    <nav class="tf-management__breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        <i class="bi bi-chevron-right"></i>
        <a href="{{ route('companies.index') }}">Company Management</a>
        <i class="bi bi-chevron-right"></i>
        <span>Add Company</span>
    </nav>

    <section class="tf-card tf-management__form-card">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-building tf-card__icon"></i>
                <div>
                    <h2>Company details</h2>
                    <p>Enter the basic details for this company.</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('companies.store') }}" class="tf-management__form">
            @csrf
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="name">Company Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control" required maxlength="255" autofocus>
                    @error('name') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea id="description" name="description" class="form-control" rows="4" maxlength="2000">{{ old('description') }}</textarea>
                    @error('description') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select" required>
                        <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                    </select>
                    @error('status') <div class="tf-management__field-error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="tf-form-actions mt-4">
                <a href="{{ route('companies.index') }}" class="tf-btn tf-btn--ghost">Cancel</a>
                <button type="submit" class="tf-btn tf-btn--primary">
                    <i class="bi bi-check-lg"></i>
                    Save Company
                </button>
            </div>
        </form>
    </section>
@endsection
