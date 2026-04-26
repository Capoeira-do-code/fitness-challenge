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
        let lastY = window.scrollY;
        let ticking = false;
        const toggleNav = () => {
            const currentY = Math.max(0, window.scrollY);
            const goingDown = currentY > lastY + 8;
            const goingUp = currentY < lastY - 8;
            const shouldHide = goingDown && currentY > 90;

            if (shouldHide || goingUp || currentY < 40) {
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
})();
