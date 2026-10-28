@extends('layouts.guest')

@section('title', 'Sign in')
@section('full_width_content', 'true')

@section('content')
    <main class="tf-login-page">
        <section class="tf-login-showcase" aria-label="TaskFlow overview">
            <a class="tf-login-brand" href="{{ route('login') }}" aria-label="TaskFlow home">
                <span class="tf-login-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                        <path d="m5 8 7 4 7-4"></path>
                        <path d="m5 12 7 4 7-4"></path>
                        <path d="m5 16 7 4 7-4"></path>
                    </svg>
                </span>
                <span>TaskFlow</span>
            </a>

            <div class="tf-login-showcase__content">
                <h1>Manage every task, team<br>and deadline in one place.</h1>
                <p>Track progress across companies and departments, and keep your work on schedule.</p>

                <ul class="tf-login-benefits">
                    <li>
                        <span class="tf-login-benefits__icon">✓</span>
                        <span><strong>Clear task ownership</strong><small>Assign tasks to the right person and see who is responsible at every step.</small></span>
                    </li>
                    <li>
                        <span class="tf-login-benefits__icon">⌁</span>
                        <span><strong>Live progress tracking</strong><small>Follow deadlines, risks and completion across every team in real time.</small></span>
                    </li>
                    <li>
                        <span class="tf-login-benefits__icon">×</span>
                        <span><strong>Built for teams</strong><small>Organize companies, departments and users with role-based access.</small></span>
                    </li>
                </ul>
            </div>


        </section>

        <section class="tf-login-panel" aria-labelledby="signInTitle">
            <div class="tf-login-panel__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8">
                    <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                </svg>
            </div>
            <h2 id="signInTitle">Sign in</h2>
            <p>Use your TaskFlow account to continue.</p>

            @if (session('status'))
                <div class="alert alert-info tf-login-alert" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->has('email'))
                <div class="alert alert-danger tf-login-alert" role="alert">{{ $errors->first('email') }}</div>
            @endif

            <form class="tf-login-form" method="POST" action="{{ route('login') }}">
                @csrf
                <div>
                    <label for="email">Email</label>
                    <div class="tf-login-input">
                        <span aria-hidden="true">✉</span>
                        <input class="@error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" placeholder="name@company.com" required autofocus autocomplete="username">
                    </div>
                </div>
                <div>
                    <div class="tf-login-label-row">
                        <label for="password">Password</label>
                    </div>
                    <div class="tf-login-input">
                        <span aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8">
                                <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                                <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                            </svg>
                        </span>
                        <input class="@error('password') is-invalid @enderror" id="password" name="password" type="password" required autocomplete="current-password">
                        <button class="tf-login-password-toggle" type="button" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false">
                            <svg data-password-toggle-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" aria-hidden="true">
                                <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                                <path d="M8 10V7a4 4 0 0 1 7.2-2.4"></path>
                                <path d="M12 14v2"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="tf-login-remember">
                    <input id="remember" name="remember" type="checkbox">
                    <label for="remember">Remember me</label>
                </div>
                <button type="submit">Sign in <span aria-hidden="true">→</span></button>
            </form>

            <span class="tf-login-secure">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 3 5 6v5c0 4.4 3 8.5 7 10 4-1.5 7-5.6 7-10V6l-7-3Z"></path>
                    <path d="m9.5 12 1.6 1.6 3.4-3.4"></path>
                </svg>
                Secure, encrypted sign in
            </span>
        </section>
    </main>

    <script>
        document.querySelector('[data-password-toggle]')?.addEventListener('click', function () {
            const passwordInput = document.getElementById(this.getAttribute('aria-controls'));

            if (!passwordInput) {
                return;
            }

            const isPasswordVisible = passwordInput.type === 'text';
            const passwordToggleIcon = this.querySelector('[data-password-toggle-icon]');

            passwordInput.type = isPasswordVisible ? 'password' : 'text';
            this.setAttribute('aria-pressed', String(!isPasswordVisible));
            this.setAttribute('aria-label', isPasswordVisible ? 'Show password' : 'Hide password');

            if (passwordToggleIcon) {
                passwordToggleIcon.innerHTML = isPasswordVisible
                    ? '<rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 7.2-2.4"></path><path d="M12 14v2"></path>'
                    : '<rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path><path d="M12 14v2"></path>';
            }
        });
    </script>
@endsection
