(() => {
    const STATUS_LABELS = {
        pending: 'Pending',
        in_progress: 'In progress',
        completed: 'Completed',
        cancelled: 'Cancelled',
    };
    const MIN_DAY_WIDTH = 18;
    const MAX_DAY_WIDTH = 72;
    const DEFAULT_DAY_WIDTH = 64;
    const LEFT_COLUMN_WIDTH = 230;

    document.querySelectorAll('[data-milestone-timeline]').forEach((root) => {
        const source = [...root.querySelectorAll('[data-timeline-phase]')];
        const phases = source.map((element) => ({
            id: element.dataset.phaseId,
            name: element.dataset.phaseName || '',
            description: element.dataset.phaseDescription || '',
            startDate: parseDate(element.dataset.phaseStartDate),
            endDate: parseDate(element.dataset.phaseEndDate),
            createdDate: parseDate(element.dataset.phaseCreatedDate),
            activityActor: element.dataset.phaseActivityActor || element.dataset.phaseCreator || 'Unknown user',
            activityText: element.dataset.phaseActivityText || 'created the milestone',
            activityAt: element.dataset.phaseActivityAt || '',
            completedAt: parseDate(element.dataset.phaseCompletedAt),
            isMilestone: element.dataset.phaseIsMilestone === 'true',
            status: element.dataset.phaseStatus,
            progressPercentage: Number(element.dataset.phaseProgress) || 0,
            activityEvents: JSON.parse(element.dataset.phaseActivityEvents || '[]')
                .map((activity) => ({ ...activity, date: parseDate(activity.date) }))
                .filter((activity) => activity.date),
            activityExpanded: true,
            updateUrl: element.dataset.phaseUpdateUrl,
            canUpdate: element.dataset.phaseCanUpdate === 'true',
        }));

        initializeTimeline(root, { phases, onUpdate: updatePhase });
    });

    function initializeTimeline(root, { phases, onUpdate }) {
        const error = root.querySelector('[data-timeline-error]');
        const addPhaseButton = root.querySelector('[data-timeline-add-phase]');
        const createPhaseForm = root.querySelector('.milestone-timeline__create-form');

        addPhaseButton?.addEventListener('click', () => {
            createPhaseForm.hidden = false;
            addPhaseButton.setAttribute('aria-expanded', 'true');
            createPhaseForm.querySelector('input[name="title"]')?.focus();
        });

        if (phases.length === 0) {
            return;
        }

        if (phases.some((phase) => !phase.startDate || !phase.endDate || !STATUS_LABELS[phase.status])) {
            showError(error, 'A phase has an unsupported date or status, so the timeline could not be displayed.');
            return;
        }

        const today = startOfDay(new Date());
        const allDates = phases.flatMap((phase) => [
            phase.startDate,
            phase.endDate,
            ...phase.activityEvents.map((activity) => activity.date),
        ]);
        const earliestDate = new Date(Math.min(...allDates.map((date) => date.getTime())));
        const latestDate = new Date(Math.max(...allDates.map((date) => date.getTime())));
        const rangeStart = addDays(earliestDate, -3);
        const rangeEnd = addDays(latestDate, 14);
        const days = datesBetween(rangeStart, rangeEnd);
        const todayIndex = days.findIndex((date) => sameDay(date, today));
        const content = root.querySelector('[data-timeline-content]');
        const scroll = root.querySelector('[data-timeline-scroll]');
        const zoomLabel = root.querySelector('[data-timeline-zoom-label]');
        const editorLayer = document.createElement('div');
        editorLayer.className = 'milestone-timeline__editor-layer';
        root.append(editorLayer);
        const editFormOrigins = new WeakMap();
        let dayWidth = DEFAULT_DAY_WIDTH;

        const positionEditForm = (form) => {
            const viewport = scroll.getBoundingClientRect();
            const rootRect = root.getBoundingClientRect();
            const width = Math.min(270, Math.max(180, viewport.width - 16));

            form.style.width = `${width}px`;
            form.style.left = `${viewport.left + 8 - rootRect.left}px`;
            form.style.top = `${viewport.top + 60 - rootRect.top}px`;
        };

        const positionPopovers = () => {
            const viewport = scroll.getBoundingClientRect();
            const minimumLeft = viewport.left + 8;
            const popovers = content.querySelectorAll('.milestone-timeline__phase-popover');

            popovers.forEach((popover) => {
                const bar = popover.parentElement;
                const barRect = bar.getBoundingClientRect();
                const popoverWidth = Math.min(248, Math.max(160, viewport.width - 16));
                const maximumLeft = viewport.right - popoverWidth - 8;
                const left = Math.max(minimumLeft, Math.min(barRect.left, maximumLeft));
                const originalDisplay = popover.style.display;

                popover.style.width = `${popoverWidth}px`;
                popover.style.left = `${left - barRect.left}px`;
                popover.style.display = 'grid';
                const popoverHeight = popover.getBoundingClientRect().height;
                const preferredTop = viewport.top + 60;
                const maximumTop = Math.max(viewport.top + 8, viewport.bottom - popoverHeight - 8);
                const top = Math.min(preferredTop, maximumTop);

                popover.style.top = `${top - barRect.top}px`;
                popover.style.display = originalDisplay;
            });

            const activityTooltips = content.querySelectorAll('.milestone-timeline__activity-tooltip');
            activityTooltips.forEach((tooltip) => {
                const badge = tooltip.parentElement;
                const badgeRect = badge.getBoundingClientRect();
                const tooltipWidth = Math.min(220, Math.max(160, viewport.width - 16));
                const maximumLeft = viewport.right - tooltipWidth - 8;
                const left = Math.max(minimumLeft, Math.min(badgeRect.left, maximumLeft));
                const originalDisplay = tooltip.style.display;
                const originalVisibility = tooltip.style.visibility;

                tooltip.style.width = `${tooltipWidth}px`;
                tooltip.style.left = `${left - badgeRect.left}px`;
                tooltip.style.visibility = 'hidden';
                tooltip.style.display = 'grid';
                const tooltipHeight = tooltip.getBoundingClientRect().height;
                const belowTop = badgeRect.bottom;
                const top = belowTop + tooltipHeight <= viewport.bottom - 8
                    ? belowTop
                    : Math.max(viewport.top + 8, badgeRect.top - tooltipHeight);

                tooltip.style.top = `${top - badgeRect.top}px`;
                tooltip.style.display = originalDisplay;
                tooltip.style.visibility = originalVisibility;
            });

            const editForm = editorLayer.querySelector('[data-phase-edit-form]:not([hidden])');
            if (editForm instanceof HTMLFormElement) {
                positionEditForm(editForm);
            }
        };

        scroll.addEventListener('scroll', positionPopovers, { passive: true });
        window.addEventListener('resize', positionPopovers);
        root.addEventListener('pointerover', (event) => {
            if (event.target instanceof Element && event.target.closest('.milestone-timeline__item')) {
                positionPopovers();
            }
        });
        root.addEventListener('focusin', (event) => {
            if (event.target instanceof Element && event.target.closest('.milestone-timeline__item')) {
                positionPopovers();
            }
        });

        const render = () => {
            const openForm = editorLayer.querySelector('[data-phase-edit-form]:not([hidden])');
            if (openForm instanceof HTMLFormElement) {
                closeEditForm(openForm, false);
            }

            const previousScrollLeft = scroll.scrollLeft;
            const previousScrollTop = scroll.scrollTop;
            const gridWidth = days.length * dayWidth;
            const style = `--timeline-days:${days.length};--timeline-day-width:${dayWidth}px;--timeline-track-width:${gridWidth}px`;
            const dateHeaders = days.map((date) => {
                const isToday = sameDay(date, today);
                const weekday = new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(date);
                const dateLabel = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(date);

                return `<span class="milestone-timeline__day${isWeekend(date) ? ' is-weekend' : ''}${isToday ? ' is-today' : ''}" style="width:${dayWidth}px"><span class="milestone-timeline__day-date">${escapeHtml(dateLabel)}</span><span class="milestone-timeline__weekday">${escapeHtml(weekday)}</span></span>`;
            }).join('');
            const rows = phases.map((phase) => renderPhaseRow(phase, days, dayWidth, today)).join('');
            const todayLine = todayIndex < 0
                ? ''
                : `<span class="milestone-timeline__today-line" style="left:${LEFT_COLUMN_WIDTH + (todayIndex * dayWidth) + dayWidth / 2}px" aria-hidden="true"></span>`;

            content.innerHTML = `
                <div class="milestone-timeline__grid" style="${style}">
                    <div class="milestone-timeline__calendar">
                        <div class="milestone-timeline__corner">Phase / Milestone</div>
                        <div class="milestone-timeline__date-head">
                            <div class="milestone-timeline__days">${dateHeaders}</div>
                        </div>
                    </div>
                    <div class="milestone-timeline__body">
                        ${todayLine}
                        ${rows}
                    </div>
                </div>`;
            scroll.scrollLeft = previousScrollLeft;
            scroll.scrollTop = previousScrollTop;
            positionPopovers();
            zoomLabel.textContent = `${Math.round((dayWidth / DEFAULT_DAY_WIDTH) * 100)}%`;
            updateProgress(root, phases);
        };

        const closeEditForm = (form, restoreFocus = true) => {
            const origin = editFormOrigins.get(form);
            if (origin) {
                origin.placeholder.parentNode?.insertBefore(form, origin.placeholder);
                origin.placeholder.remove();
                editFormOrigins.delete(form);
            }

            form.hidden = true;
            const phaseName = origin?.phaseName ?? form.closest('.milestone-timeline__phase-name');
            phaseName?.classList.remove('is-editing');
            const editButton = origin?.editButton ?? phaseName?.querySelector('[data-phase-edit]');
            editButton?.setAttribute('aria-expanded', 'false');
            if (restoreFocus) {
                editButton?.focus();
            }
        };

        root.addEventListener('click', async (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const activityToggle = target?.closest('[data-phase-activity-toggle]');
            if (activityToggle) {
                const phase = phases.find((item) => item.id === activityToggle.dataset.phaseActivityToggle);
                if (!phase) {
                    return;
                }

                phase.activityExpanded = !phase.activityExpanded;
                render();
                root.querySelectorAll('[data-phase-activity-toggle]').forEach((toggle) => {
                    if (toggle.dataset.phaseActivityToggle === phase.id) {
                        toggle.focus();
                    }
                });
                return;
            }

            const editButton = target?.closest('[data-phase-edit]');
            if (editButton) {
                const phaseName = editButton.closest('.milestone-timeline__phase-name');
                const form = phaseName?.querySelector('[data-phase-edit-form]');
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                const openForm = editorLayer.querySelector('[data-phase-edit-form]:not([hidden])');
                if (openForm instanceof HTMLFormElement) {
                    closeEditForm(openForm, false);
                }

                const placeholder = document.createComment('');
                phaseName.insertBefore(placeholder, form);
                editFormOrigins.set(form, { placeholder, phaseName, editButton });
                editorLayer.append(form);
                form.hidden = false;
                phaseName.classList.add('is-editing');
                editButton.setAttribute('aria-expanded', 'true');
                positionEditForm(form);
                const titleInput = form.querySelector('input[name="title"]');
                titleInput?.focus();
                titleInput?.select();
                return;
            }

            const cancelButton = target?.closest('[data-phase-edit-cancel]');
            if (cancelButton) {
                const form = cancelButton.closest('[data-phase-edit-form]');
                if (form instanceof HTMLFormElement) {
                    closeEditForm(form);
                }
                return;
            }

            if (target?.closest('.milestone-timeline__phase-note')) {
                return;
            }

            const button = target?.closest('[data-timeline-toggle]');
            if (!button
                || button.disabled
                || button.getAttribute('aria-disabled') === 'true'
                || target?.closest('.milestone-timeline__phase-popover')) {
                return;
            }

            const phase = phases.find((item) => item.id === button.dataset.timelineToggle);
            if (!phase) {
                return;
            }

            const nextStatus = phase.status === 'completed' ? 'pending' : 'completed';
            button.disabled = true;
            error.hidden = true;

            try {
                await onUpdate(phase, { status: nextStatus });
                render();
            } catch (requestError) {
                showError(error, requestError instanceof Error
                    ? requestError.message
                    : 'Could not update this phase. Try again.');
                button.disabled = false;
            }
        });

        root.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-phase-edit-form]')) {
                return;
            }

            event.preventDefault();
            const phase = phases.find((item) => item.id === form.dataset.phaseEditForm);
            if (!phase) {
                return;
            }

            const titleInput = form.querySelector('input[name="title"]');
            const descriptionInput = form.querySelector('textarea[name="description"]');
            const statusInput = form.querySelector('select[name="status"]');
            if (!(titleInput instanceof HTMLInputElement)
                || !(descriptionInput instanceof HTMLTextAreaElement)
                || !(statusInput instanceof HTMLSelectElement)) {
                return;
            }

            const title = titleInput.value.trim();
            if (!title) {
                titleInput.setCustomValidity('Enter a phase name.');
                titleInput.reportValidity();
                return;
            }
            titleInput.setCustomValidity('');

            const saveButton = form.querySelector('[type="submit"]');
            if (saveButton instanceof HTMLButtonElement) {
                saveButton.disabled = true;
            }
            error.hidden = true;

            try {
                await onUpdate(phase, {
                    title,
                    description: descriptionInput.value.trim() || null,
                    status: statusInput.value,
                });
                closeEditForm(form, false);
                render();
                root.querySelectorAll('[data-phase-edit]').forEach((button) => {
                    if (button.dataset.phaseEdit === phase.id) {
                        button.focus();
                    }
                });
            } catch (requestError) {
                showError(error, requestError instanceof Error
                    ? requestError.message
                    : 'Could not update this phase. Try again.');
                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.disabled = false;
                }
            }
        });

        root.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            const form = root.querySelector('[data-phase-edit-form]:not([hidden])');
            if (form instanceof HTMLFormElement) {
                event.preventDefault();
                closeEditForm(form);
            }
        });

        root.querySelector('[data-timeline-today]')?.addEventListener('click', () => {
            if (todayIndex >= 0) {
                scroll.scrollLeft = Math.max(
                    0,
                    LEFT_COLUMN_WIDTH + (todayIndex * dayWidth) - (scroll.clientWidth / 2),
                );
            }
            scroll.scrollTop = 0;
        });

        root.querySelector('[data-timeline-zoom-out]')?.addEventListener('click', () => {
            dayWidth = Math.max(MIN_DAY_WIDTH, dayWidth - 6);
            render();
        });
        root.querySelector('[data-timeline-zoom-in]')?.addEventListener('click', () => {
            dayWidth = Math.min(MAX_DAY_WIDTH, dayWidth + 6);
            render();
        });

        render();
    }

    function renderPhaseRow(phase, days, dayWidth, today) {
        const startIndex = dayIndex(days, phase.startDate);
        const endIndex = dayIndex(days, phase.endDate);
        const isOverdue = phase.status !== 'completed' && phase.endDate < today;
        const visualStatus = phase.status === 'completed'
            ? 'completed'
            : isOverdue
                ? 'overdue'
                : phase.status === 'in_progress'
                    ? 'in-progress'
                    : 'pending';
        const statusLabel = STATUS_LABELS[phase.status];
        const statusDescription = isOverdue ? `${statusLabel}; Overdue` : statusLabel;
        const progress = phase.status === 'completed'
            ? 100
            : Math.min(100, Math.max(0, Math.round(phase.progressPercentage)));
        const markerDate = phase.endDate;
        const createdDate = phase.createdDate || phase.startDate;
        const description = phase.description.trim() || 'No description provided.';
        const activityDescription = phase.activityEvents.length > 0
            ? phase.activityEvents.map((activity) => (
                `${activity.label} by ${activity.actor} on ${formatDate(activity.date)}`
            )).join('; ')
            : `${phase.activityActor} ${phase.activityText}`;
        const detailText = [
            `Phase name: ${phase.name}`,
            `Status: ${statusDescription}`,
            `Description: ${description}`,
            `Created date: ${formatDate(createdDate)}`,
            `Target date: ${formatDate(phase.endDate)}`,
            `Activity: ${activityDescription}${phase.activityAt ? ` on ${phase.activityAt}` : ''}`,
        ].join('. ');
        const popoverId = `milestone-details-${phase.id}`;
        const popover = `
            <span id="${escapeHtml(popoverId)}" class="milestone-timeline__phase-popover" role="tooltip">
                <span class="milestone-timeline__phase-popover-heading">
                    <span class="milestone-timeline__phase-popover-field">
                        <span class="milestone-timeline__phase-popover-label">Phase name</span>
                        <strong>${escapeHtml(phase.name)}</strong>
                    </span>
                    <span class="milestone-timeline__phase-popover-status">
                        <span class="milestone-timeline__phase-popover-label">Status</span>
                        <strong>${escapeHtml(statusDescription)}</strong>
                    </span>
                </span>
                <span class="milestone-timeline__phase-popover-description">
                    <span class="milestone-timeline__phase-popover-label">Description</span>
                    <span>${escapeHtml(description)}</span>
                </span>
                <span class="milestone-timeline__phase-popover-dates">
                    <span>
                        <span class="milestone-timeline__phase-popover-label">Created</span>
                        <strong>${escapeHtml(formatDate(createdDate))}</strong>
                    </span>
                    <span>
                        <span class="milestone-timeline__phase-popover-label">Target</span>
                        <strong>${escapeHtml(formatDate(phase.endDate))}</strong>
                    </span>
                </span>
                <span class="milestone-timeline__phase-popover-activity">
                    <span class="milestone-timeline__phase-popover-label">Activity</span>
                    <span><strong>${escapeHtml(phase.activityActor)}</strong> ${escapeHtml(phase.activityText)}</span>
                    ${phase.activityAt ? `<small>${escapeHtml(phase.activityAt)}</small>` : ''}
                </span>
            </span>`;
        const leftIndex = Math.min(startIndex, endIndex);
        const left = leftIndex * dayWidth;
        const spanDays = Math.max(1, Math.abs(endIndex - startIndex) + 1);
        const startOffset = (startIndex - leftIndex) * dayWidth;
        const deadlineOffset = ((endIndex - leftIndex) * dayWidth) + (dayWidth / 2);
        const pathStart = startOffset + 6;
        const pathWidth = Math.max(12, deadlineOffset - pathStart);
        const targetOffset = Math.max(0, deadlineOffset - pathStart);
        const badgeWidth = 180;
        const itemWidth = Math.max(spanDays * dayWidth, startOffset + badgeWidth);
        const eventLanes = [];
        const activityEvents = phase.activityEvents.length > 0
            ? phase.activityEvents
            : [{
                type: 'milestone_created',
                status: '',
                label: 'Milestone created',
                date: createdDate,
                actor: phase.activityActor,
            }];
        const visibleActivityEvents = phase.activityExpanded
            ? activityEvents
            : activityEvents.slice(0, 1);
        const eventBadges = visibleActivityEvents.map((activity, activityIndex) => {
            const eventIndex = dayIndex(days, activity.date);
            if (eventIndex < 0) {
                return '';
            }

            const badgeVariant = activity.type === 'milestone_created'
                ? 'created'
                : activity.type === 'milestone_updated'
                    ? 'updated'
                    : `status-${activity.status || 'pending'}`;
            const eventOffset = ((eventIndex - leftIndex) * dayWidth) + 6 - pathStart;
            const estimatedBadgeWidth = Math.min(180, (activity.label.length * 5) + 16);
            let laneIndex = eventLanes.findIndex((lane) => lane.every((badge) => (
                eventOffset >= badge.right + 8
                || eventOffset + estimatedBadgeWidth + 8 <= badge.left
            )));
            if (laneIndex < 0) {
                laneIndex = eventLanes.length;
                eventLanes.push([]);
            }
            eventLanes[laneIndex].push({
                left: eventOffset,
                right: eventOffset + estimatedBadgeWidth,
            });
            const badgeTop = 14 + (laneIndex * 24);
            const tooltipId = `milestone-activity-${phase.id}-${activity.id ?? activityIndex}`;
            const statusDetails = activity.type !== 'milestone_updated' && activity.status_label
                ? `<span class="milestone-timeline__activity-tooltip-row"><strong>Status</strong><span>${escapeHtml(activity.old_status_label ? `${activity.old_status_label} → ` : '')}${escapeHtml(activity.status_label)}</span></span>`
                : '';
            const remarks = activity.remarks
                ? `<span class="milestone-timeline__activity-tooltip-remarks">${escapeHtml(activity.remarks)}</span>`
                : '';
            const tooltip = `<span id="${escapeHtml(tooltipId)}" class="milestone-timeline__activity-tooltip" role="tooltip"><strong>${escapeHtml(activity.label)}</strong><span class="milestone-timeline__activity-tooltip-row"><strong>By</strong><span>${escapeHtml(activity.actor || 'Unknown user')}</span></span><span class="milestone-timeline__activity-tooltip-row"><strong>Date</strong><span>${escapeHtml(formatDate(activity.date))}${activity.time ? ` · ${escapeHtml(activity.time)}` : ''}</span></span>${statusDetails}${remarks}</span>`;

            return `<span class="milestone-timeline__phase-note" style="left:${eventOffset}px;top:${badgeTop}px" aria-hidden="true"><span class="milestone-timeline__phase-note-label milestone-timeline__phase-note-label--${escapeHtml(badgeVariant)}">${escapeHtml(activity.label)}</span>${tooltip}</span>`;
        }).join('');
        const activityBadgesId = `milestone-activity-${phase.id}`;
        const activityBadges = `<span id="${escapeHtml(activityBadgesId)}" class="milestone-timeline__activity-badges">${eventBadges}</span>`;
        const rowHeight = Math.max(76, 62 + ((eventLanes.length - 1) * 24));
        const isMilestone = phase.isMilestone || startIndex === endIndex;
        const targetStatus = phase.status === 'completed' ? 'pending' : 'completed';
        const canToggle = phase.canUpdate && phase.status !== 'cancelled';
        const statusOptions = [
            { value: 'pending', label: 'Pending' },
            { value: 'completed', label: 'Completed' },
            { value: 'cancelled', label: 'Cancel' },
            ...(phase.status === 'in_progress' ? [{ value: 'in_progress', label: 'In progress' }] : []),
        ];
        const item = `<button type="button" class="milestone-timeline__item milestone-timeline__item--bar is-${visualStatus}${isMilestone ? ' is-milestone' : ''}" style="left:${left}px;width:${itemWidth}px" aria-label="${escapeHtml(`${detailText}. Activate to mark ${targetStatus === 'completed' ? 'complete' : 'pending'}.`)}" aria-describedby="${escapeHtml(popoverId)}" aria-disabled="${canToggle ? 'false' : 'true'}" aria-pressed="${phase.status === 'completed'}" data-timeline-toggle="${escapeHtml(phase.id)}"><span class="milestone-timeline__phase-bar" style="left:${pathStart}px;width:${pathWidth}px" aria-hidden="true"><span class="milestone-timeline__phase-bar-progress" style="width:${progress}%"></span>${phase.status === 'completed' ? '<i class="bi bi-check-lg milestone-timeline__phase-start-check"></i>' : ''}${activityBadges}${popover}<span class="milestone-timeline__phase-target" style="left:${targetOffset}px"><i class="bi bi-diamond-fill milestone-timeline__phase-marker"></i><time>(${escapeHtml(formatShortDate(markerDate))})</time></span></span></button>`;

        const editForm = phase.canUpdate
            ? `<form id="milestone-edit-${escapeHtml(phase.id)}" class="milestone-timeline__phase-edit" role="dialog" aria-modal="false" aria-label="Edit phase ${escapeHtml(phase.name)}" data-phase-edit-form="${escapeHtml(phase.id)}" hidden>
                    <label>
                        <span>Phase name</span>
                        <input type="text" name="title" value="${escapeHtml(phase.name)}" maxlength="500" required>
                    </label>
                    <label>
                        <span>Description</span>
                        <textarea name="description" rows="2" maxlength="5000">${escapeHtml(phase.description)}</textarea>
                    </label>
                    <label>
                        <span>Status</span>
                        <select name="status"${phase.status === 'cancelled' ? ' disabled' : ''}>${statusOptions.map(({ value, label }) => `<option value="${value}"${phase.status === value ? ' selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>
                    </label>
                    <div class="milestone-timeline__phase-edit-actions">
                        <button type="button" data-phase-edit-cancel>Cancel</button>
                        <button type="submit">Save</button>
                    </div>
                </form>`
            : '';
        const phaseTitle = phase.canUpdate
            ? `<button type="button" class="milestone-timeline__phase-edit-trigger" data-phase-edit="${escapeHtml(phase.id)}" aria-haspopup="dialog" aria-expanded="false" aria-controls="milestone-edit-${escapeHtml(phase.id)}">${escapeHtml(phase.name)}</button>`
            : `<strong>${escapeHtml(phase.name)}</strong>`;
        const activityToggle = activityEvents.length > 1
            ? `<button type="button" class="milestone-timeline__activity-toggle" data-phase-activity-toggle="${escapeHtml(phase.id)}" aria-expanded="${phase.activityExpanded ? 'true' : 'false'}" aria-controls="${escapeHtml(activityBadgesId)}" aria-label="${phase.activityExpanded ? 'Collapse' : 'Expand'} activity badges for ${escapeHtml(phase.name)}"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>`
            : '';

        return `
            <div class="milestone-timeline__row" style="--phase-row-height:${rowHeight}px">
                <div class="milestone-timeline__phase-name" title="${escapeHtml(phase.name)}">
                    <span class="milestone-timeline__phase-icon" aria-hidden="true"><i class="bi ${phase.status === 'completed' ? 'bi-check-circle-fill' : 'bi-diamond-fill'}"></i></span>
                    <span class="milestone-timeline__phase-copy">
                        ${phaseTitle}
                        <span>${escapeHtml(formatShortDate(phase.startDate))} – ${escapeHtml(formatShortDate(phase.endDate))}</span>
                    </span>
                    ${activityToggle}
                    ${editForm}
                </div>
                <div class="milestone-timeline__track">
                    ${renderDayCells(days, today)}
                    ${item}
                </div>
            </div>`;
    }

    function updateProgress(root, phases) {
        const completed = phases.filter((phase) => phase.status === 'completed').length;
        const total = phases.length;
        const percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

        root.querySelector('[data-timeline-summary]').textContent = `${completed} of ${total} completed`;
        root.querySelector('[data-timeline-footer-count]').textContent = `${completed} of ${total} phases complete`;
        root.querySelector('[data-timeline-footer-progress]').textContent = `${percentage}% progress`;
    }

    async function updatePhase(phase, changes) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!csrfToken) {
            throw new Error('The security token is missing. Refresh the page and try again.');
        }

        const response = await fetch(phase.updateUrl, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(changes),
        });

        if (!response.ok) {
            throw new Error(`Could not update this phase (HTTP ${response.status}).`);
        }

        const result = await response.json();
        if (result.ok !== true
            || Object.entries(changes).some(([field, value]) => {
                const actualValue = result.milestone?.[field];
                return (field === 'description' ? (actualValue ?? '') : actualValue)
                    !== (field === 'description' ? (value ?? '') : value);
            })) {
            throw new Error('The server did not confirm the requested phase update.');
        }

        phase.name = result.milestone.title;
        phase.description = result.milestone.description ?? '';
        phase.status = result.milestone.status;
        phase.progressPercentage = Number(result.milestone.progress_percentage) || 0;
        phase.completedAt = typeof result.milestone.completed_at === 'string'
            ? parseDate(result.milestone.completed_at.slice(0, 10))
            : null;
    }

    function renderDayCells(days, today) {
        return days.map((date) => `<span class="milestone-timeline__cell${isWeekend(date) ? ' is-weekend' : ''}${sameDay(date, today) ? ' is-today' : ''}" aria-hidden="true"></span>`).join('');
    }

    function datesBetween(start, end) {
        const dates = [];
        for (let date = start; date <= end; date = addDays(date, 1)) {
            dates.push(date);
        }

        return dates;
    }

    function addDays(date, days) {
        const result = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        result.setDate(result.getDate() + days);
        return result;
    }

    function parseDate(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }

        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
            ? date
            : null;
    }

    function startOfDay(date) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate());
    }

    function sameDay(left, right) {
        return left.getFullYear() === right.getFullYear()
            && left.getMonth() === right.getMonth()
            && left.getDate() === right.getDate();
    }

    function isWeekend(date) {
        return date.getDay() === 0 || date.getDay() === 6;
    }

    function dayIndex(days, target) {
        return days.findIndex((date) => sameDay(date, target));
    }

    function formatDate(date) {
        return new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        }).format(date);
    }

    function formatShortDate(date) {
        return new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
        }).format(date);
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

    function showError(element, message) {
        if (element) {
            element.textContent = message;
            element.hidden = false;
        }
    }
})();
