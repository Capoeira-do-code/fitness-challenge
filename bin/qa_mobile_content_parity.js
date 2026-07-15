/**
 * Mobile/desktop content parity and enriched mobile menu checks.
 *
 * Run after `php bin/qa_workouts.php --keep` against a disposable server:
 *   node bin/qa_mobile_content_parity.js --base http://127.0.0.1:8113
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8113').replace(/\/$/, '');
const USERNAME = arg('username', 'roberto');
const PASSWORD = arg('password', 'Verify123!');
const REPORT_DIR = path.join(__dirname, '..', 'e2e-report');
fs.mkdirSync(REPORT_DIR, { recursive: true });

const failures = [];
const check = (condition, name, detail = '') => {
    const pass = Boolean(condition);
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
    if (!pass) failures.push(`${name}${detail ? `: ${detail}` : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(condition, name, detail);
    if (!condition) throw new Error(`${name}${detail ? `: ${detail}` : ''}`);
};

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
    ensure(!page.url().includes('page=login'), 'Inicio de sesión para QA de paridad');
};

const open = async (page, route) => page.goto(`${BASE}${route}`, { waitUntil: 'networkidle' });
const countVisible = (page, selector) => page.locator(`${selector}:visible`).count();
const overflowState = (page) => page.evaluate(() => {
    const content = Math.max(document.documentElement.scrollWidth, document.body.scrollWidth);
    const offenders = content <= innerWidth + 1 ? [] : [...document.querySelectorAll('body *')]
        .filter((element) => {
            const rect = element.getBoundingClientRect();
            const style = getComputedStyle(element);
            return style.display !== 'none' && rect.width > 0 && rect.height > 0
                && (rect.right > innerWidth + 1 || rect.left < -1);
        })
        .slice(0, 8)
        .map((element) => {
            const rect = element.getBoundingClientRect();
            return `${element.tagName.toLowerCase()}.${String(element.className).replace(/\s+/g, '.').slice(0, 90)}`
                + `[${Math.round(rect.left)},${Math.round(rect.right)};${Math.round(rect.width)}]`;
        });
    return { viewport: innerWidth, content, offenders };
});

const pages = [
    {
        name: 'Inicio',
        route: '/?page=dashboard',
        selector: '.dashboard-layout > [data-dashboard-widget]',
        feed: '.dashboard-desktop-root .mobile-widget-feed-head',
        screenshot: 'ui-mobile-dashboard-full-widgets.png',
    },
    {
        name: 'Analytics',
        route: '/?page=analytics',
        selector: '.analytics-section:not(.analytics-summary-section)',
        feed: '.analytics-page > .mobile-widget-feed-head',
        screenshot: 'ui-mobile-analytics-full-widgets.png',
    },
    {
        name: 'Team',
        route: '/?page=team',
        selector: '.team-layout-grid > [data-team-widget]',
        feed: '.team-desktop-root .mobile-widget-feed-head',
        screenshot: 'ui-mobile-team-full-widgets.png',
    },
    {
        name: 'Perfil',
        route: '/?page=profile',
        selector: '.profile-home-grid > [data-profile-block]',
        feed: '.profile-hierarchy-screen > .mobile-widget-feed-head',
        screenshot: 'ui-mobile-profile-full-widgets.png',
    },
];

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    const runtimeErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            runtimeErrors.push(message.text());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);

        const desktopCounts = {};
        for (const entry of pages) {
            await page.setViewportSize({ width: 1280, height: 900 });
            await open(page, entry.route);
            desktopCounts[entry.name] = await countVisible(page, entry.selector);
            check(desktopCounts[entry.name] >= 4, `${entry.name}: escritorio expone suficientes módulos`, `${desktopCounts[entry.name]}`);
        }
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page, '/?page=dashboard');
        const desktopAchievementCount = await countVisible(page, '.dashboard-achievement-card');
        await open(page, '/?page=team');
        const desktopTeamMetricCount = await countVisible(page, '.team-widget-metrics > .metric-card');

        for (const entry of pages) {
            await page.setViewportSize({ width: 390, height: 844 });
            await open(page, entry.route);
            const mobileCount = await countVisible(page, entry.selector);
            check(mobileCount === desktopCounts[entry.name],
                `${entry.name}: móvil conserva todos los módulos visibles de escritorio`,
                `${mobileCount}/${desktopCounts[entry.name]}`);
            check(await countVisible(page, entry.feed) === 1,
                `${entry.name}: cabecera separa navegación y widgets`);
            const overflow = await overflowState(page);
            check(overflow.content <= overflow.viewport + 1,
                `${entry.name}: sin overflow horizontal`,
                `${overflow.content}/${overflow.viewport}px ${overflow.offenders.join(' | ')}`);
            await page.screenshot({ path: path.join(REPORT_DIR, entry.screenshot), fullPage: true });
        }

        await open(page, '/?page=dashboard');
        const mobileKpis = await countVisible(page, '.dashboard-mobile-home .mobile-today-metrics > span');
        const desktopKpis = await page.locator('.dashboard-layout [data-dashboard-widget="kpis"] .metric-card').count();
        check(mobileKpis === desktopKpis && mobileKpis > 0,
            'Inicio no recorta los indicadores diarios', `${mobileKpis}/${desktopKpis}`);
        const mobileAchievementCount = await countVisible(page, '.dashboard-achievement-card');
        check(mobileAchievementCount === desktopAchievementCount,
            'Inicio no recorta la lista de logros', `${mobileAchievementCount}/${desktopAchievementCount}`);

        await open(page, '/?page=analytics');
        const mobileAnalyticsKpis = await countVisible(page, '.analytics-mobile-root .mobile-kpi-grid > *');
        const desktopAnalyticsKpis = await page.locator('.analytics-summary-section .analytics-stat-card').count();
        check(mobileAnalyticsKpis === desktopAnalyticsKpis && mobileAnalyticsKpis > 4,
            'Analytics no recorta sus indicadores de resumen', `${mobileAnalyticsKpis}/${desktopAnalyticsKpis}`);

        await open(page, '/?page=team');
        const mobileTeamMetricCount = await countVisible(page, '.team-widget-metrics > .metric-card');
        check(mobileTeamMetricCount === desktopTeamMetricCount && mobileTeamMetricCount >= 4,
            'Team no oculta métricas internas en móvil', `${mobileTeamMetricCount}/${desktopTeamMetricCount}`);

        for (const route of ['/?page=analytics', '/?page=team', '/?page=profile']) {
            await open(page, route);
            const menuState = await page.locator('.mobile-hub-section-grid:visible').evaluate((grid) => {
                const rows = [...grid.querySelectorAll(':scope > .hierarchy-nav-row')];
                return {
                    columns: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
                    tones: new Set(rows.map((row) => getComputedStyle(row).borderColor)).size,
                    minHeight: Math.min(...rows.map((row) => row.getBoundingClientRect().height)),
                };
            });
            check(menuState.columns === 2 && menuState.tones >= 4 && menuState.minHeight >= 60,
                `${route}: submenú móvil es claro, compacto y usa categorías de color`, JSON.stringify(menuState));
        }

        await open(page, '/?page=dashboard');
        const plus = page.locator('.mobile-liquid-nav .liquid-nav-plus');
        await plus.locator(':scope > summary').click();
        await plus.locator('.mobile-quick-featured').waitFor({ state: 'visible' });
        const quickState = await plus.locator('.mobile-quick-featured').evaluate((grid) => {
            const actions = [...grid.querySelectorAll(':scope > a')];
            return {
                actions: actions.length,
                columns: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
                minHeight: Math.min(...actions.map((action) => action.getBoundingClientRect().height)),
                tones: new Set(actions.map((action) => getComputedStyle(action).borderColor)).size,
            };
        });
        check(quickState.actions === 4 && quickState.columns === 4 && quickState.minHeight >= 44 && quickState.tones === 4,
            'El menú + ofrece cuatro accesos directos táctiles y diferenciados', JSON.stringify(quickState));
        check(await plus.locator('[data-menu-open="quick-register"], [data-menu-open="quick-create"]').count() === 2,
            'El menú + conserva sus dos submenús jerárquicos');
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-mobile-quick-menu-rich.png'), fullPage: false });
        const registerTrigger = plus.locator('[data-menu-open="quick-register"]');
        await registerTrigger.click();
        await plus.locator('[data-menu-view="quick-register"] [data-menu-back]').click();
        check(await registerTrigger.evaluate((element) => document.activeElement === element),
            'Volver del menú + restaura el foco');
        await page.keyboard.press('Escape');
        check(!(await plus.evaluate((element) => element.open)), 'Escape cierra el menú + enriquecido');

        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 900 ? 844 : 900 });
            for (const entry of pages) {
                await open(page, entry.route);
                const overflow = await overflowState(page);
                check(overflow.content <= overflow.viewport + 1,
                    `${entry.name}: sin overflow a ${width}px`,
                    `${overflow.content}/${overflow.viewport}px ${overflow.offenders.join(' | ')}`);
            }
        }

        check(serverErrors.length === 0, 'Sin respuestas 5xx', serverErrors.join(' | '));
        check(runtimeErrors.length === 0, 'Sin errores JavaScript', runtimeErrors.join(' | '));
    } catch (error) {
        failures.push(error.stack || error.message);
    } finally {
        await browser.close();
    }

    if (failures.length > 0) {
        console.error(`\n${failures.length} fallo(s):\n- ${failures.join('\n- ')}`);
        process.exit(1);
    }
    console.log('\nParidad de contenido móvil/desktop validada.');
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
