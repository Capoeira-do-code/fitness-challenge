/**
 * Browser QA for contextual exercise media inside a routine and live session.
 * Run only against a disposable database.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8121').replace(/\/$/, '');
const PASSWORD = value('password', 'Verify123!');
const reportDir = path.join(__dirname, '..', 'e2e-report');
const checks = [];

const check = (name, pass, detail = '') => {
    checks.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(name, condition, detail);
    if (!condition) throw new Error(`${name}${detail ? `: ${detail}` : ''}`);
};
const login = async (page, username = 'roberto') => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
};
const horizontalOverflow = (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

(async () => {
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('dialog', (dialog) => dialog.accept());
    const pageErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('console', (message) => {
        const source = message.location().url || '';
        if (message.type() === 'error' && source.startsWith(BASE)) pageErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    const stamp = Date.now();
    const routineName = `QA Media ${stamp}`;
    const personalName = `Press vídeo ${stamp}`;
    let routineId = 0;
    let routineExerciseId = 0;
    let sessionId = 0;
    let personalExerciseId = 0;

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.locator('[data-app-modal-open="wk-new-routine-modal"]').first().click();
        const modal = page.locator('#wk-new-routine-modal');
        await modal.locator('input[name="name"]').fill(routineName);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            modal.locator('button[type="submit"]').click(),
        ]);
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));
        ensure(routineId > 0, 'crea una rutina desechable');

        await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}&q=bench`, { waitUntil: 'networkidle' });
        const benchCard = page.locator('.workouts-library-card:not(.is-personal)').first();
        ensure(await benchCard.count() === 1, 'encuentra un ejercicio del catálogo');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            benchCard.locator('.workouts-library-add.is-contextual button').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const originalRow = page.locator('.workouts-exercise-row').first();
        const settingsHref = await originalRow.locator('.workouts-routine-exercise-edit').getAttribute('href');
        routineExerciseId = Number(new URL(settingsHref, BASE).searchParams.get('routine_exercise_id'));
        ensure(routineExerciseId > 0, 'la fila conserva una identidad contextual');

        await page.goto(`${BASE}${settingsHref}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-exercise-appearance').count() === 1, 'ajustes muestran la apariencia del ejercicio');
        ensure(await page.locator('form input[name="action"][value="routine_exercise_personalize"]').count() === 1, 'el catálogo ofrece una acción directa para hacerlo propio');
        const targetForm = page.locator('.workouts-routine-exercise-form');
        await targetForm.locator('input[name="target_sets"]').fill('4');
        await targetForm.locator('input[name="target_reps"]').fill('8');
        await targetForm.locator('input[name="target_weight"]').fill('42');
        await targetForm.locator('select[name="rest_seconds"]').selectOption('90');
        await targetForm.locator('textarea[name="notes"]').fill('Mantén la trayectoria estable');
        await Promise.all([
            page.waitForURL((url) => !url.searchParams.has('routine_exercise_id')),
            targetForm.locator('button[type="submit"]').click(),
        ]);

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&routine_exercise_id=${routineExerciseId}`, { waitUntil: 'networkidle' });
        const personalizeForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="routine_exercise_personalize"]') });
        await Promise.all([
            page.waitForURL((url) => url.searchParams.has('custom_exercise')),
            personalizeForm.locator('button[type="submit"]').click(),
        ]);
        const editorUrl = new URL(page.url());
        personalExerciseId = Number(editorUrl.searchParams.get('custom_exercise'));
        ensure(Number(editorUrl.searchParams.get('target_routine_id')) === routineId, 'el editor conserva la rutina');
        ensure(Number(editorUrl.searchParams.get('target_routine_exercise_id')) === routineExerciseId, 'el editor conserva la fila que debe sustituir');
        ensure(await page.locator('input[name="target_routine_exercise_id"]').inputValue() === String(routineExerciseId), 'el contexto seguro viaja dentro del formulario');
        ensure((await page.locator('.workouts-custom-header').innerText()).includes(routineName), 'la cabecera explica dónde se aplicará el cambio');
        ensure(await page.locator('[data-workout-editor-step="media"]:visible').count() === 1 && await page.locator('[data-workout-editor-step="basics"]:visible').count() === 0, 'Editar multimedia abre directamente su sección móvil');

        const customForm = page.locator('form.workouts-custom-editor');
        await customForm.locator('[data-workout-editor-step-trigger="basics"]').click();
        await customForm.locator('input[name="name"]').fill(personalName);
        await customForm.locator('[data-workout-editor-step-trigger="media"]').click();
        await customForm.locator('[data-workout-video-details] summary').click();
        await customForm.locator('input[name="video_url"]').fill('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        await customForm.locator('input[name="cover_mode"][value="video"] + span').click();
        await page.waitForTimeout(250);
        ensure(await page.locator('[data-workout-video-preview] iframe').count() === 1, 'YouTube ofrece previsualización antes de guardar');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-context-media-editor-mobile.png'), fullPage: true });
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_exercise_id')) === routineExerciseId),
            customForm.locator('button[type="submit"]').click(),
        ]);

        ensure(await page.locator('.workouts-exercise-appearance img[src*="ytimg.com"]').count() === 1, 'la miniatura de vídeo vuelve a los ajustes de la rutina');
        ensure(await page.locator('.workouts-exercise-appearance a', { hasText: /Edit media|Editar multimedia|Modifica contenuti/i }).count() === 1, 'la copia personal se puede editar sin repetir la clonación');
        ensure(await page.locator('form input[name="action"][value="routine_exercise_personalize"]').count() === 0, 'la acción de catálogo desaparece tras personalizar');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-context-media-settings-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const personalizedRows = page.locator('.workouts-exercise-row');
        ensure(await personalizedRows.count() === 1, 'personalizar sustituye en vez de duplicar');
        const rowText = await personalizedRows.first().innerText();
        ensure(rowText.includes(personalName), 'la rutina usa el nombre personal');
        ensure(rowText.includes('4×8') && rowText.includes('42kg') && rowText.includes('90'), 'objetivos y descanso sobreviven a la sustitución', rowText.replace(/\s+/g, ' '));
        ensure(rowText.includes('Mantén la trayectoria'), 'las notas sobreviven a la sustitución');
        ensure(await personalizedRows.locator('.workouts-exercise-cover img[src*="ytimg.com"]').count() === 1, 'la portada personalizada aparece en el resumen');
        ensure(await personalizedRows.locator('.workouts-exercise-cover b').count() === 1, 'la portada identifica que existe un vídeo');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-context-media-summary-mobile.png'), fullPage: true });

        const unauthorized = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const unauthorizedPage = await unauthorized.newPage();
        await login(unauthorizedPage, 'catalina');
        await unauthorizedPage.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${editorUrl.searchParams.get('custom_exercise')}&target_routine_id=${routineId}&target_routine_exercise_id=${routineExerciseId}`, { waitUntil: 'networkidle' });
        ensure(await unauthorizedPage.locator('form.workouts-custom-editor').count() === 0, 'otra persona no puede abrir la copia contextual');
        await unauthorized.close();

        const overflowFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [
                `/?page=workouts&routine_id=${routineId}`,
                `/?page=workouts&routine_id=${routineId}&routine_exercise_id=${routineExerciseId}`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await horizontalOverflow(page);
                if (excess > 1) overflowFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('apariencia contextual sin overflow entre 320 y 1024 px', overflowFailures.length === 0, overflowFailures.join(', '));

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const startForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).first();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) > 0),
            startForm.locator('button[type="submit"]').click(),
        ]);
        sessionId = Number(new URL(page.url()).searchParams.get('session_id'));
        const sessionExercise = page.locator('.workouts-session-exercise');
        ensure(await sessionExercise.count() === 1, 'la sesión mantiene un solo ejercicio');
        ensure(await sessionExercise.locator('.workouts-exercise-cover img[src*="ytimg.com"]').count() === 1, 'la portada personalizada llega a la sesión');
        ensure(await sessionExercise.locator('.workouts-set-row').count() === 4, 'la sesión conserva cuatro series');
        ensure(await sessionExercise.locator('input[name="weight"]').first().inputValue() === '42' && await sessionExercise.locator('input[name="reps"]').first().inputValue() === '8', 'la sesión conserva peso y repeticiones');
        await page.screenshot({ path: path.join(reportDir, 'ui-session-context-media-mobile.png'), fullPage: true });

        const sessionOverflow = await horizontalOverflow(page);
        check('sesión con portada personalizada no desborda', sessionOverflow <= 1, `${sessionOverflow}px`);
        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo contextual completo de multimedia', false, error.stack || error.message);
    } finally {
        if (!page.isClosed() && sessionId > 0) {
            try {
                await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
                if (await cancel.count()) await cancel.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        if (!page.isClosed() && routineId > 0) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings`, { waitUntil: 'networkidle', timeout: 5000 });
                const remove = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await remove.count()) await remove.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        if (!page.isClosed() && personalExerciseId > 0) {
            try {
                await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${personalExerciseId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const removeExercise = page.locator('.workouts-custom-danger').filter({ has: page.locator('input[name="action"][value="custom_exercise_delete"]') });
                if (await removeExercise.count()) await removeExercise.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
