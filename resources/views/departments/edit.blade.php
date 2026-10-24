@extends('layouts.app')

@section('title', 'Edit Department')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Edit Department</h1>
            <p>Update {{ $department->name }} and its operating status.</p>
        </div>
        <div class="tf-page-head__actions">
            <a href="{{ route('departments.index') }}" class="tf-btn tf-btn--ghost">
                <i class="bi bi-arrow-left"></i>
                Back to departments
            </a>
        </div>
    </header>

    <nav class="tf-management__breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Dashboard</a>
        <i class="bi bi-chevron-right"></i>
        <a href="{{ route('departments.index') }}">Department Management</a>
        <i class="bi bi-chevron-right"></i>
        <span>Edit Department</span>
    </nav>

    @if (session('error'))
        <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
    @endif

    <section class="tf-card tf-management__form-card">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-diagram-3 tf-card__icon"></i>
                <div>
                    <h2>Department details</h2>
                    <p>Update the company, department name, designation, description, and status.</p>
                </div>
            </div>
        </div>
        @include('departments._form', [
            'department' => $department,
            'companies' => $companies,
            'formAction' => route('departments.update', $department),
            'submitLabel' => 'Save Changes',
        ])
    </section>
@endsection
