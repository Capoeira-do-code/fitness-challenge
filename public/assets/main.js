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
            const currentY = Math.max(0, window.scrollY);
            const goingDown = currentY > lastY + 4;
            const goingUp = currentY < lastY - 6;
            const shouldHide = !navHidden && goingDown && currentY > 120 && currentY - lastToggleY > 28;
            const shouldShow = navHidden && (currentY < 48 || (goingUp && lastToggleY - currentY > 18));

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
            document.body.classList.add('is-transitioning');
            window.setTimeout(() => document.body.classList.remove('is-transitioning'), 1800);
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
                workoutAddButton.hidden = !enabled;
                workoutAddButton.disabled = !enabled;
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
            history.pushState({}, '', href);
            applyState(root, href);
            initAdminAchievementFields();
            window.scrollTo(0, 0);
        };

        roots.forEach((root) => {
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
                navigate(root, link.href);
            });
        });

        window.addEventListener('popstate', () => {
            roots.forEach((root) => applyState(root, window.location.href));
            initAdminAchievementFields();
        });
    };

    const initProfileGoalsSection = () => {
        const profileRoot = document.querySelector('[data-spa-page="profile"]');
        if (!profileRoot) {
            return;
        }

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
                document.body.classList.add('is-transitioning');
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
                document.body.classList.remove('is-transitioning');
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
                document.body.classList.add('is-transitioning');
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
                root.replaceWith(nextPage);
                history.pushState({}, '', url.toString());
                initAnalyticsEnhancement();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (error) {
                console.error('Analytics update failed:', error);
                window.location.href = url.toString();
            } finally {
                document.body.classList.remove('is-transitioning');
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
                document.body.classList.add('is-transitioning');
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
                document.body.classList.remove('is-transitioning');
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

    const initProfileConfigEditor = () => {
        const editors = document.querySelectorAll('[data-config-editor]');
        if (editors.length === 0) {
            return;
        }
        editors.forEach((editor) => {
            const readonly = editor.querySelector('[data-config-readonly]');
            const form = editor.querySelector('[data-config-form]');
            const editLink = editor.closest('.panel')?.querySelector('[data-config-edit-link]');
            const cancelLink = editor.querySelector('[data-config-cancel-link]');
            if (!readonly || !form || !(editLink instanceof HTMLAnchorElement)) {
                return;
            }

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

    const initProfilePdfExport = () => {
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
                await loadScript('https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js');
            }
            if (!window.Chart) {
                await loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js');
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
                const activityLines = Array.isArray(payload.recent_activity)
                    ? payload.recent_activity.map((item) => `- ${item.summary || '-'} | ${item.action || '-'} | ${formatDate(item.created_at || '')}`)
                    : [];

                addLines(['Goals:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(goalLines.length > 0 ? goalLines : ['- No goals.'], { size: 9, lineHeight: 11 });
                y += 4;
                addLines(['Achievements:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(achievementLines.length > 0 ? achievementLines : ['- No achievements.'], { size: 9, lineHeight: 11 });
                y += 4;
                addLines(['Recent activity:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(activityLines.length > 0 ? activityLines : ['- No recent activity.'], { size: 9, lineHeight: 11 });

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
        document.querySelectorAll('[data-team-layout-list], [data-dashboard-layout-list]').forEach((list) => {
            if (!(list instanceof HTMLElement)) {
                return;
            }

            let dragged = null;
            const isDashboardLayout = list.hasAttribute('data-dashboard-layout-list');
            const itemSelector = isDashboardLayout ? '[data-dashboard-layout-item]' : '[data-team-layout-item]';

            const refreshOrderInputs = () => {
                if (!isDashboardLayout) {
                    return;
                }
                list.querySelectorAll(itemSelector).forEach((item, index) => {
                    if (!(item instanceof HTMLElement)) {
                        return;
                    }
                    item.querySelectorAll('[data-dashboard-order-input]').forEach((input) => {
                        if (input instanceof HTMLInputElement) {
                            input.value = String(index + 1);
                        }
                    });
                });
            };
            const moveItem = (item, direction) => {
                if (!(item instanceof HTMLElement)) {
                    return;
                }
                if (direction === 'up' && item.previousElementSibling) {
                    list.insertBefore(item, item.previousElementSibling);
                }
                if (direction === 'down' && item.nextElementSibling) {
                    list.insertBefore(item.nextElementSibling, item);
                }
                refreshOrderInputs();
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

            list.querySelectorAll(itemSelector).forEach((item) => {
                if (!(item instanceof HTMLElement)) {
                    return;
                }

                item.addEventListener('dragstart', () => {
                    dragged = item;
                    item.classList.add('is-dragging');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('is-dragging');
                    dragged = null;
                    refreshOrderInputs();
                });
                item.querySelectorAll('[data-layout-move]').forEach((button) => {
                    if (!(button instanceof HTMLButtonElement)) {
                        return;
                    }
                    button.addEventListener('click', () => {
                        moveItem(item, button.dataset.layoutMove || '');
                    });
                });
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
                refreshOrderInputs();
            });
            refreshOrderInputs();
        });
    };

    const initNotificationsAjax = () => {
        const root = document.querySelector('[data-notifications-page]');
        if (!(root instanceof HTMLElement) || root.dataset.notificationsReady === '1') {
            return;
        }
        root.dataset.notificationsReady = '1';

        const replaceBadge = (doc) => {
            const currentTrigger = document.querySelector('.user-menu-trigger');
            if (!(currentTrigger instanceof HTMLElement)) {
                return;
            }
            const currentBadge = currentTrigger.querySelector('[data-notification-badge]');
            const nextBadge = doc.querySelector('.user-menu-trigger [data-notification-badge]');
            if (nextBadge instanceof HTMLElement) {
                const clone = nextBadge.cloneNode(true);
                if (currentBadge instanceof HTMLElement) {
                    currentBadge.replaceWith(clone);
                } else {
                    currentTrigger.appendChild(clone);
                }
                return;
            }
            if (currentBadge instanceof HTMLElement) {
                currentBadge.remove();
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
                document.body.classList.add('is-transitioning');
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
                document.body.classList.remove('is-transitioning');
            }
        });
    };

    const initAll = () => {
        const safeInit = (initFn) => {
            try {
                initFn();
            } catch (error) {
                console.error('Init failed:', error);
            }
        };

        safeInit(initLoginLocale);
        safeInit(initFlashNotifications);
        safeInit(initLiquidInteractions);
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
        safeInit(initSettingsAvatarHashFallback);
        safeInit(initImageCroppers);
        safeInit(initProfilePdfExport);
        safeInit(initTeamLayoutEditor);
        safeInit(initNotificationsAjax);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
