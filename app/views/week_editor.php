<?php

declare(strict_types=1);

$weekdayNames = [];
for ($i = 0; $i < 7; $i++) {
    $weekdayNames[$i] = t('weekday.' . $i);
}

$userStepGoal = max(0, (int) ($selectedUser['step_goal'] ?? 0));
$workoutTypeById = [];
foreach ((array) ($workoutTypes ?? []) as $type) {
    $typeId = (int) ($type['id'] ?? 0);
    if ($typeId <= 0) {
        continue;
    }
    $workoutTypeById[$typeId] = (string) ($type['name'] ?? '');
}

$resolveWorkoutSelection = static function (?int $workoutTypeId, string $workoutTypeText, array $workoutTypeById): array {
    $typeId = $workoutTypeId !== null && $workoutTypeId > 0 ? $workoutTypeId : null;
    $typeText = trim($workoutTypeText);
    $isKnownType = $typeId !== null && isset($workoutTypeById[$typeId]);

    $selectValue = '';
    $customValue = '';
    if ($isKnownType) {
        $selectValue = (string) $typeId;
    } elseif ($typeText !== '') {
        $selectValue = '__custom__';
        $customValue = $typeText;
    }

    return [
        'select_value' => $selectValue,
        'custom_value' => $customValue,
        'is_custom' => $selectValue === '__custom__',
        'is_filled' => $selectValue !== '' && ($selectValue !== '__custom__' || $customValue !== ''),
    ];
};
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

        <div class="week-editor-grid" id="week-editor-grid" data-step-goal="<?= $userStepGoal ?>">
            <?php foreach ($weekDates as $idx => $date): ?>
                <?php
                $log = $logsByDate[$date] ?? [];
                $logWorkouts = is_array($log['workouts'] ?? null) ? array_values((array) $log['workouts']) : [];
                if ($logWorkouts === [] && !empty($log)) {
                    $legacyWorkoutTypeId = !empty($log['workout_type_id']) ? (int) $log['workout_type_id'] : null;
                    $legacyWorkoutType = trim((string) ($log['workout_type'] ?? ''));
                    if ($legacyWorkoutTypeId !== null || $legacyWorkoutType !== '') {
                        $logWorkouts[] = [
                            'workout_type_id' => $legacyWorkoutTypeId,
                            'workout_type' => $legacyWorkoutType,
                        ];
                    }
                }

                $primaryWorkout = is_array($logWorkouts[0] ?? null) ? (array) $logWorkouts[0] : ['workout_type_id' => null, 'workout_type' => ''];
                $extraWorkouts = array_values(array_slice($logWorkouts, 1));

                $primaryTypeId = !empty($primaryWorkout['workout_type_id']) ? (int) $primaryWorkout['workout_type_id'] : null;
                $primaryTypeName = trim((string) ($primaryWorkout['workout_type'] ?? ''));
                $primarySelection = $resolveWorkoutSelection($primaryTypeId, $primaryTypeName, $workoutTypeById);

                $completedWorkout = (int) ($log['workout_done'] ?? 0) === 1 || $primarySelection['is_filled'] || $extraWorkouts !== [];

                $stepsRaw = isset($log['steps']) ? (string) $log['steps'] : '';
                $stepValue = $stepsRaw === '' ? null : (int) $stepsRaw;
                $showStepExcuse = $userStepGoal > 0 && ($stepValue === null || $stepValue < $userStepGoal);
                $showWorkoutExcuse = !$completedWorkout && !$primarySelection['is_filled'] && $extraWorkouts === [];
                ?>
                <article class="week-day-card" data-date="<?= e($date) ?>" data-step-goal="<?= $userStepGoal ?>">
                    <header class="week-day-head">
                        <strong><?= e($weekdayNames[$idx] ?? $date) ?></strong>
                        <span class="muted small"><?= e(format_date_eu($date)) ?></span>
                    </header>

                    <section class="week-section week-section-metrics">
                        <div class="week-section-head">
                            <h3><?= e(t('table.daily_metrics')) ?></h3>
                        </div>
                        <div class="week-section-grid week-metrics-grid">
                            <label class="week-field">
                                <span><?= e(t('metric.steps')) ?></span>
                                <input type="number" min="0" name="steps" value="<?= e((string) ($log['steps'] ?? '')) ?>" data-steps-input>
                            </label>
                            <label class="week-field">
                                <span><?= e(t('metric.distance_km')) ?></span>
                                <input type="number" min="0" step="0.01" name="distance_km" value="<?= e((string) ($log['distance_km'] ?? '')) ?>">
                            </label>
                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_training_calories')) ?>">
                                <label class="week-field">
                                    <span><?= e(t('entries.training_calories_burned')) ?></span>
                                    <input type="number" min="0" step="1" name="training_calories_burned" value="<?= e((string) ($log['training_calories_burned'] ?? '')) ?>">
                                </label>
                            </div>
                            <label class="week-field">
                                <span><?= e(t('metric.weight')) ?></span>
                                <input type="number" step="0.1" name="weight" value="<?= e((string) ($log['weight'] ?? '')) ?>">
                            </label>
                        </div>
                    </section>

                    <section class="week-section week-section-workout">
                        <div class="week-section-head">
                            <h3><?= e(t('table.workout_section')) ?></h3>
                        </div>
                        <div class="week-section-grid week-workout-grid">
                            <label class="check week-check" data-help="<?= e(t('table.week_help_workout_excuse')) ?>">
                                <input type="checkbox" name="workout_done" value="1" data-workout-done <?= $completedWorkout ? 'checked' : '' ?>>
                                <?= e(t('table.completed_workout')) ?>
                            </label>
                            <label class="check week-check">
                                <input type="checkbox" name="junk_food" value="1" <?= !empty($log) && (int) ($log['junk_food'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <?= e(t('table.junk')) ?>
                            </label>

                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_extra_workout')) ?>">
                                <label class="week-field">
                                    <span><?= e(t('table.primary_workout_type')) ?></span>
                                    <div class="workout-type-control" data-workout-control>
                                        <select name="workout_type_id" data-primary-workout-select <?= $primarySelection['is_custom'] ? 'hidden' : '' ?>>
                                            <option value=""><?= e(t('common.none')) ?></option>
                                            <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                                                <option value="<?= (int) $type['id'] ?>" <?= $primarySelection['select_value'] === (string) ((int) $type['id']) ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                            <?php endforeach; ?>
                                            <option value="__custom__" <?= $primarySelection['is_custom'] ? 'selected' : '' ?>><?= e(t('entries.workout_other')) ?></option>
                                        </select>
                                        <div class="workout-type-custom" data-primary-workout-custom-wrap <?= $primarySelection['is_custom'] ? '' : 'hidden' ?>>
                                            <input type="text" name="workout_type" data-primary-workout-custom list="workout-type-options-week" value="<?= e($primarySelection['custom_value']) ?>" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                            <button class="btn small btn-ghost" type="button" data-primary-workout-reset><?= e(t('common.cancel')) ?></button>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div class="week-extra-toolbar week-help-wrap" data-help="<?= e(t('table.week_help_extra_workout')) ?>">
                                <button class="btn btn-ghost small" type="button" data-extra-toggle><?= e(t('table.add_extra_workout')) ?></button>
                                <span class="muted small" data-extra-count></span>
                            </div>

                            <div class="week-extra-panel" data-extra-panel <?= $extraWorkouts !== [] ? '' : 'hidden' ?>>
                                <div class="week-extra-list" data-extra-list>
                                    <?php foreach ($extraWorkouts as $extraWorkout): ?>
                                        <?php
                                        $extraTypeId = !empty($extraWorkout['workout_type_id']) ? (int) $extraWorkout['workout_type_id'] : null;
                                        $extraTypeName = trim((string) ($extraWorkout['workout_type'] ?? ''));
                                        $extraSelection = $resolveWorkoutSelection($extraTypeId, $extraTypeName, $workoutTypeById);
                                        ?>
                                        <div class="week-extra-row" data-extra-row>
                                            <label class="week-field">
                                                <span><?= e(t('entries.workout_type')) ?></span>
                                                <select data-workout-select>
                                                    <option value=""><?= e(t('common.none')) ?></option>
                                                    <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                                                        <option value="<?= (int) $type['id'] ?>" <?= $extraSelection['select_value'] === (string) ((int) $type['id']) ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__custom__" <?= $extraSelection['is_custom'] ? 'selected' : '' ?>><?= e(t('entries.workout_other')) ?></option>
                                                </select>
                                            </label>
                                            <label class="week-field week-extra-custom" data-workout-custom-wrap <?= $extraSelection['is_custom'] ? '' : 'hidden' ?>>
                                                <span><?= e(t('entries.custom_workout_type')) ?></span>
                                                <input type="text" data-workout-custom-input value="<?= e($extraSelection['custom_value']) ?>" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                            </label>
                                            <button type="button" class="btn btn-ghost small" data-extra-remove><?= e(t('entries.remove_workout')) ?></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-ghost small" data-extra-add><?= e(t('table.add_extra_workout')) ?></button>
                            </div>
                            <template data-extra-template>
                                <div class="week-extra-row" data-extra-row>
                                    <label class="week-field">
                                        <span><?= e(t('entries.workout_type')) ?></span>
                                        <select data-workout-select>
                                            <option value=""><?= e(t('common.none')) ?></option>
                                            <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                                                <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['name']) ?></option>
                                            <?php endforeach; ?>
                                            <option value="__custom__"><?= e(t('entries.workout_other')) ?></option>
                                        </select>
                                    </label>
                                    <label class="week-field week-extra-custom" data-workout-custom-wrap hidden>
                                        <span><?= e(t('entries.custom_workout_type')) ?></span>
                                        <input type="text" data-workout-custom-input placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                    </label>
                                    <button type="button" class="btn btn-ghost small" data-extra-remove><?= e(t('entries.remove_workout')) ?></button>
                                </div>
                            </template>
                        </div>
                    </section>

                    <section class="week-section week-section-habits">
                        <div class="week-section-head">
                            <h3><?= e(t('table.habits_section')) ?></h3>
                            <button type="button" class="btn btn-ghost small" data-custom-habit-toggle><?= e(t('table.custom_habit')) ?></button>
                        </div>
                        <div class="week-help-wrap" data-help="<?= e(t('table.week_help_habits')) ?>">
                            <div class="week-custom-habit" data-custom-habit-form hidden>
                                <label class="week-field">
                                    <span><?= e(t('table.custom_habit')) ?></span>
                                    <input type="text" data-custom-habit-input placeholder="<?= e(t('table.custom_habit_placeholder')) ?>" maxlength="60">
                                </label>
                                <div class="week-custom-habit-actions">
                                    <button type="button" class="btn btn-primary small" data-custom-habit-save><?= e(t('common.create')) ?></button>
                                    <button type="button" class="btn btn-ghost small" data-custom-habit-cancel><?= e(t('common.cancel')) ?></button>
                                </div>
                                <p class="muted small" data-custom-habit-status aria-live="polite"></p>
                            </div>
                        </div>
                        <div class="week-day-habits" data-habits-list>
                            <?php foreach ((array) ($habits ?? []) as $habit): ?>
                                <?php $code = (string) $habit['code']; ?>
                                <label class="check">
                                    <input type="checkbox" name="habit_<?= e($code) ?>" data-habit-code="<?= e($code) ?>" value="1" <?= !empty($log['habits'][$code]) && (int) $log['habits'][$code]['value'] === 1 ? 'checked' : '' ?>>
                                    <?= e((string) $habit['label']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="week-section week-section-excuses">
                        <div class="week-section-head">
                            <h3><?= e(t('table.excuses_section')) ?></h3>
                        </div>
                        <div class="week-excuses-grid">
                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_step_excuse')) ?>" data-step-excuse-wrap <?= $showStepExcuse ? '' : 'hidden' ?>>
                                <label class="week-field week-field-secondary">
                                    <span><?= e(t('table.step_excuse_label')) ?></span>
                                    <input type="text" name="step_exception_reason" value="<?= e((string) ($log['step_exception_reason'] ?? '')) ?>">
                                </label>
                            </div>
                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_workout_excuse')) ?>" data-workout-excuse-wrap <?= $showWorkoutExcuse ? '' : 'hidden' ?>>
                                <label class="week-field week-field-secondary">
                                    <span><?= e(t('table.workout_excuse_label')) ?></span>
                                    <input type="text" name="workout_exception_reason" value="<?= e((string) ($log['workout_exception_reason'] ?? '')) ?>">
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="week-section week-section-notes">
                        <div class="week-section-head">
                            <h3><?= e(t('table.notes_section')) ?></h3>
                        </div>
                        <label class="week-field week-day-notes">
                            <span><?= e(t('common.notes')) ?></span>
                            <input type="text" name="notes" value="<?= e((string) ($log['notes'] ?? '')) ?>">
                        </label>
                    </section>

                    <div class="week-day-actions">
                        <button class="btn small btn-primary js-save-row" type="button"><?= e(t('table.save_day')) ?></button>
                        <span class="save-status" aria-live="polite"></span>
                    </div>
                </article>
            <?php endforeach; ?>
            <datalist id="workout-type-options-week">
                <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
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
        extraCount: <?= json_encode(t('table.extra_workout_count')) ?>,
        extraNone: <?= json_encode(t('table.extra_workout_none')) ?>,
        customHabitSaving: <?= json_encode(t('table.custom_habit_saving')) ?>,
        customHabitCreated: <?= json_encode(t('table.custom_habit_created')) ?>,
        customHabitRequired: <?= json_encode(t('table.custom_habit_required')) ?>,
        customHabitError: <?= json_encode(t('table.custom_habit_error')) ?>,
    };

    if (!grid) {
        return;
    }

    const formatExtraCount = (count) => {
        if (count <= 0) {
            return labels.extraNone;
        }
        return labels.extraCount.replace('{count}', String(count));
    };

    const isFilled = (value) => String(value || '').trim() !== '';

    const setWorkoutRowVisibility = (row) => {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const select = row.querySelector('[data-workout-select]');
        const customWrap = row.querySelector('[data-workout-custom-wrap]');
        if (!(select instanceof HTMLSelectElement) || !(customWrap instanceof HTMLElement)) {
            return;
        }
        const isCustom = select.value === '__custom__';
        customWrap.hidden = !isCustom;
    };

    const parseWorkoutFromControls = (select, customInput) => {
        if (!(select instanceof HTMLSelectElement)) {
            return null;
        }
        const value = String(select.value || '').trim();
        if (value === '') {
            return null;
        }
        if (value === '__custom__') {
            const customValue = customInput instanceof HTMLInputElement ? String(customInput.value || '').trim() : '';
            if (!isFilled(customValue)) {
                return null;
            }
            return {
                workout_type_id: '',
                workout_type: customValue,
            };
        }
        return {
            workout_type_id: value,
            workout_type: '',
        };
    };

    const getPrimaryWorkout = (card) => {
        const select = card.querySelector('[data-primary-workout-select]');
        const customInput = card.querySelector('[data-primary-workout-custom]');
        return parseWorkoutFromControls(select, customInput);
    };

    const getExtraWorkoutRows = (card) => {
        const list = card.querySelector('[data-extra-list]');
        if (!(list instanceof HTMLElement)) {
            return [];
        }
        return [...list.querySelectorAll('[data-extra-row]')];
    };

    const getExtraWorkouts = (card) => {
        const rows = getExtraWorkoutRows(card);
        const workouts = [];
        rows.forEach((row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const select = row.querySelector('[data-workout-select]');
            const customInput = row.querySelector('[data-workout-custom-input]');
            const parsed = parseWorkoutFromControls(select, customInput);
            if (parsed !== null) {
                workouts.push(parsed);
            }
        });
        return workouts;
    };

    const collectWorkouts = (card) => {
        const workouts = [];
        const primary = getPrimaryWorkout(card);
        if (primary !== null) {
            workouts.push(primary);
        }
        getExtraWorkouts(card).forEach((workout) => workouts.push(workout));
        return workouts;
    };

    const hasWorkoutSelection = (card) => {
        return collectWorkouts(card).length > 0;
    };

    const updateExtraCounter = (card) => {
        const counter = card.querySelector('[data-extra-count]');
        if (!(counter instanceof HTMLElement)) {
            return;
        }
        const count = getExtraWorkouts(card).length;
        counter.textContent = formatExtraCount(count);
    };

    const updateExcuseVisibility = (card) => {
        const stepExcuseWrap = card.querySelector('[data-step-excuse-wrap]');
        const workoutExcuseWrap = card.querySelector('[data-workout-excuse-wrap]');
        const stepsInput = card.querySelector('[data-steps-input]');
        const workoutDoneInput = card.querySelector('[data-workout-done]');

        const stepGoal = Number(card.dataset.stepGoal || 0);
        const rawSteps = stepsInput instanceof HTMLInputElement ? String(stepsInput.value || '').trim() : '';
        const parsedSteps = rawSteps === '' ? null : Number(rawSteps);
        const stepMissed = stepGoal > 0 && (parsedSteps === null || Number.isNaN(parsedSteps) || parsedSteps < stepGoal);
        if (stepExcuseWrap instanceof HTMLElement) {
            stepExcuseWrap.hidden = !stepMissed;
        }

        const completedChecked = workoutDoneInput instanceof HTMLInputElement ? workoutDoneInput.checked : false;
        const primaryWorkout = getPrimaryWorkout(card);
        const extraWorkouts = getExtraWorkouts(card);
        const showWorkoutExcuse = !completedChecked && primaryWorkout === null && extraWorkouts.length === 0;
        if (workoutExcuseWrap instanceof HTMLElement) {
            workoutExcuseWrap.hidden = !showWorkoutExcuse;
        }
    };

    const enforceCompletedFromSelections = (card) => {
        const workoutDoneInput = card.querySelector('[data-workout-done]');
        if (!(workoutDoneInput instanceof HTMLInputElement)) {
            return;
        }
        if (hasWorkoutSelection(card)) {
            workoutDoneInput.checked = true;
        }
    };

    const clearPrimaryWorkout = (card) => {
        const select = card.querySelector('[data-primary-workout-select]');
        const customWrap = card.querySelector('[data-primary-workout-custom-wrap]');
        const customInput = card.querySelector('[data-primary-workout-custom]');
        if (select instanceof HTMLSelectElement) {
            select.hidden = false;
            select.value = '';
        }
        if (customWrap instanceof HTMLElement) {
            customWrap.hidden = true;
        }
        if (customInput instanceof HTMLInputElement) {
            customInput.value = '';
        }
    };

    const setupExtraRow = (card, row) => {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const select = row.querySelector('[data-workout-select]');
        if (select instanceof HTMLSelectElement) {
            setWorkoutRowVisibility(row);
            select.addEventListener('change', () => {
                setWorkoutRowVisibility(row);
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
        const customInput = row.querySelector('[data-workout-custom-input]');
        if (customInput instanceof HTMLInputElement) {
            customInput.addEventListener('input', () => {
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
        const removeButton = row.querySelector('[data-extra-remove]');
        if (removeButton instanceof HTMLButtonElement) {
            removeButton.addEventListener('click', () => {
                row.remove();
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
    };

    const appendExtraRow = (card, preset) => {
        const list = card.querySelector('[data-extra-list]');
        const template = card.querySelector('template[data-extra-template]');
        if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-extra-row]');
        if (!(row instanceof HTMLElement)) {
            return;
        }

        const select = row.querySelector('[data-workout-select]');
        const customInput = row.querySelector('[data-workout-custom-input]');
        const customWrap = row.querySelector('[data-workout-custom-wrap]');

        if (select instanceof HTMLSelectElement && preset && typeof preset === 'object') {
            const presetTypeId = String(preset.workout_type_id || '').trim();
            const presetType = String(preset.workout_type || '').trim();
            if (presetTypeId !== '') {
                select.value = presetTypeId;
            } else if (presetType !== '') {
                select.value = '__custom__';
            }
            if (customInput instanceof HTMLInputElement && presetType !== '' && select.value === '__custom__') {
                customInput.value = presetType;
            }
            if (customWrap instanceof HTMLElement) {
                customWrap.hidden = select.value !== '__custom__';
            }
        }

        list.appendChild(row);
        setupExtraRow(card, row);
    };

    const addHabitToCard = (card, habit, checked) => {
        const list = card.querySelector('[data-habits-list]');
        if (!(list instanceof HTMLElement)) {
            return;
        }
        const code = String(habit.code || '').trim();
        const label = String(habit.label || '').trim();
        if (!isFilled(code) || !isFilled(label)) {
            return;
        }

        const existing = list.querySelector('[data-habit-code="' + code + '"]');
        if (existing instanceof HTMLInputElement) {
            if (checked) {
                existing.checked = true;
            }
            return;
        }

        const wrapper = document.createElement('label');
        wrapper.className = 'check';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'habit_' + code;
        input.value = '1';
        input.dataset.habitCode = code;
        input.checked = Boolean(checked);

        wrapper.appendChild(input);
        wrapper.appendChild(document.createTextNode(' ' + label));
        list.appendChild(wrapper);
    };

    const setupCustomHabitForm = (card, allCards) => {
        const toggle = card.querySelector('[data-custom-habit-toggle]');
        const form = card.querySelector('[data-custom-habit-form]');
        if (!(toggle instanceof HTMLButtonElement) || !(form instanceof HTMLElement)) {
            return;
        }

        const input = form.querySelector('[data-custom-habit-input]');
        const saveButton = form.querySelector('[data-custom-habit-save]');
        const cancelButton = form.querySelector('[data-custom-habit-cancel]');
        const status = form.querySelector('[data-custom-habit-status]');

        const setStatus = (message, className) => {
            if (!(status instanceof HTMLElement)) {
                return;
            }
            status.textContent = message;
            status.className = 'muted small ' + className;
        };

        toggle.addEventListener('click', () => {
            form.hidden = !form.hidden;
            if (!form.hidden && input instanceof HTMLInputElement) {
                input.focus();
            }
        });

        cancelButton?.addEventListener('click', () => {
            form.hidden = true;
            if (input instanceof HTMLInputElement) {
                input.value = '';
            }
            setStatus('', '');
        });

        saveButton?.addEventListener('click', async () => {
            if (!(input instanceof HTMLInputElement) || !(saveButton instanceof HTMLButtonElement)) {
                return;
            }

            const label = String(input.value || '').trim();
            if (!isFilled(label)) {
                setStatus(labels.customHabitRequired, 'save-status error');
                return;
            }

            saveButton.disabled = true;
            setStatus(labels.customHabitSaving, 'save-status saving');

            try {
                const response = await fetch('/?page=api_create_habit', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: csrf,
                        label: label,
                    }),
                });

                const json = await response.json();
                if (!response.ok || !json.ok || !json.habit) {
                    setStatus(json.message || labels.customHabitError, 'save-status error');
                    return;
                }

                allCards.forEach((targetCard) => {
                    addHabitToCard(targetCard, json.habit, targetCard === card);
                });

                setStatus(labels.customHabitCreated, 'save-status ok');
                input.value = '';
                form.hidden = true;
            } catch (error) {
                setStatus(labels.customHabitError, 'save-status error');
            } finally {
                saveButton.disabled = false;
            }
        });
    };

    const cards = [...grid.querySelectorAll('.week-day-card')];

    cards.forEach((card) => {
        const primarySelect = card.querySelector('[data-primary-workout-select]');
        const primaryCustomWrap = card.querySelector('[data-primary-workout-custom-wrap]');
        const primaryCustomInput = card.querySelector('[data-primary-workout-custom]');
        const primaryReset = card.querySelector('[data-primary-workout-reset]');
        const workoutDoneInput = card.querySelector('[data-workout-done]');

        const updatePrimaryVisibility = () => {
            if (!(primarySelect instanceof HTMLSelectElement) || !(primaryCustomWrap instanceof HTMLElement)) {
                return;
            }
            const isCustom = primarySelect.value === '__custom__';
            primarySelect.hidden = isCustom;
            primaryCustomWrap.hidden = !isCustom;
            if (!isCustom && primaryCustomInput instanceof HTMLInputElement) {
                primaryCustomInput.value = '';
            }
        };

        if (primarySelect instanceof HTMLSelectElement) {
            primarySelect.addEventListener('change', () => {
                updatePrimaryVisibility();
                enforceCompletedFromSelections(card);
                updateExcuseVisibility(card);
            });
            updatePrimaryVisibility();
        }

        if (primaryCustomInput instanceof HTMLInputElement) {
            primaryCustomInput.addEventListener('input', () => {
                enforceCompletedFromSelections(card);
                updateExcuseVisibility(card);
            });
        }

        if (primaryReset instanceof HTMLButtonElement) {
            primaryReset.addEventListener('click', () => {
                if (primarySelect instanceof HTMLSelectElement) {
                    primarySelect.value = '';
                }
                if (primaryCustomInput instanceof HTMLInputElement) {
                    primaryCustomInput.value = '';
                }
                updatePrimaryVisibility();
                updateExcuseVisibility(card);
            });
        }

        if (workoutDoneInput instanceof HTMLInputElement) {
            workoutDoneInput.addEventListener('change', () => {
                if (!workoutDoneInput.checked) {
                    clearPrimaryWorkout(card);
                }
                enforceCompletedFromSelections(card);
                updateExcuseVisibility(card);
            });
        }

        const stepsInput = card.querySelector('[data-steps-input]');
        if (stepsInput instanceof HTMLInputElement) {
            stepsInput.addEventListener('input', () => updateExcuseVisibility(card));
            stepsInput.addEventListener('change', () => updateExcuseVisibility(card));
        }

        getExtraWorkoutRows(card).forEach((row) => setupExtraRow(card, row));

        const extraToggle = card.querySelector('[data-extra-toggle]');
        const extraPanel = card.querySelector('[data-extra-panel]');
        if (extraToggle instanceof HTMLButtonElement && extraPanel instanceof HTMLElement) {
            extraToggle.addEventListener('click', () => {
                if (extraPanel.hidden) {
                    extraPanel.hidden = false;
                    if (getExtraWorkoutRows(card).length === 0) {
                        appendExtraRow(card, null);
                    }
                } else {
                    extraPanel.hidden = true;
                }
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }

        const extraAdd = card.querySelector('[data-extra-add]');
        if (extraAdd instanceof HTMLButtonElement) {
            extraAdd.addEventListener('click', () => {
                appendExtraRow(card, null);
                const extraPanelNode = card.querySelector('[data-extra-panel]');
                if (extraPanelNode instanceof HTMLElement) {
                    extraPanelNode.hidden = false;
                }
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }

        setupCustomHabitForm(card, cards);

        enforceCompletedFromSelections(card);
        updateExtraCounter(card);
        updateExcuseVisibility(card);
    });

    async function saveRow(row) {
        const workouts = collectWorkouts(row);
        const extraWorkouts = getExtraWorkouts(row);
        const primaryWorkout = getPrimaryWorkout(row);
        const workoutDoneInput = row.querySelector('[data-workout-done]');

        const data = {
            csrf_token: csrf,
            user_id: userId,
            log_date: row.dataset.date,
            steps: row.querySelector('[name="steps"]').value,
            distance_km: row.querySelector('[name="distance_km"]').value,
            training_calories_burned: row.querySelector('[name="training_calories_burned"]').value,
            workout_done: (workoutDoneInput instanceof HTMLInputElement && workoutDoneInput.checked) || workouts.length > 0 ? 1 : 0,
            junk_food: row.querySelector('[name="junk_food"]') instanceof HTMLInputElement ? (row.querySelector('[name="junk_food"]').checked ? 1 : 0) : 0,
            extra_workout: extraWorkouts.length > 0 ? 1 : 0,
            weight: row.querySelector('[name="weight"]').value,
            workout_type_id: primaryWorkout ? (primaryWorkout.workout_type_id || '') : '',
            workout_type: primaryWorkout ? (primaryWorkout.workout_type || '') : '',
            workouts: workouts,
            step_exception_reason: row.querySelector('[name="step_exception_reason"]').value,
            workout_exception_reason: row.querySelector('[name="workout_exception_reason"]').value,
            habits: {},
            notes: row.querySelector('[name="notes"]').value,
        };

        row.querySelectorAll('[data-habit-code]').forEach((input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            data.habits[input.dataset.habitCode] = input.checked ? 1 : 0;
        });

        const status = row.querySelector('.save-status');
        const button = row.querySelector('.js-save-row');
        if (!(status instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
            return false;
        }

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
            if (!(row instanceof HTMLElement)) {
                return;
            }
            await saveRow(row);
        });
    });

    const saveAll = document.getElementById('save-all-rows');
    const saveAllStatus = document.getElementById('save-all-status');
    saveAll?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        if (!(button instanceof HTMLButtonElement) || !(saveAllStatus instanceof HTMLElement)) {
            return;
        }

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
