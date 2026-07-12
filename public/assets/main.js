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
        const pinnedBottomNavPages = new Set(['gallery', 'photo']);
        const isPinnedBottomNavPage = () => pinnedBottomNavPages.has(String(document.body?.dataset?.page || ''));
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
            if (isPinnedBottomNavPage()) {
                [bottomNav, floatingLog].forEach((element) => {
                    if (!element) {
                        return;
                    }
                    element.classList.remove('nav-hidden', 'is-hidden');
                });
                navHidden = false;
                ticking = false;
                return;
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
                [bottomNav, floatingLog].forEach((element) => {
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

    const entryForm = document.querySelector('[data-testid="entry-form"]');
    if (entryForm) {
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
            if (!isCustom && customInput instanceof HTMLInputElement) {
                customInput.value = '';
            }
            buildWorkoutSubfields(row);
            updateWorkoutIndexes();
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
            if (workoutEnabled instanceof HTMLInputElement && !workoutEnabled.checked) {
                workoutEnabled.checked = true;
                updateWorkoutPanelState();
            }
            if (!(workoutRows instanceof HTMLElement) || !(workoutTemplate instanceof HTMLTemplateElement)) {
                return;
            }
            const fragment = workoutTemplate.content.cloneNode(true);
            workoutRows.appendChild(fragment);
            const rows = getWorkoutRows();
            const lastRow = rows[rows.length - 1];
            if (lastRow instanceof HTMLElement) {
                updateWorkoutRowVisibility(lastRow);
                const select = lastRow.querySelector('[data-workout-select]');
                if (select instanceof HTMLSelectElement) {
                    select.focus();
                }
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
    }

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
                root.querySelectorAll('[data-goal-edit-form]').forEach((form) => {
                    form.hidden = true;
                });
            }
        };

        const navigate = (root, href) => {
            document.body.classList.add('is-view-changing');
            window.__fcSpaDepth = (window.__fcSpaDepth || 0) + 1;
            history.pushState({}, '', href);
            applyState(root, href);
            initAdminAchievementFields();
            window.scrollTo(0, 0);
            clearMobileViewTransitionState(true);
            queueMobileViewTransitionStateCleanup(350, true);
        };

        roots.forEach((root) => {
            if (!(root instanceof HTMLElement) || root.dataset.spaNavigationReady === '1') {
                applyState(root, window.location.href);
                return;
            }
            root.dataset.spaNavigationReady = '1';
            applyState(root, window.location.href);
            root.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                const link = target.closest('a[data-spa-link], a[data-spa-back]');
                if (!(link instanceof HTMLAnchorElement)) {
                    return;
                }
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                // Smart back: when there is in-app history, return to the exact
                // previous view (e.g. a goal opened from Home returns to Home,
                // one opened from the list returns to the list). Falls back to
                // the static href on a deep link with no in-app history.
                if (link.matches('[data-spa-history]') && (window.__fcSpaDepth || 0) > 0) {
                    history.back();
                    return;
                }
                navigate(root, link.href);
            });
        });

        if (typeof window.__fcSpaPopstateHandler === 'function') {
            window.removeEventListener('popstate', window.__fcSpaPopstateHandler);
        }
        window.__fcSpaPopstateHandler = () => {
            window.__fcSpaDepth = Math.max(0, (window.__fcSpaDepth || 0) - 1);
            roots.forEach((root) => applyState(root, window.location.href));
            initAdminAchievementFields();
            clearMobileViewTransitionState(true);
            queueMobileViewTransitionStateCleanup(350, true);
        };
        window.addEventListener('popstate', window.__fcSpaPopstateHandler);
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

            const fileInput = form.querySelector('[data-image-crop-input]');
            const outputInput = form.querySelector('[data-image-crop-output]');
            const cropper = form.querySelector('[data-image-cropper]');
            const canvas = form.querySelector('[data-image-crop-canvas]');
            const zoomInput = form.querySelector('[data-image-crop-zoom]');
            const emptyHint = form.querySelector('[data-image-crop-empty]');

            if (!(fileInput instanceof HTMLInputElement)
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

            fileInput.addEventListener('change', () => {
                const selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                if (!selectedFile) {
                    state.img = null;
                    render();
                    return;
                }
                if (cropper instanceof HTMLElement) {
                    cropper.hidden = false;
                }

                const reader = new FileReader();
                reader.onload = () => {
                    const img = new Image();
                    img.onload = () => resetFromImage(img);
                    img.src = String(reader.result || '');
                };
                reader.readAsDataURL(selectedFile);
            });

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

    const initGalleryRecentInfinite = () => {
        const root = document.querySelector('[data-gallery-recent-root]');
        if (!(root instanceof HTMLElement) || root.dataset.galleryRecentReady === '1') {
            return;
        }

        const grid = root.querySelector('[data-gallery-recent-grid]');
        const loadMoreButton = root.querySelector('[data-gallery-recent-load-more]');
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
                    image.loading = itemIndex < 18 ? 'eager' : 'lazy';
                    image.setAttribute('fetchpriority', itemIndex < 8 ? 'high' : 'low');
                    image.decoding = 'async';
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
            initGalleryMonthOverlay();
        };

        const loadNextPage = async () => {
            if (isLoading || !hasMore || !Number.isFinite(nextPage) || nextPage <= 0) {
                return;
            }
            isLoading = true;
            root.classList.add('is-loading');
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
            } catch (error) {
                console.error('Gallery incremental load failed:', error);
                setHasMore(false);
            } finally {
                isLoading = false;
                root.classList.remove('is-loading');
            }
        };

        if (loadMoreButton instanceof HTMLButtonElement) {
            loadMoreButton.addEventListener('click', () => {
                loadNextPage();
            });
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
                const items = layoutItems();
                items.forEach((item, index) => {
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
                const items = layoutItems();
                const index = items.indexOf(item);
                if (index === -1) {
                    return;
                }
                if (direction === 'up' && index > 0) {
                    list.insertBefore(item, items[index - 1]);
                }
                if (direction === 'down' && index < items.length - 1) {
                    list.insertBefore(items[index + 1], item);
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

            list.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target.closest('[data-layout-move]') : null;
                if (!(target instanceof HTMLButtonElement)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const item = target.closest(itemSelector);
                if (!(item instanceof HTMLElement)) {
                    return;
                }
                moveItem(item, String(target.dataset.layoutMove || '').toLowerCase());
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
            if (link.closest('[data-spa-link], [data-spa-back], [data-analytics-filter], [data-dashboard-control-form], [data-calendar-view-option], [data-no-pjax]')) {
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

        const executeInsertedScripts = () => {
            document.querySelectorAll('main.container script').forEach((script) => {
                if (!(script instanceof HTMLScriptElement) || !canRunInlineScript(script)) {
                    return;
                }
                const nextScript = document.createElement('script');
                Array.from(script.attributes).forEach((attribute) => {
                    nextScript.setAttribute(attribute.name, attribute.value);
                });
                if (!nextScript.src) {
                    nextScript.textContent = script.textContent || '';
                }
                script.replaceWith(nextScript);
            });
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
                    history.pushState({ __fcPjax: true, scrollX: 0, scrollY: 0 }, '', targetUrl.toString());
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
                executeInsertedScripts();
                runPageHydration(false);

                if (push) {
                    window.scrollTo(0, 0);
                } else {
                    restoreScrollFromState(popState);
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
        safeInit(initGalleryRecentInfinite);
        safeInit(initImageCroppers);
        safeInit(initProfilePdfExport);
        safeInit(initTeamLayoutEditor);
        safeInit(initNotificationsAjax);
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
    // ---- Kebab menus (native <details data-kebab-menu>) ----
    const closeAllKebabs = (except) => {
        document.querySelectorAll('details[data-kebab-menu][open]').forEach((el) => {
            if (el !== except) el.removeAttribute('open');
        });
    };

    document.addEventListener('toggle', (event) => {
        const el = event.target;
        if (el instanceof HTMLDetailsElement && el.matches('details[data-kebab-menu]') && el.open) {
            closeAllKebabs(el);
        }
    }, true);

    // Close when clicking a menu item (buttons/links) or outside
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const insideMenu = target.closest('details[data-kebab-menu]');
        if (!insideMenu) {
            closeAllKebabs(null);
            return;
        }
        // Clicking an actual item closes the menu (after its own handler runs)
        if (target.closest('.kebab-menu-item')) {
            setTimeout(() => insideMenu.removeAttribute('open'), 0);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAllKebabs(null);
    });

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
   Dashboard direct drag-and-drop reordering + unsaved-changes guard (#2)

   In layout-edit mode the widget CARDS themselves become draggable, so the user
   reorders the real dashboard instead of an abstract list. Uses Pointer Events
   (one code path for mouse + touch). A drag only starts after the pointer moves
   past a threshold, so a tap never turns into an accidental drag on mobile.
   ========================================================================== */
(() => {
    const isEditing = () => document.body.classList.contains('layout-edit-active');
    const layout = document.querySelector('.dashboard-layout');
    // The dashboard ships TWO editor forms (a desktop panel and a mobile sheet).
    // Whichever one the user submits must carry the same state, so every sync
    // below is applied to all of them, not just the first match.
    const forms = [...document.querySelectorAll('[data-dashboard-layout-editor]')];
    if (!layout || forms.length === 0) return;
    const form = forms[0];

    const widgets = () => [...layout.querySelectorAll('[data-dashboard-widget]')];
    const DRAG_THRESHOLD = 8; // px before a press becomes a drag (anti tap-drag)

    let dirty = false;
    const markDirty = () => {
        dirty = true;
        document.body.classList.add('layout-has-unsaved');
    };

    /* ---- unsaved-changes guard + keep both editor forms in sync ---- */
    forms.forEach((f) => {
        f.addEventListener('change', (e) => {
            markDirty();
            // Mirror a widget toggle into the other editor form so the one that
            // actually gets submitted always reflects what the user saw.
            const cb = e.target;
            if (cb instanceof HTMLInputElement && cb.type === 'checkbox' && cb.name === 'dashboard_widgets[]') {
                forms.forEach((other) => {
                    if (other === f) return;
                    const twin = other.querySelector(`input[type="checkbox"][name="dashboard_widgets[]"][value="${CSS.escape(cb.value)}"]`);
                    if (twin instanceof HTMLInputElement) twin.checked = cb.checked;
                });
            }
        });
        f.addEventListener('submit', () => { dirty = false; });
    });

    window.addEventListener('beforeunload', (e) => {
        if (!dirty || !isEditing()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // Intercept in-app navigation (links) while there are unsaved changes.
    document.addEventListener('click', (e) => {
        if (!dirty || !isEditing()) return;
        const link = e.target instanceof Element ? e.target.closest('a[href]') : null;
        if (!link || link.closest('[data-dashboard-layout-editor]')) return;
        if (link.target === '_blank' || link.href.startsWith('javascript:')) return;
        const msg = layout.dataset.unsavedMessage || 'You have unsaved layout changes. Leave without saving?';
        if (!window.confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
        } else {
            dirty = false;
        }
    }, true);

    /* ---- order syncing: DOM order -> inline order + hidden form inputs ---- */
    const syncOrder = () => {
        widgets().forEach((el, i) => {
            const key = el.getAttribute('data-dashboard-widget');
            el.style.order = String((i + 1) * 10);
            forms.forEach((f) => {
                const input = f.querySelector(`[data-dashboard-order-input][name="dashboard_order[${key}]"]`);
                if (input instanceof HTMLInputElement) input.value = String(i + 1);
            });
        });
        markDirty();
    };

    /* ---- pointer-based drag ---- */
    let dragEl = null;
    let placeholder = null;
    let startX = 0, startY = 0, offX = 0, offY = 0;
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
        if (placeholder && placeholder.parentNode) placeholder.remove();
        document.body.classList.remove('layout-dragging');
        dragEl = null; placeholder = null; active = false;
    };

    layout.addEventListener('pointerdown', (e) => {
        if (!isEditing() || e.button !== 0) return;
        const el = e.target instanceof Element ? e.target.closest('[data-dashboard-widget]') : null;
        if (!el) return;
        // Let real controls inside a widget keep working.
        if (e.target instanceof Element && e.target.closest('a, button, input, select, textarea')) return;
        dragEl = el;
        const r = el.getBoundingClientRect();
        startX = e.clientX; startY = e.clientY;
        offX = e.clientX - r.left; offY = e.clientY - r.top;
        active = false;
    });

    window.addEventListener('pointermove', (e) => {
        if (!dragEl) return;
        if (!active) {
            if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return;
            // promote to a real drag
            active = true;
            const r = dragEl.getBoundingClientRect();
            placeholder = document.createElement('div');
            placeholder.className = 'dashboard-drop-placeholder';
            placeholder.style.height = `${r.height}px`;
            placeholder.style.order = dragEl.style.order;
            dragEl.parentNode.insertBefore(placeholder, dragEl);
            dragEl.classList.add('is-dragging');
            dragEl.style.width = `${r.width}px`;
            dragEl.style.position = 'fixed';
            dragEl.style.zIndex = '9999';
            dragEl.style.pointerEvents = 'none';
            document.body.classList.add('layout-dragging');
            try { dragEl.setPointerCapture(e.pointerId); } catch (_) {}
        }
        e.preventDefault();
        dragEl.style.left = `${e.clientX - offX}px`;
        dragEl.style.top = `${e.clientY - offY}px`;

        // Pick the drop target by nearest centre rather than strict containment:
        // the layout is a grid, so the pointer is often in the gutter between
        // cards, where a contains() test finds nothing and the drag feels dead.
        let over = null;
        let best = Infinity;
        for (const w of widgets()) {
            if (w === dragEl || w === placeholder) continue;
            const r = w.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) continue;
            const cx = r.left + r.width / 2;
            const cy = r.top + r.height / 2;
            const d = Math.hypot(e.clientX - cx, e.clientY - cy);
            if (d < best) { best = d; over = w; }
        }
        if (over && placeholder) {
            const r = over.getBoundingClientRect();
            // Reading order: past the vertical midpoint (or, on the same row,
            // past the horizontal midpoint) means "insert after".
            const sameRow = Math.abs(e.clientY - (r.top + r.height / 2)) < r.height / 2;
            const after = sameRow
                ? e.clientX > r.left + r.width / 2
                : e.clientY > r.top + r.height / 2;
            placeholder.style.order = over.style.order;
            over.parentNode.insertBefore(placeholder, after ? over.nextSibling : over);
        }
    }, { passive: false });

    window.addEventListener('pointerup', () => {
        if (!dragEl) return;
        if (active && placeholder) {
            placeholder.parentNode.insertBefore(dragEl, placeholder);
            cleanup();
            syncOrder();
        } else {
            cleanup();
        }
    });
    window.addEventListener('pointercancel', cleanup);
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
