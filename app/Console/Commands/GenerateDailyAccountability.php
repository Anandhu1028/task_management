<?php

namespace App\Console\Commands;

use App\Services\EmployeeDailyAccountabilityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

#[Signature('dwr:generate-missing-activity {--date= : Activity date to evaluate in Y-m-d format}')]
#[Description('Store daily work activity status and warnings for active employees')]
class GenerateDailyAccountability extends Command
{
    public function handle(EmployeeDailyAccountabilityService $accountability): int
    {
        $dateOption = $this->option('date');
        $dateValidator = Validator::make(
            ['date' => $dateOption],
            ['date' => ['nullable', 'date_format:Y-m-d']],
        );

        if ($dateValidator->fails()) {
            $this->error('The date must use Y-m-d format.');

            return self::FAILURE;
        }

        $date = $dateOption
            ? Carbon::createFromFormat('!Y-m-d', $dateOption, config('app.timezone'))
            : today();

        if ($date === false || $date->isFuture()) {
            $this->error('Daily activity cannot be evaluated for a future date.');

            return self::FAILURE;
        }

        $cutoff = $date->copy()->setTime(23, 59);
        if ($date->isToday() && now()->lt($cutoff)) {
            $this->error('Daily activity can only be evaluated after 11:59 PM.');

            return self::FAILURE;
        }

        $warningCount = $accountability->generateWarningsForDate($date);
        $this->info("Daily accountability evaluated for {$date->toDateString()}; {$warningCount} employees have no recorded work activity.");

        return self::SUCCESS;
    }
}
