/**
 * Cross-module spacing and widget typography contract.
 *
 * Run against a disposable fixture:
 *   node bin/qa_widget_consistency.js --base http://127.0.0.1:8099 --password ChangeMe123!
 */

const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8123').replace(/\/$/, '');
const PASSWORD = arg('password', 'Verify123!');

const checks = [];
const check = (name, condition, detail = '') => {
    const pass = Boolean(condition);
    checks.push({ name, pass, detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` - ${detail}` : ''}`);
};

const routes = [
    ['dashboard', '/?page=dashboard'],
    ['entries-data', '/?page=entries&mode=data'],
    ['entries-meal', '/?page=entries&mode=meal'],
    ['week-editor', '/?page=week_editor&range=week'],
    ['summary', '/?page=table&range=week'],
    ['analytics', '/?page=analytics'],
    ['gallery', '/?page=gallery'],
    ['social', '/?page=social'],
    ['profile', '/?page=profile'],
    ['profile-goals', '/?page=profile&section=goals'],
    ['friends', '/?page=friends'],
    ['duels', '/?page=duels'],
    ['competitions', '/?page=competitions'],
    ['team', '/?page=team'],
    ['workouts', '/?page=workouts'],
    ['workouts-stats', '/?page=workouts&view=stats'],
    ['achievements', '/?page=achievements'],
    ['quests', '/?page=quests'],
    ['challenges', '/?page=challenges'],
    ['settings', '/?page=settings'],
    ['settings-notifications', '/?page=settings&view=notifications'],
    ['notifications', '/?page=notifications'],
    ['penalties', '/?page=penalties'],
    ['admin', '/?page=admin'],
    ['admin-training', '/?page=admin&group=training'],
    ['admin-section', '/?page=admin&section=training'],
    ['team-settings', '/?page=team_settings&team_id=1'],
    ['team-settings-members', '/?page=team_settings&team_id=1&section=members'],
];

const layoutSelector = [
    '.screen.stack-lg',
    '.dashboard-layout',
    '.analytics-grid',
    '.profile-home-grid',
    '.profile-data-grid',
    '.social-dashboard-grid',
    '.team-layout-grid',
    '.settings-nav-grid',
    '.team-settings-nav-grid',
    '.admin-group-grid',
    '.metric-grid',
    '.grid-two',
    '.duels-columns',
    '.workouts-start-grid',
    '.achievement-grid',
    '.achievement-page-grid',
    '.achievement-summary-strip',
    '.hierarchy-nav-list',
    '.spa-shell .admin-section-list-menu',
    '.stack.entry-data-form',
].join(',');

const headingSelector = [
    '.panel > h2',
    '.panel > h3',
    '.panel > .panel-head h2',
    '.panel > .panel-head h3',
    '.dashboard-layout > [data-dashboard-widget] h2',
    '.dashboard-layout > [data-dashboard-widget] h3',
    '.analytics-layout-item > .analytics-section-title h2',
    '.analytics-chart-card h3',
    '.profile-home-grid > [data-profile-block] h2',
    '.social-dashboard-grid > .social-overview-card h2',
    '.team-layout-grid > [data-team-widget] h2',
    '.workouts-screen > .panel h2',
    '.achievements-page-panel .panel-head h2',
    '.mobile-widget-feed-head h2',
].join(',');

const menuTitleSelector = [
    '.hierarchy-nav-copy strong',
    '.settings-nav-copy strong',
    '.admin-section-list-menu .settings-row > span:first-child',
].join(',');

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    if (!page.url().includes('page=login')) return;
    await page.fill('input[name="username"]', 'roberto');
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const pageErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            pageErrors.push(message.text());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);
        const audit = [];
        for (const viewport of [
            { name: 'mobile-320', width: 320, height: 844, expectedGap: 12 },
            { name: 'mobile-390', width: 390, height: 844, expectedGap: 12 },
            { name: 'tablet-768', width: 768, height: 1024, expectedGap: 12 },
            { name: 'desktop-1280', width: 1280, height: 900, expectedGap: 16 },
            { name: 'desktop-1440', width: 1440, height: 1000, expectedGap: 16 },
        ]) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            for (const [route, url] of routes) {
                let response = null;
                for (let attempt = 0; attempt < 2; attempt += 1) {
                    response = await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                    const ready = await page.evaluate(() => document.body.dataset.page !== undefined
                        && getComputedStyle(document.body).fontFamily.includes('Manrope'));
                    if (ready) break;
                    await page.waitForTimeout(120);
                }
                const state = await page.evaluate(({ layouts, headings, menuTitles }) => {
                    const visible = (node) => {
                        if (!(node instanceof HTMLElement)) return false;
                        const style = getComputedStyle(node);
                        const rect = node.getBoundingClientRect();
                        return style.display !== 'none' && style.visibility !== 'hidden'
                            && Number.parseFloat(style.opacity || '1') > 0.01
                            && rect.width > 0 && rect.height > 0;
                    };
                    const layoutRows = [...document.querySelectorAll(layouts)]
                        .filter(visible)
                        .map((node) => ({
                            className: String(node.className || node.tagName),
                            gap: Number.parseFloat(getComputedStyle(node).gap),
                        }));
                    const headingRows = [...new Set([...document.querySelectorAll(headings)].filter(visible))]
                        .map((node) => {
                            const style = getComputedStyle(node);
                            return {
                                text: node.textContent.trim().replace(/\s+/g, ' ').slice(0, 60),
                                fontFamily: style.fontFamily,
                                fontSize: Number.parseFloat(style.fontSize),
                                fontWeight: Number.parseInt(style.fontWeight, 10),
                                lineHeight: Number.parseFloat(style.lineHeight),
                            };
                        });
                    const menuTitleRows = [...new Set([...document.querySelectorAll(menuTitles)].filter(visible))]
                        .map((node) => {
                            const style = getComputedStyle(node);
                            return {
                                text: node.textContent.trim().replace(/\s+/g, ' ').slice(0, 60),
                                fontFamily: style.fontFamily,
                                fontSize: Number.parseFloat(style.fontSize),
                                fontWeight: Number.parseInt(style.fontWeight, 10),
                                lineHeight: Number.parseFloat(style.lineHeight),
                            };
                        });
                    return {
                        bodyFont: getComputedStyle(document.body).fontFamily,
                        layouts: layoutRows,
                        headings: headingRows,
                        menuTitles: menuTitleRows,
                        overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - innerWidth,
                    };
                }, { layouts: layoutSelector, headings: headingSelector, menuTitles: menuTitleSelector });
                audit.push({ viewport: viewport.name, route, status: response?.status() || 0, expectedGap: viewport.expectedGap, ...state });
            }
        }

        const badResponses = audit.filter((item) => item.status === 0 || item.status >= 400);
        const badLayouts = audit.flatMap((item) => item.layouts
            .filter((layout) => layout.gap !== item.expectedGap)
            .map((layout) => ({ viewport: item.viewport, route: item.route, expected: item.expectedGap, ...layout })));
        const badHeadings = audit.flatMap((item) => item.headings
            .filter((heading) => heading.fontSize !== 16 || heading.lineHeight !== 20
                || heading.fontWeight < 700 || !heading.fontFamily.startsWith('"Plus Jakarta Sans"'))
            .map((heading) => ({ viewport: item.viewport, route: item.route, ...heading })));
        const badBodyFonts = audit.filter((item) => !item.bodyFont.startsWith('Manrope'))
            .map((item) => ({ viewport: item.viewport, route: item.route, bodyFont: item.bodyFont }));
        const badMenuTitles = audit.flatMap((item) => item.menuTitles
            .filter((title) => title.fontSize !== 14 || title.fontWeight < 700
                || !title.fontFamily.startsWith('"Plus Jakarta Sans"'))
            .map((title) => ({ viewport: item.viewport, route: item.route, ...title })));
        const overflows = audit.filter((item) => item.overflow > 1)
            .map((item) => ({ viewport: item.viewport, route: item.route, overflow: item.overflow }));
        const headingCounts = new Map();
        for (const item of audit) {
            if (!['dashboard', 'analytics', 'profile', 'social', 'team', 'workouts'].includes(item.route)) continue;
            headingCounts.set(item.route, (headingCounts.get(item.route) || 0) + item.headings.length);
        }
        const missingHeadingRoutes = [...headingCounts].filter(([, count]) => count === 0);

        check('all audited routes respond', badResponses.length === 0, JSON.stringify(badResponses));
        check('widget grids share 12px mobile and 16px wider rhythm', badLayouts.length === 0, JSON.stringify(badLayouts));
        check('widget titles share font, 16px size, weight and line height', badHeadings.length === 0, JSON.stringify(badHeadings));
        check('all core modules expose audited widget titles', missingHeadingRoutes.length === 0, JSON.stringify(missingHeadingRoutes));
        check('body copy uses the shared Manrope stack', badBodyFonts.length === 0, JSON.stringify(badBodyFonts));
        check('navigation-card titles share the 14px heading style', badMenuTitles.length === 0, JSON.stringify(badMenuTitles));
        check('no page-level horizontal overflow', overflows.length === 0, JSON.stringify(overflows));
        check('no JavaScript errors', pageErrors.length === 0, pageErrors.join(' | '));
        check('no HTTP 5xx responses', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('widget consistency audit completes', false, error.stack || error.message);
    } finally {
        await context.close();
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} widget consistency checks passed.`);
    if (failed.length) process.exitCode = 1;
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
