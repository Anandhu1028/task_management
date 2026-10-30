@extends('layouts.pwa')

@section('title', 'Sign in · TaskFlow')
@section('body_class', 'pwa--auth')

@section('content')
<main class="pwa-auth">
    <form method="POST" action="{{ route('pwa.login.store') }}" class="pwa-card pwa-auth__card" novalidate>
        @csrf
        <div class="pwa-auth__brand">
            <span class="pwa-brand__mark pwa-brand__mark--lg">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            </span>
            <h1>TaskFlow</h1>
            <p>Sign in to view your tasks.</p>
        </div>

        <label class="pwa-field" for="email">
            <span>Email</span>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email">
            @error('email')<small class="pwa-error">{{ $message }}</small>@enderror
        </label>

        <label class="pwa-field" for="password">
            <span>Password</span>
            <span class="pwa-field__group">
                <input id="password" name="password" type="password" required autocomplete="current-password">
                <button type="button" class="pwa-field__toggle" data-password-toggle aria-label="Show password">Show</button>
            </span>
            @error('password')<small class="pwa-error">{{ $message }}</small>@enderror
        </label>

        <label class="pwa-check"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in</span></label>

        <button type="submit" class="pwa-btn pwa-btn--primary pwa-btn--block">Sign in</button>
    </form>
</main>
@endsection
