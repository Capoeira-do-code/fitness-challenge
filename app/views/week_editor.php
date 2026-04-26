<?php

declare(strict_types=1);

$weekdayNames = [];
for ($i = 0; $i < 7; $i++) {
    $weekdayNames[$i] = t('weekday.' . $i);
}
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.table')) ?></p>
            <h1><?= e(t('table.editor_title')) ?></h1>
            <p class="muted"><?= e(t('table.subtitle')) ?></p>
        </div>
    </div>

    <article class="panel">
        <form method="get" class="control-strip wrap">
            <input type="hidden" name="page" value="week_editor">
            <label>
                <?= e(t('common.user')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= (int) $selectedUser['id'] === (int) $user['id'] ? 'selected' : '' ?>>
                            <?= e((string) $user['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?= e(t('common.week')) ?>
                <input type="week" name="week" value="<?= e(date_to_iso_week($weekStart)) ?>" onchange="this.form.submit()">
            </label>
        </form>

        <div class="week-editor-grid" id="week-editor-grid">
            <?php foreach ($weekDates as $idx => $date): ?>
                <?php $log = $logsByDate[$date] ?? []; ?>
                <article class="week-day-card" data-date="<?= e($date) ?>">
                    <header class="week-day-head">
                        <strong><?= e($weekdayNames[$idx] ?? $date) ?></strong>
                        <span class="muted small"><?= e(format_date_eu($date)) ?></span>
                    </header>

                    <div class="week-day-row week-day-primary">
                        <label>
                            <?= e(t('metric.steps')) ?>
                            <input type="number" min="0" name="steps" value="<?= e((string) ($log['steps'] ?? 0)) ?>">
                        </label>
                        <label>
                            <?= e(t('metric.distance_km')) ?>
                            <input type="number" min="0" step="0.01" name="distance_km" value="<?= e((string) ($log['distance_km'] ?? '')) ?>">
                        </label>
                        <label>
                            <?= e(t('metric.weight')) ?>
                            <input type="number" step="0.1" name="weight" value="<?= e((string) ($log['weight'] ?? '')) ?>">
                        </label>
                        <label class="check">
                            <input type="checkbox" name="workout_done" value="1" <?= !empty($log) && (int) ($log['workout_done'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <?= e(t('entries.workout')) ?>
                        </label>
                        <label class="check">
                            <input type="checkbox" name="junk_food" value="1" <?= !empty($log) && (int) ($log['junk_food'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <?= e(t('table.junk')) ?>
                        </label>
                        <label class="check">
                            <input type="checkbox" name="extra_workout" value="1" <?= !empty($log) && (int) ($log['extra_workout'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <?= e(t('table.extra_wo')) ?>
                        </label>
                    </div>

                    <div class="week-day-row week-day-secondary">
                        <?php
                        $workoutTypeId = (int) ($log['workout_type_id'] ?? 0);
                        $workoutTypeText = trim((string) ($log['workout_type'] ?? ''));
                        $customWorkout = $workoutTypeId === 0 && $workoutTypeText !== '';
                        ?>
                        <label>
                            <?= e(t('entries.workout_type')) ?>
                            <div class="workout-type-control" data-workout-control>
                                <select name="workout_type_id" data-workout-select <?= $customWorkout ? 'hidden' : '' ?>>
                                    <option value=""><?= e(t('common.none')) ?></option>
                                    <?php foreach (($workoutTypes ?? []) as $type): ?>
                                        <option value="<?= (int) $type['id'] ?>" <?= $workoutTypeId === (int) $type['id'] ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__" <?= $customWorkout ? 'selected' : '' ?>><?= e(t('entries.workout_type_custom')) ?></option>
                                </select>
                                <div class="workout-type-custom" data-workout-custom-wrap <?= $customWorkout ? '' : 'hidden' ?>>
                                    <input type="text" name="workout_type" data-workout-custom list="workout-type-options-week" value="<?= e($workoutTypeText) ?>" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                    <button class="btn small btn-ghost" type="button" data-workout-reset><?= e(t('common.cancel')) ?></button>
                                </div>
                            </div>
                        </label>
                        <label>
                            <?= e(t('table.exc_steps')) ?>
                            <input type="text" name="step_exception_reason" value="<?= e((string) ($log['step_exception_reason'] ?? '')) ?>">
                        </label>
                        <label>
                            <?= e(t('table.exc_workout')) ?>
                            <input type="text" name="workout_exception_reason" value="<?= e((string) ($log['workout_exception_reason'] ?? '')) ?>">
                        </label>
                        <label class="week-day-notes">
                            <?= e(t('common.notes')) ?>
                            <input type="text" name="notes" value="<?= e((string) ($log['notes'] ?? '')) ?>">
                        </label>
                    </div>

                    <?php if (($habits ?? []) !== []): ?>
                    <div class="week-day-habits">
                        <?php foreach (($habits ?? []) as $habit): ?>
                            <?php $code = (string) $habit['code']; ?>
                            <label class="check">
                                <input type="checkbox" name="habit_<?= e($code) ?>" data-habit-code="<?= e($code) ?>" value="1" <?= !empty($log['habits'][$code]) && (int) $log['habits'][$code]['value'] === 1 ? 'checked' : '' ?>>
                                <?= e((string) $habit['label']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="week-day-actions">
                        <button class="btn small btn-primary js-save-row" type="button"><?= e(t('common.save')) ?></button>
                        <span class="save-status" aria-live="polite"></span>
                    </div>
                </article>
            <?php endforeach; ?>
            <datalist id="workout-type-options-week">
                <?php foreach (($workoutTypes ?? []) as $type): ?>
                    <option value="<?= e((string) $type['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="inline-actions week-save-all-row">
            <button class="btn btn-primary" type="button" id="save-all-rows" data-testid="save-all-rows"><?= e(t('table.save_week')) ?></button>
            <span id="save-all-status" class="save-all-status" aria-live="polite"></span>
        </div>
    </article>
</section>

<script>
(function () {
    const grid = document.getElementById('week-editor-grid');
    const csrf = <?= json_encode(csrf_token()) ?>;
    const userId = <?= (int) $selectedUser['id'] ?>;
    const labels = {
        saving: <?= json_encode(t('common.saving')) ?>,
        saved: <?= json_encode(t('common.saved')) ?>,
        error: <?= json_encode(t('common.error')) ?>,
        savingWeek: <?= json_encode(t('table.saving_week')) ?>,
        savedWeek: <?= json_encode(t('table.saved_week')) ?>,
        savedWeekWithErrors: <?= json_encode(t('table.saved_week_with_errors')) ?>,
    };

    if (!grid) {
        return;
    }

    const setupWorkoutControl = (row) => {
        const select = row.querySelector('[data-workout-select]');
        const customWrap = row.querySelector('[data-workout-custom-wrap]');
        const customInput = row.querySelector('[data-workout-custom]');
        const resetButton = row.querySelector('[data-workout-reset]');
        if (!select || !customWrap || !customInput) {
            return;
        }

        const render = () => {
            const customMode = select.value === '__custom__';
            select.hidden = customMode;
            customWrap.hidden = !customMode;
            if (customMode) {
                customInput.focus();
            } else {
                customInput.value = '';
            }
        };

        select.addEventListener('change', render);
        resetButton?.addEventListener('click', () => {
            select.value = '';
            render();
        });
        render();
    };

    grid.querySelectorAll('.week-day-card').forEach(setupWorkoutControl);

    async function saveRow(row) {
        const workoutSelect = row.querySelector('[data-workout-select]');
        const workoutCustom = row.querySelector('[data-workout-custom]');
        const useCustomWorkout = workoutSelect && workoutSelect.value === '__custom__';
        const data = {
            csrf_token: csrf,
            user_id: userId,
            log_date: row.dataset.date,
            steps: row.querySelector('[name="steps"]').value,
            distance_km: row.querySelector('[name="distance_km"]').value,
            workout_done: row.querySelector('[name="workout_done"]').checked ? 1 : 0,
            junk_food: row.querySelector('[name="junk_food"]').checked ? 1 : 0,
            extra_workout: row.querySelector('[name="extra_workout"]').checked ? 1 : 0,
            weight: row.querySelector('[name="weight"]').value,
            workout_type_id: useCustomWorkout ? '' : (workoutSelect?.value || ''),
            workout_type: useCustomWorkout ? (workoutCustom?.value || '') : '',
            step_exception_reason: row.querySelector('[name="step_exception_reason"]').value,
            workout_exception_reason: row.querySelector('[name="workout_exception_reason"]').value,
            habits: {},
            notes: row.querySelector('[name="notes"]').value,
        };

        row.querySelectorAll('[data-habit-code]').forEach((input) => {
            data.habits[input.dataset.habitCode] = input.checked ? 1 : 0;
        });

        const status = row.querySelector('.save-status');
        const button = row.querySelector('.js-save-row');
        button.disabled = true;
        status.textContent = labels.saving;
        status.className = 'save-status saving';

        try {
            const response = await fetch('/?page=api_save_row', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data),
            });

            const json = await response.json();
            if (!response.ok || !json.ok) {
                status.textContent = json.message || labels.error;
                status.className = 'save-status error';
                return false;
            }

            status.textContent = labels.saved;
            status.className = 'save-status ok';
            return true;
        } catch (error) {
            status.textContent = labels.error;
            status.className = 'save-status error';
            return false;
        } finally {
            button.disabled = false;
        }
    }

    grid.querySelectorAll('.js-save-row').forEach((button) => {
        button.addEventListener('click', async () => {
            const row = button.closest('.week-day-card');
            await saveRow(row);
        });
    });

    const saveAll = document.getElementById('save-all-rows');
    const saveAllStatus = document.getElementById('save-all-status');
    saveAll?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const rows = [...grid.querySelectorAll('.week-day-card')];
        let successCount = 0;
        let errorCount = 0;

        button.disabled = true;
        saveAllStatus.textContent = labels.savingWeek + ' 0/' + rows.length;
        saveAllStatus.className = 'save-all-status saving';

        for (let i = 0; i < rows.length; i++) {
            const ok = await saveRow(rows[i]);
            if (ok) {
                successCount++;
            } else {
                errorCount++;
            }
            saveAllStatus.textContent = labels.savingWeek + ' ' + (i + 1) + '/' + rows.length;
        }

        button.disabled = false;
        if (errorCount > 0) {
            saveAllStatus.textContent = labels.savedWeekWithErrors + ' (' + successCount + '/' + rows.length + ')';
            saveAllStatus.className = 'save-all-status error';
        } else {
            saveAllStatus.textContent = labels.savedWeek + ' (' + successCount + '/' + rows.length + ')';
            saveAllStatus.className = 'save-all-status ok';
        }
    });
})();
</script>
