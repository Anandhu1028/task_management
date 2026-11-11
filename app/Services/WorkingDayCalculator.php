<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WorkingDayCalculator
{
    public function count(string|\DateTimeInterface $startDate, string|\DateTimeInterface $deadlineDate): int
    {
        return count($this->dates($startDate, $deadlineDate));
    }

    /** @return list<CarbonImmutable> */
    public function dates(string|\DateTimeInterface $startDate, string|\DateTimeInterface $deadlineDate): array
    {
        $start = CarbonImmutable::parse($startDate, config('app.timezone'))->startOfDay();
        $deadline = CarbonImmutable::parse($deadlineDate, config('app.timezone'))->startOfDay();
        $weeklyHolidays = array_map('intval', config('tasks.volume_weekly_holidays', [0, 6]));
        $holidays = array_flip(config('tasks.volume_holidays', []));
        $dates = [];

        while ($start->lte($deadline)) {
            if (! in_array($start->dayOfWeek, $weeklyHolidays, true) && ! isset($holidays[$start->toDateString()])) {
                $dates[] = $start;
            }
            $start = $start->addDay();
        }

        return $dates;
    }
}
