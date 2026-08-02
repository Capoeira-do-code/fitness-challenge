<?php

declare(strict_types=1);

// render_view() evaluates this file before the shared layout, so this controls
// both the browser title and the compact page name in the mobile topbar.
$title = t('nav.nutrition');
$series = array_values((array) ($nutritionSeries ?? []));
$allEntries = array_values((array) ($nutritionEntries ?? []));
$activeEntries = array_values(array_filter($allEntries, static fn(array $entry): bool => trim((string) ($entry['archived_at'] ?? '')) === ''));
$archivedEntries = array_values(array_filter($allEntries, static fn(array $entry): bool => trim((string) ($entry['archived_at'] ?? '')) !== ''));
$autoOpenMealId = max(0, (int) ($nutritionAutoOpenMealId ?? 0));
$nutritionReturnTo = trim((string) ($nutritionReturnContext ?? ''));
$historyView = (string) ($_GET['nutrition_history'] ?? 'active');
$historyView = in_array($historyView, ['active', 'archived'], true) ? $historyView : 'active';
if ($autoOpenMealId > 0) {
    foreach ($allEntries as $autoOpenEntry) {
        if ((int) ($autoOpenEntry['id'] ?? 0) !== $autoOpenMealId) {
            continue;
        }
        $historyView = trim((string) ($autoOpenEntry['archived_at'] ?? '')) !== '' ? 'archived' : 'active';
        break;
    }
}
$entries = $historyView === 'archived' ? $archivedEntries : $activeEntries;
$consumed = array_sum(array_map(static fn(array $row): float => (float) ($row['consumed'] ?? 0), $series));
$exercise = array_sum(array_map(static fn(array $row): float => (float) ($row['exercise'] ?? 0), $series));
$balances = array_values(array_filter(array_map(static fn(array $row): ?float => isset($row['balance']) ? (float) $row['balance'] : null, $series), static fn(?float $value): bool => $value !== null));
$balance = array_sum($balances);
$tdeeReady = is_array($nutritionTdee ?? null);
$hasNutritionData = count(array_filter($series, static fn(array $row): bool => !empty($row['has_meal_data']))) > 0;
$nutritionLocale = current_locale();
$nutritionUi = [
    'en' => [
        'active' => 'Current', 'archived' => 'Archived', 'archive' => 'Archive', 'unarchive' => 'Unarchive',
        'view_details' => 'View details', 'archived_badge' => 'Archived', 'primary' => 'Essentials',
        'macros' => 'Macros', 'more_nutrients' => 'More nutrients', 'schedule' => 'Date and time',
        'photo' => 'Meal photo', 'photo_hint' => 'Optional · JPG, PNG or WebP', 'notes_hint' => 'Add a short description…',
        'new_meal' => 'New meal', 'new_meal_hint' => 'Log the essentials first. Add only the nutrients you know.',
        'save_meal' => 'Save meal', 'save_changes' => 'Save changes', 'archive_confirm' => 'Archive this meal? You can restore it later.',
        'unarchive_confirm' => 'Restore this meal to your current history?', 'action_done' => 'Meal history updated.',
        'action_error' => 'The meal could not be updated. Try again.', 'empty_active' => 'No current meals in this period.',
        'empty_archived' => 'No archived meals in this period.', 'shared_photo_hint' => 'Meals with a photo are also shared in your gallery.',
        'energy_setup_title' => 'Complete your energy expenditure', 'energy_setup_hint' => 'We need your data to calculate a realistic deficit. Until then, we will not show a misleading balance.',
        'configure' => 'Set up', 'consumed' => 'Consumed', 'period_14_days' => 'kcal · 14 days', 'exercise' => 'Exercise',
        'recorded_kcal' => 'kcal logged', 'deficit' => 'Deficit', 'surplus' => 'Surplus', 'accumulated_kcal' => 'kcal accumulated',
        'complete_profile' => 'Complete your profile', 'daily_tdee' => 'Daily TDEE', 'estimated_method' => 'Mifflin–St Jeor estimate',
        'manual_value' => 'Manual value', 'daily_balance' => 'Daily balance', 'intake_and_expenditure' => 'Intake and expenditure',
        'adjust_tdee' => 'Adjust TDEE', 'first_meal_trend' => 'Log your first meal to see the trend.', 'calorie_history' => 'Calorie history',
        'history' => 'History', 'recent_meals' => 'Recent meals', 'meal_history' => 'Meal history',
        'energy_expenditure' => 'Energy expenditure', 'configure_tdee' => 'Set up TDEE', 'birth_date' => 'Date of birth',
        'formula_sex' => 'Sex used by the formula', 'select' => 'Select', 'female' => 'Female', 'male' => 'Male',
        'height_cm' => 'Height (cm)', 'activity' => 'Activity', 'activity_sedentary' => 'Sedentary', 'activity_light' => 'Light',
        'activity_moderate' => 'Moderate', 'activity_active' => 'Active', 'activity_very_active' => 'Very active',
        'calculation_weight' => 'Weight used in the calculation', 'no_weight' => 'No weight logged',
        'weight_missing_hint' => 'Log your weight in Daily Log to calculate the estimate.',
        'weight_latest_hint' => 'We use your latest weight logged in Daily Log.',
        'weight_target_hint' => 'We use your profile target weight; log your current weight to improve the estimate.',
        'manual_tdee' => 'Manual TDEE', 'optional' => 'optional', 'manual_tdee_hint' => 'Use it only if you already know your maintenance calories. This value replaces the automatic calculation.',
        'tdee_example' => 'e.g. 2350', 'save_calculation' => 'Save calculation', 'chart_consumed' => 'Consumed', 'chart_total_expenditure' => 'Total expenditure',
    ],
    'es' => [
        'active' => 'Actuales', 'archived' => 'Archivadas', 'archive' => 'Archivar', 'unarchive' => 'Desarchivar',
        'view_details' => 'Ver detalles', 'archived_badge' => 'Archivada', 'primary' => 'Esencial',
        'macros' => 'Macros', 'more_nutrients' => 'Más nutrientes', 'schedule' => 'Fecha y hora',
        'photo' => 'Foto de la comida', 'photo_hint' => 'Opcional · JPG, PNG o WebP', 'notes_hint' => 'Añade una descripción breve…',
        'new_meal' => 'Nueva comida', 'new_meal_hint' => 'Registra primero lo esencial. Añade solo los nutrientes que conozcas.',
        'save_meal' => 'Guardar comida', 'save_changes' => 'Guardar cambios', 'archive_confirm' => '¿Archivar esta comida? Podrás recuperarla después.',
        'unarchive_confirm' => '¿Devolver esta comida al historial actual?', 'action_done' => 'Historial de comidas actualizado.',
        'action_error' => 'No se pudo actualizar la comida. Inténtalo de nuevo.', 'empty_active' => 'No hay comidas actuales en este periodo.',
        'empty_archived' => 'No hay comidas archivadas en este periodo.', 'shared_photo_hint' => 'Las comidas con foto también se comparten en tu galería.',
        'energy_setup_title' => 'Completa tu gasto energético', 'energy_setup_hint' => 'Necesitamos tus datos para calcular un déficit realista. Hasta entonces no mostraremos un balance engañoso.',
        'configure' => 'Configurar', 'consumed' => 'Consumidas', 'period_14_days' => 'kcal · 14 días', 'exercise' => 'Ejercicio',
        'recorded_kcal' => 'kcal registradas', 'deficit' => 'Déficit', 'surplus' => 'Superávit', 'accumulated_kcal' => 'kcal acumuladas',
        'complete_profile' => 'Completa tu perfil', 'daily_tdee' => 'TDEE diario', 'estimated_method' => 'Estimación Mifflin–St Jeor',
        'manual_value' => 'Valor manual', 'daily_balance' => 'Balance diario', 'intake_and_expenditure' => 'Consumo y gasto',
        'adjust_tdee' => 'Ajustar TDEE', 'first_meal_trend' => 'Registra tu primera comida para ver la tendencia.', 'calorie_history' => 'Histórico de calorías',
        'history' => 'Historial', 'recent_meals' => 'Comidas recientes', 'meal_history' => 'Historial de comidas',
        'energy_expenditure' => 'Gasto energético', 'configure_tdee' => 'Configurar TDEE', 'birth_date' => 'Fecha de nacimiento',
        'formula_sex' => 'Sexo usado por la fórmula', 'select' => 'Selecciona', 'female' => 'Femenino', 'male' => 'Masculino',
        'height_cm' => 'Altura (cm)', 'activity' => 'Actividad', 'activity_sedentary' => 'Sedentaria', 'activity_light' => 'Ligera',
        'activity_moderate' => 'Moderada', 'activity_active' => 'Activa', 'activity_very_active' => 'Muy activa',
        'calculation_weight' => 'Peso usado en el cálculo', 'no_weight' => 'Sin peso registrado',
        'weight_missing_hint' => 'Registra tu peso en Daily Log para poder calcular la estimación.',
        'weight_latest_hint' => 'Usamos tu último peso registrado en Daily Log.',
        'weight_target_hint' => 'Usamos el peso objetivo de tu perfil; registra tu peso actual para mejorar la estimación.',
        'manual_tdee' => 'TDEE manual', 'optional' => 'opcional', 'manual_tdee_hint' => 'Úsalo solo si ya conoces tus calorías de mantenimiento. Este valor sustituye el cálculo automático.',
        'tdee_example' => 'Ej. 2350', 'save_calculation' => 'Guardar cálculo', 'chart_consumed' => 'Consumidas', 'chart_total_expenditure' => 'Gasto total',
    ],
    'it' => [
        'active' => 'Attuali', 'archived' => 'Archiviate', 'archive' => 'Archivia', 'unarchive' => 'Ripristina',
        'view_details' => 'Vedi dettagli', 'archived_badge' => 'Archiviata', 'primary' => 'Essenziale',
        'macros' => 'Macro', 'more_nutrients' => 'Altri nutrienti', 'schedule' => 'Data e ora',
        'photo' => 'Foto del pasto', 'photo_hint' => 'Opzionale · JPG, PNG o WebP', 'notes_hint' => 'Aggiungi una breve descrizione…',
        'new_meal' => 'Nuovo pasto', 'new_meal_hint' => 'Registra prima i dati essenziali. Aggiungi solo i nutrienti che conosci.',
        'save_meal' => 'Salva pasto', 'save_changes' => 'Salva modifiche', 'archive_confirm' => 'Archiviare questo pasto? Potrai ripristinarlo in seguito.',
        'unarchive_confirm' => 'Ripristinare questo pasto nella cronologia attuale?', 'action_done' => 'Cronologia dei pasti aggiornata.',
        'action_error' => 'Impossibile aggiornare il pasto. Riprova.', 'empty_active' => 'Nessun pasto attuale in questo periodo.',
        'empty_archived' => 'Nessun pasto archiviato in questo periodo.', 'shared_photo_hint' => 'I pasti con foto vengono condivisi anche nella tua galleria.',
        'energy_setup_title' => 'Completa il tuo dispendio energetico', 'energy_setup_hint' => 'Ci servono i tuoi dati per calcolare un deficit realistico. Fino ad allora non mostreremo un bilancio fuorviante.',
        'configure' => 'Configura', 'consumed' => 'Assunte', 'period_14_days' => 'kcal · 14 giorni', 'exercise' => 'Esercizio',
        'recorded_kcal' => 'kcal registrate', 'deficit' => 'Deficit', 'surplus' => 'Surplus', 'accumulated_kcal' => 'kcal accumulate',
        'complete_profile' => 'Completa il profilo', 'daily_tdee' => 'TDEE giornaliero', 'estimated_method' => 'Stima Mifflin–St Jeor',
        'manual_value' => 'Valore manuale', 'daily_balance' => 'Bilancio giornaliero', 'intake_and_expenditure' => 'Assunzione e dispendio',
        'adjust_tdee' => 'Regola TDEE', 'first_meal_trend' => 'Registra il primo pasto per vedere l’andamento.', 'calorie_history' => 'Cronologia calorie',
        'history' => 'Cronologia', 'recent_meals' => 'Pasti recenti', 'meal_history' => 'Cronologia dei pasti',
        'energy_expenditure' => 'Dispendio energetico', 'configure_tdee' => 'Configura TDEE', 'birth_date' => 'Data di nascita',
        'formula_sex' => 'Sesso usato dalla formula', 'select' => 'Seleziona', 'female' => 'Femminile', 'male' => 'Maschile',
        'height_cm' => 'Altezza (cm)', 'activity' => 'Attività', 'activity_sedentary' => 'Sedentaria', 'activity_light' => 'Leggera',
        'activity_moderate' => 'Moderata', 'activity_active' => 'Attiva', 'activity_very_active' => 'Molto attiva',
        'calculation_weight' => 'Peso usato nel calcolo', 'no_weight' => 'Nessun peso registrato',
        'weight_missing_hint' => 'Registra il peso nel Diario giornaliero per calcolare la stima.',
        'weight_latest_hint' => 'Usiamo l’ultimo peso registrato nel Diario giornaliero.',
        'weight_target_hint' => 'Usiamo il peso obiettivo del profilo; registra il peso attuale per migliorare la stima.',
        'manual_tdee' => 'TDEE manuale', 'optional' => 'opzionale', 'manual_tdee_hint' => 'Usalo solo se conosci già le calorie di mantenimento. Questo valore sostituisce il calcolo automatico.',
        'tdee_example' => 'Es. 2350', 'save_calculation' => 'Salva calcolo', 'chart_consumed' => 'Assunte', 'chart_total_expenditure' => 'Dispendio totale',
    ],
][$nutritionLocale] ?? [];
$nutritionFormatValue = static function (mixed $value, int $decimals = 1): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $formatted = number_format((float) $value, $decimals, ',', '.');
    return $decimals > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
};
$nutritionInputValue = static function (mixed $value, int $decimals = 3): string {
    if ($value === null || $value === '') {
        return '';
    }
    $formatted = number_format((float) $value, max(0, $decimals), '.', '');
    return $decimals > 0 ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
};
$nutritionHistoryUrl = static function (string $view) use ($rangeEnd): string {
    return '/?' . http_build_query(['page' => 'nutrition', 'date' => (string) $rangeEnd, 'nutrition_history' => $view]) . '#nutrition-history';
};
?>
<section class="screen nutrition-page stack-lg" data-nutrition-page>
    <header class="nutrition-hero">
        <div>
            <p class="eyebrow"><?= e(t('nav.nutrition')) ?></p>
            <h1><?= e(t('dashboard.calories_consumed')) ?></h1>
            <p class="muted"><?= e(format_date_eu((string) $rangeStart)) ?> — <?= e(format_date_eu((string) $rangeEnd)) ?></p>
        </div>
        <button class="btn btn-primary nutrition-new-meal" type="button" data-nutrition-open><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><?= e(t('entries.quick_meal')) ?></span></button>
    </header>

    <?php if (!$tdeeReady): ?>
        <article class="panel nutrition-setup-callout">
            <span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span>
            <div><strong><?= e($nutritionUi['energy_setup_title']) ?></strong><p class="muted"><?= e($nutritionUi['energy_setup_hint']) ?></p></div>
            <button class="btn btn-ghost" type="button" data-tdee-open><?= e($nutritionUi['configure']) ?></button>
        </article>
    <?php endif; ?>

    <div class="nutrition-kpi-grid">
        <article class="nutrition-kpi"><small><?= e($nutritionUi['consumed']) ?></small><strong><?= e(number_format($consumed, 0, ',', '.')) ?></strong><span><?= e($nutritionUi['period_14_days']) ?></span></article>
        <article class="nutrition-kpi"><small><?= e($nutritionUi['exercise']) ?></small><strong><?= e(number_format($exercise, 0, ',', '.')) ?></strong><span><?= e($nutritionUi['recorded_kcal']) ?></span></article>
        <article class="nutrition-kpi <?= $balance <= 0 ? 'is-deficit' : 'is-surplus' ?>"><small><?= e($nutritionUi[$balance <= 0 ? 'deficit' : 'surplus']) ?></small><strong><?= $tdeeReady ? e(number_format(abs($balance), 0, ',', '.')) : '—' ?></strong><span><?= e($nutritionUi[$tdeeReady ? 'accumulated_kcal' : 'complete_profile']) ?></span></article>
        <article class="nutrition-kpi"><small><?= e($nutritionUi['daily_tdee']) ?></small><strong><?= $tdeeReady ? e(number_format((float) $nutritionTdee['value'], 0, ',', '.')) : '—' ?></strong><span><?= e($nutritionUi[!empty($nutritionTdee['estimated']) ? 'estimated_method' : 'manual_value']) ?></span></article>
    </div>

    <article class="panel nutrition-chart-panel">
        <div class="panel-head"><div><p class="eyebrow"><?= e($nutritionUi['daily_balance']) ?></p><h2><?= e($nutritionUi['intake_and_expenditure']) ?></h2></div><button class="btn btn-ghost small" type="button" data-tdee-open><?= e($nutritionUi['adjust_tdee']) ?></button></div>
        <?php if (!$hasNutritionData): ?>
            <div class="empty-state"><p><?= e($nutritionUi['first_meal_trend']) ?></p></div>
        <?php else: ?>
            <div class="metric-chart-wrap"><canvas id="nutritionChart" aria-label="<?= e($nutritionUi['calorie_history']) ?>"></canvas></div>
        <?php endif; ?>
    </article>

    <article class="panel nutrition-recent" id="nutrition-history">
        <div class="nutrition-history-head">
            <div><p class="eyebrow"><?= e($nutritionUi['history']) ?></p><h2><?= e($nutritionUi['recent_meals']) ?></h2></div>
            <nav class="nutrition-history-tabs" aria-label="<?= e($nutritionUi['meal_history']) ?>">
                <a href="<?= e($nutritionHistoryUrl('active')) ?>"<?= $historyView === 'active' ? ' aria-current="page"' : '' ?>><?= e($nutritionUi['active']) ?><strong data-nutrition-history-count="active"><?= count($activeEntries) ?></strong></a>
                <a href="<?= e($nutritionHistoryUrl('archived')) ?>"<?= $historyView === 'archived' ? ' aria-current="page"' : '' ?>><?= e($nutritionUi['archived']) ?><strong data-nutrition-history-count="archived"><?= count($archivedEntries) ?></strong></a>
            </nav>
        </div>
        <p class="nutrition-live-status" data-nutrition-live-status role="status" hidden></p>
        <div class="nutrition-entry-list" data-nutrition-entry-list>
            <?php foreach ($entries as $entry): ?>
                <?php
                $nutritionEntryId = (int) ($entry['id'] ?? 0);
                $nutritionEntryDetailModalId = 'nutrition-entry-detail-modal-' . $nutritionEntryId;
                $nutritionEntryEditModalId = 'nutrition-entry-edit-modal-' . $nutritionEntryId;
                $nutritionEntryArchived = trim((string) ($entry['archived_at'] ?? '')) !== '';
                $nutritionMealType = (string) ($entry['meal_type'] ?? 'other');
                $nutritionMealLabel = t('nutrition.type_' . $nutritionMealType);
                $nutritionPhotoPath = trim((string) ($entry['photo_path'] ?? ''));
                $nutritionPhotoUrl = $nutritionPhotoPath !== '' ? media_thumbnail_url($nutritionPhotoPath, 200) : '';
                $nutritionNotes = trim((string) ($entry['notes'] ?? ''));
                ?>
                <article class="nutrition-entry-row<?= $nutritionEntryArchived ? ' is-archived' : '' ?>" data-nutrition-entry-row="<?= $nutritionEntryId ?>" data-entry-archived="<?= $nutritionEntryArchived ? '1' : '0' ?>">
                    <span class="nutrition-meal-icon<?= $nutritionPhotoUrl !== '' ? ' has-photo' : '' ?>" aria-hidden="true">
                        <?php if ($nutritionPhotoUrl !== ''): ?><img src="<?= e($nutritionPhotoUrl) ?>" alt="" loading="lazy"><?php else: ?><?= activity_icon_svg('flame') ?><?php endif; ?>
                    </span>
                    <span class="nutrition-entry-copy">
                        <span class="nutrition-entry-title"><strong><?= e($nutritionMealLabel) ?></strong><?php if ($nutritionEntryArchived): ?><em><?= e($nutritionUi['archived_badge']) ?></em><?php endif; ?></span>
                        <small><?= e(format_date_eu((string) ($entry['entry_date'] ?? ''))) ?><?= trim((string) ($entry['entry_time'] ?? '')) !== '' ? ' · ' . e((string) $entry['entry_time']) : '' ?></small>
                        <?php if ($nutritionNotes !== ''): ?><span class="nutrition-entry-note"><?= e($nutritionNotes) ?></span><?php endif; ?>
                        <span class="nutrition-entry-macros"><span>P <?= e($nutritionFormatValue($entry['protein_g'] ?? null)) ?>g</span><span>C <?= e($nutritionFormatValue($entry['carbs_g'] ?? null)) ?>g</span><span>F <?= e($nutritionFormatValue($entry['fat_g'] ?? null)) ?>g</span></span>
                    </span>
                    <strong class="nutrition-entry-calories"><?= e($nutritionFormatValue($entry['calories'] ?? 0, 0)) ?><small>kcal</small></strong>
                    <details class="kebab-menu nutrition-entry-menu" data-kebab-menu data-align="end">
                        <summary class="kebab-menu-trigger nutrition-entry-actions" aria-label="<?= e(t('nutrition.manage_meal')) ?>" aria-expanded="false"><span class="kebab-menu-dots" aria-hidden="true"><span></span><span></span><span></span></span></summary>
                        <div class="kebab-menu-panel" role="menu">
                            <button type="button" class="kebab-menu-item" data-app-modal-open="<?= e($nutritionEntryDetailModalId) ?>" aria-haspopup="dialog"><span aria-hidden="true"><?= activity_icon_svg('info') ?></span><span><?= e($nutritionUi['view_details']) ?></span></button>
                            <button type="button" class="kebab-menu-item" data-app-modal-open="<?= e($nutritionEntryEditModalId) ?>" aria-haspopup="dialog"><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><?= e(t('common.edit')) ?></span></button>
                            <form method="post" action="/?page=nutrition" data-nutrition-row-action="<?= $nutritionEntryArchived ? 'unarchive' : 'archive' ?>" data-entry-id="<?= $nutritionEntryId ?>" data-entry-archived="<?= $nutritionEntryArchived ? '1' : '0' ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_nutrition_entry">
                                <input type="hidden" name="entry_id" value="<?= $nutritionEntryId ?>">
                                <input type="hidden" name="return_date" value="<?= e((string) $rangeEnd) ?>">
                                <?php if ($nutritionReturnTo !== ''): ?><input type="hidden" name="return_to" value="<?= e($nutritionReturnTo) ?>"><?php endif; ?>
                                <input type="hidden" name="nutrition_entry_state" value="<?= $nutritionEntryArchived ? 'unarchive' : 'archive' ?>">
                                <button type="submit" class="kebab-menu-item" data-confirm-action="<?= e($nutritionEntryArchived ? $nutritionUi['unarchive_confirm'] : $nutritionUi['archive_confirm']) ?>"><span aria-hidden="true"><?= activity_icon_svg($nutritionEntryArchived ? 'restart' : 'download') ?></span><span><?= e($nutritionEntryArchived ? $nutritionUi['unarchive'] : $nutritionUi['archive']) ?></span></button>
                            </form>
                            <form method="post" action="/?page=nutrition" data-nutrition-row-action="delete" data-entry-id="<?= $nutritionEntryId ?>" data-entry-archived="<?= $nutritionEntryArchived ? '1' : '0' ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_nutrition_entry">
                                <input type="hidden" name="entry_id" value="<?= $nutritionEntryId ?>">
                                <input type="hidden" name="return_date" value="<?= e((string) $rangeEnd) ?>">
                                <?php if ($nutritionReturnTo !== ''): ?><input type="hidden" name="return_to" value="<?= e($nutritionReturnTo) ?>"><?php endif; ?>
                                <button type="submit" class="kebab-menu-item is-danger" data-confirm-action="<?= e(t('nutrition.delete_meal_confirm')) ?>"><span aria-hidden="true"><?= activity_icon_svg('trash') ?></span><span><?= e(t('common.delete')) ?></span></button>
                            </form>
                        </div>
                    </details>
                </article>

                <div class="app-modal nutrition-entry-modal" id="<?= e($nutritionEntryDetailModalId) ?>" data-entry-modal-for="<?= $nutritionEntryId ?>"<?= $autoOpenMealId === $nutritionEntryId ? ' data-nutrition-entry-auto-open="1"' : '' ?> hidden role="dialog" aria-modal="true" aria-labelledby="<?= e($nutritionEntryDetailModalId) ?>-title">
                    <div class="app-modal-card nutrition-entry-modal-card nutrition-entry-detail-card">
                        <div class="app-modal-head"><div><p class="eyebrow"><?= e(t('nutrition.meal_details')) ?></p><h2 id="<?= e($nutritionEntryDetailModalId) ?>-title"><?= e($nutritionMealLabel) ?></h2></div><button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button></div>
                        <?php if ($nutritionPhotoUrl !== ''): ?><img class="nutrition-entry-detail-photo" src="<?= e(media_thumbnail_url($nutritionPhotoPath, 800)) ?>" alt="<?= e($nutritionMealLabel) ?>" loading="lazy"><?php endif; ?>
                        <dl class="nutrition-entry-detail-grid">
                            <div><dt><?= e(t('nutrition.calories')) ?></dt><dd><?= e($nutritionFormatValue($entry['calories'] ?? 0, 0)) ?> kcal</dd></div>
                            <div><dt><?= e(t('nutrition.protein')) ?></dt><dd><?= e($nutritionFormatValue($entry['protein_g'] ?? null)) ?> g</dd></div>
                            <div><dt><?= e(t('nutrition.carbs')) ?></dt><dd><?= e($nutritionFormatValue($entry['carbs_g'] ?? null)) ?> g</dd></div>
                            <div><dt><?= e(t('nutrition.fat')) ?></dt><dd><?= e($nutritionFormatValue($entry['fat_g'] ?? null)) ?> g</dd></div>
                            <div><dt><?= e(t('nutrition.fiber')) ?></dt><dd><?= e($nutritionFormatValue($entry['fiber_g'] ?? null)) ?> g</dd></div>
                            <div><dt><?= e(t('nutrition.sugar')) ?></dt><dd><?= e($nutritionFormatValue($entry['sugar_g'] ?? null)) ?> g</dd></div>
                            <div><dt><?= e(t('nutrition.sodium')) ?></dt><dd><?= e($nutritionFormatValue($entry['sodium_mg'] ?? null, 0)) ?> mg</dd></div>
                            <div><dt><?= e($nutritionUi['schedule']) ?></dt><dd><?= e(format_date_eu((string) ($entry['entry_date'] ?? ''))) ?><?= trim((string) ($entry['entry_time'] ?? '')) !== '' ? ' · ' . e((string) $entry['entry_time']) : '' ?></dd></div>
                        </dl>
                        <?php if ($nutritionNotes !== ''): ?><div class="nutrition-entry-detail-notes"><strong><?= e(t('nutrition.notes')) ?></strong><p><?= nl2br(e($nutritionNotes)) ?></p></div><?php endif; ?>
                        <button type="button" class="btn btn-primary btn-block" data-app-modal-close><?= e(t('common.close_action')) ?></button>
                    </div>
                </div>

                <div class="app-modal nutrition-entry-modal" id="<?= e($nutritionEntryEditModalId) ?>" data-entry-modal-for="<?= $nutritionEntryId ?>" hidden role="dialog" aria-modal="true" aria-labelledby="<?= e($nutritionEntryEditModalId) ?>-title">
                    <div class="app-modal-card nutrition-entry-modal-card nutrition-editor-card">
                        <form method="post" action="/?page=nutrition" class="nutrition-editor-form nutrition-entry-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_nutrition_entry">
                            <input type="hidden" name="entry_id" value="<?= $nutritionEntryId ?>">
                            <input type="hidden" name="return_date" value="<?= e((string) $rangeEnd) ?>">
                            <?php if ($nutritionReturnTo !== ''): ?><input type="hidden" name="return_to" value="<?= e($nutritionReturnTo) ?>"><?php endif; ?>
                            <header class="nutrition-editor-head"><div><p class="eyebrow"><?= e(t('nutrition.meal_details')) ?></p><h2 id="<?= e($nutritionEntryEditModalId) ?>-title"><?= e(t('nutrition.edit_meal')) ?></h2></div><button type="button" class="dialog-close" data-app-modal-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></header>
                            <section class="nutrition-editor-section nutrition-editor-primary">
                                <span class="nutrition-editor-section-title"><?= e($nutritionUi['primary']) ?></span>
                                <label class="nutrition-calorie-field"><span><?= e(t('nutrition.calories')) ?></span><span class="nutrition-input-unit"><input type="text" name="calories" inputmode="numeric" pattern="[0-9]+" enterkeyhint="next" value="<?= e($nutritionInputValue($entry['calories'] ?? 0, 0)) ?>" required><strong>kcal</strong></span></label>
                                <label><?= e(t('nutrition.meal_type')) ?><select name="meal_type"><?php foreach (['breakfast', 'lunch', 'dinner', 'snack', 'other'] as $mealType): ?><option value="<?= e($mealType) ?>"<?= $nutritionMealType === $mealType ? ' selected' : '' ?>><?= e(t('nutrition.type_' . $mealType)) ?></option><?php endforeach; ?></select></label>
                            </section>
                            <section class="nutrition-editor-section">
                                <span class="nutrition-editor-section-title"><?= e($nutritionUi['schedule']) ?></span>
                                <div class="nutrition-editor-schedule"><label><?= e(t('common.date')) ?><input type="date" name="entry_date" value="<?= e((string) ($entry['entry_date'] ?? '')) ?>" required></label><label><?= e(t('nutrition.time')) ?><input type="time" name="entry_time" value="<?= e((string) ($entry['entry_time'] ?? '')) ?>"></label></div>
                            </section>
                            <section class="nutrition-editor-section">
                                <span class="nutrition-editor-section-title"><?= e($nutritionUi['macros']) ?></span>
                                <div class="nutrition-macro-grid">
                                    <label><span>P <small>g</small></span><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" enterkeyhint="next" name="protein_g" value="<?= e($nutritionInputValue($entry['protein_g'] ?? null)) ?>" placeholder="35"></label>
                                    <label><span>C <small>g</small></span><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" enterkeyhint="next" name="carbs_g" value="<?= e($nutritionInputValue($entry['carbs_g'] ?? null)) ?>" placeholder="60"></label>
                                    <label><span>F <small>g</small></span><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" enterkeyhint="next" name="fat_g" value="<?= e($nutritionInputValue($entry['fat_g'] ?? null)) ?>" placeholder="22"></label>
                                </div>
                                <details class="nutrition-advanced-fields"><summary><span><?= activity_icon_svg('sliders') ?><strong><?= e($nutritionUi['more_nutrients']) ?></strong></span><b aria-hidden="true">›</b></summary><div class="nutrition-secondary-grid"><label><?= e(t('nutrition.fiber')) ?><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" name="fiber_g" value="<?= e($nutritionInputValue($entry['fiber_g'] ?? null)) ?>"></label><label><?= e(t('nutrition.sugar')) ?><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" name="sugar_g" value="<?= e($nutritionInputValue($entry['sugar_g'] ?? null)) ?>"></label><label><?= e(t('nutrition.sodium')) ?><input type="text" inputmode="numeric" pattern="[0-9]+" name="sodium_mg" value="<?= e($nutritionInputValue($entry['sodium_mg'] ?? null, 0)) ?>"></label></div></details>
                            </section>
                            <label class="nutrition-editor-notes"><?= e(t('nutrition.notes')) ?><textarea name="notes" rows="3" maxlength="500" enterkeyhint="done" placeholder="<?= e($nutritionUi['notes_hint']) ?>"><?= e((string) ($entry['notes'] ?? '')) ?></textarea></label>
                            <footer class="nutrition-editor-save"><button type="submit" class="btn btn-primary btn-block"><?= e($nutritionUi['save_changes']) ?></button></footer>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="nutrition-history-empty empty-state" data-nutrition-history-empty<?= $entries === [] ? '' : ' hidden' ?>><p><?= e($historyView === 'archived' ? $nutritionUi['empty_archived'] : $nutritionUi['empty_active']) ?></p></div>
    </article>
