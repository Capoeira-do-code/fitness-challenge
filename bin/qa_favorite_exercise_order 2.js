/**
 * Browser QA for ordering the user's favorite exercise collection.
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
const BASE = value('base', 'http://127.0.0.1:8131').replace(/\/$/, '');
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
const organizerNames = async (page) => page.locator('[data-favorite-exercise-item] .workouts-routine-order-copy strong').evaluateAll(
    (nodes) => nodes.map((node) => node.childNodes[0]?.textContent?.trim() || node.textContent.trim()),
);
const cardNames = async (page) => page.locator('.workouts-library-card .workouts-library-copy h3').allInnerTexts();
const overflow = async (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

(async () => {
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(18000);
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

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        const routineId = Number(await page.locator('select[name="routine_id"] option').first().getAttribute('value'));
        ensure(routineId > 0, 'fixture ofrece una rutina para comprobar contexto', `#${routineId}`);

        const favoritedNames = [];
        for (let index = 0; index < 3; index++) {
            const card = page.locator('.workouts-library-card:not(.is-favorite)').first();
            favoritedNames.push((await card.locator('.workouts-library-copy h3').innerText()).trim());
            await Promise.all([
                page.waitForLoadState('networkidle'),
                card.locator('.workouts-favorite-toggle').click(),
            ]);
        }
        ensure(new Set(favoritedNames).size === 3, 'se fijan tres ejercicios distintos', favoritedNames.join(' · '));

        await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-card').count() === 3, 'Favoritos muestra la colección personal');
        ensure(await page.locator('.workouts-library-actions a[href*="library_mode=organize"]').isVisible(), 'Favoritos expone Organizar cuando resulta útil');
        const initialCardNames = (await cardNames(page)).map((name) => name.trim());

        await page.locator('.workouts-library-actions a[href*="library_mode=organize"]').click();
        await page.waitForURL((url) => url.searchParams.get('library_mode') === 'organize');
        await page.waitForLoadState('networkidle');
        ensure(await page.locator('.workouts-favorite-order-panel').count() === 1, 'organizador vive dentro de Biblioteca y Favoritos');
        ensure(await page.locator('.workouts-library-filters').count() === 0 && await page.locator('.workouts-muscle-filters').count() === 0, 'el modo ordenar elimina filtros que podrían ocultar filas');
        ensure(await page.locator('[data-favorite-exercise-item]').count() === 3, 'organizador carga todos los favoritos sin paginar');
        ensure((await organizerNames(page)).join('|') === initialCardNames.join('|'), 'editor comienza con el orden visible en Favoritos');

        const rows = page.locator('[data-favorite-exercise-item]');
        const idsBefore = await rows.locator('input[name="order[]"]').evaluateAll((inputs) => inputs.map((input) => Number(input.value)));
        const firstName = initialCardNames[0];
        await rows.first().locator('[data-favorite-exercise-move="down"]').click();
        const previewNames = await organizerNames(page);
        ensure(previewNames[0] === initialCardNames[1] && previewNames[1] === firstName, 'Bajar reordena inmediatamente', `${initialCardNames.slice(0, 2).join(' > ')} → ${previewNames.slice(0, 2).join(' > ')}`);
        const focusedExercise = await page.evaluate(() => document.activeElement?.closest('[data-favorite-exercise-item]')?.getAttribute('data-exercise-name') || '');
        ensure(focusedExercise === firstName, 'el foco acompaña al ejercicio movido', focusedExercise);
        ensure((await page.locator('[data-favorite-exercise-status]').innerText()).trim() !== '', 'el cambio se anuncia a lectores de pantalla');

        const touchTargets = await page.locator('[data-favorite-exercise-move]').evaluateAll((buttons) => buttons.map((button) => {
            const rect = button.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        }));
        ensure(touchTargets.every(([width, height]) => width >= 44 && height >= 44), 'controles de orden mantienen 44 px', JSON.stringify(touchTargets));
        const saveRect = await page.locator('.workouts-routine-order-save .btn').boundingBox();
        const navRect = await page.locator('.bottom-nav').boundingBox();
        ensure(Boolean(saveRect && navRect && saveRect.y + saveRect.height <= navRect.y + 1), 'Guardar permanece visible sobre la barra inferior', JSON.stringify({ saveRect, navRect }));
        ensure((await overflow(page)) <= 1, 'organizador sin overflow a 390 px');
        await page.screenshot({ path: path.join(reportDir, 'ui-favorite-exercise-order-mobile.png'), fullPage: true });

        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('library_mode') === 'organize'),
            page.locator('.workouts-routine-order-save button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        ensure((await organizerNames(page)).join('|') === previewNames.join('|'), 'orden persiste tras guardar');
        ensure(await page.locator('.flash-success').count() === 1, 'guardar confirma el cambio');

        await page.locator('.workouts-library-actions a').filter({ hasText: /Listo|Done|Fatto/ }).click();
        await page.waitForURL((url) => !url.searchParams.has('library_mode'));
        await page.waitForLoadState('networkidle');
        ensure((await cardNames(page)).map((name) => name.trim()).join('|') === previewNames.join('|'), 'Favoritos reutiliza el orden elegido');

        const outsider = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const outsiderPage = await outsider.newPage();
        await login(outsiderPage, 'catalina');
        await outsiderPage.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        const outsiderCsrf = await outsiderPage.locator('input[name="csrf_token"]').first().inputValue();
        await outsiderPage.request.post(`${BASE}/?page=workouts`, {
            form: {
                csrf_token: outsiderCsrf,
                action: 'exercise_favorites_reorder',
                'order[]': idsBefore.slice().reverse().map(String),
            },
        });
        await outsider.close();
        await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites&library_mode=organize`, { waitUntil: 'networkidle' });
        ensure((await organizerNames(page)).join('|') === previewNames.join('|'), 'otra persona no puede cambiar la colección');

        await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites&library_mode=organize&target_routine_id=${routineId}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-favorite-order-panel').count() === 0, 'un contexto de rutina conserva la biblioteca de selección');
        ensure(await page.locator('.workouts-library-target').count() === 1, 'la rutina destino sigue visible');
        ensure(await page.locator('.workouts-library-grid.is-contextual').count() === 1, 'el contexto no se sustituye por el organizador');

        await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites&library_mode=organize`, { waitUntil: 'networkidle' });
        const initialTheme = await page.locator('body').getAttribute('data-theme');
        const themeToggle = page.locator('[data-theme-toggle]');
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'dark');
        }
        const darkSurface = await page.locator('.workouts-favorite-order-group').evaluate((node) => getComputedStyle(node).backgroundColor);
        ensure(darkSurface !== 'rgba(0, 0, 0, 0)', 'editor mantiene superficie en tema oscuro', darkSurface);
        await page.screenshot({ path: path.join(reportDir, 'ui-favorite-exercise-order-mobile-dark.png'), fullPage: true });
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'light');
        }

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites&library_mode=organize`, { waitUntil: 'networkidle' });
            const excess = await overflow(page);
            if (excess > 1) responsiveFailures.push(`${width}:${excess}`);
        }
        check('favoritos ordenables responden de 320 a 1440 px', responsiveFailures.length === 0, responsiveFailures.join(', '));
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: path.join(reportDir, 'ui-favorite-exercise-order-desktop.png'), fullPage: true });

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo del orden de favoritos', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
