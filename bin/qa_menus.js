/**
 * Regression focused on contextual menus and their mobile action-sheet mode.
 *
 * Usage:
 *   node bin/qa_menus.js --base http://127.0.0.1:8113
 *
 * Run against a throwaway database: this creates one team goal and toggles a
 * routine favourite so real form/action wiring is covered too.
 */

const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};

const BASE = arg('base', 'http://127.0.0.1:8113').replace(/\/$/, '');
const USERNAME = arg('admin', 'roberto');
const PASSWORD = arg('password', 'Verify123!');
const REPORT_DIR = path.join(__dirname, '..', 'e2e-report');
const results = [];

const check = (name, pass, detail = '') => {
    const row = { name, pass: Boolean(pass), detail };
    results.push(row);
    console.log(`${row.pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`);
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
};

const visibleMenuItems = (page, stackSelector = '.kebab-menu-panel.is-portaled') => page.locator(
    `${stackSelector} > [data-menu-view]:not([hidden]) > .kebab-menu-item`
).allTextContents().then((items) => items.map((item) => item.replace(/\s+/g, ' ').trim()));

const mobileSheetState = (page) => page.evaluate(() => {
    const panel = document.querySelector('.kebab-menu-panel.is-portaled');
    const backdrop = document.querySelector('.kebab-menu-backdrop');
    const nav = document.querySelector('.bottom-nav');
    const rect = panel?.getBoundingClientRect();
    const panelStyles = panel ? getComputedStyle(panel) : null;
    const backgroundParts = panelStyles?.backgroundColor.match(/[\d.]+/g)?.map(Number) || [];
    const backgroundAlpha = backgroundParts.length >= 4 ? backgroundParts[3] : 1;
    return {
        panel: Boolean(panel),
        backdropCount: document.querySelectorAll('.kebab-menu-backdrop').length,
        bodyLocked: document.body.classList.contains('kebab-menu-open-mobile'),
        inViewport: Boolean(rect && rect.left >= -1 && rect.right <= innerWidth + 1
            && rect.top >= -1 && rect.bottom <= innerHeight + 1),
        minItemHeight: panel
            ? Math.min(...[...panel.querySelectorAll('[data-menu-view]:not([hidden]) .kebab-menu-item')]
                .map((item) => item.getBoundingClientRect().height))
            : 0,
        aboveChrome: Boolean(panel && (!nav
            || Number.parseInt(getComputedStyle(panel).zIndex, 10) > Number.parseInt(getComputedStyle(nav).zIndex, 10))),
        backdropAboveChrome: Boolean(backdrop && (!nav
            || Number.parseInt(getComputedStyle(backdrop).zIndex, 10) > Number.parseInt(getComputedStyle(nav).zIndex, 10))),
        backgroundAlpha,
        rect: rect ? { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom } : null,
    };
});

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 320, height: 568 } });
    const page = await context.newPage();
    const jsErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);

        /* User menu: keep common destinations visible, group secondary choices. */
        await page.goto(`${BASE}/?page=dashboard`);
        await page.waitForLoadState('networkidle');
        const topbarState = await page.evaluate(() => {
            const topbar = document.querySelector('.topbar');
            const container = document.querySelector('.container-with-nav');
            const title = document.querySelector('.topbar-page-title');
            const originalTitle = title?.textContent || '';
            if (title) title.textContent = 'Panel de entrenamiento y progreso personal extraordinariamente largo';
            const barRect = topbar?.getBoundingClientRect();
            const containerRect = container?.getBoundingClientRect();
            const targets = topbar ? [...topbar.querySelectorAll('.btn-topbar, .topbar-notif-btn, .user-menu-trigger')]
                .filter((node) => getComputedStyle(node).display !== 'none' && node.getBoundingClientRect().height > 0) : [];
            const state = {
                height: Math.round(barRect?.height || 0),
                contentGap: Math.round((containerRect?.top || 0) - (barRect?.bottom || 0)),
                minTarget: targets.length ? Math.min(...targets.map((node) => Math.min(
                    node.getBoundingClientRect().width,
                    node.getBoundingClientRect().height
                ))) : 0,
                titleEllipses: Boolean(title && title.scrollWidth > title.clientWidth && getComputedStyle(title).textOverflow === 'ellipsis'),
                overflow: document.documentElement.scrollWidth > innerWidth + 1,
            };
            if (title) title.textContent = originalTitle;
            return state;
        });
        check('Topbar móvil es compacta, táctil y admite títulos largos', topbarState.height <= 60
            && topbarState.contentGap >= 12 && topbarState.minTarget >= 44
            && topbarState.titleEllipses && !topbarState.overflow, JSON.stringify(topbarState));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-topbar-mobile-v2.png'), fullPage: false });
        const contextMenu = page.locator('details.topbar-context');
        if (await contextMenu.count() === 1) {
            await contextMenu.locator(':scope > summary').click();
            await page.waitForTimeout(60);
            const contextState = await page.evaluate(() => {
                const panel = document.querySelector('details.topbar-context[open] > .topbar-context-panel');
                const rect = panel?.getBoundingClientRect();
                const controls = panel ? [...panel.querySelectorAll('select, button, a.btn')]
                    .filter((node) => getComputedStyle(node).display !== 'none' && node.getBoundingClientRect().height > 0) : [];
                return {
                    centered: Boolean(rect && Math.abs((rect.left + rect.width / 2) - innerWidth / 2) <= 2),
                    inViewport: Boolean(rect && rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight),
                    width: Math.round(rect?.width || 0),
                    minTarget: controls.length ? Math.min(...controls.map((node) => node.getBoundingClientRect().height)) : 0,
                    overflow: Boolean(panel && panel.scrollWidth > panel.clientWidth + 1),
                };
            });
            check('Topbar context is a compact centered mobile panel',
                contextState.centered && contextState.inViewport && contextState.width <= 420
                    && contextState.minTarget >= 44 && !contextState.overflow,
                JSON.stringify(contextState));
            await page.screenshot({ path: path.join(REPORT_DIR, 'ui-topbar-context-mobile-v2.png'), fullPage: false });
            await page.keyboard.press('Escape');
        }
        const userTrigger = page.locator('details.user-menu > summary');
        await userTrigger.click();
        await page.waitForTimeout(50);
        const userMain = page.locator('.user-menu-panel > [data-menu-view="main"]');
        const userMainState = await page.evaluate(() => {
            const details = document.querySelector('details.user-menu');
            const panel = details?.querySelector('.user-menu-panel');
            const view = details?.querySelector('[data-menu-view="main"]');
            const rect = panel?.getBoundingClientRect();
            return {
                open: Boolean(details?.open),
                expanded: details?.querySelector(':scope > summary')?.getAttribute('aria-expanded'),
                categories: view?.querySelectorAll('[data-menu-open]').length || 0,
                directItems: view?.querySelectorAll(':scope > a, :scope > button').length || 0,
                columns: view ? getComputedStyle(view).gridTemplateColumns.split(' ').filter(Boolean).length : 0,
                centered: Boolean(rect && Math.abs((rect.left + rect.width / 2) - innerWidth / 2) <= 2),
                overflow: document.documentElement.scrollWidth > innerWidth + 1,
            };
        });
        check('User menu is a centered single-column hierarchy',
            userMainState.open && userMainState.expanded === 'true'
                && userMainState.categories === 2 && userMainState.directItems <= 8
                && userMainState.columns === 1 && userMainState.centered && !userMainState.overflow,
            `${userMainState.directItems} items, ${userMainState.categories} categories, ${userMainState.columns} column`);

        const communityButton = userMain.locator('[data-menu-open="user-community"]');
        await communityButton.click();
        await page.locator('.user-menu-view[data-menu-view="user-community"]:not([hidden]) [data-menu-back]').waitFor();
        await page.waitForFunction(() => document.activeElement?.matches('[data-menu-back]'));
        const communityState = await page.evaluate(() => {
            const main = document.querySelector('.user-menu-panel > [data-menu-view="main"]');
            const sub = document.querySelector('.user-menu-panel > [data-menu-view="user-community"]');
            return {
                mainHidden: Boolean(main?.hidden),
                subVisible: Boolean(sub && !sub.hidden),
                links: sub ? [...sub.querySelectorAll(':scope > a')].map((item) => item.textContent.trim()) : [],
                focusBack: document.activeElement?.matches('[data-menu-back]') || false,
            };
        });
        check('Community drill-down exposes only Friends and Duels',
            communityState.mainHidden && communityState.subVisible && communityState.links.length === 2
                && communityState.focusBack,
            communityState.links.join(', '));
        await page.locator('.user-menu-view:not([hidden]) [data-menu-back]').click();
        await page.waitForTimeout(50);
        check('User submenu Back restores its category focus',
            await communityButton.evaluate((button) => document.activeElement === button));

        await userMain.locator('[data-menu-open="user-appearance"]').click();
        check('Appearance drill-down keeps theme and avatar controls together',
            await page.locator('.user-menu-view[data-menu-view="user-appearance"]:not([hidden]) [data-theme-toggle]').count() === 1
                && await page.locator('.user-menu-view[data-menu-view="user-appearance"]:not([hidden]) a').count() === 1);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-menu-user-mobile.png'), fullPage: false });
        await page.keyboard.press('Escape');
        const userClosed = await page.evaluate(() => {
            const details = document.querySelector('details.user-menu');
            return !details?.open && details?.querySelector(':scope > summary')?.getAttribute('aria-expanded') === 'false'
                && document.activeElement === details?.querySelector(':scope > summary');
        });
        check('Escape closes the user menu and restores trigger focus', userClosed);

        /* Central + menu: one hierarchy, no duplicate featured shortcuts. */
        const plusMenu = page.locator('details.bottom-nav-plus');
        await plusMenu.locator(':scope > summary').click();
        await page.locator('.bottom-nav-plus-menu:visible').waitFor();
        await page.waitForTimeout(80);
        const plusState = await page.evaluate(() => {
            const details = document.querySelector('details.bottom-nav-plus');
            const panel = details?.querySelector('.bottom-nav-plus-menu');
            const main = panel?.querySelector('[data-menu-view="main"]');
            const rect = panel?.getBoundingClientRect();
            return {
                open: Boolean(details?.open),
                bodyLocked: document.body.classList.contains('mobile-sheet-open'),
                backdrop: document.querySelectorAll('.mobile-sheet-backdrop').length,
                centered: Boolean(rect && Math.abs((rect.left + rect.width / 2) - innerWidth / 2) <= 2),
                inViewport: Boolean(rect && rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight),
                width: Math.round(rect?.width || 0),
                choices: main?.querySelectorAll(':scope > [data-menu-open]').length || 0,
                featuredDuplicates: main?.querySelectorAll('.mobile-quick-featured').length || 0,
                focusInside: Boolean(panel?.contains(document.activeElement)),
            };
        });
        check('The + menu is centered, locked and free of duplicate actions',
            plusState.open && plusState.bodyLocked && plusState.backdrop === 1
                && plusState.centered && plusState.inViewport && plusState.width <= 420
                && plusState.choices === 2 && plusState.featuredDuplicates === 0 && plusState.focusInside,
            JSON.stringify(plusState));
        const registerButton = page.locator('.bottom-nav-plus-menu [data-menu-view="main"] [data-menu-open="quick-register"]');
        await registerButton.click();
        await page.locator('.bottom-nav-plus-menu [data-menu-view="quick-register"]:not([hidden]) [data-menu-back]').waitFor();
        check('The + menu reveals logging actions at one clear second level',
            await page.locator('.bottom-nav-plus-menu [data-menu-view="quick-register"]:not([hidden]) > a').count() === 3);
        await page.locator('.bottom-nav-plus-menu [data-menu-view="quick-register"] [data-menu-back]').click();
        check('The + menu Back action restores focus', await registerButton.evaluate((button) => document.activeElement === button));
        await page.keyboard.press('Escape');
        check('Escape closes the + menu and removes its backdrop', await page.evaluate(() => {
            const details = document.querySelector('details.bottom-nav-plus');
            return !details?.open && !document.querySelector('.mobile-sheet-backdrop')
                && !document.body.classList.contains('mobile-sheet-open')
                && document.activeElement === details?.querySelector(':scope > summary');
        }));

        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await page.waitForTimeout(180);
        const stableNav = await page.evaluate(() => {
            const nav = document.querySelector('nav.bottom-nav.mobile-liquid-nav');
            const rect = nav?.getBoundingClientRect();
            return Boolean(nav && !nav.classList.contains('nav-hidden') && !nav.classList.contains('is-hidden')
                && rect && rect.bottom <= innerHeight + 1 && rect.top < innerHeight);
        });
        check('Bottom navigation remains visible after scrolling', stableNav);

        /* Routine menu: main actions are compact; organization is one level deep. */
        await page.goto(`${BASE}/?page=workouts`);
        await page.waitForLoadState('networkidle');
        const routineMenu = page.locator('.workouts-routine-card details[data-kebab-menu]').first();
        check('Routine fixture exposes a contextual menu', await routineMenu.count() === 1);
        await routineMenu.locator(':scope > summary').click();
        await page.locator('.kebab-menu-panel.is-portaled').waitFor({ state: 'visible' });
        await page.waitForTimeout(80);
        const initialSheet = await mobileSheetState(page);
        const routineMainItems = await visibleMenuItems(page);
        check('Mobile kebab opens as one stable, accessible action sheet',
            initialSheet.panel && initialSheet.backdropCount === 1 && initialSheet.bodyLocked
                && initialSheet.inViewport && initialSheet.minItemHeight >= 44
                && initialSheet.aboveChrome && initialSheet.backdropAboveChrome
                && initialSheet.backgroundAlpha >= 0.95,
            JSON.stringify(initialSheet));
        check('Routine main menu is reduced to Edit, Organize and Delete',
            routineMainItems.length === 3 && routineMainItems.some((item) => /organizar|organize|organizza/i.test(item)),
            routineMainItems.join(' · '));

        const organizeButton = page.locator('.kebab-menu-panel.is-portaled [data-menu-open]').first();
        await organizeButton.click();
        const routineSubItems = await visibleMenuItems(page);
        check('Routine organization submenu preserves all secondary actions',
            routineSubItems.length === 3
                && /favor/i.test(routineSubItems[0])
                && /duplic/i.test(routineSubItems[1])
                && /archiv/i.test(routineSubItems[2]),
            routineSubItems.join(' · '));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-menu-routine-mobile.png'), fullPage: false });

        const sheetBack = page.locator('.kebab-menu-panel.is-portaled [data-menu-back]');
        await sheetBack.click();
        await page.waitForTimeout(50);
        check('Routine submenu Back restores the Organize control',
            await organizeButton.evaluate((button) => document.activeElement === button));
        await page.locator('.kebab-menu-backdrop').click({ position: { x: 4, y: 4 } });
        const backdropClosed = await page.evaluate(() => {
            const details = document.querySelector('.workouts-routine-card details[data-kebab-menu]');
            return !details?.open && !document.querySelector('.kebab-menu-panel.is-portaled')
                && !document.querySelector('.kebab-menu-backdrop')
                && !document.body.classList.contains('kebab-menu-open-mobile')
                && document.activeElement === details?.querySelector(':scope > summary');
        });
        check('Backdrop closes cleanly with no orphaned portal', backdropClosed);

        /* Exercise a real action; compare requested favourite value with resulting card. */
        await routineMenu.locator(':scope > summary').click();
        await page.locator('.kebab-menu-panel.is-portaled [data-menu-open]').first().click();
        const favoriteAction = page.locator('.kebab-menu-panel.is-portaled [data-wk-submit="routine_favorite"]');
        const favoriteTarget = await favoriteAction.getAttribute('data-wk-value');
        const favoriteRoutineId = await favoriteAction.getAttribute('data-wk-routine');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            favoriteAction.click(),
        ]);
        await page.goto(`${BASE}/?page=workouts`);
        await page.waitForLoadState('networkidle');
        const persistedFavoriteAction = page.locator(
            `[data-wk-submit="routine_favorite"][data-wk-routine="${favoriteRoutineId}"]`
        );
        const favoriteApplied = await persistedFavoriteAction.evaluate((action, value) =>
            action.closest('.workouts-routine-card')?.classList.contains('is-favorite') === (value === '1'), favoriteTarget);
        check('Portaled routine action still reaches its real handler', favoriteApplied, `target=${favoriteTarget}`);

        /* Profile menu uses the same hierarchy and sheet behavior. */
        await page.goto(`${BASE}/?page=profile`);
        await page.waitForLoadState('networkidle');
        const profileMenu = page.locator('.profile-hero-menu');
        await profileMenu.locator(':scope > summary').click();
        await page.waitForTimeout(80);
        const profileMainItems = await visibleMenuItems(page);
        const personalize = page.locator('.kebab-menu-panel.is-portaled [data-menu-open]').first();
        await personalize.click();
        const profileSubItems = await visibleMenuItems(page);
        check('Profile menu groups personalization without hiding primary actions',
            profileMainItems.length >= 3 && profileSubItems.length === 2
                && /personal|personaliz/i.test(profileMainItems.join(' ')),
            `${profileMainItems.join(' · ')} → ${profileSubItems.join(' · ')}`);
        await page.keyboard.press('Escape');

        /* Build a real team goal fixture through the UI, then inspect its menu. */
        await page.goto(`${BASE}/?page=team&section=challenge`);
        await page.waitForLoadState('networkidle');
        const createGoal = page.locator('[data-team-goal-open][data-goal-mode="create"]');
        if (await createGoal.count() === 1) {
            await createGoal.click();
            const modal = page.locator('[data-team-goal-modal]');
            await modal.locator('[data-goal-title-input]').fill('QA menú móvil');
            await modal.locator('[data-goal-target-input]').fill('12345');
            await Promise.all([
                page.waitForLoadState('networkidle'),
                modal.locator('[data-team-goal-submit]').click(),
            ]);
            await page.goto(`${BASE}/?page=team&section=challenge`);
            await page.waitForLoadState('networkidle');
        }
        const teamGoalMenu = page.locator('.team-goal-actions-menu:visible').first();
        check('Team goal fixture exposes its contextual menu', await teamGoalMenu.count() === 1);
        if (await teamGoalMenu.count() === 1) {
            await teamGoalMenu.locator(':scope > summary').click();
            await page.locator('.kebab-menu-panel.is-portaled [data-menu-view="main"]:not([hidden])').waitFor();
            const teamGoalMain = await visibleMenuItems(page);
            const statusButton = page.locator('.kebab-menu-panel.is-portaled [data-menu-open]').first();
            await statusButton.click();
            const statusItems = await visibleMenuItems(page);
            check('Team goal status actions live in a clear submenu',
                teamGoalMain.length === 3 && statusItems.length === 2,
                `${teamGoalMain.join(' · ')} → ${statusItems.join(' · ')}`);
            await page.locator('.kebab-menu-panel.is-portaled [data-menu-back]').click();
            const editGoal = page.locator('.kebab-menu-panel.is-portaled .kebab-menu-item').first();
            await editGoal.click();
            await page.waitForTimeout(100);
            const editModalState = await page.evaluate(() => {
                const modal = document.querySelector('[data-team-goal-modal]');
                return Boolean(modal && !modal.hidden)
                    && !document.querySelector('.kebab-menu-panel.is-portaled')
                    && !document.querySelector('.kebab-menu-backdrop');
            });
            check('Team goal Edit opens normally and removes the mobile portal', editModalState);
            await page.locator('[data-team-goal-modal] [data-team-goal-close]').last().click();
        }

        /* Responsive matrix: no clipped page or sheet in the supported phone band. */
        const matrixFailures = [];
        for (const width of [320, 360, 390, 430]) {
            await page.setViewportSize({ width, height: 760 });
            for (const targetPage of ['workouts', 'profile', 'team']) {
                await page.goto(`${BASE}/?page=${targetPage}`);
                await page.waitForLoadState('networkidle');
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
                const firstMenu = page.locator('details[data-kebab-menu]:visible').first();
                let sheetOk = true;
                let state = null;
                if (await firstMenu.count() === 1) {
                    await firstMenu.locator(':scope > summary').click();
                    await page.locator('.kebab-menu-panel.is-portaled').waitFor({ state: 'visible' });
                    await page.waitForTimeout(80);
                    state = await mobileSheetState(page);
                    sheetOk = state.panel && state.backdropCount === 1 && state.inViewport;
                    await page.keyboard.press('Escape');
                }
                if (overflow || !sheetOk) matrixFailures.push(`${targetPage}@${width}:${overflow ? 'overflow' : JSON.stringify(state)}`);
            }
        }
        check('Menus remain inside the viewport from 320 to 430px',
            matrixFailures.length === 0, matrixFailures.join(', '));

        /* Desktop remains a compact anchored popover without mobile scrim. */
        await page.setViewportSize({ width: 1180, height: 800 });
        await page.goto(`${BASE}/?page=workouts`);
        await page.waitForLoadState('networkidle');
        await page.locator('.workouts-routine-card details[data-kebab-menu] > summary').first().click();
        await page.locator('.kebab-menu-panel.is-portaled').waitFor({ state: 'visible' });
        await page.waitForTimeout(80);
        const desktopState = await page.evaluate(() => {
            const panel = document.querySelector('.kebab-menu-panel.is-portaled');
            const rect = panel?.getBoundingClientRect();
            return {
                noBackdrop: !document.querySelector('.kebab-menu-backdrop'),
                noBodyLock: !document.body.classList.contains('kebab-menu-open-mobile'),
                inViewport: Boolean(rect && rect.left >= 0 && rect.right <= innerWidth
                    && rect.top >= 0 && rect.bottom <= innerHeight),
                width: Math.round(rect?.width || 0),
            };
        });
        check('Desktop menu stays an anchored popover without mobile scrim',
            desktopState.noBackdrop && desktopState.noBodyLock && desktopState.inViewport
                && desktopState.width >= 190 && desktopState.width <= 280,
            `width=${desktopState.width}px`);
        await page.keyboard.press('Escape');

        /* Dark theme: verify the same mobile surface, not only the page chrome. */
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=dashboard`);
        await page.waitForLoadState('networkidle');
        if (await page.locator('body').getAttribute('data-theme') !== 'dark') {
            await page.locator('details.user-menu > summary').click();
            await page.locator('[data-menu-open="user-appearance"]').click();
            await page.locator('[data-theme-toggle]').click();
            await page.waitForTimeout(250);
            const menuClose = page.locator('.user-menu-view:not([hidden]) [data-menu-close]');
            if (await menuClose.count() === 1) await menuClose.click();
            else await page.keyboard.press('Escape');
        }
        await page.goto(`${BASE}/?page=profile`);
        await page.waitForLoadState('networkidle');
        await page.locator('.profile-hero-menu > summary').click();
        await page.locator('.kebab-menu-panel.is-portaled').waitFor({ state: 'visible' });
        await page.waitForTimeout(250);
        const darkSheet = await page.evaluate(() => {
            const panel = document.querySelector('.kebab-menu-panel.is-portaled');
            const nav = document.querySelector('.bottom-nav');
            const styles = panel ? getComputedStyle(panel) : null;
            const backgroundParts = styles?.backgroundColor.match(/[\d.]+/g)?.map(Number) || [];
            return {
                theme: document.body.dataset.theme || '',
                panel: Boolean(panel),
                backdrop: Boolean(document.querySelector('.kebab-menu-backdrop')),
                background: styles?.backgroundColor || '',
                color: styles?.color || '',
                backgroundAlpha: backgroundParts.length >= 4 ? backgroundParts[3] : 1,
                aboveChrome: Boolean(panel && (!nav
                    || Number.parseInt(styles.zIndex, 10) > Number.parseInt(getComputedStyle(nav).zIndex, 10))),
            };
        });
        check('Mobile action sheet keeps a themed surface in dark mode',
            darkSheet.theme === 'dark' && darkSheet.panel && darkSheet.backdrop
                && darkSheet.background !== '' && darkSheet.color !== ''
                && darkSheet.backgroundAlpha >= 0.95 && darkSheet.aboveChrome,
            `${darkSheet.background} / ${darkSheet.color}, alpha=${darkSheet.backgroundAlpha}`);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-menu-profile-mobile-dark.png'), fullPage: false });
        await page.keyboard.press('Escape');

        check('No uncaught JavaScript errors during menu flows', jsErrors.length === 0,
            jsErrors.slice(0, 3).join(' | '));
        check('No HTTP 5xx responses during menu flows', serverErrors.length === 0,
            serverErrors.slice(0, 3).join(' | '));
    } catch (error) {
        check('Menu regression completed without harness exception', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = results.filter((row) => !row.pass);
    console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
    if (failed.length > 0) process.exitCode = 1;
})();
