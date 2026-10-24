<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Seeder;
use RuntimeException;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['Northstar Digital', 'Product Design', 'Product Designer', 'Product design and user research.'],
            ['BluePeak Solutions', 'Client Services', 'Account Manager', 'Client onboarding and account support.'],
            ['Cedarstone Consulting', 'Business Strategy', 'Business Analyst', 'Business analysis and strategic planning.'],
            ['Summit Health Group', 'Patient Services', 'Care Coordinator', 'Patient scheduling and care coordination.'],
            ['BrightPath Education', 'Curriculum', 'Curriculum Specialist', 'Curriculum design and learning materials.'],
            ['Evergreen Logistics', 'Fleet Operations', 'Fleet Coordinator', 'Fleet planning and delivery operations.'],
            ['Silverline Finance', 'Accounting', 'Accountant', 'Financial reporting and account management.'],
            ['Atlas Manufacturing', 'Quality Assurance', 'Quality Inspector', 'Product inspection and quality processes.'],
            ['Harborview Hospitality', 'Guest Experience', 'Guest Services Associate', 'Guest support and hospitality services.'],
            ['Redwood Creative Studio', 'Creative Production', 'Creative Producer', 'Creative project planning and production.'],
        ];

        $companies = Company::query()
            ->whereIn('name', array_column($departments, 0))
            ->get()
            ->keyBy('name');

        foreach ($departments as [$companyName, $name, $designation, $description]) {
            $company = $companies->get($companyName);

            if ($company === null) {
                throw new RuntimeException("Demo company [{$companyName}] was not found. Run CompanySeeder first.");
            }

            $department = Department::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'designation' => $designation,
                    'description' => $description,
                    'status' => 'active',
                ],
            );
        }
    }
}
