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
            <input type="hidden" name="page" value="table">
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
                <?= e(t('common.week_monday')) ?>
                <input type="date" name="week_start" value="<?= e($weekStart) ?>" onchange="this.form.submit()">
            </label>
        </form>

        <div class="table-wrap spreadsheet-wrap">
            <table class="table spreadsheet" id="spreadsheet" data-testid="spreadsheet-table">
                <thead>
                <tr>
                    <th class="sticky-col"><?= e(t('common.date')) ?></th>
                    <th><?= e(t('metric.steps')) ?></th>
                    <th><?= e(t('metric.distance_km')) ?></th>
                    <th><?= e(t('entries.workout')) ?></th>
                    <th><?= e(t('table.junk')) ?></th>
                    <th><?= e(t('table.extra_wo')) ?></th>
                    <th><?= e(t('metric.weight')) ?></th>
                    <th><?= e(t('entries.workout_type')) ?></th>
                    <th><?= e(t('table.exc_steps')) ?></th>
                    <th><?= e(t('table.exc_workout')) ?></th>
                    <?php foreach (($habits ?? []) as $habit): ?>
                        <th><?= e((string) $habit['label']) ?></th>
                    <?php endforeach; ?>
                    <th><?= e(t('common.notes')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($weekDates as $idx => $date): ?>
                    <?php $log = $logsByDate[$date] ?? []; ?>
                    <tr data-date="<?= e($date) ?>">
                        <td class="sticky-col date-cell">
                            <strong><?= e($weekdayNames[$idx] ?? $date) ?></strong>
                            <span class="muted small"><?= e(format_date_eu($date)) ?></span>
                        </td>
                        <td><input type="number" min="0" name="steps" value="<?= e((string) ($log['steps'] ?? 0)) ?>"></td>
                        <td><input type="number" min="0" step="0.01" name="distance_km" value="<?= e((string) ($log['distance_km'] ?? '')) ?>"></td>
                        <td><input type="checkbox" name="workout_done" value="1" <?= !empty($log) && (int) ($log['workout_done'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                        <td><input type="checkbox" name="junk_food" value="1" <?= !empty($log) && (int) ($log['junk_food'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                        <td><input type="checkbox" name="extra_workout" value="1" <?= !empty($log) && (int) ($log['extra_workout'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                        <td><input type="number" step="0.1" name="weight" value="<?= e((string) ($log['weight'] ?? '')) ?>"></td>
                        <td>
                            <select name="workout_type_id">
                                <option value=""><?= e(t('common.none')) ?></option>
                                <?php foreach (($workoutTypes ?? []) as $type): ?>
                                    <option value="<?= (int) $type['id'] ?>" <?= (int) ($log['workout_type_id'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="workout_type" list="workout-type-options-week" value="<?= e((string) ($log['workout_type'] ?? '')) ?>">
                        </td>
                        <td><input type="text" name="step_exception_reason" value="<?= e((string) ($log['step_exception_reason'] ?? '')) ?>"></td>
                        <td><input type="text" name="workout_exception_reason" value="<?= e((string) ($log['workout_exception_reason'] ?? '')) ?>"></td>
                        <?php foreach (($habits ?? []) as $habit): ?>
                            <?php $code = (string) $habit['code']; ?>
                            <td><input type="checkbox" name="habit_<?= e($code) ?>" data-habit-code="<?= e($code) ?>" value="1" <?= !empty($log['habits'][$code]) && (int) $log['habits'][$code]['value'] === 1 ? 'checked' : '' ?>></td>
                        <?php endforeach; ?>
                        <td><input type="text" name="notes" value="<?= e((string) ($log['notes'] ?? '')) ?>"></td>
                        <td>
                            <button class="btn small js-save-row" type="button"><?= e(t('common.save')) ?></button>
                            <span class="save-status" aria-live="polite"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <datalist id="workout-type-options-week">
                <?php foreach (($workoutTypes ?? []) as $type): ?>
                    <option value="<?= e((string) $type['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="inline-actions">
            <button class="btn btn-primary" type="button" id="save-all-rows" data-testid="save-all-rows"><?= e(t('table.save_week')) ?></button>
        </div>
    </article>
</section>

<script>
(function () {
    const table = document.getElementById('spreadsheet');
    const csrf = <?= json_encode(csrf_token()) ?>;
    const userId = <?= (int) $selectedUser['id'] ?>;
    const labels = {
        saving: <?= json_encode(t('common.saving')) ?>,
        saved: <?= json_encode(t('common.saved')) ?>,
        error: <?= json_encode(t('common.error')) ?>,
    };

    async function saveRow(row) {
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
            workout_type_id: row.querySelector('[name="workout_type_id"]').value,
            workout_type: row.querySelector('[name="workout_type"]').value,
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

    table.querySelectorAll('.js-save-row').forEach((button) => {
        button.addEventListener('click', async () => {
            const row = button.closest('tr');
            await saveRow(row);
        });
    });

    document.getElementById('save-all-rows').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        const rows = [...table.querySelectorAll('tbody tr')];
        for (const row of rows) {
            await saveRow(row);
        }
        button.disabled = false;
    });
})();
</script>
