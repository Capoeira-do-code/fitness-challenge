<?php

declare(strict_types=1);

$series = array_values((array) ($nutritionSeries ?? []));
$entries = array_values((array) ($nutritionEntries ?? []));
$consumed = array_sum(array_map(static fn(array $row): float => (float) ($row['consumed'] ?? 0), $series));
$exercise = array_sum(array_map(static fn(array $row): float => (float) ($row['exercise'] ?? 0), $series));
$balances = array_values(array_filter(array_map(static fn(array $row): ?float => isset($row['balance']) ? (float) $row['balance'] : null, $series), static fn(?float $value): bool => $value !== null));
$balance = array_sum($balances);
$tdeeReady = is_array($nutritionTdee ?? null);
$hasNutritionData = count(array_filter($series, static fn(array $row): bool => !empty($row['has_meal_data']))) > 0;
?>
<section class="screen nutrition-page stack-lg">
    <header class="nutrition-hero">
        <div>
            <p class="eyebrow">Nutrition</p>
            <h1><?= e(t('dashboard.calories_consumed')) ?></h1>
            <p class="muted"><?= e(format_date_eu((string) $rangeStart)) ?> — <?= e(format_date_eu((string) $rangeEnd)) ?></p>
        </div>
        <button class="btn btn-primary" type="button" data-nutrition-open>+ <?= e(t('entries.quick_meal')) ?></button>
    </header>

    <?php if (!$tdeeReady): ?>
        <article class="panel nutrition-setup-callout">
            <span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span>
            <div><strong>Completa tu gasto energético</strong><p class="muted">Necesitamos tus datos para calcular un déficit realista. Hasta entonces no mostraremos un balance engañoso.</p></div>
            <button class="btn btn-ghost" type="button" data-tdee-open>Configurar</button>
        </article>
    <?php endif; ?>

    <div class="nutrition-kpi-grid">
        <article class="nutrition-kpi"><small>Consumidas</small><strong><?= e(number_format($consumed, 0, ',', '.')) ?></strong><span>kcal · 14 días</span></article>
        <article class="nutrition-kpi"><small>Ejercicio</small><strong><?= e(number_format($exercise, 0, ',', '.')) ?></strong><span>kcal registradas</span></article>
        <article class="nutrition-kpi <?= $balance <= 0 ? 'is-deficit' : 'is-surplus' ?>"><small><?= $balance <= 0 ? 'Déficit' : 'Superávit' ?></small><strong><?= $tdeeReady ? e(number_format(abs($balance), 0, ',', '.')) : '—' ?></strong><span><?= $tdeeReady ? 'kcal acumuladas' : 'Completa tu perfil' ?></span></article>
        <article class="nutrition-kpi"><small>TDEE diario</small><strong><?= $tdeeReady ? e(number_format((float) $nutritionTdee['value'], 0, ',', '.')) : '—' ?></strong><span><?= !empty($nutritionTdee['estimated']) ? 'Estimación Mifflin–St Jeor' : 'Valor manual' ?></span></article>
    </div>

    <article class="panel nutrition-chart-panel">
        <div class="panel-head"><div><p class="eyebrow">Balance diario</p><h2>Consumo y gasto</h2></div><button class="btn btn-ghost small" type="button" data-tdee-open>Ajustar TDEE</button></div>
        <?php if (!$hasNutritionData): ?>
            <div class="empty-state"><p>Registra tu primera comida para ver la tendencia.</p></div>
        <?php else: ?>
            <div class="metric-chart-wrap"><canvas id="nutritionChart" aria-label="Histórico de calorías"></canvas></div>
        <?php endif; ?>
    </article>

    <article class="panel nutrition-recent">
        <div class="panel-head"><div><p class="eyebrow">Historial</p><h2>Comidas recientes</h2></div><span class="pill"><?= count($entries) ?></span></div>
        <?php if ($entries === []): ?>
            <div class="empty-state"><p>No hay comidas en este periodo.</p></div>
        <?php else: ?>
            <div class="nutrition-entry-list">
                <?php foreach ($entries as $entry): ?>
                    <div class="nutrition-entry-row">
                        <span class="nutrition-meal-icon" aria-hidden="true"><?= activity_icon_svg('flame') ?></span>
                        <span><strong><?= e(ucfirst((string) $entry['meal_type'])) ?></strong><small><?= e(format_date_eu((string) $entry['entry_date'])) ?> · <?= e((string) ($entry['entry_time'] ?? '')) ?><?= trim((string) ($entry['notes'] ?? '')) !== '' ? ' · ' . e((string) $entry['notes']) : '' ?></small></span>
                        <strong><?= e(number_format((float) $entry['calories'], 0, ',', '.')) ?> <small>kcal</small></strong>
                        <?php if (trim((string) ($entry['photo_path'] ?? '')) !== ''): ?><span class="nutrition-photo-badge"><?= activity_icon_svg('image') ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<dialog class="app-dialog nutrition-dialog" data-nutrition-dialog<?= !empty($nutritionAutoOpen) ? ' data-auto-open="1"' : '' ?>>
    <form method="post" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_nutrition_entry">
        <?php if (($nutritionReturnContext ?? '') === 'gallery'): ?><input type="hidden" name="return_to" value="gallery"><?php endif; ?>
        <header><div><p class="eyebrow">Nueva comida</p><h2>Registrar comida</h2><small class="muted">Añade las kcal y completa los nutrientes que conozcas.</small></div><button type="button" class="dialog-close" data-dialog-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></header>
        <div class="grid-inline two nutrition-meal-basics">
            <label>Fecha<input type="date" name="entry_date" value="<?= e((string) ($rangeEnd ?? to_date(null))) ?>" required></label>
            <label>Tipo<select name="meal_type"><option value="breakfast">Desayuno</option><option value="lunch">Comida</option><option value="dinner">Cena</option><option value="snack">Snack</option><option value="other">Otro</option></select></label>
            <label class="span-two nutrition-calorie-field"><span>Calorías</span><span class="nutrition-input-unit"><input type="number" name="calories" min="0" step="1" inputmode="numeric" placeholder="650" required><strong>kcal</strong></span></label>
        </div>
        <details class="nutrition-advanced-fields">
            <summary><span><?= activity_icon_svg('sliders') ?><strong>Avanzado</strong><small>Macros y otros nutrientes</small></span><b aria-hidden="true">›</b></summary>
            <div class="grid-inline two">
                <label>Hora<input type="time" name="entry_time" value="<?= e((new DateTimeImmutable())->format('H:i')) ?>"></label>
                <label>Proteína <span class="muted">(g)</span><input type="number" name="protein_g" min="0" step="0.1" inputmode="decimal" placeholder="35"></label>
                <label>Carbohidratos <span class="muted">(g)</span><input type="number" name="carbs_g" min="0" step="0.1" inputmode="decimal" placeholder="60"></label>
                <label>Grasa <span class="muted">(g)</span><input type="number" name="fat_g" min="0" step="0.1" inputmode="decimal" placeholder="22"></label>
                <label>Fibra <span class="muted">(g)</span><input type="number" name="fiber_g" min="0" step="0.1" inputmode="decimal" placeholder="8"></label>
                <label>Azúcar <span class="muted">(g)</span><input type="number" name="sugar_g" min="0" step="0.1" inputmode="decimal" placeholder="12"></label>
                <label class="span-two">Sodio <span class="muted">(mg)</span><input type="number" name="sodium_mg" min="0" step="1" inputmode="numeric" placeholder="700"></label>
            </div>
        </details>
        <label class="nutrition-photo-field">Foto <em>(opcional)</em><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        <label>Notas<textarea name="notes" rows="2" maxlength="500"></textarea></label>
        <p class="muted small">La comida solo se compartirá en la galería si añades una foto.</p>
        <button class="btn btn-primary btn-block" type="submit">Guardar comida</button>
    </form>
