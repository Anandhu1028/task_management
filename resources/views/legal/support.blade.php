@extends('layouts.guest')

@section('title', 'Support & Help Center')
@section('full_width_content', 'true')

@section('content')
<div class="container py-5" style="max-width: 860px;">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
            <div>
                <a class="text-decoration-none fw-bold fs-4 text-dark d-block mb-1" href="{{ route('login') }}">TaskFlow</a>
                <h1 class="h3 fw-bold mb-0">Support & Help Center</h1>
            </div>
            <span class="badge bg-success-subtle text-success fw-medium px-3 py-2 rounded-pill">Active Support</span>
        </div>

        <div class="prose text-secondary lh-lg">
            <p class="lead text-dark mb-4">
                Need assistance with TaskFlow, your account, or the ChatGPT MCP integration? Our support team is here to help.
            </p>

            <div class="row g-4 mb-5">
                <div class="col-md-6">
                    <div class="p-4 rounded-3 border bg-light h-100">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-2 me-3">
                                <svg width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4Zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2Zm13 2.383-4.708 2.825L15 11.105V5.383Zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741ZM1 11.105l4.708-2.897L1 5.383v5.722Z"/>
                                </svg>
                            </div>
                            <h3 class="h6 fw-bold mb-0 text-dark">Email Support</h3>
                        </div>
                        <p class="small text-muted mb-2">For technical help, bug reports, and integration questions:</p>
                        <a href="mailto:support@focuzacademy.in" class="fw-semibold text-primary text-decoration-none">support@focuzacademy.in</a>
                        <div class="small text-muted mt-2">Response time: Within 24 business hours</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="p-4 rounded-3 border bg-light h-100">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-2 me-3">
                                <svg width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                                </svg>
                            </div>
                            <h3 class="h6 fw-bold mb-0 text-dark">Account & Access</h3>
                        </div>
                        <p class="small text-muted mb-2">For company registration, role assignments, or invitation approvals:</p>
                        <a href="mailto:admin@focuzacademy.in" class="fw-semibold text-primary text-decoration-none">admin@focuzacademy.in</a>
                        <div class="small text-muted mt-2">Available Monday - Friday, 9:00 AM - 6:00 PM IST</div>
                    </div>
                </div>
            </div>

            <h2 class="h5 text-dark fw-bold mb-3">Frequently Asked Questions</h2>
            
            <div class="accordion mb-4" id="supportFaq">
                <div class="accordion-item border rounded-3 mb-2">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button collapsed fw-medium" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                            How do I connect TaskFlow to ChatGPT?
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#supportFaq">
                        <div class="accordion-body text-secondary small">
                            In ChatGPT, locate the TaskFlow integration or connector, select <strong>Connect</strong>, and complete the OAuth 2.1 login and consent flow with your TaskFlow account credentials.
                        </div>
                    </div>
                </div>

                <div class="accordion-item border rounded-3 mb-2">
                    <h2 class="accordion-header" id="headingTwo">
                        <button class="accordion-button collapsed fw-medium" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                            Why can't I see tasks from other teams or companies?
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#supportFaq">
                        <div class="accordion-body text-secondary small">
                            TaskFlow enforces strict multi-tenant and role-based access control. Employees only view their assigned tasks, Team Leaders view department tasks, and Managers/CEOs view their assigned company records.
                        </div>
                    </div>
                </div>

                <div class="accordion-item border rounded-3">
                    <h2 class="accordion-header" id="headingThree">
                        <button class="accordion-button collapsed fw-medium" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                            How do I disconnect ChatGPT or revoke access?
                        </button>
                    </h2>
                    <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#supportFaq">
                        <div class="accordion-body text-secondary small">
                            You can disconnect the integration directly in your ChatGPT account settings or visit our <a href="{{ url('/data-deletion') }}">Data Deletion & Revocation</a> page for complete instructions.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-top pt-4 mt-5 d-flex justify-content-between align-items-center">
            <span class="small text-muted">&copy; {{ date('Y') }} TaskFlow. All rights reserved.</span>
            <div class="small">
                <a href="{{ url('/privacy-policy') }}" class="text-muted text-decoration-none me-3">Privacy Policy</a>
                <a href="{{ url('/terms') }}" class="text-muted text-decoration-none me-3">Terms of Service</a>
                <a href="{{ url('/data-deletion') }}" class="text-muted text-decoration-none">Data Deletion</a>
            </div>
        </div>
    </div>
</div>
@endsection

