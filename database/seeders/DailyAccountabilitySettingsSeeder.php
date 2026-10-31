<?php

namespace Database\Seeders;

use App\Models\DailyAccountabilitySetting;
use Illuminate\Database\Seeder;

class DailyAccountabilitySettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DailyAccountabilitySetting::current();
    }
}
