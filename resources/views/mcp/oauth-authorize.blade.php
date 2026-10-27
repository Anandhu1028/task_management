@extends('layouts.guest')

@section('title', 'Authorize Application')

@section('content')
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3"
                    style="width: 56px; height: 56px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor"
                        class="bi bi-shield-lock" viewBox="0 0 16 16">
                        <path
                            d="M5.338 1.59a61.44 61.44 0 0 0-2.837.856.481.481 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.725 10.725 0 0 0 2.287 2.233c.346.244.652.42.893.533.12.057.218.095.293.118a.55.55 0 0 0 .101.025.615.615 0 0 0 .1-.025c.076-.023.174-.061.294-.118.24-.113.547-.29.893-.533a10.726 10.726 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524zM5.072.56C6.157.265 7.31 0 8 0s1.843.265 2.928.56c1.11.3 2.229.655 2.887.87a1.54 1.54 0 0 1 1.044 1.262c.599 4.491-.71 7.776-2.363 9.922a12.24 12.24 0 0 1-2.66 2.6c-.414.293-.787.5-1.077.636a2.84 2.84 0 0 1-.384.152.92.92 0 0 1-.378 0 2.84 2.84 0 0 1-.384-.152c-.29-.136-.663-.343-1.077-.636a12.24 12.24 0 0 1-2.66-2.6C2.183 10.474.874 7.189 1.473 2.698A1.54 1.54 0 0 1 2.517 1.43C3.175 1.216 4.294.86 5.072.56z" />
                        <path
                            d="M9.5 6.5a1.5 1.5 0 0 1-1 1.415l.385 1.99a.5.5 0 0 1-.491.595h-.788a.5.5 0 0 1-.49-.595l.384-1.99a1.5 1.5 0 1 1 2-1.415z" />
                    </svg>
                </div>
                <h4 class="fw-bold mb-1">Authorize Access</h4>
                <p class="text-secondary small mb-0">
                    <strong>{{ $client->name }}</strong> would like to connect to your <strong>TaskFlow</strong> account.
                </p>
            </div>

            <div class="bg-body-tertiary rounded-3 p-3 mb-4">
                <div class="d-flex align-items-center mb-2">
                    <div class="fw-semibold text-dark me-2">Signed in as:</div>
                    <div class="text-truncate text-secondary">{{ $user->name }} ({{ $user->email }})</div>
                </div>
                <div class="small text-muted">
                    Role: <span
                        class="badge bg-secondary-subtle text-secondary-emphasis">{{ $user->getRoleNames()->first() ?? $user->primaryRole?->name ?? 'User' }}</span>
                    @if($user->department)
                        · Dept: {{ $user->department->name }}
                    @endif
                </div>
            </div>

            <div class="mb-4">
                <div class="fw-semibold small text-uppercase text-secondary tracking-wider mb-2">Permissions requested:
                </div>
                <ul class="list-unstyled mb-0">
                    @foreach($scopes as $scope)
                        <li class="d-flex align-items-start py-2 border-bottom border-light-subtle">
                            <svg class="text-success me-2 mt-1 flex-shrink-0" width="16" height="16" fill="currentColor"
                                viewBox="0 0 16 16">
                                <path
                                    d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.88 12.01a.733.733 0 0 1-1.065.02L3.217 8.384a.757.757 0 0 1 0-1.06.733.733 0 0 1 1.047 0l3.052 3.093 5.4-6.425a.247.247 0 0 1 .02-.022z" />
                            </svg>
                            <div>
                                @if($scope === 'tasks:read')
                                    <div class="fw-medium small text-dark">Read tasks and subtasks</div>
                                    <div class="text-muted" style="font-size: 0.8rem;">View tasks, details, timelines and progress
                                        within your authorized permissions.</div>
                                @elseif($scope === 'reports:read')
                                    <div class="fw-medium small text-dark">Access reporting and analytics</div>
                                    <div class="text-muted" style="font-size: 0.8rem;">Query task reports, overdue status, workload
                                        summaries and project performance.</div>
                                @elseif($scope === 'offline_access')
                                    <div class="fw-medium small text-dark">Maintain background access</div>
                                    <div class="text-muted" style="font-size: 0.8rem;">Allow ChatGPT to refresh tokens without
                                        requiring repeated sign-in.</div>
                                @else
                                    <div class="fw-medium small text-dark">{{ $scope }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <form action="{{ url('/oauth/authorize') }}" method="POST">
                @csrf
                <input type="hidden" name="client_id" value="{{ $client->client_id }}">
                <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
                <input type="hidden" name="state" value="{{ $state }}">
                <input type="hidden" name="code_challenge" value="{{ $codeChallenge }}">
                <input type="hidden" name="code_challenge_method" value="{{ $codeChallengeMethod }}">
                <input type="hidden" name="scope" value="{{ implode(' ', $scopes) }}">

                <div class="d-grid gap-2">
                    <button type="submit" name="action" value="allow" class="btn btn-primary fw-medium py-2 rounded-3">
                        Authorize {{ $client->name }}
                    </button>
                    <button type="submit" name="action" value="deny"
                        class="btn btn-outline-secondary fw-medium py-2 rounded-3">
                        Cancel & Deny
                    </button>
                </div>
            </form>

            <p class="text-center text-muted small mt-4 mb-0" style="font-size: 0.75rem;">
                Only authorized records matching your assigned role will be accessible. No sensitive configuration or
                unauthorized personal details are shared.
            </p>
        </div>
    </div>
@endsection