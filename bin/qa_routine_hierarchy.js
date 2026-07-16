/**
 * Browser QA for the hierarchical routine → settings/library flow.
 * Run only against a disposable QA database.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8113').replace(/\/$/, '');
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
const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', 'roberto');
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
};
const overflow = async (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

(async () => {
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
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
    page.on('dialog', (dialog) => dialog.accept());

    const routineName = `QA Flujo simple ${Date.now()}`;
    const customExerciseName = `QA Movimiento ${Date.now()}`;
    let routineId = 0;
    let customExerciseId = 0;

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.locator('[data-app-modal-open="wk-new-routine-modal"]').first().click();
        const modal = page.locator('#wk-new-routine-modal');
        await modal.locator('input[name="name"]').fill(routineName);
        await modal.locator('input[name="description"]').fill('Rutina de prueba para el flujo jerárquico');
        await modal.locator('input[name="days[]"][value="wed"] + span').click();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            modal.locator('button[type="submit"]').click(),
        ]);
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));

        ensure(await page.locator('.workouts-routine-summary').count() === 1, 'la raíz de rutina muestra un resumen compacto');
        ensure(await page.locator('.workouts-routine-form').count() === 0, 'la raíz no mezcla el formulario de ajustes');
        ensure(await page.locator('.workouts-add-exercise, .workouts-custom-exercise').count() === 0, 'la raíz elimina los formularios largos de ejercicios');
        const flash = page.locator('.container-with-nav > .flash');
        if (await flash.count()) {
            const [topbarBox, flashBox, heroBox] = await Promise.all([
                page.locator('.topbar').boundingBox(),
                flash.boundingBox(),
                page.locator('.workouts-hero').boundingBox(),
            ]);
            ensure(
                topbarBox && flashBox && heroBox
                    && flashBox.y >= topbarBox.y + topbarBox.height - 1
                    && flashBox.y + flashBox.height <= heroBox.y + 1,
                'el aviso móvil ocupa su propia fila sin tapar navegación ni contenido'
            );
        }
        const addHref = await page.locator('.workouts-routine-summary-actions a').filter({ hasText: /Add|Añadir|Aggiungi/i }).getAttribute('href');
        ensure((addHref || '').includes(`target_routine_id=${routineId}`), 'añadir ejercicios conserva la rutina destino');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-overview-mobile.png'), fullPage: true });

        await page.locator(`a[href*="routine_id=${routineId}"][href*="section=settings"]`).click();
        await page.waitForLoadState('networkidle');
        ensure(new URL(page.url()).searchParams.get('section') === 'settings', 'ajustes usa una URL compartible');
        ensure(new URL(page.url()).searchParams.get('settings_view') === null, 'identidad es la sección inicial compatible');
        ensure(await page.locator('.workouts-routine-settings-nav a').count() === 4, 'ajustes ofrece cuatro subpantallas claras');
        const settingsTargets = await page.locator('.workouts-routine-settings-nav a').evaluateAll((nodes) => nodes.map((node) => {
            const rect = node.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        }));
        ensure(settingsTargets.every(([width, height]) => width >= 44 && height >= 44), 'submenús mantienen objetivos táctiles amplios', JSON.stringify(settingsTargets));
        ensure(await page.locator('.workouts-routine-form input[name="settings_view"][value="identity"]').count() === 1, 'identidad carga solo su formulario');
        ensure(await page.locator('.workouts-routine-settings-content .workouts-routine-media-settings, .workouts-routine-settings-content .workouts-day-picker, .workouts-routine-settings-content .workouts-routine-danger').count() === 0, 'identidad no mezcla portada, calendario ni gestión');
        ensure(await page.locator('.workouts-routine-exercises-panel').count() === 0, 'ajustes no duplica el contenido de entrenamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-settings-identity-mobile.png'), fullPage: true });

        await page.locator('.workouts-routine-settings-nav a[href*="settings_view=media"]').click();
        await page.waitForLoadState('networkidle');
        ensure(new URL(page.url()).searchParams.get('settings_view') === 'media', 'portada usa su propia URL');
        ensure(await page.locator('.workouts-routine-media-settings').count() === 1, 'portada carga el editor multimedia');
        ensure(await page.locator('.workouts-routine-settings-content .workouts-routine-form input[name="name"], .workouts-routine-settings-content .workouts-day-picker').count() === 0, 'portada no repite identidad ni calendario');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-settings-media-mobile.png'), fullPage: true });

        await page.locator('.workouts-routine-settings-nav a[href*="settings_view=schedule"]').click();
        await page.waitForLoadState('networkidle');
        ensure(await page.locator('.workouts-routine-settings-content .workouts-day-picker').count() === 1, 'calendario vive en una subpantalla compacta');
        ensure(await page.locator('.workouts-routine-settings-content .workouts-routine-media-settings, .workouts-routine-settings-content .workouts-routine-identity-picker').count() === 0, 'calendario no carga editores secundarios');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-settings-schedule-mobile.png'), fullPage: true });

        await page.locator('.workouts-routine-settings-nav a[href*="settings_view=management"]').click();
        await page.waitForLoadState('networkidle');
        ensure(await page.locator('.workouts-routine-danger').count() === 1, 'acciones destructivas viven en Gestión');
        ensure(await page.locator('.workouts-routine-form').count() === 0, 'Gestión no mezcla formularios de edición');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-settings-management-mobile.png'), fullPage: true });

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=identity`, { waitUntil: 'networkidle' });
        const desktopColumns = await page.locator('.workouts-routine-settings-shell').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length);
        ensure(desktopColumns === 2, 'escritorio usa navegación lateral y editor en dos columnas');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-settings-identity-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });

        await page.goto(`${BASE}${addHref}`, { waitUntil: 'networkidle' });
        ensure(Number(new URL(page.url()).searchParams.get('target_routine_id')) === routineId, 'biblioteca abre en contexto de rutina');
        ensure(await page.locator('.workouts-library-target').count() === 1, 'biblioteca explica claramente el destino');
        ensure(await page.locator('.workouts-library-card').count() <= 12, 'biblioteca contextual mantiene el límite de doce resultados');
        ensure(await page.locator('.workouts-library-add select[name="routine_id"]').count() === 0, 'biblioteca contextual elimina selectores repetidos');
        ensure(await page.locator('.workouts-library-add.is-contextual').count() > 0, 'cada ejercicio ofrece una acción directa');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-library-context-mobile.png'), fullPage: true });

        const firstCard = page.locator('.workouts-library-card').first();
        const exerciseName = (await firstCard.locator('h3').innerText()).trim();
        const guideHref = await firstCard.locator('h3 a').getAttribute('href');
        ensure((guideHref || '').includes(`target_routine_id=${routineId}`), 'la guía conserva el contexto de la rutina');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            firstCard.locator('.workouts-library-add.is-contextual button').click(),
        ]);
        ensure(Number(new URL(page.url()).searchParams.get('target_routine_id')) === routineId, 'añadir mantiene filtros y contexto');
        const addedCard = page.locator('.workouts-library-card').filter({ hasText: exerciseName }).first();
        ensure(await addedCard.locator('.workouts-library-add.is-contextual button:disabled').count() === 1, 'el ejercicio añadido cambia a estado completado');

        const filter = page.locator('.workouts-library-mobile-search');
        await filter.locator('input[name="q"]').fill('press');
        await Promise.all([page.waitForLoadState('networkidle'), filter.locator('button[type="submit"]').click()]);
        ensure(Number(new URL(page.url()).searchParams.get('target_routine_id')) === routineId, 'buscar no pierde la rutina destino');

        await page.goto(`${BASE}${guideHref}`, { waitUntil: 'networkidle' });
        const guideBack = await page.locator('.workouts-hero > a').getAttribute('href');
        ensure((guideBack || '').includes(`target_routine_id=${routineId}`), 'volver desde la guía regresa a la biblioteca contextual');

        await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const customHref = await page.locator('.workouts-library-actions a').getAttribute('href');
        ensure((customHref || '').includes(`target_routine_id=${routineId}`), 'crear ejercicio personalizado conserva el destino');
        await page.goto(`${BASE}${customHref}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('form.workouts-custom-editor input[name="target_routine_id"]').inputValue() === String(routineId), 'editor personalizado recibe la rutina destino');
        const customForm = page.locator('form.workouts-custom-editor');
        await customForm.locator('input[name="name"]').fill(customExerciseName);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) === routineId),
            customForm.locator('button[type="submit"]').click(),
        ]);
        const customRow = page.locator('.workouts-exercise-row').filter({ hasText: customExerciseName }).first();
        ensure(await customRow.count() === 1, 'crear un ejercicio desde el flujo lo añade directamente');
        const customGuideHref = await customRow.locator('a[href*="?page=workouts&exercise_id="]').getAttribute('href');
        customExerciseId = Number(new URL(customGuideHref, BASE).searchParams.get('exercise_id'));
        ensure(customExerciseId > 0, 'el ejercicio contextual conserva una guía propia', `#${customExerciseId}`);

        await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}`, { waitUntil: 'networkidle' });
        await page.locator('.workouts-library-target .btn').click();
        await page.waitForLoadState('networkidle');
        ensure(Number(new URL(page.url()).searchParams.get('routine_id')) === routineId, 'Listo vuelve al resumen de la rutina');
        ensure(await page.locator('.workouts-exercise-row').filter({ hasText: exerciseName }).count() === 1, 'el resumen refleja el ejercicio añadido');

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [
                `/?page=workouts&routine_id=${routineId}`,
                `/?page=workouts&routine_id=${routineId}&section=settings&settings_view=identity`,
                `/?page=workouts&routine_id=${routineId}&section=settings&settings_view=media`,
                `/?page=workouts&routine_id=${routineId}&section=settings&settings_view=schedule`,
                `/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`,
                `/?page=workouts&view=library&target_routine_id=${routineId}`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('flujo jerárquico sin overflow entre 320 y 1440 px', responsiveFailures.length === 0, responsiveFailures.join(', '));
        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo jerárquico completo de rutina', false, error.stack || error.message);
    } finally {
        if (routineId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanup = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await cleanup.count()) await cleanup.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best-effort cleanup */ }
        }
        if (customExerciseId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${customExerciseId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanupExercise = page.locator('.workouts-custom-danger');
                if (await cleanupExercise.count()) await cleanupExercise.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best-effort cleanup */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