</section>

<dialog class="app-dialog nutrition-dialog nutrition-editor-dialog" data-nutrition-dialog<?= !empty($nutritionAutoOpen) ? ' data-auto-open="1"' : '' ?>>
    <form method="post" enctype="multipart/form-data" class="nutrition-editor-form nutrition-create-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_nutrition_entry">
        <?php if ($nutritionReturnTo !== ''): ?><input type="hidden" name="return_to" value="<?= e($nutritionReturnTo) ?>"><?php endif; ?>
        <header class="nutrition-editor-head"><div><p class="eyebrow"><?= e($nutritionUi['new_meal']) ?></p><h2><?= e(t('entries.log_meal')) ?></h2><small class="muted"><?= e($nutritionUi['new_meal_hint']) ?></small></div><button type="button" class="dialog-close" data-dialog-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></header>
        <section class="nutrition-editor-section nutrition-editor-primary">
            <span class="nutrition-editor-section-title"><?= e($nutritionUi['primary']) ?></span>
            <label class="nutrition-calorie-field"><span><?= e(t('nutrition.calories')) ?></span><span class="nutrition-input-unit"><input type="text" name="calories" inputmode="numeric" pattern="[0-9]+" enterkeyhint="next" placeholder="650" required autofocus><strong>kcal</strong></span></label>
            <fieldset class="nutrition-meal-type-picker"><legend><?= e(t('nutrition.meal_type')) ?></legend><div><?php foreach (['breakfast', 'lunch', 'dinner', 'snack', 'other'] as $mealIndex => $mealType): ?><label><input type="radio" name="meal_type" value="<?= e($mealType) ?>"<?= $mealIndex === 0 ? ' checked' : '' ?>><span><?= e(t('nutrition.type_' . $mealType)) ?></span></label><?php endforeach; ?></div></fieldset>
        </section>
        <section class="nutrition-editor-section">
            <span class="nutrition-editor-section-title"><?= e($nutritionUi['schedule']) ?></span>
            <div class="nutrition-editor-schedule"><label><?= e(t('common.date')) ?><input type="date" name="entry_date" value="<?= e((string) ($rangeEnd ?? to_date(null))) ?>" required></label><label><?= e(t('nutrition.time')) ?><input type="time" name="entry_time" value="<?= e((new DateTimeImmutable())->format('H:i')) ?>"></label></div>
        </section>
        <section class="nutrition-editor-section">
            <span class="nutrition-editor-section-title"><?= e($nutritionUi['macros']) ?></span>
            <div class="nutrition-macro-grid">
                <label><span>P <small>g</small></span><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" enterkeyhint="next" name="protein_g" placeholder="35"></label>
                <label><span>C <small>g</small></span><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" enterkeyhint="next" name="carbs_g" placeholder="60"></label>
                <label><span>F <small>g</small></span><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" enterkeyhint="next" name="fat_g" placeholder="22"></label>
            </div>
            <details class="nutrition-advanced-fields"><summary><span><?= activity_icon_svg('sliders') ?><strong><?= e($nutritionUi['more_nutrients']) ?></strong></span><b aria-hidden="true">›</b></summary><div class="nutrition-secondary-grid"><label><?= e(t('nutrition.fiber')) ?><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" name="fiber_g" placeholder="8"></label><label><?= e(t('nutrition.sugar')) ?><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" name="sugar_g" placeholder="12"></label><label><?= e(t('nutrition.sodium')) ?><input type="text" inputmode="numeric" pattern="[0-9]+" name="sodium_mg" placeholder="700"></label></div></details>
        </section>
        <label class="nutrition-photo-field"><span class="nutrition-photo-field-copy"><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><span><strong><?= e($nutritionUi['photo']) ?></strong><small><?= e($nutritionUi['photo_hint']) ?></small></span></span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        <label class="nutrition-editor-notes"><?= e(t('nutrition.notes')) ?><textarea name="notes" rows="3" maxlength="500" enterkeyhint="done" placeholder="<?= e($nutritionUi['notes_hint']) ?>"></textarea></label>
        <p class="nutrition-photo-sharing-hint muted small"><?= e($nutritionUi['shared_photo_hint']) ?></p>
        <footer class="nutrition-editor-save"><button class="btn btn-primary btn-block" type="submit"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><span><?= e($nutritionUi['save_meal']) ?></span></button></footer>
    </form>
