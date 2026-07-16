/**
 * Responsive regression coverage for the dashboard/workout/notification pass.
 * Run against the disposable database started by bin/e2e_local.py.
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
const checks = [];
const pageErrors = [];
const serverErrors = [];

const check = (name, pass, detail = '') => {
    checks.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(name, condition, detail);
    if (!condition) throw new Error(`${name}${detail ? `: ${detail}` : ''}`);
};
const overflow = (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

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

(async () => {
    const reportDir = path.join(__dirname, '..', 'e2e-report');
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
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

        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        const scoreText = await page.locator('.mobile-score-link strong').innerText();
        ensure(/\d+[,.]\d\s*\/\s*100/.test(scoreText), 'la puntuación móvil se expresa sobre 100', scoreText);
        ensure(await page.locator('a[href*="metric=score"]').count() === 2, 'la puntuación enlaza una vez por superficie');
        ensure(await page.locator('a[href*="metric=calories_consumed"]').count() >= 1, 'calorías consumidas enlaza a detalle');
        ensure(await page.locator('a[href*="metric=calories_burned"]').count() === 2, 'calorías quemadas enlaza una vez por superficie');
        ensure(await page.locator('.metric-card:empty').count() === 0, 'Inicio no genera tarjetas métricas vacías');
        ensure(await page.locator('[data-dashboard-widget="achievement_progress"]').count() === 1, 'el widget de progreso de logros se reconcilia');
        ensure(await page.locator('.dashboard-layout [data-dashboard-widget] [data-dashboard-widget]').count() === 0, 'los widgets de Inicio no quedan anidados por HTML inválido');

        await page.goto(`${BASE}/?page=season`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.season-ranking-page').count() === 1, 'la clasificación de temporada responde');
        ensure(await page.locator('.season-ranking-row.is-me').count() <= 1, 'la temporada resalta como máximo al usuario actual');

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-today-card').count() === 1, 'Entreno muestra Entreno de hoy');
        await page.goto(`${BASE}/?page=workouts&view=plan`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-week-agenda .workouts-day-card').count() === 7, 'Mi semana es una agenda de siete días');
        ensure(await page.locator('.workouts-week-agenda .btn-icon').count() === 0, 'la agenda evita acciones de inicio solo con icono');

        await page.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-mobile-search input[type="search"]').count() === 1, 'la búsqueda móvil de Biblioteca es persistente');
        await page.locator('[data-workout-filter-open]').click();
        ensure(await page.locator('[data-workout-filter-panel]').getAttribute('aria-hidden') === 'false', 'los filtros abren una hoja móvil');
        await page.locator('[data-workout-filter-close]').click();

        await page.goto(`${BASE}/?page=week_editor&range=week`, { waitUntil: 'networkidle' });
        const dayCards = page.locator('[data-week-day-card]');
        ensure(await dayCards.count() === 7, 'el registro semanal conserva siete días');
        const openDays = await dayCards.evaluateAll((cards) => cards.filter((card) => !card.classList.contains('is-mobile-collapsed')).length);
        ensure(openDays === 1, 'el acordeón móvil abre un único día por defecto', String(openDays));
        ensure(await page.locator('#save-all-rows').count() === 1, 'Guardar semana sigue disponible');

        for (const metric of ['score', 'steps', 'distance', 'workouts', 'calories_consumed', 'calories_burned']) {
            const response = await page.goto(`${BASE}/?page=metric&metric=${metric}`, { waitUntil: 'networkidle' });
            ensure(response && response.status() < 500, `métrica ${metric} responde`, String(response?.status()));
        }

        for (const route of ['profile', 'achievements', 'team']) {
            await page.goto(`${BASE}/?page=${route}`, { waitUntil: 'networkidle' });
            ensure(await page.locator('form input[name="action"][value="delete_achievement_award"]').count() === 0, `${route} no expone borrado de premios`);
        }

        const viewports = [
            [320, 740], [390, 844], [430, 900], [1280, 900], [1440, 960],
        ];
        const routes = [
            '/?page=dashboard', '/?page=entries&mode=data', '/?page=entries&mode=meal',
            '/?page=workouts', '/?page=workouts&view=plan', '/?page=workouts&view=library',
            '/?page=week_editor&range=week', '/?page=social', '/?page=gallery&gallery_view=recent',
            '/?page=notifications', '/?page=season', '/?page=settings',
        ];
        for (const [width, height] of viewports) {
            await page.setViewportSize({ width, height });
            for (const route of routes) {
                const response = await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle' });
                const extra = await overflow(page);
                check(`${width}px ${route} sin HTTP 500`, Boolean(response && response.status() < 500), String(response?.status()));
                check(`${width}px ${route} sin desbordamiento`, extra <= 1, `${extra}px`);
            }
        }

        for (const theme of ['light', 'dark']) {
            for (const [width, height] of [[390, 844], [1280, 900]]) {
                await page.setViewportSize({ width, height });
                await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
                await page.evaluate((value) => {
                    document.body.dataset.theme = value;
                    document.body.classList.toggle('theme-active-dark', value === 'dark');
                }, theme);
                check(`${theme} ${width}px dashboard sin desbordamiento`, await overflow(page) <= 1);
            }
        }

        const directPage = await context.newPage();
        await directPage.goto(`${BASE}/?page=season`, { waitUntil: 'networkidle' });
        if (new URL(directPage.url()).searchParams.get('page') === 'login') {
            await login(directPage);
            await directPage.goto(`${BASE}/?page=season`, { waitUntil: 'networkidle' });
        }
        ensure(await directPage.locator('[data-contextual-back-container]:not([hidden])').count() === 0, 'entrada directa no muestra Volver contextual');
        await directPage.close();

        check('sin errores de consola', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 500', serverErrors.length === 0, serverErrors.join(' | '));
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=week_editor&range=week`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: path.join(reportDir, 'ui-week-accordion-followup.png'), fullPage: true });
    } catch (error) {
        check('ejecución completa', false, error.stack || error.message);
    } finally {
        const report = {
            generatedAt: new Date().toISOString(),
            base: BASE,
            checks,
            pageErrors,
            serverErrors,
            ok: checks.length > 0 && checks.every((item) => item.pass),
        };
        fs.writeFileSync(path.join(reportDir, 'qa-responsive-followup.json'), JSON.stringify(report, null, 2));
        await browser.close();
        process.exitCode = report.ok ? 0 : 1;
    }
})();
