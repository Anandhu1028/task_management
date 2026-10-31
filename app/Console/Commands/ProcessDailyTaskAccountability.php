<?php

namespace App\Console\Commands;

use App\Services\DailyTaskAccountabilityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('daily-accountability:process')]
#[Description('Record missing Daily Task update warnings')]
class ProcessDailyTaskAccountability extends Command
{
    public function handle(DailyTaskAccountabilityService $accountability): int
    {
        $processed = $accountability->processDueReports();
        $this->info("Processed {$processed} missing Daily Task update warnings.");

        return self::SUCCESS;
    }
}
