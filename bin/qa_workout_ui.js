/**
 * Browser regression for the ranked training hub.
 *
 * Run against a local server backed by storage/qa_workouts.sqlite:
 *   node bin/qa_workout_ui.js --base http://127.0.0.1:8113
 */

const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf('--' + name);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8113').replace(/\/$/, '');
const USERNAME = arg('username', 'roberto');
const PASSWORD = arg('password', 'Verify123!');
const reportDir = path.join(__dirname, '..', 'e2e-report');

const results = [];
const check = (name, pass, detail = '') => {
    results.push({ name, pass, detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(name, Boolean(condition), detail);
    if (!condition) {
        throw new Error(`${name}${detail ? ': ' + detail : ''}`);
    }
};
const noHorizontalOverflow = async (page, name) => {
    const dimensions = await page.evaluate(() => ({
        viewport: window.innerWidth,
        document: document.documentElement.scrollWidth,
        body: document.body.scrollWidth,
    }));
    const widest = Math.max(dimensions.document, dimensions.body);
    check(name, widest <= dimensions.viewport + 1, `${widest}px / ${dimensions.viewport}px`);
};
const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
    ensure(!page.url().includes('page=login'), 'inicio de sesión QA');
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const runtimeErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
    page.on('console', (message) => {
        const text = message.text();
        if (message.type() === 'error' && !text.startsWith('Failed to load resource:')) runtimeErrors.push(text);
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);
        const routineName = `UI Martes Domingo ${Date.now()}`;

        await page.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-card').count() === 12, 'biblioteca limita la primera carga', '12 ejercicios');
        ensure(await page.locator('.workouts-mobile-subheader [data-hierarchy-back]').count() === 1, 'biblioteca conserva retorno jerárquico');
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-section-grid .hierarchy-nav-row').count() === 5, 'grid único del hub completo');
        await page.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-pagination').count() === 1, 'biblioteca ofrece paginación');
        ensure(await page.locator('[data-library-layout-switch]').count() === 1, 'selector de densidad disponible');
        if (await page.locator('[data-library-layout-switch] button[value="cards"][aria-pressed="true"]').count() !== 1) {
            await Promise.all([
                page.waitForLoadState('networkidle'),
                page.locator('[data-library-layout-switch] button[value="cards"]').click(),
            ]);
        }
        ensure(await page.locator('[data-library-layout-switch] button[value="cards"][aria-pressed="true"]').count() === 1, 'vista visual predeterminada');
        const visualCardHeight = await page.locator('.workouts-library-card').first().evaluate((element) => element.getBoundingClientRect().height);
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.locator('[data-library-layout-switch] button[value="compact"]').click(),
        ]);
        ensure(await page.locator('.workouts-library-grid.is-compact[data-library-layout="compact"]').count() === 1, 'vista compacta aplicada');
        ensure(await page.locator('[data-library-layout-switch] button[value="compact"][aria-pressed="true"]').count() === 1, 'selector refleja la vista compacta');
        ensure(await page.locator('.workouts-library-card').count() === 12, 'vista compacta conserva los 12 resultados');
        ensure(await page.locator('.workouts-library-copy h3').count() === 12
            && await page.locator('.workouts-library-copy p').count() === 12
            && await page.locator('.workouts-library-card .workouts-exercise-tags').count() === 12
            && await page.locator('.workouts-library-card a.btn').count() >= 12, 'vista compacta conserva información y acciones');
        const compactCardHeight = await page.locator('.workouts-library-card').first().evaluate((element) => element.getBoundingClientRect().height);
        ensure(compactCardHeight < visualCardHeight * 0.78, 'vista compacta reduce la altura de tarjeta', `${Math.round(visualCardHeight)}px → ${Math.round(compactCardHeight)}px`);
        const layoutTargetSizes = await page.locator('[data-library-layout-switch] button').evaluateAll((buttons) => buttons.map((button) => {
            const box = button.getBoundingClientRect();
            return Math.min(box.width, box.height);
        }));
        ensure(layoutTargetSizes.every((size) => size >= 44), 'selector compacto mantiene objetivos táctiles de 44 px', layoutTargetSizes.map(Math.round).join(', '));
        await page.reload({ waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-grid.is-compact').count() === 1, 'preferencia compacta persiste al recargar');
        await noHorizontalOverflow(page, 'biblioteca compacta móvil sin desbordamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-workout-library-compact-mobile.png'), fullPage: true });
        await page.goto(`${BASE}/?page=workouts&view=library&library_page=2`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-card').count() === 12, 'segunda carga mantiene el límite', '12 ejercicios');
        ensure(await page.locator('.workouts-library-grid.is-compact').count() === 1, 'preferencia compacta persiste al paginar');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.locator('[data-library-layout-switch] button[value="cards"]').click(),
        ]);
        ensure(page.url().includes('library_page=2')
            && await page.locator('.workouts-library-grid:not(.is-compact)[data-library-layout="cards"]').count() === 1, 'cambiar a visual conserva la página');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.locator('[data-library-layout-switch] button[value="compact"]').click(),
        ]);
        ensure(page.url().includes('library_page=2')
            && await page.locator('.workouts-library-grid.is-compact').count() === 1, 'volver a compacta conserva la página');
        const pagedGuideHref = await page.locator('.workouts-library-card h3 a').first().getAttribute('href');
        ensure((pagedGuideHref || '').includes('library_page=2'), 'guía conserva la página de biblioteca');
        await noHorizontalOverflow(page, 'biblioteca móvil sin desbordamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-workout-library-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=library&muscle=chest`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-card').count() === 4, 'filtro UI por pecho', '4 resultados');
        ensure(await page.locator('.workouts-library-grid.is-compact').count() === 1, 'preferencia compacta persiste con filtros');
        const firstGuideHref = await page.locator('.workouts-library-card h3 a').first().getAttribute('href');
        ensure(Boolean(firstGuideHref), 'enlace a guía disponible');
        await page.goto(`${BASE}${firstGuideHref}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-guide-steps li').count() === 3, 'guía UI muestra pasos');
        ensure(await page.locator('.workouts-guide-note.tips').count() === 1, 'guía UI muestra consejo');
        ensure(await page.locator('.workouts-guide-note.mistakes').count() === 1, 'guía UI muestra error común');
        await noHorizontalOverflow(page, 'guía móvil sin desbordamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-workout-guide-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=plan`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-day-card').count() === 7, 'agenda UI muestra siete días');
        await page.click('[data-app-modal-open="wk-new-routine-modal"]');
        const routineModal = page.locator('#wk-new-routine-modal');
        ensure(await routineModal.evaluate((element) => !element.hidden && element.classList.contains('is-open')), 'modal de rutina accesible');
        await routineModal.locator('input[name="name"]').fill(routineName);
        await routineModal.locator('input[name="description"]').fill('Rutina creada desde móvil');
        await routineModal.locator('input[name="days[]"][value="tue"] + span').click();
        await routineModal.locator('input[name="days[]"][value="sun"] + span').click();
        await Promise.all([
            page.waitForLoadState('networkidle'),
            routineModal.locator('button[type="submit"]').click(),
        ]);
        const routineUrl = page.url();
        ensure(routineUrl.includes('routine_id='), 'crear rutina desde la interfaz');
        await page.goto(`${routineUrl}&section=settings&settings_view=schedule`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-day-picker input[value="tue"]:checked').count() === 1, 'martes persistido en UI');
        ensure(await page.locator('.workouts-day-picker input[value="sun"]:checked').count() === 1, 'domingo persistido en UI');

        await page.goto(`${BASE}/?page=workouts&view=library&muscle=chest`, { waitUntil: 'networkidle' });
        const firstCard = page.locator('.workouts-library-card').first();
        await firstCard.locator('select[name="routine_id"]').selectOption({ label: routineName });
        await Promise.all([
            page.waitForLoadState('networkidle'),
            firstCard.locator('.workouts-library-add button[type="submit"]').click(),
        ]);
        await page.goto(routineUrl, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-exercise-row').count() === 1, 'añadir ejercicio a rutina desde biblioteca');

        const startForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).first();
        await Promise.all([
            page.waitForLoadState('networkidle'),
            startForm.locator('button[type="submit"]').click(),
        ]);
        ensure(page.url().includes('session_id='), 'iniciar entrenamiento desde rutina');
        ensure(await page.locator('.workouts-session-exercise').count() === 1, 'sesión contiene ejercicio de rutina');
        ensure(await page.locator('.workouts-session-guide').count() === 1, 'explicación disponible durante entrenamiento');
        const firstSet = page.locator('.workouts-set-row').first();
        await firstSet.locator('input[name="weight"]').fill('60');
        await firstSet.locator('input[name="reps"]').fill('8');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            firstSet.locator('button[name="completed"]').click(),
        ]);
        ensure(await page.locator('.workouts-set-row.is-done').count() === 1, 'marcar serie completada');
        const finishForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_finish"]') });
        await Promise.all([
            page.waitForLoadState('networkidle'),
            finishForm.locator('button[type="submit"]').click(),
        ]);
        ensure(!page.url().includes('session_id='), 'finalizar entrenamiento desde UI');

        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(`${BASE}/?page=workouts&view=plan`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-week-agenda').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length) === 1, 'agenda escritorio lineal con separadores');
        ensure(await page.locator('.workouts-week-agenda .workouts-day-card').count() === 7, 'agenda escritorio conserva los siete días');
        await noHorizontalOverflow(page, 'agenda escritorio sin desbordamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-workout-plan-desktop.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=ranks`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-body-rank-card').count() === 10, 'rangos UI por parte corporal');
        ensure(await page.locator('.workouts-exercise-rank-row').count() === 29, 'rangos UI por ejercicio', '29 ejercicios puntuables');
        ensure(await page.locator('.workouts-leaderboard-row').count() >= 2, 'clasificación UI del equipo');
        const overallScore = Number((await page.locator('.workouts-rank-emblem strong').innerText()).replace(',', '.'));
        const overallRankKey = await page.locator('.workouts-rank-hero').getAttribute('data-rank');
        ensure(Number.isFinite(overallScore) && overallScore >= 0 && Boolean(overallRankKey), 'resumen de rango global calculado', `${overallRankKey} · ${overallScore} puntos`);
        await noHorizontalOverflow(page, 'ranking escritorio sin desbordamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-workout-ranks-desktop.png'), fullPage: true });

        const responsiveWidths = [320, 360, 390, 430, 768, 1024, 1280, 1440];
        const responsiveViews = ['library', 'plan', 'ranks'];
        const overflowFailures = [];
        for (const width of responsiveWidths) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const view of responsiveViews) {
                await page.goto(`${BASE}/?page=workouts&view=${view}`, { waitUntil: 'networkidle' });
                const scrollWidth = await page.evaluate(() => Math.max(
                    document.documentElement.scrollWidth,
                    document.body.scrollWidth,
                ));
                if (scrollWidth > width + 1) overflowFailures.push(`${view}@${width}=${scrollWidth}`);
            }
        }
        check(
            'matriz responsive completa',
            overflowFailures.length === 0,
            overflowFailures.length === 0 ? '320, 360, 390, 430, 768, 1024, 1280 y 1440 px' : overflowFailures.join(', '),
        );

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        await page.evaluate(() => {
            document.body.dataset.theme = 'dark';
            document.body.classList.add('theme-active-dark');
        });
        const darkBackground = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        check('modo oscuro del hub', darkBackground !== 'rgb(255, 255, 255)', darkBackground);
        await noHorizontalOverflow(page, 'biblioteca oscura móvil sin desbordamiento');
        await page.screenshot({ path: path.join(reportDir, 'ui-workout-library-mobile-dark.png'), fullPage: true });

        check('errores JavaScript', runtimeErrors.length === 0, runtimeErrors.join(' | '));
        check('respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('ejecución completa del flujo UI', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = results.filter((result) => !result.pass);
    console.log(`\n${results.length - failed.length}/${results.length} comprobaciones UI correctas.`);
    if (failed.length > 0) process.exitCode = 1;
})();
