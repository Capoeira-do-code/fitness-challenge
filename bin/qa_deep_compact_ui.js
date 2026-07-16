/**
 * Deep responsive QA for the shared compact visual system.
 * Run only against the disposable QA database.
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
const fixturePath = path.join(reportDir, 'qa-gallery-fixture.png');
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
const visibleCount = (locator) => locator.evaluateAll((nodes) => nodes.filter((node) => {
    const style = getComputedStyle(node);
    const rect = node.getBoundingClientRect();
    return !node.hidden && style.display !== 'none' && style.visibility !== 'hidden'
        && rect.width > 0 && rect.height > 0;
}).length);
const gridColumns = (locator) => locator.evaluate((node) => getComputedStyle(node)
    .gridTemplateColumns.split(' ').filter(Boolean).length);

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
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const page = await context.newPage();
    page.setDefaultTimeout(18000);
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('console', (message) => {
        const source = message.location().url || '';
        if (message.type() === 'error' && source.startsWith(BASE)) pageErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    let createdSession = false;
    try {
        await login(page);

        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        const bodyFont = await page.locator('body').evaluate((node) => getComputedStyle(node).fontFamily);
        ensure(bodyFont.includes('Manrope'), 'tipografía global consistente', bodyFont);
        ensure(await page.locator('.compact-panel.glass-panel').count() >= 4, 'Inicio usa las primitivas visuales compartidas');
        ensure(await page.locator('[data-dashboard-widget="achievement_progress"] .compact-list-item').count() <= 3, 'progreso de logros conserva tres filas compactas');
        const achievementStates = await page.locator('.dashboard-achievement-progress-row').evaluateAll((nodes) => nodes.map((node) => node.dataset.state));
        ensure(achievementStates.every((state) => state === 'locked' || state === 'nearly-complete'), 'logros bloqueados exponen estado semántico', achievementStates.join(', '));
        const questStates = await page.locator('.quest-item').evaluateAll((nodes) => nodes.map((node) => node.dataset.state));
        ensure(questStates.length > 0 && questStates.every(Boolean), 'misiones exponen estado semántico', questStates.join(', '));
        ensure(await page.locator('.dashboard-ranking-panel .leaderboard-delta').count() === await page.locator('.dashboard-ranking-panel .leaderboard-row').count(), 'ranking muestra diferencia respecto al líder');
        ensure(await page.locator('.dashboard-ranking-panel .leaderboard-row.is-me').count() <= 1, 'ranking resalta como máximo al usuario actual');

        for (const [width, expected] of [[320, 2], [360, 4], [390, 4], [430, 4]]) {
            await page.setViewportSize({ width, height: 844 });
            await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
            ensure(await gridColumns(page.locator('.dashboard-training-progress-grid')) === expected, `Inicio distribuye métricas correctamente a ${width}px`, `${expected} columnas`);
            await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
            ensure(await gridColumns(page.locator('.workouts-overview-kpi-strip')) === expected, `Entreno distribuye métricas correctamente a ${width}px`, `${expected} columnas`);
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        if (await page.locator('.workouts-active-session-card').count() === 0) {
            const startForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).first();
            ensure(await startForm.count() === 1, 'hay una acción disponible para iniciar sesión');
            await Promise.all([
                page.waitForLoadState('networkidle'),
                startForm.locator('button[type="submit"]').click(),
            ]);
            createdSession = true;
            await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        }
        ensure(await page.locator('.workouts-active-session-card').count() === 1, 'sesión activa usa una única tarjeta enriquecida');
        ensure(await page.locator('.workouts-active-session-progress .goal-progress').count() === 1, 'sesión activa muestra progreso');
        ensure(await page.locator('.workouts-active-session-finish').count() === 1, 'sesión activa permite finalizar');
        const resumeTarget = await page.locator('.workouts-resume-banner').evaluate((node) => {
            const rect = node.getBoundingClientRect();
            return { width: Math.round(rect.width), height: Math.round(rect.height) };
        });
        ensure(resumeTarget.height >= 44, 'reanudar conserva objetivo táctil accesible', JSON.stringify(resumeTarget));

        await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.profile-hero.compact-panel.glass-panel').count() === 1, 'perfil propio usa cabecera compacta');
        ensure(await page.locator('.profile-avatar-trigger').count() === 1, 'perfil propio mantiene edición de avatar');
        const foreignHref = await page.locator('a[href*="page=profile"][href*="user_id="]').evaluateAll((nodes) => {
            const own = new URL(location.href).searchParams.get('user_id');
            return nodes.map((node) => node.getAttribute('href')).find((href) => href && new URL(href, location.origin).searchParams.get('user_id') !== own) || '';
        });
        if (foreignHref) {
            await page.goto(`${BASE}${foreignHref}`, { waitUntil: 'networkidle' });
            ensure(await page.locator('.profile-avatar-trigger').count() === 0, 'perfil ajeno respeta privacidad de edición');
        } else {
            check('perfil ajeno respeta privacidad de edición', true, 'sin perfil externo en esta fixture');
        }

        await page.goto(`${BASE}/?page=gallery&gallery_view=recent`, { waitUntil: 'networkidle' });
        if (await page.locator('.photos-gallery-tile').count() === 0) {
            fs.writeFileSync(fixturePath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAQAAABFaP0WAAAADElEQVR42mP8z8AARAAHggJ/P3QXWQAAAABJRU5ErkJggg==', 'base64'));
            await page.goto(`${BASE}/?page=entries&mode=meal`, { waitUntil: 'networkidle' });
            await page.locator('input[name="caption"]').fill('QA compact gallery');
            await page.locator('input[name="photo"]').setInputFiles(fixturePath);
            await Promise.all([
                page.waitForLoadState('networkidle'),
                page.locator('.proof-photo-form button[type="submit"]').click(),
            ]);
            await page.goto(`${BASE}/?page=gallery&gallery_view=recent`, { waitUntil: 'networkidle' });
        }
        ensure(await page.locator('.photos-gallery-tile').count() >= 1, 'Galería presenta miniaturas');
        ensure(await gridColumns(page.locator('.photos-gallery-grid')) === 3, 'Galería móvil usa tres columnas');
        const tileShape = await page.locator('.photos-gallery-tile').first().evaluate((node) => {
            const rect = node.getBoundingClientRect();
            const image = node.querySelector('img');
            return { delta: Math.abs(rect.width - rect.height), decoding: image?.decoding || '', loading: image?.loading || '' };
        });
        ensure(tileShape.delta <= 1, 'miniaturas de Galería son cuadradas', JSON.stringify(tileShape));
        ensure(tileShape.decoding === 'async', 'miniaturas se decodifican de forma asíncrona', tileShape.decoding);
        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('page') === 'photo'),
            page.locator('.photos-gallery-tile').first().click(),
        ]);
        await page.locator('.photo-post').waitFor({ state: 'visible' });
        ensure(await page.locator('.photo-post.compact-panel.glass-panel').count() === 1, 'detalle de foto conserva contexto y diseño compacto');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=season`, { waitUntil: 'networkidle' });
        const visibleSeasonRows = await visibleCount(page.locator('[data-collapsible-item]'));
        ensure(visibleSeasonRows <= 3, 'temporada limita filas móviles inicialmente', `${visibleSeasonRows} visibles`);

        const routes = ['dashboard', 'workouts', 'social', 'profile', 'gallery&gallery_view=recent', 'duels', 'competitions', 'season'];
        const viewports = [[320, 740], [360, 800], [375, 812], [390, 844], [430, 900], [768, 900], [1280, 900], [1600, 1000]];
        for (const [width, height] of viewports) {
            await page.setViewportSize({ width, height });
            for (const route of routes) {
                const response = await page.goto(`${BASE}/?page=${route}`, { waitUntil: 'networkidle' });
                check(`${width}px ${route} responde`, Boolean(response && response.status() < 500), String(response?.status()));
                check(`${width}px ${route} sin desbordamiento`, await overflow(page) <= 1, `${await overflow(page)}px`);
            }
        }

        for (const theme of ['light', 'dark']) {
            for (const [width, height] of [[390, 844], [1280, 900]]) {
                await page.setViewportSize({ width, height });
                await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
                await page.evaluate((selectedTheme) => {
                    document.body.dataset.theme = selectedTheme;
                    document.body.classList.toggle('theme-active-dark', selectedTheme === 'dark');
                }, theme);
                ensure(await overflow(page) <= 1, `${theme} ${width}px sin desbordamiento`);
                await page.screenshot({ path: path.join(reportDir, `ui-deep-dashboard-${theme}-${width}.png`), fullPage: true });
            }
        }

        ensure(pageErrors.length === 0, 'sin errores de consola', pageErrors.join(' | '));
        ensure(serverErrors.length === 0, 'sin respuestas HTTP 500', serverErrors.join(' | '));
    } catch (error) {
        check('ejecución completa', false, error.stack || error.message);
    } finally {
        if (createdSession) {
            page.removeAllListeners('dialog');
            page.on('dialog', (dialog) => dialog.accept());
            await page.setViewportSize({ width: 390, height: 844 }).catch(() => {});
            await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' }).catch(() => {});
            const finish = page.locator('.workouts-active-session-finish');
            if (await finish.count().catch(() => 0)) {
                await Promise.all([
                    page.waitForLoadState('networkidle').catch(() => {}),
                    finish.locator('button[type="submit"]').click().catch(() => {}),
                ]);
            }
        }
        if (fs.existsSync(fixturePath)) fs.unlinkSync(fixturePath);
        const report = {
            generatedAt: new Date().toISOString(),
            base: BASE,
            checks,
            pageErrors,
            serverErrors,
            ok: checks.length > 0 && checks.every((item) => item.pass),
        };
        fs.writeFileSync(path.join(reportDir, 'qa-deep-compact-ui.json'), JSON.stringify(report, null, 2));
        await browser.close();
        process.exitCode = report.ok ? 0 : 1;
    }
})();
