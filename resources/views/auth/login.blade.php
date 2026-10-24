@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 mb-2">Sign in</h1>
            <p class="text-muted mb-4">Use your TaskFlow account to continue.</p>

            @if (session('status'))
                <div class="alert alert-info" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->has('email'))
                <div class="alert alert-danger" role="alert">{{ $errors->first('email') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" required autocomplete="current-password">
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" id="remember" name="remember" type="checkbox">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </form>

            <p class="text-center text-muted mt-4 mb-0">
                Need an account? <a href="{{ route('register') }}">Create one</a>
            </p>
        </div>
    </div>
@endsection
