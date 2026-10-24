<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teamMemberRole = Role::query()
            ->where('name', 'Employee')
            ->where('guard_name', 'web')
            ->first();

        if ($teamMemberRole === null) {
            throw new RuntimeException('The Employee role is missing. Run PermissionSeeder before UserSeeder.');
        }

        $demoUsers = [
            ['Northstar Digital', 'Product Design', 'Product Designer'],
            ['BluePeak Solutions', 'Client Services', 'Account Manager'],
            ['Cedarstone Consulting', 'Business Strategy', 'Business Analyst'],
            ['Summit Health Group', 'Patient Services', 'Care Coordinator'],
            ['BrightPath Education', 'Curriculum', 'Curriculum Specialist'],
            ['Evergreen Logistics', 'Fleet Operations', 'Fleet Coordinator'],
            ['Silverline Finance', 'Accounting', 'Accountant'],
            ['Atlas Manufacturing', 'Quality Assurance', 'Quality Inspector'],
            ['Harborview Hospitality', 'Guest Experience', 'Guest Services Associate'],
            ['Redwood Creative Studio', 'Creative Production', 'Creative Producer'],
        ];

        $createdCount = 0;

        DB::transaction(function () use (&$createdCount, $demoUsers, $teamMemberRole): void {
            foreach ($demoUsers as $index => [$companyName, $departmentName, $designationName]) {
                $sequence = $index + 1;
                $email = \sprintf('demo.user%02d@example.test', $sequence);
                $employeeId = \sprintf('DEMO-%03d', $sequence);
                $existingUser = User::withTrashed()->where('email', $email)->first();

                if ($existingUser !== null) {
                    if (! $existingUser->is_demo) {
                        throw new RuntimeException("Cannot seed demo account because [{$email}] belongs to a non-demo user.");
                    }

                    continue;
                }

                if (User::withTrashed()->where('employee_id', $employeeId)->exists()) {
                    throw new RuntimeException("Cannot seed demo account because employee ID [{$employeeId}] is already in use.");
                }

                $department = Department::query()
                    ->with('company')
                    ->where('name', $departmentName)
                    ->whereHas('company', fn ($query) => $query->where('name', $companyName))
                    ->first();
                if ($department === null
                    || $department->status !== 'active'
                    || $department->company?->status !== 'active'
                    || $department->designation !== $designationName) {
                    throw new RuntimeException("The active demo organization or designation [{$companyName} / {$departmentName} / {$designationName}] is missing.");
                }

                $user = User::create([
                    'name' => \sprintf('Demo User %02d', $sequence),
                    'email' => $email,
                    'employee_id' => $employeeId,
                    'phone' => \sprintf('+1 555 010 %04d', $sequence),
                    'company_id' => $department->company_id,
                    'department_id' => $department->id,
                    'role_id' => $teamMemberRole->id,
                    'designation' => $department->designation,
                    'status' => $sequence === \count($demoUsers) ? 'inactive' : 'active',
                    'is_demo' => true,
                    'password' => Str::random(64),
                ]);
                $user->assignRole($teamMemberRole);
                $createdCount++;
            }
        });

        $this->command?->info("Created {$createdCount} demo user account(s).");
    }
}
