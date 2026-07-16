/**
 * Mobile hierarchy v2 acceptance checks.
 *
 * Run against the disposable workout fixture:
 *   node bin/qa_hierarchy.js --base http://127.0.0.1:8113
 */

const fs = require('fs');
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
fs.mkdirSync(reportDir, { recursive: true });

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
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
    ensure(!page.url().includes('page=login'), 'inicio de sesión jerarquía');
};
const visibleScreenHeight = (page) => page.evaluate(() => {
    const screen = document.querySelector('main .screen');
    return screen ? Math.max(screen.scrollHeight, Math.ceil(screen.getBoundingClientRect().height)) : 0;
});
const noOverflow = async (page, label) => {
    const sizes = await page.evaluate(() => ({
        viewport: window.innerWidth,
        html: document.documentElement.scrollWidth,
        body: document.body.scrollWidth,
    }));
    check(Math.max(sizes.html, sizes.body) <= sizes.viewport + 1, label, `${Math.max(sizes.html, sizes.body)}px / ${sizes.viewport}px`);
};
const activeMobileHref = async (page) => page.locator('.mobile-liquid-nav .liquid-nav-item.active').getAttribute('href');
const open = async (page, route) => page.goto(`${BASE}${route}`, { waitUntil: 'networkidle' });

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const runtimeErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) runtimeErrors.push(message.text());
    });
    page.on('response', (response) => { if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`); });

    try {
        await login(page);

        await open(page, '/?page=dashboard');
        ensure(await page.locator('.mobile-liquid-nav .liquid-nav-item').count() === 4, 'barra inferior contiene cuatro destinos');
        ensure(await page.locator('.mobile-liquid-nav .liquid-nav-plus').count() === 1, 'barra inferior integra la acción central');
        check((await activeMobileHref(page) || '').includes('page=dashboard'), 'Inicio activo en dashboard');
        ensure(await page.locator('.dashboard-mobile-home > *').count() === 5, 'Inicio mantiene cuatro superficies bajo su cabecera');
        check(await page.locator('.dashboard-desktop-root .mobile-widget-feed-head:visible').count() === 1,
            'Inicio enlaza el resumen con sus widgets configurables');
        check(await page.locator('.dashboard-layout > [data-dashboard-widget]:visible').count() >= 4,
            'Inicio móvil conserva los widgets de escritorio');
        await noOverflow(page, 'Inicio sin overflow horizontal');
        await page.screenshot({ path: path.join(reportDir, 'ui-v2-dashboard-mobile.png'), fullPage: true });

        const plus = page.locator('.mobile-liquid-nav .liquid-nav-plus');
        await plus.locator('summary').click();
        await page.waitForTimeout(100);
        ensure(await page.locator('.mobile-sheet-backdrop').count() === 1, 'sheet central crea backdrop');
        ensure(await plus.locator('[data-menu-view="main"] > [data-menu-open]:visible').count() === 2
            && await plus.locator('.mobile-quick-featured').count() === 0,
            'sheet central evita duplicados y ofrece dos categorías');
        const registerTrigger = plus.locator('[data-menu-open="quick-register"]');
        await registerTrigger.click();
        ensure(await plus.locator('[data-menu-view="quick-register"]:not([hidden])').count() === 1, 'sheet abre segundo nivel');
        await plus.locator('[data-menu-view="quick-register"] [data-menu-back]').click();
        await page.waitForTimeout(50);
        check(await registerTrigger.evaluate((element) => document.activeElement === element), 'Volver restaura el foco');
        await page.keyboard.press('Escape');
        check(!(await plus.evaluate((element) => element.open)), 'Escape cierra el sheet');
        await plus.locator('summary').click();
        await page.locator('.mobile-sheet-backdrop').click({ position: { x: 8, y: 8 } });
        check(!(await plus.evaluate((element) => element.open)), 'backdrop cierra el sheet');

        await open(page, '/?page=dashboard&section=progress');
        ensure(await page.locator('.hierarchy-page-header [data-hierarchy-back]').count() === 1, 'subpantalla de Inicio tiene Volver');
        check((await activeMobileHref(page) || '').includes('page=dashboard'), 'subpantalla conserva Inicio activo');

        await open(page, '/?page=workouts');
        ensure(await page.locator('.workouts-section-grid .hierarchy-nav-row').count() === 5, 'Entreno usa un único grid jerárquico');
        await noOverflow(page, 'Entreno sin overflow horizontal');
        await page.screenshot({ path: path.join(reportDir, 'ui-v2-training-mobile.png'), fullPage: true });
        await open(page, '/?page=workouts&view=library');
        check(await page.locator('.workouts-library-card').count() <= 12, 'Biblioteca nunca supera 12 resultados');
        ensure(await page.locator('.workouts-mobile-subheader [data-hierarchy-back]').count() === 1, 'Biblioteca tiene retorno jerárquico');

        await open(page, '/?page=social');
        ensure(await page.locator('.social-hub-screen > .hierarchy-nav-list .hierarchy-nav-row').count() === 3, 'Social muestra tres submenús');
        check(await page.locator('.social-quick-actions a').count() === 4,
            'Social ofrece acciones directas sin vaciar el hub');
        check(await page.locator('.social-dashboard-grid .social-overview-card').count() === 5,
            'Social conserva equipo, competición, comunidad, círculo y actividad');
        check(await page.locator('.social-competition-metrics a').count() === 3,
            'Social resume duelos, competiciones y pendientes');
        check(await page.locator('.social-section-grid .hierarchy-nav-icon svg').count() === 3,
            'Social usa iconos SVG en lugar de entidades visibles');
        check(!(await page.locator('.social-section-grid').innerText()).includes('&#'),
            'Social no imprime códigos HTML como texto');
        check((await page.locator('.social-section-grid').innerText()).includes('Fitness Challenge Team'),
            'Social resume elementos reales del escritorio sin duplicar la navegación');
        check(await page.locator('.social-hub-screen > .hierarchy-page-header > div').evaluate((element) => element.getBoundingClientRect().width) >= 300,
            'cabecera raíz de Social aprovecha el ancho disponible');
        check((await activeMobileHref(page) || '').includes('page=social'), 'Social activo en su hub');
        check(await visibleScreenHeight(page) <= 844 * 2.5, 'Social raíz es compacto');
        await noOverflow(page, 'Social sin overflow horizontal');
        await page.screenshot({ path: path.join(reportDir, 'ui-v2-social-mobile.png'), fullPage: true });
        await open(page, '/?page=social&section=community');
        ensure(await page.locator('.social-hub-screen [data-hierarchy-back]').count() === 1, 'sección Social tiene Volver');

        await open(page, '/?page=analytics');
        check((await activeMobileHref(page) || '').includes('page=dashboard'), 'Analytics pertenece a Inicio');
        ensure(await page.locator('.analytics-mobile-root .hierarchy-nav-row').count() === 5, 'Analytics ofrece cinco secciones');
        check(await page.locator('.analytics-page > .mobile-widget-feed-head:visible').count() === 1,
            'Analytics presenta su feed completo tras el resumen');
        check(await page.locator('.analytics-section:not(.analytics-summary-section):visible').count() >= 4,
            'Analytics móvil mantiene sus secciones de datos');
        await page.screenshot({ path: path.join(reportDir, 'ui-v2-analytics-mobile.png'), fullPage: true });

        await open(page, '/?page=team');
        check((await activeMobileHref(page) || '').includes('page=social'), 'Team pertenece a Social');
        check(await page.locator('.team-mobile-root .hierarchy-nav-row').count() >= 5, 'Team ofrece navegación por secciones');
        check(await page.locator('.team-desktop-root .mobile-widget-feed-head:visible').count() === 1,
            'Team presenta sus widgets después del menú');
        check(await page.locator('.team-layout-grid > [data-team-widget]:visible').count() >= 4,
            'Team móvil conserva los widgets de escritorio');
        const teamChartDensity = await page.evaluate(() => {
            const carousel = document.querySelector('.team-widget-daily-charts');
            const canvas = document.querySelector('.team-cumulative-chart-card canvas');
            const carouselStyle = carousel ? getComputedStyle(carousel) : null;
            const canvasRect = canvas?.getBoundingClientRect();
            return {
                charts: document.querySelectorAll('.team-layout-grid canvas').length,
                horizontal: Boolean(carousel && carousel.scrollWidth > carousel.clientWidth
                    && carouselStyle?.overflowX === 'auto'),
                maxChartHeight: Math.round(canvasRect?.height || 0),
            };
        });
        check(teamChartDensity.charts >= 5 && teamChartDensity.horizontal
            && teamChartDensity.maxChartHeight <= 150,
            'Team conserva sus gráficas en carruseles compactos', JSON.stringify(teamChartDensity));
        await page.screenshot({ path: path.join(reportDir, 'ui-v2-team-mobile.png'), fullPage: true });

        await open(page, '/?page=profile');
        check((await activeMobileHref(page) || '').includes('page=profile'), 'Perfil activo');
        check(await page.locator('.profile-mobile-root .hierarchy-nav-row').count() >= 4, 'Perfil ofrece navegación por secciones');
        check(await page.locator('.profile-hierarchy-screen > .mobile-widget-feed-head:visible').count() === 1,
            'Perfil presenta sus bloques configurables después del menú');
        check(await page.locator('.profile-home-grid > [data-profile-block]:visible').count() >= 4,
            'Perfil móvil conserva sus bloques de escritorio');
        await page.screenshot({ path: path.join(reportDir, 'ui-v2-profile-mobile.png'), fullPage: true });
        await open(page, '/?page=settings');
        check((await activeMobileHref(page) || '').includes('page=profile'), 'Settings pertenece a Perfil');
        check(await page.locator('.settings-index-screen > .hierarchy-page-header-root').count() === 1,
            'Ajustes usa cabecera compacta de app');
        check(await page.locator('.settings-nav-item').count() === 7, 'Ajustes conserva todas sus secciones');
        await open(page, '/?page=settings&view=preferences');
        check(await page.locator('.settings-preference-group').count() === 3,
            'Preferencias separa idioma, objetivos y apariencia');
        await open(page, '/?page=settings&view=body');
        check(await page.locator('.settings-weight-summary > span').count() === 3
            && await page.locator('.settings-weight-history').count() === 1,
            'Peso muestra resumen, objetivo e historial real');

        await open(page, '/?page=social');
        await page.evaluate(() => {
            document.body.dataset.theme = 'dark';
            document.body.classList.add('theme-active-dark');
        });
        const darkSurface = await page.locator('.social-hub-screen .hierarchy-nav-row').first().evaluate((element) => getComputedStyle(element).backgroundColor);
        check(Boolean(darkSurface) && darkSurface !== 'rgba(0, 0, 0, 0)', 'tema oscuro conserva superficies jerárquicas', darkSurface);

        for (const width of [320, 360, 390, 430, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: width < 900 ? 844 : 900 });
            await open(page, '/?page=dashboard');
            await noOverflow(page, `sin overflow a ${width}px`);
        }

        check(serverErrors.length === 0, 'sin respuestas 5xx', serverErrors.join(' | '));
        check(runtimeErrors.length === 0, 'sin errores JavaScript', runtimeErrors.join(' | '));
    } catch (error) {
        failures.push(error.stack || error.message);
    } finally {
        await browser.close();
    }

    if (failures.length > 0) {
        console.error(`\n${failures.length} fallo(s):\n- ${failures.join('\n- ')}`);
        process.exit(1);
    }
    console.log('\nJerarquía móvil v2 validada.');
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
