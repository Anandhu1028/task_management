<?php

namespace App\Console\Commands;

use App\Services\RecurringTaskGenerationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:generate-recurring-occurrences')]
#[Description('Create due daily occurrences for active recurring task templates')]
class GenerateRecurringTaskOccurrences extends Command
{
    public function handle(RecurringTaskGenerationService $generation): int
    {
        $createdCount = $generation->generateDueOccurrences();
        $this->info("Created {$createdCount} recurring task occurrence(s).");

        return self::SUCCESS;
    }
}
