/**
 * Focused browser regression for Dashboard training widgets and the compact
 * Workouts header. Run only against a throwaway database.
 *
 *   node bin/qa_dashboard_workouts.js --base http://127.0.0.1:8116
 */

const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8116').replace(/\/$/, '');
const USERNAME = arg('username', 'roberto');
const PASSWORD = arg('password', 'Verify123!');
const REPORT_DIR = path.join(__dirname, '..', 'e2e-report');

const results = [];
const check = (name, pass, detail = '') => {
    results.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(name, condition, detail);
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
    ensure(!page.url().includes('page=login'), 'QA login');
};
const noOverflow = (page) => page.evaluate(() =>
    Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) <= innerWidth + 1);

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const runtimeErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') runtimeErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);

        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        const dashboardState = await page.evaluate(() => {
            const rank = document.querySelector('[data-dashboard-widget="training_rank"]');
            const progress = document.querySelector('[data-dashboard-widget="training_progress"]');
            return {
                rank: Boolean(rank),
                progress: Boolean(progress),
                rankKey: rank?.getAttribute('data-rank') || '',
                boardRows: rank?.querySelectorAll('.dashboard-training-board-row').length || 0,
                selectedRows: rank?.querySelectorAll('.dashboard-training-board-row.is-selected').length || 0,
                progressMetrics: progress?.querySelectorAll('.dashboard-training-progress-grid > span').length || 0,
                challengeHeading: [...document.querySelectorAll('[data-dashboard-widget="ranking"] h2')]
                    .some((heading) => /reto|challenge/i.test(heading.textContent || '')),
            };
        });
        check('Dashboard exposes separate rank and progress widgets',
            dashboardState.rank && dashboardState.progress && dashboardState.challengeHeading,
            JSON.stringify(dashboardState));
        check('Training rank uses real strength data and team rows',
            dashboardState.rankKey !== '' && dashboardState.rankKey !== 'unranked'
                && dashboardState.boardRows >= 2 && dashboardState.selectedRows === 1,
            `${dashboardState.rankKey}, ${dashboardState.boardRows} rows`);
        check('Training progress stays a four-metric snapshot', dashboardState.progressMetrics === 4,
            `${dashboardState.progressMetrics} metrics`);
        check('Dashboard mobile has no horizontal overflow', await noOverflow(page));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-dashboard-training-widgets-mobile.png'), fullPage: true });

        /* New widgets participate in the existing persistence contract. */
        await page.goto(`${BASE}/?page=dashboard&layout_edit=1`, { waitUntil: 'networkidle' });
        const rankToggle = page.locator('input[name="dashboard_widgets[]"][value="training_rank"]');
        const progressToggle = page.locator('input[name="dashboard_widgets[]"][value="training_progress"]');
        check('Layout editor lists both new widgets',
            await rankToggle.count() === 1 && await progressToggle.count() === 1
                && await rankToggle.isChecked() && await progressToggle.isChecked());
        await progressToggle.evaluate((input) => {
            input.checked = false;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.locator('#dashboard-layout-edit-form .dashboard-editbar-actions button[type="submit"]').click(),
        ]);
        check('Hidden training widget persists while rank remains visible',
            await page.locator('[data-dashboard-widget="training_progress"]').count() === 0
                && await page.locator('[data-dashboard-widget="training_rank"]').count() === 1);
        await page.goto(`${BASE}/?page=dashboard&layout_edit=1`, { waitUntil: 'networkidle' });
        await page.locator('input[name="dashboard_widgets[]"][value="training_progress"]').evaluate((input) => {
            input.checked = true;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.locator('#dashboard-layout-edit-form .dashboard-editbar-actions button[type="submit"]').click(),
        ]);
        check('Training progress widget can be restored',
            await page.locator('[data-dashboard-widget="training_progress"]').count() === 1);
        await page.goto(`${BASE}/?page=dashboard&layout_edit=1`, { waitUntil: 'networkidle' });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('#dashboard-layout-edit-form .dashboard-editbar-reset').evaluate((button) => button.click()),
        ]);
        check('Dashboard layout reset keeps both new defaults',
            await page.locator('[data-dashboard-widget="training_rank"]').count() === 1
                && await page.locator('[data-dashboard-widget="training_progress"]').count() === 1);

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const compactState = await page.evaluate(() => {
            const hero = document.querySelector('.workouts-hero');
            const summary = document.querySelector('.workouts-overview-summary');
            const sectionGrid = document.querySelector('.workouts-section-grid');
            const summaryRect = summary?.getBoundingClientRect();
            const heroRect = hero?.getBoundingClientRect();
            const sectionRect = sectionGrid?.getBoundingClientRect();
            return {
                heroVisible: Boolean(hero && getComputedStyle(hero).display !== 'none'),
                destinations: sectionGrid?.querySelectorAll('.hierarchy-nav-row').length || 0,
                sectionHeight: Math.round(sectionRect?.height || 0),
                strips: summary?.querySelectorAll('.workouts-overview-kpi-strip').length || 0,
                metrics: summary?.querySelectorAll('.workouts-overview-kpi-strip > span').length || 0,
                summaryHeight: Math.round(summaryRect?.height || 0),
                topFootprint: Math.round((summaryRect?.bottom || 0) - (sectionRect?.top || (heroRect?.height ? heroRect.top : 0))),
                summaryColumns: summary?.querySelector('.workouts-overview-kpi-strip')
                    ? getComputedStyle(summary.querySelector('.workouts-overview-kpi-strip')).gridTemplateColumns.split(' ').length : 0,
            };
        });
        check('Workout summary consolidates four values into one compact strip',
            compactState.strips === 1 && compactState.metrics === 4 && compactState.summaryColumns === 4,
            JSON.stringify(compactState));
        check('Workout root uses one compact hierarchical grid',
            !compactState.heroVisible && compactState.destinations === 5 && compactState.sectionHeight <= 400
                && compactState.summaryHeight <= 150 && compactState.topFootprint <= 620,
            `${compactState.topFootprint}px total · grid ${compactState.sectionHeight}px · summary ${compactState.summaryHeight}px`);
        check('Workout overview mobile has no horizontal overflow', await noOverflow(page));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-workout-overview-compact-mobile.png'), fullPage: true });

        const responsiveProblems = [];
        for (const width of [320, 360, 390, 430]) {
            await page.setViewportSize({ width, height: 760 });
            for (const view of ['overview', 'plan', 'library', 'ranks', 'stats']) {
                const url = `${BASE}/?page=workouts${view === 'overview' ? '' : `&view=${view}`}`;
                await page.goto(url, { waitUntil: 'networkidle' });
                await page.waitForTimeout(80);
                const state = await page.evaluate(() => {
                    const rootGrid = document.querySelector('.workouts-section-grid');
                    const subheader = document.querySelector('.workouts-mobile-subheader');
                    return {
                        overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
                        rootDestinations: rootGrid?.querySelectorAll('.hierarchy-nav-row').length || 0,
                        subheaderVisible: Boolean(subheader && getComputedStyle(subheader).display !== 'none'),
                    };
                });
                const hierarchyOk = view === 'overview' ? state.rootDestinations === 5 : state.subheaderVisible;
                if (state.overflow || !hierarchyOk) {
                    responsiveProblems.push(`${view}@${width}:${JSON.stringify(state)}`);
                }
            }
        }
        check('Every workout section keeps its mobile hierarchy from 320 to 430px',
            responsiveProblems.length === 0, responsiveProblems.join(' | '));

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        const desktopWidgets = await page.evaluate(() => {
            const rank = document.querySelector('[data-dashboard-widget="training_rank"]')?.getBoundingClientRect();
            const progress = document.querySelector('[data-dashboard-widget="training_progress"]')?.getBoundingClientRect();
            return {
                sideBySide: Boolean(rank && progress && Math.abs(rank.top - progress.top) <= 2),
                rankTop: Math.round(rank?.top || 0),
                progressTop: Math.round(progress?.top || 0),
                rankWidth: Math.round(rank?.width || 0),
                progressWidth: Math.round(progress?.width || 0),
            };
        });
        check('Training widgets form a balanced desktop pair', desktopWidgets.sideBySide,
            `${desktopWidgets.rankWidth}px + ${desktopWidgets.progressWidth}px · y ${desktopWidgets.rankTop}/${desktopWidgets.progressTop}`);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-dashboard-training-widgets-desktop.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const desktopTabs = await page.evaluate(() => {
            const grid = document.querySelector('.workouts-section-grid');
            return grid ? {
                fits: grid.scrollWidth <= grid.clientWidth + 1,
                height: Math.round(grid.getBoundingClientRect().height),
                columns: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
            } : { fits: false, height: 0, columns: 0 };
        });
        check('Workout navigation uses one five-column desktop grid',
            desktopTabs.fits && desktopTabs.height <= 110 && desktopTabs.columns === 5,
            `${desktopTabs.columns} columns · ${desktopTabs.height}px`);

        check('No uncaught JavaScript errors', runtimeErrors.length === 0, runtimeErrors.join(' | '));
        check('No HTTP 5xx responses', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('Dashboard/workouts regression completed', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = results.filter((result) => !result.pass);
    console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
    if (failed.length > 0) process.exitCode = 1;
})();
