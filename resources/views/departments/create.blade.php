@extends('layouts.app')

@section('title', 'Add Department')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Add Department</h1>
            <p>Create a department and assign its company and designation.</p>
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
        <span>Add Department</span>
    </nav>

    <section class="tf-card tf-management__form-card">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-diagram-3 tf-card__icon"></i>
                <div>
                    <h2>Department details</h2>
                    <p>Set the company, department name, designation, description, and status.</p>
                </div>
            </div>
        </div>
        @include('departments._form', [
            'department' => null,
            'companies' => $companies,
            'formAction' => route('departments.store'),
            'submitLabel' => 'Save Department',
        ])
    </section>
@endsection
