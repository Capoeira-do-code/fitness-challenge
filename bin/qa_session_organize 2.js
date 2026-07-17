/**
 * Browser QA for editing the exercise sequence of an active workout.
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
const BASE = value('base', 'http://127.0.0.1:8126').replace(/\/$/, '');
const PASSWORD = value('password', 'Verify123!');
const reportDir = path.join(__dirname, '..', 'e2e-report');
const checks = [];

const check = (name, pass, detail = '') => {
    checks.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` -- ${detail}` : ''}`);
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
const overflow = (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);
const organizerNames = (page) => page.locator('[data-session-exercise-item] .workouts-routine-organizer-copy strong').allTextContents()
    .then((names) => names.map((name) => name.trim()));
const sessionNames = (page) => page.locator('.workouts-session-exercise .workouts-session-exercise-head strong').allTextContents()
    .then((names) => names.map((name) => name.trim()));

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

    const routineName = `QA Live order ${Date.now()}`;
    let routineId = 0;
    let sessionId = 0;
    let sessionFinished = false;

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const modal = page.locator('#wk-new-routine-modal');
        await modal.evaluate((overlay) => window.AppOverlay.open(overlay));
        await modal.locator('input[name="name"]').fill(routineName);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            modal.locator('button[type="submit"]').click(),
        ]);
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));
        ensure(routineId > 0, 'crea una rutina para la sesion');

        for (const query of ['bench', 'running', 'plank']) {
            await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}&q=${encodeURIComponent(query)}`, { waitUntil: 'networkidle' });
            const card = page.locator('.workouts-library-card').first();
            ensure(await card.count() === 1, `encuentra ${query}`);
            await card.locator('.workouts-library-add.is-contextual button').click();
            await page.waitForLoadState('networkidle');
        }

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const start = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') });
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) > 0),
            start.locator('button[type="submit"]').click(),
        ]);
        sessionId = Number(new URL(page.url()).searchParams.get('session_id'));
        const initialOrder = await sessionNames(page);
        ensure(sessionId > 0 && initialOrder.length === 3, 'inicia una sesion con tres ejercicios', initialOrder.join(' > '));
        ensure(await page.locator('.workouts-session-organize-link').count() === 1, 'la sesion ofrece Editar entreno');

        const firstSet = page.locator('.workouts-session-exercise.is-active .workouts-set-row').first();
        await firstSet.locator('button[name="completed"]').click();
        await page.waitForLoadState('networkidle');
        ensure(await page.locator('.workouts-session-exercise.is-active .workouts-set-row.is-done').count() === 1, 'registra una serie antes de editar');

        await page.locator('.workouts-session-organize-link').click();
        await page.waitForLoadState('networkidle');
        const organizeUrl = new URL(page.url());
        ensure(organizeUrl.searchParams.get('section') === 'organize' && Number(organizeUrl.searchParams.get('session_id')) === sessionId, 'Editar entreno usa una URL compartible');
        ensure(await page.locator('.workouts-session-organizer').count() === 1, 'renderiza una subpantalla compacta');
        ensure((await page.locator('.workouts-hero > a').getAttribute('href')) === `/?page=workouts&session_id=${sessionId}`, 'Volver apunta a la sesion padre');
        ensure(await page.locator('body.mobile-immersive-mode').count() === 1 && await page.locator('.bottom-nav:visible').count() === 0, 'mantiene el modo inmersivo movil');
        ensure(JSON.stringify(await organizerNames(page)) === JSON.stringify(initialOrder), 'el editor conserva la secuencia actual');

        const protectedItem = page.locator('[data-session-exercise-item]').filter({ hasText: initialOrder[0] });
        ensure(await protectedItem.locator('input[name="remove[]"]:disabled').count() === 1, 'un ejercicio con series completadas no se puede quitar');
        const removableCount = await page.locator('input[name="remove[]"]:not(:disabled)').count();
        ensure(removableCount === 2, 'los ejercicios pendientes siguen siendo editables');
        const controlSizes = await page.locator('[data-session-exercise-move], .workouts-session-organizer-remove').evaluateAll((controls) => controls.map((control) => {
            const rect = control.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        }));
        ensure(controlSizes.every(([width, height]) => width >= 44 && height >= 44), 'orden y borrado mantienen objetivos de 44 px', JSON.stringify(controlSizes));

        const movingName = initialOrder[2];
        const movingItem = page.locator('[data-session-exercise-item]').filter({ hasText: movingName });
        const moveUp = movingItem.locator('[data-session-exercise-move="up"]');
        await moveUp.click();
        await moveUp.click();
        const reordered = await organizerNames(page);
        ensure(reordered[0] === movingName, 'mueve el ultimo ejercicio al principio', reordered.join(' > '));
        ensure(await movingItem.evaluate((item) => item.contains(document.activeElement)), 'el foco permanece en el ejercicio movido');

        const removedName = initialOrder[1];
        const removedItem = page.locator('[data-session-exercise-item]').filter({ hasText: removedName });
        await removedItem.locator('.workouts-session-organizer-remove').click();
        ensure(await removedItem.locator('input[name="remove[]"]').isChecked(), 'marcar quitar es una accion explicita');
        ensure(await removedItem.evaluate((item) => item.classList.contains('is-marked-for-removal')), 'la fila previsualiza que se quitara');
        ensure((await page.locator('[data-session-exercise-status]').textContent()).includes(removedName), 'el cambio se anuncia a lectores de pantalla');
        await page.screenshot({ path: path.join(reportDir, 'ui-session-organize-mobile.png'), fullPage: true });

        const organizeForm = page.locator('[data-session-exercise-organizer]');
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) === sessionId && !url.searchParams.has('section')),
            organizeForm.locator('button[type="submit"]').click(),
        ]);
        const expectedOrder = [movingName, initialOrder[0]];
        ensure(JSON.stringify(await sessionNames(page)) === JSON.stringify(expectedOrder), 'guardar aplica orden y eliminacion', (await sessionNames(page)).join(' > '));
        ensure(await page.locator('.workouts-session-exercise').filter({ hasText: initialOrder[0] }).locator('.workouts-set-row.is-done').count() === 1, 'la serie completada sobrevive al cambio');
        ensure((await page.locator('.workouts-session-exercise.is-active .workouts-session-exercise-head strong').innerText()).trim() === movingName, 'el modo focalizado empieza por el nuevo primer ejercicio');
        await page.reload({ waitUntil: 'networkidle' });
        ensure(JSON.stringify(await sessionNames(page)) === JSON.stringify(expectedOrder), 'la sesion editada persiste al recargar');
        await page.screenshot({ path: path.join(reportDir, 'ui-session-organized-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}&section=organize`, { waitUntil: 'networkidle' });
        const storedIds = await page.locator('input[name="order[]"]').evaluateAll((inputs) => inputs.map((input) => input.value));
        const unauthorizedContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const unauthorizedPage = await unauthorizedContext.newPage();
        await login(unauthorizedPage, 'catalina');
        await unauthorizedPage.goto(`${BASE}/?page=workouts&session_id=${sessionId}&section=organize`, { waitUntil: 'networkidle' });
        ensure(await unauthorizedPage.locator('.workouts-session-organizer').count() === 0, 'otra persona no puede abrir el editor');
        const csrf = await unauthorizedPage.locator('input[name="csrf_token"]').first().inputValue();
        const unauthorizedStatus = await unauthorizedPage.evaluate(async ({ targetSession, csrfToken, ids }) => {
            const body = new URLSearchParams({
                csrf_token: csrfToken,
                action: 'session_exercises_organize',
                session_id: String(targetSession),
            });
            ids.slice().reverse().forEach((id) => body.append('order[]', id));
            body.append('remove[]', ids[0]);
            const response = await fetch('/?page=workouts', { method: 'POST', body });
            return response.status;
        }, { targetSession: sessionId, csrfToken: csrf, ids: storedIds });
        ensure(unauthorizedStatus === 200, 'el POST ajeno se rechaza sin provocar 5xx', String(unauthorizedStatus));
        await unauthorizedContext.close();
        await page.reload({ waitUntil: 'networkidle' });
        ensure(JSON.stringify(await organizerNames(page)) === JSON.stringify(expectedOrder), 'el POST ajeno no modifica la sesion');

        const overflowFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const target of [
                `/?page=workouts&session_id=${sessionId}`,
                `/?page=workouts&session_id=${sessionId}&section=organize`,
            ]) {
                await page.goto(`${BASE}${target}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) overflowFailures.push(`${width}:${target}:${excess}`);
            }
        }
        check('edicion de sesion sin overflow entre 320 y 1024 px', overflowFailures.length === 0, overflowFailures.join(', '));

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle' });
        const finish = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_finish"]') });
        await Promise.all([
            page.waitForURL((url) => !url.searchParams.has('session_id')),
            finish.locator('button[type="submit"]').click(),
        ]);
        sessionFinished = true;
        await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}&section=organize`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-session-organizer').count() === 0 && await page.locator('.workouts-session-organize-link').count() === 0, 'una sesion finalizada queda de solo lectura');
        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de edicion de sesion', false, error.stack || error.message);
    } finally {
        if (!sessionFinished && sessionId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
                if (await cancel.count()) await cancel.locator('button[type="submit"]').click({ timeout: 3000 });
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
