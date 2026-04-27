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
        let ticking = false;
        const toggleNav = () => {
            const currentY = Math.max(0, window.scrollY);
            const goingDown = currentY > lastY + 8;
            const goingUp = currentY < lastY - 8;
            const shouldHide = goingDown && currentY > 90;

            if (shouldHide || goingUp || currentY < 40) {
                if (shouldHide && floatingLog instanceof HTMLDetailsElement) {
                    floatingLog.open = false;
                    floatingLog.classList.remove('is-open');
                }
                [bottomNav, floatingLog].forEach((element) => {
                    if (!element) {
                        return;
                    }
                    element.classList.toggle('nav-hidden', shouldHide);
                });
                lastY = currentY;
            }
            ticking = false;
        };

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(toggleNav);
                ticking = true;
            }
        }, { passive: true });
    }

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

        const getWorkoutRows = () => {
            if (!(workoutRows instanceof HTMLElement)) {
                return [];
            }
            return [...workoutRows.querySelectorAll('[data-workout-row]')];
        };

        const getWorkoutValueFromRow = (row) => {
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
                const disable = rows.length <= 1;
                removeButton.disabled = disable;
                removeButton.setAttribute('aria-disabled', disable ? 'true' : 'false');
            });
        };

        const evaluateFailures = () => {
            const goalType = entryForm.dataset.primaryGoalType || 'steps';
            const stepGoal = Number(entryForm.dataset.stepGoal || 0);
            const kmGoal = Number(entryForm.dataset.kmGoal || 0);
            const stepsValue = Number(stepsInput?.value || 0);
            const kmValue = Number(kmInput?.value || 0);
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
                }
                updateWorkoutRemoveButtons();
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
                updateReasons();
            });
        }

        workoutAddButton?.addEventListener('click', () => {
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
            updateReasons();
        });

        [stepsInput, kmInput].forEach((input) => {
            input?.addEventListener('input', updateReasons);
            input?.addEventListener('change', updateReasons);
        });
        ensureOneWorkoutRow();
        getWorkoutRows().forEach((row) => updateWorkoutRowVisibility(row));
        updateWorkoutRemoveButtons();
        updateReasons();
    }

    const proofPhotoForm = document.querySelector('[data-proof-photo-form]');
    if (proofPhotoForm) {
        const fileInput = proofPhotoForm.querySelector('[data-proof-photo-input]');
        const previewContainer = proofPhotoForm.querySelector('[data-proof-photo-preview]');
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
        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

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
            const heicLike = isHeicLikeFile(selectedFile);
            previewImage.addEventListener('error', () => {
                if (heicLike) {
                    renderUnsupportedPreview();
                    return;
                }
                renderPlaceholder();
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
                if (link.hasAttribute('data-spa-back')) {
                    if (window.history.length > 1) {
                        window.history.back();
                        return;
                    }
                    const fallbackHref = link.getAttribute('href') || '/?page=admin';
                    navigate(root, fallbackHref);
                    return;
                }
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
                const confirmed = window.confirm('¿Eliminar este objetivo?');
                if (!confirmed) {
                    event.preventDefault();
                }
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
                    <h3 id=\"confirm-title\">¿Eliminar este logro?</h3>
                    <div class=\"confirm-modal-actions\">
                        <button type=\"button\" class=\"btn btn-ghost\" data-achievement-delete-cancel>Cancelar</button>
                        <button type=\"button\" class=\"btn btn-primary\" data-achievement-delete-confirm>Eliminar</button>
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
                    error.textContent = 'No se puede eliminar: award_id inválido.';
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
                    if (emptyHint instanceof HTMLElement) {
                        emptyHint.hidden = false;
                    }
                    return;
                }

                if (emptyHint instanceof HTMLElement) {
                    emptyHint.hidden = true;
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
                const sectionOverview = String(i18n.pdf_section_overview || 'Configuracion y totales');
                const sectionCharts = String(i18n.pdf_section_charts || 'Graficos semanales');
                const sectionDaily = String(i18n.pdf_section_daily || 'Detalle diario');
                const sectionNutrition = String(i18n.pdf_section_nutrition || 'Nutricion y fotos');
                const sectionActivity = String(i18n.pdf_section_activity || 'Goals, logros y actividad');

                pdf.setFillColor(20, 163, 139);
                pdf.rect(0, 0, width, 104, 'F');
                pdf.setTextColor(255, 255, 255);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(22);
                pdf.text(pdfTitle, margin, 44);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(12);
                pdf.text(`${displayName} (@${username})`, margin, 66);
                pdf.text(`Generado: ${generatedAt}`, margin, 84);
                pdf.text(`Rango challenge: ${challengeRangeLabel}`, margin + 240, 84);
                pdf.setTextColor(25, 35, 45);
                y = 128;

                addSectionTitle(`1) ${sectionOverview}`);
                addLines([
                    `Objetivo principal: ${config.primary_goal_type || '-'}`,
                    `Valor objetivo: ${config.primary_goal_value ?? '-'}`,
                    `Primary goals spec: ${config.primary_goals_spec || '-'}`,
                    `Workout target/semana: ${config.workout_target ?? '-'}`,
                    `Mantenimiento: ${config.maintenance_calories ?? '-'} kcal`,
                    `Goal quemar: ${config.calorie_burn_goal ?? '-'} kcal`,
                    `Maximo consumir: ${config.calorie_consumed_max ?? '-'} kcal`,
                    `Peso ideal: ${config.ideal_weight ?? '-'} kg`,
                ], { size: 10, lineHeight: 12 });
                y += 6;
                addLines([
                    `Pasos totales: ${formatNumber(totals.steps, 0)}`,
                    `Distancia total: ${formatNumber(totals.distance_km, 2)} km`,
                    `Workouts contados: ${formatNumber(totals.workouts, 0)}`,
                    `Score: ${formatNumber(totals.score, 1)}`,
                    `Strikes: ${formatNumber(totals.strikes, 0)}`,
                    `Penalizacion: €${formatNumber(totals.penalty, 2)}`,
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
                    addLines(['Sin detalle diario disponible.'], { size: 10, lineHeight: 12 });
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
                            `${date} | pasos ${formatNumber(day.steps, 0)} | km ${formatNumber(day.distance_km, 2)} | workouts ${formatNumber(day.workout_count, 0)} (contado ${formatNumber(day.workout_counted, 0)})`,
                            `burned ${day.training_calories_burned == null ? '-' : `${formatNumber(day.training_calories_burned, 0)} kcal`} | peso ${day.weight == null ? '-' : `${formatNumber(day.weight, 1)} kg`} | junk ${day.junk_food ? 'si' : 'no'} | extra ${day.extra_workout ? 'si' : 'no'}`,
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
                    addLines(['Sin datos de nutricion/fotos.'], { size: 10, lineHeight: 12 });
                } else {
                    dailyNutrition.forEach((day) => {
                        const totalsByDay = day.totals || {};
                        const header = `${formatDate(day.date)} | fotos ${formatNumber(day.photo_count, 0)} | kcal ${formatNumber(totalsByDay.calories, 0)} | P ${formatNumber(totalsByDay.protein_g, 1)}g / C ${formatNumber(totalsByDay.carbs_g, 1)}g / F ${formatNumber(totalsByDay.fat_g, 1)}g`;
                        addPageIfNeeded(20);
                        addLines([header], { font: 'bold', size: 9, lineHeight: 11 });
                        const items = Array.isArray(day.items) ? day.items : [];
                        if (items.length === 0) {
                            addLines(['  - Sin fotos o entradas.'], { size: 9, lineHeight: 11 });
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
                addLines(goalLines.length > 0 ? goalLines : ['- Sin goals.'], { size: 9, lineHeight: 11 });
                y += 4;
                addLines(['Achievements:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(achievementLines.length > 0 ? achievementLines : ['- Sin logros.'], { size: 9, lineHeight: 11 });
                y += 4;
                addLines(['Actividad reciente:'], { font: 'bold', size: 10, lineHeight: 12 });
                addLines(activityLines.length > 0 ? activityLines : ['- Sin actividad reciente.'], { size: 9, lineHeight: 11 });

                const safeUsername = username.replace(/[^a-z0-9_-]/gi, '-').toLowerCase();
                const today = new Date().toISOString().slice(0, 10);
                pdf.save(`challenge-report-${safeUsername}-${today}.pdf`);
            } catch (error) {
                console.error('PDF export failed', error);
                window.alert('No se pudo generar el PDF en este momento.');
            } finally {
                button.disabled = false;
                button.textContent = previousLabel || 'Exportar datos de usuario en PDF';
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
        safeInit(initSpaNavigation);
        safeInit(initAdminAchievementFields);
        safeInit(initAchievementDeleteModal);
        safeInit(initPhotoDeleteModal);
        safeInit(initProfileGoalsSection);
        safeInit(initProfileConfigEditor);
        safeInit(initImageCroppers);
        safeInit(initProfilePdfExport);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
