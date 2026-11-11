@extends('layouts.guest')

@section('title', 'Data Deletion & OAuth Revocation')
@section('full_width_content', 'true')

@section('content')
<div class="container py-5" style="max-width: 860px;">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
            <div>
                <a class="text-decoration-none fw-bold fs-4 text-dark d-block mb-1" href="{{ route('login') }}">TaskFlow</a>
                <h1 class="h3 fw-bold mb-0">Data Deletion & Revocation</h1>
            </div>
            <span class="badge bg-warning-subtle text-warning-emphasis fw-medium px-3 py-2 rounded-pill">User Rights</span>
        </div>

        <div class="prose text-secondary lh-lg">
            <h2 class="h5 text-dark fw-bold mt-4 mb-3">1. How to Revoke ChatGPT Access</h2>
            <p>
                You have the right to revoke ChatGPT’s access to your TaskFlow account at any time. When revoked, all active access tokens and refresh tokens associated with that session are immediately invalidated:
            </p>
            <ol>
                <li><strong>In ChatGPT:</strong> Navigate to your ChatGPT workspace settings &rarr; <em>Connected apps / Connected accounts</em> &rarr; Locate <strong>TaskFlow</strong> &rarr; Click <strong>Disconnect</strong>.</li>
                <li><strong>Via Automated Endpoint:</strong> Client applications can programmatically revoke tokens via RFC 7009 by sending a POST request to <code>{{ url('/oauth/revoke') }}</code> with parameter <code>token=&lt;token&gt;</code>.</li>
                <li><strong>Manual Administrative Revocation:</strong> Email us at <a href="mailto:support@focuzacademy.in">support@focuzacademy.in</a> with subject <em>"Revoke ChatGPT MCP Access"</em> and your account email. Our administrators will revoke all issued tokens for your account within 24 hours.</li>
            </ol>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">2. How to Request Account & Data Deletion</h2>
            <p>
                To request permanent deletion of your individual user account and personal identifiers from TaskFlow:
            </p>
            <ul>
                <li>Send an email to <a href="mailto:privacy@focuzacademy.in">privacy@focuzacademy.in</a> from the email address registered with your TaskFlow account.</li>
                <li>State in the subject line: <strong>"Data Deletion Request - [Your Full Name]"</strong>.</li>
                <li>Include your organization name and whether you wish to delete your individual user profile or request organizational data purging (requires company Administrator approval).</li>
            </ul>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">3. Data Deletion Timeline & Retention Schedule</h2>
            <p>
                Upon receiving an authenticated deletion request:
            </p>
            <ul>
                <li><strong>Immediate Action (Within 24 Hours):</strong> All active sessions, OAuth access tokens, and refresh tokens are immediately revoked. The account status is set to inactive.</li>
                <li><strong>Personal Data Purge (Within 14 Days):</strong> Personal profile records, contact numbers, and login credentials are permanently deleted from our active database.</li>
                <li><strong>Company Operational Records:</strong> Task completion entries, audit trails, and status histories created on behalf of your employer remain preserved in anonymized form as required for your employer's legitimate business records, unless your employer's authorized representative instructs full company deletion.</li>
                <li><strong>Database Backups:</strong> Residual backup copies are overwritten according to our standard 30-day backup cycle.</li>
            </ul>

            <h2 class="h5 text-dark fw-bold mt-4 mb-3">4. Confirmation of Deletion</h2>
            <p>
                You will receive written email confirmation once your deletion request has been fulfilled. If you have any questions or require assistance during this process, please reach out to our privacy team at <a href="mailto:privacy@focuzacademy.in">privacy@focuzacademy.in</a>.
            </p>
        </div>

        <div class="border-top pt-4 mt-5 d-flex justify-content-between align-items-center">
            <span class="small text-muted">&copy; {{ date('Y') }} TaskFlow. All rights reserved.</span>
            <div class="small">
                <a href="{{ url('/privacy-policy') }}" class="text-muted text-decoration-none me-3">Privacy Policy</a>
                <a href="{{ url('/terms') }}" class="text-muted text-decoration-none me-3">Terms of Service</a>
                <a href="{{ url('/support') }}" class="text-muted text-decoration-none">Support</a>
            </div>
        </div>
    </div>
</div>
@endsection

