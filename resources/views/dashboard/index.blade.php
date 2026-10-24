@php
    $resolvedRole = $role ?? 'Employee';
@endphp

@if ($resolvedRole === 'Admin')
    @include('dashboard.admin')
@elseif ($resolvedRole === 'CEO')
    @include('dashboard.ceo')
@elseif ($resolvedRole === 'Team Leader')
    @include('dashboard.team-leader')
@else
    @include('dashboard.employee')
@endif