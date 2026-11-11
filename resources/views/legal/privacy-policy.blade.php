@extends('layouts.guest')

@section('title', 'Privacy Policy')
@section('full_width_content', 'true')

@section('content')
<div class="container py-5" style="max-width: 860px;">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
            <div>
                <a class="text-decoration-none fw-bold fs-4 text-dark d-block mb-1" href="{{ route('login') }}">TaskFlow</a>
                <h1 class="h3 fw-bold mb-0">Privacy Policy</h1>
            </div>
            <span class="badge bg-primary-subtle text-primary fw-medium px-3 py-2 rounded-pill">Effective: November 2026</span>
        </div>

        <div class="prose text-secondary lh-lg">
            <h2 class="h5 text-dark fw-bold mt-4 mb-3">1. Introduction & Overview</h2>
            <p>
                TaskFlow ("we", "our", or "the Platform") operates a task management and operational reporting platform for organizations and teams. This Privacy Policy details how we collect, process, store, and safeguard your data when using TaskFlow and its Model Context Protocol (MCP) and ChatGPT integration features.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">2. What Information We Collect</h2>
            <p>
                When you create an account, log in, or interact with TaskFlow, we collect:
            </p>
            <ul>
                <li><strong>Account Identity:</strong> Your full name, work email address, department, designation, employee ID, and assigned company affiliation.</li>
                <li><strong>Authentication Credentials:</strong> Securely salted and hashed passwords (never stored in plain text), active session identifiers, and OAuth tokens.</li>
                <li><strong>Task & Operational Data:</strong> Tasks, projects, subtasks, work status histories, deadline requests, progress percentages, and daily work updates.</li>
                <li><strong>System & Audit Metadata:</strong> Timestamps of actions, authenticated user ID, company ID, OAuth client identifier, and IP address for security logging.</li>
            </ul>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">3. How the ChatGPT MCP Integration Operates</h2>
            <p>
                When you connect your TaskFlow account to ChatGPT via OAuth 2.1:
            </p>
            <ul>
                <li><strong>Explicit Authorization:</strong> Access is granted only after you explicitly sign in to your TaskFlow account and authorize the requested OAuth scopes (e.g. <code>tasks:read</code>, <code>reports:read</code>).</li>
                <li><strong>Scoped Tenant Isolation:</strong> ChatGPT can only access records that your individual user account and role are authorized to view within your company. External users cannot view internal company records.</li>
                <li><strong>Information Sent by ChatGPT:</strong> ChatGPT sends natural-language-derived queries, tool arguments (e.g., status filters, project names, or task identifiers), and your temporary Bearer token to our secure endpoint at <code>https://tracker-manage.focuzacademy.in/mcp</code>.</li>
                <li><strong>Information Returned to ChatGPT:</strong> Only the specific structured task data, reports, or subtask updates requested by you and permitted by your permissions are returned. We do not transmit other users' private passwords, unrelated company tasks, or internal system configurations.</li>
            </ul>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">4. OAuth Access & Token Handling</h2>
            <p>
                OAuth access tokens use cryptographically signed JSON Web Tokens (JWT) with strict time limits (8 hours). Refresh tokens are stored as SHA-256 hashes, strictly rotated upon each use, and protected by automated replay detection. You can revoke access at any time via the OAuth revocation endpoint or by contacting support.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">5. Third-Party Processing & AI Services</h2>
            <p>
                When you interact with TaskFlow through ChatGPT, OpenAI processes your conversational prompts subject to OpenAI's privacy policy and data usage terms. TaskFlow does not sell your personal or task data to any third party, nor do we use your organizational task data to train public foundation models without explicit consent.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">6. Data Retention & Storage</h2>
            <p>
                Task data, status updates, and reports are retained for the duration of your company's active subscription. Audit logs are maintained for security, access auditing, and regulatory compliance, and are purged periodically according to our retention policies.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">7. Security Measures</h2>
            <p>
                We employ industry-standard security safeguards including TLS 1.3 encryption in transit, strict PKCE S256 verification, hashed credentials, rate limiting, and role-based access control (RBAC) enforced at the database and application levels.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">8. Account Deletion & OAuth Revocation</h2>
            <p>
                You have the right to request deletion of your personal data or revoke third-party app connections at any time. For step-by-step instructions, please review our <a href="{{ url('/data-deletion') }}" class="text-primary fw-medium">Data Deletion Policy</a>.
            </p>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">9. Contact Us</h2>
            <p>
                For privacy inquiries, security questions, or data protection requests, please visit our <a href="{{ url('/support') }}" class="text-primary fw-medium">Support Page</a> or email us at <code>privacy@focuzacademy.in</code>.
            </p>
        </div>

        <div class="border-top pt-4 mt-5 d-flex justify-content-between align-items-center">
            <span class="small text-muted">&copy; {{ date('Y') }} TaskFlow. All rights reserved.</span>
            <div class="small">
                <a href="{{ url('/terms') }}" class="text-muted text-decoration-none me-3">Terms of Service</a>
                <a href="{{ url('/support') }}" class="text-muted text-decoration-none me-3">Support</a>
                <a href="{{ url('/data-deletion') }}" class="text-muted text-decoration-none">Data Deletion</a>
            </div>
        </div>
    </div>
</div>
@endsection

