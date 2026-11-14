(() => {
    'use strict';

    const DEFAULT_DAY_WIDTH = 60;
    const MIN_DAY_WIDTH = 18;
    const MAX_DAY_WIDTH = 72;
    const ZOOM_STEP = 6;
    const DEFAULT_RANGE_DAYS = 14;
    const EVENT_BADGE_WIDTH = 170;
    const ROW_HEIGHT = 56;
    const EVENT_LANE_HEIGHT = 24;
    const STATUS_LABELS = {
        completed: 'Completed',
        in_progress: 'In Progress',
        pending: 'Pending',
    };

    document.querySelectorAll('[data-milestone-timeline]').forEach(initializeTimeline);

    function initializeTimeline(root) {
        const scroll = root.querySelector('[data-timeline-scroll]');
        const content = root.querySelector('[data-timeline-content]');
        const loading = root.querySelector('[data-timeline-loading]');
        const empty = root.querySelector('[data-timeline-empty]');
        const error = root.querySelector('[data-timeline-error]');
        const addButton = root.querySelector('[data-timeline-add-phase]');
        const createForm = root.querySelector('[data-timeline-create-form]');
        const todayKey = root.dataset.timelineToday;

        if (!scroll || !content || !loading || !empty || !error || !todayKey) {
            throw new Error('The project timeline is missing required markup or date configuration.');
        }

        const today = parseDateKey(todayKey);
        const state = {
            milestones: [],
            summary: { completed_count: 0, total_count: 0, progress_percent: 0 },
            dayWidth: DEFAULT_DAY_WIDTH,
            expandedMilestones: new Set(),
        };

        root.querySelector('[data-timeline-zoom-label]').textContent = '100%';
        root.querySelector('[data-timeline-zoom-out]').addEventListener('click', () => changeZoom(-ZOOM_STEP));
        root.querySelector('[data-timeline-zoom-in]').addEventListener('click', () => changeZoom(ZOOM_STEP));
        root.querySelector('[data-timeline-today]').addEventListener('click', () => {
            scroll.scrollLeft = 0;
            scroll.scrollTop = 0;
        });

        if (addButton && createForm) {
            addButton.addEventListener('click', () => {
                createForm.hidden = !createForm.hidden;
                addButton.setAttribute('aria-expanded', String(!createForm.hidden));
                if (!createForm.hidden) {
                    createForm.querySelector('input[name="title"]')?.focus();
                }
            });
        }

        content.addEventListener('pointerover', (event) => {
            const badge = event.target.closest('.milestone-timeline__event-badge');
            if (badge && !badge.contains(event.relatedTarget)) {
                positionActivityTooltip(badge);
            }
        });
        content.addEventListener('focusin', (event) => {
            const badge = event.target.closest('.milestone-timeline__event-badge');
            if (badge) {
                positionActivityTooltip(badge);
            }
        });

        content.addEventListener('click', async (event) => {
            const activitiesToggle = event.target.closest('[data-timeline-activities-toggle]');
            if (activitiesToggle) {
                const milestoneId = activitiesToggle.dataset.timelineActivitiesToggle;
                if (state.expandedMilestones.has(milestoneId)) {
                    state.expandedMilestones.delete(milestoneId);
                } else {
                    state.expandedMilestones.add(milestoneId);
                }

                const scrollLeft = scroll.scrollLeft;
                const scrollTop = scroll.scrollTop;
                renderTimeline();
                scroll.scrollLeft = scrollLeft;
                scroll.scrollTop = scrollTop;
                [...content.querySelectorAll('[data-timeline-activities-toggle]')]
                    .find((button) => button.dataset.timelineActivitiesToggle === milestoneId)
                    ?.focus();
                return;
            }

            const button = event.target.closest('[data-timeline-toggle]');
            if (!button || button.disabled) {
                return;
            }

            const milestone = state.milestones.find((item) => String(item.id) === button.dataset.timelineToggle);
            if (!milestone) {
                showError(new Error('The selected milestone is no longer available. Reload the page and try again.'));
                return;
            }

            const nextStatus = milestone.status === 'completed' ? 'pending' : 'completed';
            button.disabled = true;
            clearError();

            try {
                const url = root.dataset.updateUrlTemplate?.replace('__MILESTONE__', encodeURIComponent(milestone.id));
                if (!url || url.includes('__MILESTONE__')) {
                    throw new Error('The milestone update URL is not configured.');
                }

                await requestJson(url, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ status: nextStatus }),
                });
                await loadTimeline();
            } catch (requestError) {
                button.disabled = false;
                showError(requestError);
            }
        });

        function positionActivityTooltip(badge) {
            const badgeRect = badge.getBoundingClientRect();
            const tooltip = badge.querySelector('.milestone-timeline__event-tooltip');
            const tooltipRect = tooltip.getBoundingClientRect();
            const tooltipWidth = Math.min(230, window.innerWidth - 32);
            const tooltipHeight = Math.min(tooltipRect.height || 100, window.innerHeight - 24);
            const margin = 12;
            const left = Math.max(margin, Math.min(badgeRect.left, window.innerWidth - tooltipWidth - margin));
            const belowBadge = badgeRect.bottom + 6;
            const top = belowBadge + tooltipHeight <= window.innerHeight - margin
                ? belowBadge
                : Math.max(margin, badgeRect.top - tooltipHeight - 6);

            badge.style.setProperty('--timeline-tooltip-left', `${left}px`);
            badge.style.setProperty('--timeline-tooltip-top', `${top}px`);
        }

        function changeZoom(delta) {
            state.dayWidth = Math.min(MAX_DAY_WIDTH, Math.max(MIN_DAY_WIDTH, state.dayWidth + delta));
            const percentage = Math.round((state.dayWidth / DEFAULT_DAY_WIDTH) * 100);
            root.querySelector('[data-timeline-zoom-label]').textContent = `${percentage}%`;
            renderTimeline();
        }

        async function loadTimeline() {
            loading.hidden = false;
            empty.hidden = true;
            scroll.hidden = true;
            clearError();

            try {
                const payload = await requestJson(root.dataset.timelineUrl, {
                    headers: { Accept: 'application/json' },
                });
                validatePayload(payload);
                state.milestones = payload.milestones;
                state.summary = payload.summary;

                loading.hidden = true;
                updateSummary();
                if (state.milestones.length === 0) {
                    empty.hidden = false;
                    return;
                }

                renderTimeline();
                scroll.hidden = false;
            } catch (requestError) {
                loading.hidden = true;
                showError(requestError);
            }
        }

        function validatePayload(payload) {
            if (!payload || !Array.isArray(payload.milestones) || !payload.summary) {
                throw new Error('The project timeline response is incomplete.');
            }

            const { completed_count: completed, total_count: total, progress_percent: progress } = payload.summary;
            if (!Number.isInteger(completed) || !Number.isInteger(total) || !Number.isInteger(progress)
                || total !== payload.milestones.length || completed < 0 || completed > total
                || progress < 0 || progress > 100) {
                throw new Error('The project timeline summary does not match its milestone data.');
            }

            payload.milestones.forEach((milestone) => {
                if (!milestone || !STATUS_LABELS[milestone.status] || !Array.isArray(milestone.activities)
                    || typeof milestone.title !== 'string' || !isDateKey(milestone.milestone_date)) {
                    throw new Error('The project timeline contains an invalid milestone.');
                }

                milestone.activities.forEach((activity) => {
                    if (!activity || typeof activity.label !== 'string'
                        || (activity.actor_name !== null && typeof activity.actor_name !== 'string')
                        || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(activity.created_at)) {
                        throw new Error('The project timeline contains an invalid activity.');
                    }
                });
            });
        }

        function renderTimeline() {
            const days = buildDays(state.milestones, todayKey, state.dayWidth);
            const trackWidth = days.length * state.dayWidth;
            const rootId = `milestone-timeline-${root.dataset.timelineUrl.replace(/[^a-zA-Z0-9]/g, '-')}`;
            const rows = state.milestones.map((milestone) => renderMilestoneRow(
                milestone,
                days,
                state.dayWidth,
                rootId,
                state.expandedMilestones,
            )).join('');
            const todayOffset = state.dayWidth / 2;

            content.innerHTML = `
                <div
                    class="milestone-timeline__grid"
                    style="--timeline-left-width:230px;--timeline-track-width:${trackWidth}px;--timeline-days:${days.length};--timeline-day-width:${state.dayWidth}px"
                >
                    <div class="milestone-timeline__calendar">
                        <div class="milestone-timeline__corner">Phase / Milestone</div>
                        <div class="milestone-timeline__date-head">
                            ${renderMonthHeaders(days, state.dayWidth)}
                            <div class="milestone-timeline__days">${days.map((day) => renderDayHeader(day, state.dayWidth)).join('')}</div>
                        </div>
                    </div>
                    <div class="milestone-timeline__body">
                        ${rows}
                        <span class="milestone-timeline__today-line" style="left:calc(230px + ${todayOffset}px)" aria-hidden="true"></span>
                    </div>
                </div>
            `;
        }

        function updateSummary() {
            const { completed_count: completed, total_count: total, progress_percent: progress } = state.summary;
            root.querySelector('[data-timeline-summary]').textContent = `${completed} of ${total} completed`;
            root.querySelector('[data-timeline-footer-count]').textContent = `${completed} of ${total} phases complete`;
            root.querySelector('[data-timeline-footer-progress]').textContent = `${progress}% progress`;
            const progressbar = root.querySelector('[data-timeline-progress]');
            progressbar.setAttribute('aria-valuenow', String(progress));
            root.querySelector('[data-timeline-progress-fill]').style.width = `${progress}%`;
        }

        function showError(requestError) {
            error.textContent = requestError instanceof Error
                ? requestError.message
                : 'The project timeline could not be loaded.';
            error.hidden = false;
        }

        function clearError() {
            error.textContent = '';
            error.hidden = true;
        }

        async function requestJson(url, options) {
            if (!url) {
                throw new Error('The project timeline URL is not configured.');
            }

            const response = await fetch(url, {
                credentials: 'same-origin',
                ...options,
            });
            const payload = await response.json();
            if (!response.ok) {
                const validationErrors = payload?.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : '';
                throw new Error(payload?.message || validationErrors || `The request failed (${response.status}).`);
            }

            return payload;
        }

        loadTimeline();
    }

    function buildDays(milestones, todayKey, dayWidth) {
        let lastDateKey = addDaysToKey(todayKey, DEFAULT_RANGE_DAYS);
        milestones.forEach((milestone) => {
            if (milestone.milestone_date > lastDateKey) {
                lastDateKey = milestone.milestone_date;
            }
            milestone.activities.forEach((activity) => {
                const activityDate = activity.created_at.slice(0, 10);
                if (activityDate >= todayKey && activityDate > lastDateKey) {
                    lastDateKey = activityDate;
                }
            });
        });

        const days = [];
        for (let dateKey = todayKey; dateKey <= lastDateKey; dateKey = addDaysToKey(dateKey, 1)) {
            const date = parseDateKey(dateKey);
            days.push({
                dateKey,
                date,
                dayNumber: date.getDate(),
                weekday: new Intl.DateTimeFormat('en', { weekday: 'short' }).format(date),
                month: new Intl.DateTimeFormat('en', { month: 'short', year: 'numeric' }).format(date),
                isWeekend: date.getDay() === 0 || date.getDay() === 6,
                isToday: dateKey === todayKey,
                width: dayWidth,
            });
        }

        return days;
    }

    function renderMonthHeaders(days, dayWidth) {
        const groups = [];
        days.forEach((day) => {
            const lastGroup = groups[groups.length - 1];
            if (lastGroup && lastGroup.label === day.month) {
                lastGroup.count += 1;
            } else {
                groups.push({ label: day.month, count: 1 });
            }
        });

        return `
            <div class="milestone-timeline__months" style="width:${days.length * dayWidth}px">
                ${groups.map((group) => `<span style="width:${group.count * dayWidth}px">${escapeHtml(group.label)}</span>`).join('')}
            </div>
        `;
    }

    function renderDayHeader(day, dayWidth) {
        const classes = [
            'milestone-timeline__day',
            day.isWeekend ? 'is-weekend' : '',
            day.isToday ? 'is-today' : '',
        ].filter(Boolean).join(' ');
        const number = day.isToday
            ? `<span class="milestone-timeline__day-pill">${day.dayNumber}</span>`
            : `<span class="milestone-timeline__day-number">${day.dayNumber}</span>`;

        return `
            <div class="${classes}" style="width:${dayWidth}px" aria-label="${escapeHtml(formatLongDate(day.date))}">
                ${number}
                <span class="milestone-timeline__weekday">${escapeHtml(day.weekday)}</span>
            </div>
        `;
    }

    function renderMilestoneRow(milestone, days, dayWidth, rootId, expandedMilestones) {
        const placements = placeActivities(milestone.activities, days, dayWidth);
        const milestoneId = String(milestone.id);
        const isExpanded = expandedMilestones.has(milestoneId);
        const visiblePlacements = isExpanded ? placements.items : placements.items.slice(0, 1);
        const visibleLaneCount = isExpanded ? placements.laneCount : Math.min(1, placements.laneCount);
        const rowHeight = Math.max(ROW_HEIGHT, 16 + visibleLaneCount * EVENT_LANE_HEIGHT);
        const status = milestone.status;
        const statusLabel = STATUS_LABELS[status];
        const description = milestone.description || '';
        const titleAttribute = description ? `${milestone.title}\n${description}` : milestone.title;
        const statusIcon = renderStatusIcon(milestone, statusLabel);
        const activityToggleLabel = isExpanded
            ? `Show one activity badge for ${milestone.title}`
            : `Show all ${placements.items.length} activity badges for ${milestone.title}`;
        const activityToggle = placements.items.length > 1
            ? `
                <button
                    type="button"
                    class="milestone-timeline__activity-toggle"
                    data-timeline-activities-toggle="${escapeHtml(milestoneId)}"
                    aria-expanded="${String(isExpanded)}"
                    aria-label="${escapeHtml(activityToggleLabel)}"
                    title="${escapeHtml(activityToggleLabel)}"
                ><i class="bi ${isExpanded ? 'bi-chevron-up' : 'bi-chevron-down'}" aria-hidden="true"></i></button>
            `
            : '';
        const trackId = `${rootId}-milestone-${milestoneId}`;
        const cells = days.map((day) => {
            const classes = [
                'milestone-timeline__cell',
                day.isWeekend ? 'is-weekend' : '',
                day.isToday ? 'is-today' : '',
            ].filter(Boolean).join(' ');
            return `<div class="${classes}" aria-hidden="true"></div>`;
        }).join('');

        return `
            <div class="milestone-timeline__row" style="--phase-row-height:${rowHeight}px">
                <div class="milestone-timeline__phase-name" aria-label="${escapeHtml(`${milestone.title}, ${statusLabel}`)}">
                    ${statusIcon}
                    <div class="milestone-timeline__phase-copy">
                        <strong title="${escapeHtml(titleAttribute)}">${escapeHtml(milestone.title)}</strong>
                        <span class="milestone-timeline__phase-status is-${status}">${escapeHtml(statusLabel)}</span>
                    </div>
                    ${activityToggle}
                </div>
                <div id="${escapeHtml(trackId)}" class="milestone-timeline__track" style="--timeline-track-width:${days.length * dayWidth}px;--timeline-days:${days.length};--timeline-day-width:${dayWidth}px;--phase-row-height:${rowHeight}px">
                    ${cells}
                    ${visiblePlacements.map((placement) => renderActivityBadge(
                        milestone,
                        placement,
                        dayWidth,
                        rootId,
                    )).join('')}
                </div>
            </div>
        `;
    }

    function renderStatusIcon(milestone, statusLabel) {
        const iconClass = milestone.status === 'completed'
            ? 'bi-check-lg'
            : milestone.status === 'in_progress'
                ? 'bi-diamond-fill'
                : 'bi-diamond';
        const className = `milestone-timeline__status-icon is-${milestone.status}`;
        const ariaLabel = `Status: ${statusLabel}. Click to mark ${milestone.status === 'completed' ? 'pending' : 'completed'}.`;

        if (milestone.can_update) {
            return `
                <button
                    type="button"
                    class="${className} is-toggle"
                    data-timeline-toggle="${escapeHtml(String(milestone.id))}"
                    aria-label="${escapeHtml(ariaLabel)}"
                    title="${escapeHtml(ariaLabel)}"
                ><i class="bi ${iconClass}" aria-hidden="true"></i><span class="visually-hidden">${statusLabel}</span></button>
            `;
        }

        return `<span class="${className}" role="img" aria-label="Status: ${escapeHtml(statusLabel)}" title="Status: ${escapeHtml(statusLabel)}"><i class="bi ${iconClass}" aria-hidden="true"></i></span>`;
    }

    function placeActivities(activities, days, dayWidth) {
        const todayKey = days[0]?.dateKey;
        const dayIndexes = new Map(days.map((day, index) => [day.dateKey, index]));
        const laneEnds = [];
        const items = [...activities]
            .filter((activity) => activity.created_at.slice(0, 10) >= todayKey)
            .sort((first, second) => first.created_at.localeCompare(second.created_at) || first.id - second.id)
            .flatMap((activity) => {
                const dateKey = activity.created_at.slice(0, 10);
                const dayIndex = dayIndexes.get(dateKey);
                if (dayIndex === undefined) {
                    return [];
                }

                const occupiedDays = Math.max(1, Math.ceil((EVENT_BADGE_WIDTH + 8) / dayWidth));
                let laneIndex = laneEnds.findIndex((endIndex) => dayIndex >= endIndex);
                if (laneIndex === -1) {
                    laneIndex = laneEnds.length;
                    laneEnds.push(dayIndex + occupiedDays);
                } else {
                    laneEnds[laneIndex] = dayIndex + occupiedDays;
                }

                return [{ activity, dayIndex, laneIndex }];
            });

        return { items, laneCount: laneEnds.length };
    }

    function renderActivityBadge(milestone, placement, dayWidth, rootId) {
        const { activity, dayIndex, laneIndex } = placement;
        const eventDate = activity.created_at.slice(0, 10);
        const eventTime = formatEventTime(activity.created_at);
        const dateLabel = formatLongDate(parseDateKey(eventDate));
        const colorClass = activity.type === 'created'
            ? 'is-created'
            : activity.type === 'updated'
                ? 'is-updated'
                : `is-status-${activity.new_status || 'unknown'}`;
        const tooltipId = `${rootId}-activity-${milestone.id}-${activity.id}`;
        const badgeLabel = `${activity.label} on ${dateLabel} at ${eventTime}`;
        const actor = `<span class="milestone-timeline__event-actor">By: ${escapeHtml(activity.actor_name || 'Unknown user')}</span>`;
        const description = milestone.description
            ? `<span class="milestone-timeline__event-description">${escapeHtml(milestone.description)}</span>`
            : '';

        return `
            <span
                class="milestone-timeline__event-badge ${colorClass}"
                style="left:${dayIndex * dayWidth + 4}px;top:${8 + laneIndex * EVENT_LANE_HEIGHT}px"
                tabindex="0"
                role="note"
                aria-label="${escapeHtml(badgeLabel)}"
                aria-describedby="${escapeHtml(tooltipId)}"
            >
                <span class="milestone-timeline__event-label">${escapeHtml(activity.label)}</span>
                <span id="${escapeHtml(tooltipId)}" class="milestone-timeline__event-tooltip" role="tooltip">
                    <strong>${escapeHtml(milestone.title)}</strong>
                    <span>${escapeHtml(activity.label)}</span>
                    ${actor}
                    ${description}
                    <time datetime="${escapeHtml(activity.created_at)}">${escapeHtml(dateLabel)} · ${escapeHtml(eventTime)}</time>
                </span>
            </span>
        `;
    }

    function formatEventTime(dateTime) {
        const match = dateTime.match(/T(\d{2}):(\d{2}):\d{2}/);
        if (!match) {
            throw new Error('A milestone activity has an invalid time.');
        }

        const hour = Number(match[1]);
        const minute = match[2];
        const suffix = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 || 12;

        return `${displayHour}:${minute} ${suffix}`;
    }

    function formatLongDate(date) {
        return new Intl.DateTimeFormat('en', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(date);
    }

    function parseDateKey(dateKey) {
        if (!isDateKey(dateKey)) {
            throw new Error(`Invalid calendar date: ${dateKey}`);
        }

        const [year, month, day] = dateKey.split('-').map(Number);
        const date = new Date(year, month - 1, day, 12);
        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
            throw new Error(`Invalid calendar date: ${dateKey}`);
        }

        return date;
    }

    function addDaysToKey(dateKey, days) {
        const date = parseDateKey(dateKey);
        date.setDate(date.getDate() + days);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function isDateKey(value) {
        return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
    }

    function csrfToken() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) {
            throw new Error('The security token is missing. Refresh the page and try again.');
        }

        return token;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        })[character]);
    }
})();