</dialog>

<dialog class="app-dialog nutrition-dialog" data-tdee-dialog>
    <form method="post" class="stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_tdee_profile">
        <header><div><p class="eyebrow">Gasto energético</p><h2>Configurar TDEE</h2></div><button type="button" class="dialog-close" data-dialog-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></header>
        <div class="grid-inline two">
            <label>Fecha de nacimiento<input type="date" name="birth_date" value="<?= e((string) ($currentUser['birth_date'] ?? '')) ?>"></label>
            <label>Sexo usado por la fórmula<select name="tdee_sex"><option value="">Selecciona</option><option value="female" <?= ($currentUser['tdee_sex'] ?? '') === 'female' ? 'selected' : '' ?>>Femenino</option><option value="male" <?= ($currentUser['tdee_sex'] ?? '') === 'male' ? 'selected' : '' ?>>Masculino</option></select></label>
            <label>Altura (cm)<input type="number" name="height_cm" min="80" max="260" step="0.1" value="<?= e((string) ($currentUser['height_cm'] ?? '')) ?>"></label>
            <label>Actividad<select name="activity_level"><?php foreach (['sedentary' => 'Sedentaria', 'light' => 'Ligera', 'moderate' => 'Moderada', 'active' => 'Activa', 'very_active' => 'Muy activa'] as $key => $label): ?><option value="<?= e($key) ?>" <?= ($currentUser['activity_level'] ?? 'moderate') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <div class="span-two nutrition-tdee-weight">
                <span>Peso usado en el cálculo</span>
                <strong><?= ($nutritionCalculationWeight ?? null) !== null ? e(number_format((float) $nutritionCalculationWeight, 1, ',', '.')) . ' kg' : 'Sin peso registrado' ?></strong>
                <small><?php if (($nutritionCalculationWeight ?? null) === null): ?>Registra tu peso en Daily Log para poder calcular la estimación.<?php elseif (!empty($nutritionWeightIsLatest)): ?>Usamos tu último peso registrado en Daily Log.<?php else: ?>Usamos el peso objetivo de tu perfil; registra tu peso actual para mejorar la estimación.<?php endif; ?></small>
            </div>
            <label class="span-two">TDEE manual <em>(opcional)</em><input type="number" name="tdee_override" min="500" max="10000" step="1" value="<?= e((string) ($currentUser['tdee_override'] ?? '')) ?>" placeholder="Ej. 2350"><small>Úsalo solo si ya conoces tus calorías de mantenimiento. Este valor sustituye el cálculo automático.</small></label>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Guardar cálculo</button>
    </form>
</dialog>

<?php if ($hasNutritionData): ?>
<script src="/asset.php?file=vendor%2Fchart.umd.min.js&amp;v=4.4.3"></script>
<script>
new Chart(document.getElementById('nutritionChart'), {type:'bar',data:{labels:<?= json_encode(array_map(static fn(array $r): string => format_date_eu((string) $r['entry_date']), $series)) ?>,datasets:[
{label:'Consumidas',data:<?= json_encode(array_map(static fn(array $r): ?float => !empty($r['has_meal_data']) ? (float) $r['consumed'] : null, $series)) ?>,backgroundColor:'#f97316'},
{label:'Gasto total',data:<?= json_encode(array_map(static fn(array $r): ?float => !empty($r['has_meal_data']) && isset($r['tdee']) ? (float) $r['tdee'] + (float) ($r['exercise'] ?? 0) : null, $series)) ?>,backgroundColor:'#18a999'}
]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}},plugins:{legend:{position:'bottom'}}}});
</script>
<?php endif; ?>
