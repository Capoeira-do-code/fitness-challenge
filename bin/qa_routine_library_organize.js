/**
 * Browser QA for the personal routine order screen.
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
const BASE = value('base', 'http://127.0.0.1:8129').replace(/\/$/, '');
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
const namesIn = async (locator) => locator.locator('[data-routine-library-item] .workouts-routine-order-copy strong').evaluateAll(
    (nodes) => nodes.map((node) => node.childNodes[0]?.textContent?.trim() || node.textContent.trim()),
);
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
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const rootCards = page.locator('.workouts-routine-card');
        ensure(await rootCards.count() >= 3, 'fixture ofrece varias rutinas para ordenar', `${await rootCards.count()} rutinas`);
        ensure(await page.locator('.workouts-organize-routines-link').isVisible(), 'Inicio de Entreno expone Organizar');

        const favoriteCard = rootCards.first();
        const favoriteName = (await favoriteCard.locator('.workouts-routine-title strong').innerText()).trim();
        if (await favoriteCard.locator('.workouts-fav-star').count() === 0) {
            await favoriteCard.locator('.kebab-menu-trigger').click();
            const favoriteAction = page.locator('.kebab-menu-panel.is-portaled [data-menu-open]').filter({ hasText: /Organizar|Organize|Organizza/ }).first();
            if (await favoriteAction.count()) await favoriteAction.click();
            const favoriteSubmit = page.locator('.kebab-menu-panel.is-portaled [data-wk-submit="routine_favorite"]').first();
            await Promise.all([
                page.waitForLoadState('networkidle'),
                favoriteSubmit.click(),
            ]);
        }

        await page.goto(`${BASE}/?page=workouts&view=organize`, { waitUntil: 'networkidle' });
        ensure(new URL(page.url()).searchParams.get('view') === 'organize', 'organizador usa una URL compartible');
        ensure((await page.locator('.workouts-hero > a').getAttribute('href')) === '/?page=workouts', 'Volver apunta al hub de Entreno');
        ensure(await page.locator('.bottom-nav a[aria-current="page"]').getAttribute('href') === '/?page=workouts', 'barra inferior mantiene Entreno activo');

        const favoriteGroup = page.locator('[data-routine-order-group="favorites"]');
        const otherGroup = page.locator('[data-routine-order-group="others"]');
        ensure(await favoriteGroup.count() === 1, 'favoritas viven en un grupo fijo');
        ensure((await namesIn(favoriteGroup)).includes(favoriteName), 'la rutina fijada aparece primero', favoriteName);
        ensure(await otherGroup.locator('[data-routine-library-item]').count() >= 2, 'el resto se puede ordenar de forma independiente');

        const rows = otherGroup.locator('[data-routine-library-item]');
        const idsBefore = await rows.locator('input[name="order[]"]').evaluateAll((inputs) => inputs.map((input) => Number(input.value)));
        const namesBefore = await namesIn(otherGroup);
        const firstRow = rows.first();
        await firstRow.locator('[data-routine-library-move="down"]').click();
        const namesPreview = await namesIn(otherGroup);
        ensure(namesPreview[0] === namesBefore[1] && namesPreview[1] === namesBefore[0], 'Bajar actualiza la previsualización al instante', `${namesBefore.slice(0, 2).join(' > ')} → ${namesPreview.slice(0, 2).join(' > ')}`);
        const focusedRoutine = await page.evaluate(() => document.activeElement?.closest('[data-routine-library-item]')?.getAttribute('data-exercise-name') || '');
        ensure(focusedRoutine === namesBefore[0], 'el control conserva el foco en la rutina movida', focusedRoutine);
        ensure((await otherGroup.locator('[data-routine-library-status]').innerText()).trim() !== '', 'el cambio se anuncia a lectores de pantalla');
        const positions = await rows.locator('[data-routine-library-position]').allInnerTexts();
        ensure(positions.join(',') === Array.from({ length: positions.length }, (_, index) => index + 1).join(','), 'las posiciones se renumeran sin recargar', positions.join(','));

        const touchTargets = await page.locator('[data-routine-library-move]').evaluateAll((buttons) => buttons.map((button) => {
            const rect = button.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        }));
        ensure(touchTargets.every(([width, height]) => width >= 44 && height >= 44), 'controles de orden mantienen 44 px', JSON.stringify(touchTargets));
        ensure((await overflow(page)) <= 1, 'organizador móvil sin overflow a 390 px');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-library-organize-mobile.png'), fullPage: true });

        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('view') === 'organize'),
            otherGroup.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        const persistedNames = await namesIn(page.locator('[data-routine-order-group="others"]'));
        ensure(persistedNames.slice(0, 2).join('|') === namesPreview.slice(0, 2).join('|'), 'orden personalizado persiste tras guardar', persistedNames.slice(0, 2).join(' > '));
        ensure(await page.locator('.flash-success').count() === 1, 'guardar confirma el nuevo orden');

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const cardNames = await page.locator('.workouts-routine-card .workouts-routine-title strong').allInnerTexts();
        ensure(cardNames[0].trim() === favoriteName, 'favorita sigue fijada al principio del hub');
        ensure(cardNames.slice(1, 3).map((name) => name.trim()).join('|') === namesPreview.slice(0, 2).join('|'), 'Inicio refleja el orden elegido');

        const outsider = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const outsiderPage = await outsider.newPage();
        await login(outsiderPage, 'catalina');
        await outsiderPage.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const outsiderCsrf = await outsiderPage.locator('input[name="csrf_token"]').first().inputValue();
        await outsiderPage.request.post(`${BASE}/?page=workouts`, {
            form: {
                csrf_token: outsiderCsrf,
                action: 'routine_reorder',
                return_to: 'organize',
                'order[]': idsBefore.slice().reverse().map(String),
            },
        });
        await outsider.close();
        await page.goto(`${BASE}/?page=workouts&view=organize`, { waitUntil: 'networkidle' });
        ensure((await namesIn(page.locator('[data-routine-order-group="others"]'))).slice(0, 2).join('|') === namesPreview.slice(0, 2).join('|'), 'otra persona no puede alterar el orden');

        const initialTheme = await page.locator('body').getAttribute('data-theme');
        const themeToggle = page.locator('[data-theme-toggle]');
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'dark');
        }
        const darkSurface = await page.locator('.workouts-routine-order-group').first().evaluate((node) => getComputedStyle(node).backgroundColor);
        ensure(darkSurface !== 'rgba(0, 0, 0, 0)', 'organizador conserva superficie en tema oscuro', darkSurface);
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-library-organize-mobile-dark.png'), fullPage: true });
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'light');
        }

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            await page.goto(`${BASE}/?page=workouts&view=organize`, { waitUntil: 'networkidle' });
            const excess = await overflow(page);
            if (excess > 1) responsiveFailures.push(`${width}:${excess}`);
        }
        check('organizador responde de 320 a 1440 px', responsiveFailures.length === 0, responsiveFailures.join(', '));
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-library-organize-desktop.png'), fullPage: true });

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo del organizador de rutinas', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