</dialog>

<dialog class="app-dialog nutrition-dialog nutrition-tdee-dialog" data-tdee-dialog>
    <form method="post" class="stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_tdee_profile">
        <header><div><p class="eyebrow"><?= e($nutritionUi['energy_expenditure']) ?></p><h2><?= e($nutritionUi['configure_tdee']) ?></h2></div><button type="button" class="dialog-close" data-dialog-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></header>
        <div class="grid-inline two">
            <label><?= e($nutritionUi['birth_date']) ?><input type="date" name="birth_date" value="<?= e((string) ($currentUser['birth_date'] ?? '')) ?>"></label>
            <label><?= e($nutritionUi['formula_sex']) ?><select name="tdee_sex"><option value=""><?= e($nutritionUi['select']) ?></option><option value="female" <?= ($currentUser['tdee_sex'] ?? '') === 'female' ? 'selected' : '' ?>><?= e($nutritionUi['female']) ?></option><option value="male" <?= ($currentUser['tdee_sex'] ?? '') === 'male' ? 'selected' : '' ?>><?= e($nutritionUi['male']) ?></option></select></label>
            <label><?= e($nutritionUi['height_cm']) ?><input type="number" name="height_cm" min="80" max="260" step="0.1" value="<?= e((string) ($currentUser['height_cm'] ?? '')) ?>"></label>
            <label><?= e($nutritionUi['activity']) ?><select name="activity_level"><?php foreach (['sedentary', 'light', 'moderate', 'active', 'very_active'] as $key): ?><option value="<?= e($key) ?>" <?= ($currentUser['activity_level'] ?? 'moderate') === $key ? 'selected' : '' ?>><?= e($nutritionUi['activity_' . $key]) ?></option><?php endforeach; ?></select></label>
            <div class="span-two nutrition-tdee-weight">
                <span><?= e($nutritionUi['calculation_weight']) ?></span>
                <strong><?= ($nutritionCalculationWeight ?? null) !== null ? e(number_format((float) $nutritionCalculationWeight, 1, ',', '.')) . ' kg' : e($nutritionUi['no_weight']) ?></strong>
                <small><?= e($nutritionUi[($nutritionCalculationWeight ?? null) === null ? 'weight_missing_hint' : (!empty($nutritionWeightIsLatest) ? 'weight_latest_hint' : 'weight_target_hint')]) ?></small>
            </div>
            <label class="span-two"><?= e($nutritionUi['manual_tdee']) ?> <em>(<?= e($nutritionUi['optional']) ?>)</em><input type="number" name="tdee_override" min="500" max="10000" step="1" value="<?= e((string) ($currentUser['tdee_override'] ?? '')) ?>" placeholder="<?= e($nutritionUi['tdee_example']) ?>"><small><?= e($nutritionUi['manual_tdee_hint']) ?></small></label>
        </div>
        <button class="btn btn-primary btn-block" type="submit"><?= e($nutritionUi['save_calculation']) ?></button>
    </form>
