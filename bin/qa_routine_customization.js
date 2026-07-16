/**
 * Browser QA for customizable routine icons and accent colors.
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

    const routineName = `QA Identidad ${Date.now()}`;
    let routineId = 0;

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.locator('[data-app-modal-open="wk-new-routine-modal"]').first().click();
        const modal = page.locator('#wk-new-routine-modal');
        ensure(await modal.isVisible(), 'crear rutina abre un selector jerárquico');
        ensure(await modal.locator('input[name="icon"]').count() === 8, 'selector ofrece ocho iconos');
        ensure(await modal.locator('input[name="accent_color_preset"]').count() === 8, 'selector conserva ocho colores rápidos');
        ensure(await modal.locator('input[name="accent_color"][type="color"]').count() === 1, 'selector añade un color libre');

        const targets = await modal.locator('.workouts-routine-icon-options label > span, .workouts-routine-color-options label > span, .workouts-routine-custom-color').evaluateAll((nodes) => nodes.map((node) => {
            const rect = node.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        }));
        check('opciones visuales mantienen objetivos táctiles de 44 px', targets.every(([width, height]) => width >= 44 && height >= 44), JSON.stringify(targets));
        ensure((await overflow(page)) <= 1, 'selector móvil sin desbordamiento');

        await modal.locator('input[name="name"]').fill(routineName);
        await modal.locator('input[name="description"]').fill('Fuerza y cardio personalizada');
        await modal.locator('input[name="icon"][value="cycle"] + span').click();
        await modal.locator('input[name="accent_color_preset"][value="#ec4899"] + span').click();
        ensure(await modal.locator('input[name="accent_color"]').inputValue() === '#ec4899', 'preset sincroniza el selector libre');
        await modal.locator('input[name="accent_color"]').fill('#12a4d9');
        ensure(await modal.locator('[data-routine-color-output]').textContent() === '#12A4D9', 'color libre actualiza su código en vivo');
        ensure(await modal.locator('[data-routine-identity-picker]').evaluate((node) => node.style.getPropertyValue('--routine-accent').trim() === '#12a4d9'), 'color libre previsualiza la identidad');
        await modal.locator('input[name="days[]"][value="mon"] + span').click();
        await modal.locator('input[name="days[]"][value="fri"] + span').click();
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-picker-mobile.png'), fullPage: true });

        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            modal.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));
        ensure(routineId > 0, 'crear rutina personalizada abre su detalle', `#${routineId}`);
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=identity`, { waitUntil: 'networkidle' });
        const editForm = page.locator('form.workouts-routine-form');
        ensure(await editForm.locator('input[name="icon"][value="cycle"]:checked').count() === 1, 'icono persiste al crear');
        ensure(await editForm.locator('input[name="accent_color"]').inputValue() === '#12a4d9', 'color libre persiste al crear');
        ensure(await editForm.locator('input[name="accent_color_preset"]:checked').count() === 0, 'un color libre no simula un preset');

        await editForm.locator('input[name="icon"][value="bolt"] + span').click();
        await editForm.locator('input[name="accent_color_preset"][value="#f97316"] + span').click();
        ensure(await editForm.locator('input[name="accent_color"]').inputValue() === '#f97316', 'preset sigue siendo un atajo editable');
        await editForm.locator('input[name="accent_color"]').fill('#d946ef');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            editForm.locator('button[type="submit"]').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=identity`, { waitUntil: 'networkidle' });
        const savedEditForm = page.locator('form.workouts-routine-form');
        ensure(await savedEditForm.locator('input[name="icon"][value="bolt"]:checked').count() === 1, 'editar conserva el nuevo icono');
        ensure(await savedEditForm.locator('input[name="accent_color"]').inputValue() === '#d946ef', 'editar conserva un segundo color libre');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-editor-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const card = page.locator('.workouts-routine-card').filter({ hasText: routineName }).first();
        ensure(await card.count() === 1, 'rutina personalizada aparece en Inicio de Entreno');
        const cardAccent = await card.evaluate((node) => node.style.getPropertyValue('--routine-accent').trim());
        ensure(cardAccent === '#d946ef', 'tarjeta aplica el color libre elegido', cardAccent);
        const cardPath = await card.locator('.workouts-routine-icon path').first().getAttribute('d');
        ensure((cardPath || '').includes('m13 2-9 12'), 'tarjeta aplica el icono elegido');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-card-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=plan`, { waitUntil: 'networkidle' });
        const scheduled = page.locator('.workouts-day-routine').filter({ hasText: routineName });
        ensure(await scheduled.count() === 2, 'agenda muestra la rutina en los dos días elegidos');
        ensure(await scheduled.first().locator('.workouts-routine-icon').count() === 1, 'agenda reutiliza la identidad visual');

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [
                '/?page=workouts',
                '/?page=workouts&view=plan',
                `/?page=workouts&routine_id=${routineId}`,
                `/?page=workouts&routine_id=${routineId}&section=settings&settings_view=identity`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('rutinas personalizadas responden entre 320 y 1024 px', responsiveFailures.length === 0, responsiveFailures.join(', '));

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const themeToggle = page.locator('[data-theme-toggle]');
        const initialTheme = await page.locator('body').getAttribute('data-theme');
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'dark');
        }
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-card-dark-mobile.png'), fullPage: true });
        const darkSurface = await card.evaluate((node) => getComputedStyle(node).backgroundColor);
        check('tarjeta conserva contraste en tema oscuro', darkSurface !== 'rgba(0, 0, 0, 0)', darkSurface);
        await themeToggle.evaluate((button) => button.click());
        await page.waitForFunction(() => document.body.dataset.theme === 'light');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-card-light-mobile.png'), fullPage: true });
        const lightSurface = await card.evaluate((node) => getComputedStyle(node).backgroundColor);
        check('tarjeta conserva contraste en tema claro', lightSurface !== 'rgba(0, 0, 0, 0)' && lightSurface !== darkSurface, lightSurface);
        if (initialTheme === 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'dark');
        }
        await page.waitForTimeout(350);

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`, { waitUntil: 'networkidle' });
        const deleteForm = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
        await Promise.all([
            page.waitForURL((url) => !url.searchParams.has('routine_id')),
            deleteForm.locator('button[type="submit"]').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        check('QA elimina la rutina temporal', await page.locator('.workouts-routine-card').filter({ hasText: routineName }).count() === 0);

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de personalización de rutinas', false, error.stack || error.message);
    } finally {
        if (routineId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanup = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await cleanup.count()) await cleanup.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best-effort cleanup */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
