/**
 * Browser QA for per-exercise routine targets and typed session fields.
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
const BASE = value('base', 'http://127.0.0.1:8119').replace(/\/$/, '');
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

    const routineName = `QA Objetivos ${Date.now()}`;
    let routineId = 0;
    let strengthTargetUrl = '';
    let cardioTargetUrl = '';
    let isometricTargetUrl = '';

    const addFromLibrary = async (query) => {
        await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}&q=${encodeURIComponent(query)}`, { waitUntil: 'networkidle' });
        const card = page.locator('.workouts-library-card').first();
        ensure(await card.count() === 1, `biblioteca encuentra ${query}`);
        const name = (await card.locator('h3').innerText()).trim();
        await Promise.all([
            page.waitForLoadState('networkidle'),
            card.locator('.workouts-library-add.is-contextual button').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const row = page.locator('.workouts-exercise-row').filter({ hasText: name }).first();
        ensure(await row.count() === 1, `${name} aparece en la rutina`);
        return { name, url: await row.locator('.workouts-routine-exercise-edit').getAttribute('href') };
    };

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

        const strength = await addFromLibrary('bench');
        strengthTargetUrl = strength.url || '';
        ensure(strengthTargetUrl.includes('routine_exercise_id='), 'cada ejercicio abre ajustes propios');
        ensure(await page.locator('form input[name="action"][value="routine_remove_exercise"]').count() === 0, 'el resumen evita borrado accidental');

        await page.goto(`${BASE}${strengthTargetUrl}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-routine-exercise-editor').count() === 1, 'objetivo de fuerza usa una subpantalla');
        ensure(await page.locator('input[name="target_reps"]').count() === 1, 'fuerza muestra repeticiones');
        ensure(await page.locator('input[name="target_duration_minutes"]').count() === 0, 'fuerza oculta métricas de cardio');
        const strengthForm = page.locator('.workouts-routine-exercise-form');
        await strengthForm.locator('input[name="target_sets"]').fill('4');
        await strengthForm.locator('input[name="target_reps"]').fill('8');
        await strengthForm.locator('input[name="target_weight"]').fill('65');
        await strengthForm.locator('select[name="unit"]').selectOption('kg');
        await strengthForm.locator('select[name="rest_seconds"]').selectOption('90');
        await strengthForm.locator('textarea[name="notes"]').fill('Pausa controlada y escápulas estables');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-strength-target-mobile.png'), fullPage: true });
        await Promise.all([page.waitForURL((url) => !url.searchParams.has('routine_exercise_id')), strengthForm.locator('button[type="submit"]').click()]);
        const strengthRow = page.locator('.workouts-exercise-row').filter({ hasText: strength.name }).first();
        const strengthSummary = await strengthRow.innerText();
        ensure(strengthSummary.includes('4×8') && strengthSummary.includes('65kg') && strengthSummary.includes('90'), 'resumen muestra carga, series y descanso', strengthSummary.replace(/\s+/g, ' '));
        ensure(strengthSummary.includes('Pausa controlada'), 'resumen muestra la nota personal');

        const cardio = await addFromLibrary('running');
        cardioTargetUrl = cardio.url || '';
        await page.goto(`${BASE}${cardioTargetUrl}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('input[name="target_duration_minutes"]').count() === 1, 'cardio muestra duración en minutos');
        ensure(await page.locator('input[name="target_distance"]').count() === 1, 'cardio muestra distancia');
        ensure(await page.locator('input[name="target_reps"]').count() === 0, 'cardio oculta repeticiones irrelevantes');
        const cardioForm = page.locator('.workouts-routine-exercise-form');
        await cardioForm.locator('input[name="target_sets"]').fill('2');
        await cardioForm.locator('input[name="target_duration_minutes"]').fill('25');
        await cardioForm.locator('input[name="target_distance"]').fill('5.25');
        await cardioForm.locator('select[name="rest_seconds"]').selectOption('60');
        await cardioForm.locator('textarea[name="notes"]').fill('Ritmo conversacional');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-cardio-target-mobile.png'), fullPage: true });
        await Promise.all([page.waitForURL((url) => !url.searchParams.has('routine_exercise_id')), cardioForm.locator('button[type="submit"]').click()]);
        const cardioRow = page.locator('.workouts-exercise-row').filter({ hasText: cardio.name }).first();
        const cardioSummary = await cardioRow.innerText();
        ensure(cardioSummary.includes('2×25') && cardioSummary.includes('5.25 km') && cardioSummary.includes('60'), 'resumen cardio muestra rondas, tiempo y distancia', cardioSummary.replace(/\s+/g, ' '));

        const isometric = await addFromLibrary('plank');
        isometricTargetUrl = isometric.url || '';
        await page.goto(`${BASE}${isometricTargetUrl}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('input[name="target_duration_seconds"]').count() === 1, 'isométrico muestra duración en segundos');
        ensure(await page.locator('input[name="target_reps"], input[name="target_duration_minutes"]').count() === 0, 'isométrico oculta repeticiones y minutos');
        const isometricForm = page.locator('.workouts-routine-exercise-form');
        await isometricForm.locator('input[name="target_sets"]').fill('3');
        await isometricForm.locator('input[name="target_duration_seconds"]').fill('45');
        await isometricForm.locator('select[name="rest_seconds"]').selectOption('60');
        await isometricForm.locator('textarea[name="notes"]').fill('Cadera neutra');
        await Promise.all([page.waitForURL((url) => !url.searchParams.has('routine_exercise_id')), isometricForm.locator('button[type="submit"]').click()]);
        const isometricSummary = await page.locator('.workouts-exercise-row').filter({ hasText: isometric.name }).first().innerText();
        ensure(isometricSummary.includes('3×45s'), 'resumen isométrico muestra series y segundos', isometricSummary.replace(/\s+/g, ' '));
        await page.waitForTimeout(350);
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-targets-summary-mobile.png'), fullPage: true });

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [strengthTargetUrl, cardioTargetUrl, isometricTargetUrl]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('editores de objetivos no desbordan entre 320 y 1024 px', responsiveFailures.length === 0, responsiveFailures.join(', '));

        const unauthorizedContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const unauthorizedPage = await unauthorizedContext.newPage();
        await login(unauthorizedPage, 'catalina');
        await unauthorizedPage.goto(`${BASE}${strengthTargetUrl}`, { waitUntil: 'networkidle' });
        ensure(await unauthorizedPage.locator('.workouts-routine-exercise-editor').count() === 0, 'otra persona no puede editar el objetivo');
        await unauthorizedContext.close();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const start = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).first();
        await Promise.all([page.waitForURL((url) => Number(url.searchParams.get('session_id')) > 0), start.locator('button[type="submit"]').click()]);
        const strengthSession = page.locator('.workouts-session-exercise[data-exercise-type="strength"]').first();
        const cardioSession = page.locator('.workouts-session-exercise[data-exercise-type="cardio"]').first();
        const isometricSession = page.locator('.workouts-session-exercise[data-exercise-type="isometric"]').first();
        ensure(await page.locator('body.mobile-immersive-mode').count() === 1 && await page.locator('.bottom-nav:visible').count() === 0, 'sesión móvil entra en modo inmersivo');
        ensure(await strengthSession.locator('.workouts-set-row').count() === 4, 'sesión crea cuatro series de fuerza');
        ensure(await strengthSession.locator('input[name="weight"]').first().inputValue() === '65' && await strengthSession.locator('input[name="reps"]').first().inputValue() === '8', 'sesión precarga carga y repeticiones');
        ensure((await strengthSession.locator('.workouts-session-prescription').innerText()).includes('Pausa controlada'), 'sesión muestra indicación y descanso');
        ensure(await cardioSession.locator('.workouts-set-row').count() === 2, 'sesión crea dos rondas de cardio');
        ensure(await cardioSession.locator('input[name="duration_minutes"]').first().inputValue() === '25' && await cardioSession.locator('input[name="distance"]').first().inputValue() === '5.25', 'sesión usa minutos y distancia para cardio');
        ensure(await cardioSession.locator('input[name="reps"], input[name="weight"]').count() === 0, 'cardio no muestra campos de fuerza');
        ensure(await isometricSession.locator('.workouts-set-row').count() === 3, 'sesión crea tres series isométricas');
        ensure(await isometricSession.locator('input[name="duration_seconds"]').first().inputValue() === '45', 'sesión precarga segundos para isométricos');
        ensure(await isometricSession.locator('input[name="reps"], input[name="duration_minutes"]').count() === 0, 'isométrico mantiene campos relevantes');

        const addSet = strengthSession.locator('form').filter({ has: page.locator('input[name="action"][value="session_add_set"]') });
        await Promise.all([page.waitForLoadState('networkidle'), addSet.locator('button[type="submit"]').click()]);
        const refreshedStrength = page.locator('.workouts-session-exercise[data-exercise-type="strength"]').first();
        ensure(await refreshedStrength.locator('.workouts-set-row').count() === 5, 'añadir serie conserva el flujo rápido');
        ensure(await refreshedStrength.locator('input[name="weight"]').last().inputValue() === '65' && await refreshedStrength.locator('input[name="reps"]').last().inputValue() === '8', 'nueva serie hereda el objetivo anterior');
        await page.screenshot({ path: path.join(reportDir, 'ui-session-mixed-targets-mobile.png'), fullPage: true });

        const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
        await Promise.all([page.waitForLoadState('networkidle'), cancel.locator('button[type="submit"]').click()]);

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de objetivos por ejercicio', false, error.stack || error.message);
    } finally {
        if (!page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle', timeout: 5000 });
                const resume = page.locator('.workouts-resume-banner');
                if (await resume.count()) {
                    await resume.click();
                    const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
                    if (await cancel.count()) await cancel.locator('button[type="submit"]').click({ timeout: 3000 });
                }
            } catch (_) { /* best effort */ }
        }
        if (routineId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanup = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await cleanup.count()) await cleanup.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
