(() => {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, idx) => {
        row.style.animation = `fadeUp 0.22s ease ${idx * 0.015}s both`;
    });

    const style = document.createElement('style');
    style.textContent = `
      @keyframes fadeUp {
        from { opacity: 0; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
      }
    `;
    document.head.appendChild(style);

    const bottomNav = document.querySelector('.bottom-nav');
    const floatingLog = document.querySelector('.floating-log');
    if (bottomNav || floatingLog) {
        const syncMobileBottomNavHeight = () => {
            const navHeight = bottomNav instanceof HTMLElement
                ? Math.ceil(bottomNav.getBoundingClientRect().height)
                : 0;
            document.documentElement.style.setProperty('--mobile-bottom-nav-height', `${navHeight}px`);
        };
        syncMobileBottomNavHeight();
        window.addEventListener('resize', syncMobileBottomNavHeight, { passive: true });
        window.addEventListener('orientationchange', syncMobileBottomNavHeight, { passive: true });

        let lastY = window.scrollY;
        let lastToggleY = lastY;
        let navHidden = false;
        let ticking = false;
        const toggleNav = () => {
            // The mobile bottom navigation is the app's one global sticky surface.
            // It must never disappear halfway through a task; only the legacy desktop
            // floating action may collapse while scrolling.
            if (bottomNav instanceof HTMLElement) {
                bottomNav.classList.remove('nav-hidden', 'is-hidden');
            }
            const currentY = Math.max(0, window.scrollY);
            const goingDown = currentY > lastY + 6;
            const goingUp = currentY < lastY - 1;
            const shouldHide = !navHidden && goingDown && currentY > 120 && currentY - lastToggleY > 28;
            const shouldShow = navHidden && (currentY < 48 || goingUp);

            if (shouldHide || shouldShow) {
                if (shouldHide && floatingLog instanceof HTMLDetailsElement) {
                    floatingLog.open = false;
                    floatingLog.classList.remove('is-open');
                }
                [floatingLog].forEach((element) => {
                    if (!element) {
                        return;
                    }
                    element.classList.toggle('nav-hidden', shouldHide);
                    element.classList.toggle('is-hidden', shouldHide);
                });
                navHidden = shouldHide;
                lastToggleY = currentY;
            }
            lastY = currentY;
            ticking = false;
        };

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(toggleNav);
                ticking = true;
            }
        }, { passive: true });

        const syncMobileKeyboardState = () => {
            const viewport = window.visualViewport;
            const active = document.activeElement;
            const acceptsText = active instanceof HTMLInputElement
                || active instanceof HTMLTextAreaElement
                || active instanceof HTMLSelectElement
                || (active instanceof HTMLElement && active.isContentEditable);
            const occluded = viewport ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop) : 0;
            document.body.classList.toggle('mobile-keyboard-open', acceptsText && occluded > 140);
        };
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', syncMobileKeyboardState, { passive: true });
            window.visualViewport.addEventListener('scroll', syncMobileKeyboardState, { passive: true });
        }
        document.addEventListener('focusin', syncMobileKeyboardState);
        document.addEventListener('focusout', () => window.setTimeout(syncMobileKeyboardState, 0));
        syncMobileKeyboardState();
    }

    let pageLoadingCount = 0;
    const syncPageLoadingClass = () => {
        document.body.classList.toggle('is-transitioning', pageLoadingCount > 0);
    };
    let transitionCleanupTimer = null;
    const clearMobileViewTransitionState = (force = false) => {
        if (!force && pageLoadingCount > 0) {
            return;
        }
        document.body.classList.remove(
            'is-view-changing',
            'view-changing',
            'is-loading',
            'mobile-view-blur',
            'page-blur',
            'is-transitioning'
        );

        document.querySelectorAll('.is-blurred, .view-blur, .mobile-blur').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.classList.remove('is-blurred', 'view-blur', 'mobile-blur');
            }
        });

        document.querySelectorAll('.view-transition-overlay, .mobile-loading-overlay').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.remove();
            }
        });

        document.querySelectorAll('.is-loading').forEach((node) => {
            if (node instanceof HTMLElement && node !== document.body) {
                node.classList.remove('is-loading');
            }
        });
    };
    const queueMobileViewTransitionStateCleanup = (delayMs = 350, force = false) => {
        if (transitionCleanupTimer !== null) {
            window.clearTimeout(transitionCleanupTimer);
        }
        transitionCleanupTimer = window.setTimeout(() => {
            clearMobileViewTransitionState(force);
            transitionCleanupTimer = null;
        }, delayMs);
    };
    const beginPageLoading = () => {
        pageLoadingCount += 1;
        syncPageLoadingClass();
        queueMobileViewTransitionStateCleanup(350);
    };
    const endPageLoading = () => {
        pageLoadingCount = Math.max(0, pageLoadingCount - 1);
        syncPageLoadingClass();
        if (pageLoadingCount === 0) {
            clearMobileViewTransitionState(true);
        } else {
            queueMobileViewTransitionStateCleanup(350);
        }
    };

    window.addEventListener('pageshow', () => {
        clearMobileViewTransitionState(true);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => clearMobileViewTransitionState(true), { once: true });
    } else {
        clearMobileViewTransitionState(true);
    }
    queueMobileViewTransitionStateCleanup(350, true);

    const pjaxSafePages = new Set([
        'dashboard',
        'entries',
        'photo',
        'gallery',
        'notifications',
        'challenges',
        'settings',
        'profile',
        'achievements',
        'admin',
        'team_settings',
        'team',
        'metric',
        'comparison_detail',
        'strikes_detail',
        'penalties',
        'analytics',
        'table',
        'week_editor',
    ]);
    const isSafePjaxPageUrl = (url) => {
        if (!(url instanceof URL)) {
            return false;
        }
        if (url.origin !== window.location.origin || url.pathname !== '/') {
            return false;
        }
        const page = (url.searchParams.get('page') || 'dashboard').trim().toLowerCase();
        return pjaxSafePages.has(page);
    };

    const initFlashNotifications = () => {
        document.querySelectorAll('.flash').forEach((flash) => {
            if (!(flash instanceof HTMLElement) || flash.dataset.flashReady === '1') {
                return;
            }
            flash.dataset.flashReady = '1';
            flash.addEventListener('animationend', (event) => {
                if (event.animationName !== 'flashExitMobile' && event.animationName !== 'flashExitDesktop') {
                    return;
                }
                flash.hidden = true;
                flash.remove();
            });
        });
    };

    const initLiquidInteractions = () => {
        if (document.documentElement.dataset.liquidInteractionsReady === '1') {
            return;
        }
        document.documentElement.dataset.liquidInteractionsReady = '1';

        const pressableSelector = [
            '.btn',
            '.nav-links a',
            '.bottom-nav a',
            '.bottom-nav-plus > summary',
            '.liquid-nav-item',
            '.liquid-nav-plus > summary',
            '.photo-mode-segments a',
            '.calendar-view-segments a',
            '.analytics-period-segments a',
            '.entries-calendar-day',
            '.entries-calendar-mobile-tile',
            '.photos-gallery-tile',
            'button',
            'summary',
        ].join(',');

        const clearPressed = () => {
            document.querySelectorAll('.is-pressed').forEach((node) => node.classList.remove('is-pressed'));
        };

        document.addEventListener('pointerdown', (event) => {
            const target = event.target instanceof Element ? event.target.closest(pressableSelector) : null;
            if (target instanceof HTMLElement) {
                target.classList.add('is-pressed');
            }
        }, { passive: true });
        document.addEventListener('pointerup', clearPressed, { passive: true });
        document.addEventListener('pointercancel', clearPressed, { passive: true });
        document.addEventListener('blur', clearPressed, true);

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (form instanceof HTMLFormElement && !form.hasAttribute('data-no-transition')) {
                document.body.classList.add('is-transitioning');
                queueMobileViewTransitionStateCleanup(350);
            }
        }, true);

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!(target instanceof HTMLAnchorElement)) {
                return;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || target.target === '_blank' || target.hasAttribute('download')) {
                return;
            }
            const href = target.getAttribute('href') || '';
            if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) {
                return;
            }
            const nextUrl = new URL(target.href, window.location.origin);
            if (nextUrl.origin !== window.location.origin) {
                return;
            }
            if (target.closest('[data-spa-link], [data-spa-back], [data-analytics-filter], [data-dashboard-control-form], [data-calendar-view-option], [data-no-pjax]')) {
                return;
            }
            if (isSafePjaxPageUrl(nextUrl)) {
                return;
            }
            document.body.classList.add('is-transitioning');
            window.setTimeout(() => {
                document.body.classList.remove('is-transitioning');
                clearMobileViewTransitionState(true);
            }, 240);
            queueMobileViewTransitionStateCleanup(350, true);
        }, true);
    };

    // The entry form used to be wired up once, at script load. After an in-page
    // (pjax) navigation to ?page=entries the form is a brand new DOM node, so none
    // of these listeners existed - which is why "Add workout" did nothing when you
    // reached the page through the app instead of a hard reload. It is an init
    // function now, run on every page hydration.
    const initEntryForm = () => {
        const entryForm = document.querySelector('[data-testid="entry-form"]');
        if (!entryForm || entryForm.dataset.entryFormReady === '1') {
            return;
        }
        entryForm.dataset.entryFormReady = '1';
        const stepsInput = entryForm.querySelector('[name="steps"]');
        const kmInput = entryForm.querySelector('[name="distance_km"]');
        const missingReason = entryForm.querySelector('[data-reason="missing"]');
        const missingReasonLabel = entryForm.querySelector('[data-missing-reason-label]');
        const missingReasonItems = entryForm.querySelector('[data-missing-reason-items]');
        const workoutRows = entryForm.querySelector('[data-workout-rows]');
        const workoutTemplate = entryForm.querySelector('template[data-workout-template]');
        const workoutAddButton = entryForm.querySelector('[data-workout-add]');
        const workoutEnabled = entryForm.querySelector('[data-workout-enabled]');
        const workoutPanel = entryForm.querySelector('[data-workout-panel]');
        const labels = {
            steps: String(entryForm.dataset.labelSteps || 'Steps'),
            km: String(entryForm.dataset.labelKm || 'Distance'),
            workouts: String(entryForm.dataset.labelWorkouts || 'Workouts'),
        };
        const missingLabel = String(entryForm.dataset.missingLabel || 'Valid reason');
        const missingPrefix = String(entryForm.dataset.missingPrefix || 'Missing');
        let primaryGoals = [];
        try {
            const parsedGoals = JSON.parse(entryForm.dataset.primaryGoals || '[]');
            if (Array.isArray(parsedGoals)) {
                primaryGoals = parsedGoals;
            }
        } catch {
            primaryGoals = [];
        }
        let workoutFieldsByType = {};
        try {
            const parsedFields = JSON.parse(entryForm.dataset.workoutFields || '{}');
            if (parsedFields && typeof parsedFields === 'object') {
                workoutFieldsByType = parsedFields;
            }
        } catch {
            workoutFieldsByType = {};
        }

        const isWorkoutEnabled = () => !(workoutEnabled instanceof HTMLInputElement) || workoutEnabled.checked;

        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const getWorkoutRows = () => {
            if (!(workoutRows instanceof HTMLElement)) {
                return [];
            }
            return [...workoutRows.querySelectorAll('[data-workout-row]')];
        };

        const syncWorkoutSubfieldsState = (row, container, hasFields = null) => {
            if (!(row instanceof HTMLElement) || !(container instanceof HTMLElement)) {
                return;
            }
            const visible = hasFields === null ? container.children.length > 0 : Boolean(hasFields);
            container.hidden = !visible;
            row.classList.toggle('has-workout-data', visible);
        };

        const updateWorkoutIndexes = () => {
            getWorkoutRows().forEach((row, index) => {
                row.querySelectorAll('[data-name-template]').forEach((input) => {
                    if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement || input instanceof HTMLTextAreaElement)) {
                        return;
                    }
                    input.name = String(input.dataset.nameTemplate || '').replaceAll('__INDEX__', String(index));
                });
            });
        };

        const buildWorkoutSubfields = (row, force = false) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const select = row.querySelector('[data-workout-select]');
            const container = row.querySelector('[data-workout-subfields]');
            if (!(select instanceof HTMLSelectElement) || !(container instanceof HTMLElement)) {
                return;
            }
            if (!force && container.children.length > 0) {
                syncWorkoutSubfieldsState(row, container, true);
                return;
            }
            const typeId = String(select.value || '').trim();
            const fields = Array.isArray(workoutFieldsByType[typeId]) ? workoutFieldsByType[typeId] : [];
            container.innerHTML = fields.map((field) => {
                const fieldId = Number(field.id || 0);
                if (!(fieldId > 0)) {
                    return '';
                }
                const inputKind = String(field.input_kind || 'number') === 'text' ? 'text' : 'number';
                const required = field.required ? ' required' : '';
                const numericAttrs = inputKind === 'number' ? ' step="0.01" min="0"' : '';
                const dataKey = escapeHtml(field.data_key || '');
                return `
                    <label>
                        ${escapeHtml(field.label || '')}
                        <input type="${inputKind}"${numericAttrs}${required} data-name-template="workouts[__INDEX__][fields][${fieldId}]" data-workout-field-data-key="${dataKey}">
                    </label>
                `;
            }).join('');
            syncWorkoutSubfieldsState(row, container, fields.length > 0);
        };

        const sumWorkoutFieldValues = (dataKey) => {
            if (!isWorkoutEnabled()) {
                return 0;
            }
            return getWorkoutRows().reduce((total, row) => {
                if (!(row instanceof HTMLElement)) {
                    return total;
                }
                row.querySelectorAll(`[data-workout-field-data-key="${dataKey}"]`).forEach((input) => {
                    if (input instanceof HTMLInputElement) {
                        const value = Number(input.value || 0);
                        if (Number.isFinite(value) && value > 0) {
                            total += value;
                        }
                    }
                });
                return total;
            }, 0);
        };

        const updateWorkoutPanelState = () => {
            const enabled = isWorkoutEnabled();
            if (workoutPanel instanceof HTMLElement) {
                workoutPanel.hidden = !enabled;
                workoutPanel.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    if (control === workoutAddButton) {
                        return;
                    }
                    if (control instanceof HTMLButtonElement) {
                        if (!enabled) {
                            control.disabled = true;
                        }
                        return;
                    }
                    if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
                        control.disabled = !enabled;
                    }
                });
            }
            if (workoutAddButton instanceof HTMLButtonElement) {
                workoutAddButton.hidden = false;
                workoutAddButton.disabled = false;
            }
        };

        const getWorkoutValueFromRow = (row) => {
            if (!isWorkoutEnabled()) {
                return 0;
            }
            if (!(row instanceof HTMLElement)) {
                return 0;
            }
            const select = row.querySelector('[data-workout-select]');
            const customInput = row.querySelector('[data-workout-custom-input]');
            const selectedValue = select instanceof HTMLSelectElement ? String(select.value || '').trim() : '';
            const customValue = customInput instanceof HTMLInputElement ? String(customInput.value || '').trim() : '';
            if (selectedValue === '__custom__') {
                return customValue !== '' ? 1 : 0;
            }

            return selectedValue !== '' ? 1 : 0;
        };

        const updateWorkoutRowVisibility = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const select = row.querySelector('[data-workout-select]');
            const customField = row.querySelector('[data-workout-custom]');
            const customInput = row.querySelector('[data-workout-custom-input]');
            const isCustom = select instanceof HTMLSelectElement && String(select.value || '').trim() === '__custom__';
            if (customField instanceof HTMLElement) {
                customField.hidden = !isCustom;
            }
            if (select instanceof HTMLSelectElement) {
                select.required = isWorkoutEnabled();
            }
            if (customInput instanceof HTMLInputElement) {
                customInput.required = isWorkoutEnabled() && isCustom;
                if (!isCustom) {
                    customInput.value = '';
                }
            }
            buildWorkoutSubfields(row);
            updateWorkoutIndexes();
        };

        const focusWorkoutRow = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const select = row.querySelector('[data-workout-select]');
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            // A new account only has "None" and "Other". Sending the user to a
            // two-option select in that state adds a pointless step and made the
            // workout flow look broken. Open the custom field immediately; once a
            // type or routine exists, keep the regular picker behaviour.
            const hasPresetChoice = Array.from(select.options).some((option) => {
                const value = String(option.value || '').trim();
                return value !== '' && value !== '__custom__';
            });
            const customOption = Array.from(select.options).find((option) => option.value === '__custom__');
            if (!hasPresetChoice && customOption) {
                select.value = '__custom__';
                updateWorkoutRowVisibility(row);
                const customInput = row.querySelector('[data-workout-custom-input]');
                if (customInput instanceof HTMLInputElement) {
                    customInput.focus();
                    return;
                }
            }
            select.focus();
        };

        const ensureOneWorkoutRow = () => {
            if (!(workoutRows instanceof HTMLElement) || !(workoutTemplate instanceof HTMLTemplateElement)) {
                return;
            }
            if (getWorkoutRows().length > 0) {
                return;
            }
            const fragment = workoutTemplate.content.cloneNode(true);
            workoutRows.appendChild(fragment);
        };

        const updateWorkoutRemoveButtons = () => {
            const rows = getWorkoutRows();
            rows.forEach((row) => {
                const removeButton = row.querySelector('[data-workout-remove]');
                if (!(removeButton instanceof HTMLButtonElement)) {
                    return;
                }
                const disable = rows.length <= 1 || !isWorkoutEnabled();
                removeButton.disabled = disable;
                removeButton.setAttribute('aria-disabled', disable ? 'true' : 'false');
            });
        };

        const evaluateFailures = () => {
            const goalType = entryForm.dataset.primaryGoalType || 'steps';
            const stepGoal = Number(entryForm.dataset.stepGoal || 0);
            const kmGoal = Number(entryForm.dataset.kmGoal || 0);
            const stepsValue = Number(stepsInput?.value || 0) + sumWorkoutFieldValues('steps');
            const kmValue = Number(kmInput?.value || 0) + sumWorkoutFieldValues('distance_km');
            const workoutValue = getWorkoutRows().some((row) => getWorkoutValueFromRow(row) === 1) ? 1 : 0;
            let missingSteps = goalType === 'km' && kmGoal > 0 ? kmValue < kmGoal : stepsValue < stepGoal;
            let missingWorkout = goalType === 'workouts' ? workoutValue < Math.max(1, Number(entryForm.dataset.primaryGoalValue || 1)) : false;
            const missingItems = new Set();
            if (missingSteps) {
                missingItems.add(goalType === 'km' ? labels.km : labels.steps);
            }
            if (missingWorkout) {
                missingItems.add(labels.workouts);
            }

            if (primaryGoals.length > 0) {
                missingSteps = false;
                missingWorkout = false;
                missingItems.clear();
                primaryGoals.forEach((goal) => {
                    if (!goal || typeof goal !== 'object') {
                        return;
                    }
                    const type = String(goal.type || '').toLowerCase().trim();
                    const target = Number(goal.value || 0);
                    if (!type || !(target > 0)) {
                        return;
                    }

                    if (type === 'steps' && stepsValue < target) {
                        missingSteps = true;
                        missingItems.add(labels.steps);
                    } else if (type === 'km' && kmValue < target) {
                        missingSteps = true;
                        missingItems.add(labels.km);
                    } else if (type === 'workouts' && workoutValue < target) {
                        missingWorkout = true;
                        missingItems.add(labels.workouts);
                    }
                });
            }

            return {
                missingSteps,
                missingWorkout,
                items: [...missingItems],
            };
        };

        const updateReasons = () => {
            const result = evaluateFailures();
            const isMissingAny = result.missingSteps || result.missingWorkout;
            if (missingReason instanceof HTMLElement) {
                missingReason.hidden = !isMissingAny;
            }
            if (missingReasonLabel instanceof HTMLElement) {
                missingReasonLabel.textContent = missingLabel;
            }
            if (missingReasonItems instanceof HTMLElement) {
                missingReasonItems.textContent = isMissingAny && result.items.length > 0
                    ? `${missingPrefix}: ${result.items.join(' + ')}`
                    : '';
            }
        };

        if (workoutRows instanceof HTMLElement) {
            workoutRows.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }
                const row = target.closest('[data-workout-row]');
                if (row instanceof HTMLElement) {
                    updateWorkoutRowVisibility(row);
                    if (target.matches('[data-workout-select]')) {
                        buildWorkoutSubfields(row, true);
                    }
                }
                updateWorkoutRemoveButtons();
                updateWorkoutIndexes();
                updateReasons();
            });
            workoutRows.addEventListener('input', () => {
                updateReasons();
            });
            workoutRows.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }
                const removeButton = target.closest('[data-workout-remove]');
                if (!(removeButton instanceof HTMLButtonElement)) {
                    return;
                }
                const row = removeButton.closest('[data-workout-row]');
                if (!(row instanceof HTMLElement)) {
                    return;
                }
                const rows = getWorkoutRows();
                if (rows.length <= 1) {
                    return;
                }
                row.remove();
                ensureOneWorkoutRow();
                getWorkoutRows().forEach((workoutRow) => updateWorkoutRowVisibility(workoutRow));
                updateWorkoutRemoveButtons();
                updateWorkoutIndexes();
                updateReasons();
            });
        }

        workoutAddButton?.addEventListener('click', () => {
            const wasEnabled = isWorkoutEnabled();
            if (workoutEnabled instanceof HTMLInputElement && !workoutEnabled.checked) {
                workoutEnabled.checked = true;
                updateWorkoutPanelState();
            }
            if (!(workoutRows instanceof HTMLElement) || !(workoutTemplate instanceof HTMLTemplateElement)) {
                return;
            }
            ensureOneWorkoutRow();
            let rows = getWorkoutRows();

            // The disabled form already keeps one template row ready. The first
            // tap should reveal and use it, not create a second empty workout.
            if (wasEnabled) {
                const fragment = workoutTemplate.content.cloneNode(true);
                workoutRows.appendChild(fragment);
                rows = getWorkoutRows();
            }
            const rowToFocus = rows[rows.length - 1];
            if (rowToFocus instanceof HTMLElement) {
                updateWorkoutRowVisibility(rowToFocus);
                focusWorkoutRow(rowToFocus);
            }
            updateWorkoutRemoveButtons();
            updateWorkoutIndexes();
            updateReasons();
        });

        workoutEnabled?.addEventListener('change', () => {
            ensureOneWorkoutRow();
            getWorkoutRows().forEach((row) => updateWorkoutRowVisibility(row));
            updateWorkoutIndexes();
            updateWorkoutPanelState();
            updateWorkoutRemoveButtons();
            updateReasons();
        });

        [stepsInput, kmInput].forEach((input) => {
            input?.addEventListener('input', updateReasons);
            input?.addEventListener('change', updateReasons);
        });
        ensureOneWorkoutRow();
        getWorkoutRows().forEach((row) => updateWorkoutRowVisibility(row));
        updateWorkoutRemoveButtons();
        updateWorkoutIndexes();
        updateWorkoutPanelState();
        updateReasons();
    };

    const proofPhotoForm = document.querySelector('[data-proof-photo-form]');
    if (proofPhotoForm) {
        const fileInput = proofPhotoForm.querySelector('[data-proof-photo-input]');
        const previewContainer = proofPhotoForm.querySelector('[data-proof-photo-preview]');
        const uploadState = proofPhotoForm.querySelector('[data-proof-photo-state]');
        const nutritionToggle = proofPhotoForm.querySelector('[data-photo-nutrition-toggle]');
        const nutritionPanel = proofPhotoForm.querySelector('[data-photo-nutrition-panel]');
        const nutritionAdvancedToggle = proofPhotoForm.querySelector('[data-photo-nutrition-advanced-toggle]');
        const nutritionAdvanced = proofPhotoForm.querySelector('[data-photo-nutrition-advanced]');
        let activeObjectUrl = null;

        const placeholderTitle = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.placeholderTitle || 'Select a photo to preview')
            : 'Select a photo to preview';
        const placeholderHint = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.placeholderHint || 'The image will be saved as proof')
            : 'The image will be saved as proof';
        const unsupportedTitle = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.previewUnsupportedTitle || 'Preview not available')
            : 'Preview not available';
        const unsupportedHint = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.previewUnsupportedHint || 'This format can be uploaded, but your browser cannot preview it.')
            : 'This format can be uploaded, but your browser cannot preview it.';
        const previewAlt = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.previewAlt || 'Photo preview')
            : 'Photo preview';
        const stateIdle = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.stateIdle || 'No photo selected.')
            : 'No photo selected.';
        const stateSelected = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.stateSelected || 'Photo selected.')
            : 'Photo selected.';
        const stateUnsupported = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.stateUnsupported || 'Selected file cannot be previewed in this browser.')
            : 'Selected file cannot be previewed in this browser.';
        const stateError = previewContainer instanceof HTMLElement
            ? (previewContainer.dataset.stateError || 'Unable to preview this file.')
            : 'Unable to preview this file.';
        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        const setUploadState = (message) => {
            if (!(uploadState instanceof HTMLElement)) {
                return;
            }
            uploadState.textContent = String(message || '').trim();
        };

        const hasFilledValues = (root) => {
            if (!(root instanceof HTMLElement)) {
                return false;
            }
            const controls = root.querySelectorAll('input, textarea, select');
            return [...controls].some((control) => {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)) {
                    return false;
                }
                return String(control.value || '').trim() !== '';
            });
        };

        const setPanelState = (expanded) => {
            if (!(nutritionPanel instanceof HTMLElement)) {
                return;
            }
            nutritionPanel.hidden = !expanded;
            if (nutritionToggle instanceof HTMLButtonElement) {
                nutritionToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        };

        const setAdvancedState = (expanded) => {
            if (!(nutritionAdvanced instanceof HTMLElement)) {
                return;
            }
            nutritionAdvanced.hidden = !expanded;
            if (nutritionAdvancedToggle instanceof HTMLButtonElement) {
                nutritionAdvancedToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        };

        const renderPlaceholder = () => {
            if (!(previewContainer instanceof HTMLElement)) {
                return;
            }
            previewContainer.innerHTML = `
                <div class="photo-placeholder">
                    <div class="photo-placeholder-content">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v8.59l3.3-3.3a1 1 0 0 1 1.4 0L14 15.6l2.3-2.3a1 1 0 0 1 1.4 0L19 14.6V6Zm3 1.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Z"/></svg>
                        <p>${escapeHtml(placeholderTitle)}</p>
                        <small>${escapeHtml(placeholderHint)}</small>
                    </div>
                </div>
            `;
            setUploadState(stateIdle);
        };

        const renderUnsupportedPreview = () => {
            if (!(previewContainer instanceof HTMLElement)) {
                return;
            }
            previewContainer.innerHTML = `
                <div class="photo-placeholder photo-placeholder-unsupported">
                    <div class="photo-placeholder-content">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm7 4a1 1 0 0 1 1 1v4.2a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Zm0 8.6a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4Z"/></svg>
                        <p>${escapeHtml(unsupportedTitle)}</p>
                        <small>${escapeHtml(unsupportedHint)}</small>
                    </div>
                </div>
            `;
            setUploadState(stateUnsupported);
        };

        const isHeicLikeFile = (file) => {
            if (!(file instanceof File)) {
                return false;
            }
            const normalizedType = String(file.type || '').toLowerCase();
            if (['image/heic', 'image/heif', 'image/x-heic', 'image/x-heif', 'image/heic-sequence', 'image/heif-sequence'].includes(normalizedType)) {
                return true;
            }
            const normalizedName = String(file.name || '').toLowerCase();
            return normalizedName.endsWith('.heic') || normalizedName.endsWith('.heif');
        };

        const renderPreview = () => {
            if (!(fileInput instanceof HTMLInputElement) || !(previewContainer instanceof HTMLElement)) {
                return;
            }
            if (activeObjectUrl) {
                URL.revokeObjectURL(activeObjectUrl);
                activeObjectUrl = null;
            }
            const selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            if (!selectedFile) {
                renderPlaceholder();
                return;
            }
            activeObjectUrl = URL.createObjectURL(selectedFile);
            previewContainer.innerHTML = '';
            const previewImage = document.createElement('img');
            previewImage.src = activeObjectUrl;
            previewImage.alt = previewAlt;
            setUploadState(`${stateSelected} ${selectedFile.name || ''}`.trim());
            const heicLike = isHeicLikeFile(selectedFile);
            previewImage.addEventListener('error', () => {
                if (heicLike) {
                    renderUnsupportedPreview();
                    return;
                }
                renderPlaceholder();
                setUploadState(stateError);
            });
            previewContainer.appendChild(previewImage);
        };

        nutritionToggle?.addEventListener('click', () => {
            const current = nutritionPanel instanceof HTMLElement ? !nutritionPanel.hidden : false;
            setPanelState(!current);
        });
        nutritionAdvancedToggle?.addEventListener('click', () => {
            const current = nutritionAdvanced instanceof HTMLElement ? !nutritionAdvanced.hidden : false;
            setAdvancedState(!current);
        });

        setPanelState(hasFilledValues(nutritionPanel));
        setAdvancedState(hasFilledValues(nutritionAdvanced));
        fileInput?.addEventListener('change', renderPreview);
        renderPreview();
        window.addEventListener('beforeunload', () => {
            if (activeObjectUrl) {
                URL.revokeObjectURL(activeObjectUrl);
            }
        });
    }

    const initPhotoDeleteModal = () => {
        const modal = document.querySelector('[data-photo-delete-modal]');
        const triggers = document.querySelectorAll('[data-photo-delete-trigger]');
        if (!(modal instanceof HTMLElement) || triggers.length === 0) {
            return;
        }

        const confirmButton = modal.querySelector('[data-photo-delete-confirm]');
        const cancelButtons = modal.querySelectorAll('[data-photo-delete-cancel]');
        const titleNode = modal.querySelector('#photo-delete-title');
        let pendingForm = null;
        let defaultTitle = titleNode instanceof HTMLElement ? titleNode.textContent || '' : '';

        const closeModal = () => {
            pendingForm = null;
            if (titleNode instanceof HTMLElement) {
                titleNode.textContent = defaultTitle;
            }
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
        };

        const openModal = (message) => {
            if (titleNode instanceof HTMLElement && message) {
                titleNode.textContent = message;
            }
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            if (confirmButton instanceof HTMLElement) {
                confirmButton.focus();
            }
        };

        triggers.forEach((trigger) => {
            if (!(trigger instanceof HTMLButtonElement)) {
                return;
            }
            trigger.addEventListener('click', () => {
                const formId = String(trigger.dataset.photoDeleteForm || '').trim();
                if (!formId) {
                    return;
                }
                const form = document.getElementById(formId);
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }
                pendingForm = form;
                const message = String(trigger.dataset.photoDeleteMessage || '').trim();
                openModal(message);
            });
        });

        if (confirmButton instanceof HTMLButtonElement) {
            confirmButton.addEventListener('click', () => {
                if (pendingForm instanceof HTMLFormElement) {
                    pendingForm.submit();
                }
                closeModal();
            });
        }

        cancelButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        window.addEventListener('keydown', (event) => {
            if (!modal.hidden && event.key === 'Escape') {
                closeModal();
            }
        });
    };

    const initPhotoEditModal = () => {
        const modal = document.querySelector('[data-photo-edit-modal]');
        const triggers = document.querySelectorAll('[data-photo-edit-open]');
        if (!(modal instanceof HTMLElement) || triggers.length === 0) {
            return;
        }

        const closeButtons = modal.querySelectorAll('[data-photo-edit-close]');
        const focusTarget = modal.querySelector('input[name="log_date"]');
        let opener = null;

        const closeModal = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
            if (opener instanceof HTMLElement) {
                opener.focus();
            }
            opener = null;
        };

        const openModal = (trigger) => {
            opener = trigger;
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            if (focusTarget instanceof HTMLElement) {
                window.setTimeout(() => focusTarget.focus(), 0);
            }
        };

        triggers.forEach((trigger) => {
            if (!(trigger instanceof HTMLButtonElement)) {
                return;
            }
            trigger.addEventListener('click', () => {
                openModal(trigger);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        window.addEventListener('keydown', (event) => {
            if (!modal.hidden && event.key === 'Escape') {
                closeModal();
            }
        });
    };

    const initStrikeReviewModal = () => {
        const modal = document.querySelector('[data-strike-review-modal]');
        const triggers = document.querySelectorAll('[data-strike-review-open]');
        if (!(modal instanceof HTMLElement) || triggers.length === 0) {
            return;
        }

        const form = modal.querySelector('[data-strike-review-form]');
        const targetUserInput = modal.querySelector('[data-strike-review-target-user]');
        const weekStartInput = modal.querySelector('[data-strike-review-week-start]');
        const eventDateInput = modal.querySelector('[data-strike-review-event-date]');
        const reasonInput = modal.querySelector('[data-strike-review-reason]');
        const commentInput = modal.querySelector('[data-strike-review-comment]');
        const cancelButtons = modal.querySelectorAll('[data-strike-review-cancel]');
        let opener = null;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const closeModal = () => {
            if (weekStartInput instanceof HTMLInputElement) {
                weekStartInput.value = '';
            }
            if (eventDateInput instanceof HTMLInputElement) {
                eventDateInput.value = '';
            }
            if (reasonInput instanceof HTMLInputElement) {
                reasonInput.value = '';
            }
            if (commentInput instanceof HTMLTextAreaElement) {
                commentInput.value = '';
            }
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
            if (opener instanceof HTMLElement) {
                opener.focus();
            }
            opener = null;
        };

        const openModal = (trigger) => {
            if (!(trigger instanceof HTMLElement)) {
                return;
            }
            if (targetUserInput instanceof HTMLInputElement) {
                targetUserInput.value = String(trigger.dataset.targetUserId || targetUserInput.value || '').trim();
            }
            if (weekStartInput instanceof HTMLInputElement) {
                weekStartInput.value = String(trigger.dataset.weekStart || '').trim();
            }
            if (eventDateInput instanceof HTMLInputElement) {
                eventDateInput.value = String(trigger.dataset.eventDate || '').trim();
            }
            if (reasonInput instanceof HTMLInputElement) {
                reasonInput.value = String(trigger.dataset.reason || '').trim();
            }
            if (commentInput instanceof HTMLTextAreaElement) {
                commentInput.value = '';
            }
            opener = trigger;
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            if (commentInput instanceof HTMLTextAreaElement) {
                commentInput.focus();
            }
        };

        triggers.forEach((trigger) => {
            if (!(trigger instanceof HTMLButtonElement)) {
                return;
            }
            trigger.addEventListener('click', () => {
                openModal(trigger);
            });
        });

        cancelButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        window.addEventListener('keydown', (event) => {
            if (!modal.hidden && event.key === 'Escape') {
                closeModal();
            }
        });
    };

    const lightbox = document.getElementById('mealLightbox');
    if (lightbox) {
        let photos = [];
        let index = 0;
        const image = lightbox.querySelector('[data-lightbox-image]');
        const caption = lightbox.querySelector('[data-lightbox-caption]');
        const render = () => {
            const current = photos[index] || {};
            if (image) {
                if (current.src) {
                    image.src = current.src;
                } else {
                    image.removeAttribute('src');
                }
            }
            if (caption) {
                caption.textContent = current.caption || '';
            }
        };
        document.querySelectorAll('.meal-thumb').forEach((button) => {
            button.addEventListener('click', () => {
                try {
                    photos = JSON.parse(button.dataset.photos || '[]');
                } catch {
                    photos = [];
                }
                if (photos.length === 0) {
                    return;
                }
                index = 0;
                render();
                if (typeof lightbox.showModal === 'function') {
                    lightbox.showModal();
                } else {
                    lightbox.setAttribute('open', 'open');
                }
            });
        });
        lightbox.querySelector('[data-lightbox-close]')?.addEventListener('click', () => lightbox.close());
        lightbox.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => {
            index = (index - 1 + photos.length) % photos.length;
            render();
        });
        lightbox.querySelector('[data-lightbox-next]')?.addEventListener('click', () => {
            index = (index + 1) % photos.length;
            render();
        });
    }

    document.querySelectorAll('.js-toggle-achievements').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = button.closest('.panel');
            const grid = panel?.querySelector('[data-achievement-grid]');
            if (!grid) {
                return;
            }
            const expanded = grid.classList.toggle('expanded');
            button.textContent = expanded ? (button.dataset.collapseLabel || 'View less') : (button.dataset.expandLabel || 'View all');
        });
    });

    const floatingMenu = floatingLog instanceof HTMLDetailsElement ? floatingLog : null;
    if (floatingMenu) {
        const syncFloatingMenuState = () => {
            floatingMenu.classList.toggle('is-open', floatingMenu.open);
        };
        const closeFloatingMenu = () => {
            floatingMenu.open = false;
            syncFloatingMenuState();
        };

        floatingMenu.addEventListener('toggle', syncFloatingMenuState);
        syncFloatingMenuState();

        window.addEventListener('click', (event) => {
            if (!(event.target instanceof Node)) {
                return;
            }
            if (!floatingMenu.contains(event.target)) {
                closeFloatingMenu();
            }
        });

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && floatingMenu.open) {
                closeFloatingMenu();
            }
        });
    }

    const initLoginLocale = () => {
        const form = document.querySelector('[data-locale-selector="login"][data-locale-async="1"]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const select = form.querySelector('select[name="locale"]');
        const dictionaryNode = document.getElementById('login-i18n-dictionary');
        if (!(select instanceof HTMLSelectElement) || !(dictionaryNode instanceof HTMLScriptElement)) {
            return;
        }

        let dictionary = {};
        try {
            dictionary = JSON.parse(dictionaryNode.textContent || '{}');
        } catch {
            dictionary = {};
        }

        const supported = Object.keys(dictionary).length > 0 ? Object.keys(dictionary) : ['en'];
        const fallback = supported.includes('en') ? 'en' : supported[0];
        const storageKey = 'fc.locale';
        const readStoredLocale = () => {
            try {
                return localStorage.getItem(storageKey) || '';
            } catch {
                return '';
            }
        };
        const persistLocale = (locale) => {
            try {
                localStorage.setItem(storageKey, locale);
            } catch {
                // Ignore storage failures.
            }
        };

        const normalizeLocale = (value) => {
            const lower = String(value || '').trim().toLowerCase();
            if (supported.includes(lower)) {
                return lower;
            }
            const short = lower.split('-')[0];
            return supported.includes(short) ? short : fallback;
        };

        const setLocaleOnBackend = async (locale) => {
            const body = new URLSearchParams();
            body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
            body.set('redirect_to', form.querySelector('input[name="redirect_to"]')?.value || window.location.pathname + window.location.search);
            body.set('locale', locale);
            body.set('async', '1');
            try {
                await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: body.toString(),
                });
            } catch {
                // Keep frontend locale even if sync fails.
            } finally {
                queueMobileViewTransitionStateCleanup(350);
            }
        };

        const applyLocale = (locale, { persist = true, sync = true } = {}) => {
            const normalized = normalizeLocale(locale);
            const bundle = dictionary[normalized] || dictionary[fallback] || {};
            Object.entries(bundle).forEach(([key, text]) => {
                document.querySelectorAll(`[data-i18n="${key}"]`).forEach((node) => {
                    node.textContent = String(text);
                });
            });
            document.documentElement.lang = normalized;
            select.value = normalized;
            if (persist) {
                persistLocale(normalized);
            }
            if (sync) {
                setLocaleOnBackend(normalized);
            }
        };

        const detectBrowserLocale = () => {
            const candidates = Array.isArray(navigator.languages) && navigator.languages.length > 0
                ? navigator.languages
                : [navigator.language || fallback];
            for (const candidate of candidates) {
                const normalized = normalizeLocale(candidate);
                if (supported.includes(normalized)) {
                    return normalized;
                }
            }
            return fallback;
        };

        const current = normalizeLocale(select.value);
        const stored = normalizeLocale(readStoredLocale());
        const initial = stored || detectBrowserLocale() || fallback;

        applyLocale(initial, { persist: true, sync: initial !== current });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
        });

        select.addEventListener('change', () => {
            applyLocale(select.value, { persist: true, sync: true });
        });
    };

    const initSpaNavigation = () => {
        const roots = document.querySelectorAll('[data-spa-page]');
        if (roots.length === 0) {
            return;
        }

        const applyState = (root, urlValue) => {
            const url = new URL(urlValue, window.location.origin);
            const params = url.searchParams;
            const activeSection = params.get('section') || '';
            const main = root.querySelector('[data-spa-main]');
            if (main) {
                main.hidden = activeSection !== '';
                main.classList.toggle('hidden', activeSection !== '');
            }
            root.querySelectorAll('[data-spa-home-extra]').forEach((node) => {
                node.hidden = activeSection !== '';
                node.classList.toggle('hidden', activeSection !== '');
            });
            root.querySelectorAll('[data-spa-section]').forEach((panel) => {
                const isActive = panel.getAttribute('data-spa-section') === activeSection;
                panel.hidden = !isActive;
                panel.classList.toggle('active', isActive);
            });
            root.querySelectorAll('details[data-spa-param][data-spa-value]').forEach((detail) => {
                const param = detail.getAttribute('data-spa-param');
                const value = detail.getAttribute('data-spa-value');
                detail.open = param !== null && value !== null && params.get(param) === value;
            });
            root.querySelectorAll('[data-spa-param-show][data-spa-value]').forEach((node) => {
                const param = node.getAttribute('data-spa-param-show');
                const value = node.getAttribute('data-spa-value');
                node.hidden = !(param !== null && value !== null && params.get(param) === value);
            });
            root.querySelectorAll('[data-spa-show-when-no-param]').forEach((node) => {
                const raw = node.getAttribute('data-spa-show-when-no-param') || '';
                const paramsToWatch = raw.split(',').map((value) => value.trim()).filter(Boolean);
                const shouldHide = paramsToWatch.some((param) => params.get(param));
                node.hidden = shouldHide;
            });
            if (root.getAttribute('data-spa-page') === 'profile') {
                const hasGoalId = (params.get('goal_id') || '').trim() !== '';
                const createGoalMode = params.get('goal_new') === '1';
                if (hasGoalId) {
                    root.querySelectorAll('[data-spa-param-show="goal_new"]').forEach((node) => {
                        node.hidden = true;
                    });
                }
                if (createGoalMode) {
                    root.querySelectorAll('[data-spa-param-show="goal_id"]').forEach((node) => {
                        node.hidden = true;
                    });
                }
                // The goal editor lives in a modal now, and the modal owns its own
                // visibility. Force-hiding the form here left its Save button collapsed
                // to zero height inside an open dialog.
                root.querySelectorAll('[data-goal-edit-form]').forEach((form) => {
                    const dialog = form.closest('.app-modal');
                    if (!dialog) {
                        form.hidden = true;
                    }
                });
            }
        };

        // Section links used to reveal pre-rendered panels in place. That left
        // root-only UI (the Profile hub, hero and widget heading) mounted above
        // the selected section, so a tap appeared to merely make the page taller.
        // The shared PJAX router now owns these links and replaces <main> with the
        // server-rendered URL state. This initializer only normalizes the initial
        // document and remains a safe no-JS/full-load fallback.
        if (typeof window.__fcSpaPopstateHandler === 'function') {
            window.removeEventListener('popstate', window.__fcSpaPopstateHandler);
        }
        window.__fcSpaPopstateHandler = null;
        roots.forEach((root) => {
            if (!(root instanceof HTMLElement)) {
                return;
            }
            root.dataset.spaNavigationReady = '1';
            applyState(root, window.location.href);
        });
    };

    const initProfileGoalsSection = () => {
        const profileRoot = document.querySelector('[data-spa-page="profile"]');
        if (!profileRoot) {
            return;
        }

        if (document.documentElement.dataset.profileGoalsDelegated === '1') {
            return;
        }
        document.documentElement.dataset.profileGoalsDelegated = '1';

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const editButton = target.closest('[data-goal-edit-toggle]');
            if (editButton instanceof HTMLButtonElement) {
                const targetId = editButton.dataset.target || '';
                const form = targetId !== '' ? document.getElementById(targetId) : null;
                if (form instanceof HTMLElement) {
                    form.hidden = false;
                }
                return;
            }

            const cancelButton = target.closest('[data-goal-edit-cancel]');
            if (cancelButton instanceof HTMLButtonElement) {
                const targetId = cancelButton.dataset.target || '';
                const form = targetId !== '' ? document.getElementById(targetId) : null;
                if (form instanceof HTMLElement) {
                    form.hidden = true;
                }
                return;
            }

            const deleteButton = target.closest('[data-goal-delete-confirm]');
            if (deleteButton instanceof HTMLButtonElement) {
                const confirmed = window.confirm('Delete this goal?');
                if (!confirmed) {
                    event.preventDefault();
                }
            }
        });
    };

    const initAchievementInfoModal = () => {
        const cards = document.querySelectorAll('[data-achievement-modal]');
        if (cards.length === 0) {
            return;
        }

        const ensureModal = () => {
            const existing = document.querySelector('[data-achievement-info-modal]');
            if (existing instanceof HTMLElement) {
                return existing;
            }

            const modal = document.createElement('div');
            modal.className = 'confirm-modal achievement-info-modal';
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('data-achievement-info-modal', '');
            modal.innerHTML = `
                <div class="confirm-modal-backdrop" data-achievement-info-close></div>
                <div class="confirm-modal-card achievement-info-modal-card" role="dialog" aria-modal="true" aria-labelledby="achievement-info-title">
                    <button type="button" class="achievement-info-close" data-achievement-info-close aria-label="Close">x</button>
                    <span class="achievement-chip" data-achievement-info-status></span>
                    <h3 id="achievement-info-title" data-achievement-info-title></h3>
                    <p data-achievement-info-description></p>
                    <div class="achievement-info-meta">
                        <span class="achievement-chip" data-achievement-info-date hidden></span>
                        <span class="achievement-chip" data-achievement-info-reward hidden></span>
                    </div>
                    <div class="achievement-progress achievement-modal-progress" data-achievement-info-progress hidden>
                        <div class="goal-progress"><span data-achievement-info-progress-bar></span></div>
                        <small data-achievement-info-progress-text></small>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            return modal;
        };

        const modal = ensureModal();
        const title = modal.querySelector('[data-achievement-info-title]');
        const description = modal.querySelector('[data-achievement-info-description]');
        const status = modal.querySelector('[data-achievement-info-status]');
        const date = modal.querySelector('[data-achievement-info-date]');
        const reward = modal.querySelector('[data-achievement-info-reward]');
        const progress = modal.querySelector('[data-achievement-info-progress]');
        const progressBar = modal.querySelector('[data-achievement-info-progress-bar]');
        const progressText = modal.querySelector('[data-achievement-info-progress-text]');
        const closeButtons = modal.querySelectorAll('[data-achievement-info-close]');

        const setOptionalText = (element, text) => {
            if (!(element instanceof HTMLElement)) {
                return;
            }
            const value = String(text || '').trim();
            element.hidden = value === '';
            element.textContent = value;
        };

        const closeModal = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
        };

        const openModal = (card) => {
            if (!(card instanceof HTMLElement)) {
                return;
            }
            if (title) {
                title.textContent = card.dataset.achievementName || '';
            }
            if (description) {
                description.textContent = card.dataset.achievementDescription || '';
            }
            if (status) {
                status.textContent = card.dataset.achievementStatus || '';
            }
            setOptionalText(date, card.dataset.achievementDate || '');
            setOptionalText(reward, card.dataset.achievementReward ? `Reward: ${card.dataset.achievementReward}` : '');

            const hasProgress = card.dataset.achievementProgress === '1';
            if (progress instanceof HTMLElement) {
                progress.hidden = !hasProgress;
            }
            if (hasProgress) {
                const pct = Math.max(0, Math.min(100, Number(card.dataset.achievementProgressPct || 0)));
                if (progressBar instanceof HTMLElement) {
                    progressBar.style.width = `${pct}%`;
                }
                if (progressText) {
                    progressText.textContent = card.dataset.achievementProgressText || '';
                }
            }

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            const close = modal.querySelector('[data-achievement-info-close]');
            if (close instanceof HTMLElement) {
                close.focus();
            }
        };

        const interactiveSelector = 'a, button, form, input, select, textarea, summary, details, label';
        cards.forEach((card) => {
            if (!(card instanceof HTMLElement) || card.dataset.achievementModalReady === '1') {
                return;
            }
            card.dataset.achievementModalReady = '1';
            card.addEventListener('click', (event) => {
                const target = event.target;
                if (target instanceof Element && target.closest(interactiveSelector)) {
                    return;
                }
                openModal(card);
            });
            card.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                openModal(card);
            });
        });

        closeButtons.forEach((button) => button.addEventListener('click', closeModal));
        window.addEventListener('keydown', (event) => {
            if (!modal.hidden && event.key === 'Escape') {
                closeModal();
            }
        });
    };

    const initMealCalendar = () => {
        const root = document.querySelector('[data-meal-calendar-root]');
        if (!(root instanceof HTMLElement) || root.dataset.mealCalendarReady === '1') {
            return;
        }
        const calendarPage = String(root.dataset.calendarPage || 'entries');
        const form = document.querySelector(`[data-meal-calendar-form][data-calendar-page="${calendarPage}"]`) || root.querySelector('[data-meal-calendar-form]');
        const dateInput = form?.querySelector('[data-meal-calendar-date]');
        const periodInput = form?.querySelector('[data-meal-calendar-period]');
        const periodLabel = form?.querySelector('[data-meal-calendar-period-label]');
        const viewSelect = form?.querySelector('[data-meal-calendar-view]');
        const viewOptions = form?.querySelectorAll('[data-calendar-view-option]') || root.querySelectorAll('[data-calendar-view-option]');
        const daysGrid = root.querySelector('[data-meal-calendar-days]');
        const backLink = root.querySelector('[data-meal-calendar-back]');
        const photosPanel = document.querySelector('[data-meal-calendar-photos-panel]');
        const photosTarget = photosPanel?.querySelector('[data-meal-calendar-selected-photos]');
        const periodPanel = document.querySelector('[data-meal-calendar-period-panel]');
        const periodTarget = periodPanel?.querySelector('[data-meal-calendar-period-photos]');
        const periodCount = periodPanel?.querySelector('[data-meal-calendar-period-count]');
        const selectedDateLabel = photosPanel?.querySelector('.eyebrow');
        const visiblePeriodLabels = document.querySelectorAll('[data-meal-calendar-visible-period]');
        const visiblePeriodInputs = document.querySelectorAll('[data-meal-calendar-visible-period-input]');
        const visiblePeriodTriggers = document.querySelectorAll('[data-meal-calendar-visible-period-trigger]');
        const galleryUrl = String(root.dataset.galleryUrl || '/?page=gallery');

        if (!(form instanceof HTMLFormElement) || !(dateInput instanceof HTMLInputElement) || !(viewSelect instanceof HTMLInputElement) || !(daysGrid instanceof HTMLElement)) {
            return;
        }

        root.dataset.mealCalendarReady = '1';
        dateInput.onchange = null;
        dateInput.removeAttribute('onchange');
        if (periodInput instanceof HTMLInputElement) {
            periodInput.onchange = null;
            periodInput.removeAttribute('onchange');
        }

        const submitFallback = () => {
            HTMLFormElement.prototype.submit.call(form);
        };
        const endpointUrl = () => {
            const url = new URL('/', window.location.origin);
            url.searchParams.set('page', 'api_meal_calendar');
            url.searchParams.set('calendar_view', viewSelect.value || 'week');
            url.searchParams.set('include_photos', String(root.dataset.includePhotos || '1') === '0' ? '0' : '1');
            const periodValue = periodInput instanceof HTMLInputElement ? periodInput.value : '';
            if (viewSelect.value === 'month' && /^\d{4}-\d{2}$/.test(periodValue)) {
                url.searchParams.set('calendar_month', periodValue);
            } else if (viewSelect.value === 'week' && /^\d{4}-W\d{2}$/.test(periodValue)) {
                url.searchParams.set('calendar_week', periodValue);
            } else {
                url.searchParams.set('date', periodValue || dateInput.value || '');
            }
            const userId = String(root.dataset.userId || '').trim();
            if (userId !== '') {
                url.searchParams.set('user_id', userId);
            }
            return url;
        };
        const pageUrl = (date, view) => {
            const url = new URL('/', window.location.origin);
            url.searchParams.set('page', calendarPage === 'gallery' ? 'gallery' : 'entries');
            if (calendarPage === 'gallery') {
                url.searchParams.set('gallery_view', 'calendar');
                const userId = String(root.dataset.userId || '').trim();
                if (userId !== '') {
                    url.searchParams.set('user_id', userId);
                }
            } else {
                url.searchParams.set('mode', 'calendar');
                const userId = String(root.dataset.userId || '').trim();
                if (userId !== '') {
                    url.searchParams.set('user_id', userId);
                }
            }
            url.searchParams.set('calendar_view', view || 'week');
            const targetView = view || 'week';
            if (targetView === 'month') {
                url.searchParams.set('calendar_month', String(date || '').slice(0, 7));
            } else if (targetView === 'week') {
                url.searchParams.set('calendar_week', isoWeekValue(date || ''));
            } else {
                url.searchParams.set('date', date || '');
            }
            return url;
        };
        const isoWeekValue = (date) => {
            const parsed = new Date(`${date || ''}T00:00:00`);
            if (Number.isNaN(parsed.getTime())) {
                return '';
            }
            const day = parsed.getDay() || 7;
            parsed.setDate(parsed.getDate() + 4 - day);
            const yearStart = new Date(parsed.getFullYear(), 0, 1);
            const week = Math.ceil((((parsed - yearStart) / 86400000) + 1) / 7);
            return `${parsed.getFullYear()}-W${String(week).padStart(2, '0')}`;
        };
        const configurePeriodInput = (view) => {
            if (!(periodInput instanceof HTMLInputElement)) {
                return;
            }
            if (view === 'month') {
                periodInput.type = 'month';
                periodInput.name = 'calendar_month';
                if (periodLabel instanceof HTMLElement) {
                    periodLabel.textContent = periodInput.dataset.labelMonth || 'Month';
                }
                return;
            }
            if (view === 'week') {
                periodInput.type = 'week';
                periodInput.name = 'calendar_week';
                if (periodLabel instanceof HTMLElement) {
                    periodLabel.textContent = periodInput.dataset.labelWeek || 'Week';
                }
                return;
            }
            periodInput.type = 'date';
            periodInput.name = 'date';
            if (periodLabel instanceof HTMLElement) {
                periodLabel.textContent = periodInput.dataset.labelDate || 'Date';
            }
        };
        const setPeriodInputForView = (view, date) => {
            if (!(periodInput instanceof HTMLInputElement)) {
                return;
            }
            configurePeriodInput(view);
            const normalizedDate = String(date || dateInput.value || '');
            if (view === 'month') {
                periodInput.value = normalizedDate.slice(0, 7);
                return;
            }
            if (view === 'week') {
                periodInput.value = isoWeekValue(normalizedDate);
                return;
            }
            periodInput.value = normalizedDate;
        };
        const updatePeriodInput = (payload) => {
            if (!(periodInput instanceof HTMLInputElement)) {
                return;
            }
            const view = String(payload.calendar_view || viewSelect.value || 'week');
            configurePeriodInput(view);
            if (view === 'month') {
                periodInput.value = String(payload.calendar_month || String(payload.date || '').slice(0, 7));
                return;
            }
            if (view === 'week') {
                periodInput.value = String(payload.calendar_week || isoWeekValue(payload.date || dateInput.value));
                return;
            }
            periodInput.value = String(payload.date || dateInput.value || '');
        };
        const updateVisiblePeriodInputs = (payload) => {
            const view = String(payload.calendar_view || viewSelect.value || 'week');
            const monthValue = view === 'month'
                ? String(payload.calendar_month || String(payload.date || dateInput.value || '').slice(0, 7))
                : String(payload.date || dateInput.value || '').slice(0, 7);
            if (!/^\d{4}-\d{2}$/.test(monthValue)) {
                return;
            }
            visiblePeriodInputs.forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.value = monthValue;
                    input.disabled = view !== 'month';
                    input.hidden = view !== 'month';
                }
            });
        };
        const appendText = (parent, tagName, text, className = '') => {
            const node = document.createElement(tagName);
            if (className !== '') {
                node.className = className;
            }
            node.textContent = String(text || '');
            parent.appendChild(node);
            return node;
        };
        const renderCalendarEmptyState = (target, labels) => {
            const empty = document.createElement('div');
            empty.className = 'calendar-empty-state';
            appendText(empty, 'strong', labels.empty_period_title || 'No photos in this period');
            appendText(empty, 'p', labels.empty_period_body || labels.no_photos || 'No photos uploaded yet.');
            const link = document.createElement('a');
            link.className = 'btn btn-ghost small';
            link.href = galleryUrl;
            link.textContent = labels.view_latest || 'View latest photos';
            empty.appendChild(link);
            target.appendChild(empty);
        };
        const renderDays = (payload) => {
            const labels = payload.labels || {};
            const selectedDate = String(payload.date || '');
            const activeView = String(payload.calendar_view || viewSelect.value || 'month');
            daysGrid.classList.toggle('meal-calendar-month', payload.calendar_view === 'month');
            daysGrid.classList.toggle('meal-calendar-week', payload.calendar_view === 'week');
            daysGrid.classList.toggle('meal-calendar-day', payload.calendar_view === 'day');
            daysGrid.innerHTML = '';
            (Array.isArray(payload.days) ? payload.days : []).forEach((day) => {
                const link = document.createElement('a');
                link.className = `entries-calendar-day${day.has_log ? ' has-log' : ''}${String(day.date || '') === selectedDate ? ' is-selected' : ''}`;
                link.href = String(day.href || '#');

                const article = document.createElement('article');
                const dayLabel = activeView === 'month'
                    ? (day.day_number || day.date_short || day.date_label || day.date || '')
                    : (activeView === 'week' ? (day.date_short || day.date_label || day.date || '') : (day.date_label || day.date || ''));
                appendText(article, 'strong', dayLabel);
                const previewPhotos = Array.isArray(day.preview_photos) ? day.preview_photos : [];
                if (previewPhotos.length > 0 || day.thumb_url || day.preview_url) {
                    const collage = document.createElement('div');
                    const collagePhotos = previewPhotos.length > 0
                        ? previewPhotos.slice(0, 3)
                        : [{ thumb_url: day.thumb_url, photo_url: day.preview_url }];
                    collage.className = `entries-calendar-collage collage-count-${Math.min(3, Math.max(1, collagePhotos.length))}`;
                    collagePhotos.forEach((photo) => {
                        const src = String(photo.thumb_url || photo.photo_url || '');
                        if (src === '') {
                            return;
                        }
                        const image = document.createElement('img');
                        image.src = src;
                        if (photo.thumb_srcset) {
                            image.srcset = String(photo.thumb_srcset || '');
                        }
                        image.sizes = String(photo.thumb_sizes || '(max-width: 600px) 24vw, 140px');
                        image.width = 400;
                        image.height = 400;
                        image.alt = String(labels.photo || 'Photo');
                        image.loading = 'lazy';
                        image.decoding = 'async';
                        collage.appendChild(image);
                    });
                    article.appendChild(collage);
                } else {
                    appendText(article, 'div', labels.no_photo || 'No photo', 'entries-calendar-empty');
                }
                appendText(article, 'span', day.count_label || '', 'badge');

                link.appendChild(article);
                daysGrid.appendChild(link);
            });
        };
        const renderPhotos = (payload) => {
            if (!(photosTarget instanceof HTMLElement)) {
                return;
            }
            const labels = payload.labels || {};
            const photos = Array.isArray(payload.selected_photos) ? payload.selected_photos : [];
            const selectedDay = (Array.isArray(payload.days) ? payload.days : []).find((day) => String(day.date || '') === String(payload.date || ''));
            if (selectedDateLabel instanceof HTMLElement) {
                selectedDateLabel.textContent = `${labels.date || 'Date'} | ${selectedDay?.date_label || payload.date || ''}`;
            }
            photosTarget.innerHTML = '';
            if (photos.length === 0) {
                renderCalendarEmptyState(photosTarget, labels);
                return;
            }

            const grid = document.createElement('div');
            grid.className = 'photo-grid';
            photos.forEach((photo) => {
                const figure = document.createElement('figure');
                figure.className = 'photo-card';

                const media = document.createElement('a');
                media.className = 'photo-card-media';
                media.href = String(photo.photo_href || '#');
                if (photo.thumb_url || photo.photo_url) {
                    const image = document.createElement('img');
                    image.src = String(photo.thumb_url || photo.photo_url || '');
                    if (photo.thumb_srcset) {
                        image.srcset = String(photo.thumb_srcset || '');
                    }
                    image.sizes = String(photo.thumb_sizes || '(max-width: 600px) 46vw, 240px');
                    image.width = 400;
                    image.height = 400;
                    image.alt = String(labels.photo || 'Photo');
                    image.loading = 'lazy';
                    image.decoding = 'async';
                    media.appendChild(image);
                } else {
                    appendText(media, 'div', labels.no_photo || 'No photo', 'entries-calendar-empty');
                }
                figure.appendChild(media);

                const caption = document.createElement('figcaption');
                appendText(caption, 'strong', photo.display_name || '');
                appendText(caption, 'span', `${photo.date_label || ''} | ${photo.category_label || ''}`);
                if (String(photo.caption || '').trim() !== '') {
                    appendText(caption, 'span', photo.caption || '');
                }
                if (String(photo.nutrition || '').trim() !== '') {
                    appendText(caption, 'span', photo.nutrition || '', 'photo-nutrition-line');
                }
                figure.appendChild(caption);
                grid.appendChild(figure);
            });
            photosTarget.appendChild(grid);
        };
        const renderPeriodPhotos = (payload) => {
            if (!(periodTarget instanceof HTMLElement)) {
                return;
            }
            const labels = payload.labels || {};
            const photos = Array.isArray(payload.period_photos) ? payload.period_photos : [];
            if (periodCount instanceof HTMLElement) {
                periodCount.textContent = `${photos.length} ${photos.length === 1 ? (labels.photo_singular || 'photo') : (labels.photo_plural || 'photos')}`;
            }
            periodTarget.innerHTML = '';
            if (photos.length === 0) {
                renderCalendarEmptyState(periodTarget, labels);
                return;
            }

            const grid = document.createElement('div');
            grid.className = 'entries-calendar-mobile-gallery';
            photos.forEach((photo) => {
                const link = document.createElement('a');
                link.className = 'entries-calendar-mobile-tile';
                link.href = String(photo.photo_href || '#');
                link.dataset.dateLabel = String(photo.date_label || photo.date || '');

                if (photo.thumb_url || photo.photo_url) {
                    const image = document.createElement('img');
                    image.src = String(photo.thumb_url || photo.photo_url || '');
                    if (photo.thumb_srcset) {
                        image.srcset = String(photo.thumb_srcset || '');
                    }
                    image.sizes = String(photo.thumb_sizes || '(max-width: 600px) 33vw, 180px');
                    image.width = 400;
                    image.height = 400;
                    image.alt = String(labels.photo || 'Photo');
                    image.loading = 'lazy';
                    image.decoding = 'async';
                    link.appendChild(image);
                } else {
                    appendText(link, 'div', labels.no_photo || 'No photo', 'entries-calendar-empty');
                }
                appendText(link, 'span', photo.date_label || photo.date || '');
                grid.appendChild(link);
            });
            periodTarget.appendChild(grid);
        };
        const updateVisiblePhotoPeriod = () => {
            if (!(periodTarget instanceof HTMLElement)) {
                return;
            }
            const tiles = Array.from(periodTarget.querySelectorAll('.entries-calendar-mobile-tile'));
            const viewportMid = window.innerHeight * 0.38;
            const activeTile = tiles.find((tile) => {
                const rect = tile.getBoundingClientRect();
                return rect.bottom >= 0 && rect.top <= viewportMid;
            });
            if (activeTile instanceof HTMLElement && activeTile.dataset.dateLabel) {
                visiblePeriodLabels.forEach((label) => {
                    if (label instanceof HTMLElement) {
                        label.textContent = activeTile.dataset.dateLabel || '';
                    }
                });
            }
        };
        let visiblePeriodTicking = false;
        const queueVisiblePhotoPeriod = () => {
            if (visiblePeriodTicking) {
                return;
            }
            visiblePeriodTicking = true;
            window.requestAnimationFrame(() => {
                updateVisiblePhotoPeriod();
                visiblePeriodTicking = false;
            });
        };
        const renderPayload = (payload) => {
            if (!payload || payload.ok !== true) {
                throw new Error('Calendar response was not ok.');
            }
            dateInput.value = String(payload.date || dateInput.value || '');
            viewSelect.value = String(payload.calendar_view || viewSelect.value || 'week');
            updatePeriodInput(payload);
            updateVisiblePeriodInputs(payload);
            viewOptions.forEach((option) => {
                if (!(option instanceof HTMLElement)) {
                    return;
                }
                const isActive = option.dataset.calendarViewOption === viewSelect.value;
                option.classList.toggle('active', isActive);
                option.setAttribute('aria-current', isActive ? 'true' : 'false');
                if (option instanceof HTMLAnchorElement) {
                    option.href = pageUrl(dateInput.value, option.dataset.calendarViewOption || viewSelect.value).toString();
                }
            });
            if (backLink instanceof HTMLAnchorElement) {
                backLink.href = `/?page=entries&mode=meal&date=${encodeURIComponent(dateInput.value)}`;
            }
            visiblePeriodLabels.forEach((label) => {
                if (label instanceof HTMLElement) {
                    label.classList.add('is-updating');
                }
            });
            window.requestAnimationFrame(() => {
                visiblePeriodLabels.forEach((label) => {
                    if (!(label instanceof HTMLElement)) {
                        return;
                    }
                    label.textContent = String(payload.period_label || payload.calendar_month || payload.calendar_week || payload.date || '');
                    label.classList.remove('is-updating');
                });
            });
            renderDays(payload);
            renderPhotos(payload);
            renderPeriodPhotos(payload);
            queueVisiblePhotoPeriod();
        };
        const loadCalendar = async (pushState = true) => {
            try {
                root.classList.add('is-loading');
                beginPageLoading();
                const response = await fetch(endpointUrl().toString(), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                renderPayload(payload);
                if (pushState) {
                    history.pushState({}, '', pageUrl(dateInput.value, viewSelect.value).toString());
                }
            } catch (error) {
                console.error('Calendar update failed:', error);
                submitFallback();
            } finally {
                root.classList.remove('is-loading');
                endPageLoading();
                clearMobileViewTransitionState(true);
                queueMobileViewTransitionStateCleanup(350, true);
            }
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            loadCalendar(true);
        });
        form.addEventListener('change', (event) => {
            if (event.target === dateInput || event.target === periodInput || event.target === viewSelect) {
                loadCalendar(true);
            }
        });
        viewOptions.forEach((option) => {
            if (!(option instanceof HTMLAnchorElement)) {
                return;
            }
            option.addEventListener('click', (event) => {
                event.preventDefault();
                const nextView = option.dataset.calendarViewOption || viewSelect.value || 'month';
                viewSelect.value = nextView;
                setPeriodInputForView(nextView, dateInput.value);
                loadCalendar(true);
            });
        });
        visiblePeriodInputs.forEach((input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            input.addEventListener('change', () => {
                if (!/^\d{4}-\d{2}$/.test(input.value)) {
                    return;
                }
                viewSelect.value = 'month';
                if (periodInput instanceof HTMLInputElement) {
                    configurePeriodInput('month');
                    periodInput.value = input.value;
                }
                loadCalendar(true);
            });
        });
        visiblePeriodTriggers.forEach((trigger) => {
            if (!(trigger instanceof HTMLElement)) {
                return;
            }
            const openPicker = () => {
                if (viewSelect.value !== 'month') {
                    return;
                }
                const input = trigger.querySelector('[data-meal-calendar-visible-period-input]');
                if (!(input instanceof HTMLInputElement)) {
                    return;
                }
                input.focus({ preventScroll: true });
                if (typeof input.showPicker === 'function') {
                    try {
                        input.showPicker();
                    } catch (error) {
                        input.click();
                    }
                } else {
                    input.click();
                }
            };
            trigger.addEventListener('click', (event) => {
                if (event.target instanceof HTMLInputElement) {
                    return;
                }
                openPicker();
            });
            trigger.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                openPicker();
            });
        });
        window.addEventListener('popstate', () => {
            const url = new URL(window.location.href);
            const isEntriesCalendar = url.searchParams.get('page') === 'entries' && url.searchParams.get('mode') === 'calendar';
            const isGalleryCalendar = url.searchParams.get('page') === 'gallery' && url.searchParams.get('gallery_view') === 'calendar';
            if ((calendarPage === 'gallery' && !isGalleryCalendar) || (calendarPage !== 'gallery' && !isEntriesCalendar)) {
                return;
            }
            dateInput.value = url.searchParams.get('date') || dateInput.value;
            viewSelect.value = url.searchParams.get('calendar_view') || viewSelect.value;
            if (periodInput instanceof HTMLInputElement) {
                configurePeriodInput(viewSelect.value);
                if (viewSelect.value === 'month') {
                    periodInput.value = url.searchParams.get('calendar_month') || periodInput.value;
                } else if (viewSelect.value === 'week') {
                    periodInput.value = url.searchParams.get('calendar_week') || periodInput.value;
                } else {
                    periodInput.value = url.searchParams.get('date') || periodInput.value;
                }
            }
            loadCalendar(false);
        });
        window.addEventListener('scroll', queueVisiblePhotoPeriod, { passive: true });
    };

    const formatDayMonth = (dateString) => {
        const parts = String(dateString || '').split('/');
        return parts.length >= 2 ? `${parts[0]}/${parts[1]}` : String(dateString || '');
    };

    const analyticsChartInstances = new WeakMap();

    const initAnalyticsCharts = (container = document) => {
        if (typeof window.Chart === 'undefined') {
            return;
        }
        const root = container instanceof Element ? container : document;
        const payloadNode = root.querySelector('[data-analytics-chart-data]');
        if (!(payloadNode instanceof HTMLScriptElement)) {
            return;
        }

        let payload = null;
        try {
            payload = JSON.parse(payloadNode.textContent || '{}');
        } catch (error) {
            console.error('Analytics chart payload failed to parse:', error);
            return;
        }

        const dateChartOptions = () => ({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { title: (items) => (items && items[0] ? items[0].label : '') } },
            },
            scales: { x: { ticks: { callback: function (value) { return formatDayMonth(this.getLabelForValue(value)); } } } },
        });

        (Array.isArray(payload.charts) ? payload.charts : []).forEach((chartConfig) => {
            const chartId = String(chartConfig.id || '');
            if (chartId === '') {
                return;
            }
            const canvas = root.querySelector(`#${chartId}`);
            if (!(canvas instanceof HTMLCanvasElement)) {
                return;
            }
            const existing = analyticsChartInstances.get(canvas);
            if (existing && typeof existing.destroy === 'function') {
                existing.destroy();
            }
            const chart = new window.Chart(canvas, {
                type: chartConfig.type || 'line',
                data: { labels: chartConfig.labels || [], datasets: chartConfig.datasets || [] },
                options: dateChartOptions(),
            });
            analyticsChartInstances.set(canvas, chart);
        });

        const compareConfig = payload.compare || {};
        const compareCanvas = root.querySelector(`#${String(compareConfig.id || 'compareChart')}`);
        if (compareCanvas instanceof HTMLCanvasElement) {
            const existing = analyticsChartInstances.get(compareCanvas);
            if (existing && typeof existing.destroy === 'function') {
                existing.destroy();
            }
            const chart = new window.Chart(compareCanvas, {
                type: 'bar',
                data: { labels: compareConfig.labels || [], datasets: compareConfig.datasets || [] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } },
                    scales: { y: { beginAtZero: true, max: 100 } },
                },
            });
            analyticsChartInstances.set(compareCanvas, chart);
        }
    };

    window.initAnalyticsCharts = initAnalyticsCharts;

    const initAnalyticsEnhancement = () => {
        const root = document.querySelector('[data-analytics-page]');
        if (!(root instanceof HTMLElement) || root.dataset.analyticsReady === '1') {
            return;
        }
        root.dataset.analyticsReady = '1';

        const form = document.querySelector('[data-analytics-filter]');
        if (!(form instanceof HTMLFormElement)) {
            initAnalyticsCharts(root);
            return;
        }

        const setPeriod = (period) => {
            const periodInput = form.querySelector('input[name="analytics_period"]');
            if (periodInput instanceof HTMLInputElement) {
                periodInput.value = period;
            }
        };

        const replaceAnalyticsPage = async (url) => {
            try {
                root.classList.add('is-loading');
                beginPageLoading();
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'text/html' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`Analytics request failed: ${response.status}`);
                }
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nextPage = doc.querySelector('[data-analytics-page]');
                if (!(nextPage instanceof HTMLElement)) {
                    throw new Error('Analytics page fragment missing.');
                }
                const currentTopbarContext = document.querySelector('.topbar-actions .topbar-context');
                const nextTopbarContext = doc.querySelector('.topbar-actions .topbar-context');
                if (currentTopbarContext instanceof HTMLElement && nextTopbarContext instanceof HTMLElement) {
                    currentTopbarContext.replaceWith(nextTopbarContext);
                }
                const currentEditButton = document.querySelector('.topbar-actions .analytics-edit-layout-button');
                const nextEditButton = doc.querySelector('.topbar-actions .analytics-edit-layout-button');
                if (currentEditButton instanceof HTMLElement && nextEditButton instanceof HTMLElement) {
                    currentEditButton.replaceWith(nextEditButton);
                } else if (currentEditButton instanceof HTMLElement) {
                    currentEditButton.remove();
                } else if (nextEditButton instanceof HTMLElement) {
                    const topbarActions = document.querySelector('.topbar-actions');
                    const addMenu = topbarActions?.querySelector('.topbar-add-menu');
                    if (topbarActions instanceof HTMLElement) {
                        topbarActions.insertBefore(nextEditButton, addMenu instanceof HTMLElement ? addMenu : null);
                    }
                }
                root.replaceWith(nextPage);
                history.pushState({}, '', url.toString());
                initAnalyticsEnhancement();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (error) {
                console.error('Analytics update failed:', error);
                window.location.href = url.toString();
            } finally {
                root.classList.remove('is-loading');
                endPageLoading();
                clearMobileViewTransitionState(true);
                queueMobileViewTransitionStateCleanup(350, true);
            }
        };

        const formUrl = () => new URL(`/?${new URLSearchParams(new FormData(form)).toString()}`, window.location.origin);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            replaceAnalyticsPage(formUrl());
        });
        form.addEventListener('change', (event) => {
            if (event.target instanceof HTMLSelectElement || event.target instanceof HTMLInputElement) {
                replaceAnalyticsPage(formUrl());
            }
        });
        form.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            const period = link.dataset.analyticsPeriod || '';
            if (period !== '') {
                setPeriod(period);
            }
            replaceAnalyticsPage(new URL(link.href, window.location.origin));
        });

        initAnalyticsCharts(root);
    };

    const initDashboardEnhancement = () => {
        const root = document.querySelector('[data-dashboard-page]');
        if (!(root instanceof HTMLElement) || root.dataset.dashboardReady === '1') {
            return;
        }
        root.dataset.dashboardReady = '1';
        const form = document.querySelector('[data-dashboard-control-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const replaceDashboardPage = async (url) => {
            try {
                root.classList.add('is-loading');
                beginPageLoading();
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'text/html' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`Dashboard request failed: ${response.status}`);
                }
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nextPage = doc.querySelector('[data-dashboard-page]');
                if (!(nextPage instanceof HTMLElement)) {
                    throw new Error('Dashboard page fragment missing.');
                }
                const currentTopbarContext = document.querySelector('.topbar-actions .topbar-context');
                const nextTopbarContext = doc.querySelector('.topbar-actions .topbar-context');
                if (currentTopbarContext instanceof HTMLElement && nextTopbarContext instanceof HTMLElement) {
                    currentTopbarContext.replaceWith(nextTopbarContext);
                }
                root.replaceWith(nextPage);
                history.pushState({}, '', url.toString());
                initDashboardEnhancement();
                initTeamLayoutEditor();
            } catch (error) {
                console.error('Dashboard update failed:', error);
                window.location.href = url.toString();
            } finally {
                root.classList.remove('is-loading');
                endPageLoading();
                clearMobileViewTransitionState(true);
                queueMobileViewTransitionStateCleanup(350, true);
            }
        };

        const formUrl = () => new URL(`/?${new URLSearchParams(new FormData(form)).toString()}`, window.location.origin);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            replaceDashboardPage(formUrl());
        });
        form.addEventListener('change', (event) => {
            if (event.target instanceof HTMLSelectElement || event.target instanceof HTMLInputElement) {
                replaceDashboardPage(formUrl());
            }
        });
    };

    const initAchievementDeleteModal = () => {
        const triggers = document.querySelectorAll('[data-achievement-delete-trigger]');
        if (triggers.length === 0) {
            return;
        }

        const ensureModal = () => {
            const existing = document.querySelector('[data-achievement-delete-modal]');
            if (existing instanceof HTMLElement) {
                return existing;
            }

            const modal = document.createElement('div');
            modal.className = 'confirm-modal';
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('data-achievement-delete-modal', '');
            modal.innerHTML = `
                <div class=\"confirm-modal-backdrop\" data-achievement-delete-cancel></div>
                <div class=\"confirm-modal-card\" role=\"dialog\" aria-modal=\"true\" aria-labelledby=\"confirm-title\">
                    <h3 id=\"confirm-title\">Delete this achievement?</h3>
                    <div class=\"confirm-modal-actions\">
                        <button type=\"button\" class=\"btn btn-ghost\" data-achievement-delete-cancel>Cancel</button>
                        <button type=\"button\" class=\"btn btn-primary\" data-achievement-delete-confirm>Delete</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            return modal;
        };

        const modal = ensureModal();
        const confirmBtn = modal.querySelector('[data-achievement-delete-confirm]');
        const cancelBtns = modal.querySelectorAll('[data-achievement-delete-cancel]');
        let pendingDeleteForm = null;

        const closeModal = () => {
            pendingDeleteForm = null;
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
        };

        const openModal = () => {
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            if (confirmBtn instanceof HTMLElement) {
                confirmBtn.focus();
            }
        };

        triggers.forEach((btn) => {
            if (!(btn instanceof HTMLButtonElement)) {
                return;
            }
            btn.addEventListener('click', () => {
                const card = btn.closest('.achievement-card');
                card?.querySelector('.achievement-delete-error')?.remove();

                const formId = btn.dataset.formId || '';
                if (formId === '') {
                    console.warn('Achievement delete form id missing on trigger.', btn);
                    return;
                }

                const form = document.getElementById(formId);
                if (!(form instanceof HTMLFormElement)) {
                    console.warn('Achievement delete form not found:', formId);
                    return;
                }

                const awardInput = form.querySelector('input[name="award_id"]');
                const awardValue = String(awardInput?.value || '').trim();
                if (awardValue === '') {
                    console.warn('Achievement award_id is empty for form:', formId);
                    const error = document.createElement('p');
                    error.className = 'achievement-delete-error';
                    error.textContent = 'Cannot delete: invalid award_id.';
                    card?.appendChild(error);
                    return;
                }

                pendingDeleteForm = form;
                openModal();
            });
        });

        if (confirmBtn instanceof HTMLButtonElement) {
            confirmBtn.addEventListener('click', () => {
                if (pendingDeleteForm instanceof HTMLFormElement) {
                    pendingDeleteForm.submit();
                }
                closeModal();
            });
        }

        cancelBtns.forEach((btn) => {
            btn.addEventListener('click', closeModal);
        });

        window.addEventListener('keydown', (event) => {
            if (!modal.hidden && event.key === 'Escape') {
                closeModal();
            }
        });

        triggers.forEach((btn) => {
            if (!(btn instanceof HTMLButtonElement)) {
                return;
            }
            const formId = btn.dataset.formId || '';
            const form = formId !== '' ? document.getElementById(formId) : null;
            if (!(form instanceof HTMLFormElement)) {
                console.warn('Achievement delete form not found during init:', formId);
                return;
            }
            const awardInput = form.querySelector('input[name="award_id"]');
            if (String(awardInput?.value || '').trim() === '') {
                console.warn('Achievement delete form has empty award_id:', formId);
            }
        });
    };

    const initPrimaryGoalsSelector = (editor) => {
        const widget = editor.querySelector('[data-primary-goals-editor]');
        if (!(widget instanceof HTMLElement) || widget.dataset.primaryGoalsReady === '1') {
            return;
        }

        const form = widget.closest('form');
        const input = form?.querySelector('[data-primary-goals-spec-input]');
        const list = widget.querySelector('[data-primary-goals-list]');
        const template = widget.querySelector('template[data-primary-goal-template]');
        const addButton = widget.querySelector('[data-primary-goal-add]');
        const empty = widget.querySelector('[data-primary-goals-empty]');
        if (!(form instanceof HTMLFormElement)
            || !(input instanceof HTMLInputElement)
            || !(list instanceof HTMLElement)
            || !(template instanceof HTMLTemplateElement)
            || !(addButton instanceof HTMLButtonElement)) {
            return;
        }

        const rows = () => Array.from(list.querySelectorAll('[data-primary-goal-row]'))
            .filter((row) => row instanceof HTMLElement);

        const formatGoalValue = (type, value) => {
            if (type === 'km') {
                return value.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
            }

            return String(Math.round(value));
        };

        const configureRow = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const select = row.querySelector('[data-primary-goal-type]');
            const valueInput = row.querySelector('[data-primary-goal-value]');
            if (!(select instanceof HTMLSelectElement) || !(valueInput instanceof HTMLInputElement)) {
                return;
            }

            const selectedOption = select.selectedOptions[0];
            const step = selectedOption?.dataset.step || (select.value === 'km' ? '0.1' : '1');
            const placeholder = selectedOption?.dataset.placeholder || '';
            valueInput.step = step;
            valueInput.placeholder = placeholder;
            valueInput.inputMode = select.value === 'km' ? 'decimal' : 'numeric';
        };

        const updateEmptyState = () => {
            if (empty instanceof HTMLElement) {
                empty.hidden = rows().length > 0;
            }
        };

        const serialize = () => {
            const order = [];
            const goals = new Map();
            rows().forEach((row) => {
                const select = row.querySelector('[data-primary-goal-type]');
                const valueInput = row.querySelector('[data-primary-goal-value]');
                if (!(select instanceof HTMLSelectElement) || !(valueInput instanceof HTMLInputElement)) {
                    return;
                }
                const type = String(select.value || '').trim();
                const value = Number.parseFloat(String(valueInput.value || '').replace(',', '.'));
                if (!type || !Number.isFinite(value) || value <= 0) {
                    return;
                }
                if (!goals.has(type)) {
                    order.push(type);
                }
                goals.set(type, formatGoalValue(type, value));
            });

            input.value = order
                .filter((type) => goals.has(type))
                .map((type) => `${type}:${goals.get(type)}`)
                .join(';');
            updateEmptyState();
        };

        const addRow = () => {
            const row = template.content.firstElementChild?.cloneNode(true);
            if (!(row instanceof HTMLElement)) {
                return;
            }
            list.appendChild(row);
            configureRow(row);
            updateEmptyState();
            serialize();

            const select = row.querySelector('[data-primary-goal-type]');
            if (select instanceof HTMLSelectElement) {
                select.focus();
            }
        };

        rows().forEach(configureRow);
        updateEmptyState();
        serialize();

        addButton.addEventListener('click', addRow);
        list.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target.closest('[data-primary-goal-remove]') : null;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            target.closest('[data-primary-goal-row]')?.remove();
            serialize();
        });
        list.addEventListener('input', (event) => {
            const row = event.target instanceof Element ? event.target.closest('[data-primary-goal-row]') : null;
            if (row instanceof HTMLElement) {
                serialize();
            }
        });
        list.addEventListener('change', (event) => {
            const row = event.target instanceof Element ? event.target.closest('[data-primary-goal-row]') : null;
            if (row instanceof HTMLElement) {
                configureRow(row);
                serialize();
            }
        });
        form.addEventListener('submit', serialize);
        widget.dataset.primaryGoalsReady = '1';
    };

    const initPrivacyOptions = () => {
        const form = document.querySelector('.profile-privacy-form');
        if (!form) {
            return;
        }
        const options = Array.from(form.querySelectorAll('.privacy-option'));
        form.addEventListener('change', (event) => {
            const target = event.target;
            if (!target || target.name !== 'profile_visibility') {
                return;
            }
            options.forEach((option) => {
                const input = option.querySelector('input[type="radio"]');
                option.classList.toggle('is-selected', !!input && input.checked);
            });
        });
    };

    const initProfileConfigEditor = () => {
        const editors = document.querySelectorAll('[data-config-editor]');
        if (editors.length === 0) {
            return;
        }
        editors.forEach((editor) => {
            if (!(editor instanceof HTMLElement)) {
                return;
            }
            initPrimaryGoalsSelector(editor);
            const readonly = editor.querySelector('[data-config-readonly]');
            const form = editor.querySelector('[data-config-form]');
            const editLink = editor.closest('.panel')?.querySelector('[data-config-edit-link]');
            const cancelLink = editor.querySelector('[data-config-cancel-link]');
            if (!readonly || !form || !(editLink instanceof HTMLAnchorElement) || editor.dataset.configEditorReady === '1') {
                return;
            }
            editor.dataset.configEditorReady = '1';

            const applyMode = (editing) => {
                readonly.hidden = editing;
                form.hidden = !editing;
            };

            editLink.addEventListener('click', (event) => {
                event.preventDefault();
                const url = new URL(editLink.href, window.location.origin);
                history.pushState({}, '', url.toString());
                applyMode(true);
            });

            if (cancelLink instanceof HTMLAnchorElement) {
                cancelLink.addEventListener('click', (event) => {
                    event.preventDefault();
                    const url = new URL(cancelLink.href, window.location.origin);
                    history.pushState({}, '', url.toString());
                    applyMode(false);
                });
            }
        });
    };

    const initAdminAchievementFields = () => {
        const forms = document.querySelectorAll('[data-achievement-form]');
        if (forms.length === 0) {
            return;
        }

        const bindForm = (form) => {
            if (!(form instanceof HTMLElement)) {
                return;
            }

            const toggle = form.querySelector('[data-achievement-conditional-toggle]');
            const fields = form.querySelector('[data-achievement-conditional-fields]');
            const metric = form.querySelector('[data-achievement-metric]');
            const habitWrap = form.querySelector('[data-achievement-habit-wrap]');
            if (!(toggle instanceof HTMLInputElement) || !(fields instanceof HTMLElement)) {
                return;
            }

            const updateHabit = () => {
                if (!(metric instanceof HTMLSelectElement) || !(habitWrap instanceof HTMLElement)) {
                    return;
                }
                const showHabit = metric.value === 'habit_completion' && toggle.checked;
                habitWrap.hidden = !showHabit;
                habitWrap.querySelectorAll('input, select, textarea').forEach((control) => {
                    if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
                        control.disabled = !showHabit;
                    }
                });
            };

            const updateConditional = () => {
                const enabled = toggle.checked;
                fields.hidden = !enabled;
                fields.querySelectorAll('input, select, textarea').forEach((control) => {
                    if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
                        control.disabled = !enabled;
                    }
                });
                if (enabled) {
                    updateHabit();
                } else if (habitWrap instanceof HTMLElement) {
                    habitWrap.hidden = true;
                }
            };

            if (form.dataset.achievementConditionalReady !== '1') {
                toggle.addEventListener('change', updateConditional);
                metric?.addEventListener('change', updateHabit);
                form.dataset.achievementConditionalReady = '1';
            }

            updateConditional();
        };

        forms.forEach((form) => bindForm(form));
    };

    const initImageCroppers = () => {
        const forms = document.querySelectorAll('[data-image-cropper-form]');
        if (forms.length === 0) {
            return;
        }

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.cropReady === '1') {
                return;
            }

            const fileInputs = Array.from(form.querySelectorAll('[data-image-crop-input]'))
                .filter((input) => input instanceof HTMLInputElement);
            const outputInput = form.querySelector('[data-image-crop-output]');
            const cropper = form.querySelector('[data-image-cropper]');
            const canvas = form.querySelector('[data-image-crop-canvas]');
            const zoomInput = form.querySelector('[data-image-crop-zoom]');
            const emptyHint = form.querySelector('[data-image-crop-empty]');
            const cropSubmit = form.querySelector('[data-image-crop-submit]');

            if (fileInputs.length === 0
                || !(outputInput instanceof HTMLInputElement)
                || !(canvas instanceof HTMLCanvasElement)
                || !(zoomInput instanceof HTMLInputElement)) {
                return;
            }

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }

            const state = {
                img: null,
                scale: 1,
                offsetX: 0,
                offsetY: 0,
                dragPointerId: null,
                dragStartX: 0,
                dragStartY: 0,
                dragOriginX: 0,
                dragOriginY: 0,
            };

            const clampOffsets = () => {
                if (!(state.img instanceof Image)) {
                    return;
                }
                const size = canvas.width;
                const baseScale = Math.max(size / state.img.naturalWidth, size / state.img.naturalHeight);
                const drawScale = baseScale * state.scale;
                const drawWidth = state.img.naturalWidth * drawScale;
                const drawHeight = state.img.naturalHeight * drawScale;
                const minX = Math.min(0, size - drawWidth);
                const minY = Math.min(0, size - drawHeight);
                state.offsetX = Math.min(0, Math.max(minX, state.offsetX));
                state.offsetY = Math.min(0, Math.max(minY, state.offsetY));
            };

            const render = () => {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = '#f3f7f6';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                if (!(state.img instanceof Image)) {
                    outputInput.value = '';
                    if (cropSubmit instanceof HTMLButtonElement) {
                        cropSubmit.disabled = true;
                    }
                    if (cropper instanceof HTMLElement) {
                        cropper.hidden = true;
                    }
                    if (emptyHint instanceof HTMLElement) {
                        emptyHint.hidden = false;
                    }
                    return;
                }

                if (emptyHint instanceof HTMLElement) {
                    emptyHint.hidden = true;
                }
                if (cropper instanceof HTMLElement) {
                    cropper.hidden = false;
                }

                clampOffsets();
                const size = canvas.width;
                const baseScale = Math.max(size / state.img.naturalWidth, size / state.img.naturalHeight);
                const drawScale = baseScale * state.scale;
                const drawWidth = state.img.naturalWidth * drawScale;
                const drawHeight = state.img.naturalHeight * drawScale;

                ctx.drawImage(state.img, state.offsetX, state.offsetY, drawWidth, drawHeight);
            };

            const resetFromImage = (img) => {
                state.img = img;
                if (cropper instanceof HTMLElement) {
                    cropper.hidden = false;
                }
                state.scale = Number(zoomInput.value || 1);
                if (!Number.isFinite(state.scale) || state.scale < 1) {
                    state.scale = 1;
                }
                const size = canvas.width;
                const baseScale = Math.max(size / img.naturalWidth, size / img.naturalHeight);
                const drawScale = baseScale * state.scale;
                const drawWidth = img.naturalWidth * drawScale;
                const drawHeight = img.naturalHeight * drawScale;
                state.offsetX = (size - drawWidth) / 2;
                state.offsetY = (size - drawHeight) / 2;
                render();
            };

            fileInputs.forEach((fileInput) => fileInput.addEventListener('change', () => {
                const selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                if (!selectedFile) {
                    return;
                }
                fileInputs.forEach((otherInput) => {
                    if (otherInput !== fileInput) otherInput.value = '';
                });
                if (cropper instanceof HTMLElement) {
                    cropper.hidden = false;
                }
                if (cropSubmit instanceof HTMLButtonElement) {
                    cropSubmit.disabled = false;
                }

                const reader = new FileReader();
                reader.onload = () => {
                    const img = new Image();
                    img.onload = () => resetFromImage(img);
                    img.src = String(reader.result || '');
                };
                reader.readAsDataURL(selectedFile);
            }));

            zoomInput.addEventListener('input', () => {
                if (!(state.img instanceof Image)) {
                    return;
                }
                let previousScale = state.scale;
                state.scale = Number(zoomInput.value || 1);
                if (!Number.isFinite(state.scale) || state.scale < 1) {
                    state.scale = 1;
                }
                if (previousScale <= 0) {
                    previousScale = 1;
                }
                const ratio = state.scale / previousScale;
                const center = canvas.width / 2;
                state.offsetX = center - (center - state.offsetX) * ratio;
                state.offsetY = center - (center - state.offsetY) * ratio;
                render();
            });

            canvas.addEventListener('pointerdown', (event) => {
                if (!(state.img instanceof Image)) {
                    return;
                }
                state.dragPointerId = event.pointerId;
                state.dragStartX = event.clientX;
                state.dragStartY = event.clientY;
                state.dragOriginX = state.offsetX;
                state.dragOriginY = state.offsetY;
                canvas.setPointerCapture(event.pointerId);
            });

            canvas.addEventListener('pointermove', (event) => {
                if (state.dragPointerId !== event.pointerId || !(state.img instanceof Image)) {
                    return;
                }
                state.offsetX = state.dragOriginX + (event.clientX - state.dragStartX);
                state.offsetY = state.dragOriginY + (event.clientY - state.dragStartY);
                render();
            });

            const stopDrag = (event) => {
                if (state.dragPointerId !== event.pointerId) {
                    return;
                }
                state.dragPointerId = null;
                if (canvas.hasPointerCapture(event.pointerId)) {
                    canvas.releasePointerCapture(event.pointerId);
                }
            };
            canvas.addEventListener('pointerup', stopDrag);
            canvas.addEventListener('pointercancel', stopDrag);

            form.addEventListener('submit', () => {
                if (state.img instanceof Image) {
                    outputInput.value = canvas.toDataURL('image/jpeg', 0.92);
                } else {
                    outputInput.value = '';
                }
            });

            form.dataset.cropReady = '1';
            render();
        });
    };

    const initSettingsAvatarHashFallback = () => {
        if (document.body?.dataset.page !== 'settings') {
            return;
        }
        const url = new URL(window.location.href);
        if (url.hash !== '#avatar' || url.searchParams.get('view') === 'avatar') {
            return;
        }
        url.searchParams.set('view', 'avatar');
        window.location.replace(url.toString());
    };

    const initGalleryMonthOverlay = () => {
        const overlay = document.querySelector('[data-gallery-month-floating]');
        const grid = document.querySelector('.photos-gallery-grid-continuous');
        if (!(overlay instanceof HTMLElement) || !(grid instanceof HTMLElement)) {
            return;
        }
        const tiles = Array.from(grid.querySelectorAll('.photos-gallery-tile[data-month-label]'))
            .filter((tile) => tile instanceof HTMLElement);
        if (tiles.length === 0) {
            return;
        }
        const monthStarts = tiles.filter((tile) => tile.dataset.monthStart === '1');

        let lastLabel = '';
        let scrollTimer = 0;
        let ticking = false;
        const hideOverlaySoon = () => {
            window.clearTimeout(scrollTimer);
            scrollTimer = window.setTimeout(() => {
                overlay.classList.remove('is-visible');
                overlay.hidden = true;
            }, 900);
        };
        const update = () => {
            const topbarOffset = 86;
            const gridRect = grid.getBoundingClientRect();
            if (window.scrollY <= 12 || gridRect.bottom <= topbarOffset || gridRect.top > window.innerHeight) {
                overlay.classList.remove('is-visible');
                overlay.hidden = true;
                ticking = false;
                return;
            }

            let activeLabel = '';
            let activeMonthStart = null;
            for (const tile of monthStarts) {
                const rect = tile.getBoundingClientRect();
                if (rect.top <= topbarOffset + 10) {
                    activeMonthStart = tile;
                } else {
                    break;
                }
            }
            if (activeMonthStart instanceof HTMLElement) {
                activeLabel = String(activeMonthStart.dataset.monthLabel || '').trim();
            }
            if (activeLabel === '') {
                const firstVisible = tiles.find((tile) => tile.getBoundingClientRect().bottom >= topbarOffset + 4);
                activeLabel = String(firstVisible?.dataset.monthLabel || tiles[0]?.dataset.monthLabel || '').trim();
            }
            const label = activeLabel;
            if (label !== '') {
                if (label !== lastLabel) {
                    overlay.textContent = label;
                    lastLabel = label;
                }
                overlay.hidden = false;
                overlay.classList.add('is-visible');
                hideOverlaySoon();
            }
            ticking = false;
        };
        const requestUpdate = () => {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(update);
            }
        };

        if (typeof window.__fcGalleryMonthOverlayScrollHandler === 'function') {
            window.removeEventListener('scroll', window.__fcGalleryMonthOverlayScrollHandler);
        }
        if (typeof window.__fcGalleryMonthOverlayResizeHandler === 'function') {
            window.removeEventListener('resize', window.__fcGalleryMonthOverlayResizeHandler);
        }
        window.__fcGalleryMonthOverlayScrollHandler = requestUpdate;
        window.__fcGalleryMonthOverlayResizeHandler = requestUpdate;
        window.addEventListener('scroll', window.__fcGalleryMonthOverlayScrollHandler, { passive: true });
        window.addEventListener('resize', window.__fcGalleryMonthOverlayResizeHandler, { passive: true });
    };

    const initGalleryImageStates = (scope = document) => {
        const images = scope.querySelectorAll('[data-gallery-image]:not([data-gallery-image-ready])');
        images.forEach((image) => {
            if (!(image instanceof HTMLImageElement)) {
                return;
            }
            image.dataset.galleryImageReady = '1';
            const tile = image.closest('.photos-gallery-tile');
            if (!(tile instanceof HTMLElement)) {
                return;
            }
            const markLoaded = () => {
                tile.classList.remove('is-image-loading', 'is-image-error');
                tile.querySelector('[data-gallery-image-error]')?.remove();
            };
            const markFailed = () => {
                tile.classList.remove('is-image-loading');
                tile.classList.add('is-image-error');
                if (tile.querySelector('[data-gallery-image-error]')) {
                    return;
                }
                const root = tile.closest('[data-gallery-recent-root]');
                const fallback = document.createElement('span');
                fallback.className = 'gallery-image-error';
                fallback.dataset.galleryImageError = '';
                fallback.textContent = String(root?.dataset.galleryImageErrorLabel || image.alt || 'Image unavailable');
                tile.appendChild(fallback);
            };
            image.addEventListener('load', markLoaded, { once: true });
            image.addEventListener('error', markFailed, { once: true });
            if (image.complete) {
                if (image.naturalWidth > 0) {
                    markLoaded();
                } else {
                    markFailed();
                }
            }
        });
    };

    const initCompactDisclosures = () => {
        document.querySelectorAll('[data-versus-details-toggle]:not([data-disclosure-ready])').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            button.dataset.disclosureReady = '1';
            button.addEventListener('click', () => {
                const detailId = String(button.getAttribute('aria-controls') || '');
                const detail = detailId !== '' ? document.getElementById(detailId) : button.parentElement?.querySelector('[data-versus-details]');
                if (!(detail instanceof HTMLElement)) {
                    return;
                }
                const expanded = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                button.classList.toggle('is-expanded', expanded);
                detail.hidden = !expanded;
                const label = button.querySelector('[data-versus-details-label]');
                if (label instanceof HTMLElement) {
                    label.textContent = expanded ? String(label.dataset.labelClose || '') : String(label.dataset.labelOpen || '');
                }
            });
        });
        document.querySelectorAll('[data-quest-detail-toggle]:not([data-disclosure-ready])').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            button.dataset.disclosureReady = '1';
            button.addEventListener('click', () => {
                const detail = button.closest('.quest-item')?.querySelector('[data-quest-detail]');
                if (!(detail instanceof HTMLElement)) {
                    return;
                }
                const expanded = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                button.classList.toggle('is-expanded', expanded);
                detail.hidden = !expanded;
            });
        });
    };

    const initGalleryRecentInfinite = () => {
        const root = document.querySelector('[data-gallery-recent-root]');
        if (!(root instanceof HTMLElement) || root.dataset.galleryRecentReady === '1') {
            return;
        }

        const grid = root.querySelector('[data-gallery-recent-grid]');
        const loadMoreButton = root.querySelector('[data-gallery-recent-load-more]');
        const loadError = root.querySelector('[data-gallery-load-error]');
        const loadRetryButton = root.querySelector('[data-gallery-load-retry]');
        const sentinel = root.querySelector('[data-gallery-recent-sentinel]');
        if (!(grid instanceof HTMLElement)) {
            return;
        }

        root.dataset.galleryRecentReady = '1';
        const endpoint = String(root.dataset.galleryRecentApi || '/?page=api_gallery_recent');
        const userId = Number.parseInt(String(root.dataset.galleryUserId || '0'), 10);
        const perPage = Number.parseInt(String(root.dataset.galleryPerPage || '96'), 10);
        const noPhotoLabel = String(root.dataset.galleryNoPhotoLabel || 'No photo');
        const photoLabel = String(root.dataset.galleryPhotoLabel || 'Photo');
        let nextPage = Number.parseInt(String(root.dataset.galleryNextPage || ''), 10);
        let hasMore = String(root.dataset.galleryHasMore || '0') === '1';
        let isLoading = false;
        let observer = null;
        let loadingSkeletons = [];

        const setLoadError = (visible) => {
            root.classList.toggle('has-load-error', visible);
            if (loadError instanceof HTMLElement) {
                loadError.hidden = !visible;
            }
        };

        const showLoadingSkeletons = () => {
            const fragment = document.createDocumentFragment();
            loadingSkeletons = Array.from({ length: 6 }, () => {
                const skeleton = document.createElement('span');
                skeleton.className = 'photos-gallery-tile gallery-tile-skeleton';
                skeleton.setAttribute('aria-hidden', 'true');
                fragment.appendChild(skeleton);
                return skeleton;
            });
            grid.appendChild(fragment);
        };
        const clearLoadingSkeletons = () => {
            loadingSkeletons.forEach((skeleton) => skeleton.remove());
            loadingSkeletons = [];
        };

        const setHasMore = (value) => {
            hasMore = value;
            root.dataset.galleryHasMore = value ? '1' : '0';
            if (loadMoreButton instanceof HTMLElement) {
                loadMoreButton.hidden = !value;
            }
            if (!value && observer instanceof IntersectionObserver) {
                observer.disconnect();
            }
        };

        const appendItems = (items) => {
            if (!Array.isArray(items)) {
                return;
            }
            const fragment = document.createDocumentFragment();
            items.forEach((item, itemIndex) => {
                if (!item || typeof item !== 'object') {
                    return;
                }
                const link = document.createElement('a');
                link.className = 'photos-gallery-tile';
                link.href = String(item.href || '#');
                const dateLabel = String(item.date_label || '');
                link.setAttribute('aria-label', `${photoLabel} ${dateLabel}`.trim());
                const monthLabel = String(item.month_label || '').trim();
                if (monthLabel !== '') {
                    link.dataset.monthLabel = monthLabel;
                }
                if (item.month_start) {
                    link.dataset.monthStart = '1';
                }

                const thumbUrl = String(item.thumb_url || '').trim();
                if (thumbUrl !== '') {
                    link.classList.add('is-image-loading');
                    const image = document.createElement('img');
                    image.src = thumbUrl;
                    const thumbSrcset = String(item.thumb_srcset || '').trim();
                    if (thumbSrcset !== '') {
                        image.srcset = thumbSrcset;
                    }
                    image.sizes = String(item.thumb_sizes || '(max-width: 700px) 33vw, (max-width: 1100px) 20vw, 170px');
                    image.width = 400;
                    image.height = 400;
                    image.alt = photoLabel;
                    image.loading = itemIndex < 6 ? 'eager' : 'lazy';
                    image.setAttribute('fetchpriority', itemIndex < 3 ? 'high' : 'low');
                    image.decoding = 'async';
                    image.dataset.galleryImage = '';
                    link.appendChild(image);
                } else {
                    const empty = document.createElement('span');
                    empty.className = 'entries-calendar-empty';
                    empty.textContent = noPhotoLabel;
                    link.appendChild(empty);
                }

                const dateNode = document.createElement('span');
                dateNode.className = 'photos-gallery-date';
                dateNode.textContent = dateLabel;
                link.appendChild(dateNode);
                fragment.appendChild(link);
            });
            grid.appendChild(fragment);
            initGalleryImageStates(grid);
            initGalleryMonthOverlay();
        };

        const loadNextPage = async () => {
            if (isLoading || !hasMore || !Number.isFinite(nextPage) || nextPage <= 0) {
                return;
            }
            isLoading = true;
            root.classList.add('is-loading');
            root.setAttribute('aria-busy', 'true');
            setLoadError(false);
            showLoadingSkeletons();
            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('user_id', String(Number.isFinite(userId) ? userId : 0));
                url.searchParams.set('gallery_page', String(nextPage));
                url.searchParams.set('gallery_per_page', String(Number.isFinite(perPage) && perPage > 0 ? perPage : 96));
                const response = await fetch(url.toString(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`Gallery recent request failed (${response.status})`);
                }
                const payload = await response.json();
                appendItems(Array.isArray(payload.items) ? payload.items : []);
                const payloadHasMore = Boolean(payload.has_more);
                const payloadNextPage = Number.parseInt(String(payload.next_page ?? ''), 10);
                nextPage = Number.isFinite(payloadNextPage) ? payloadNextPage : 0;
                root.dataset.galleryNextPage = Number.isFinite(payloadNextPage) ? String(payloadNextPage) : '';
                setHasMore(payloadHasMore && Number.isFinite(payloadNextPage) && payloadNextPage > 0);
                if (hasMore && observer instanceof IntersectionObserver && sentinel instanceof HTMLElement) {
                    observer.observe(sentinel);
                }
            } catch (error) {
                setLoadError(true);
                if (observer instanceof IntersectionObserver) {
                    observer.disconnect();
                }
            } finally {
                clearLoadingSkeletons();
                isLoading = false;
                root.classList.remove('is-loading');
                root.removeAttribute('aria-busy');
            }
        };

        if (loadMoreButton instanceof HTMLButtonElement) {
            loadMoreButton.addEventListener('click', () => {
                loadNextPage();
            });
        }
        if (loadRetryButton instanceof HTMLButtonElement) {
            loadRetryButton.addEventListener('click', loadNextPage);
        }

        if (window.IntersectionObserver && sentinel instanceof HTMLElement && hasMore) {
            observer = new IntersectionObserver((entries) => {
                const visible = entries.some((entry) => entry.isIntersecting);
                if (visible) {
                    loadNextPage();
                }
            }, {
                root: null,
                rootMargin: '900px 0px',
                threshold: 0.01,
            });
            observer.observe(sentinel);
        } else {
            setHasMore(hasMore);
        }
    };

    const initProfilePdfExport = () => {
        const button = document.querySelector('[data-profile-pdf-export]');
        const payloadNode = document.getElementById('profile-pdf-data');
        if (!(button instanceof HTMLButtonElement) || !(payloadNode instanceof HTMLScriptElement)) {
            return;
        }
        if (button.dataset.profilePdfReady === '1') {
            return;
        }
        button.dataset.profilePdfReady = '1';

        let payload = {};
        try {
            payload = JSON.parse(payloadNode.textContent || '{}');
        } catch {
            payload = {};
        }

        const localDeps = {
            jspdf: '/assets/vendor/jspdf.umd.min.js?v=2.5.1',
            autoTable: '/assets/vendor/jspdf.plugin.autotable.min.js?v=3.8.4',
            chart: '/assets/vendor/chart.umd.min.js?v=4.4.3',
        };
        const loadScript = (src) => new Promise((resolve, reject) => {
            const absoluteSrc = new URL(src, window.location.origin).href;
            const existing = Array.from(document.scripts).find((script) => script.src === absoluteSrc || script.getAttribute('src') === src);
            if (existing) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error(`Failed to load ${src}`));
            document.head.appendChild(script);
        });
        const ensureDeps = async () => {
            if (!window.jspdf || !window.jspdf.jsPDF) {
                await loadScript(localDeps.jspdf);
            }
            const jsPDF = window.jspdf?.jsPDF;
            if (!jsPDF) {
                throw new Error('jsPDF is not available.');
            }
            if (!jsPDF.API || !jsPDF.API.autoTable) {
                await loadScript(localDeps.autoTable);
            }
            if (!window.Chart) {
                await loadScript(localDeps.chart);
            }
        };

        const asObject = (value) => (value && typeof value === 'object' ? value : {});
        const i18n = asObject(payload.i18n);
        const labels = asObject(payload.labels);
        const text = (key, fallback) => {
            const value = i18n[key] ?? labels[key];
            const normalized = String(value ?? '').trim();
            if (normalized === '' || normalized === key || normalized.includes('.')) {
                return fallback;
            }
            return normalized;
        };
        const label = (key, fallback) => text(key, fallback);
        const noDataLabel = text('pdf_no_data', 'No data available.');
        const asNumber = (value, fallback = 0) => {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : fallback;
        };
        const hasValue = (value) => value !== null && value !== undefined && String(value).trim() !== '';
        const cleanText = (value, fallback = '-') => {
            const normalized = String(value ?? '').replace(/\s+/g, ' ').trim();
            return normalized === '' ? fallback : normalized;
        };
        const formatNumber = (value, decimals = 0) => asNumber(value, 0).toLocaleString(undefined, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
        const formatValue = (value, decimals = 0, unit = '') => {
            if (!hasValue(value)) {
                return '-';
            }
            const suffix = unit === '' ? '' : ` ${unit}`;
            return `${formatNumber(value, decimals)}${suffix}`;
        };
        const formatPercent = (value, decimals = 0) => `${formatNumber(value, decimals)}%`;
        const formatCurrency = (value) => `EUR ${formatNumber(value, 2)}`;
        const hasPositiveNumber = (value) => asNumber(value, 0) > 0;
        const formatDate = (value) => {
            const raw = String(value || '').trim();
            if (raw === '') {
                return '-';
            }
            const normalized = /^\d{4}-\d{2}-\d{2}$/.test(raw) ? `${raw}T00:00:00` : raw.replace(' ', 'T');
            const date = new Date(normalized);
            if (Number.isNaN(date.getTime())) {
                return raw;
            }
            return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
        };
        const formatDateRange = (start, end) => {
            const from = formatDate(start);
            const to = formatDate(end);
            if (from === '-' && to === '-') {
                return '-';
            }
            if (from === '-') {
                return to;
            }
            if (to === '-' || to === from) {
                return from;
            }
            return `${from} - ${to}`;
        };
        const normalizeStatus = (value) => {
            const status = String(value || '').trim();
            if (status === '') {
                return '-';
            }
            return status.charAt(0).toUpperCase() + status.slice(1).replaceAll('_', ' ');
        };
        const hexToRgba = (hex, alpha) => {
            const normalized = String(hex || '').replace('#', '');
            if (!/^[0-9a-f]{6}$/i.test(normalized)) {
                return `rgba(20, 163, 139, ${alpha})`;
            }
            const red = parseInt(normalized.slice(0, 2), 16);
            const green = parseInt(normalized.slice(2, 4), 16);
            const blue = parseInt(normalized.slice(4, 6), 16);
            return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
        };

        const buildChartImage = async ({ title, rows, color, type = 'line', beginAtZero = true }) => {
            if (!Array.isArray(rows) || rows.length === 0 || !window.Chart) {
                return null;
            }
            const chartRows = rows.filter((row) => row && typeof row === 'object');
            if (chartRows.length === 0) {
                return null;
            }
            const canvas = document.createElement('canvas');
            canvas.width = 1200;
            canvas.height = 430;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return null;
            }
            const backgroundPlugin = {
                id: 'profilePdfCanvasBackground',
                beforeDraw(chart) {
                    const { ctx: chartCtx, width, height } = chart;
                    chartCtx.save();
                    chartCtx.fillStyle = '#ffffff';
                    chartCtx.fillRect(0, 0, width, height);
                    chartCtx.restore();
                },
            };
            const chart = new window.Chart(ctx, {
                type,
                data: {
                    labels: chartRows.map((row) => cleanText(row.label, '')),
                    datasets: [{
                        label: title,
                        data: chartRows.map((row) => asNumber(row.value, 0)),
                        borderColor: color,
                        backgroundColor: hexToRgba(color, type === 'bar' ? 0.48 : 0.16),
                        borderWidth: 3,
                        fill: type !== 'bar',
                        tension: 0.28,
                        pointRadius: type === 'bar' ? 0 : 2,
                    }],
                },
                options: {
                    responsive: false,
                    animation: false,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: {
                            display: true,
                            text: title,
                            color: '#17212b',
                            font: { size: 22, weight: 'bold' },
                            padding: { top: 12, bottom: 18 },
                        },
                    },
                    scales: {
                        x: {
                            ticks: { color: '#65727e', maxRotation: 55, minRotation: 0, autoSkip: true },
                            grid: { display: false },
                        },
                        y: {
                            beginAtZero,
                            ticks: { color: '#65727e' },
                            grid: { color: 'rgba(101, 114, 126, 0.16)' },
                        },
                    },
                },
                plugins: [backgroundPlugin],
            });
            await new Promise((resolve) => window.requestAnimationFrame(resolve));
            const imageData = canvas.toDataURL('image/png');
            chart.destroy();
            return imageData;
        };

        button.addEventListener('click', async () => {
            if (button.disabled) {
                return;
            }
            const labelNode = button.querySelector('[data-profile-pdf-export-label]');
            const previousLabel = labelNode instanceof HTMLElement ? labelNode.textContent : button.textContent;
            const setButtonLabel = (value) => {
                if (labelNode instanceof HTMLElement) {
                    labelNode.textContent = value;
                } else {
                    button.textContent = value;
                }
            };

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            setButtonLabel(text('pdf_generating', 'Generating PDF...'));

            try {
                await ensureDeps();

                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ unit: 'pt', format: 'a4', compress: true });
                if (typeof pdf.autoTable !== 'function') {
                    throw new Error('jspdf-autotable is not available.');
                }

                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const margin = 36;
                const topMargin = 62;
                const bottomMargin = 46;
                const contentWidth = pageWidth - margin * 2;
                const colors = {
                    ink: [23, 33, 43],
                    muted: [101, 114, 126],
                    line: [217, 226, 221],
                    primary: [20, 163, 139],
                    primaryDark: [13, 125, 108],
                    navy: [17, 33, 51],
                    success: [22, 133, 91],
                    warning: [217, 119, 6],
                    danger: [190, 18, 60],
                    coral: [255, 107, 74],
                    soft: [238, 248, 246],
                    alt: [248, 251, 250],
                    paper: [253, 252, 249],
                };
                let y = margin;
                const username = cleanText(payload.username, cleanText(payload.display_name, 'user')).replace(/^@/, '');
                const displayName = cleanText(payload.display_name, username);
                const generatedAt = formatDate(payload.generated_at || new Date().toISOString());
                const challengeRange = asObject(payload.challenge_range);
                const challengeRangeLabel = `${formatDate(challengeRange.start)} - ${formatDate(challengeRange.end)}`;
                const config = asObject(payload.config);
                const totals = asObject(payload.totals);
                const totalSummary = { ...totals, ...asObject(payload.total_summary) };
                const charts = asObject(payload.charts);
                const weeklySummary = Array.isArray(payload.weekly_summary) ? payload.weekly_summary : [];
                const monthlySummary = Array.isArray(payload.monthly_summary) ? payload.monthly_summary : [];
                const allDailyDetails = Array.isArray(payload.daily_details) ? payload.daily_details : [];
                const allDailyNutrition = Array.isArray(payload.daily_photo_nutrition) ? payload.daily_photo_nutrition : [];
                const habitGoalCodes = new Set((Array.isArray(payload.habit_goal_codes) ? payload.habit_goal_codes : []).map((code) => String(code)));
                const pdfTitle = text('pdf_title', 'Challenge report');
                const sectionOverview = text('pdf_section_overview', 'Configuration and totals');
                const sectionWeekly = text('pdf_section_weekly', 'Weekly progress');
                const sectionMonthly = text('pdf_section_monthly', 'Monthly review');
                const sectionTotal = text('pdf_section_total', 'Total summary');
                const sectionDaily = text('pdf_section_daily', 'Daily details');
                const sectionNutrition = text('pdf_section_nutrition', 'Nutrition and photos');
                const sectionGoals = text('pdf_section_goals', 'Goals');
                const sectionAchievements = text('pdf_section_achievements', 'Achievements');

                const addPageIfNeeded = (needed = 24) => {
                    if (y + needed <= pageHeight - bottomMargin) {
                        return;
                    }
                    pdf.addPage();
                    y = topMargin;
                };
                const addSectionTitle = (title) => {
                    addPageIfNeeded(42);
                    pdf.setFillColor(...colors.soft);
                    pdf.setDrawColor(...colors.line);
                    pdf.roundedRect(margin, y, contentWidth, 30, 6, 6, 'FD');
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(12);
                    pdf.setTextColor(...colors.ink);
                    pdf.text(String(title), margin + 12, y + 20);
                    y += 42;
                };
                const addTableCaption = (title, subtitle = '') => {
                    const caption = cleanText(title, '');
                    const hint = cleanText(subtitle, '');
                    if (caption === '') {
                        return;
                    }
                    addPageIfNeeded(hint === '' ? 22 : 36);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(9.4);
                    pdf.setTextColor(...colors.navy);
                    pdf.text(caption, margin, y);
                    y += 12;
                    if (hint !== '') {
                        pdf.setFont('helvetica', 'normal');
                        pdf.setFontSize(7.4);
                        pdf.setTextColor(...colors.muted);
                        const wrapped = pdf.splitTextToSize(hint, contentWidth);
                        pdf.text(wrapped, margin, y);
                        y += wrapped.length * 9 + 4;
                    }
                };
                const addAutoTable = (head, body, options = {}) => {
                    if (options.caption) {
                        addTableCaption(options.caption, options.captionHint || '');
                    }
                    addPageIfNeeded(72);
                    const headCells = Array.isArray(head[0]) ? head[0].length : 1;
                    const tableBody = Array.isArray(body) && body.length > 0
                        ? body
                        : [[{ content: noDataLabel, colSpan: headCells, styles: { halign: 'center', textColor: colors.muted } }]];
                    pdf.autoTable({
                        startY: y,
                        head,
                        body: tableBody,
                        margin: { top: topMargin, right: margin, bottom: bottomMargin, left: margin },
                        theme: options.theme || 'striped',
                        showHead: 'everyPage',
                        styles: {
                            font: 'helvetica',
                            fontSize: options.fontSize ?? 8,
                            cellPadding: options.cellPadding ?? 4,
                            overflow: 'linebreak',
                            lineColor: colors.line,
                            lineWidth: 0.45,
                            textColor: colors.ink,
                            valign: 'top',
                        },
                        headStyles: {
                            fillColor: options.headColor ?? colors.navy,
                            textColor: [255, 255, 255],
                            fontStyle: 'bold',
                            fontSize: options.headFontSize ?? 7.6,
                        },
                        alternateRowStyles: { fillColor: options.alternateFill ?? colors.alt },
                        bodyStyles: { fillColor: colors.paper },
                        columnStyles: options.columnStyles || {},
                        didParseCell: options.didParseCell,
                        didDrawCell: options.didDrawCell,
                    });
                    y = (pdf.lastAutoTable?.finalY || y) + (options.afterSpacing ?? 16);
                };
                const drawMetricCards = (cards) => {
                    const columns = 3;
                    const gap = 10;
                    const cardWidth = (contentWidth - gap * (columns - 1)) / columns;
                    const cardHeight = 60;
                    addPageIfNeeded(Math.ceil(cards.length / columns) * (cardHeight + gap) + 10);
                    cards.forEach((card, index) => {
                        const col = index % columns;
                        const row = Math.floor(index / columns);
                        const cardX = margin + col * (cardWidth + gap);
                        const cardY = y + row * (cardHeight + gap);
                        pdf.setFillColor(255, 255, 255);
                        pdf.setDrawColor(...colors.line);
                        pdf.roundedRect(cardX, cardY, cardWidth, cardHeight, 7, 7, 'FD');
                        pdf.setFont('helvetica', 'bold');
                        pdf.setFontSize(7.6);
                        pdf.setTextColor(...colors.primaryDark);
                        pdf.text(String(card.label).toUpperCase(), cardX + 10, cardY + 17, { maxWidth: cardWidth - 20 });
                        pdf.setFontSize(14);
                        pdf.setTextColor(...colors.ink);
                        const valueLines = pdf.splitTextToSize(String(card.value), cardWidth - 20).slice(0, 2);
                        pdf.text(valueLines, cardX + 10, cardY + 39);
                    });
                    y += Math.ceil(cards.length / columns) * (cardHeight + gap) - gap + 22;
                };
                const addTextBlock = (lines, options = {}) => {
                    const lineHeight = options.lineHeight ?? 12;
                    pdf.setFont('helvetica', options.font || 'normal');
                    pdf.setFontSize(options.fontSize ?? 9);
                    pdf.setTextColor(...(options.color || colors.ink));
                    lines.forEach((line) => {
                        const wrapped = pdf.splitTextToSize(String(line), contentWidth);
                        wrapped.forEach((chunk) => {
                            addPageIfNeeded(lineHeight);
                            pdf.text(chunk, margin, y);
                            y += lineHeight;
                        });
                    });
                };
                const addChartPanel = async (chartDef) => {
                    const rows = Array.isArray(chartDef.rows) ? chartDef.rows : [];
                    const image = await buildChartImage({ ...chartDef, rows });
                    if (!image) {
                        return false;
                    }
                    if (chartDef.caption) {
                        addTableCaption(chartDef.caption);
                    }
                    addPageIfNeeded(212);
                    pdf.setDrawColor(...colors.line);
                    pdf.setFillColor(255, 255, 255);
                    pdf.roundedRect(margin, y, contentWidth, 188, 7, 7, 'FD');
                    pdf.addImage(image, 'PNG', margin + 7, y + 8, contentWidth - 14, 171);
                    y += 204;
                    return true;
                };
                const addDocumentChrome = () => {
                    const pageCount = pdf.internal.getNumberOfPages();
                    for (let pageNumber = 1; pageNumber <= pageCount; pageNumber += 1) {
                        pdf.setPage(pageNumber);
                        pdf.setDrawColor(...colors.line);
                        pdf.setTextColor(...colors.muted);
                        pdf.setFont('helvetica', 'normal');
                        pdf.setFontSize(8);
                        if (pageNumber > 1) {
                            pdf.text(pdfTitle, margin, 28);
                            pdf.text(`${displayName} (@${username})`, pageWidth - margin, 28, { align: 'right' });
                            pdf.line(margin, 38, pageWidth - margin, 38);
                        }
                        pdf.line(margin, pageHeight - 34, pageWidth - margin, pageHeight - 34);
                        pdf.text(`${displayName} (@${username})`, margin, pageHeight - 18, { maxWidth: contentWidth * 0.7 });
                        pdf.text(`${pageNumber} / ${pageCount}`, pageWidth - margin, pageHeight - 18, { align: 'right' });
                    }
                };

                const progressBar = (value) => {
                    const pct = Math.max(0, Math.min(100, asNumber(value, 0)));
                    const filled = Math.round(pct / 10);
                    return `[${'#'.repeat(filled)}${'.'.repeat(10 - filled)}] ${formatPercent(pct, 0)}`;
                };
                const approvalSummary = (day) => {
                    const rows = [];
                    const pushApproval = (title, status, detail) => {
                        if (!hasValue(status) && !hasValue(detail)) {
                            return;
                        }
                        const detailParts = [
                            hasValue(status) ? normalizeStatus(status) : '',
                            hasValue(detail) ? cleanText(detail, '') : '',
                        ].filter(Boolean);
                        rows.push(`${title}: ${detailParts.join(' - ')}`);
                    };
                    pushApproval(label('steps', 'Steps'), day.approval_step_status, day.approval_step_detail);
                    pushApproval(label('workouts', 'Workouts'), day.approval_workout_status, day.approval_workout_detail);
                    pushApproval(label('extra_workout', 'Extra workout'), day.approval_extra_status, day.approval_extra_detail);
                    return rows.join('\n');
                };
                const habitSummary = (day) => {
                    const habits = Array.isArray(day.habits) ? day.habits : [];
                    return habits.map((habit) => {
                        const code = String(habit.code || '');
                        const habitLabel = cleanText(habit.label || code, '');
                        if (habitLabel === '') {
                            return '';
                        }
                        if (Number(habit.value) === 1) {
                            return habitLabel;
                        }
                        return habitGoalCodes.has(code) ? `${habitLabel}: 0` : '';
                    }).filter(Boolean).join(', ');
                };
                const flagSummary = (day) => [
                    Number(day.junk_food) === 1 ? label('junk_food', 'Junk food') : '',
                    Number(day.extra_workout) === 1 ? label('extra_workout', 'Extra workout') : '',
                ].filter(Boolean).join('\n');
                const noteSummary = (day) => {
                    const habits = habitSummary(day);
                    return [
                        hasValue(day.missing_reason) ? `${text('pdf_missing_reason', 'Missing reason')}: ${cleanText(day.missing_reason)}` : '',
                        hasValue(day.junk_food_reason) ? `${label('junk_food', 'Junk food')}: ${cleanText(day.junk_food_reason)}` : '',
                        hasValue(day.notes) ? cleanText(day.notes) : '',
                        habits !== '' ? `${text('pdf_habits', 'Habits')}: ${habits}` : '',
                    ].filter(Boolean).join('\n');
                };
                const dailyHasReportInput = (day) => {
                    const workouts = Array.isArray(day.workout_types) ? day.workout_types : [];
                    const habits = Array.isArray(day.habits) ? day.habits : [];
                    return hasPositiveNumber(day.steps)
                        || hasPositiveNumber(day.distance_km)
                        || hasPositiveNumber(day.workout_count)
                        || hasPositiveNumber(day.workout_counted)
                        || workouts.length > 0
                        || hasPositiveNumber(day.training_calories_burned)
                        || hasValue(day.weight)
                        || Number(day.junk_food) === 1
                        || Number(day.extra_workout) === 1
                        || hasValue(day.missing_reason)
                        || hasValue(day.junk_food_reason)
                        || hasValue(day.notes)
                        || approvalSummary(day) !== ''
                        || habits.some((habit) => Number(habit.value) === 1 || habitGoalCodes.has(String(habit.code || '')));
                };
                const nutritionHasReportInput = (day) => {
                    const dayTotals = asObject(day.totals);
                    return hasPositiveNumber(day.photo_count)
                        || (Array.isArray(day.items) && day.items.length > 0)
                        || Object.values(dayTotals).some((value) => hasPositiveNumber(value));
                };
                const dailyDetails = allDailyDetails.filter(dailyHasReportInput);
                const dailyNutrition = allDailyNutrition.filter(nutritionHasReportInput);
                const totalNutrition = asObject(totalSummary.nutrition);

                pdf.setFillColor(...colors.navy);
                pdf.rect(0, 0, pageWidth, 134, 'F');
                pdf.setFillColor(...colors.primary);
                pdf.rect(0, 0, 9, 134, 'F');
                pdf.setFillColor(...colors.coral);
                pdf.rect(9, 0, 4, 134, 'F');
                pdf.setTextColor(255, 255, 255);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(23);
                pdf.text(pdfTitle, margin, 44, { maxWidth: contentWidth * 0.62 });
                pdf.setFontSize(15);
                pdf.text(displayName, margin, 75, { maxWidth: contentWidth * 0.62 });
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(10);
                pdf.text(`@${username}`, margin, 96);
                pdf.setFontSize(9);
                pdf.text(`${text('pdf_generated', 'Generated')}: ${generatedAt}`, pageWidth - margin, 54, { align: 'right' });
                pdf.text(`${text('pdf_challenge_range', 'Challenge range')}: ${challengeRangeLabel}`, pageWidth - margin, 78, { align: 'right' });
                y = 160;

                drawMetricCards([
                    { label: label('input_days', 'Input days'), value: formatNumber(totalSummary.input_days ?? dailyDetails.length, 0) },
                    { label: label('steps', 'Steps'), value: formatNumber(totalSummary.steps ?? totals.steps, 0) },
                    { label: label('distance', 'Distance'), value: formatValue(totalSummary.distance_km ?? totals.distance_km, 2, 'km') },
                    { label: label('workouts', 'Workouts'), value: formatNumber(totalSummary.workouts ?? totals.workouts, 0) },
                    { label: label('progress', 'Progress'), value: formatPercent(totalSummary.avg_progress_pct ?? 0, 0) },
                    { label: label('penalty', 'Penalty'), value: formatCurrency(totalSummary.penalty ?? totals.penalty) },
                ]);

                addTableCaption(text('pdf_executive_summary', 'Executive summary'));
                addTextBlock([
                    `${displayName} (@${username}) - ${text('pdf_challenge_range', 'Challenge range')}: ${challengeRangeLabel}.`,
                    `${label('input_days', 'Input days')}: ${formatNumber(totalSummary.input_days ?? dailyDetails.length, 0)}. ${label('photo_days', 'Photo days')}: ${formatNumber(totalSummary.photo_days ?? dailyNutrition.length, 0)}. ${label('progress', 'Progress')}: ${formatPercent(totalSummary.avg_progress_pct ?? 0, 0)}.`,
                    `${label('steps', 'Steps')}: ${formatNumber(totalSummary.steps ?? totals.steps, 0)}. ${label('distance', 'Distance')}: ${formatValue(totalSummary.distance_km ?? totals.distance_km, 2, 'km')}. ${label('workouts', 'Workouts')}: ${formatNumber(totalSummary.workouts ?? totals.workouts, 0)}.`,
                    hasValue(totalSummary.weight_change) ? `${label('weight_change', 'Weight change')}: ${formatValue(totalSummary.weight_change, 1, 'kg')}.` : '',
                ].filter(Boolean), { color: colors.ink, fontSize: 8.8, lineHeight: 12 });
                y += 8;

                addSectionTitle(`1. ${sectionOverview}`);
                addAutoTable(
                    [[text('pdf_current_setup', 'Current setup'), '']],
                    [
                        [label('primary_goal', 'Primary daily goal'), cleanText(config.primary_goal_type)],
                        [label('primary_goal_value', 'Goal value'), formatValue(config.primary_goal_value, 2)],
                        [label('primary_goals_spec', 'Multi-goals'), cleanText(config.primary_goals_spec)],
                        [label('workout_target', 'Workout target'), formatValue(config.workout_target, 0)],
                        [label('maintenance_calories', 'Maintenance calories'), formatValue(config.maintenance_calories, 0, 'kcal')],
                        [label('calorie_burn_goal', 'Daily burn goal'), formatValue(config.calorie_burn_goal, 0, 'kcal')],
                        [label('calorie_consumed_max', 'Daily max consumed'), formatValue(config.calorie_consumed_max, 0, 'kcal')],
                        [label('ideal_weight', 'Ideal weight'), formatValue(config.ideal_weight, 1, 'kg')],
                    ],
                    {
                        caption: text('pdf_current_setup', 'Current setup'),
                        fontSize: 8.4,
                        columnStyles: { 0: { cellWidth: 178, fontStyle: 'bold' }, 1: { cellWidth: contentWidth - 178 } },
                    }
                );

                addSectionTitle(`2. ${sectionWeekly}`);
                addAutoTable(
                    [[
                        label('week', 'Week'),
                        label('status', 'Status'),
                        label('steps', 'Steps'),
                        'Km',
                        label('workouts', 'Workouts'),
                        label('compliance', 'Compliance'),
                        label('progress', 'Progress'),
                        label('failures', 'Failures'),
                        label('penalty', 'Penalty'),
                    ]],
                    weeklySummary.map((week) => [
                        formatDateRange(week.week_start, week.week_end),
                        normalizeStatus(week.status),
                        formatNumber(week.steps, 0),
                        formatNumber(week.distance_km, 2),
                        formatNumber(week.workouts, 0),
                        `${label('steps', 'Steps')}: ${formatNumber(week.step_success, 0)} / ${formatNumber(week.step_required, 0)}\n${label('workouts', 'Workouts')}: ${formatNumber(week.workout_success, 0)} / ${formatNumber(week.workout_target, 0)}\n${label('strikes', 'Strikes')}: ${formatNumber(week.strikes_after_week, 0)}`,
                        progressBar(week.progress_pct),
                        formatNumber(week.failures, 0),
                        formatCurrency(week.penalty),
                    ]),
                    {
                        caption: text('pdf_weekly_progress_table', 'Weekly compliance table'),
                        fontSize: 6.7,
                        cellPadding: 3,
                        columnStyles: {
                            0: { cellWidth: 74 },
                            1: { cellWidth: 48 },
                            2: { cellWidth: 50, halign: 'right' },
                            3: { cellWidth: 38, halign: 'right' },
                            4: { cellWidth: 38, halign: 'right' },
                            5: { cellWidth: 96 },
                            6: { cellWidth: 86 },
                            7: { cellWidth: 38, halign: 'right' },
                            8: { cellWidth: 55, halign: 'right' },
                        },
                    }
                );
                let renderedWeeklyCharts = 0;
                if (await addChartPanel({
                    title: text('pdf_chart_weekly_progress', 'Weekly progress'),
                    caption: text('pdf_chart_weekly_progress', 'Weekly progress'),
                    rows: Array.isArray(charts.weekly_progress) ? charts.weekly_progress : [],
                    color: '#14a38b',
                    type: 'bar',
                    beginAtZero: true,
                })) {
                    renderedWeeklyCharts += 1;
                }
                if (await addChartPanel({
                    title: label('score', 'Score'),
                    caption: label('score', 'Score'),
                    rows: Array.isArray(charts.score) ? charts.score : [],
                    color: '#0f766e',
                    type: 'line',
                    beginAtZero: true,
                })) {
                    renderedWeeklyCharts += 1;
                }
                if (renderedWeeklyCharts === 0) {
                    addTextBlock([noDataLabel], { color: colors.muted });
                    y += 8;
                }

                addSectionTitle(`3. ${sectionMonthly}`);
                addAutoTable(
                    [[
                        label('month', 'Month'),
                        label('input_days', 'Input days'),
                        label('photo_days', 'Photo days'),
                        label('steps', 'Steps'),
                        'Km',
                        label('workouts', 'Workouts'),
                        label('calories_consumed', 'Calories'),
                        label('average_weight', 'Avg weight'),
                        label('weight_change', 'Change'),
                        label('progress', 'Progress'),
                    ]],
                    monthlySummary.map((month) => [
                        cleanText(month.label || month.month),
                        formatNumber(month.input_days, 0),
                        formatNumber(month.photo_days, 0),
                        formatNumber(month.steps, 0),
                        formatNumber(month.distance_km, 2),
                        formatNumber(month.workouts, 0),
                        formatNumber(month.calories, 0),
                        formatValue(month.avg_weight, 1, 'kg'),
                        formatValue(month.weight_change, 1, 'kg'),
                        progressBar(month.progress_pct),
                    ]),
                    {
                        caption: text('pdf_monthly_summary_table', 'Monthly summary table'),
                        fontSize: 6.5,
                        cellPadding: 3,
                        columnStyles: {
                            0: { cellWidth: 78 },
                            1: { cellWidth: 40, halign: 'right' },
                            2: { cellWidth: 40, halign: 'right' },
                            3: { cellWidth: 54, halign: 'right' },
                            4: { cellWidth: 44, halign: 'right' },
                            5: { cellWidth: 44, halign: 'right' },
                            6: { cellWidth: 54, halign: 'right' },
                            7: { cellWidth: 54, halign: 'right' },
                            8: { cellWidth: 50, halign: 'right' },
                            9: { cellWidth: 65 },
                        },
                    }
                );
                const monthlyChartDefs = [
                    { title: text('pdf_chart_monthly_progress', 'Monthly progress'), caption: text('pdf_chart_monthly_progress', 'Monthly progress'), rows: charts.monthly_progress, color: '#14a38b', type: 'bar', beginAtZero: true },
                    { title: text('pdf_chart_monthly_steps', 'Monthly steps'), caption: text('pdf_chart_monthly_steps', 'Monthly steps'), rows: charts.monthly_steps, color: '#2563eb', type: 'bar', beginAtZero: true },
                    { title: text('pdf_chart_monthly_workouts', 'Monthly workouts'), caption: text('pdf_chart_monthly_workouts', 'Monthly workouts'), rows: charts.monthly_workouts, color: '#be185d', type: 'bar', beginAtZero: true },
                    { title: text('pdf_chart_nutrition_calories', 'Nutrition calories'), caption: text('pdf_chart_nutrition_calories', 'Nutrition calories'), rows: charts.nutrition_calories, color: '#d97706', type: 'bar', beginAtZero: true },
                ];
                for (const chartDef of monthlyChartDefs) {
                    await addChartPanel(chartDef);
                }
                monthlySummary.forEach((month) => {
                    addAutoTable(
                        [[label('category', 'Category'), label('total', 'Total'), label('category', 'Category'), label('total', 'Total')]],
                        [
                            [label('input_days', 'Input days'), formatNumber(month.input_days, 0), label('photo_days', 'Photo days'), formatNumber(month.photo_days, 0)],
                            [label('steps', 'Steps'), formatNumber(month.steps, 0), label('distance', 'Distance'), formatValue(month.distance_km, 2, 'km')],
                            [label('workouts', 'Workouts'), formatNumber(month.workouts, 0), label('training_calories_burned', 'Burned'), formatValue(month.training_calories_burned, 0, 'kcal')],
                            [label('photos', 'Photos'), formatNumber(month.photo_count, 0), label('calories_consumed', 'Calories'), formatValue(month.calories, 0, 'kcal')],
                            [label('average_weight', 'Avg weight'), formatValue(month.avg_weight, 1, 'kg'), label('weight_change', 'Change'), formatValue(month.weight_change, 1, 'kg')],
                            [label('progress', 'Progress'), progressBar(month.progress_pct), '', ''],
                        ],
                        {
                            caption: `${cleanText(month.label || month.month)} ${text('pdf_monthly_summary_table', 'monthly summary')}`,
                            fontSize: 7,
                            cellPadding: 3,
                            afterSpacing: 10,
                            columnStyles: {
                                0: { cellWidth: 126, fontStyle: 'bold' },
                                1: { cellWidth: 136 },
                                2: { cellWidth: 126, fontStyle: 'bold' },
                                3: { cellWidth: contentWidth - 388 },
                            },
                        }
                    );
                });

                addSectionTitle(`4. ${sectionTotal}`);
                addAutoTable(
                    [[label('category', 'Category'), label('total', 'Total')]],
                    [
                        [label('input_days', 'Input days'), formatNumber(totalSummary.input_days ?? dailyDetails.length, 0)],
                        [label('photo_days', 'Photo days'), formatNumber(totalSummary.photo_days ?? dailyNutrition.length, 0)],
                        [label('photos', 'Photos'), formatNumber(totalSummary.photo_count ?? 0, 0)],
                        [label('steps', 'Steps'), formatNumber(totalSummary.steps ?? totals.steps, 0)],
                        [label('distance', 'Distance'), formatValue(totalSummary.distance_km ?? totals.distance_km, 2, 'km')],
                        [label('workouts', 'Workouts'), formatNumber(totalSummary.workouts ?? totals.workouts, 0)],
                        [label('training_calories_burned', 'Burned'), formatValue(totalSummary.training_calories_burned, 0, 'kcal')],
                        [label('calories_consumed', 'Calories'), formatValue(totalNutrition.calories, 0, 'kcal')],
                        [label('protein', 'Protein'), formatValue(totalNutrition.protein_g, 1, 'g')],
                        [label('carbs', 'Carbs'), formatValue(totalNutrition.carbs_g, 1, 'g')],
                        [label('fat', 'Fat'), formatValue(totalNutrition.fat_g, 1, 'g')],
                        [label('average_weight', 'Avg weight'), formatValue(totalSummary.avg_weight, 1, 'kg')],
                        [label('weight_change', 'Weight change'), formatValue(totalSummary.weight_change, 1, 'kg')],
                        [label('progress', 'Progress'), progressBar(totalSummary.avg_progress_pct ?? 0)],
                        [label('failures', 'Failures'), formatNumber(totalSummary.failures, 0)],
                        [label('strikes', 'Strikes'), formatNumber(totalSummary.strikes ?? totals.strikes, 0)],
                        [label('penalty', 'Penalty'), formatCurrency(totalSummary.penalty ?? totals.penalty)],
                    ],
                    {
                        caption: text('pdf_total_summary_table', 'Total summary table'),
                        fontSize: 8,
                        columnStyles: { 0: { cellWidth: 208, fontStyle: 'bold' }, 1: { cellWidth: contentWidth - 208 } },
                    }
                );
                const totalChartDefs = [
                    { title: label('steps', 'Steps'), caption: label('steps', 'Steps'), rows: charts.steps, color: '#14a38b', type: 'line', beginAtZero: true },
                    { title: label('distance', 'Distance'), caption: label('distance', 'Distance'), rows: charts.distance, color: '#2563eb', type: 'line', beginAtZero: true },
                    { title: label('workouts', 'Workouts'), caption: label('workouts', 'Workouts'), rows: charts.workouts, color: '#be185d', type: 'bar', beginAtZero: true },
                    { title: label('weight', 'Weight'), caption: label('weight', 'Weight'), rows: charts.weight, color: '#334155', type: 'line', beginAtZero: false },
                ];
                for (const chartDef of totalChartDefs) {
                    await addChartPanel(chartDef);
                }

                addSectionTitle(`5. ${sectionDaily}`);
                addAutoTable(
                    [[
                        label('date', 'Date'),
                        label('steps', 'Steps'),
                        'Km',
                        label('workouts', 'Workouts'),
                        label('training_calories_burned', 'Burned'),
                        label('weight', 'Weight'),
                        text('pdf_flags_notes', 'Flags, approvals and notes'),
                    ]],
                    dailyDetails.map((day) => {
                        const workoutTypes = Array.isArray(day.workout_types) ? day.workout_types.map((workout) => cleanText(workout, '')).filter(Boolean) : [];
                        const workoutSummary = [
                            hasPositiveNumber(day.workout_count) || hasPositiveNumber(day.workout_counted)
                                ? `${formatNumber(day.workout_count, 0)} / ${formatNumber(day.workout_counted, 0)}`
                                : '',
                            workoutTypes.join(', '),
                        ].filter(Boolean).join('\n');
                        const flagsNotes = [flagSummary(day), approvalSummary(day), noteSummary(day)].filter(Boolean).join('\n');
                        return [
                            formatDate(day.date),
                            formatNumber(day.steps, 0),
                            formatNumber(day.distance_km, 2),
                            workoutSummary,
                            formatValue(day.training_calories_burned, 0, 'kcal'),
                            formatValue(day.weight, 1, 'kg'),
                            flagsNotes,
                        ];
                    }),
                    {
                        caption: text('pdf_daily_input_table', 'Daily input table'),
                        fontSize: 6.8,
                        cellPadding: 3,
                        columnStyles: {
                            0: { cellWidth: 58 },
                            1: { cellWidth: 50, halign: 'right' },
                            2: { cellWidth: 38, halign: 'right' },
                            3: { cellWidth: 88 },
                            4: { cellWidth: 54, halign: 'right' },
                            5: { cellWidth: 44, halign: 'right' },
                            6: { cellWidth: contentWidth - 332 },
                        },
                    }
                );

                addSectionTitle(`6. ${sectionNutrition}`);
                addAutoTable(
                    [[
                        label('date', 'Date'),
                        label('photos', 'Photos'),
                        label('calories', 'Calories'),
                        label('protein', 'Protein'),
                        label('carbs', 'Carbs'),
                        label('fat', 'Fat'),
                        label('fiber', 'Fiber'),
                        label('sugar', 'Sugar'),
                        label('sodium', 'Sodium'),
                    ]],
                    dailyNutrition.map((day) => {
                        const totalsByDay = asObject(day.totals);
                        return [
                            formatDate(day.date),
                            formatNumber(day.photo_count, 0),
                            formatNumber(totalsByDay.calories, 0),
                            formatValue(totalsByDay.protein_g, 1, 'g'),
                            formatValue(totalsByDay.carbs_g, 1, 'g'),
                            formatValue(totalsByDay.fat_g, 1, 'g'),
                            formatValue(totalsByDay.fiber_g, 1, 'g'),
                            formatValue(totalsByDay.sugar_g, 1, 'g'),
                            formatValue(totalsByDay.sodium_mg, 0, 'mg'),
                        ];
                    }),
                    {
                        caption: text('pdf_nutrition_day_table', 'Nutrition day table'),
                        fontSize: 6.9,
                        cellPadding: 3,
                        columnStyles: {
                            0: { cellWidth: 56 },
                            1: { cellWidth: 42, halign: 'right' },
                            2: { cellWidth: 46, halign: 'right' },
                            3: { cellWidth: 56, halign: 'right' },
                            4: { cellWidth: 56, halign: 'right' },
                            5: { cellWidth: 52, halign: 'right' },
                            6: { cellWidth: 52, halign: 'right' },
                            7: { cellWidth: 52, halign: 'right' },
                            8: { cellWidth: contentWidth - 412, halign: 'right' },
                        },
                    }
                );
                const foodRows = [];
                dailyNutrition.forEach((day) => {
                    const items = Array.isArray(day.items) ? day.items : [];
                    items.forEach((item) => {
                        foodRows.push([
                            formatDate(day.date),
                            cleanText(item.category),
                            cleanText(item.caption),
                            formatValue(item.calories, 0),
                            formatValue(item.protein_g, 1, 'g'),
                            formatValue(item.carbs_g, 1, 'g'),
                            formatValue(item.fat_g, 1, 'g'),
                            formatValue(item.fiber_g, 1, 'g'),
                            formatValue(item.sugar_g, 1, 'g'),
                            formatValue(item.sodium_mg, 0, 'mg'),
                        ]);
                    });
                });
                addAutoTable(
                    [[
                        label('date', 'Date'),
                        label('category', 'Category'),
                        label('caption', 'Caption'),
                        label('calories', 'Calories'),
                        label('protein', 'Protein'),
                        label('carbs', 'Carbs'),
                        label('fat', 'Fat'),
                        label('fiber', 'Fiber'),
                        label('sugar', 'Sugar'),
                        label('sodium', 'Sodium'),
                    ]],
                    foodRows,
                    {
                        caption: text('pdf_food_items', 'Food items'),
                        fontSize: 6.6,
                        cellPadding: 3,
                        columnStyles: {
                            0: { cellWidth: 50 },
                            1: { cellWidth: 54 },
                            2: { cellWidth: contentWidth - 388 },
                            3: { cellWidth: 38, halign: 'right' },
                            4: { cellWidth: 42, halign: 'right' },
                            5: { cellWidth: 42, halign: 'right' },
                            6: { cellWidth: 38, halign: 'right' },
                            7: { cellWidth: 40, halign: 'right' },
                            8: { cellWidth: 40, halign: 'right' },
                            9: { cellWidth: 44, halign: 'right' },
                        },
                    }
                );

                addSectionTitle(`7. ${sectionGoals}`);
                const goals = Array.isArray(payload.goals) ? payload.goals : [];
                addAutoTable(
                    [[
                        label('goal_name', 'Goal name'),
                        label('type', 'Type'),
                        label('current', 'Current'),
                        label('target', 'Target'),
                        label('progress', 'Progress'),
                        label('status', 'Status'),
                        label('due_date', 'Due date'),
                    ]],
                    goals.map((goal) => [
                        cleanText(goal.title),
                        cleanText(goal.target_type),
                        cleanText(goal.current_label, formatValue(goal.current_value, 1)),
                        cleanText(goal.target_label, formatValue(goal.target_value, 1)),
                        progressBar(goal.progress_pct),
                        cleanText(goal.status_label, normalizeStatus(goal.status || 'active')),
                        formatDate(goal.due_date || ''),
                    ]),
                    {
                        caption: text('pdf_goals_table', 'Goals table'),
                        fontSize: 6.9,
                        columnStyles: {
                            0: { cellWidth: 124 },
                            1: { cellWidth: 70 },
                            2: { cellWidth: 60, halign: 'right' },
                            3: { cellWidth: 60, halign: 'right' },
                            4: { cellWidth: 80 },
                            5: { cellWidth: 62 },
                            6: { cellWidth: contentWidth - 456 },
                        },
                    }
                );

                addSectionTitle(`8. ${sectionAchievements}`);
                const achievements = Array.isArray(payload.achievements) ? payload.achievements : [];
                addAutoTable(
                    [[label('achievement_name', 'Name'), label('description', 'Description'), label('reward', 'Reward'), label('date', 'Date')]],
                    achievements.map((achievement) => [
                        cleanText(achievement.name),
                        cleanText(achievement.description),
                        cleanText(achievement.reward_text),
                        formatDate(achievement.awarded_at || ''),
                    ]),
                    {
                        caption: text('pdf_achievements_table', 'Achievements table'),
                        fontSize: 7.1,
                        columnStyles: {
                            0: { cellWidth: 122 },
                            1: { cellWidth: contentWidth - 318 },
                            2: { cellWidth: 98 },
                            3: { cellWidth: 98 },
                        },
                    }
                );

                addDocumentChrome();
                const safeUsername = username.replace(/[^a-z0-9_-]/gi, '-').toLowerCase() || 'user';
                const today = new Date().toISOString().slice(0, 10);
                pdf.save(`challenge-report-${safeUsername}-${today}.pdf`);
            } catch (error) {
                console.error('PDF export failed', error);
                window.alert(text('pdf_export_failed', 'Could not generate the PDF right now.'));
            } finally {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                setButtonLabel(previousLabel || text('export_pdf', 'Export user data to PDF'));
            }
        });
    };

    const initProfilePdfExportLegacy = () => {
        const button = document.querySelector('[data-profile-pdf-export]');
        const payloadNode = document.getElementById('profile-pdf-data');
        if (!(button instanceof HTMLButtonElement) || !(payloadNode instanceof HTMLScriptElement)) {
            return;
        }

        let payload = {};
        try {
            payload = JSON.parse(payloadNode.textContent || '{}');
        } catch {
            payload = {};
        }

        const loadScript = (src) => new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error(`Failed to load ${src}`));
            document.head.appendChild(script);
        });

        const ensureDeps = async () => {
            if (!window.jspdf) {
                await loadScript('/assets/vendor/jspdf.umd.min.js?v=2.5.1');
            }
            if (!window.Chart) {
                await loadScript('/assets/vendor/chart.umd.min.js?v=4.4.3');
            }
        };

        const asNumber = (value, fallback = 0) => {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : fallback;
        };

        const formatNumber = (value, decimals = 0) => {
            const parsed = asNumber(value, 0);
            return parsed.toLocaleString(undefined, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
        };

        const formatDate = (value) => {
            const text = String(value || '').trim();
            if (!text) {
                return '-';
            }
            const date = new Date(text);
            if (Number.isNaN(date.getTime())) {
                return text;
            }
            return date.toLocaleDateString();
        };

        const buildChartImage = async ({ title, rows, color, type = 'line' }) => {
            if (!Array.isArray(rows) || rows.length === 0) {
                return null;
            }
            const labels = rows.map((row) => String(row.label || ''));
            const values = rows.map((row) => asNumber(row.value, 0));

            const canvas = document.createElement('canvas');
            canvas.width = 1120;
            canvas.height = 360;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return null;
            }

            const chart = new Chart(ctx, {
                type,
                data: {
                    labels,
                    datasets: [{
                        label: title,
                        data: values,
                        borderColor: color,
                        backgroundColor: `${color}2B`,
                        borderWidth: 2,
                        fill: type !== 'bar',
                        tension: 0.25,
                    }],
                },
                options: {
                    responsive: false,
                    animation: false,
                    plugins: { legend: { display: true, position: 'bottom' } },
                    scales: { y: { beginAtZero: true } },
                },
            });

            await new Promise((resolve) => window.setTimeout(resolve, 40));
            const imageData = canvas.toDataURL('image/png');
            chart.destroy();
            return imageData;
        };

        button.addEventListener('click', async () => {
            if (button.disabled) {
                return;
            }
            button.disabled = true;
            const previousLabel = button.textContent;
            const i18n = payload.i18n || {};
            button.textContent = String(i18n.pdf_generating || 'Generando PDF...');

            try {
                await ensureDeps();

                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ unit: 'pt', format: 'a4' });
                const width = pdf.internal.pageSize.getWidth();
                const height = pdf.internal.pageSize.getHeight();
                const margin = 34;
                const contentWidth = width - margin * 2;
                let y = margin;

                const addPageIfNeeded = (needed = 24) => {
                    if (y + needed <= height - margin) {
                        return;
                    }
                    pdf.addPage();
                    y = margin;
                };

                const addLines = (lines, { font = 'normal', size = 10, lineHeight = 12 } = {}) => {
                    pdf.setFont('helvetica', font);
                    pdf.setFontSize(size);
                    lines.forEach((line) => {
                        const chunks = pdf.splitTextToSize(String(line), contentWidth);
                        chunks.forEach((chunk) => {
                            addPageIfNeeded(lineHeight);
                            pdf.text(chunk, margin, y);
                            y += lineHeight;
                        });
                    });
                };

                const addSectionTitle = (title) => {
                    addPageIfNeeded(30);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(14);
                    pdf.text(String(title), margin, y);
                    y += 16;
                };

                const username = String(payload.username || 'user').trim() || 'user';
                const displayName = String(payload.display_name || username);
                const generatedAt = formatDate(payload.generated_at || new Date().toISOString());
                const challengeRange = payload.challenge_range || {};
                const challengeRangeLabel = `${formatDate(challengeRange.start)} - ${formatDate(challengeRange.end)}`;
                const config = payload.config || {};
                const totals = payload.totals || {};
                const pdfTitle = String(i18n.pdf_title || 'Challenge report');
                const sectionOverview = String(i18n.pdf_section_overview || 'Configuration and totals');
                const sectionCharts = String(i18n.pdf_section_charts || 'Weekly charts');
                const sectionDaily = String(i18n.pdf_section_daily || 'Daily details');
                const sectionNutrition = String(i18n.pdf_section_nutrition || 'Nutrition and photos');
                const sectionActivity = String(i18n.pdf_section_activity || 'Goals, achievements and activity');

                pdf.setFillColor(20, 163, 139);
                pdf.rect(0, 0, width, 104, 'F');
                pdf.setTextColor(255, 255, 255);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(22);
                pdf.text(pdfTitle, margin, 44);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(12);
                pdf.text(`${displayName} (@${username})`, margin, 66);
                pdf.text(`Generated: ${generatedAt}`, margin, 84);
                pdf.text(`Challenge range: ${challengeRangeLabel}`, margin + 240, 84);
                pdf.setTextColor(25, 35, 45);
                y = 128;

                addSectionTitle(`1) ${sectionOverview}`);
                addLines([
                    `Primary goal: ${config.primary_goal_type || '-'}`,
                    `Target value: ${config.primary_goal_value ?? '-'}`,
                    `Primary goals spec: ${config.primary_goals_spec || '-'}`,
                    `Workout target/week: ${config.workout_target ?? '-'}`,
                    `Maintenance: ${config.maintenance_calories ?? '-'} kcal`,
                    `Burn goal: ${config.calorie_burn_goal ?? '-'} kcal`,
                    `Max consumed: ${config.calorie_consumed_max ?? '-'} kcal`,
                    `Ideal weight: ${config.ideal_weight ?? '-'} kg`,
                ], { size: 10, lineHeight: 12 });
                y += 6;
                addLines([
                    `Total steps: ${formatNumber(totals.steps, 0)}`,
                    `Total distance: ${formatNumber(totals.distance_km, 2)} km`,
                    `Counted workouts: ${formatNumber(totals.workouts, 0)}`,
                    `Score: ${formatNumber(totals.score, 1)}`,
                    `Strikes: ${formatNumber(totals.strikes, 0)}`,
                    `Penalty: €${formatNumber(totals.penalty, 2)}`,
                ], { size: 10, lineHeight: 12 });

                addSectionTitle(`2) ${sectionCharts}`);
                const chartDefs = [
                    { key: 'steps', title: 'Steps', color: '#14a38b', type: 'line' },
                    { key: 'distance', title: 'Distance', color: '#3b82f6', type: 'line' },
                    { key: 'workouts', title: 'Workouts', color: '#ec4899', type: 'bar' },
                    { key: 'score', title: 'Score', color: '#0f766e', type: 'line' },
                    { key: 'weight', title: 'Weight', color: '#334155', type: 'line' },
                ];
                for (const chartDef of chartDefs) {
                    const rows = (payload.charts && payload.charts[chartDef.key]) || [];
                    if (!Array.isArray(rows) || rows.length === 0) {
                        continue;
                    }
                    const image = await buildChartImage({
                        title: chartDef.title,
                        rows,
                        color: chartDef.color,
                        type: chartDef.type,
                    });
                    if (!image) {
                        continue;
                    }
                    addPageIfNeeded(220);
                    addLines([chartDef.title], { font: 'bold', size: 11, lineHeight: 12 });
                    addPageIfNeeded(190);
                    pdf.addImage(image, 'PNG', margin, y, contentWidth, 180);
                    y += 188;
                }

                const dailyDetails = Array.isArray(payload.daily_details) ? payload.daily_details : [];
                addSectionTitle(`3) ${sectionDaily}`);
                if (dailyDetails.length === 0) {
                    addLines(['No daily details available.'], { size: 10, lineHeight: 12 });
                } else {
                    dailyDetails.forEach((day) => {
                        const date = formatDate(day.date);
                        const workouts = Array.isArray(day.workout_types) && day.workout_types.length > 0
                            ? day.workout_types.join(', ')
                            : '-';
                        const approvals = [
                            day.approval_step_status ? `step:${day.approval_step_status}` : '',
                            day.approval_workout_status ? `workout:${day.approval_workout_status}` : '',
                            day.approval_extra_status ? `extra:${day.approval_extra_status}` : '',
                        ].filter(Boolean).join(' | ') || '-';
                        const detailLines = [
                            `${date} | steps ${formatNumber(day.steps, 0)} | km ${formatNumber(day.distance_km, 2)} | workouts ${formatNumber(day.workout_count, 0)} (counted ${formatNumber(day.workout_counted, 0)})`,
                            `burned ${day.training_calories_burned == null ? '-' : `${formatNumber(day.training_calories_burned, 0)} kcal`} | weight ${day.weight == null ? '-' : `${formatNumber(day.weight, 1)} kg`} | junk ${day.junk_food ? 'yes' : 'no'} | extra ${day.extra_workout ? 'yes' : 'no'}`,
                            `tipos: ${workouts}`,
                            `missing reason: ${day.missing_reason || '-'}`,
                            `approvals: ${approvals}`,
                            `notes: ${day.notes || '-'}`,
                        ];
                        if (Array.isArray(day.habits) && day.habits.length > 0) {
                            const habitLine = day.habits
                                .map((habit) => `${habit.label || habit.code}: ${habit.value ? '1' : '0'}`)
                                .join(' | ');
                            detailLines.push(`habits: ${habitLine || '-'}`);
                        }
                        addPageIfNeeded(96);
                        addLines(detailLines, { size: 9, lineHeight: 11 });
                        y += 3;
                    });
                }

                const dailyNutrition = Array.isArray(payload.daily_photo_nutrition) ? payload.daily_photo_nutrition : [];
                addSectionTitle(`4) ${sectionNutrition}`);
                if (dailyNutrition.length === 0) {
                    addLines(['No nutrition/photo data.'], { size: 10, lineHeight: 12 });
                } else {
                    dailyNutrition.forEach((day) => {
                        const totalsByDay = day.totals || {};
                        const header = `${formatDate(day.date)} | fotos ${formatNumber(day.photo_count, 0)} | kcal ${formatNumber(totalsByDay.calories, 0)} | P ${formatNumber(totalsByDay.protein_g, 1)}g / C ${formatNumber(totalsByDay.carbs_g, 1)}g / F ${formatNumber(totalsByDay.fat_g, 1)}g`;
                        addPageIfNeeded(20);
                        addLines([header], { font: 'bold', size: 9, lineHeight: 11 });
                        const items = Array.isArray(day.items) ? day.items : [];
                        if (items.length === 0) {
                            addLines(['  - No photos or entries.'], { size: 9, lineHeight: 11 });
                        } else {
                            items.forEach((item) => {
                                addLines([
                                    `  - [${item.category || '-'}] ${item.caption || '-'} | kcal ${item.calories == null ? '-' : formatNumber(item.calories, 0)} | P/C/F ${item.protein_g == null ? '-' : formatNumber(item.protein_g, 1)}/${item.carbs_g == null ? '-' : formatNumber(item.carbs_g, 1)}/${item.fat_g == null ? '-' : formatNumber(item.fat_g, 1)}`
                                ], { size: 9, lineHeight: 11 });
                            });
                        }
                        y += 2;
                    });
                }

                addSectionTitle(`5) ${sectionActivity}`);
                const goalLines = Array.isArray(payload.goals)
                    ? payload.goals.map((goal) => `- ${goal.title || '-'} (${goal.target_type || '-'}) target ${goal.target_value || 0} | ${goal.status || 'active'} | due ${formatDate(goal.due_date || '')}`)
                    : [];
                const achievementLines = Array.isArray(payload.achievements)
                    ? payload.achievements.map((achievement) => `- ${achievement.name || '-'}${achievement.reward_text ? ` | ${achievement.reward_text}` : ''} | ${formatDate(achievement.awarded_at || '')}`)
                    : [];
                addLines(['Goals:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(goalLines.length > 0 ? goalLines : ['- No goals.'], { size: 9, lineHeight: 11 });
                y += 4;
                addLines(['Achievements:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(achievementLines.length > 0 ? achievementLines : ['- No achievements.'], { size: 9, lineHeight: 11 });

                const safeUsername = username.replace(/[^a-z0-9_-]/gi, '-').toLowerCase();
                const today = new Date().toISOString().slice(0, 10);
                pdf.save(`challenge-report-${safeUsername}-${today}.pdf`);
            } catch (error) {
                console.error('PDF export failed', error);
                window.alert('Could not generate the PDF right now.');
            } finally {
                button.disabled = false;
                button.textContent = previousLabel || 'Export user data to PDF';
            }
        });
    };

    const initTeamLayoutEditor = () => {
        document.querySelectorAll('[data-team-layout-list], [data-dashboard-layout-list], [data-analytics-layout-list], [data-profile-layout-list]').forEach((list) => {
            if (!(list instanceof HTMLElement)) {
                return;
            }
            if (list.dataset.layoutEditorReady === '1') {
                return;
            }
            list.dataset.layoutEditorReady = '1';

            let dragged = null;
            const isDashboardLayout = list.hasAttribute('data-dashboard-layout-list');
            const isAnalyticsLayout = list.hasAttribute('data-analytics-layout-list');
            const isProfileLayout = list.hasAttribute('data-profile-layout-list');
            const itemSelector = isDashboardLayout
                ? '[data-dashboard-layout-item]'
                : (isAnalyticsLayout ? '[data-analytics-layout-item]' : (isProfileLayout ? '[data-profile-layout-item]' : '[data-team-layout-item]'));
            const orderInputSelector = isDashboardLayout
                ? '[data-dashboard-order-input]'
                : (isAnalyticsLayout ? '[data-analytics-order-input]' : (isProfileLayout ? '[data-profile-order-input]' : ''));

            const layoutItemMatches = (node) => node instanceof Element && node.matches(itemSelector);
            const layoutItems = () => [...list.querySelectorAll(itemSelector)].filter((node) => node instanceof HTMLElement);
            const scopedLayoutItems = (item) => {
                const scope = item instanceof HTMLElement ? String(item.dataset.layoutScope || '') : '';
                return scope === '' ? layoutItems() : layoutItems().filter((candidate) => candidate.dataset.layoutScope === scope);
            };
            const refreshOrderInputs = () => {
                if (orderInputSelector === '') {
                    return;
                }
                list.querySelectorAll(itemSelector).forEach((item, index) => {
                    if (!(item instanceof HTMLElement)) {
                        return;
                    }
                    item.querySelectorAll(orderInputSelector).forEach((input) => {
                        if (input instanceof HTMLInputElement) {
                            input.value = String(index + 1);
                        }
                    });
                });
            };
            const updateLayoutMoveButtons = () => {
                layoutItems().forEach((item) => {
                    const items = scopedLayoutItems(item);
                    const index = items.indexOf(item);
                    item.querySelectorAll('[data-layout-move]').forEach((button) => {
                        if (!(button instanceof HTMLButtonElement)) {
                            return;
                        }
                        const direction = String(button.dataset.layoutMove || '').toLowerCase();
                        const disable = (direction === 'up' && index === 0)
                            || (direction === 'down' && index === items.length - 1);
                        button.disabled = disable;
                        button.setAttribute('aria-disabled', disable ? 'true' : 'false');
                    });
                });
            };
            const persistLayoutOrder = () => {
                refreshOrderInputs();
                updateLayoutMoveButtons();
                if (typeof window.saveLayoutOrder === 'function') {
                    try {
                        window.saveLayoutOrder();
                    } catch (error) {
                        console.warn('saveLayoutOrder failed:', error);
                    }
                } else if (typeof saveLayoutOrder === 'function') {
                    try {
                        saveLayoutOrder();
                    } catch (error) {
                        console.warn('saveLayoutOrder failed:', error);
                    }
                }
            };
            const moveItem = (item, direction) => {
                if (!(item instanceof HTMLElement)) {
                    return;
                }
                const items = scopedLayoutItems(item);
                const index = items.indexOf(item);
                if (index === -1) {
                    return;
                }
                if (direction === 'up' && index > 0) {
                    items[index - 1].before(item);
                }
                if (direction === 'down' && index < items.length - 1) {
                    items[index + 1].after(item);
                }
                persistLayoutOrder();
            };

            const getAfterElement = (container, y) => {
                const items = [...container.querySelectorAll(`${itemSelector}:not(.is-dragging)`)];
                return items.reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset, element: child };
                    }

                    return closest;
                }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
            };

            list.addEventListener('dragstart', (event) => {
                const target = event.target;
                const item = target instanceof Element ? target.closest(itemSelector) : null;
                if (!layoutItemMatches(item)) {
                    return;
                }
                if (item instanceof HTMLElement) {
                    dragged = item;
                    item.classList.add('is-dragging');
                }
            });

            list.addEventListener('dragend', (event) => {
                const target = event.target;
                const item = target instanceof Element ? target.closest(itemSelector) : null;
                if (!layoutItemMatches(item)) {
                    return;
                }
                if (item instanceof HTMLElement) {
                    item.classList.remove('is-dragging');
                }
                dragged = null;
                persistLayoutOrder();
            });

            list.addEventListener('dragover', (event) => {
                event.preventDefault();
                if (!(dragged instanceof HTMLElement)) {
                    return;
                }

                const afterElement = getAfterElement(list, event.clientY);
                if (afterElement === null) {
                    list.appendChild(dragged);
                } else {
                    list.insertBefore(dragged, afterElement);
                }
                persistLayoutOrder();
            });

            list.querySelectorAll('[data-layout-move]').forEach((button) => {
                if (!(button instanceof HTMLButtonElement) || button.dataset.layoutMoveTouchReady === '1') {
                    return;
                }
                button.dataset.layoutMoveTouchReady = '1';
                button.style.touchAction = 'manipulation';
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    const item = button.closest(itemSelector);
                    // Dashboard touch controls are handled by its live-preview
                    // controller below so one click also updates the real cards.
                    if (!isDashboardLayout && item instanceof HTMLElement) {
                        moveItem(item, String(button.dataset.layoutMove || '').toLowerCase());
                    }
                });
                button.addEventListener('touchstart', (event) => {
                    event.stopPropagation();
                }, { passive: true });
            });

            persistLayoutOrder();
        });
    };

    const initNotificationsAjax = () => {
        const root = document.querySelector('[data-notifications-page]');
        if (!(root instanceof HTMLElement) || root.dataset.notificationsReady === '1') {
            return;
        }
        root.dataset.notificationsReady = '1';

        const replaceBadge = (doc) => {
            const syncBadge = (containerSelector) => {
                const currentContainer = document.querySelector(containerSelector);
                const nextContainer = doc.querySelector(containerSelector);
                if (!(currentContainer instanceof HTMLElement) || !(nextContainer instanceof HTMLElement)) {
                    return;
                }
                if (nextContainer.hasAttribute('aria-label')) {
                    currentContainer.setAttribute('aria-label', nextContainer.getAttribute('aria-label') || '');
                }
                const currentBadge = currentContainer.querySelector('[data-notification-badge]');
                const nextBadge = nextContainer.querySelector('[data-notification-badge]');
                if (nextBadge instanceof HTMLElement) {
                    const clone = nextBadge.cloneNode(true);
                    if (currentBadge instanceof HTMLElement) {
                        currentBadge.replaceWith(clone);
                    } else {
                        currentContainer.appendChild(clone);
                    }
                    return;
                }
                if (currentBadge instanceof HTMLElement) {
                    currentBadge.remove();
                }
            };

            syncBadge('.topbar-notif-btn');
            syncBadge('.user-menu-trigger');

            const currentNotificationsLink = document.querySelector('.user-menu-panel a[href="/?page=notifications"]');
            const nextNotificationsLink = doc.querySelector('.user-menu-panel a[href="/?page=notifications"]');
            if (currentNotificationsLink instanceof HTMLElement && nextNotificationsLink instanceof HTMLElement) {
                currentNotificationsLink.textContent = nextNotificationsLink.textContent;
            }
        };

        root.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-notification-form]')) {
                return;
            }
            const confirmMessage = String(form.dataset.confirm || '').trim();
            if (confirmMessage !== '' && !window.confirm(confirmMessage)) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            try {
                root.classList.add('is-loading');
                beginPageLoading();
                const response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'text/html' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`Notification request failed: ${response.status}`);
                }
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nextPage = doc.querySelector('[data-notifications-page]');
                if (!(nextPage instanceof HTMLElement)) {
                    throw new Error('Notifications fragment missing.');
                }
                replaceBadge(doc);
                root.replaceWith(nextPage);
                initNotificationsAjax();
            } catch (error) {
                console.error('Notification action failed:', error);
                HTMLFormElement.prototype.submit.call(form);
            } finally {
                root.classList.remove('is-loading');
                endPageLoading();
                clearMobileViewTransitionState(true);
                queueMobileViewTransitionStateCleanup(350, true);
            }
        });
    };

    const initInPageNavigation = () => {
        if (document.documentElement.dataset.inPageNavigationReady === '1') {
            return;
        }
        document.documentElement.dataset.inPageNavigationReady = '1';

        const canRunInlineScript = (script) => {
            if (!(script instanceof HTMLScriptElement)) {
                return false;
            }
            const type = String(script.type || '').trim().toLowerCase();
            if (type === 'application/json' || type === 'application/ld+json' || type === 'text/plain') {
                return false;
            }
            const src = String(script.getAttribute('src') || '').trim();
            if (src.includes('/assets/main.js')) {
                return false;
            }
            return type === '' || type === 'text/javascript' || type === 'application/javascript' || type === 'module';
        };

        const isPjaxPageUrl = (url) => {
            return isSafePjaxPageUrl(url);
        };

        const shouldSkipLink = (link, event) => {
            if (!(link instanceof HTMLAnchorElement)) {
                return true;
            }
            if (event.defaultPrevented || event.button !== 0) {
                return true;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return true;
            }
            if (link.target === '_blank' || link.hasAttribute('download')) {
                return true;
            }
            const href = String(link.getAttribute('href') || '').trim();
            if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) {
                return true;
            }
            if (link.closest('[data-analytics-filter], [data-dashboard-control-form], [data-calendar-view-option], [data-no-pjax]')) {
                return true;
            }
            return false;
        };

        const syncBodyState = (nextBody) => {
            if (!(nextBody instanceof HTMLBodyElement)) {
                return;
            }
            const nextClasses = String(nextBody.getAttribute('class') || '').trim();
            document.body.className = nextClasses;
            const nextStyle = String(nextBody.getAttribute('style') || '').trim();
            if (nextStyle === '') {
                document.body.removeAttribute('style');
            } else {
                document.body.setAttribute('style', nextStyle);
            }
            Array.from(document.body.attributes).forEach((attribute) => {
                if (attribute.name.startsWith('data-')) {
                    document.body.removeAttribute(attribute.name);
                }
            });
            Array.from(nextBody.attributes).forEach((attribute) => {
                if (attribute.name.startsWith('data-')) {
                    document.body.setAttribute(attribute.name, attribute.value);
                }
            });
            syncPageLoadingClass();
        };

        const syncBottomNav = (doc) => {
            const currentItems = Array.from(document.querySelectorAll('.bottom-nav .liquid-nav-item'));
            const nextItems = Array.from(doc.querySelectorAll('.bottom-nav .liquid-nav-item'));
            if (currentItems.length === 0 || nextItems.length === 0 || currentItems.length !== nextItems.length) {
                return;
            }
            currentItems.forEach((node, index) => {
                const current = node instanceof HTMLAnchorElement ? node : null;
                const next = nextItems[index] instanceof HTMLAnchorElement ? nextItems[index] : null;
                if (!(current instanceof HTMLAnchorElement) || !(next instanceof HTMLAnchorElement)) {
                    return;
                }
                current.className = next.className;
                current.href = next.href;
                if (next.hasAttribute('aria-current')) {
                    current.setAttribute('aria-current', next.getAttribute('aria-current') || 'page');
                } else {
                    current.removeAttribute('aria-current');
                }
                const nextIcon = next.querySelector('.nav-icon');
                const currentIcon = current.querySelector('.nav-icon');
                if (nextIcon instanceof HTMLElement && currentIcon instanceof HTMLElement) {
                    currentIcon.innerHTML = nextIcon.innerHTML;
                }
                const nextLabel = next.querySelector('.nav-label');
                const currentLabel = current.querySelector('.nav-label');
                if (nextLabel instanceof HTMLElement && currentLabel instanceof HTMLElement) {
                    currentLabel.textContent = nextLabel.textContent || '';
                }
            });
        };

        const executeInsertedScripts = async () => {
            const scripts = [...document.querySelectorAll('main.container script')];
            for (const script of scripts) {
                if (!(script instanceof HTMLScriptElement) || !canRunInlineScript(script)) {
                    continue;
                }
                const source = String(script.getAttribute('src') || '').trim();
                if (source !== '' && /chart(?:\.umd(?:\.min)?|\.min)?\.js/i.test(source) && window.Chart) {
                    script.remove();
                    continue;
                }
                const nextScript = document.createElement('script');
                Array.from(script.attributes).forEach((attribute) => {
                    nextScript.setAttribute(attribute.name, attribute.value);
                });
                if (source === '') {
                    nextScript.textContent = script.textContent || '';
                    script.replaceWith(nextScript);
                    continue;
                }
                nextScript.async = false;
                const loaded = new Promise((resolve, reject) => {
                    nextScript.addEventListener('load', resolve, { once: true });
                    nextScript.addEventListener('error', () => reject(new Error(`Failed to load page script: ${source}`)), { once: true });
                });
                script.replaceWith(nextScript);
                await loaded;
            }
        };

        const persistScrollState = () => {
            const currentState = history.state && typeof history.state === 'object'
                ? history.state
                : {};
            history.replaceState(
                {
                    ...currentState,
                    __fcPjax: true,
                    scrollX: window.scrollX,
                    scrollY: window.scrollY,
                },
                '',
                window.location.href
            );
        };

        const restoreScrollFromState = (state) => {
            if (!state || typeof state !== 'object') {
                window.scrollTo(0, 0);
                return;
            }
            const scrollX = Number(state.scrollX || 0);
            const scrollY = Number(state.scrollY || 0);
            window.scrollTo(Number.isFinite(scrollX) ? scrollX : 0, Number.isFinite(scrollY) ? scrollY : 0);
        };

        const navigateInPage = async (targetUrl, { push = true, popState = null } = {}) => {
            if (window.__fcPjaxBusy === true) {
                return;
            }
            document.body.classList.add('is-view-changing');
            queueMobileViewTransitionStateCleanup(350, true);
            window.__fcPjaxBusy = true;
            beginPageLoading();
            document.dispatchEvent(new CustomEvent('fc:beforePageSwap', {
                detail: {
                    from: window.location.href,
                    to: targetUrl.toString(),
                },
            }));

            try {
                const response = await fetch(targetUrl.toString(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`In-page request failed (${response.status})`);
                }
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nextMain = doc.querySelector('main.container');
                const currentMain = document.querySelector('main.container');
                if (!(nextMain instanceof HTMLElement) || !(currentMain instanceof HTMLElement)) {
                    throw new Error('Missing main container in in-page response.');
                }

                if (push) {
                    persistScrollState();
                    const currentDepth = Number(history.state?.__fcPjaxDepth || 0);
                    history.pushState({
                        __fcPjax: true,
                        __fcPjaxDepth: Number.isFinite(currentDepth) ? currentDepth + 1 : 1,
                        scrollX: 0,
                        scrollY: 0,
                    }, '', targetUrl.toString());
                }

                document.title = doc.title || document.title;
                syncBodyState(doc.body);

                const currentTopbar = document.querySelector('header.topbar');
                const nextTopbar = doc.querySelector('header.topbar');
                if (currentTopbar instanceof HTMLElement && nextTopbar instanceof HTMLElement) {
                    currentTopbar.replaceWith(nextTopbar);
                } else if (currentTopbar instanceof HTMLElement && !(nextTopbar instanceof HTMLElement)) {
                    currentTopbar.remove();
                } else if (!(currentTopbar instanceof HTMLElement) && nextTopbar instanceof HTMLElement) {
                    document.body.insertBefore(nextTopbar, document.body.firstChild);
                }

                currentMain.replaceWith(nextMain);
                syncBottomNav(doc);
                await executeInsertedScripts();
                runPageHydration(false);

                if (push) {
                    window.scrollTo(0, 0);
                } else {
                    restoreScrollFromState(popState);
                }
                const navigationFocus = Array.from(nextMain.querySelectorAll(
                    '[data-navigation-focus], .hierarchy-page-header h1, .profile-hero h1, .screen h1'
                )).find((candidate) => candidate instanceof HTMLElement
                    && !candidate.closest('[hidden]')
                    && candidate.getClientRects().length > 0);
                if (navigationFocus instanceof HTMLElement) {
                    if (!navigationFocus.hasAttribute('tabindex')) {
                        navigationFocus.setAttribute('tabindex', '-1');
                    }
                    window.setTimeout(() => {
                        try { navigationFocus.focus({ preventScroll: true }); } catch (_) { navigationFocus.focus(); }
                    }, 0);
                }
                document.dispatchEvent(new CustomEvent('fc:afterPageSwap', {
                    detail: {
                        url: targetUrl.toString(),
                        push,
                    },
                }));
            } catch (error) {
                console.error('In-page navigation failed:', error);
                window.location.href = targetUrl.toString();
                return;
            } finally {
                window.__fcPjaxBusy = false;
                endPageLoading();
                clearMobileViewTransitionState(true);
                queueMobileViewTransitionStateCleanup(350, true);
            }
        };

        if (!history.state || typeof history.state !== 'object' || history.state.__fcPjax !== true) {
            history.replaceState({
                __fcPjax: true,
                __fcPjaxDepth: 0,
                scrollX: window.scrollX,
                scrollY: window.scrollY,
            }, '', window.location.href);
        }

        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!(link instanceof HTMLAnchorElement) || shouldSkipLink(link, event)) {
                return;
            }
            const url = new URL(link.href, window.location.origin);
            if (!isPjaxPageUrl(url)) {
                return;
            }
            event.preventDefault();
            const pjaxDepth = Number(history.state?.__fcPjaxDepth || 0);
            if (link.matches('[data-spa-history]') && Number.isFinite(pjaxDepth) && pjaxDepth > 0) {
                history.back();
                return;
            }
            navigateInPage(url, { push: true });
        }, true);

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (String(form.method || 'get').trim().toLowerCase() !== 'get') {
                return;
            }
            if (form.target === '_blank' || form.hasAttribute('data-no-pjax')) {
                return;
            }
            if (form.closest('[data-analytics-filter], [data-dashboard-control-form], [data-no-pjax]')) {
                return;
            }
            const actionUrl = new URL(form.action || window.location.href, window.location.origin);
            if (!isPjaxPageUrl(actionUrl)) {
                return;
            }
            const params = new URLSearchParams();
            const formData = new FormData(form);
            formData.forEach((value, key) => {
                if (typeof value === 'string') {
                    params.append(key, value);
                }
            });
            actionUrl.search = params.toString();
            event.preventDefault();
            navigateInPage(actionUrl, { push: true });
        }, true);

        window.addEventListener('popstate', (event) => {
            const targetUrl = new URL(window.location.href);
            if (!isPjaxPageUrl(targetUrl)) {
                return;
            }
            navigateInPage(targetUrl, { push: false, popState: event.state });
        });
    };

    const initThemeToggle = () => {
        const button = document.querySelector('[data-theme-toggle]');
        if (!(button instanceof HTMLButtonElement) || button.dataset.themeToggleBound === '1') {
            return;
        }
        button.dataset.themeToggleBound = '1';

        const body = document.body;

        const resolveIsDark = () => {
            const attr = body.getAttribute('data-theme');
            if (attr === 'dark') {
                return true;
            }
            if (attr === 'light') {
                return false;
            }
            return window.matchMedia('(prefers-color-scheme: dark)').matches;
        };

        const applyResolved = (isDark) => {
            body.classList.toggle('theme-active-dark', isDark);
            body.classList.toggle('theme-active-light', !isDark);
            button.setAttribute('aria-pressed', String(isDark));
            const label = button.querySelector('[data-theme-toggle-label]');
            if (label) {
                label.textContent = isDark
                    ? (button.dataset.labelLight || label.textContent)
                    : (button.dataset.labelDark || label.textContent);
            }
        };

        applyResolved(resolveIsDark());

        button.addEventListener('click', () => {
            const nextIsDark = !resolveIsDark();
            const nextTheme = nextIsDark ? 'dark' : 'light';
            body.setAttribute('data-theme', nextTheme);
            applyResolved(nextIsDark);

            fetch('/?page=set_theme', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    Accept: 'application/json',
                },
                body: new URLSearchParams({
                    csrf_token: button.dataset.csrf || '',
                    theme_mode: nextTheme,
                    async: '1',
                }),
                keepalive: true,
            }).catch(() => {});

            const menu = button.closest('.user-menu');
            if (menu instanceof HTMLDetailsElement) {
                menu.open = false;
            }
        });
    };

    const initWorkoutHubTabs = () => {
        document.querySelectorAll('[data-workouts-tabs]').forEach((tabs) => {
            if (!(tabs instanceof HTMLElement) || tabs.dataset.workoutsTabsReady === '1') {
                return;
            }
            tabs.dataset.workoutsTabsReady = '1';
            const revealActive = () => {
                const active = tabs.querySelector('[aria-current="page"]');
                if (!(active instanceof HTMLElement) || tabs.scrollWidth <= tabs.clientWidth) {
                    return;
                }
                tabs.scrollLeft = Math.max(0, active.offsetLeft - ((tabs.clientWidth - active.offsetWidth) / 2));
            };
            window.requestAnimationFrame(revealActive);
            window.addEventListener('resize', revealActive, { passive: true });
        });
    };

    const initWorkoutLibraryFilters = () => {
        const panel = document.querySelector('[data-workout-filter-panel]');
        const openButton = document.querySelector('[data-workout-filter-open]');
        if (!(panel instanceof HTMLElement) || !(openButton instanceof HTMLButtonElement)) {
            return;
        }
        const closeButton = panel.querySelector('[data-workout-filter-close]');
        const setOpen = (open) => {
            const mobileSheet = window.matchMedia('(max-width: 700px)').matches;
            panel.classList.toggle('is-open', open);
            panel.setAttribute('aria-hidden', mobileSheet && !open ? 'true' : 'false');
            document.body.classList.toggle('has-workout-filter-sheet', open);
            if (open) {
                window.requestAnimationFrame(() => panel.querySelector('select, input, button')?.focus());
            }
        };
        if (panel.dataset.workoutFilterReady === '1') {
            return;
        }
        panel.dataset.workoutFilterReady = '1';
        openButton.addEventListener('click', () => setOpen(true));
        closeButton?.addEventListener('click', () => setOpen(false));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && panel.classList.contains('is-open')) {
                setOpen(false);
                openButton.focus();
            }
        });
        setOpen(false);
    };

    const initContextualBack = () => {
        const container = document.querySelector('[data-contextual-back-container]');
        if (!(container instanceof HTMLElement)) {
            return;
        }
        const depth = Number(window.history.state?.__fcPjaxDepth || 0);
        let sameOriginReferrer = false;
        try {
            sameOriginReferrer = document.referrer !== ''
                && new URL(document.referrer).origin === window.location.origin;
        } catch (_) {
            sameOriginReferrer = false;
        }
        container.hidden = !(Number.isFinite(depth) && depth > 0) && !sameOriginReferrer;
    };

    const initCollapsibleLists = () => {
        document.querySelectorAll('[data-collapsible-list]').forEach((list) => {
            if (!(list instanceof HTMLElement)) {
                return;
            }
            const items = Array.from(list.querySelectorAll('[data-collapsible-item]'));
            const toggle = list.querySelector('[data-collapsible-toggle]');
            if (!(toggle instanceof HTMLButtonElement) || items.length === 0) {
                if (toggle instanceof HTMLElement) {
                    toggle.hidden = true;
                }
                return;
            }
            const limit = window.matchMedia('(max-width: 700px)').matches
                ? Number(list.dataset.mobileCount || 4)
                : Number(list.dataset.desktopCount || 6);
            const hasOverflow = items.length > limit;
            let expanded = list.dataset.collapsibleExpanded === '1';
            const render = () => {
                items.forEach((item, index) => {
                    if (item instanceof HTMLElement) {
                        item.hidden = !expanded && index >= limit;
                    }
                });
                toggle.hidden = !hasOverflow;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                toggle.textContent = expanded
                    ? (toggle.dataset.labelLess || '')
                    : (toggle.dataset.labelMore || '');
            };
            if (list.dataset.collapsibleReady !== '1') {
                list.dataset.collapsibleReady = '1';
                toggle.addEventListener('click', () => {
                    expanded = !expanded;
                    list.dataset.collapsibleExpanded = expanded ? '1' : '0';
                    render();
                });
            }
            render();
        });
    };

    const runPageHydration = (includeOneTime = false) => {
        const safeInit = (initFn) => {
            try {
                initFn();
            } catch (error) {
                console.error('Init failed:', error);
            }
        };

        if (includeOneTime) {
            safeInit(initLiquidInteractions);
            safeInit(initInPageNavigation);
        }
        safeInit(initLoginLocale);
        safeInit(initFlashNotifications);
        safeInit(initThemeToggle);
        safeInit(initContextualBack);
        safeInit(initCollapsibleLists);
        safeInit(initWorkoutHubTabs);
        safeInit(initWorkoutLibraryFilters);
        safeInit(initSpaNavigation);
        safeInit(initAdminAchievementFields);
        safeInit(initAchievementInfoModal);
        safeInit(initAchievementDeleteModal);
        safeInit(initMealCalendar);
        safeInit(initAnalyticsEnhancement);
        safeInit(initDashboardEnhancement);
        safeInit(initPhotoDeleteModal);
        safeInit(initPhotoEditModal);
        safeInit(initStrikeReviewModal);
        safeInit(initProfileGoalsSection);
        safeInit(initProfileConfigEditor);
        safeInit(initPrivacyOptions);
        safeInit(initSettingsAvatarHashFallback);
        safeInit(initGalleryMonthOverlay);
        safeInit(initGalleryImageStates);
        safeInit(initGalleryRecentInfinite);
        safeInit(initCompactDisclosures);
        safeInit(initImageCroppers);
        safeInit(initProfilePdfExport);
        safeInit(initTeamLayoutEditor);
        safeInit(initNotificationsAjax);
        safeInit(initEntryForm);
        clearMobileViewTransitionState(true);
        queueMobileViewTransitionStateCleanup(350, true);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => runPageHydration(true));
    } else {
        runPageHydration(true);
    }
})();

/* ==========================================================================
   Reusable UI controllers: kebab menu + app modal/drawer (#components)
   ========================================================================== */
(() => {
    // ---- Dismissible menus (native <details>) ----
    // A native <details> stays open until you click its own summary again, so every
    // dropdown in the topbar - the bell, the + menu, the user menu, the view panel -
    // was left hanging open when you clicked away or pressed Escape. They all behave
    // like the kebab menus now: one open at a time, closed by an outside click or Escape.
    const MENU_SELECTOR = 'details[data-kebab-menu], details.notif-menu, details.user-menu,'
        + ' details.add-menu, details.topbar-context';
    const portaledKebabs = new WeakMap();
    const kebabPanelOwners = new WeakMap();
    const menuBackdrops = new WeakMap();
    let suppressMenuScrollCloseUntil = 0;
    // Each menu owns a real navigation history. The old controller remembered a
    // single trigger, so opening a submenu from another submenu made Back jump to
    // the root and lose focus. Entries keep both the view and the control that
    // opened the next level, which supports any depth while the UI intentionally
    // exposes at most three levels.
    const menuViewHistories = new WeakMap();

    const resetMenuStack = (stack) => {
        if (!(stack instanceof HTMLElement)) return;
        const views = Array.from(stack.querySelectorAll(':scope > [data-menu-view]'));
        views.forEach((view) => { view.hidden = view.getAttribute('data-menu-view') !== 'main'; });
        stack.dataset.activeMenuView = 'main';
        menuViewHistories.set(stack, [{ viewName: 'main', trigger: null }]);
    };

    const showMenuView = (stack, viewName, returnControl = null, pushHistory = true, focusView = true) => {
        if (!(stack instanceof HTMLElement)) return false;
        const views = Array.from(stack.querySelectorAll(':scope > [data-menu-view]'));
        const targetView = views.find((view) => view.getAttribute('data-menu-view') === viewName);
        if (!(targetView instanceof HTMLElement)) return false;
        let history = menuViewHistories.get(stack);
        if (!Array.isArray(history) || history.length === 0) {
            const currentView = String(stack.dataset.activeMenuView || 'main');
            history = [{ viewName: currentView, trigger: null }];
        }
        if (pushHistory) {
            const activeName = String(stack.dataset.activeMenuView || history[history.length - 1]?.viewName || 'main');
            const currentEntry = history[history.length - 1];
            if (!currentEntry || currentEntry.viewName !== activeName) {
                history.push({ viewName: activeName, trigger: null });
            }
            history.push({ viewName, trigger: returnControl instanceof HTMLElement ? returnControl : null });
        } else if (history.length === 0 || history[history.length - 1]?.viewName !== viewName) {
            history.push({ viewName, trigger: null });
        }
        menuViewHistories.set(stack, history);
        views.forEach((view) => { view.hidden = view !== targetView; });
        stack.dataset.activeMenuView = viewName;
        const focusTarget = targetView.querySelector('[data-menu-back], .kebab-menu-item, a, button');
        if (focusView && focusTarget instanceof HTMLElement) {
            window.requestAnimationFrame(() => {
                try { focusTarget.focus({ preventScroll: true }); } catch (_) { focusTarget.focus(); }
            });
        }
        return true;
    };

    const restoreKebabPanel = (menu) => {
        const state = portaledKebabs.get(menu);
        if (!state) return;
        const { panel, placeholder, backdrop } = state;
        resetMenuStack(panel);
        if (placeholder.parentNode) {
            placeholder.replaceWith(panel);
        } else {
            panel.remove();
        }
        if (backdrop instanceof HTMLElement) backdrop.remove();
        kebabPanelOwners.delete(panel);
        panel.classList.remove('is-portaled');
        panel.removeAttribute('data-kebab-portaled');
        panel.removeAttribute('style');
        portaledKebabs.delete(menu);
        const trigger = menu.querySelector(':scope > summary');
        if (trigger instanceof HTMLElement) trigger.setAttribute('aria-expanded', 'false');
        if (!document.querySelector('.kebab-menu-panel.is-portaled')) {
            document.body.classList.remove('kebab-menu-open-mobile');
        }
    };

    const portalKebabPanel = (menu) => {
        if (!(menu instanceof HTMLDetailsElement) || portaledKebabs.has(menu)) return;
        const trigger = menu.querySelector(':scope > summary');
        const panel = menu.querySelector(':scope > .kebab-menu-panel');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) return;

        const placeholder = document.createComment('kebab-menu-panel');
        const mobile = window.matchMedia('(max-width: 600px)').matches;
        let backdrop = null;
        panel.replaceWith(placeholder);
        if (mobile) {
            backdrop = document.createElement('div');
            backdrop.className = 'kebab-menu-backdrop';
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.appendChild(backdrop);
            document.body.classList.add('kebab-menu-open-mobile');
        }
        document.body.appendChild(panel);
        panel.classList.add('is-portaled');
        panel.setAttribute('data-kebab-portaled', 'true');
        panel.style.position = 'fixed';
        // The mobile liquid nav sits at 9999 in the final theme layer. Context
        // sheets must cover app chrome or its labels bleed into the actions.
        panel.style.zIndex = '12020';
        kebabPanelOwners.set(panel, menu);

        if (mobile) {
            panel.style.left = '0.6rem';
            panel.style.right = '0.6rem';
            panel.style.bottom = 'calc(0.6rem + env(safe-area-inset-bottom))';
            panel.style.top = 'auto';
            panel.style.minWidth = '0';
        } else {
            const rect = trigger.getBoundingClientRect();
            const panelWidth = Math.max(190, Math.min(280, panel.offsetWidth || 190));
            const preferredLeft = menu.dataset.align === 'start'
                ? rect.left
                : rect.right - panelWidth;
            const left = Math.max(8, Math.min(window.innerWidth - panelWidth - 8, preferredLeft));
            const estimatedHeight = Math.max(48, panel.offsetHeight || 160);
            const opensUp = rect.bottom + 8 + estimatedHeight > window.innerHeight && rect.top > estimatedHeight;
            panel.style.width = `${panelWidth}px`;
            panel.style.left = `${left}px`;
            panel.style.right = 'auto';
            panel.style.top = opensUp ? 'auto' : `${Math.min(window.innerHeight - 8, rect.bottom + 6)}px`;
            panel.style.bottom = opensUp ? `${Math.max(8, window.innerHeight - rect.top + 6)}px` : 'auto';
        }
        portaledKebabs.set(menu, { panel, placeholder, backdrop, mobile });
        // Focusing/scrolling the trigger into view can emit a delayed scroll event
        // immediately after opening. Ignore that synthetic tail so desktop popovers
        // do not close on the same interaction that opened them.
        suppressMenuScrollCloseUntil = window.performance.now() + 250;
        trigger.setAttribute('aria-expanded', 'true');
        if (backdrop instanceof HTMLElement) {
            backdrop.addEventListener('click', () => {
                menu.removeAttribute('open');
                restoreKebabPanel(menu);
                try { trigger.focus({ preventScroll: true }); } catch (_) { trigger.focus(); }
            }, { once: true });
        }
    };

    const menuForTarget = (target) => {
        if (!(target instanceof Element)) return null;
        const directMenu = target.closest(MENU_SELECTOR);
        if (directMenu instanceof HTMLDetailsElement) return directMenu;
        const portaledPanel = target.closest('.kebab-menu-panel.is-portaled');
        return portaledPanel instanceof HTMLElement ? kebabPanelOwners.get(portaledPanel) || null : null;
    };

    const closeMenu = (menu, restoreFocus = false) => {
        if (!(menu instanceof HTMLDetailsElement)) return;
        menu.removeAttribute('open');
        const menuBackdrop = menuBackdrops.get(menu);
        if (menuBackdrop instanceof HTMLElement) menuBackdrop.remove();
        menuBackdrops.delete(menu);
        const stack = menu.querySelector(':scope > [data-menu-stack]');
        resetMenuStack(stack);
        if (menu.matches('details.bottom-nav-plus')) {
            document.body.classList.remove('mobile-sheet-open');
        }
        if (menu.matches('details[data-kebab-menu]')) restoreKebabPanel(menu);
        const trigger = menu.querySelector(':scope > summary');
        if (trigger instanceof HTMLElement) {
            trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) {
                try { trigger.focus({ preventScroll: true }); } catch (_) { trigger.focus(); }
            }
        }
    };

    const closeAllKebabs = (except) => {
        document.querySelectorAll(MENU_SELECTOR).forEach((el) => {
            if (el !== except && el.open) closeMenu(el, false);
        });
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('[data-hierarchy-back]') : null;
        if (!(target instanceof HTMLElement)) return;
        event.preventDefault();
        const fallback = String(target.getAttribute('data-fallback') || '/');
        let sameOriginReferrer = false;
        try {
            sameOriginReferrer = document.referrer !== '' && new URL(document.referrer).origin === window.location.origin;
        } catch (_) {
            sameOriginReferrer = false;
        }
        if (window.history.length > 1 && (sameOriginReferrer || window.history.state !== null)) {
            window.history.back();
            return;
        }
        window.location.assign(fallback);
    });

    document.addEventListener('toggle', (event) => {
        const el = event.target;
        if (el instanceof HTMLDetailsElement && el.matches(MENU_SELECTOR) && el.open) {
            suppressMenuScrollCloseUntil = window.performance.now() + 250;
            closeAllKebabs(el);
            const trigger = el.querySelector(':scope > summary');
            if (trigger instanceof HTMLElement) trigger.setAttribute('aria-expanded', 'true');
            if (el.matches('details[data-kebab-menu]')) {
                window.requestAnimationFrame(() => {
                    if (el.open) portalKebabPanel(el);
                });
            } else if (el.matches('details.bottom-nav-plus') && window.matchMedia('(max-width: 899px)').matches) {
                document.body.classList.add('mobile-sheet-open');
                const stack = el.querySelector(':scope > [data-menu-stack]');
                resetMenuStack(stack);
                const backdrop = document.createElement('div');
                backdrop.className = 'mobile-sheet-backdrop';
                backdrop.setAttribute('aria-hidden', 'true');
                document.body.appendChild(backdrop);
                menuBackdrops.set(el, backdrop);
                backdrop.addEventListener('click', () => closeMenu(el, true), { once: true });
                window.requestAnimationFrame(() => {
                    const focusTarget = stack?.querySelector('[data-menu-close], [data-menu-open], a, button');
                    if (focusTarget instanceof HTMLElement) {
                        try { focusTarget.focus({ preventScroll: true }); } catch (_) { focusTarget.focus(); }
                    }
                });
            }
        } else if (el instanceof HTMLDetailsElement && el.matches(MENU_SELECTOR)) {
            const stack = el.querySelector(':scope > [data-menu-stack]');
            resetMenuStack(stack);
            const menuBackdrop = menuBackdrops.get(el);
            if (menuBackdrop instanceof HTMLElement) menuBackdrop.remove();
            menuBackdrops.delete(el);
            if (el.matches('details[data-kebab-menu]')) restoreKebabPanel(el);
            if (el.matches('details.bottom-nav-plus')) document.body.classList.remove('mobile-sheet-open');
        }
    }, true);

    // Close when clicking a menu item (buttons/links) or outside
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const confirmAction = target.closest('[data-confirm-action]');
        if (confirmAction) {
            const message = String(confirmAction.getAttribute('data-confirm-action') || '').trim();
            if (message !== '' && !window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
        }
        const stack = target.closest('[data-menu-stack]');
        const submenuOpen = target.closest('[data-menu-open]');
        if (submenuOpen instanceof HTMLElement && stack instanceof HTMLElement) {
            event.preventDefault();
            event.stopPropagation();
            showMenuView(stack, String(submenuOpen.getAttribute('data-menu-open') || ''), submenuOpen);
            return;
        }
        const submenuBack = target.closest('[data-menu-back]');
        if (submenuBack instanceof HTMLElement && stack instanceof HTMLElement) {
            event.preventDefault();
            event.stopPropagation();
            const history = menuViewHistories.get(stack) || [{ viewName: 'main', trigger: null }];
            const leaving = history.length > 1 ? history.pop() : history[0];
            const previous = history[history.length - 1] || { viewName: 'main', trigger: null };
            menuViewHistories.set(stack, history);
            // Back is different from opening a view: the control that opened the
            // level is the deterministic focus target. Do not queue the generic
            // first-control focus as it can win the race on the next frame.
            showMenuView(stack, String(previous.viewName || 'main'), null, false, false);
            const returnControl = leaving?.trigger;
            if (returnControl instanceof HTMLElement) {
                try { returnControl.focus({ preventScroll: true }); } catch (_) { returnControl.focus(); }
            }
            return;
        }
        const menuClose = target.closest('[data-menu-close]');
        if (menuClose instanceof HTMLElement) {
            event.preventDefault();
            event.stopPropagation();
            closeMenu(menuForTarget(target), true);
            return;
        }

        const insideMenu = menuForTarget(target);
        if (!insideMenu) {
            closeAllKebabs(null);
            return;
        }
        // Clicking an actual item closes the menu (after its own handler runs). Controls
        // that live inside a menu to be used there - selects, checkboxes, the layout
        // editor - must not close it under the user's fingers.
        const item = target.closest('.kebab-menu-item, .notif-menu-item a, .notif-menu-all, .user-menu-panel a, .add-menu-panel a');
        if (item) {
            setTimeout(() => closeMenu(insideMenu, false), 0);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const openMenus = Array.from(document.querySelectorAll(MENU_SELECTOR))
            .filter((menu) => menu instanceof HTMLDetailsElement && menu.open);
        if (openMenus.length === 0) return;
        event.preventDefault();
        const menuToFocus = openMenus[openMenus.length - 1];
        closeAllKebabs(null);
        closeMenu(menuToFocus, true);
    });

    window.addEventListener('resize', () => closeAllKebabs(null), { passive: true });
    // Close anchored desktop popovers on intentional wheel scrolling. Listening to
    // the resulting `scroll` event also catches Playwright/browser focus scrolling
    // and used to close a menu during the very click that opened it.
    window.addEventListener('wheel', () => {
        if (!window.matchMedia('(max-width: 600px)').matches
            && window.performance.now() >= suppressMenuScrollCloseUntil) closeAllKebabs(null);
    }, { passive: true });

    // ---- App modal / drawer ----
    const openOverlay = (overlay) => {
        if (!overlay) return;
        overlay.hidden = false;
        // Force reflow so the transition runs
        void overlay.offsetWidth;
        overlay.classList.add('is-open');
        document.body.classList.add('app-scroll-locked');
        const focusable = overlay.querySelector('[autofocus], input, button, [tabindex]');
        if (focusable instanceof HTMLElement) {
            try { focusable.focus({ preventScroll: true }); } catch (_) {}
        }
    };

    const closeOverlay = (overlay) => {
        if (!overlay || overlay.hidden) return;
        overlay.classList.remove('is-open');
        const anyOpen = () => document.querySelector('.app-modal.is-open, .app-drawer.is-open');
        const finish = () => {
            overlay.hidden = true;
            if (!anyOpen()) document.body.classList.remove('app-scroll-locked');
        };
        let done = false;
        const onEnd = () => { if (done) return; done = true; finish(); };
        overlay.addEventListener('transitionend', onEnd, { once: true });
        setTimeout(onEnd, 260);
    };

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const opener = target.closest('[data-app-modal-open]');
        if (opener) {
            const id = opener.getAttribute('data-app-modal-open');
            const overlay = id ? document.getElementById(id) : null;
            if (overlay) {
                event.preventDefault();
                openOverlay(overlay);
                return;
            }
        }

        const closer = target.closest('[data-app-modal-close]');
        if (closer) {
            event.preventDefault();
            closeOverlay(closer.closest('.app-modal, .app-drawer'));
            return;
        }

        // Backdrop click (clicking the overlay itself, not its card)
        if (target.matches('.app-modal, .app-drawer') && target.classList.contains('is-open')) {
            closeOverlay(target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const open = document.querySelector('.app-modal.is-open, .app-drawer.is-open');
        if (open) closeOverlay(open);
    });

    // Expose for programmatic use elsewhere
    window.AppOverlay = { open: openOverlay, close: closeOverlay };
})();

/* ==========================================================================
   Back-to-top floating button (#9 long config/settings pages)
   ========================================================================== */
(() => {
    const btn = document.querySelector('[data-to-top]');
    if (!(btn instanceof HTMLElement)) return;
    btn.hidden = false;
    let ticking = false;
    const threshold = 480;
    const update = () => {
        ticking = false;
        const y = window.scrollY || document.documentElement.scrollTop || 0;
        btn.classList.toggle('is-visible', y > threshold);
    };
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(update);
    }, { passive: true });
    btn.addEventListener('click', () => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    });
    update();
})();

/* Settings subpages: warn only after a real form change, and clear the guard on save. */
(() => {
    const dirtyForms = new Set();
    const liveDirtyForms = () => Array.from(dirtyForms).filter((form) => document.contains(form));

    document.addEventListener('change', (event) => {
        const form = event.target instanceof Element
            ? event.target.closest('form[data-settings-dirty-form]')
            : null;
        if (form instanceof HTMLFormElement) {
            dirtyForms.add(form);
            form.classList.add('has-unsaved-changes');
        }
    });
    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement) {
            dirtyForms.delete(event.target);
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (liveDirtyForms().length === 0) return;
        event.preventDefault();
        event.returnValue = '';
    });
    document.addEventListener('click', (event) => {
        if (liveDirtyForms().length === 0) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!(link instanceof HTMLAnchorElement) || link.target === '_blank') return;
        const root = document.querySelector('[data-settings-section]');
        const message = root instanceof HTMLElement
            ? String(root.dataset.unsavedMessage || '')
            : '';
        if (message !== '' && !window.confirm(message)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        dirtyForms.clear();
    }, true);
})();

/* ==========================================================================
   Workouts — kebab menu actions submit a POST form (#5)
   ========================================================================== */
(() => {
    document.addEventListener('click', (event) => {
        const el = event.target instanceof Element ? event.target.closest('[data-wk-submit]') : null;
        if (!el) return;
        const confirmMsg = el.getAttribute('data-wk-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/?page=workouts';
        const add = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value == null ? '' : String(value);
            form.appendChild(input);
        };
        add('csrf_token', csrfInput instanceof HTMLInputElement ? csrfInput.value : '');
        add('action', el.getAttribute('data-wk-submit') || '');
        if (el.hasAttribute('data-wk-routine')) add('routine_id', el.getAttribute('data-wk-routine'));
        if (el.hasAttribute('data-wk-value')) add('value', el.getAttribute('data-wk-value'));
        document.body.appendChild(form);
        form.submit();
    });
})();

/* ==========================================================================
   Visual layout editing: drag the real cards (desktop)

   In layout-edit mode the CARDS themselves become draggable, so the user
   rearranges the real page instead of an abstract list. One engine, four
   registrations (dashboard, analytics, profile, team) - the pages differ only in
   which container holds the cards, which attribute carries a card's key, and how
   its editor form records the order.

   Pointer Events give one code path for mouse and pen. A drag only starts after
   the pointer passes a threshold, so a click never becomes an accidental drag.
   Touch is deliberately excluded: on a phone the edit view puts the editor over
   the page, so a card cannot be grabbed there - the "Visible widgets" list does
   the reordering instead.
   ========================================================================== */
(() => {
    const DRAG_THRESHOLD = 8; // px before a press becomes a drag
    const isEditing = () => document.body.classList.contains('layout-edit-active');
    const dragSupported = () => !window.matchMedia('(max-width: 899px)').matches;

    const initLayoutDrag = (config) => {
        const container = document.querySelector(config.container);
        const forms = [...document.querySelectorAll(config.editor)];
        if (!container || forms.length === 0) {
            return;
        }
        if (container.dataset.layoutDragReady === '1') {
            return;
        }
        container.dataset.layoutDragReady = '1';

        // Only the cards that are actually laid out: a hidden widget has no place in
        // the running order, and dropping onto one would be dropping into nothing.
        const cards = () => [...container.querySelectorAll(config.item)]
            .filter((el) => el.offsetParent !== null || el.getBoundingClientRect().height > 0);

        const keyOf = (el) => el.getAttribute(config.keyAttr) || '';

        const labels = {
            drag: document.body.dataset.layoutDragLabel || 'Drag to reorder',
            remove: document.body.dataset.layoutRemoveLabel || 'Remove widget',
            add: document.body.dataset.layoutAddLabel || 'Add widget',
            visible: document.body.dataset.layoutVisibleLabel || 'Visible',
        };

        let dirty = false;
        const markDirty = () => {
            dirty = true;
            document.body.classList.add('layout-has-unsaved');
        };

        const editorRows = () => forms.flatMap((form) => {
            const list = form.querySelector(config.list);
            return list ? [...list.querySelectorAll(config.listItem)] : [];
        });

        const checkboxForKey = (key) => {
            for (const form of forms) {
                const checkbox = form.querySelector(`${config.list} input[type="checkbox"][value="${CSS.escape(key)}"]`);
                if (checkbox instanceof HTMLInputElement) {
                    return checkbox;
                }
            }
            return null;
        };

        const cardForKey = (key) => [...container.querySelectorAll(config.item)]
            .find((card) => keyOf(card) === key) || null;

        const refreshVisibilityButtons = () => {
            editorRows().forEach((row) => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                const button = row.querySelector('[data-layout-visibility-toggle]');
                if (!(checkbox instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement)) {
                    return;
                }
                button.dataset.visible = checkbox.checked ? '1' : '0';
                button.setAttribute('aria-pressed', checkbox.checked ? 'true' : 'false');
                button.textContent = checkbox.checked ? labels.visible : labels.add;
            });
        };

        const syncVisibilityFromEditors = () => {
            editorRows().forEach((row, index) => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (!(checkbox instanceof HTMLInputElement)) {
                    return;
                }
                const card = cardForKey(checkbox.value);
                if (!(card instanceof HTMLElement)) {
                    return;
                }
                card.hidden = !checkbox.checked;
                card.classList.toggle('is-layout-hidden', !checkbox.checked);
                if (checkbox.checked) {
                    card.style.removeProperty('display');
                }
                card.style.order = String((index + 1) * 10);
                container.querySelectorAll(`[data-team-follows="${CSS.escape(checkbox.value)}"]`).forEach((follower) => {
                    follower.style.order = String((index + 1) * 10 - 1);
                });
                if (config.orderInput) {
                    const input = row.querySelector(`[name="${config.orderInput}[${CSS.escape(checkbox.value)}]"]`);
                    if (input instanceof HTMLInputElement) {
                        input.value = String(index + 1);
                    }
                }
            });
            refreshVisibilityButtons();
        };

        const setCardVisible = (key, visible) => {
            const checkbox = checkboxForKey(key);
            if (!(checkbox instanceof HTMLInputElement)) {
                return;
            }
            checkbox.checked = visible;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            syncVisibilityFromEditors();
            markDirty();
        };

        container.querySelectorAll(config.item).forEach((card) => {
            if (card.querySelector(':scope > [data-layout-card-controls]')) {
                return;
            }
            const controls = document.createElement('div');
            controls.className = 'layout-card-controls';
            controls.dataset.layoutCardControls = '1';

            const handle = document.createElement('span');
            handle.className = 'layout-card-drag-handle';
            handle.setAttribute('aria-hidden', 'true');
            handle.title = labels.drag;
            handle.textContent = '\u283f';

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'layout-card-remove';
            remove.dataset.layoutRemoveCard = keyOf(card);
            remove.setAttribute('aria-label', labels.remove);
            remove.title = labels.remove;
            remove.textContent = '\u00d7';

            controls.append(handle, remove);
            card.appendChild(controls);
        });

        forms.forEach((form) => {
            form.querySelectorAll(config.listItem).forEach((row) => {
                if (row.querySelector('[data-layout-visibility-toggle]')) {
                    return;
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'layout-visibility-toggle';
                button.dataset.layoutVisibilityToggle = '1';
                row.appendChild(button);
            });
        });
        refreshVisibilityButtons();

        container.addEventListener('click', (event) => {
            const remove = event.target instanceof Element ? event.target.closest('[data-layout-remove-card]') : null;
            if (!(remove instanceof HTMLButtonElement)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            setCardVisible(String(remove.dataset.layoutRemoveCard || ''), false);
        });

        forms.forEach((form) => {
            form.addEventListener('click', (event) => {
                const toggle = event.target instanceof Element ? event.target.closest('[data-layout-visibility-toggle]') : null;
                if (!(toggle instanceof HTMLButtonElement)) {
                    return;
                }
                event.preventDefault();
                const row = toggle.closest(config.listItem);
                const checkbox = row?.querySelector('input[type="checkbox"]');
                if (!(checkbox instanceof HTMLInputElement)) {
                    return;
                }
                setCardVisible(checkbox.value, !checkbox.checked);
            });
            form.addEventListener('change', (event) => {
                if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
                    syncVisibilityFromEditors();
                }
            });
        });

        /* ---- the drag result has to reach the form, or Save saves the old order ----
           Three things move together: the card's visual order, the matching row in the
           editor list (team derives its order purely from that list's DOM order), and
           the hidden order input where one exists. */
        const syncOrder = () => {
            cards().forEach((el, index) => {
                const key = keyOf(el);
                el.style.order = String((index + 1) * 10);

                // A follower card (team missions rides with members) has no slot of its
                // own: it takes the order of the card it belongs to, minus one, so a drag
                // never leaves it stranded on the other side of the page.
                container.querySelectorAll(`[data-team-follows="${CSS.escape(key)}"]`)
                    .forEach((follower) => {
                        follower.style.order = String((index + 1) * 10 - 1);
                    });

                forms.forEach((form) => {
                    if (config.orderInput) {
                        const input = form.querySelector(
                            `[name="${config.orderInput}[${key}]"]`
                        );
                        if (input instanceof HTMLInputElement) {
                            input.value = String(index + 1);
                        }
                    }

                    const list = form.querySelector(config.list);
                    if (!list) {
                        return;
                    }
                    const row = list.querySelector(
                        `input[type="checkbox"][value="${CSS.escape(key)}"]`
                    );
                    const item = row ? row.closest(config.listItem) : null;
                    if (item) {
                        list.appendChild(item); // append in card order -> list ends up sorted
                    }
                });
            });
            markDirty();
        };

        /* ---- unsaved-changes guard ---- */
        forms.forEach((form) => {
            form.addEventListener('change', markDirty);
            form.addEventListener('submit', () => {
                dirty = false;
                document.body.classList.remove('layout-has-unsaved');
            });
        });

        window.addEventListener('beforeunload', (event) => {
            if (!dirty || !isEditing()) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });

        document.addEventListener('click', (event) => {
            if (!dirty || !isEditing()) {
                return;
            }
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link || link.closest(config.editor)) {
                return;
            }
            if (link.target === '_blank' || link.href.startsWith('javascript:')) {
                return;
            }
            const message = container.dataset.unsavedMessage
                || 'You have unsaved layout changes. Leave without saving?';
            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            } else {
                dirty = false;
            }
        }, true);

        /* ---- pointer drag ---- */
        let dragEl = null;
        let placeholder = null;
        let startX = 0;
        let startY = 0;
        let offX = 0;
        let offY = 0;
        let active = false;

        const cleanup = () => {
            if (dragEl) {
                dragEl.classList.remove('is-dragging');
                dragEl.style.position = '';
                dragEl.style.left = '';
                dragEl.style.top = '';
                dragEl.style.width = '';
                dragEl.style.zIndex = '';
                dragEl.style.pointerEvents = '';
            }
            if (placeholder && placeholder.parentNode) {
                placeholder.remove();
            }
            document.body.classList.remove('layout-dragging');
            dragEl = null;
            placeholder = null;
            active = false;
        };

        container.addEventListener('pointerdown', (event) => {
            if (!isEditing() || event.button !== 0 || !dragSupported()) {
                return;
            }
            const target = event.target instanceof Element ? event.target : null;
            const handle = target ? target.closest('.layout-card-drag-handle') : null;
            const el = handle ? handle.closest(config.item) : (target ? target.closest(config.item) : null);
            if (!el || !container.contains(el)) {
                return;
            }
            const rect = el.getBoundingClientRect();
            const inCardGripStrip = event.clientY >= rect.top && event.clientY <= rect.top + 48;
            const onInteractiveControl = Boolean(target?.closest('a, button, input, select, textarea, summary, [role="button"]'));
            // The visible handle is the clearest affordance, while the card's top
            // strip keeps the original desktop "grab the card" interaction working.
            // Content and controls below it remain fully selectable/clickable.
            if (!handle && (!inCardGripStrip || onInteractiveControl)) {
                return;
            }
            event.preventDefault();
            dragEl = el;
            startX = event.clientX;
            startY = event.clientY;
            offX = event.clientX - rect.left;
            offY = event.clientY - rect.top;
            active = false;
            try { (handle || el).setPointerCapture(event.pointerId); } catch (_) { /* not fatal */ }
        });

        window.addEventListener('pointermove', (event) => {
            if (!dragEl) {
                return;
            }
            if (!active) {
                if (Math.hypot(event.clientX - startX, event.clientY - startY) < DRAG_THRESHOLD) {
                    return;
                }
                active = true;
                const rect = dragEl.getBoundingClientRect();
                placeholder = document.createElement('div');
                placeholder.className = 'dashboard-drop-placeholder';
                placeholder.style.height = `${rect.height}px`;
                placeholder.style.order = dragEl.style.order;
                dragEl.parentNode.insertBefore(placeholder, dragEl);
                dragEl.classList.add('is-dragging');
                dragEl.style.width = `${rect.width}px`;
                dragEl.style.position = 'fixed';
                dragEl.style.zIndex = '9999';
                dragEl.style.pointerEvents = 'none';
                document.body.classList.add('layout-dragging');
            }
            event.preventDefault();
            dragEl.style.left = `${event.clientX - offX}px`;
            dragEl.style.top = `${event.clientY - offY}px`;

            // Pick the drop target by nearest centre rather than strict containment:
            // these are grids, so the pointer often sits in the gutter between cards,
            // where a contains() test finds nothing and the drag feels dead.
            let over = null;
            let best = Infinity;
            for (const card of cards()) {
                if (card === dragEl || card === placeholder) {
                    continue;
                }
                const rect = card.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) {
                    continue;
                }
                const distance = Math.hypot(
                    event.clientX - (rect.left + rect.width / 2),
                    event.clientY - (rect.top + rect.height / 2)
                );
                if (distance < best) {
                    best = distance;
                    over = card;
                }
            }
            if (over && placeholder) {
                const rect = over.getBoundingClientRect();
                // Reading order: past the vertical midpoint (or, within the same row,
                // past the horizontal midpoint) means "insert after".
                // Full-width cards form a vertical list: their lower half must mean
                // “after”. Only use the horizontal midpoint for cards that actually
                // occupy a column in a multi-column grid.
                const containerRect = container.getBoundingClientRect();
                const sameRow = rect.width < containerRect.width * 0.8
                    && Math.abs(event.clientY - (rect.top + rect.height / 2)) < rect.height / 2;
                const verticalMidpoint = rect.top + rect.height / 2;
                // Dropping exactly on the centre of a full-width card used to be
                // a no-op. Resolve that neutral point from the drag direction so
                // moving down inserts after and moving up inserts before.
                const afterVertically = Math.abs(event.clientY - verticalMidpoint) <= 1
                    ? event.clientY > startY
                    : event.clientY > verticalMidpoint;
                const after = sameRow
                    ? event.clientX > rect.left + rect.width / 2
                    : afterVertically;
                placeholder.style.order = over.style.order;
                over.parentNode.insertBefore(placeholder, after ? over.nextSibling : over);
            }
        }, { passive: false });

        window.addEventListener('pointerup', () => {
            if (!dragEl) {
                return;
            }
            if (active && placeholder) {
                placeholder.parentNode.insertBefore(dragEl, placeholder);
                cleanup();
                syncOrder();
            } else {
                cleanup();
            }
        });

        window.addEventListener('pointercancel', cleanup);
    };

    const LAYOUTS = [
        {
            container: '.dashboard-layout',
            item: '[data-dashboard-widget]',
            keyAttr: 'data-dashboard-widget',
            editor: '[data-dashboard-layout-editor]',
            list: '[data-dashboard-layout-list]',
            listItem: '[data-dashboard-layout-item]',
            orderInput: 'dashboard_order',
        },
        {
            container: '.analytics-page',
            item: '[data-analytics-section]',
            keyAttr: 'data-analytics-section',
            editor: '[data-analytics-layout-editor]',
            list: '[data-analytics-layout-list]',
            listItem: '[data-analytics-layout-item]',
            orderInput: 'analytics_order',
        },
        {
            container: '.profile-home-grid',
            item: '[data-profile-block]',
            keyAttr: 'data-profile-block',
            editor: '[data-profile-layout-editor]',
            list: '[data-profile-layout-list]',
            listItem: '[data-profile-layout-item]',
            orderInput: 'profile_order',
        },
        {
            // Team has no order inputs: its running order is the DOM order of the
            // checkboxes it submits, which syncOrder() rewrites for us.
            container: '.team-layout-grid',
            item: '[data-team-widget]',
            keyAttr: 'data-team-widget',
            editor: '[data-team-layout-editor]',
            list: '[data-team-layout-list]',
            listItem: '[data-team-layout-item]',
            orderInput: '',
        },
    ];

    const initAll = () => LAYOUTS.forEach(initLayoutDrag);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    document.addEventListener('fc:afterPageSwap', initAll);
})();

/* Structured exercise-guide builder. It keeps the existing newline payload for
   compatibility, while giving mobile users real items they can add, reorder and
   remove without editing three large text blobs. */
(() => {
    const initBuilder = (builder) => {
        if (!(builder instanceof HTMLElement) || builder.dataset.guideBuilderReady === '1') return;
        builder.dataset.guideBuilderReady = '1';
        const limit = Math.max(1, Math.min(50, Number(builder.dataset.maxItems) || 20));
        const sections = [...builder.querySelectorAll('[data-guide-section]')];

        const autoGrow = (input) => {
            if (!(input instanceof HTMLTextAreaElement)) return;
            input.style.height = 'auto';
            input.style.height = `${Math.min(120, Math.max(44, input.scrollHeight))}px`;
        };
        const sectionParts = (section) => ({
            items: section.querySelector('[data-guide-items]'),
            output: section.querySelector('[data-guide-output]'),
            counter: section.querySelector('[data-guide-count]'),
            empty: section.querySelector('[data-guide-empty]'),
            add: section.querySelector('[data-guide-add]'),
            template: section.querySelector('[data-guide-item-template]'),
        });
        const syncSection = (section) => {
            const { items, output, counter, empty, add } = sectionParts(section);
            if (!(items instanceof HTMLElement)) return;
            const rows = [...items.querySelectorAll(':scope > [data-guide-item]')];
            const values = [];
            rows.forEach((row, index) => {
                const input = row.querySelector('[data-guide-item-input]');
                const value = input instanceof HTMLTextAreaElement
                    ? input.value.replace(/\s*(?:\r?\n)+\s*/g, ' ').trim()
                    : '';
                if (value !== '') values.push(value);
                const position = row.querySelector('[data-guide-index]');
                if (position instanceof HTMLElement) position.textContent = String(index + 1);
                const up = row.querySelector('[data-guide-move="up"]');
                const down = row.querySelector('[data-guide-move="down"]');
                if (up instanceof HTMLButtonElement) up.disabled = index === 0;
                if (down instanceof HTMLButtonElement) down.disabled = index === rows.length - 1;
            });
            if (output instanceof HTMLTextAreaElement) output.value = values.join('\n');
            if (counter instanceof HTMLElement) {
                counter.textContent = String(values.length);
                counter.setAttribute('aria-label', String(builder.dataset.countTemplate || '{count}').replace('{count}', String(values.length)));
            }
            if (empty instanceof HTMLElement) empty.hidden = rows.length > 0;
            if (add instanceof HTMLButtonElement) add.disabled = rows.length >= limit;
        };
        const createItem = (section, value = '', after = null, focus = true) => {
            const { items, template } = sectionParts(section);
            if (!(items instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) return null;
            if (items.querySelectorAll(':scope > [data-guide-item]').length >= limit) return null;
            const fragment = template.content.cloneNode(true);
            const item = fragment.querySelector('[data-guide-item]');
            if (!(item instanceof HTMLElement)) return null;
            const input = item.querySelector('[data-guide-item-input]');
            if (input instanceof HTMLTextAreaElement) input.value = String(value || '').replace(/\s*(?:\r?\n)+\s*/g, ' ').trim();
            if (after instanceof HTMLElement && after.parentElement === items) after.insertAdjacentElement('afterend', item);
            else items.appendChild(item);
            autoGrow(input);
            syncSection(section);
            if (focus && input instanceof HTMLTextAreaElement) {
                input.focus({ preventScroll: true });
                if (window.matchMedia('(max-width: 700px)').matches) {
                    window.requestAnimationFrame(() => input.scrollIntoView({ block: 'center', inline: 'nearest' }));
                }
            }
            return item;
        };

        sections.forEach((section) => {
            if (!(section instanceof HTMLDetailsElement)) return;
            section.querySelectorAll('[data-guide-item-input]').forEach(autoGrow);
            syncSection(section);
            section.addEventListener('toggle', () => {
                if (!section.open || !window.matchMedia('(max-width: 700px)').matches) return;
                sections.forEach((other) => {
                    if (other !== section && other instanceof HTMLDetailsElement) other.open = false;
                });
            });
            section.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) return;
                const add = target.closest('[data-guide-add]');
                if (add) {
                    section.open = true;
                    createItem(section);
                    return;
                }
                const item = target.closest('[data-guide-item]');
                if (!(item instanceof HTMLElement)) return;
                const remove = target.closest('[data-guide-remove]');
                if (remove) {
                    const nextFocus = item.previousElementSibling?.querySelector('[data-guide-item-input]')
                        || item.nextElementSibling?.querySelector('[data-guide-item-input]')
                        || section.querySelector('[data-guide-add]');
                    item.remove();
                    syncSection(section);
                    if (nextFocus instanceof HTMLElement) nextFocus.focus({ preventScroll: true });
                    return;
                }
                const move = target.closest('[data-guide-move]');
                if (!(move instanceof HTMLButtonElement)) return;
                const items = item.parentElement;
                const sibling = move.dataset.guideMove === 'up' ? item.previousElementSibling : item.nextElementSibling;
                if (!(items instanceof HTMLElement) || !(sibling instanceof HTMLElement)) return;
                if (move.dataset.guideMove === 'up') items.insertBefore(item, sibling);
                else items.insertBefore(sibling, item);
                syncSection(section);
                move.focus({ preventScroll: true });
            });
            section.addEventListener('input', (event) => {
                const input = event.target;
                if (!(input instanceof HTMLTextAreaElement) || !input.matches('[data-guide-item-input]')) return;
                const lines = input.value.split(/\r?\n/);
                if (lines.length > 1) {
                    input.value = lines.shift() || '';
                    let anchor = input.closest('[data-guide-item]');
                    lines.filter((line) => line.trim() !== '').forEach((line) => {
                        anchor = createItem(section, line, anchor, false) || anchor;
                    });
                    const lastInput = anchor instanceof HTMLElement ? anchor.querySelector('[data-guide-item-input]') : null;
                    if (lastInput instanceof HTMLTextAreaElement) lastInput.focus({ preventScroll: true });
                }
                autoGrow(input);
                syncSection(section);
            });
            section.addEventListener('keydown', (event) => {
                const input = event.target;
                if (!(input instanceof HTMLTextAreaElement) || !input.matches('[data-guide-item-input]')) return;
                const item = input.closest('[data-guide-item]');
                if (!(item instanceof HTMLElement)) return;
                if (event.key === 'Enter') {
                    event.preventDefault();
                    createItem(section, '', item);
                    return;
                }
                if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                    event.preventDefault();
                    const button = item.querySelector(`[data-guide-move="${event.key === 'ArrowUp' ? 'up' : 'down'}"]`);
                    if (button instanceof HTMLButtonElement && !button.disabled) button.click();
                    input.focus({ preventScroll: true });
                    return;
                }
                if (event.key === 'Backspace' && input.value === '') {
                    const rows = item.parentElement?.querySelectorAll(':scope > [data-guide-item]') || [];
                    if (rows.length > 1) {
                        event.preventDefault();
                        const previous = item.previousElementSibling?.querySelector('[data-guide-item-input]');
                        item.remove();
                        syncSection(section);
                        if (previous instanceof HTMLTextAreaElement) previous.focus({ preventScroll: true });
                    }
                }
            });
        });

        const form = builder.closest('form');
        form?.addEventListener('submit', () => sections.forEach(syncSection));
    };

    const init = () => document.querySelectorAll('[data-workout-guide-builder]').forEach(initBuilder);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Ordered exercise photo gallery. Existing paths stay opaque to the client;
   fresh files use new:N tokens that the server resolves after upload. */
(() => {
    'use strict';

    const imagePositionPresets = {
        top: { x: 50, y: 18 },
        bottom: { x: 50, y: 82 },
        left: { x: 18, y: 50 },
        right: { x: 82, y: 50 },
        center: { x: 50, y: 50 },
    };
    const positionDetails = (value) => {
        const raw = String(value || '').trim().toLowerCase();
        if (Object.hasOwn(imagePositionPresets, raw)) return { value: raw, ...imagePositionPresets[raw] };
        const match = /^focal:(\d{1,3}):(\d{1,3})$/.exec(raw);
        if (match) {
            const x = Number(match[1]);
            const y = Number(match[2]);
            if (x >= 0 && x <= 100 && y >= 0 && y <= 100) return { value: `focal:${x}:${y}`, x, y };
        }
        return { value: 'center', ...imagePositionPresets.center };
    };
    const positionCss = (value) => {
        const position = positionDetails(value);
        return `${position.x}% ${position.y}%`;
    };

    const initGallery = (gallery) => {
        if (!(gallery instanceof HTMLDetailsElement) || gallery.dataset.workoutGalleryReady === '1') return;
        gallery.dataset.workoutGalleryReady = '1';
        const form = gallery.closest('form');
        const list = gallery.querySelector('[data-workout-gallery-list]');
        const template = gallery.querySelector('[data-workout-gallery-template]');
        const fileInput = gallery.querySelector('[data-workout-gallery-input]');
        const empty = gallery.querySelector('[data-workout-gallery-empty]');
        const status = gallery.querySelector('[data-workout-gallery-status]');
        const focusStatus = gallery.querySelector('[data-workout-gallery-focus-status]');
        const positionInputs = [...gallery.querySelectorAll('[data-workout-image-position-input]')];
        const focalEditor = gallery.querySelector('[data-workout-gallery-focal-editor]');
        const focalPreview = gallery.querySelector('[data-workout-gallery-focal-preview]');
        const focalSurface = gallery.querySelector('[data-workout-gallery-focal-surface]');
        const focalMarker = gallery.querySelector('[data-workout-gallery-focal-marker]');
        const focalX = gallery.querySelector('[data-workout-gallery-focal-x]');
        const focalY = gallery.querySelector('[data-workout-gallery-focal-y]');
        const focalXOutput = gallery.querySelector('[data-workout-gallery-focal-x-output]');
        const focalYOutput = gallery.querySelector('[data-workout-gallery-focal-y-output]');
        const focalValue = gallery.querySelector('[data-workout-gallery-focal-value]');
        const limit = Math.max(1, Number.parseInt(gallery.dataset.galleryLimit || '4', 10) || 4);
        if (!form || !list || !(template instanceof HTMLTemplateElement) || !(fileInput instanceof HTMLInputElement)) return;

        const items = () => [...list.querySelectorAll('[data-workout-gallery-item]')];
        const storedItems = () => items().filter((item) => !String(item.dataset.galleryToken || '').startsWith('new:'));
        const announceChange = () => gallery.dispatchEvent(new CustomEvent('workout:gallerychange', { bubbles: true }));
        const selectedPosition = () => form.querySelector('[data-workout-image-position-input]:checked')?.value || 'center';
        const normalizedPosition = (value) => positionDetails(value).value;
        const itemPosition = (item) => normalizedPosition(item?.querySelector('[data-workout-gallery-position]')?.value || 'center');
        const itemCaption = (item) => String(item?.querySelector('[data-workout-gallery-caption]')?.value || '').trim();
        const fileKey = (file) => `${file.name}:${file.size}:${file.type}`;

        const update = (announce = true) => {
            const rows = items();
            let selectedCover = rows.find((item) => item.querySelector('[data-workout-gallery-cover]')?.checked);
            if (!selectedCover && rows[0]) {
                const firstCover = rows[0].querySelector('[data-workout-gallery-cover]');
                if (firstCover) firstCover.checked = true;
                selectedCover = rows[0];
            }
            let selectedFocus = rows.find((item) => item.classList.contains('is-editing'));
            if (!selectedFocus && rows[0]) selectedFocus = selectedCover || rows[0];
            rows.forEach((item, index) => {
                item.classList.toggle('is-cover', item === selectedCover);
                item.classList.toggle('is-editing', item === selectedFocus);
                const figure = item.querySelector('figure');
                if (figure) figure.dataset.photoNumber = String(index + 1);
                const image = item.querySelector('[data-workout-gallery-image]');
                const position = itemPosition(item);
                if (image) {
                    image.alt = itemCaption(item) || `${gallery.dataset.galleryPhotoLabel || 'Photo'} ${index + 1}`;
                    image.style.objectPosition = positionCss(position);
                }
                const focus = item.querySelector('[data-workout-gallery-focus]');
                if (focus) {
                    focus.setAttribute('aria-pressed', item === selectedFocus ? 'true' : 'false');
                    focus.setAttribute('aria-label', String(gallery.dataset.galleryAdjustLabel || 'Adjust photo {count}').replace('{count}', String(index + 1)));
                }
                const up = item.querySelector('[data-workout-gallery-move="up"]');
                const down = item.querySelector('[data-workout-gallery-move="down"]');
                if (up) up.disabled = index === 0;
                if (down) down.disabled = index === rows.length - 1;
            });
            if (selectedFocus) {
                const focusPosition = itemPosition(selectedFocus);
                const coordinates = positionDetails(focusPosition);
                const selectedImage = selectedFocus.querySelector('[data-workout-gallery-image]');
                const coordinateText = String(gallery.dataset.galleryFocalTemplate || '{x}% · {y}%')
                    .replace('{x}', String(coordinates.x))
                    .replace('{y}', String(coordinates.y));
                positionInputs.forEach((input) => {
                    input.checked = input.value === focusPosition;
                });
                if (focusStatus) {
                    focusStatus.hidden = false;
                    focusStatus.textContent = String(gallery.dataset.gallerySelectedTemplate || 'Editing photo {count}')
                        .replace('{count}', String(rows.indexOf(selectedFocus) + 1));
                }
                if (focalEditor instanceof HTMLElement) focalEditor.hidden = false;
                if (focalPreview instanceof HTMLImageElement && selectedImage instanceof HTMLImageElement) {
                    focalPreview.src = selectedImage.currentSrc || selectedImage.src;
                    focalPreview.style.objectPosition = positionCss(focusPosition);
                }
                if (focalMarker instanceof HTMLElement) {
                    focalMarker.style.left = `${coordinates.x}%`;
                    focalMarker.style.top = `${coordinates.y}%`;
                }
                if (focalX instanceof HTMLInputElement) focalX.value = String(coordinates.x);
                if (focalY instanceof HTMLInputElement) focalY.value = String(coordinates.y);
                if (focalXOutput) focalXOutput.textContent = `${coordinates.x}%`;
                if (focalYOutput) focalYOutput.textContent = `${coordinates.y}%`;
                if (focalValue) focalValue.textContent = coordinateText;
                if (focalSurface instanceof HTMLButtonElement) {
                    focalSurface.setAttribute('aria-label', `${gallery.dataset.galleryFocalLabel || ''} ${coordinateText}`.trim());
                }
            } else {
                if (focusStatus) {
                    focusStatus.hidden = true;
                    focusStatus.textContent = '';
                }
                if (focalEditor instanceof HTMLElement) focalEditor.hidden = true;
                if (focalPreview instanceof HTMLImageElement) focalPreview.removeAttribute('src');
            }
            gallery.classList.toggle('has-media', rows.length > 0);
            if (empty) empty.hidden = rows.length > 0;
            if (status) status.textContent = String(gallery.dataset.galleryCountTemplate || '{count} / 4').replace('{count}', String(rows.length));
            if (announce) announceChange();
        };

        const createNewItem = (file, index, previousState = null) => {
            const fragment = template.content.cloneNode(true);
            const item = fragment.querySelector('[data-workout-gallery-item]');
            const token = `new:${index}`;
            item.dataset.galleryToken = token;
            const order = item.querySelector('[data-workout-gallery-order]');
            const position = item.querySelector('[data-workout-gallery-position]');
            const caption = item.querySelector('[data-workout-gallery-caption]');
            const cover = item.querySelector('[data-workout-gallery-cover]');
            const image = item.querySelector('[data-workout-gallery-image]');
            if (order) order.value = token;
            if (position) position.value = normalizedPosition(previousState?.position || selectedPosition());
            if (caption) caption.value = String(previousState?.caption || '');
            if (cover) cover.value = token;
            item.dataset.galleryFileKey = fileKey(file);
            if (image) {
                const objectUrl = URL.createObjectURL(file);
                image.src = objectUrl;
                image.dataset.galleryObjectUrl = objectUrl;
            }
            return item;
        };

        const revokeItem = (item) => {
            const image = item?.querySelector?.('[data-gallery-object-url], [data-workout-gallery-image][data-gallery-object-url]');
            const objectUrl = image?.dataset?.galleryObjectUrl || '';
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };

        const renderNewFiles = () => {
            const previousCover = items().find((item) => item.querySelector('[data-workout-gallery-cover]')?.checked);
            const previousFocus = items().find((item) => item.classList.contains('is-editing'));
            const previousCoverToken = String(previousCover?.dataset.galleryToken || '');
            const previousCoverFileKey = String(previousCover?.dataset.galleryFileKey || '');
            const previousFocusToken = String(previousFocus?.dataset.galleryToken || '');
            const previousFocusFileKey = String(previousFocus?.dataset.galleryFileKey || '');
            const previousNewState = new Map(items()
                .filter((item) => String(item.dataset.galleryToken || '').startsWith('new:') && item.dataset.galleryFileKey)
                .map((item) => [String(item.dataset.galleryFileKey), {
                    caption: itemCaption(item),
                    position: itemPosition(item),
                }]));
            items().filter((item) => String(item.dataset.galleryToken || '').startsWith('new:')).forEach((item) => {
                revokeItem(item);
                item.remove();
            });
            const available = Math.max(0, limit - storedItems().length);
            let files = [...(fileInput.files || [])].filter((file) => file.type.startsWith('image/')).slice(0, available);
            if (files.length !== (fileInput.files?.length || 0) && typeof DataTransfer === 'function') {
                const transfer = new DataTransfer();
                files.forEach((file) => transfer.items.add(file));
                fileInput.files = transfer.files;
                files = [...fileInput.files];
            }
            files.forEach((file, index) => list.appendChild(createNewItem(file, index, previousNewState.get(fileKey(file)) || null)));
            const nextRows = items();
            const restoredCover = nextRows.find((item) => (
                previousCoverFileKey !== ''
                    ? item.dataset.galleryFileKey === previousCoverFileKey
                    : item.dataset.galleryToken === previousCoverToken
            ));
            const restoredFocus = nextRows.find((item) => (
                previousFocusFileKey !== ''
                    ? item.dataset.galleryFileKey === previousFocusFileKey
                    : item.dataset.galleryToken === previousFocusToken
            ));
            const restoredCoverInput = restoredCover?.querySelector('[data-workout-gallery-cover]');
            if (restoredCoverInput) restoredCoverInput.checked = true;
            if (restoredFocus) restoredFocus.classList.add('is-editing');
            update();
        };

        fileInput.addEventListener('change', renderNewFiles);
        list.addEventListener('input', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target?.matches('[data-workout-gallery-caption]')) return;
            const item = target.closest('[data-workout-gallery-item]');
            const image = item?.querySelector('[data-workout-gallery-image]');
            const index = items().indexOf(item);
            if (image) image.alt = String(target.value || '').trim() || `${gallery.dataset.galleryPhotoLabel || 'Photo'} ${Math.max(0, index) + 1}`;
            announceChange();
        });
        list.addEventListener('change', (event) => {
            const cover = event.target instanceof Element ? event.target.closest('[data-workout-gallery-cover]') : null;
            if (!cover) return;
            items().forEach((row) => row.classList.toggle('is-editing', row === cover.closest('[data-workout-gallery-item]')));
            update();
        });
        list.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('button') : null;
            const item = button?.closest('[data-workout-gallery-item]');
            if (!button || !item) return;
            const rows = items();
            const index = rows.indexOf(item);
            if (button.matches('[data-workout-gallery-focus]')) {
                rows.forEach((row) => row.classList.toggle('is-editing', row === item));
                update();
                button.focus({ preventScroll: true });
                if (window.matchMedia('(max-width: 700px)').matches && focalEditor instanceof HTMLElement) {
                    window.requestAnimationFrame(() => focalEditor.scrollIntoView({ block: 'nearest' }));
                }
                return;
            }
            if (button.matches('[data-workout-gallery-move="up"]') && index > 0) {
                list.insertBefore(item, rows[index - 1]);
                update();
                button.focus({ preventScroll: true });
                return;
            }
            if (button.matches('[data-workout-gallery-move="down"]') && index >= 0 && index < rows.length - 1) {
                list.insertBefore(rows[index + 1], item);
                update();
                button.focus({ preventScroll: true });
                return;
            }
            if (!button.matches('[data-workout-gallery-remove]')) return;
            const token = String(item.dataset.galleryToken || '');
            if (token.startsWith('new:') && typeof DataTransfer === 'function') {
                const removeIndex = Number.parseInt(token.slice(4), 10);
                const transfer = new DataTransfer();
                [...(fileInput.files || [])].forEach((file, fileIndex) => {
                    if (fileIndex !== removeIndex) transfer.items.add(file);
                });
                fileInput.files = transfer.files;
                renderNewFiles();
                const nextRows = items();
                const nextFocus = nextRows[Math.min(index, nextRows.length - 1)]?.querySelector('[data-workout-gallery-remove]') || fileInput;
                if (nextFocus instanceof HTMLElement) nextFocus.focus({ preventScroll: true });
            } else {
                revokeItem(item);
                item.remove();
                update();
                const nextRows = items();
                const nextFocus = nextRows[Math.min(index, nextRows.length - 1)]?.querySelector('[data-workout-gallery-remove]') || fileInput;
                if (nextFocus instanceof HTMLElement) nextFocus.focus({ preventScroll: true });
            }
        });
        const setFocusedPosition = (value) => {
            const selectedFocus = items().find((item) => item.classList.contains('is-editing'));
            const position = selectedFocus?.querySelector('[data-workout-gallery-position]');
            if (!(position instanceof HTMLInputElement)) return;
            position.value = normalizedPosition(value);
            update();
        };
        const applyFocalControls = () => {
            if (!(focalX instanceof HTMLInputElement) || !(focalY instanceof HTMLInputElement)) return;
            const x = Math.max(0, Math.min(100, Math.round(Number(focalX.value) || 0)));
            const y = Math.max(0, Math.min(100, Math.round(Number(focalY.value) || 0)));
            setFocusedPosition(`focal:${x}:${y}`);
        };
        focalX?.addEventListener('input', applyFocalControls);
        focalY?.addEventListener('input', applyFocalControls);
        focalSurface?.addEventListener('click', (event) => {
            if (!(focalSurface instanceof HTMLButtonElement)) return;
            if (event.detail === 0) {
                if (focalX instanceof HTMLInputElement) focalX.focus({ preventScroll: true });
                return;
            }
            const rect = focalSurface.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;
            const x = Math.max(0, Math.min(100, Math.round(((event.clientX - rect.left) / rect.width) * 100)));
            const y = Math.max(0, Math.min(100, Math.round(((event.clientY - rect.top) / rect.height) * 100)));
            setFocusedPosition(`focal:${x}:${y}`);
        });
        positionInputs.forEach((input) => input.addEventListener('change', () => {
            setFocusedPosition(input.value);
        }));
        update(false);
    };

    const init = () => document.querySelectorAll('[data-workout-gallery-editor]').forEach(initGallery);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Exercise media editor: local image and safe video previews for personal and
   admin exercise forms. It is PJAX-aware and never injects user HTML. */
(() => {
    'use strict';

    const parseVideo = (rawValue) => {
        const raw = String(rawValue || '').trim();
        if (!raw) return null;

        let url;
        try {
            url = new URL(raw);
        } catch (_) {
            return null;
        }
        if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;

        const host = url.hostname.toLowerCase().replace(/^www\./, '');
        let id = '';
        if (host === 'youtu.be') {
            id = url.pathname.split('/').filter(Boolean)[0] || '';
        } else if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'youtube-nocookie.com') {
            const parts = url.pathname.split('/').filter(Boolean);
            id = url.pathname === '/watch' ? (url.searchParams.get('v') || '') : (parts[1] || '');
        }
        if (id && /^[a-zA-Z0-9_-]{6,20}$/.test(id)) {
            return {
                type: 'embed',
                provider: 'youtube',
                url: `https://www.youtube-nocookie.com/embed/${id}`,
                thumbnail: `https://i.ytimg.com/vi/${id}/hqdefault.jpg`,
            };
        }

        if (host === 'vimeo.com' || host === 'player.vimeo.com') {
            const vimeoId = url.pathname.split('/').filter(Boolean).find((part) => /^\d+$/.test(part));
            if (vimeoId) return { type: 'embed', provider: 'vimeo', url: `https://player.vimeo.com/video/${vimeoId}` };
        }

        if (/\.(mp4|webm|ogv|ogg)$/i.test(url.pathname)) {
            return { type: 'video', provider: 'direct', url: url.href };
        }
        return { type: 'link', provider: 'link', url: url.href, label: url.hostname };
    };

    const renderVideo = (container, rawValue) => {
        if (!container) return;
        container.replaceChildren();
        const media = parseVideo(rawValue);
        if (!media) return;

        if (media.type === 'embed') {
            const frame = document.createElement('iframe');
            frame.src = media.url;
            frame.title = container.dataset.videoTitle || 'Exercise video';
            frame.loading = 'lazy';
            frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            frame.allowFullscreen = true;
            container.appendChild(frame);
            return;
        }
        if (media.type === 'video') {
            const video = document.createElement('video');
            video.src = media.url;
            video.controls = true;
            video.preload = 'metadata';
            container.appendChild(video);
            return;
        }
        const link = document.createElement('a');
        link.href = media.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = media.label || media.url;
        container.appendChild(link);
    };

    const initEditor = (form) => {
        if (!(form instanceof HTMLElement) || form.dataset.workoutMediaReady === '1') return;
        form.dataset.workoutMediaReady = '1';

        const editorSteps = [...form.querySelectorAll('[data-workout-editor-step]')];
        const editorStepTriggers = [...form.querySelectorAll('[data-workout-editor-step-trigger]')];
        const editorSectionInput = form.querySelector('[data-workout-editor-section-input]');
        const editorMobileQuery = window.matchMedia('(max-width: 700px)');
        const availableEditorSections = editorSteps.map((step) => step.dataset.workoutEditorStep || '').filter(Boolean);
        let activeEditorSection = availableEditorSections.includes(form.dataset.workoutEditorSection || '')
            ? form.dataset.workoutEditorSection
            : (availableEditorSections[0] || 'basics');
        let refreshLivePreview = () => {};

        const applyEditorSection = (updateUrl = false) => {
            const isMobile = editorMobileQuery.matches;
            editorSteps.forEach((step) => {
                step.hidden = isMobile && step.dataset.workoutEditorStep !== activeEditorSection;
            });
            editorStepTriggers.forEach((trigger) => {
                const selected = trigger.dataset.workoutEditorStepTrigger === activeEditorSection;
                trigger.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            form.dataset.workoutEditorSection = activeEditorSection;
            if (editorSectionInput) editorSectionInput.value = activeEditorSection;
            if (updateUrl) {
                const url = new URL(window.location.href);
                url.searchParams.set('editor_section', activeEditorSection);
                window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
            }
        };

        editorStepTriggers.forEach((trigger) => {
            trigger.addEventListener('click', () => {
                const requested = trigger.dataset.workoutEditorStepTrigger || '';
                if (!availableEditorSections.includes(requested)) return;
                activeEditorSection = requested;
                applyEditorSection(true);
            });
        });
        const syncEditorLayout = () => applyEditorSection(false);
        if (typeof editorMobileQuery.addEventListener === 'function') editorMobileQuery.addEventListener('change', syncEditorLayout);
        else if (typeof editorMobileQuery.addListener === 'function') editorMobileQuery.addListener(syncEditorLayout);
        applyEditorSection(false);

        const trainingDefaults = form.querySelector('[data-workout-training-defaults]');
        const exerciseTypeInput = form.querySelector('[data-workout-exercise-type]');
        if (trainingDefaults && exerciseTypeInput) {
            const defaultPanels = [...trainingDefaults.querySelectorAll('[data-workout-default-panel]')];
            const defaultStatus = trainingDefaults.querySelector('[data-workout-default-status]');
            const setsLabel = trainingDefaults.querySelector('[data-workout-default-sets-label]');
            const setsInput = trainingDefaults.querySelector('[data-workout-default-value="sets"]');
            const repsInput = trainingDefaults.querySelector('[data-workout-default-value="reps"]');
            const minutesInput = trainingDefaults.querySelector('[data-workout-default-value="minutes"]');
            const secondsInput = trainingDefaults.querySelector('[data-workout-default-value="seconds"]');
            const trackedInputs = [...trainingDefaults.querySelectorAll('input, select, textarea')];
            let activeType = exerciseTypeInput.value || 'strength';

            trackedInputs.forEach((input) => input.addEventListener('input', () => {
                input.dataset.workoutDefaultTouched = '1';
                syncDefaults(false);
            }));
            trackedInputs.forEach((input) => input.addEventListener('change', () => {
                input.dataset.workoutDefaultTouched = '1';
                syncDefaults(false);
            }));

            function syncDefaults(typeChanged = false) {
                const type = exerciseTypeInput.value || 'strength';
                if (typeChanged) {
                    if (setsInput && setsInput.dataset.workoutDefaultTouched !== '1') {
                        setsInput.value = type === 'cardio' ? '1' : '3';
                    }
                    if (type === 'cardio' && minutesInput && !minutesInput.value) minutesInput.value = '20';
                    if (type === 'isometric' && secondsInput && !secondsInput.value) secondsInput.value = '30';
                    if (!['cardio', 'isometric'].includes(type) && repsInput && !repsInput.value) repsInput.value = '10';
                }
                activeType = type;
                defaultPanels.forEach((panel) => {
                    const types = String(panel.dataset.workoutDefaultPanel || '').split(',').map((value) => value.trim());
                    const visible = types.includes(type);
                    panel.hidden = !visible;
                    panel.querySelectorAll('input, select, textarea').forEach((input) => {
                        input.disabled = !visible;
                    });
                });
                if (setsLabel) setsLabel.textContent = type === 'cardio' ? setsLabel.dataset.roundsLabel : setsLabel.dataset.setsLabel;
                if (defaultStatus) {
                    const sets = setsInput?.value || '—';
                    if (type === 'cardio') defaultStatus.textContent = `${sets}×${minutesInput?.value || '—'} min`;
                    else if (type === 'isometric') defaultStatus.textContent = `${sets}×${secondsInput?.value || '—'}s`;
                    else defaultStatus.textContent = `${sets}×${repsInput?.value || '—'}`;
                }
            }

            exerciseTypeInput.addEventListener('change', () => syncDefaults(exerciseTypeInput.value !== activeType));
            syncDefaults(false);
        }

        const mobileMediaPanels = [...form.querySelectorAll('.workouts-custom-media > .workouts-custom-color-details, .workouts-custom-media-details')];
        const mobileMediaQuery = window.matchMedia('(max-width: 700px)');
        mobileMediaPanels.forEach((panel) => panel.addEventListener('toggle', () => {
            if (!panel.open || !mobileMediaQuery.matches) return;
            mobileMediaPanels.forEach((otherPanel) => {
                if (otherPanel !== panel) otherPanel.open = false;
            });
        }));

        const normalizeWorkoutMark = (value) => {
            const clean = String(value || '').trim().replace(/[<>&\u0000-\u001f\u007f]/g, '');
            if (!clean) return '•';
            if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
                const segmenter = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
                return [...segmenter.segment(clean)].slice(0, 3).map((part) => part.segment).join('');
            }
            return [...clean].slice(0, 3).join('');
        };
        [...form.querySelectorAll('[data-workout-mark-picker]')].forEach((picker) => {
            const markInput = picker.querySelector('[data-workout-mark-input]');
            const markPresets = [...picker.querySelectorAll('[data-workout-mark-preset]')];
            if (!markInput) return;
            const markPreviews = [...form.querySelectorAll('[data-workout-mark-preview]')];
            const applyWorkoutMark = (value) => {
                const mark = normalizeWorkoutMark(value);
                markInput.value = mark;
                markPresets.forEach((preset) => {
                    preset.checked = normalizeWorkoutMark(preset.value) === mark;
                });
                markPreviews.forEach((preview) => {
                    preview.textContent = mark;
                });
            };
            markPresets.forEach((preset) => preset.addEventListener('change', () => {
                if (preset.checked) applyWorkoutMark(preset.value);
            }));
            markInput.addEventListener('input', () => applyWorkoutMark(markInput.value));
            markInput.addEventListener('change', () => applyWorkoutMark(markInput.value));
            applyWorkoutMark(markInput.value);
        });

        const normalizeWorkoutColor = (value) => /^#[0-9a-f]{6}$/i.test(String(value || '').trim())
            ? String(value).trim().toLowerCase()
            : '#14b8a6';
        [...form.querySelectorAll('[data-workout-color-picker]')].forEach((picker) => {
            const colorInput = picker.querySelector('[data-workout-color-input]');
            const colorOutput = picker.querySelector('[data-workout-color-output]');
            const colorPresets = [...picker.querySelectorAll('[data-workout-color-preset]')];
            if (!colorInput) return;
            const property = picker.dataset.workoutColorProperty || '--workout-accent';
            const colorTargets = [
                picker,
                form,
                picker.closest('.workouts-custom-color-details'),
                picker.closest('.admin-training-color-details'),
                form.closest('.workouts-routine-editor'),
                ...form.querySelectorAll('[data-workout-exercise-live-preview]'),
            ].filter(Boolean);
            const applyWorkoutColor = (value) => {
                const color = normalizeWorkoutColor(value);
                colorInput.value = color;
                if (colorOutput) {
                    colorOutput.value = color.toUpperCase();
                    colorOutput.textContent = color.toUpperCase();
                }
                colorPresets.forEach((preset) => {
                    preset.checked = normalizeWorkoutColor(preset.value) === color;
                });
                colorTargets.forEach((target) => {
                    target.style.setProperty(property, color);
                    target.style.setProperty('--workout-accent', color);
                });
            };
            colorPresets.forEach((preset) => preset.addEventListener('change', () => {
                if (preset.checked) applyWorkoutColor(preset.value);
            }));
            colorInput.addEventListener('input', () => applyWorkoutColor(colorInput.value));
            colorInput.addEventListener('change', () => applyWorkoutColor(colorInput.value));
            applyWorkoutColor(colorInput.value);
        });

        const imageInput = form.querySelector('[data-workout-image-input]');
        const image = form.querySelector('[data-workout-image-preview]');
        const imageWrap = form.querySelector('[data-workout-image-preview-wrap]');
        const imageEmpty = form.querySelector('[data-workout-image-empty]');
        const removeImage = form.querySelector('[data-workout-remove-image]');
        const photoDetails = form.querySelector('[data-workout-photo-details]');
        const imageStatus = form.querySelector('[data-workout-image-status]');
        const imagePositionInputs = [...form.querySelectorAll('[data-workout-image-position-input]')];
        const originalImage = image ? image.getAttribute('src') || '' : '';
        let objectUrl = '';

        const imagePositions = {
            top: '50% 18%',
            bottom: '50% 82%',
            left: '18% 50%',
            right: '82% 50%',
            center: '50% 50%',
        };
        const applyImagePosition = () => {
            if (!image) return;
            const selected = imagePositionInputs.find((input) => input.checked);
            image.style.objectPosition = imagePositions[selected ? selected.value : 'center'] || imagePositions.center;
            refreshLivePreview();
        };
        imagePositionInputs.forEach((input) => input.addEventListener('change', applyImagePosition));
        applyImagePosition();

        const showImage = (src, isNew = false) => {
            if (!image || !imageWrap || !imageEmpty) return;
            if (src) {
                image.src = src;
                imageWrap.hidden = false;
                imageEmpty.hidden = true;
            } else {
                image.removeAttribute('src');
                imageWrap.hidden = true;
                imageEmpty.hidden = false;
            }
            if (photoDetails) photoDetails.classList.toggle('has-media', Boolean(src));
            if (imageStatus) {
                imageStatus.textContent = src
                    ? (isNew ? imageStatus.dataset.newLabel : imageStatus.dataset.readyLabel)
                    : imageStatus.dataset.emptyLabel;
            }
            refreshLivePreview();
        };

        if (imageInput) {
            imageInput.addEventListener('change', () => {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                objectUrl = '';
                const file = imageInput.files && imageInput.files[0];
                if (file && file.type.startsWith('image/')) {
                    objectUrl = URL.createObjectURL(file);
                    if (removeImage) removeImage.checked = false;
                    showImage(objectUrl, true);
                    applyImagePosition();
                } else {
                    showImage(removeImage && removeImage.checked ? '' : originalImage);
                }
            });
        }
        if (removeImage) {
            removeImage.addEventListener('change', () => {
                const selected = imageInput && imageInput.files && imageInput.files[0];
                showImage(removeImage.checked ? '' : (selected && objectUrl ? objectUrl : originalImage));
            });
        }

        const videoInput = form.querySelector('[data-workout-video-input]');
        const videoPreview = form.querySelector('[data-workout-video-preview]');
        const clearVideo = form.querySelector('[data-workout-clear-video]');
        const videoDetails = form.querySelector('[data-workout-video-details]');
        const videoStatus = form.querySelector('[data-workout-video-status]');
        if (videoInput && videoPreview) {
            const refresh = () => {
                renderVideo(videoPreview, videoInput.value);
                const hasVideo = videoInput.value.trim() !== '';
                if (videoDetails) videoDetails.classList.toggle('has-media', hasVideo);
                if (videoStatus) videoStatus.textContent = hasVideo ? videoStatus.dataset.readyLabel : videoStatus.dataset.emptyLabel;
                refreshLivePreview();
            };
            videoInput.addEventListener('input', refresh);
            videoInput.addEventListener('change', refresh);
            refresh();
            if (clearVideo) {
                clearVideo.addEventListener('click', () => {
                    videoInput.value = '';
                    refresh();
                    videoInput.focus();
                });
            }
        }

        const livePreview = form.querySelector('[data-workout-exercise-live-preview]');
        if (livePreview) {
            if (livePreview instanceof HTMLDetailsElement && editorMobileQuery.matches) livePreview.open = false;
            const previewTabs = [...livePreview.querySelectorAll('[data-workout-preview-mode]')];
            const previewPanels = [...livePreview.querySelectorAll('[data-workout-preview-panel]')];
            const nameInput = form.querySelector('input[name="name"]');
            const summaryInput = form.querySelector('textarea[name="summary"]');
            const muscleInput = form.querySelector('select[name="muscle_group"]');
            const equipmentInput = form.querySelector('select[name="equipment"]');
            const difficultyInput = form.querySelector('select[name="difficulty"]');
            const typeInput = form.querySelector('select[name="exercise_type"]');
            const markInput = form.querySelector('[data-workout-mark-input]');
            const colorInput = form.querySelector('[data-workout-color-input]');
            const setsInput = form.querySelector('[data-workout-default-value="sets"]');
            const repsInput = form.querySelector('[data-workout-default-value="reps"]');
            const minutesInput = form.querySelector('[data-workout-default-value="minutes"]');
            const secondsInput = form.querySelector('[data-workout-default-value="seconds"]');

            const selectedText = (select, fallback = '') => {
                if (!(select instanceof HTMLSelectElement)) return fallback;
                return (select.selectedOptions[0]?.textContent || fallback).trim();
            };
            const coverMode = () => {
                const checked = form.querySelector('input[name="cover_mode"]:checked');
                if (checked) return checked.value || 'auto';
                const select = form.querySelector('select[name="cover_mode"]');
                return select instanceof HTMLSelectElement ? (select.value || 'auto') : 'auto';
            };
            const targetLabel = () => {
                const type = typeInput?.value || 'strength';
                const sets = setsInput?.value || '\u2014';
                if (type === 'cardio') return `${sets}\u00d7${minutesInput?.value || '\u2014'} min`;
                if (type === 'isometric') return `${sets}\u00d7${secondsInput?.value || '\u2014'}s`;
                return `${sets}\u00d7${repsInput?.value || '\u2014'}`;
            };
            const updateText = (selector, value) => {
                livePreview.querySelectorAll(selector).forEach((node) => {
                    node.textContent = value;
                });
            };
            const activatePreviewMode = (mode, focus = false) => {
                const activeMode = previewTabs.some((tab) => tab.dataset.workoutPreviewMode === mode) ? mode : 'library';
                previewTabs.forEach((tab) => {
                    const selected = tab.dataset.workoutPreviewMode === activeMode;
                    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                    tab.tabIndex = selected ? 0 : -1;
                    if (selected && focus) tab.focus({ preventScroll: true });
                });
                previewPanels.forEach((panel) => {
                    panel.hidden = panel.dataset.workoutPreviewPanel !== activeMode;
                });
            };
            previewTabs.forEach((tab, index) => {
                tab.addEventListener('click', () => activatePreviewMode(tab.dataset.workoutPreviewMode || 'library'));
                tab.addEventListener('keydown', (event) => {
                    let nextIndex = index;
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (index - 1 + previewTabs.length) % previewTabs.length;
                    else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (index + 1) % previewTabs.length;
                    else if (event.key === 'Home') nextIndex = 0;
                    else if (event.key === 'End') nextIndex = previewTabs.length - 1;
                    else return;
                    event.preventDefault();
                    activatePreviewMode(previewTabs[nextIndex].dataset.workoutPreviewMode || 'library', true);
                });
            });

            refreshLivePreview = () => {
                const mark = normalizeWorkoutMark(markInput?.value || '');
                const color = normalizeWorkoutColor(colorInput?.value || '');
                const selectedGalleryCover = form.querySelector('[data-workout-gallery-cover]:checked')?.closest('[data-workout-gallery-item]');
                const selectedGalleryImage = selectedGalleryCover?.querySelector('[data-workout-gallery-image]');
                const legacyImageSource = image && imageWrap && !imageWrap.hidden ? (image.getAttribute('src') || '') : '';
                const imageSource = legacyImageSource || selectedGalleryImage?.getAttribute('src') || '';
                const video = parseVideo(videoInput?.value || '');
                const requestedCover = coverMode();
                let resolvedSource = 'simple';
                if (requestedCover === 'photo') resolvedSource = imageSource ? 'photo' : 'simple';
                else if (requestedCover === 'video') resolvedSource = video ? 'video' : 'simple';
                else if (requestedCover === 'auto') resolvedSource = imageSource ? 'photo' : (video ? 'video' : 'simple');
                const resolvedImage = resolvedSource === 'photo' ? imageSource : (resolvedSource === 'video' ? (video?.thumbnail || '') : '');
                const selectedPosition = imagePositionInputs.find((input) => input.checked)?.value || 'center';
                const galleryCoverPosition = selectedGalleryImage?.style?.objectPosition || '';
                const position = resolvedSource === 'photo' && galleryCoverPosition !== ''
                    ? galleryCoverPosition
                    : (imagePositions[selectedPosition] || imagePositions.center);

                livePreview.style.setProperty('--exercise-accent', color);
                livePreview.style.setProperty('--workout-accent', color);
                updateText('[data-workout-preview-head-mark]', mark);
                updateText('[data-workout-preview-mark]', mark);
                updateText('[data-workout-preview-name]', String(nameInput?.value || '').trim() || livePreview.dataset.placeholderName || 'Exercise');
                updateText('[data-workout-preview-summary]', String(summaryInput?.value || '').trim() || livePreview.dataset.placeholderSummary || '');
                updateText('[data-workout-preview-muscle]', selectedText(muscleInput));
                updateText('[data-workout-preview-equipment]', selectedText(equipmentInput));
                updateText('[data-workout-preview-difficulty]', selectedText(difficultyInput));
                updateText('[data-workout-preview-type]', selectedText(typeInput));
                updateText('[data-workout-preview-target]', targetLabel());
                updateText('[data-workout-preview-muscle-token]', String(muscleInput?.value || 'X').slice(0, 2).toUpperCase());

                const labels = {
                    auto: livePreview.dataset.coverAutoLabel || 'Auto',
                    photo: livePreview.dataset.coverPhotoLabel || 'Photo',
                    video: livePreview.dataset.coverVideoLabel || 'Video',
                    simple: livePreview.dataset.coverSimpleLabel || 'Simple',
                };
                updateText('[data-workout-preview-cover-status]', `${labels[requestedCover] || labels.auto} \u00b7 ${labels[resolvedSource] || labels.simple}`);
                livePreview.querySelectorAll('[data-workout-preview-media]').forEach((mediaNode) => {
                    mediaNode.dataset.previewSource = resolvedSource;
                    const previewImage = mediaNode.querySelector('[data-workout-preview-image]');
                    const previewMark = mediaNode.querySelector('[data-workout-preview-mark]');
                    const previewPlay = mediaNode.querySelector('[data-workout-preview-play]');
                    if (previewImage) {
                        if (resolvedImage) previewImage.src = resolvedImage;
                        else previewImage.removeAttribute('src');
                        previewImage.style.objectPosition = position;
                        previewImage.hidden = !resolvedImage;
                    }
                    if (previewMark) previewMark.hidden = Boolean(resolvedImage);
                    if (previewPlay) previewPlay.hidden = resolvedSource !== 'video';
                });
            };

            form.addEventListener('input', refreshLivePreview);
            form.addEventListener('change', refreshLivePreview);
            form.addEventListener('workout:gallerychange', refreshLivePreview);
            activatePreviewMode('library');
            refreshLivePreview();
        }
    };

    const init = () => document.querySelectorAll('[data-workout-media-editor]').forEach(initEditor);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Personal exercise drafts. Textual configuration is kept per user/editor
   context in localStorage. File objects are deliberately never persisted: a
   restored draft asks the user to select those files again. */
(() => {
    'use strict';

    const FORM_SELECTOR = 'form[data-workout-draft-key]';
    const PENDING_CLEAR_KEY = 'fitness-challenge:exercise-draft:pending-clear';
    const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;
    const MAX_SERIALIZED_SIZE = 200000;
    const ignoredNames = new Set([
        'csrf_token',
        'action',
        'exercise_id',
        'editor_section',
        'target_routine_id',
        'target_routine_exercise_id',
        'target_session_id',
        'gallery_editor',
        'gallery_order[]',
        'gallery_position[]',
        'gallery_caption[]',
        'gallery_cover',
        'image_position',
    ]);

    const storageRead = (key) => {
        try {
            const parsed = JSON.parse(window.localStorage.getItem(key) || 'null');
            if (!parsed || parsed.version !== 1 || !Number.isFinite(parsed.updatedAt)) return null;
            if (Date.now() - parsed.updatedAt > MAX_AGE_MS) {
                window.localStorage.removeItem(key);
                return null;
            }
            return parsed;
        } catch (_) {
            return null;
        }
    };
    const storageRemove = (key) => {
        try {
            window.localStorage.removeItem(key);
            return true;
        } catch (_) {
            return false;
        }
    };
    const pendingRead = () => {
        try {
            const raw = window.sessionStorage.getItem(PENDING_CLEAR_KEY) || '';
            if (!raw) return null;
            try {
                const parsed = JSON.parse(raw);
                if (parsed?.version === 1 && typeof parsed.key === 'string' && parsed.key !== '') return parsed;
            } catch (_) {}
            return { version: 1, key: raw, action: 'legacy', success: null };
        } catch (_) {
            return null;
        }
    };
    const pendingWrite = (key, action, success) => {
        try {
            window.sessionStorage.setItem(PENDING_CLEAR_KEY, JSON.stringify({
                version: 1,
                key,
                action,
                success,
                createdAt: Date.now(),
            }));
        } catch (_) {}
    };
    const pendingRemove = () => {
        try { window.sessionStorage.removeItem(PENDING_CLEAR_KEY); } catch (_) {}
    };
    const formatTime = (timestamp) => {
        try {
            return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                hour: '2-digit',
                minute: '2-digit',
            }).format(new Date(timestamp));
        } catch (_) {
            return '';
        }
    };
    const withTime = (template, timestamp) => String(template || '').replaceAll('{time}', formatTime(timestamp));

    const matchesSuccessfulRedirect = (pending) => {
        if (pending?.action === 'legacy') return true;
        const success = pending?.success && typeof pending.success === 'object' ? pending.success : {};
        const params = new URL(window.location.href).searchParams;
        if (params.get('page') !== 'workouts' || params.has('custom_exercise')) return false;
        if (success.route === 'exercise') {
            const exerciseId = Number(params.get('exercise_id') || 0);
            return exerciseId > 0 && (Number(success.exerciseId || 0) <= 0 || exerciseId === Number(success.exerciseId));
        }
        if (success.route === 'routine_exercise') {
            return Number(params.get('routine_id') || 0) === Number(success.routineId || 0)
                && Number(params.get('routine_exercise_id') || 0) === Number(success.routineExerciseId || 0);
        }
        if (success.route === 'routine') return Number(params.get('routine_id') || 0) === Number(success.routineId || 0);
        if (success.route === 'session') return Number(params.get('session_id') || 0) === Number(success.sessionId || 0);
        if (success.route === 'library_mine') return params.get('view') === 'library' && params.get('scope') === 'mine';
        return false;
    };
    const reconcilePendingSave = (forms) => {
        const pending = pendingRead();
        if (!pending) return;
        const returnedToSameEditor = forms.some((form) => form.dataset.workoutDraftKey === pending.key);
        if (!returnedToSameEditor && matchesSuccessfulRedirect(pending)) storageRemove(pending.key);
        pendingRemove();
    };

    const successRedirectFor = (form) => {
        const numberValue = (name) => Math.max(0, Number(form.elements.namedItem(name)?.value || 0));
        const routineId = numberValue('target_routine_id');
        const routineExerciseId = numberValue('target_routine_exercise_id');
        const sessionId = numberValue('target_session_id');
        const exerciseId = numberValue('exercise_id');
        if (routineExerciseId > 0) return { route: 'routine_exercise', routineId, routineExerciseId };
        if (sessionId > 0) return { route: 'session', sessionId };
        if (routineId > 0) return { route: 'routine', routineId };
        return { route: 'exercise', exerciseId };
    };

    const collectDraft = (form) => {
        const values = {};
        let hasFiles = false;
        [...form.elements].forEach((control) => {
            if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
            if (!control.name || control.disabled || ignoredNames.has(control.name) || control.matches('[data-guide-output]')) return;
            if (control instanceof HTMLInputElement && control.type === 'file') {
                hasFiles = hasFiles || (control.files?.length || 0) > 0;
                return;
            }
            if (control instanceof HTMLInputElement && ['submit', 'button', 'reset', 'image'].includes(control.type)) return;
            if (!values[control.name]) values[control.name] = [];
            if (control instanceof HTMLInputElement && ['checkbox', 'radio'].includes(control.type)) {
                if (control.checked) values[control.name].push(String(control.value).slice(0, 10000));
                return;
            }
            values[control.name].push(String(control.value).slice(0, 10000));
        });

        const gallery = [...form.querySelectorAll('[data-workout-gallery-item]')]
            .map((item) => ({
                token: String(item.dataset.galleryToken || ''),
                position: String(item.querySelector('[data-workout-gallery-position]')?.value || 'center'),
                caption: String(item.querySelector('[data-workout-gallery-caption]')?.value || '').slice(0, 120),
                cover: Boolean(item.querySelector('[data-workout-gallery-cover]')?.checked),
                focus: item.classList.contains('is-editing'),
            }))
            .filter((item) => item.token !== '' && !item.token.startsWith('new:'));

        return {
            version: 1,
            revision: String(form.dataset.workoutDraftRevision || ''),
            updatedAt: Date.now(),
            section: String(form.dataset.workoutEditorSection || 'basics'),
            values,
            gallery,
            hasFiles,
        };
    };

    const reconcileGuideItems = (form, values) => {
        ['steps', 'tips', 'mistakes'].forEach((key) => {
            const section = form.querySelector(`[data-guide-key="${key}"]`);
            if (!(section instanceof HTMLElement)) return;
            const wasOpen = section instanceof HTMLDetailsElement ? section.open : false;
            const desired = Array.isArray(values[`${key}_items[]`]) ? values[`${key}_items[]`] : [];
            let rows = [...section.querySelectorAll('[data-guide-item]')];
            const add = section.querySelector('[data-guide-add]');
            while (rows.length < desired.length && add instanceof HTMLButtonElement) {
                add.click();
                rows = [...section.querySelectorAll('[data-guide-item]')];
            }
            while (rows.length > desired.length) {
                const remove = rows.at(-1)?.querySelector('[data-guide-remove]');
                if (!(remove instanceof HTMLButtonElement)) break;
                remove.click();
                rows = [...section.querySelectorAll('[data-guide-item]')];
            }
            if (section instanceof HTMLDetailsElement) section.open = wasOpen;
        });
    };

    const restoreGallery = (form, draft) => {
        if (!Array.isArray(draft.gallery)) return;
        const list = form.querySelector('[data-workout-gallery-list]');
        if (!(list instanceof HTMLElement)) return;
        const desiredTokens = new Set(draft.gallery.map((item) => String(item.token || '')));
        if (String(draft.revision || '') === String(form.dataset.workoutDraftRevision || '')) {
            [...list.querySelectorAll('[data-workout-gallery-item]')].forEach((item) => {
                const token = String(item.dataset.galleryToken || '');
                if (token && !token.startsWith('new:') && !desiredTokens.has(token)) {
                    item.querySelector('[data-workout-gallery-remove]')?.click();
                }
            });
        }
        const byToken = new Map([...list.querySelectorAll('[data-workout-gallery-item]')]
            .map((item) => [String(item.dataset.galleryToken || ''), item]));
        draft.gallery.forEach((saved) => {
            const item = byToken.get(String(saved.token || ''));
            if (!(item instanceof HTMLElement)) return;
            list.appendChild(item);
            const position = item.querySelector('[data-workout-gallery-position]');
            const caption = item.querySelector('[data-workout-gallery-caption]');
            const cover = item.querySelector('[data-workout-gallery-cover]');
            if (position) position.value = String(saved.position || 'center');
            if (caption) caption.value = String(saved.caption || '').slice(0, 120);
            if (cover) cover.checked = Boolean(saved.cover);
        });
        const focusItem = draft.gallery.find((item) => item.focus);
        const focusButton = byToken.get(String(focusItem?.token || ''))?.querySelector('[data-workout-gallery-focus]');
        if (focusButton instanceof HTMLButtonElement) focusButton.click();
        const coverInput = list.querySelector('[data-workout-gallery-cover]:checked')
            || list.querySelector('[data-workout-gallery-cover]');
        if (coverInput instanceof HTMLInputElement) {
            coverInput.checked = true;
            coverInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        list.closest('[data-workout-gallery-editor]')?.dispatchEvent(new CustomEvent('workout:gallerychange', { bubbles: true }));
    };

    const restoreDraft = (form, draft) => {
        const values = draft && typeof draft.values === 'object' && draft.values ? draft.values : {};
        reconcileGuideItems(form, values);
        const groupIndexes = new Map();
        [...form.elements].forEach((control) => {
            if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
            if (!control.name || ignoredNames.has(control.name) || control.matches('[data-guide-output]') || control.type === 'file') return;
            const savedValues = Array.isArray(values[control.name]) ? values[control.name] : [];
            if (control instanceof HTMLInputElement && ['checkbox', 'radio'].includes(control.type)) {
                const nextChecked = savedValues.includes(String(control.value));
                if (control.checked !== nextChecked) {
                    control.checked = nextChecked;
                    control.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }
            const index = groupIndexes.get(control.name) || 0;
            groupIndexes.set(control.name, index + 1);
            if (index >= savedValues.length) return;
            const nextValue = String(savedValues[index] ?? '');
            if (control.value !== nextValue) {
                control.value = nextValue;
                control.dispatchEvent(new Event('input', { bubbles: true }));
                control.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        restoreGallery(form, draft);
        const section = ['basics', 'guide', 'media'].includes(String(draft.section || '')) ? String(draft.section) : 'basics';
        form.querySelector(`[data-workout-editor-step-trigger="${section}"]`)?.click();
    };

    const initForm = (form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.workoutDraftReady === '1') return;
        form.dataset.workoutDraftReady = '1';
        const key = String(form.dataset.workoutDraftKey || '');
        const status = form.querySelector('[data-workout-draft-status]');
        const title = status?.querySelector('[data-workout-draft-title]');
        const hint = status?.querySelector('[data-workout-draft-hint]');
        const actions = status?.querySelector('[data-workout-draft-actions]');
        const restore = status?.querySelector('[data-workout-draft-restore]');
        const discard = status?.querySelector('[data-workout-draft-discard]');
        if (!key || !(status instanceof HTMLElement) || !title || !hint || !actions) return;

        let restoring = false;
        let timer = 0;
        let foundDraft = storageRead(key);
        const label = (name) => String(status.dataset[name] || '');
        const setStatus = (state, heading, detail, showActions = false) => {
            status.dataset.state = state;
            title.textContent = heading;
            hint.textContent = detail;
            actions.hidden = !showActions;
        };
        const readyStatus = (heading = label('readyLabel')) => setStatus('ready', heading, '');
        const showFound = (draft) => {
            const detail = withTime(label('foundTemplate'), draft.updatedAt)
                + (draft.hasFiles ? ` ${label('filesLabel')}` : '');
            setStatus('found', label('foundLabel'), detail, true);
            if (window.matchMedia('(max-width: 700px)').matches) {
                window.requestAnimationFrame(() => {
                    if (status.dataset.state === 'found') status.scrollIntoView({ block: 'start' });
                });
            }
        };
        const saveNow = () => {
            window.clearTimeout(timer);
            const draft = collectDraft(form);
            try {
                const serialized = JSON.stringify(draft);
                if (serialized.length > MAX_SERIALIZED_SIZE) throw new Error('draft-too-large');
                window.localStorage.setItem(key, serialized);
                foundDraft = draft;
                const detail = draft.hasFiles ? label('filesLabel') : '';
                setStatus('saved', withTime(label('savedTemplate'), draft.updatedAt), detail);
                return true;
            } catch (_) {
                setStatus('unavailable', label('unavailableLabel'), '');
                return false;
            }
        };
        const scheduleSave = () => {
            if (restoring) return;
            foundDraft = null;
            setStatus('saving', label('savingLabel'), label('filesLabel'));
            window.clearTimeout(timer);
            timer = window.setTimeout(saveNow, 500);
        };

        if (foundDraft) showFound(foundDraft);
        else readyStatus();

        form.addEventListener('input', scheduleSave);
        form.addEventListener('change', scheduleSave);
        form.addEventListener('workout:gallerychange', scheduleSave);
        form.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target || target.closest('[data-workout-draft-restore], [data-workout-draft-discard], button[type="submit"]')) return;
            if (!target.closest('[data-workout-editor-step-trigger], [data-guide-add], [data-guide-move], [data-guide-remove], [data-workout-gallery-move], [data-workout-gallery-remove], [data-workout-gallery-focus], [data-workout-clear-video]')) return;
            window.setTimeout(scheduleSave, 0);
        });
        form.addEventListener('submit', () => {
            saveNow();
            pendingWrite(key, 'save', successRedirectFor(form));
        });
        restore?.addEventListener('click', () => {
            if (!foundDraft) return;
            restoring = true;
            restoreDraft(form, foundDraft);
            restoring = false;
            const restoredAt = Date.now();
            setStatus('restored', label('restoredLabel'), foundDraft.hasFiles ? label('filesLabel') : '');
            foundDraft.updatedAt = restoredAt;
            window.setTimeout(saveNow, 0);
        });
        discard?.addEventListener('click', () => {
            window.clearTimeout(timer);
            storageRemove(key);
            foundDraft = null;
            readyStatus(label('discardedLabel'));
        });
    };

    const init = () => {
        const forms = [...document.querySelectorAll(FORM_SELECTOR)];
        reconcilePendingSave(forms);
        forms.forEach(initForm);
    };
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const deleteForm = event.target instanceof Element ? event.target.closest('[data-workout-draft-delete-key]') : null;
        const key = String(deleteForm?.dataset?.workoutDraftDeleteKey || '');
        if (key) pendingWrite(key, 'delete', { route: 'library_mine' });
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Session rest timer: the exercise prescription becomes an active tool. The
   clock is kept in sessionStorage so a normal set POST or exercise navigation
   never resets a recovery that is already running. */
(() => {
    const STORAGE_PREFIX = 'fitness-challenge:workout-rest:';
    const MAX_SECONDS = 3600;
    const MAX_AGE_MS = 12 * 60 * 60 * 1000;

    const clampSeconds = (value) => Math.max(0, Math.min(MAX_SECONDS, Math.round(Number(value) || 0)));
    const storageKey = (sessionId) => `${STORAGE_PREFIX}${sessionId}`;
    const formatClock = (seconds) => {
        const value = clampSeconds(seconds);
        return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
    };
    const removeState = (sessionId) => {
        try { window.sessionStorage.removeItem(storageKey(sessionId)); } catch (_) {}
    };
    const saveState = (sessionId, state) => {
        try {
            window.sessionStorage.setItem(storageKey(sessionId), JSON.stringify({
                duration: clampSeconds(state.duration),
                remaining: clampSeconds(state.remaining),
                running: Boolean(state.running),
                started: state.started !== false,
                complete: Boolean(state.complete),
                endsAt: Math.max(0, Number(state.endsAt) || 0),
                updatedAt: Date.now(),
            }));
        } catch (_) {}
    };
    const readState = (sessionId) => {
        try {
            const parsed = JSON.parse(window.sessionStorage.getItem(storageKey(sessionId)) || 'null');
            if (!parsed || typeof parsed !== 'object') return null;
            if (Date.now() - Math.max(0, Number(parsed.updatedAt) || 0) > MAX_AGE_MS) {
                removeState(sessionId);
                return null;
            }
            const duration = clampSeconds(parsed.duration);
            const remaining = clampSeconds(parsed.remaining);
            if (duration <= 0) return null;
            return {
                duration,
                remaining,
                running: Boolean(parsed.running),
                started: parsed.started !== false,
                complete: Boolean(parsed.complete),
                endsAt: Math.max(0, Number(parsed.endsAt) || 0),
            };
        } catch (_) {
            removeState(sessionId);
            return null;
        }
    };

    const initTimer = (timer) => {
        if (!(timer instanceof HTMLElement) || timer.dataset.restTimerReady === '1') return;
        const sessionId = String(timer.dataset.sessionId || '').trim();
        if (!sessionId) return;
        timer.dataset.restTimerReady = '1';

        const defaultSeconds = clampSeconds(timer.dataset.defaultSeconds);
        const clock = timer.querySelector('[data-rest-timer-clock]');
        const time = timer.querySelector('[data-rest-timer-time]');
        const title = timer.querySelector('[data-rest-timer-title]');
        const status = timer.querySelector('[data-rest-timer-status]');
        const toggle = timer.querySelector('[data-rest-timer-toggle]');
        const toggleLabel = timer.querySelector('[data-rest-timer-toggle-label]');
        const toggleIcon = timer.querySelector('[data-rest-timer-icon]');
        const skip = timer.querySelector('[data-rest-timer-skip]');
        const adjusters = [...timer.querySelectorAll('[data-rest-timer-adjust]')];
        const labels = {
            ready: String(timer.dataset.readyLabel || ''),
            readyHint: String(timer.dataset.readyHint || ''),
            running: String(timer.dataset.runningLabel || ''),
            paused: String(timer.dataset.pausedLabel || ''),
            complete: String(timer.dataset.completeLabel || ''),
            start: String(timer.dataset.startLabel || ''),
            pause: String(timer.dataset.pauseLabel || ''),
            resume: String(timer.dataset.resumeLabel || ''),
            restart: String(timer.dataset.restartLabel || ''),
            skip: String(timer.dataset.skipLabel || ''),
            close: String(timer.dataset.closeLabel || ''),
        };
        let state = readState(sessionId);
        let intervalId = 0;
        let completionAnnounced = Boolean(state?.complete);

        const remainingNow = () => {
            if (!state) return defaultSeconds;
            if (!state.running) return clampSeconds(state.remaining);
            return clampSeconds(Math.ceil((state.endsAt - Date.now()) / 1000));
        };
        const persist = () => {
            if (state) saveState(sessionId, state);
            else removeState(sessionId);
        };
        const stopTicking = () => {
            if (intervalId) window.clearInterval(intervalId);
            intervalId = 0;
        };
        const finish = () => {
            if (!state) return;
            state.remaining = 0;
            state.running = false;
            state.started = true;
            state.complete = true;
            state.endsAt = 0;
            persist();
            if (!completionAnnounced && typeof navigator.vibrate === 'function') {
                try { navigator.vibrate([120, 70, 120]); } catch (_) {}
            }
            completionAnnounced = true;
        };

        const render = () => {
            if (!timer.isConnected) {
                stopTicking();
                return;
            }
            let remaining = remainingNow();
            if (state?.running && remaining <= 0) {
                finish();
                remaining = 0;
            }
            const mode = !state || state.started === false
                ? 'idle'
                : (state.complete ? 'complete' : (state.running ? 'running' : 'paused'));
            const duration = Math.max(1, state?.duration || defaultSeconds || 1);
            const progress = mode === 'complete' ? 100 : Math.max(0, Math.min(100, (remaining / duration) * 100));
            const visible = mode !== 'idle' || remaining > 0;
            timer.hidden = !visible;
            timer.dataset.state = mode;
            if (clock instanceof HTMLElement) clock.style.setProperty('--rest-progress', `${progress}%`);
            if (time instanceof HTMLTimeElement) {
                time.textContent = formatClock(remaining);
                time.dateTime = `PT${remaining}S`;
            }

            const stateLabel = mode === 'running'
                ? labels.running
                : (mode === 'paused' ? labels.paused : (mode === 'complete' ? labels.complete : labels.ready));
            const buttonLabel = mode === 'running'
                ? labels.pause
                : (mode === 'paused' ? labels.resume : (mode === 'complete' ? labels.restart : labels.start));
            if (title instanceof HTMLElement) title.textContent = stateLabel;
            if (status instanceof HTMLElement) status.textContent = mode === 'idle' ? labels.readyHint : formatClock(remaining);
            if (toggle instanceof HTMLButtonElement) toggle.setAttribute('aria-label', buttonLabel);
            if (toggleLabel instanceof HTMLElement) toggleLabel.textContent = buttonLabel;
            if (toggleIcon instanceof HTMLElement) toggleIcon.textContent = mode === 'running' ? 'Ⅱ' : (mode === 'complete' ? '↻' : '▶');
            if (skip instanceof HTMLButtonElement) {
                skip.hidden = mode === 'idle';
                skip.textContent = mode === 'complete' ? labels.close : labels.skip;
            }
            adjusters.forEach((button) => {
                if (button instanceof HTMLButtonElement) button.disabled = mode === 'complete';
            });
            if (state?.running && !intervalId) {
                intervalId = window.setInterval(render, 250);
            } else if (!state?.running) {
                stopTicking();
            }
        };

        const start = (seconds) => {
            const duration = clampSeconds(seconds);
            if (duration <= 0) return;
            state = {
                duration,
                remaining: duration,
                running: true,
                started: true,
                complete: false,
                endsAt: Date.now() + duration * 1000,
            };
            completionAnnounced = false;
            persist();
            render();
        };
        const pause = () => {
            if (!state?.running) return;
            state.remaining = remainingNow();
            state.running = false;
            state.endsAt = 0;
            persist();
            render();
        };
        const resume = () => {
            if (!state || state.remaining <= 0) {
                start(state?.duration || defaultSeconds);
                return;
            }
            state.running = true;
            state.started = true;
            state.complete = false;
            state.endsAt = Date.now() + state.remaining * 1000;
            completionAnnounced = false;
            persist();
            render();
        };

        toggle?.addEventListener('click', () => {
            if (state?.running) pause();
            else if (state && !state.complete && state.remaining > 0) resume();
            else start(state?.duration || defaultSeconds);
        });
        adjusters.forEach((button) => {
            button.addEventListener('click', () => {
                const delta = Number(button.dataset.restTimerAdjust) || 0;
                const current = remainingNow();
                const next = clampSeconds(current + delta);
                if (next <= 0 && state?.started) {
                    finish();
                    render();
                    return;
                }
                const wasRunning = Boolean(state?.running);
                state = {
                    duration: Math.max(next, state?.duration || defaultSeconds || next),
                    remaining: next,
                    running: wasRunning,
                    started: state?.started ?? false,
                    complete: false,
                    endsAt: wasRunning ? Date.now() + next * 1000 : 0,
                };
                completionAnnounced = false;
                persist();
                render();
            });
        });
        skip?.addEventListener('click', () => {
            if (state?.complete) {
                state = null;
                completionAnnounced = false;
                persist();
            } else {
                finish();
            }
            render();
        });

        document.querySelectorAll(`[data-workout-set-form][data-session-id="${CSS.escape(sessionId)}"]`).forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.restTimerReady === '1') return;
            form.dataset.restTimerReady = '1';
            form.addEventListener('click', (event) => {
                const submitter = event.target instanceof Element
                    ? event.target.closest('[data-workout-set-toggle]')
                    : null;
                if (!(submitter instanceof HTMLButtonElement) || submitter.dataset.nextCompleted !== '1') return;
                const restSeconds = clampSeconds(form.dataset.restSeconds);
                if (restSeconds <= 0) return;
                form.dataset.restTimerStarted = '1';
                start(restSeconds);
            });
            form.addEventListener('submit', (event) => {
                if (form.dataset.restTimerStarted === '1') {
                    delete form.dataset.restTimerStarted;
                    return;
                }
                const submitter = event.submitter instanceof HTMLButtonElement
                    ? event.submitter
                    : form.querySelector('[data-workout-set-toggle][data-next-completed="1"]');
                if (!(submitter instanceof HTMLButtonElement) || submitter.dataset.nextCompleted !== '1') return;
                const restSeconds = clampSeconds(form.dataset.restSeconds);
                if (restSeconds > 0) start(restSeconds);
            });
        });
        document.querySelectorAll(`[data-workout-session-end][data-session-id="${CSS.escape(sessionId)}"]`).forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.restTimerEndReady === '1') return;
            form.dataset.restTimerEndReady = '1';
            form.addEventListener('submit', (event) => {
                if (!event.defaultPrevented) removeState(sessionId);
            });
        });

        render();
    };

    const init = () => document.querySelectorAll('[data-workout-rest-timer]').forEach(initTimer);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Exercise technique viewer: one compact photo/video surface everywhere the
   exercise is consumed. Provider iframes are created only after an explicit
   play action, keeping session navigation fast and privacy-friendly. */
