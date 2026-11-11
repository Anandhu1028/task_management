@extends('layouts.guest')

@section('title', 'Terms of Service')
@section('full_width_content', 'true')

@section('content')
<div class="container py-5" style="max-width: 860px;">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
            <div>
                <a class="text-decoration-none fw-bold fs-4 text-dark d-block mb-1" href="{{ route('login') }}">TaskFlow</a>
                <h1 class="h3 fw-bold mb-0">Terms of Service</h1>
            </div>
            <span class="badge bg-secondary-subtle text-secondary fw-medium px-3 py-2 rounded-pill">Updated: November 2026</span>
        </div>

        <div class="prose text-secondary lh-lg">
            <h2 class="h5 text-dark fw-bold mt-4 mb-3">1. Agreement to Terms</h2>
            <p>
                By registering for an account, accessing the TaskFlow application, or connecting through authorized integrations including the Model Context Protocol (MCP) or ChatGPT app, you agree to comply with and be bound by these Terms of Service.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">2. Authorized Use & Account Responsibility</h2>
            <p>
                You are responsible for maintaining the confidentiality of your credentials and OAuth connections. You agree not to attempt to access records, tasks, companies, or reports beyond your authorized organization or role permissions.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">3. ChatGPT and MCP Integration Usage</h2>
            <p>
                When using the TaskFlow integration through ChatGPT:
            </p>
            <ul>
                <li>You acknowledge that requests executed by ChatGPT on your behalf utilize your personal OAuth permissions.</li>
                <li>Actions taken through MCP tools (such as viewing tasks, checking project reports, or submitting work updates) carry the same organizational validity as direct platform actions.</li>
                <li>You agree not to use the integration to attempt denial of service, prompt injection against system policies, or scraping of unauthorized corporate information.</li>
            </ul>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">4. Intellectual Property & Corporate Records</h2>
            <p>
                All company operational data, task descriptions, deliverables, and performance records remain the property of the respective subscribing company. TaskFlow does not assert ownership over user-submitted task contents.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">5. Availability & Service Level</h2>
            <p>
                While we strive for high availability and reliability, the platform and its MCP endpoints are provided on an "as is" and "as available" basis. Scheduled maintenance, updates, and unexpected disruptions may occur.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">6. Termination & Revocation</h2>
            <p>
                You may terminate your OAuth connection at any time by revoking authorized tokens. We reserve the right to suspend or terminate accounts that violate system security boundaries, perform unauthorized queries, or breach these Terms.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">7. Modifications to Terms</h2>
            <p>
                We may periodically revise these terms. Continued use of the platform after updates constitutes acceptance of the modified Terms.
            </p>
        </div>

        <div class="border-top pt-4 mt-5 d-flex justify-content-between align-items-center">
            <span class="small text-muted">&copy; {{ date('Y') }} TaskFlow. All rights reserved.</span>
            <div class="small">
                <a href="{{ url('/privacy-policy') }}" class="text-muted text-decoration-none me-3">Privacy Policy</a>
                <a href="{{ url('/support') }}" class="text-muted text-decoration-none me-3">Support</a>
                <a href="{{ url('/data-deletion') }}" class="text-muted text-decoration-none">Data Deletion</a>
            </div>
        </div>
    </div>
</div>
@endsection

