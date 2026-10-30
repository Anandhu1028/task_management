<?php

namespace App\Console\Commands;

use App\Services\DailyTaskWorkflowService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('daily-tasks:refresh-statuses')]
#[Description('Mark due Daily Tasks as pending or missed without completing parent tasks')]
class RefreshDailyTaskStatuses extends Command
{
    public function handle(DailyTaskWorkflowService $workflow): int
    {
        $updated = $workflow->refreshDueStatuses();
        $this->info("Updated {$updated} Daily Task status(es).");

        return self::SUCCESS;
    }
}
