@props([
    'departmentWorkload' => [],
])

@php
    $labels = $departmentWorkload['labels'] ?? [];
    $memberPeriods = $departmentWorkload['memberPeriods'] ?? [];
    $weekly = $departmentWorkload['weekly'] ?? ['labels' => [], 'created' => [], 'completed' => []];
    $totalAssigned = $departmentWorkload['totalAssigned'] ?? 0;
@endphp

<section class="tf-grid tf-grid--2 mb-4" id="departmentEmployeesWorkloadSection">
    <article class="tf-card">
        <div class="tf-card__head tf-card__head--tabs">
            <div class="tf-card__title">
                <i class="bi bi-people tf-card__icon"></i>
                <div>
                    <h2>Team members task graph</h2>
                    <p>Completed, in progress and overdue tasks</p>
                </div>
            </div>

            <label class="tf-chart-range">
                <span class="visually-hidden">Team member task period</span>
                <select class="form-select form-select-sm" data-member-range>
                    <option value="this_week">This week</option>
                    <option value="last_4_weeks">Last 4 weeks</option>
                </select>
            </label>
        </div>

        <div class="tf-chart-wrap tf-chart-wrap--workload">
            @if (count($labels) > 0)
                <canvas
                    id="deptWorkloadChart"
                    data-labels='@json($labels)'
                    data-periods='@json($memberPeriods)'
                ></canvas>
            @else
                <p class="text-center text-muted py-5">No department task assignments to chart yet.</p>
            @endif
        </div>

        <p class="tf-chart-foot">{{ $totalAssigned }} assigned tasks across the department</p>
    </article>

    <article class="tf-card">
        <div class="tf-card__head tf-card__head--tabs">
            <div class="tf-card__title">
                <i class="bi bi-bar-chart-line tf-card__icon"></i>
                <div>
                    <h2>Total department graph</h2>
                    <p>Tasks created and completed each week</p>
                </div>
            </div>

            <label class="tf-chart-range">
                <span class="visually-hidden">Department chart period</span>
                <select class="form-select form-select-sm" data-week-range>
                    <option value="4">Last 4 weeks</option>
                    <option value="8">Last 8 weeks</option>
                </select>
            </label>
        </div>

        <div class="tf-chart-wrap tf-chart-wrap--workload">
            @if (count($weekly['labels'] ?? []) > 0)
                <canvas
                    id="departmentWeeklyChart"
                    data-weekly='@json($weekly)'
                ></canvas>
            @else
                <p class="text-center text-muted py-5">No department task activity to chart yet.</p>
            @endif
        </div>
    </article>
</section>