</dialog>

<script>
(() => {
    const root = document.querySelector('[data-nutrition-page]');
    if (!(root instanceof HTMLElement) || root.dataset.liveActionsReady === '1') return;
    root.dataset.liveActionsReady = '1';
    const status = root.querySelector('[data-nutrition-live-status]');
    const list = root.querySelector('[data-nutrition-entry-list]');
    const empty = root.querySelector('[data-nutrition-history-empty]');
    const messageDone = <?= json_encode($nutritionUi['action_done'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const messageError = <?= json_encode($nutritionUi['action_error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const adjustCount = (name, delta) => {
        const node = root.querySelector(`[data-nutrition-history-count="${name}"]`);
        if (node) node.textContent = String(Math.max(0, Number.parseInt(node.textContent || '0', 10) + delta));
    };
    root.querySelectorAll('form[data-nutrition-row-action]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;
        form.addEventListener('submit', async (event) => {
            if (!window.fetch || !window.DOMParser) return;
            event.preventDefault();
            event.stopPropagation();
            const action = String(form.dataset.nutritionRowAction || '');
            const entryId = String(form.dataset.entryId || '');
            const wasArchived = form.dataset.entryArchived === '1';
            const submit = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button[type="submit"]');
            if (submit) { submit.disabled = true; submit.classList.add('is-busy'); }
            try {
                const payload = new FormData(form);
                // Live actions stay on Nutrition. The preserved origin is only
                // for the no-JS fallback; an archived/deleted feed post may no
                // longer exist and would turn an otherwise successful fetch
                // redirect into a misleading 404.
                payload.delete('return_to');
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST', body: payload, credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'fetch' },
                });
                const html = await response.text();
                const result = new DOMParser().parseFromString(html, 'text/html');
                const error = result.querySelector('.flash-error');
                if (!response.ok || error) throw new Error(error?.textContent?.trim() || messageError);
                root.querySelector(`[data-nutrition-entry-row="${CSS.escape(entryId)}"]`)?.remove();
                document.querySelectorAll(`[data-entry-modal-for="${CSS.escape(entryId)}"]`).forEach((modal) => modal.remove());
                if (action === 'archive') { adjustCount('active', -1); adjustCount('archived', 1); }
                else if (action === 'unarchive') { adjustCount('archived', -1); adjustCount('active', 1); }
                else { adjustCount(wasArchived ? 'archived' : 'active', -1); }
                if (empty && list && list.querySelector('[data-nutrition-entry-row]') === null) empty.hidden = false;
                if (status) { status.textContent = messageDone; status.hidden = false; }
            } catch (error) {
                if (submit) { submit.disabled = false; submit.classList.remove('is-busy'); }
                if (status) { status.textContent = error instanceof Error ? error.message : messageError; status.hidden = false; status.classList.add('is-error'); }
            }
        });
    });
    const autoModal = document.querySelector('[data-nutrition-entry-auto-open="1"]');
    if (autoModal instanceof HTMLElement) {
        let attempts = 0;
        const openAutoModal = () => {
            attempts += 1;
            if (window.AppOverlay && typeof window.AppOverlay.open === 'function') {
                window.AppOverlay.open(autoModal);
                return;
            }
            if (attempts < 20) window.setTimeout(openAutoModal, 25);
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', openAutoModal, { once: true });
        else openAutoModal();
    }
})();
</script>

<?php if ($hasNutritionData): ?>
<script src="/asset.php?file=vendor%2Fchart.umd.min.js&amp;v=4.4.3"></script>
<script>
new Chart(document.getElementById('nutritionChart'), {type:'bar',data:{labels:<?= json_encode(array_map(static fn(array $r): string => format_date_eu((string) $r['entry_date']), $series)) ?>,datasets:[
{label:<?= json_encode($nutritionUi['chart_consumed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,data:<?= json_encode(array_map(static fn(array $r): ?float => !empty($r['has_meal_data']) ? (float) $r['consumed'] : null, $series)) ?>,backgroundColor:'#f97316'},
{label:<?= json_encode($nutritionUi['chart_total_expenditure'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,data:<?= json_encode(array_map(static fn(array $r): ?float => !empty($r['has_meal_data']) && isset($r['tdee']) ? (float) $r['tdee'] + (float) ($r['exercise'] ?? 0) : null, $series)) ?>,backgroundColor:'#18a999'}
]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}},plugins:{legend:{position:'bottom'}}}});
</script>
<?php endif; ?>