(() => {
    'use strict';

    const initViewer = (viewer) => {
        if (!(viewer instanceof HTMLElement) || viewer.dataset.workoutMediaViewerReady === '1') return;
        viewer.dataset.workoutMediaViewerReady = '1';

        const tabs = [...viewer.querySelectorAll('[data-workout-media-tab]')];
        const panels = [...viewer.querySelectorAll('[data-workout-media-panel]')];
        const availableViews = panels.map((panel) => panel.dataset.workoutMediaPanel || '').filter(Boolean);
        let activeView = availableViews.includes(viewer.dataset.defaultView || '')
            ? viewer.dataset.defaultView
            : (availableViews[0] || '');

        const activate = (nextView, focusTab = false) => {
            if (!availableViews.includes(nextView)) return;
            activeView = nextView;
            panels.forEach((panel) => {
                panel.hidden = panel.dataset.workoutMediaPanel !== activeView;
            });
            tabs.forEach((tab) => {
                const selected = tab.dataset.workoutMediaTab === activeView;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
                if (selected && focusTab) tab.focus();
            });
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activate(tab.dataset.workoutMediaTab || ''));
            tab.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                let nextIndex = index;
                if (event.key === 'Home') nextIndex = 0;
                else if (event.key === 'End') nextIndex = tabs.length - 1;
                else nextIndex = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                activate(tabs[nextIndex]?.dataset.workoutMediaTab || '', true);
            });
        });
        activate(activeView);

        viewer.querySelectorAll('[data-workout-media-gallery]').forEach((gallery) => {
            const slides = [...gallery.querySelectorAll('[data-workout-gallery-slide]')];
            const captions = [...gallery.querySelectorAll('[data-workout-gallery-caption-slide]')];
            const thumbs = [...gallery.querySelectorAll('[data-workout-gallery-viewer-thumb]')];
            const status = gallery.querySelector('[data-workout-gallery-viewer-status]');
            const stage = gallery.querySelector('.workouts-media-gallery-stage');
            let activeIndex = 0;
            let pointerStart = null;
            const show = (rawIndex, focusThumb = false) => {
                if (slides.length === 0) return;
                activeIndex = (rawIndex + slides.length) % slides.length;
                slides.forEach((slide, index) => {
                    slide.hidden = index !== activeIndex;
                });
                captions.forEach((caption, index) => {
                    caption.hidden = index !== activeIndex || String(caption.textContent || '').trim() === '';
                });
                thumbs.forEach((thumb, index) => {
                    const selected = index === activeIndex;
                    thumb.setAttribute('aria-pressed', selected ? 'true' : 'false');
                    if (selected && focusThumb) thumb.focus({ preventScroll: true });
                });
                if (status) status.textContent = `${activeIndex + 1} / ${slides.length}`;
            };
            gallery.querySelectorAll('[data-workout-gallery-viewer-move]').forEach((button) => {
                button.addEventListener('click', () => {
                    show(activeIndex + (button.dataset.workoutGalleryViewerMove === 'next' ? 1 : -1));
                });
            });
            thumbs.forEach((thumb, index) => {
                thumb.addEventListener('click', () => show(index));
                thumb.addEventListener('keydown', (event) => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const nextIndex = event.key === 'Home'
                        ? 0
                        : (event.key === 'End' ? thumbs.length - 1 : index + (event.key === 'ArrowRight' ? 1 : -1));
                    show(nextIndex, true);
                });
            });
            stage?.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                pointerStart = { x: event.clientX, y: event.clientY };
            });
            stage?.addEventListener('pointerup', (event) => {
                if (!pointerStart) return;
                const deltaX = event.clientX - pointerStart.x;
                const deltaY = event.clientY - pointerStart.y;
                pointerStart = null;
                if (Math.abs(deltaX) >= 42 && Math.abs(deltaX) > Math.abs(deltaY)) show(activeIndex + (deltaX < 0 ? 1 : -1));
            });
            stage?.addEventListener('pointercancel', () => {
                pointerStart = null;
            });
            show(0);
        });

        viewer.querySelectorAll('[data-workout-video-load]').forEach((button) => {
            button.addEventListener('click', () => {
                const host = button.closest('[data-workout-lazy-video]');
                if (!(host instanceof HTMLElement) || host.dataset.videoLoaded === '1') return;
                const source = String(host.dataset.videoSrc || '').trim();
                if (!source) return;

                host.dataset.videoLoaded = '1';
                host.classList.add('is-loaded');
                button.disabled = true;
                const frame = document.createElement('iframe');
                frame.src = source;
                frame.title = host.dataset.videoTitle || 'Exercise video';
                frame.loading = 'eager';
                frame.referrerPolicy = 'strict-origin-when-cross-origin';
                frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                frame.allowFullscreen = true;
                frame.tabIndex = 0;
                const status = host.querySelector('[data-workout-video-load-status]');
                [...host.children].forEach((child) => {
                    if (child !== status) child.hidden = true;
                });
                host.appendChild(frame);
                if (status) status.textContent = host.dataset.loadedLabel || '';
                frame.focus({ preventScroll: true });
            });
        });
    };

    const init = () => document.querySelectorAll('[data-workout-media-viewer]').forEach(initViewer);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('fc:afterPageSwap', init);
})();

