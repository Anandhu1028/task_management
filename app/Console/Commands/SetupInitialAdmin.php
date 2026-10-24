<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

#[Signature('app:setup-initial-admin')]
#[Description('Interactively create the first Admin account')]
class SetupInitialAdmin extends Command
{
    public function handle(): int
    {
        if (User::withTrashed()->where('is_demo', false)->exists()) {
            $this->error('A non-demo account already exists. Initial admin setup is only available before a real account is created.');

            return self::FAILURE;
        }

        $role = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->first();

        if ($role === null) {
            $this->error('Admin role is missing. Run the PermissionSeeder before setting up the first admin.');

            return self::FAILURE;
        }

        $availableDepartments = Department::query()
            ->where('status', 'active')
            ->whereHas('company', fn ($companyQuery) => $companyQuery->where('status', 'active'))
            ->with('company')
            ->orderBy('designation')
            ->get();

        if ($availableDepartments->isEmpty()) {
            $this->error('No active company, department, and designation are available for the initial admin.');

            return self::FAILURE;
        }

        $departmentChoices = $availableDepartments->mapWithKeys(fn (Department $department): array => [
            $department->company->name.' / '.$department->name.' / '.$department->designation => $department->id,
        ]);
        $selectedChoice = $this->choice(
            'Choose the initial admin company, department, and designation.',
            $departmentChoices->keys()->all(),
        );
        $department = $availableDepartments->first(
            fn (Department $candidate): bool => $candidate->id === $departmentChoices->get($selectedChoice)
        );

        if ($department === null) {
            $this->error('The selected department is no longer available. Run the command again.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Full name'));
        $email = strtolower(trim((string) $this->ask('Email address')));
        $employeeId = trim((string) $this->ask('Employee ID'));
        $phone = trim((string) $this->ask('Phone number'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        if ($name === '' || mb_strlen($name) > 255) {
            $this->error('Enter a name of 1 to 255 characters.');

            return self::FAILURE;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            $this->error('Enter a valid email address of at most 255 characters.');

            return self::FAILURE;
        }

        if ($employeeId === '' || mb_strlen($employeeId) > 50 || User::withTrashed()->where('employee_id', $employeeId)->exists()) {
            $this->error('Enter a unique employee ID of 1 to 50 characters.');

            return self::FAILURE;
        }

        if (! preg_match('/^\+?[0-9\s\-()]{7,20}$/', $phone)) {
            $this->error('Enter a valid phone number.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 12 || $password !== $passwordConfirmation) {
            $this->error('Passwords must match and be at least 12 characters long.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $employeeId, $phone, $password, $department, $role): ?User {
            if (User::withTrashed()->where('is_demo', false)->lockForUpdate()->exists()) {
                return null;
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'employee_id' => $employeeId,
                'phone' => $phone,
                'company_id' => $department->company_id,
                'department_id' => $department->id,
                'role_id' => $role->id,
                'designation' => $department->designation,
                'status' => 'active',
                'password' => Hash::make($password),
            ]);
            $user->assignRole($role);

            return $user;
        });

        if ($user === null) {
            $this->error('An account was created while setup was in progress. No additional admin was created.');

            return self::FAILURE;
        }

        $this->info("Initial Admin [{$user->email}] was created successfully.");

        return self::SUCCESS;
    }
}
