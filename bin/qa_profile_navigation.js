/**
 * Profile hierarchy navigation and visual-consistency contract.
 *
 * Run against a disposable fixture:
 *   node bin/qa_profile_navigation.js --base http://127.0.0.1:8120
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8120').replace(/\/$/, '');
const USERNAME = arg('username', 'roberto');
const PASSWORD = arg('password', 'Verify123!');
const REPORT_DIR = path.join(__dirname, '..', 'e2e-report');
fs.mkdirSync(REPORT_DIR, { recursive: true });

const failures = [];
const check = (name, condition, detail = '') => {
    const pass = Boolean(condition);
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` - ${detail}` : ''}`);
    if (!pass) failures.push(`${name}${detail ? `: ${detail}` : ''}`);
};

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
    check('Login de fixture', !page.url().includes('page=login'), page.url());
};

const profileState = (page) => page.evaluate(() => {
    const root = document.querySelector('[data-spa-page="profile"]');
    const rows = [...document.querySelectorAll('.profile-mobile-root .hierarchy-nav-row')];
    const visibleSections = [...document.querySelectorAll('[data-spa-page="profile"] > [data-spa-section]')]
        .filter((section) => !section.hidden && getComputedStyle(section).display !== 'none');
    const rowStyles = rows.map((row) => {
        const rect = row.getBoundingClientRect();
        const style = getComputedStyle(row);
        const icon = row.querySelector('.hierarchy-nav-icon');
        const iconRect = icon?.getBoundingClientRect();
        return {
            height: Math.round(rect.height),
            radius: style.borderRadius,
            background: style.backgroundColor,
            iconWidth: Math.round(iconRect?.width || 0),
            iconHeight: Math.round(iconRect?.height || 0),
            hasSvg: Boolean(icon?.querySelector('svg')),
        };
    });
    return {
        section: root?.getAttribute('data-profile-section') || '',
        mobileRoot: document.querySelectorAll('.profile-mobile-root').length,
        visibleSections: visibleSections.map((section) => section.getAttribute('data-spa-section')),
        sectionHeaders: visibleSections.filter((section) => Boolean(section.querySelector('.profile-section-header'))).length,
        focusOnHeading: Boolean(document.activeElement?.matches('[data-navigation-focus]')),
        homeGridVisible: [...document.querySelectorAll('.profile-home-grid')].some((grid) => !grid.hidden
            && getComputedStyle(grid).display !== 'none'),
        rows: rowStyles,
        overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
    };
});

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const jsErrors = [];
    const serverErrors = [];
    const profileGets = [];
    const adminGets = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) jsErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
        if (response.request().method() === 'GET' && response.url().includes('page=profile')) profileGets.push(response.url());
        if (response.request().method() === 'GET' && response.url().includes('page=admin')) adminGets.push(response.url());
    });

    try {
        await login(page);
        await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
        const rootState = await profileState(page);
        check('Perfil raíz muestra seis accesos coherentes', rootState.rows.length === 6 && !rootState.overflow,
            JSON.stringify(rootState.rows));
        check('Los accesos usan una iconografía SVG uniforme', rootState.rows.every((row) => row.hasSvg
            && row.iconWidth === rootState.rows[0].iconWidth && row.iconHeight === rootState.rows[0].iconHeight));
        check('Los accesos comparten superficie y radio', rootState.rows.every((row) => row.radius === rootState.rows[0].radius
            && row.background === rootState.rows[0].background));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-profile-hub-uniform-mobile.png'), fullPage: true });

        const sections = ['goals', 'training', 'social', 'achievements', 'activity'];
        for (const section of sections) {
            await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
            await page.evaluate(() => { window.__qaProfileMain = document.querySelector('main.container'); });
            const requestCount = profileGets.length;
            const link = page.locator(`.profile-mobile-root a[href*="section=${section}"]`).first();
            await link.click();
            await page.waitForURL((url) => url.searchParams.get('section') === section);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(450);
            const state = await profileState(page);
            const replaced = await page.evaluate(() => window.__qaProfileMain !== document.querySelector('main.container'));
            check(`${section}: carga una subpantalla real`, replaced && profileGets.length > requestCount
                && state.section === section && state.mobileRoot === 0 && !state.homeGridVisible,
            JSON.stringify({ replaced, gets: profileGets.length - requestCount, state }));
            check(`${section}: usa una única sección y cabecera común`, state.visibleSections.length === 1
                && state.visibleSections[0] === section && state.sectionHeaders === 1
                && state.focusOnHeading && !state.overflow,
            JSON.stringify(state));
            if (section === 'goals') {
                await page.screenshot({ path: path.join(REPORT_DIR, 'ui-profile-goals-navigation-mobile.png'), fullPage: true });
                await page.evaluate(() => { window.__qaProfileMain = document.querySelector('main.container'); });
                const createRequestCount = profileGets.length;
                await page.locator('[data-spa-section="goals"] .profile-section-list a[href*="goal_new=1"]').click();
                await page.waitForURL((url) => url.searchParams.get('goal_new') === '1');
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(450);
                const createState = await profileState(page);
                const createReplaced = await page.evaluate(() => window.__qaProfileMain !== document.querySelector('main.container'));
                const createVisible = await page.locator('[data-spa-section="goals"] .profile-create-view:visible').count();
                check('Nuevo objetivo abre su propia vista real', createReplaced && profileGets.length > createRequestCount
                    && createVisible === 1 && createState.focusOnHeading && !createState.homeGridVisible,
                JSON.stringify({ createReplaced, createVisible, createState }));

                const goalTitle = `Navigation QA ${Date.now()}`;
                await page.fill('[data-spa-section="goals"] .profile-create-view input[name="title"]', goalTitle);
                await page.fill('[data-spa-section="goals"] .profile-create-view input[name="target_value"]', '10');
                await Promise.all([
                    page.waitForURL((url) => url.searchParams.get('section') === 'goals' && !url.searchParams.has('goal_new')),
                    page.click('[data-spa-section="goals"] .profile-create-view button[type="submit"]'),
                ]);
                await page.waitForLoadState('networkidle');
                const goalRow = page.locator('[data-profile-goals-list] .goal-row', { hasText: goalTitle }).first();
                check('El objetivo creado aparece en la lista', await goalRow.count() === 1);
                await page.evaluate(() => { window.__qaProfileMain = document.querySelector('main.container'); });
                const detailRequestCount = profileGets.length;
                await goalRow.click();
                await page.waitForURL((url) => Number(url.searchParams.get('goal_id') || 0) > 0);
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(450);
                const detailState = await profileState(page);
                const detailVisible = await page.locator('[data-spa-section="goals"] .profile-detail-view:visible').count();
                const detailReplaced = await page.evaluate(() => window.__qaProfileMain !== document.querySelector('main.container'));
                check('El detalle del objetivo también reemplaza la pantalla', detailReplaced
                    && profileGets.length > detailRequestCount && detailVisible === 1
                    && detailState.focusOnHeading && !detailState.homeGridVisible,
                JSON.stringify({ detailReplaced, detailVisible, detailState }));
                const detailHistoryLength = await page.evaluate(() => history.length);
                await page.locator('[data-spa-section="goals"] .profile-detail-view:visible a[data-spa-back]').click();
                await page.waitForURL((url) => url.searchParams.get('section') === 'goals' && !url.searchParams.has('goal_id'));
                await page.waitForLoadState('networkidle');
                const afterDetailBack = await page.evaluate(() => ({
                    length: history.length,
                    depth: Number(history.state?.__fcPjaxDepth || 0),
                }));
                check('Volver desde el detalle usa el historial real sin crear otra entrada', afterDetailBack.length === detailHistoryLength
                    && afterDetailBack.depth === 0, JSON.stringify(afterDetailBack));
                const requestCountBack = profileGets.length;
                await page.locator('[data-spa-section="goals"] a[data-spa-back]').first().click();
                await page.waitForURL((url) => !url.searchParams.has('section'));
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(450);
                const backState = await profileState(page);
                check('Volver recarga el hub Perfil sin dejar contenido expandido', profileGets.length > requestCountBack
                    && backState.mobileRoot === 1 && backState.visibleSections.length === 0 && backState.homeGridVisible,
                JSON.stringify(backState));
            }
        }

        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width === 768 ? 1024 : 844 });
            await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
            const hubLayout = await page.evaluate(() => {
                const links = [...document.querySelectorAll('.profile-mobile-root .hierarchy-nav-row')];
                return {
                    overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
                    count: links.length,
                    targetSizes: links.map((link) => {
                        const rect = link.getBoundingClientRect();
                        return { width: Math.round(rect.width), height: Math.round(rect.height) };
                    }),
                };
            });
            check(`Perfil raÃ­z ${width}px sin recortes y con objetivos tÃ¡ctiles`, !hubLayout.overflow
                && hubLayout.count === 6 && hubLayout.targetSizes.every((size) => size.width >= 44 && size.height >= 44),
            JSON.stringify(hubLayout));

            await page.goto(`${BASE}/?page=profile&section=goals`, { waitUntil: 'networkidle' });
            const sectionLayout = await page.evaluate(() => {
                const controls = [...document.querySelectorAll('.profile-section-header a, .profile-section-header button')]
                    .filter((control) => control.getClientRects().length > 0);
                return {
                    overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
                    visibleSections: [...document.querySelectorAll('[data-spa-page="profile"] > [data-spa-section]')]
                        .filter((section) => !section.hidden && getComputedStyle(section).display !== 'none').length,
                    targets: controls.map((control) => {
                        const rect = control.getBoundingClientRect();
                        return { width: Math.round(rect.width), height: Math.round(rect.height) };
                    }),
                };
            });
            check(`Objetivos ${width}px conserva una sola pantalla clara`, !sectionLayout.overflow
                && sectionLayout.visibleSections === 1
                && sectionLayout.targets.every((size) => size.width >= 44 && size.height >= 44),
            JSON.stringify(sectionLayout));
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=profile&section=goals`, { waitUntil: 'networkidle' });
        const darkState = await page.evaluate(() => {
            document.body.setAttribute('data-theme', 'dark');
            document.body.classList.add('theme-active-dark');
            document.body.classList.remove('theme-active-light');
            const surface = document.querySelector('.profile-native-section');
            const style = surface ? getComputedStyle(surface) : null;
            return {
                background: style?.backgroundColor || '',
                color: style?.color || '',
                overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
            };
        });
        check('Objetivos mantiene una superficie legible en tema oscuro', !darkState.overflow
            && darkState.background !== '' && darkState.background !== 'rgba(0, 0, 0, 0)', JSON.stringify(darkState));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-profile-goals-navigation-dark.png'), fullPage: true });

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
        await page.locator('.profile-home-goals a[href*="section=goals"]:visible').last().click();
        await page.waitForURL((url) => url.searchParams.get('section') === 'goals');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(450);
        const desktopState = await profileState(page);
        check('La navegación de Perfil también reemplaza contenido en desktop', desktopState.section === 'goals'
            && desktopState.visibleSections.length === 1 && !desktopState.overflow);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-profile-goals-navigation-desktop.png'), fullPage: true });

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=admin`, { waitUntil: 'networkidle' });
        const adminRootState = await page.evaluate(() => {
            const groups = [...document.querySelectorAll('[data-spa-page="admin"] [data-spa-main] a[href*="group="]')]
                .map((link) => new URL(link.href).searchParams.get('group') || '');
            return { groups, uniqueGroups: new Set(groups).size };
        });
        check('Administración no contiene grupos duplicados', adminRootState.groups.length === 4
            && adminRootState.groups.length === adminRootState.uniqueGroups, JSON.stringify(adminRootState));
        await page.locator('[data-spa-page="admin"] [data-spa-main] a[href*="group=people"]').click();
        await page.waitForURL((url) => url.searchParams.get('group') === 'people');
        await page.waitForLoadState('networkidle');
        await page.evaluate(() => { window.__qaAdminMain = document.querySelector('main.container'); });
        const adminRequestCount = adminGets.length;
        await page.locator('[data-spa-page="admin"] [data-spa-main] a[href*="section=users"]').click();
        await page.waitForURL((url) => url.searchParams.get('section') === 'users');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(450);
        const adminState = await page.evaluate(() => ({
            replaced: window.__qaAdminMain !== document.querySelector('main.container'),
            rootListPresent: document.querySelectorAll('[data-spa-page="admin"] > [data-spa-main]').length,
            rootListVisible: Boolean(document.querySelector('[data-spa-page="admin"] > [data-spa-main]:not([hidden])')),
            visibleSections: [...document.querySelectorAll('[data-spa-page="admin"] > [data-spa-section]')]
                .filter((section) => !section.hidden && getComputedStyle(section).display !== 'none')
                .map((section) => section.getAttribute('data-spa-section')),
        }));
        check('Los demás menús jerárquicos también cargan su URL real', adminState.replaced
            && adminGets.length > adminRequestCount && !adminState.rootListVisible
            && adminState.visibleSections.length === 1 && adminState.visibleSections[0] === 'users',
        JSON.stringify(adminState));

        await page.goto(`${BASE}/?page=admin&group=experience`, { waitUntil: 'networkidle' });
        await page.locator('[data-spa-page="admin"] [data-spa-main] a[href*="section=appearance"]').click();
        await page.waitForURL((url) => url.searchParams.get('section') === 'appearance');
        await page.waitForLoadState('networkidle');
        const appearanceVisible = await page.locator('[data-spa-page="admin"] > [data-spa-section="appearance"]:visible').count();
        check('Marca e inicio de sesión abre una sola sección coherente', appearanceVisible === 1);

        check('Sin errores JavaScript', jsErrors.length === 0, jsErrors.join(' | '));
        check('Sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } finally {
        await context.close();
        await browser.close();
    }

    if (failures.length) {
        console.error(`\n${failures.length} fallo(s):\n- ${failures.join('\n- ')}`);
        process.exitCode = 1;
    } else {
        console.log('\nNavegación jerárquica de Perfil validada.');
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