/* ==========================================================================
   Gallery calendar — mobile day bottom sheet (#12)

   On phones a day cell shows only a dot, so tapping it opens a sheet listing
   that day's photos instead of jumping blindly to the first one. Desktop keeps
   its existing behaviour (the cell is a plain link).
   ========================================================================== */
(() => {
    const sheet = document.getElementById('gallery-day-sheet');
    const panel = document.querySelector('.gallery-calendar-panel');
    if (!sheet || !panel || !window.AppOverlay) return;

    const grid = sheet.querySelector('[data-day-sheet-grid]');
    const title = sheet.querySelector('[data-day-sheet-title]');
    const countEl = sheet.querySelector('[data-day-sheet-count]');
    const emptyEl = sheet.querySelector('[data-day-sheet-empty]');
    const openLink = sheet.querySelector('[data-day-sheet-open]');
    const isMobile = () => window.matchMedia('(max-width: 899px)').matches;

    panel.addEventListener('click', (e) => {
        if (!isMobile()) return;
        const cell = e.target instanceof Element ? e.target.closest('.entries-calendar-day') : null;
        if (!cell) return;
        e.preventDefault();

        const count = Number(cell.dataset.calCount || 0);
        title.textContent = cell.dataset.calLabel || '';
        countEl.textContent = count > 0 ? `${count} ${grid.dataset.photosLabel || ''}`.trim() : '';
        openLink.href = cell.dataset.calAll || cell.href;

        // Reuse the thumbnails the cell already carries — no extra request.
        grid.innerHTML = '';
        const thumbs = [...cell.querySelectorAll('.entries-calendar-collage img')];
        thumbs.forEach((img) => {
            const a = document.createElement('a');
            a.href = cell.getAttribute('href') || '#';
            const clone = document.createElement('img');
            clone.src = img.currentSrc || img.src;
            clone.alt = '';
            clone.loading = 'lazy';
            a.appendChild(clone);
            grid.appendChild(a);
        });
        const hasPhotos = thumbs.length > 0;
        grid.hidden = !hasPhotos;
        emptyEl.hidden = hasPhotos;

        window.AppOverlay.open(sheet);
    });
})();

