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
        const workoutInput = entryForm.querySelector('[name="workout_done"]');
        const stepReason = entryForm.querySelector('[data-reason="steps"]');
        const workoutReason = entryForm.querySelector('[data-reason="workout"]');
        const updateReasons = () => {
            const goalType = entryForm.dataset.primaryGoalType || 'steps';
            const stepGoal = Number(entryForm.dataset.stepGoal || 0);
            const kmGoal = Number(entryForm.dataset.kmGoal || 0);
            const stepsValue = Number(stepsInput?.value || 0);
            const kmValue = Number(kmInput?.value || 0);
            const missingSteps = goalType === 'km' && kmGoal > 0 ? kmValue < kmGoal : stepsValue < stepGoal;
            const missingWorkout = workoutInput ? !workoutInput.checked : false;
            if (stepReason) {
                stepReason.hidden = !missingSteps;
            }
            if (workoutReason) {
                workoutReason.hidden = !missingWorkout;
            }
        };
        [stepsInput, kmInput, workoutInput].forEach((input) => {
            input?.addEventListener('input', updateReasons);
            input?.addEventListener('change', updateReasons);
        });
        updateReasons();
    }

    const proofPhotoForm = document.querySelector('[data-proof-photo-form]');
    if (proofPhotoForm) {
        const fileInput = proofPhotoForm.querySelector('[data-proof-photo-input]');
        const previewContainer = proofPhotoForm.querySelector('[data-proof-photo-preview]');
        let activeObjectUrl = null;

        const renderPlaceholder = () => {
            if (!(previewContainer instanceof HTMLElement)) {
                return;
            }
            previewContainer.innerHTML = `
                <div class="photo-placeholder">
                    <div class="photo-placeholder-content">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v8.59l3.3-3.3a1 1 0 0 1 1.4 0L14 15.6l2.3-2.3a1 1 0 0 1 1.4 0L19 14.6V6Zm3 1.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Z"/></svg>
                        <p>Selecciona una foto para previsualizarla</p>
                        <small>Se guardará como prueba del día</small>
                    </div>
                </div>
            `;
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
            previewContainer.innerHTML = `<img src="${activeObjectUrl}" alt="Vista previa de la foto">`;
        };

        fileInput?.addEventListener('change', renderPreview);
        renderPreview();
        window.addEventListener('beforeunload', () => {
            if (activeObjectUrl) {
                URL.revokeObjectURL(activeObjectUrl);
            }
        });
    }

    const lightbox = document.getElementById('mealLightbox');
    if (lightbox) {
        let photos = [];
        let index = 0;
        const image = lightbox.querySelector('[data-lightbox-image]');
        const caption = lightbox.querySelector('[data-lightbox-caption]');
        const render = () => {
            const current = photos[index] || {};
            if (image) {
                image.src = current.src || '';
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

        const buildChartImage = async ({ title, rows, color, type = 'line' }) => {
            if (!Array.isArray(rows) || rows.length === 0) {
                return null;
            }
            const labels = rows.map((row) => String(row.label || ''));
            const values = rows.map((row) => Number(row.value || 0));

            const canvas = document.createElement('canvas');
            canvas.width = 1120;
            canvas.height = 380;
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
                        backgroundColor: `${color}33`,
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

            await new Promise((resolve) => window.setTimeout(resolve, 30));
            const imageData = canvas.toDataURL('image/png');
            chart.destroy();
            return imageData;
        };

        const addTextBlock = (pdf, lines, x, y, maxWidth, lineHeight = 12) => {
            let cursor = y;
            lines.forEach((line) => {
                const chunks = pdf.splitTextToSize(String(line), maxWidth);
                chunks.forEach((chunk) => {
                    pdf.text(chunk, x, cursor);
                    cursor += lineHeight;
                });
            });
            return cursor;
        };

        button.addEventListener('click', async () => {
            if (button.disabled) {
                return;
            }
            button.disabled = true;
            const previousLabel = button.textContent;
            button.textContent = 'Generando PDF...';

            try {
                await ensureDeps();

                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ unit: 'pt', format: 'a4' });
                const width = pdf.internal.pageSize.getWidth();
                const height = pdf.internal.pageSize.getHeight();
                const margin = 36;
                let y = margin;

                const addPageIfNeeded = (needed = 24) => {
                    if (y + needed <= height - margin) {
                        return;
                    }
                    pdf.addPage();
                    y = margin;
                };

                const username = String(payload.username || 'user').trim() || 'user';
                const displayName = String(payload.display_name || username);
                const today = new Date().toISOString().slice(0, 10);

                pdf.setFillColor(20, 163, 139);
                pdf.rect(0, 0, width, 92, 'F');
                pdf.setTextColor(255, 255, 255);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(22);
                pdf.text('Exportar datos de usuario', margin, 46);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(12);
                pdf.text(`${displayName} (@${username})`, margin, 68);
                pdf.text(`Fecha: ${today}`, width - margin - 130, 68);

                pdf.setTextColor(30, 41, 59);
                y = 116;

                const totals = payload.totals || {};
                const config = payload.config || {};

                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(14);
                pdf.text('Configuración', margin, y);
                y += 14;
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(11);
                y = addTextBlock(pdf, [
                    `Objetivo principal: ${config.primary_goal_type || '-'}`,
                    `Valor objetivo: ${config.primary_goal_value ?? '-'}`,
                    `Workouts objetivo/semana: ${config.workout_target ?? '-'}`,
                    `Peso ideal: ${config.ideal_weight ?? '-'}`,
                ], margin, y + 8, width - margin * 2, 13) + 8;

                addPageIfNeeded(120);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(14);
                pdf.text('Totales', margin, y);
                y += 16;
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(11);
                y = addTextBlock(pdf, [
                    `Pasos: ${totals.steps ?? 0}`,
                    `Distancia total: ${totals.distance_km ?? 0} km`,
                    `Workouts: ${totals.workouts ?? 0}`,
                    `Score: ${totals.score ?? 0}`,
                    `Strikes: ${totals.strikes ?? 0}`,
                    `Penalización: €${totals.penalty ?? 0}`,
                ], margin, y, width - margin * 2, 13) + 8;

                const chartDefs = [
                    { key: 'steps', title: 'Steps chart', color: '#14a38b', type: 'line' },
                    { key: 'distance', title: 'Distance chart', color: '#3b82f6', type: 'line' },
                    { key: 'workouts', title: 'Workouts chart', color: '#ec4899', type: 'bar' },
                    { key: 'score', title: 'Score chart', color: '#0f766e', type: 'line' },
                    { key: 'weight', title: 'Weight chart', color: '#22313f', type: 'line' },
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

                    addPageIfNeeded(230);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(13);
                    pdf.text(chartDef.title, margin, y);
                    y += 10;
                    pdf.addImage(image, 'PNG', margin, y, width - margin * 2, 190);
                    y += 206;
                }

                addPageIfNeeded(100);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(14);
                pdf.text('Goals and achievements', margin, y);
                y += 14;
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(11);

                const goalLines = Array.isArray(payload.goals)
                    ? payload.goals.slice(0, 20).map((goal) => `• ${goal.title || '-'} (${goal.target_type || '-'}) target: ${goal.target_value || 0} · ${goal.status || 'active'}`)
                    : [];
                const achievementLines = Array.isArray(payload.achievements)
                    ? payload.achievements.slice(0, 20).map((achievement) => `• ${achievement.name || '-'}${achievement.reward_text ? ` · ${achievement.reward_text}` : ''}`)
                    : [];

                if (goalLines.length > 0) {
                    y = addTextBlock(pdf, ['Goals:'].concat(goalLines), margin, y + 4, width - margin * 2, 13) + 6;
                } else {
                    y = addTextBlock(pdf, ['Goals: sin datos'], margin, y + 4, width - margin * 2, 13) + 6;
                }

                addPageIfNeeded(80);
                if (achievementLines.length > 0) {
                    y = addTextBlock(pdf, ['Achievements:'].concat(achievementLines), margin, y, width - margin * 2, 13) + 8;
                } else {
                    y = addTextBlock(pdf, ['Achievements: sin datos'], margin, y, width - margin * 2, 13) + 8;
                }

                addPageIfNeeded(90);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(14);
                pdf.text('Recent activity', margin, y);
                y += 14;
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(11);
                const activityLines = Array.isArray(payload.recent_activity)
                    ? payload.recent_activity.slice(0, 35).map((item) => `• ${item.summary || '-'} · ${item.action || ''} · ${item.created_at || ''}`)
                    : [];
                if (activityLines.length > 0) {
                    addTextBlock(pdf, activityLines, margin, y + 4, width - margin * 2, 13);
                } else {
                    addTextBlock(pdf, ['Sin actividad reciente.'], margin, y + 4, width - margin * 2, 13);
                }

                const safeUsername = username.replace(/[^a-z0-9_-]/gi, '-').toLowerCase();
                pdf.save(`user-data-${safeUsername}-${today}.pdf`);
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
