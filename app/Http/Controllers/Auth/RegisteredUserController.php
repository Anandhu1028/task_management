<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $employeeRole = Role::query()
            ->where('name', 'Employee')
            ->where('guard_name', 'web')
            ->firstOrFail();

        DB::transaction(function () use ($validated, $employeeRole): void {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role_id' => $employeeRole->id,
                'status' => 'inactive',
                'requires_approval' => true,
            ]);
            $user->assignRole($employeeRole);
        });

        return redirect()
            ->route('login')
            ->with('status', 'Your account has been submitted for administrator approval. You can sign in after access is granted.');
    }
}
