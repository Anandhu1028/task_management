<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = [
            ['Northstar Digital', 'Digital product design and software services.'],
            ['BluePeak Solutions', 'Business consulting and technology solutions.'],
            ['Cedarstone Consulting', 'Operational strategy and advisory services.'],
            ['Summit Health Group', 'Community-focused healthcare services.'],
            ['BrightPath Education', 'Learning programs and education technology.'],
            ['Evergreen Logistics', 'Regional transportation and supply chain services.'],
            ['Silverline Finance', 'Financial planning and accounting services.'],
            ['Atlas Manufacturing', 'Precision manufacturing and industrial components.'],
            ['Harborview Hospitality', 'Hospitality, events, and guest services.'],
            ['Redwood Creative Studio', 'Brand strategy and creative production.'],
        ];

        foreach ($companies as [$name, $description]) {
            Company::firstOrCreate(
                ['name' => $name],
                ['description' => $description, 'status' => 'active'],
            );
        }
    }
}
