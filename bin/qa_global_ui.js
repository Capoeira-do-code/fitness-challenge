/**
 * Cross-page UI system QA for authenticated mobile and desktop routes.
 * Run only against the disposable QA database created by qa_workouts.php.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8123').replace(/\/$/, '');
const PASSWORD = value('password', 'Verify123!');
const reportDir = path.join(__dirname, '..', 'e2e-report');
const checks = [];

const routes = [
    ['inicio', 'dashboard'],
    ['registro', 'entries&mode=data'],
    ['comida', 'entries&mode=meal'],
    ['editor-semana', 'week_editor&range=week'],
    ['resumen-semana', 'table&range=week'],
    ['analytics', 'analytics'],
    ['galeria', 'gallery'],
    ['social', 'social'],
    ['perfil', 'profile'],
    ['amigos', 'friends'],
    ['duelos', 'duels'],
    ['competiciones', 'competitions'],
    ['entreno', 'workouts'],
    ['equipo', 'team'],
    ['logros', 'achievements'],
    ['misiones', 'quests'],
    ['retos', 'challenges'],
    ['ajustes', 'settings'],
    ['notificaciones', 'notifications'],
    ['penalizaciones', 'penalties'],
    ['administracion', 'admin'],
    ['administracion-entreno', 'admin&group=training'],
    ['administracion-ranked', 'admin&section=training'],
    ['ajustes-equipo', 'team_settings&team_id=1'],
    ['ajustes-equipo-general', 'team_settings&team_id=1&section=general'],
    ['ajustes-equipo-miembros', 'team_settings&team_id=1&section=members'],
    ['ajustes-equipo-solicitudes', 'team_settings&team_id=1&section=requests'],
    ['ajustes-equipo-seguridad', 'team_settings&team_id=1&section=danger'],
];
const captureRoutes = new Set([
    'resumen-semana', 'amigos', 'duelos', 'competiciones', 'retos',
    'penalizaciones', 'administracion', 'administracion-entreno', 'administracion-ranked',
    'ajustes-equipo', 'ajustes-equipo-general', 'ajustes-equipo-miembros',
]);

const check = (name, pass, detail = '') => {
    checks.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
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

(async () => {
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const pageErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('console', (message) => {
        const source = message.location().url || '';
        const text = message.text();
        if (message.type() === 'error' && source.startsWith(BASE) && !text.startsWith('Failed to load resource:')) {
            pageErrors.push(text);
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);
        const audit = [];
        for (const viewport of [
            { name: 'mobile', width: 390, height: 844 },
            { name: 'desktop', width: 1280, height: 900 },
        ]) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            for (const [name, query] of routes) {
                const response = await page.goto(`${BASE}/?page=${query}`, { waitUntil: 'networkidle' });
                const state = await page.evaluate(() => {
                    const visible = (node) => {
                        if (!(node instanceof HTMLElement)) return false;
                        const style = getComputedStyle(node);
                        const rect = node.getBoundingClientRect();
                        return style.display !== 'none' && style.visibility !== 'hidden'
                            && Number.parseFloat(style.opacity || '1') > 0.01
                            && rect.width > 0 && rect.height > 0;
                    };
                    const main = document.querySelector('main');
                    const screen = main?.querySelector('.screen');
                    const headings = [...(screen || main || document).querySelectorAll('h1')].filter(visible);
                    const topbarTitle = [...document.querySelectorAll('.topbar-page-title')].find(visible);
                    const hero = screen?.querySelector(':scope > .app-page-hero');
                    const actions = [...document.querySelectorAll('.btn, button, summary, select, textarea, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])')]
                        .filter(visible)
                        .map((node) => {
                            const rect = node.getBoundingClientRect();
                            return {
                                tag: node.tagName.toLowerCase(),
                                cls: String(node.className || '').split(/\s+/).slice(0, 2).join('.'),
                                width: Math.round(rect.width),
                                height: Math.round(rect.height),
                            };
                        });
                    const undersized = actions.filter((item) => item.width < 44 || item.height < 44);
                    const metricGrid = screen?.matches('.training-summary-screen') ? screen.querySelector('.metric-grid') : null;
                    const metricColumns = metricGrid instanceof HTMLElement
                        ? getComputedStyle(metricGrid).gridTemplateColumns.split(' ').filter(Boolean).length
                        : 0;
                    return {
                        title: headings[0]?.textContent?.trim() || topbarTitle?.textContent?.trim() || document.title.trim(),
                        headings: headings.length,
                        heroHeight: hero instanceof HTMLElement ? Math.round(hero.getBoundingClientRect().height) : 0,
                        panels: screen?.querySelectorAll('.panel').length || 0,
                        tables: screen?.querySelectorAll('table').length || 0,
                        forms: screen?.querySelectorAll('form').length || 0,
                        adminGroups: screen?.querySelectorAll('.admin-group-grid > .settings-nav-item').length || 0,
                        teamSettingsSections: screen?.querySelectorAll('.team-settings-nav-grid > .settings-nav-item').length || 0,
                        activeAdminSections: [...(screen?.querySelectorAll('[data-spa-section]') || [])].filter(visible).length,
                        metricColumns,
                        overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth,
                        undersized: undersized.slice(0, 8),
                    };
                });
                audit.push({ viewport: viewport.name, name, status: response?.status() || 0, ...state });
                if (captureRoutes.has(name)) {
                    await page.screenshot({
                        path: path.join(reportDir, `ui-global-${name}-${viewport.name}.png`),
                        fullPage: true,
                    });
                }
            }
        }

        const routeFailures = audit.filter((item) => item.status >= 400 || item.title === '' || item.headings > 1 || item.overflow > 1);
        check('todas las rutas tienen una cabecera única, responden y no desbordan', routeFailures.length === 0, JSON.stringify(routeFailures));
        const mobileAudit = audit.filter((item) => item.viewport === 'mobile');
        const tallHeroes = mobileAudit.filter((item) => item.heroHeight > 112);
        check('las cabeceras móviles no vuelven al hero antiguo', tallHeroes.length === 0, JSON.stringify(tallHeroes));
        const undersizedRoutes = mobileAudit.filter((item) => item.undersized.length > 0)
            .map((item) => ({ name: item.name, controls: item.undersized }));
        check('los controles primarios móviles alcanzan 44 px', undersizedRoutes.length === 0, JSON.stringify(undersizedRoutes));
        const adminRoot = mobileAudit.find((item) => item.name === 'administracion');
        const teamSettingsRoot = mobileAudit.find((item) => item.name === 'ajustes-equipo');
        const adminSection = mobileAudit.find((item) => item.name === 'administracion-ranked');
        const summary = mobileAudit.find((item) => item.name === 'resumen-semana');
        check('administracion y equipo usan hubs jerarquicos', adminRoot?.adminGroups === 4 && teamSettingsRoot?.teamSettingsSections === 4 && adminSection?.activeAdminSections === 1, JSON.stringify({ adminRoot, teamSettingsRoot, adminSection }));
        check('el resumen semanal mantiene dos metricas por fila', summary?.metricColumns === 2, JSON.stringify(summary));
        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));

        console.log('\nAUDIT ' + JSON.stringify(audit));
    } catch (error) {
        check('auditoría global completa', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones globales correctas.`);
    if (failed.length) process.exitCode = 1;
})();
