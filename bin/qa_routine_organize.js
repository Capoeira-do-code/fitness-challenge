/**
 * Browser QA for the accessible routine exercise organizer.
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
const BASE = value('base', 'http://127.0.0.1:8124').replace(/\/$/, '');
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
const routineNames = (page) => page.locator('.workouts-exercise-row .workouts-exercise-info strong').allTextContents()
    .then((names) => names.map((name) => name.trim()));
const organizerNames = (page) => page.locator('[data-routine-exercise-item] .workouts-routine-organizer-copy strong').allTextContents()
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

    const routineName = `QA Order ${Date.now()}`;
    let routineId = 0;

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => window.AppOverlay && typeof window.AppOverlay.open === 'function', null, { timeout: 3000 }).catch(() => {});
        ensure(
            await page.evaluate(() => Boolean(window.AppOverlay && typeof window.AppOverlay.open === 'function')),
            'el controlador global de modales esta disponible',
            pageErrors.join(' | ')
        );
        const modal = page.locator('#wk-new-routine-modal');
        await modal.evaluate((overlay) => window.AppOverlay.open(overlay));
        await modal.locator('input[name="name"]').fill(routineName);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            modal.locator('button[type="submit"]').click(),
        ]);
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));
        ensure(routineId > 0, 'crea una rutina desechable');

        const addedNames = [];
        for (const query of ['bench', 'running', 'plank']) {
            await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}&q=${encodeURIComponent(query)}`, { waitUntil: 'networkidle' });
            const card = page.locator('.workouts-library-card').first();
            ensure(await card.count() === 1, `encuentra ${query}`);
            addedNames.push((await card.locator('.workouts-library-copy h3').innerText()).trim());
            await card.locator('.workouts-library-add.is-contextual button').click();
            await page.waitForLoadState('networkidle');
        }

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const initialOrder = await routineNames(page);
        ensure(initialOrder.length === 3 && new Set(initialOrder).size === 3, 'la rutina contiene tres ejercicios distintos', initialOrder.join(' > '));
        const organizeLink = page.locator(`a[href*="routine_id=${routineId}"][href*="section=organize"]`);
        ensure(await organizeLink.count() === 1, 'el resumen ofrece Organizar junto a iniciar sesion');
        await organizeLink.click();
        await page.waitForLoadState('networkidle');

        const url = new URL(page.url());
        ensure(url.searchParams.get('section') === 'organize' && Number(url.searchParams.get('routine_id')) === routineId, 'Organizar usa una URL compartible');
        ensure(await page.locator('.workouts-routine-organizer').count() === 1, 'renderiza la subpantalla jerarquica');
        ensure((await page.locator('.workouts-hero > a').getAttribute('href')) === `/?page=workouts&routine_id=${routineId}`, 'Volver tiene un padre determinista');
        ensure(await page.locator('.bottom-nav .liquid-nav-item.active[href*="page=workouts"]').count() === 1, 'la barra inferior mantiene Entreno activo');
        ensure(JSON.stringify(await organizerNames(page)) === JSON.stringify(initialOrder), 'el organizador refleja el orden guardado');
        ensure(await page.locator('[data-routine-exercise-item]').count() === 3, 'muestra una fila compacta por ejercicio');
        ensure(await page.locator('[data-routine-exercise-item] .workouts-exercise-cover').count() === 3, 'cada fila conserva su portada o placeholder');

        const touchSizes = await page.locator('[data-routine-exercise-move]').evaluateAll((buttons) => buttons.map((button) => {
            const rect = button.getBoundingClientRect();
            return [rect.width, rect.height];
        }));
        ensure(touchSizes.every(([width, height]) => width >= 44 && height >= 44), 'controles tactiles de al menos 44 px', JSON.stringify(touchSizes));

        const movingName = initialOrder[2];
        const movingItem = page.locator('[data-routine-exercise-item]').filter({ hasText: movingName });
        const upButton = movingItem.locator('[data-routine-exercise-move="up"]');
        await upButton.click();
        const firstMoveOrder = await organizerNames(page);
        ensure(firstMoveOrder[1] === movingName, 'Subir actualiza la previsualizacion inmediatamente', firstMoveOrder.join(' > '));
        ensure(await upButton.evaluate((button) => document.activeElement === button), 'el control conserva el foco al mover una fila');
        await upButton.click();
        const organizedOrder = await organizerNames(page);
        ensure(organizedOrder[0] === movingName, 'permite mover el ultimo ejercicio al inicio', organizedOrder.join(' > '));
        ensure(await movingItem.evaluate((item) => item.contains(document.activeElement)), 'el foco permanece dentro del ejercicio movido en el limite');
        ensure((await page.locator('[data-routine-exercise-status]').textContent()).includes(movingName), 'anuncia el cambio a lectores de pantalla');
        const visiblePositions = await page.locator('[data-routine-exercise-position]').allTextContents();
        ensure(visiblePositions.join(',') === '1,2,3', 'renumera posiciones sin recargar', visiblePositions.join(','));

        await page.evaluate(() => {
            document.body.dataset.theme = 'light';
            document.body.classList.remove('theme-active-dark');
        });
        await page.waitForTimeout(350);
        const lightSurface = await page.locator('.workouts-routine-organizer-item').first().evaluate((node) => getComputedStyle(node).backgroundColor);
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-organize-mobile-light.png'), fullPage: true });
        await page.evaluate(() => {
            document.body.dataset.theme = 'dark';
            document.body.classList.add('theme-active-dark');
        });
        await page.waitForTimeout(350);
        const darkSurface = await page.locator('.workouts-routine-organizer-item').first().evaluate((node) => getComputedStyle(node).backgroundColor);
        ensure(lightSurface !== darkSurface, 'el organizador conserva ambos temas', `${lightSurface} / ${darkSurface}`);
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-organize-mobile-dark.png'), fullPage: true });

        const form = page.locator('[data-routine-exercise-organizer]');
        await Promise.all([
            page.waitForURL((nextUrl) => Number(nextUrl.searchParams.get('routine_id')) === routineId && !nextUrl.searchParams.has('section')),
            form.locator('button[type="submit"]').click(),
        ]);
        ensure(JSON.stringify(await routineNames(page)) === JSON.stringify(organizedOrder), 'guardar aplica el orden al resumen');
        await page.reload({ waitUntil: 'networkidle' });
        ensure(JSON.stringify(await routineNames(page)) === JSON.stringify(organizedOrder), 'el orden persiste tras recargar');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-organized-summary-mobile.png'), fullPage: true });

        const unauthorizedContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const unauthorizedPage = await unauthorizedContext.newPage();
        await login(unauthorizedPage, 'catalina');
        await unauthorizedPage.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=organize`, { waitUntil: 'networkidle' });
        ensure(await unauthorizedPage.locator('.workouts-routine-organizer').count() === 0, 'otra persona no puede abrir el organizador');
        const csrf = await unauthorizedPage.locator('input[name="csrf_token"]').first().inputValue();
        const storedIds = await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=organize`, { waitUntil: 'networkidle' })
            .then(() => page.locator('input[name="order[]"]').evaluateAll((inputs) => inputs.map((input) => input.value)));
        const unauthorizedPost = await unauthorizedPage.evaluate(async ({ routineId: targetRoutine, csrfToken, ids }) => {
            const body = new URLSearchParams({
                csrf_token: csrfToken,
                action: 'routine_exercises_reorder',
                routine_id: String(targetRoutine),
            });
            ids.slice().reverse().forEach((id) => body.append('order[]', id));
            const response = await fetch('/?page=workouts', { method: 'POST', body });
            return response.status;
        }, { routineId, csrfToken: csrf, ids: storedIds });
        ensure(unauthorizedPost === 200, 'la peticion ajena se rechaza sin error de servidor', String(unauthorizedPost));
        await unauthorizedContext.close();
        await page.reload({ waitUntil: 'networkidle' });
        ensure(JSON.stringify(await organizerNames(page)) === JSON.stringify(organizedOrder), 'una peticion ajena no altera el orden');

        const overflowFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const target of [
                `/?page=workouts&routine_id=${routineId}`,
                `/?page=workouts&routine_id=${routineId}&section=organize`,
            ]) {
                await page.goto(`${BASE}${target}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) overflowFailures.push(`${width}:${target}:${excess}`);
            }
        }
        check('organizador sin overflow entre 320 y 1024 px', overflowFailures.length === 0, overflowFailures.join(', '));
        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de organizacion de rutina', false, error.stack || error.message);
    } finally {
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
