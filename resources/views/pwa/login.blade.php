@extends('layouts.pwa')

@section('title', 'Sign in · '.config('pwa.name'))

@section('content')
<main class="grid min-h-screen place-items-center p-6">
    <form method="POST" action="{{ route('login') }}" class="w-full max-w-sm space-y-5 rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        @csrf
        <div><h1 class="text-xl font-bold">{{ config('pwa.name') }}</h1><p class="mt-1 text-sm text-slate-600">Sign in to view your tasks.</p></div>
        <div><label class="block text-sm font-medium" for="email">Email</label><input class="mt-1 w-full rounded-lg border-slate-300" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">@error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <div><label class="block text-sm font-medium" for="password">Password</label><div class="relative"><input class="mt-1 w-full rounded-lg border-slate-300 pr-12" id="password" name="password" type="password" required autocomplete="current-password"><button class="absolute inset-y-0 right-2 text-sm text-slate-600" type="button" aria-label="Show password" data-password-toggle>Show</button></div>@error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <button class="w-full rounded-lg bg-slate-800 px-4 py-2.5 font-semibold text-white focus:outline-none focus:ring-2 focus:ring-slate-500" type="submit">Sign in</button>
    </form>
</main>
<script>document.querySelector('[data-password-toggle]')?.addEventListener('click', (event) => { const input = document.querySelector('#password'); input.type = input.type === 'password' ? 'text' : 'password'; event.currentTarget.textContent = input.type === 'password' ? 'Show' : 'Hide'; });</script>
@endsection
