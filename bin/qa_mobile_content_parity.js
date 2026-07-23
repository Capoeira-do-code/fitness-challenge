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
            const integratedDashboardKpis = entry.name === 'Inicio'
                && mobileCount === desktopCounts[entry.name] - 1
                && await countVisible(page, '.dashboard-mobile-home [data-dashboard-mobile-surface="mobile_today"] a[href*="metric="]') >= 1;
            check(mobileCount === desktopCounts[entry.name] || integratedDashboardKpis,
                `${entry.name}: móvil conserva todos los módulos o su equivalente integrado`,
                `${mobileCount}/${desktopCounts[entry.name]}${integratedDashboardKpis ? ' + KPIs integrados' : ''}`);
            check(await countVisible(page, entry.feed) === 1,
                `${entry.name}: cabecera separa navegación y widgets`);
            const overflow = await overflowState(page);
            check(overflow.content <= overflow.viewport + 1,
                `${entry.name}: sin overflow horizontal`,
                `${overflow.content}/${overflow.viewport}px ${overflow.offenders.join(' | ')}`);
            await page.screenshot({ path: path.join(REPORT_DIR, entry.screenshot), fullPage: true });
        }

        await open(page, '/?page=dashboard');
        const mobileKpis = await countVisible(page, '.dashboard-mobile-home .mobile-today-card a[href*="metric="]');
        const desktopKpis = await page.locator('.dashboard-layout [data-dashboard-widget="kpis"] .metric-card').count();
        check(mobileKpis === desktopKpis && mobileKpis > 0,
            'Inicio no recorta los indicadores diarios', `${mobileKpis}/${desktopKpis}`);
        const initialDashboardPanels = await page.locator('[data-dashboard-collapsible]').evaluateAll((panels) => panels.map((panel) => ({
            name: panel.dataset.dashboardCollapsible || '',
            expanded: panel.dataset.dashboardExpanded || '',
            collapsed: panel.classList.contains('is-collapsed'),
            ariaExpanded: panel.querySelector('[data-dashboard-panel-toggle]')?.getAttribute('aria-expanded') || '',
        })));
        check(initialDashboardPanels.length > 0
                && initialDashboardPanels.every((panel) => panel.expanded === '0'
                    && panel.collapsed
                    && panel.ariaExpanded === 'false'),
            'Los paneles de Inicio empiezan cerrados sin preferencia guardada',
            JSON.stringify(initialDashboardPanels));

        const legacyDashboardStorageKey = await page.evaluate(() => {
            const user = String(document.body.dataset.uiUser || '0');
            const key = `fitness-challenge:ui:v1:${user}:disclosure:dashboard.quests-panel`;
            localStorage.setItem(key, '1');
            return key;
        });
        await page.reload({ waitUntil: 'networkidle' });
        const legacyState = await page.evaluate((key) => ({
            stored: localStorage.getItem(key),
            collapsed: document.querySelector('[data-dashboard-collapsible="dashboard.quests-panel"]')?.classList.contains('is-collapsed'),
        }), legacyDashboardStorageKey);
        check(legacyState.stored === null && legacyState.collapsed === true,
            'Inicio ignora y elimina el estado antiguo del navegador',
            JSON.stringify(legacyState));

        const persistedPanelSelector = '[data-dashboard-collapsible="dashboard.training-progress"]';
        const panelSaveResponse = page.waitForResponse((response) => response.url().includes('page=dashboard_panel_state')
            && response.request().method() === 'POST');
        await page.locator(`${persistedPanelSelector} [data-dashboard-panel-toggle]`).click();
        const savedPanelResponse = await panelSaveResponse;
        const savedPanelPayload = await savedPanelResponse.json().catch(() => null);
        check(savedPanelResponse.ok() && savedPanelPayload?.ok === true && savedPanelPayload?.expanded === true,
            'Abrir un panel guarda su estado en el servidor',
            JSON.stringify(savedPanelPayload));
        await page.reload({ waitUntil: 'networkidle' });
        const reloadedPanelState = await page.locator(persistedPanelSelector).evaluate((panel) => ({
            expanded: panel.dataset.dashboardExpanded,
            collapsed: panel.classList.contains('is-collapsed'),
            ariaExpanded: panel.querySelector('[data-dashboard-panel-toggle]')?.getAttribute('aria-expanded'),
        }));
        check(reloadedPanelState.expanded === '1'
                && !reloadedPanelState.collapsed
                && reloadedPanelState.ariaExpanded === 'true',
            'El panel conserva desde la base de datos su estado tras recargar',
            JSON.stringify(reloadedPanelState));
        const dashboardDisclosureLocalState = await page.evaluate(() => Object.keys(localStorage)
            .filter((key) => key.includes(':disclosure:dashboard.')));
        check(dashboardDisclosureLocalState.length === 0,
            'Los paneles de Inicio no guardan estado en localStorage',
            JSON.stringify(dashboardDisclosureLocalState));

        const collapsedPanelNames = await page.locator('[data-dashboard-collapsible].is-collapsed').evaluateAll(
            (panels) => panels.map((panel) => panel.dataset.dashboardCollapsible || '').filter(Boolean)
        );
        for (const panelName of collapsedPanelNames) {
            const responsePromise = page.waitForResponse((response) => response.url().includes('page=dashboard_panel_state')
                && response.request().method() === 'POST');
            await page.locator(`[data-dashboard-collapsible="${panelName}"] [data-dashboard-panel-toggle]`).click();
            const response = await responsePromise;
            check(response.ok(), `Inicio guarda el panel abierto: ${panelName}`);
        }
        const rankDisclosure = await page.evaluate(() => {
            const panel = document.querySelector('.dashboard-training-rank[data-dashboard-collapsible]');
            const head = panel?.querySelector(':scope > .panel-head');
            const action = head?.querySelector('.dashboard-panel-action');
            const summary = panel?.querySelector(':scope > .dashboard-training-rank-summary');
            const board = panel?.querySelector(':scope > .dashboard-training-board');
            if (!panel || !head || !action || !summary || !board) return null;
            const panelStyle = getComputedStyle(panel);
            const actionRect = action.getBoundingClientRect();
            const headRect = head.getBoundingClientRect();
            return {
                actionFits: action.scrollWidth <= action.clientWidth + 1
                    && actionRect.left >= headRect.left - 1
                    && actionRect.right <= headRect.right + 1,
                panelBackground: panelStyle.backgroundColor,
                summaryBackground: getComputedStyle(summary).backgroundColor,
                boardBackground: getComputedStyle(board).backgroundColor,
                gap: panelStyle.rowGap,
            };
        });
        check(Boolean(rankDisclosure?.actionFits),
            'Inicio muestra Ver todos completo dentro de la cabecera de rango',
            JSON.stringify(rankDisclosure));
        check(Boolean(rankDisclosure)
                && rankDisclosure.panelBackground === rankDisclosure.summaryBackground
                && rankDisclosure.panelBackground === rankDisclosure.boardBackground
                && Number.parseFloat(rankDisclosure.gap) === 0,
            'El panel de rango usa una única superficie continua',
            JSON.stringify(rankDisclosure));
        const disclosurePanels = await page.evaluate(() => {
            const definitions = [
                ['progreso de entrenamiento', '.dashboard-training-progress'],
                ['misiones', '.quests-widget'],
                ['temporada', '.season-widget'],
                ['progreso de logros', '.dashboard-achievement-progress-panel'],
                ['duelos', '.dashboard-duels-card'],
                ['competiciones', '.dashboard-competitions-card'],
                ['ranking del reto', '.dashboard-ranking-panel'],
                ['histórico semanal', '.dashboard-weekly-history'],
            ];
            return definitions.map(([name, selector]) => {
                const panel = document.querySelector(`${selector}[data-dashboard-collapsible]`);
                const head = panel?.querySelector(':scope > .panel-head');
                const content = panel
                    ? [...panel.children].find((child) => !child.matches('.panel-head, [data-layout-card-controls]'))
                    : null;
                const actions = head
                    ? [...head.querySelectorAll('.dashboard-panel-head-actions > :not(.dashboard-panel-collapse-toggle)')]
                    : [];
                if (!panel || !head || !content) {
                    return { name, found: false };
                }
                const panelStyle = getComputedStyle(panel);
                const headRect = head.getBoundingClientRect();
                return {
                    name,
                    found: true,
                    background: panelStyle.backgroundColor,
                    gap: panelStyle.rowGap,
                    radius: panelStyle.borderTopLeftRadius,
                    border: panelStyle.borderTopWidth,
                    clipsContents: panelStyle.overflowX === 'hidden'
                        && panelStyle.overflowY === 'hidden',
                    actionsFit: actions.every((action) => {
                        const actionRect = action.getBoundingClientRect();
                        return action.scrollWidth <= action.clientWidth + 1
                            && actionRect.left >= headRect.left - 1
                            && actionRect.right <= headRect.right + 1;
                    }),
                };
            });
        });
        check(disclosurePanels.length === 8
                && disclosurePanels.every((panel) => panel.found
                    && panel.background !== 'rgba(0, 0, 0, 0)'
                    && Number.parseFloat(panel.gap) === 0
                    && Number.parseFloat(panel.radius) > 0
                    && Number.parseFloat(panel.border) > 0
                    && panel.clipsContents),
            'Los ocho desplegables restantes usan una superficie continua',
            JSON.stringify(disclosurePanels));
        check(disclosurePanels.every((panel) => panel.found && panel.actionsFit),
            'Las acciones de los ocho desplegables se muestran completas',
            JSON.stringify(disclosurePanels));
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

        const profileDisclosures = await page.evaluate(() => [...document.querySelectorAll('[data-profile-collapsible]')]
            .filter((panel) => panel.getClientRects().length > 0)
            .map((panel) => {
                const head = panel.querySelector(':scope > :is(.profile-home-card-head, .panel-head)');
                const toggle = head?.querySelector('[data-profile-panel-toggle]');
                const actions = head
                    ? [...head.querySelectorAll('.profile-panel-head-actions > :not(.profile-panel-collapse-toggle), .inline-actions-mini > :not(.profile-panel-collapse-toggle)')]
                        .filter((action) => action.getClientRects().length > 0)
                    : [];
                if (!head || !toggle) return { found: false };
                const headRect = head.getBoundingClientRect();
                const toggleRect = toggle.getBoundingClientRect();
                const panelStyle = getComputedStyle(panel);
                return {
                    found: true,
                    columns: getComputedStyle(head).gridTemplateColumns.split(' ').filter(Boolean).length,
                    headHeight: headRect.height,
                    toggleWidth: toggleRect.width,
                    toggleHeight: toggleRect.height,
                    continuous: Number.parseFloat(panelStyle.rowGap) === 0
                        && panelStyle.overflowX === 'hidden'
                        && panelStyle.backgroundColor !== 'rgba(0, 0, 0, 0)',
                    actionsFit: actions.every((action) => {
                        const rect = action.getBoundingClientRect();
                        return action.scrollWidth <= action.clientWidth + 1
                            && rect.left >= headRect.left - 1
                            && rect.right <= headRect.right + 1;
                    }),
                };
            }));
        check(profileDisclosures.length > 0
                && profileDisclosures.every((panel) => panel.found
                    && panel.columns === 3
                    && panel.headHeight >= 58
                    && panel.toggleWidth >= 44
                    && panel.toggleHeight >= 44
                    && panel.continuous),
            'Perfil reutiliza la estructura de desplegable de Inicio',
            JSON.stringify(profileDisclosures));
        check(profileDisclosures.every((panel) => panel.found && panel.actionsFit),
            'Perfil muestra completas las acciones de sus cabeceras',
            JSON.stringify(profileDisclosures));

        await page.locator('[data-profile-collapsible]:visible').evaluateAll((panels) => {
            panels.forEach((panel) => {
                if (!panel.classList.contains('is-collapsed')) {
                    panel.querySelector('[data-profile-panel-toggle]')?.click();
                }
            });
        });
        const collapsedProfileActions = await page.locator('[data-profile-collapsible]:visible').evaluateAll((panels) => panels.map((panel) => ({
            collapsed: panel.classList.contains('is-collapsed'),
            visibleActions: [...panel.querySelectorAll('.profile-panel-head-actions > :not(.profile-panel-collapse-toggle), .inline-actions-mini > :not(.profile-panel-collapse-toggle)')]
                .filter((action) => action.getClientRects().length > 0).length,
            ariaExpanded: panel.querySelector('[data-profile-panel-toggle]')?.getAttribute('aria-expanded') || '',
        })));
        check(collapsedProfileActions.length > 0
                && collapsedProfileActions.every((panel) => panel.collapsed
                    && panel.visibleActions === 0
                    && panel.ariaExpanded === 'false'),
            'Perfil cerrado deja una cabecera limpia con un solo chevron',
            JSON.stringify(collapsedProfileActions));

        await open(page, '/?page=dashboard');
        const plus = page.locator('.mobile-liquid-nav .liquid-nav-plus');
        await plus.locator(':scope > summary').click();
        await plus.locator('[data-menu-view="main"] > [data-menu-open]').first().waitFor({ state: 'visible' });
        const quickState = await plus.locator('[data-menu-view="main"]').evaluate((view) => {
            const actions = [...view.querySelectorAll(':scope > [data-menu-open]')];
            const panel = view.closest('.mobile-quick-sheet');
            const rect = panel?.getBoundingClientRect();
            return {
                actions: actions.length,
                minHeight: Math.min(...actions.map((action) => action.getBoundingClientRect().height)),
                centered: Boolean(rect && Math.abs((rect.left + rect.width / 2) - innerWidth / 2) <= 2),
                duplicated: view.querySelectorAll('.mobile-quick-featured').length,
            };
        });
        check(quickState.actions === 2 && quickState.minHeight >= 44 && quickState.centered && quickState.duplicated === 0,
            'El menú + centra una jerarquía táctil sin acciones duplicadas', JSON.stringify(quickState));
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