/* Unlock celebrations: auto-dismiss the toasts the server drained for us. The
   queue is already marked shown server-side, so nothing to report back. */
(function () {
    'use strict';

    function dismiss(toast) {
        if (!toast || toast.classList.contains('is-leaving')) {
            return;
        }
        toast.classList.add('is-leaving');
        window.setTimeout(function () {
            toast.remove();
        }, 260);
    }

    function init(root) {
        var stack = (root || document).querySelector('[data-celebrations]');
        if (!stack || stack.dataset.celebrationsReady === '1') {
            return;
        }
        stack.dataset.celebrationsReady = '1';

        stack.addEventListener('click', function (event) {
            var close = event.target.closest('[data-celebration-close]');
            if (close) {
                dismiss(close.closest('.celebration-toast'));
            }
        });

        var toasts = Array.prototype.slice.call(stack.querySelectorAll('.celebration-toast'));
        toasts.forEach(function (toast, index) {
            window.setTimeout(function () {
                dismiss(toast);
            }, 5200 + (index * 900));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    document.addEventListener('pjax:loaded', function () { init(document); });
})();

/* Live preview for the dashboard "Visible widgets" list (#2).

   On touch the list is the only way to reorder, so reflect its order (and the
   show/hide checkboxes) on the real cards immediately - otherwise you reorder
   blind and only find out what you did after saving. */
(function () {
    'use strict';

    function sync(list) {
        var layout = document.querySelector('.dashboard-layout');
        var mobileHome = document.querySelector('.dashboard-mobile-home');
        if (!layout && !mobileHome) {
            return;
        }
        var items = list.querySelectorAll('[data-dashboard-layout-item]');
        items.forEach(function (item, index) {
            var box = item.querySelector('input[type="checkbox"][name="dashboard_widgets[]"]');
            if (!box) {
                return;
            }
            var card = layout ? layout.querySelector('[data-dashboard-widget="' + CSS.escape(box.value) + '"]') : null;
            var mobileSurface = mobileHome ? mobileHome.querySelector('[data-dashboard-mobile-surface="' + CSS.escape(box.value) + '"]') : null;
            [card, mobileSurface].forEach(function (surface) {
                if (!(surface instanceof HTMLElement)) {
                    return;
                }
                surface.hidden = !box.checked;
                surface.classList.toggle('is-layout-hidden', !box.checked);
                if (box.checked) {
                    surface.style.removeProperty('display');
                }
            });
            if (card instanceof HTMLElement) {
                card.style.order = String(index + 1);
            }
        });

        var form = list.closest('[data-dashboard-layout-editor]');
        var state = form ? form.querySelector('[data-dashboard-layout-state]') : null;
        if (state instanceof HTMLElement) {
            var inputs = Array.prototype.slice.call(list.querySelectorAll('input[type="checkbox"][name="dashboard_widgets[]"]'));
            var visible = inputs.filter(function (input) { return input.checked; }).length;
            var hidden = Math.max(0, inputs.length - visible);
            var visibleNode = state.querySelector('[data-layout-visible-count]');
            var hiddenNode = state.querySelector('[data-layout-hidden-count]');
            var changeNode = state.querySelector('[data-layout-change-state]');
            if (visibleNode) {
                visibleNode.textContent = String(state.dataset.visibleTemplate || '{count} visible').replace('{count}', String(visible));
            }
            if (hiddenNode) {
                hiddenNode.textContent = String(state.dataset.hiddenTemplate || '{count} hidden').replace('{count}', String(hidden));
            }
            if (changeNode) {
                changeNode.textContent = document.body.classList.contains('layout-has-unsaved')
                    ? String(state.dataset.changedLabel || 'Unsaved changes')
                    : String(state.dataset.savedLabel || 'Saved');
            }
        }
    }

    function init() {
        document.querySelectorAll('[data-dashboard-layout-list]').forEach(function (list) {
            if (list.dataset.livePreviewReady === '1') {
                return;
            }
            list.dataset.livePreviewReady = '1';
            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-layout-move]');
                if (!button) {
                    return;
                }
                var item = button.closest('[data-dashboard-layout-item]');
                var direction = String(button.dataset.layoutMove || '');
                var scope = item ? String(item.dataset.layoutScope || '') : '';
                var scopedItems = item ? Array.prototype.slice.call(list.querySelectorAll('[data-dashboard-layout-item]')).filter(function (candidate) {
                    return scope === '' || String(candidate.dataset.layoutScope || '') === scope;
                }) : [];
                var index = item ? scopedItems.indexOf(item) : -1;
                var sibling = index >= 0
                    ? (direction === 'up' ? scopedItems[index - 1] : scopedItems[index + 1])
                    : null;
                if (item && sibling) {
                    if (direction === 'up') {
                        sibling.before(item);
                    } else {
                        sibling.after(item);
                    }
                    list.querySelectorAll('[data-dashboard-layout-item]').forEach(function (layoutItem, index) {
                        var input = layoutItem.querySelector('[data-dashboard-order-input]');
                        if (input) input.value = String(index + 1);
                    });
                }
                document.body.classList.add('layout-has-unsaved');
                sync(list);
            });
            list.addEventListener('change', function () {
                document.body.classList.add('layout-has-unsaved');
                sync(list);
            });
            var bodyObserver = new MutationObserver(function () { sync(list); });
            bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            sync(list);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('pjax:loaded', init);
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Double-submit guard (#10).

   A slow save (photo upload, Notion push) left the submit button live, so a second
   impatient tap posted the form twice. Lock the button for the duration of the
   navigation; re-enable on bfcache restore so a Back button never lands the user on
   a dead form. */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-allow-multi-submit')) {
            return;
        }
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        window.setTimeout(function () {
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                btn.disabled = true;
                btn.classList.add('is-busy');
            });
        }, 0);
    }, true);

    var release = function () {
        document.querySelectorAll('form[data-submitting="1"]').forEach(function (form) {
            form.dataset.submitting = '';
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                btn.disabled = false;
                btn.classList.remove('is-busy');
            });
        });
    };

    window.addEventListener('pageshow', release);
    document.addEventListener('fc:afterPageSwap', release);
})();

/* Unsaved-layout guard for the editors that are not the dashboard (#8/#12).

   The dashboard has its own guard bundled with its drag handler. Analytics, profile
   and team share this one, so leaving a layout half-edited warns you everywhere
   instead of only on Home. */
(function () {
    'use strict';

    var SELECTOR = '[data-analytics-layout-editor], [data-profile-layout-editor], [data-team-layout-editor]';

    function init() {
        var forms = Array.prototype.slice.call(document.querySelectorAll(SELECTOR));
        if (forms.length === 0) {
            return;
        }

        var dirty = false;
        var editing = function () { return document.body.classList.contains('layout-edit-active'); };

        forms.forEach(function (form) {
            if (form.dataset.unsavedGuardReady === '1') {
                return;
            }
            form.dataset.unsavedGuardReady = '1';
            form.addEventListener('change', function () {
                dirty = true;
                document.body.classList.add('layout-has-unsaved');
            });
            form.addEventListener('click', function (event) {
                if (event.target.closest('[data-layout-move]')) {
                    dirty = true;
                    document.body.classList.add('layout-has-unsaved');
                }
            });
            form.addEventListener('submit', function () {
                dirty = false;
                document.body.classList.remove('layout-has-unsaved');
            });
        });

        window.addEventListener('beforeunload', function (event) {
            if (!dirty || !editing()) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });

        document.addEventListener('click', function (event) {
            if (!dirty || !editing()) {
                return;
            }
            var link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link || link.closest(SELECTOR)) {
                return;
            }
            if (link.target === '_blank' || link.href.indexOf('javascript:') === 0) {
                return;
            }
            var message = document.body.dataset.unsavedLayoutMessage
                || 'You have unsaved layout changes. Leave without saving?';
            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            } else {
                dirty = false;
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('fc:afterPageSwap', init);
})();

/* Missing media placeholder.

   A thumbnail whose file is gone (restored DB, half-synced uploads folder) rendered
   as a broken-image icon inside an otherwise fine card. Mark it instead, so the card
   degrades to an empty tile with its alt text rather than looking corrupted. */
(function () {
    'use strict';

    function mark(img) {
        if (!(img instanceof HTMLImageElement) || img.classList.contains('is-broken')) {
            return;
        }
        img.classList.add('is-broken');
        img.removeAttribute('srcset');
        if (!img.alt) {
            img.alt = '';
        }
    }

    document.addEventListener('error', function (event) {
        mark(event.target);
    }, true);

    // Images decoded before this script ran already fired their error event, so a
    // listener alone misses exactly the ones that were broken on first paint.
    function sweep() {
        document.querySelectorAll('img').forEach(function (img) {
            if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) {
                mark(img);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sweep);
    } else {
        sweep();
    }
    window.addEventListener('load', sweep);
    document.addEventListener('fc:afterPageSwap', function () { window.setTimeout(sweep, 300); });
})();

/* Accessible routine and live-session exercise organizer.

   Arrow controls are deliberate here: they are predictable with touch, keyboard
   and assistive technology, and avoid accidental drag gestures while scrolling a
   workout on a phone. Hidden order inputs move with each row and submit the exact
   sequence shown on screen. */
(function () {
    'use strict';

    function refresh(form) {
        var items = Array.prototype.slice.call(form.querySelectorAll('[data-exercise-organizer-item]'));
        items.forEach(function (item, index) {
            var position = item.querySelector('[data-exercise-organizer-position]');
            var up = item.querySelector('[data-exercise-organizer-move="up"]');
            var down = item.querySelector('[data-exercise-organizer-move="down"]');
            if (position) {
                position.textContent = String(index + 1);
            }
            if (up) {
                up.disabled = index === 0;
            }
            if (down) {
                down.disabled = index === items.length - 1;
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-exercise-organizer]').forEach(function (form) {
            if (form.dataset.exerciseOrganizerReady === '1') {
                return;
            }
            form.dataset.exerciseOrganizerReady = '1';
            refresh(form);
            form.addEventListener('click', function (event) {
                var button = event.target instanceof Element
                    ? event.target.closest('[data-exercise-organizer-move]')
                    : null;
                if (!button || button.disabled) {
                    return;
                }
                var item = button.closest('[data-exercise-organizer-item]');
                var list = form.querySelector('[data-exercise-organizer-list]');
                if (!item || !list) {
                    return;
                }
                var direction = button.dataset.exerciseOrganizerMove;
                var sibling = direction === 'up' ? item.previousElementSibling : item.nextElementSibling;
                if (!sibling) {
                    return;
                }
                if (direction === 'up') {
                    list.insertBefore(item, sibling);
                } else {
                    list.insertBefore(sibling, item);
                }
                refresh(form);

                var focusTarget = button.disabled
                    ? item.querySelector('[data-exercise-organizer-move]:not(:disabled)')
                    : button;
                if (focusTarget) {
                    focusTarget.focus({ preventScroll: true });
                }
                var status = form.querySelector('[data-exercise-organizer-status]');
                if (status) {
                    status.textContent = '';
                    window.requestAnimationFrame(function () {
                        status.textContent = button.dataset.announcement || '';
                    });
                }
            });
            form.addEventListener('change', function (event) {
                var remove = event.target instanceof Element
                    ? event.target.closest('[data-exercise-organizer-remove]')
                    : null;
                if (!(remove instanceof HTMLInputElement)) {
                    return;
                }
                var item = remove.closest('[data-exercise-organizer-item]');
                if (item) {
                    item.classList.toggle('is-marked-for-removal', remove.checked);
                }
                var status = form.querySelector('[data-exercise-organizer-status]');
                if (status) {
                    status.textContent = remove.checked ? (remove.dataset.announcement || '') : '';
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('pjax:loaded', init);
    document.addEventListener('fc:afterPageSwap', init);
})();
