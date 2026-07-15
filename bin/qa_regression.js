/**
 * Regression suite for the fixes in the UI/permissions round.
 *
 * It drives a real browser against a running app, so it catches the class of bug
 * this round was full of: handlers that are never bound, controls painted under an
 * overlay, and server rules that disagree with the UI.
 *
 * Usage:
 *   node bin/qa_regression.js --base http://127.0.0.1:8111 \
 *        --admin roberto --user catalina --password 'Verify123!'
 *
 * Point it at a THROWAWAY database (a copy), never production: it writes logs,
 * toggles layouts and starts workout sessions. `--user` must be a non-admin
 * account for the permission cases to mean anything; pass --skip-permissions if
 * you only have one account.
 */

const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const i = args.indexOf('--' + name);
    return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8111').replace(/\/$/, '');
const ADMIN = arg('admin', 'roberto');
const USER = arg('user', 'catalina');
const PASSWORD = arg('password', 'Verify123!');
const SKIP_PERMISSIONS = args.includes('--skip-permissions');

const results = [];
const check = (name, pass, detail = '') => {
    results.push({ name, pass, detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

const skipped = [];
const skip = (name, why) => {
    skipped.push({ name, why });
    console.log(`SKIP  ${name}  — ${why}`);
};

const login = async (page, username) => {
    await page.goto(`${BASE}/?page=login`);
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
};

// The sheet embeds its CSRF token in an inline script, not a meta tag.
const csrfFrom = (page) => page.evaluate(() => {
    const m = document.documentElement.innerHTML.match(/const csrf = "([^"]+)"/);
    return m ? m[1] : (document.querySelector('input[name="csrf_token"]')?.value || '');
});

const saveRowAs = async (page, userId) => {
    const token = await csrfFrom(page);

    return page.evaluate(async ({ uid, csrf }) => {
        const response = await fetch('/?page=api_save_row', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrf,
                user_id: uid,
                log_date: new Date().toISOString().slice(0, 10),
                steps: 1234,
            }),
        });

        return response.status;
    }, { uid: userId, csrf: token });
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const jsErrors = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));

    await login(page, ADMIN);

    // The training sheet always knows whose data it is showing: non-admins get a
    // hidden user_id, admins get a select. That is a far more reliable id than
    // scraping a nav link.
    const sheetUserId = async (target) => {
        await target.goto(`${BASE}/?page=week_editor&range=week`);
        await target.waitForLoadState('networkidle');
        return target.evaluate(() => {
            const select = document.querySelector('[data-testid="dashboard-user-select"], select[name="user_id"]');
            if (select) {
                return Number(select.value);
            }
            const hidden = document.querySelector('input[name="user_id"]');
            return hidden ? Number(hidden.value) : 0;
        });
    };
    const adminId = await sheetUserId(page);

    /* ---------------- Add workout: the whole flow, after in-app navigation ------ */
    // The bug was that the entry form was only wired up on a hard page load.
    await page.evaluate(() => {
        const a = document.createElement('a');
        a.href = '/?page=entries&mode=data';
        document.body.appendChild(a);
        a.click();
    });
    await page.waitForTimeout(1500);
    const rowsBefore = await page.locator('[data-workout-row]').count();
    const workoutWasEnabled = await page.locator('[data-workout-enabled]').evaluate((el) => el.checked);
    await page.locator('[data-workout-add]').click();
    await page.waitForTimeout(300);
    const rowsAfter = await page.locator('[data-workout-row]').count();
    const panelVisible = await page.locator('[data-workout-panel]').evaluate((el) => !el.hidden);
    const expectedRows = workoutWasEnabled ? rowsBefore + 1 : Math.max(1, rowsBefore);
    check('Add workout activates one usable row after in-app navigation', rowsAfter === expectedRows && panelVisible,
        `enabled: ${workoutWasEnabled}, rows ${rowsBefore} -> ${rowsAfter}, panel visible: ${panelVisible}`);

    // The Add button appends a required row. Populate that new row; selecting the
    // first row can leave the appended one invalid when today's log already has a
    // workout, so the browser correctly refuses to submit.
    const select = page.locator('[data-workout-row] [data-workout-select]').last();
    const options = await select.locator('option').evaluateAll((els) =>
        els.map((el) => el.value).filter((v) => v && v !== '__custom__' && !v.startsWith('routine:')));
    if (options.length > 0) {
        await select.selectOption(options[0]);
    } else {
        await select.selectOption('__custom__');
        await page.locator('[data-workout-row] [data-workout-custom-input]').last().fill('QA mobility workout');
    }
    await page.fill('input[name="steps"]', '8123');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.locator('[data-testid="entry-form"] button[type=submit]').first().click(),
    ]);
    const saved = await page.locator('.flash').first().textContent().catch(() => '');
    check('Workout entry saves and reports back', /saved|guardad|salvat/i.test(saved || ''), (saved || '').trim().slice(0, 50));

    /* ---------------- Double submit ------------------------------------------- */
    let posts = 0;
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('page=entries')) posts++; });
    await page.goto(`${BASE}/?page=entries&mode=data`);
    await page.waitForLoadState('networkidle');
    await page.fill('input[name="steps"]', '7777');
    await page.evaluate(() => {
        const button = document.querySelector('[data-testid="entry-form"] button[type=submit]');
        button.click(); button.click(); button.click();
    });
    await page.waitForTimeout(2000);
    check('Three rapid submits send exactly one POST', posts === 1, `posts: ${posts}`);

    /* ---------------- Editor controls are actually reachable ------------------- */
    // The recurring bug on this page was never logic: it was a fixed overlay (blur
    // backdrop, bottom nav) painted over the editor, so taps never landed. Assert
    // hit-testing, not just presence.
    for (const target of ['dashboard', 'analytics', 'profile']) {
        await page.goto(`${BASE}/?page=${target}&layout_edit=1`);
        await page.waitForLoadState('networkidle');
        const reachable = await page.evaluate(() => {
            const bar = document.querySelector('.layout-editbar, .dashboard-layout-editbar');
            if (!bar) {
                return { ok: false, why: 'no editbar' };
            }
            const hit = (el) => {
                const r = el.getBoundingClientRect();
                const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                return top === el || el.contains(top);
            };
            const save = bar.querySelector('button[type=submit]');
            const summary = bar.querySelector('.dashboard-layout-visibility > summary');
            return {
                ok: hit(save) && (!summary || hit(summary)),
                why: hit(save) ? 'visibility list covered' : 'Save button covered',
            };
        });
        check(`Layout editor controls are tappable on ${target} (390px)`, reachable.ok, reachable.ok ? '' : reachable.why);
    }

    /* ---------------- Layout persistence -------------------------------------- */
    await page.goto(`${BASE}/?page=dashboard&layout_edit=1`);
    await page.waitForLoadState('networkidle');
    const orderBefore = await page.$$eval('[data-dashboard-layout-item] input[name="dashboard_widgets[]"]', (e) => e.map((x) => x.value));
    const visibilityDetails = page.locator('.dashboard-layout-visibility');
    if (await visibilityDetails.getAttribute('open') === null) {
        await visibilityDetails.locator(':scope > summary').click();
    }
    await page.locator('[data-dashboard-layout-item] [data-layout-move="down"]').first().click();
    await page.locator('.dashboard-editbar-actions button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    await page.goto(`${BASE}/?page=dashboard`);
    const persisted = await page.$$eval('.dashboard-layout > [data-dashboard-widget]',
        (e) => e.map((x) => [x.dataset.dashboardWidget, parseInt(x.style.order || '0', 10)])
            .sort((a, b) => a[1] - b[1]).map((x) => x[0]));
    check('Dashboard reorder persists across a reload',
        persisted.length === orderBefore.length && persisted[0] === orderBefore[1],
        `${orderBefore.slice(0, 2).join(',')} -> ${persisted.slice(0, 2).join(',')}`);

    /* ---------------- Visual drag-and-drop on desktop -------------------------- */
    // Every page with a layout editor must be editable by dragging the real cards on
    // desktop, and must NOT advertise a grab handle on mobile (a card cannot be
    // grabbed there - the editor covers the page).
    const DRAG_PAGES = [
        { page: 'dashboard', item: '[data-dashboard-widget]', attr: 'data-dashboard-widget', key: 'dashboardWidget', save: '.dashboard-editbar-actions button[type=submit]' },
        { page: 'analytics', item: '[data-analytics-section]', attr: 'data-analytics-section', key: 'analyticsSection', save: '.dashboard-editbar-actions button[type=submit]' },
        { page: 'profile', item: '[data-profile-block]', attr: 'data-profile-block', key: 'profileBlock', save: '.dashboard-editbar-actions button[type=submit]' },
        { page: 'team', item: '[data-team-widget]', attr: 'data-team-widget', key: 'teamWidget', save: '[data-team-layout-editor] button[type=submit]:not([name])' },
    ];

    // Read the order the browser actually applies: team drives `order` from a CSS
    // custom property, so reading the inline style attribute reports nothing at all.
    const layoutOrder = (target, item, key) => target.$$eval(item, (els, k) => els
        .filter((el) => getComputedStyle(el).display !== 'none')
        .map((el) => [el.dataset[k], parseInt(getComputedStyle(el).order || '0', 10)])
        .sort((a, b) => a[1] - b[1])
        .map((x) => x[0]), key);

    await page.setViewportSize({ width: 1440, height: 900 });
    for (const target of DRAG_PAGES) {
        await page.goto(`${BASE}/?page=${target.page}&layout_edit=1`);
        await page.waitForLoadState('networkidle');

        const before = await layoutOrder(page, target.item, target.key);
        if (before.length < 2) {
            skip(`Drag reorders ${target.page} cards`, 'fewer than two visible cards');
            continue;
        }

        // CSS order can differ from DOM order after a previous save. Resolve both
        // cards from the actual visual order so this always moves the first visible
        // card after the second instead of occasionally dropping onto itself.
        const sourceKey = before[0];
        const destinationKey = before[1];
        const first = page.locator(`${target.item}[${target.attr}="${sourceKey}"]`);
        const targetCard = page.locator(`${target.item}[${target.attr}="${destinationKey}"]`);
        await first.scrollIntoViewIfNeeded();
        const handle = first.locator(':scope > [data-layout-card-controls] .layout-card-drag-handle');
        const a = await handle.boundingBox();
        const b = await targetCard.boundingBox();
        await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
        await page.mouse.down();
        await page.mouse.move(a.x + a.width / 2 + 20, a.y + 30, { steps: 5 });
        await page.mouse.move(b.x + b.width - 4, b.y + b.height / 2, { steps: 12 });
        await page.mouse.up();
        await page.waitForTimeout(250);

        const dragged = await layoutOrder(page, target.item, target.key);
        await page.locator(target.save).first().click();
        await page.waitForLoadState('networkidle');
        await page.goto(`${BASE}/?page=${target.page}`);
        await page.waitForLoadState('networkidle');
        const persisted = await layoutOrder(page, target.item, target.key);

        check(`Drag reorders ${target.page} cards and the order persists`,
            dragged.join(',') !== before.join(',') && persisted.join(',') === dragged.join(','),
            `${before.slice(0, 2).join(',')} -> ${dragged.slice(0, 2).join(',')} (saved: ${persisted.slice(0, 2).join(',')})`);
    }

    // The handle is a desktop promise only.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/?page=team&layout_edit=1`);
    await page.waitForLoadState('networkidle');
    const mobileHandle = await page.evaluate(() => {
        const el = document.querySelector('[data-team-widget]');
        return el ? getComputedStyle(el, '::before').content : 'none';
    });
    check('No drag handle on mobile, where dragging cannot work', mobileHandle === 'none', `content: ${mobileHandle}`);
    await page.setViewportSize({ width: 390, height: 844 });

    /* ---------------- Permissions --------------------------------------------- */
    if (SKIP_PERMISSIONS) {
        skip('Permission cases (read-only sheet, 403, admin write)', '--skip-permissions was passed');
    }

    if (!SKIP_PERMISSIONS) {
        const userContext = await browser.newContext();
        const userPage = await userContext.newPage();
        await login(userPage, USER);

        await userPage.goto(`${BASE}/?page=week_editor&range=week&user_id=${adminId}`);
        await userPage.waitForLoadState('networkidle');
        const banner = await userPage.locator('.sheet-readonly-banner').count();
        const saveButtons = await userPage.locator('.js-save-row, #save-all-rows').count();
        const disabled = await userPage.locator('.sheet-fieldset[disabled]').count();
        check("Normal user sees another user's sheet read-only",
            banner === 1 && saveButtons === 0 && disabled === 1,
            `banner ${banner}, save buttons ${saveButtons}, disabled fieldset ${disabled}`);

        const forbidden = await saveRowAs(userPage, adminId);
        check("Normal user cannot POST to another user's row (403)", forbidden === 403, `status ${forbidden}`);

        const ownId = await sheetUserId(userPage);
        const ownSave = await saveRowAs(userPage, ownId);
        check('Normal user can save their own row (200)', ownSave === 200, `status ${ownSave}`);

        await page.goto(`${BASE}/?page=week_editor&range=week&user_id=${ownId}`);
        await page.waitForLoadState('networkidle');
        const adminSave = await saveRowAs(page, ownId);
        check("Admin can save another user's row (200)", adminSave === 200, `status ${adminSave}`);
        await userContext.close();
    }

    /* ---------------- Shared profiles, contextual actions and Settings -------- */
    await page.goto(`${BASE}/?page=team`);
    await page.waitForLoadState('networkidle');
    const memberTarget = await page.evaluate((currentId) => {
        const links = [...document.querySelectorAll('.member-card-link[href*="page=profile"], .team-leaderboard-card[href*="page=profile"]')];
        const link = links.find((candidate) => Number(new URL(candidate.href).searchParams.get('user_id')) !== currentId)
            || links[0];
        return link ? {
            href: link.getAttribute('href') || '',
            userId: Number(new URL(link.href).searchParams.get('user_id')),
        } : null;
    }, adminId);
    check('Team member cards target the shared profile route', Boolean(memberTarget)
        && memberTarget.href.includes('page=profile')
        && memberTarget.href.includes('back=team')
        && !memberTarget.href.includes('section=member'), memberTarget?.href || 'no member link');

    if (memberTarget && memberTarget.userId > 0) {
        await page.goto(`${BASE}${memberTarget.href}`);
        await page.waitForLoadState('networkidle');
        const externalProfile = await page.evaluate((currentId) => ({
            isExternal: Number(new URL(location.href).searchParams.get('user_id')) !== currentId,
            back: Boolean(document.querySelector('.context-back-btn[href*="page=team"]')),
            avatarEdit: Boolean(document.querySelector('.profile-avatar-trigger')),
            privateSections: document.querySelectorAll('[data-spa-section="config"], [data-spa-section="activity"]').length,
        }), adminId);
        if (externalProfile.isExternal) {
            check('External profile hides owner controls and private sections', externalProfile.back
                && !externalProfile.avatarEdit && externalProfile.privateSections === 0,
            JSON.stringify(externalProfile));
        } else {
            skip('External profile hides owner controls and private sections', 'team only exposes the current account');
        }

        await page.goto(`${BASE}/?page=team&section=member&user_id=${memberTarget.userId}`);
        await page.waitForLoadState('networkidle');
        check('Legacy Member Detail URL redirects to shared profile',
            new URL(page.url()).searchParams.get('page') === 'profile'
                && new URL(page.url()).searchParams.get('back') === 'team', page.url());
    }

    await page.goto(`${BASE}/?page=settings`);
    await page.waitForLoadState('networkidle');
    const settingsIndex = await page.evaluate(() => ({
        items: document.querySelectorAll('.settings-nav-item').length,
        forms: document.querySelectorAll('.settings-index-screen form').length,
    }));
    check('Settings home is navigation rather than one giant form', settingsIndex.items >= 6 && settingsIndex.forms === 0,
        `${settingsIndex.items} sections, ${settingsIndex.forms} forms`);

    const settingsRouteProblems = [];
    for (const view of ['avatar', 'goals', 'preferences', 'privacy', 'integrations', 'account']) {
        await page.goto(`${BASE}/?page=settings&view=${view}`);
        await page.waitForLoadState('networkidle');
        const routeState = await page.evaluate(() => ({
            heading: Boolean(document.querySelector('h1')),
            back: Boolean(document.querySelector('a[href="/?page=settings"]')),
            overflow: document.documentElement.scrollWidth > innerWidth + 1,
        }));
        if (!routeState.heading || !routeState.back || routeState.overflow) settingsRouteProblems.push(view);
    }
    check('Every Settings section has a heading, back navigation and no overflow',
        settingsRouteProblems.length === 0, settingsRouteProblems.join(', '));

    await page.goto(`${BASE}/?page=competitions`);
    await page.waitForLoadState('networkidle');
    const competitionState = await page.evaluate(() => {
        const hasTeams = document.querySelectorAll('#competition-teams .squad-card').length > 0;
        const empty = document.querySelector('.competition-empty-state');
        const emptyAction = empty?.querySelector('a')?.textContent.trim() || '';
        return {
            hasTeams,
            emptyAction,
            createAnother: Boolean(document.querySelector('.squad-create-secondary')),
        };
    });
    check('Competitions never suggests another team when one already exists',
        !competitionState.hasTeams || (!competitionState.createAnother && !/create a team|crear un equipo|crea un team/i.test(competitionState.emptyAction)),
        JSON.stringify(competitionState));

    await page.goto(`${BASE}/?page=profile`);
    await page.waitForLoadState('networkidle');
    const avatarTrigger = page.locator('.profile-avatar-trigger');
    if (await avatarTrigger.count()) {
        await avatarTrigger.click();
        const frameSelector = await page.evaluate(() => {
            const options = [...document.querySelectorAll('.cosmetic-option')];
            return {
                options: options.length,
                previews: options.filter((option) => option.querySelector('.cosmetic-swatch img, .cosmetic-swatch > span')).length,
                selected: options.filter((option) => option.querySelector('input:checked')).length,
            };
        });
        check('Avatar frame selector shows real previews and one selected option',
            frameSelector.options > 0 && frameSelector.previews === frameSelector.options && frameSelector.selected === 1,
            JSON.stringify(frameSelector));
        await page.keyboard.press('Escape');
    } else {
        skip('Avatar frame selector shows real previews and one selected option', 'profile avatar trigger unavailable');
    }

    await page.goto(`${BASE}/?page=week_editor&range=week`);
    await page.waitForLoadState('networkidle');
    const habitToggle = page.locator('[data-custom-habit-toggle]').first();
    if (await habitToggle.count()) {
        await habitToggle.click();
        const habitPanel = await page.evaluate(() => {
            const panel = document.querySelector('.week-custom-habit.is-portaled');
            return panel ? {
                bodyChild: panel.parentElement === document.body,
                fixed: getComputedStyle(panel).position === 'fixed',
            } : { bodyChild: false, fixed: false };
        });
        check('Custom habit menu is portaled outside the table', habitPanel.bodyChild && habitPanel.fixed,
            JSON.stringify(habitPanel));
        await page.keyboard.press('Escape');
    }

    /* ---------------- No horizontal overflow ----------------------------------- */
    const pages = ['dashboard', 'entries&mode=data', 'week_editor&range=week', 'analytics', 'gallery',
        'profile', 'friends', 'duels', 'competitions', 'workouts', 'team', 'achievements', 'challenges',
        'settings', 'settings&view=avatar', 'settings&view=preferences', 'settings&view=privacy'];
    let overflow = [];
    for (const width of [320, 360, 390, 430, 768, 1024, 1280, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const target of pages) {
            await page.goto(`${BASE}/?page=${target}`);
            await page.waitForLoadState('networkidle');
            const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
            if (wide) {
                overflow.push(`${target}@${width}`);
            }
        }
    }
    check('No horizontal overflow on any page (320-1440px)', overflow.length === 0, overflow.join(', '));

    const touchContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        hasTouch: true,
    });
    const touchPage = await touchContext.newPage();
    await login(touchPage, ADMIN);
    await touchPage.goto(`${BASE}/?page=entries&mode=data`);
    await touchPage.waitForLoadState('networkidle');
    const undersizedTouchTargets = await touchPage.evaluate(() => {
        const selectors = [
            '.brand',
            '.entry-day-arrow',
            'input[name="steps"]',
            'input[name="distance_km"]',
            '[data-workout-add]',
            '[data-testid="entry-form"] button[type="submit"]',
        ];
        return selectors.flatMap((selector) => [...document.querySelectorAll(selector)]
            .filter((el) => {
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);
                return style.display !== 'none' && style.visibility !== 'hidden'
                    && (rect.width < 44 || rect.height < 44);
            })
            .map((el) => {
                const rect = el.getBoundingClientRect();
                return `${selector}:${Math.round(rect.width)}x${Math.round(rect.height)}`;
            }));
    });
    check('Primary mobile controls meet the 44px touch target', undersizedTouchTargets.length === 0,
        undersizedTouchTargets.join(', '));

    await touchPage.setViewportSize({ width: 320, height: 568 });
    await touchPage.goto(`${BASE}/?page=dashboard`);
    await touchPage.waitForLoadState('networkidle');
    const narrowChrome = await touchPage.evaluate(() => {
        const title = document.querySelector('.topbar-page-title');
        const clippedLabels = [...document.querySelectorAll('.bottom-nav .nav-label')]
            .filter((label) => label.scrollWidth > label.clientWidth + 1)
            .map((label) => label.textContent.trim());
        return {
            clippedLabels,
            titleClipped: title ? title.scrollWidth > title.clientWidth + 1 : true,
            labels: [...document.querySelectorAll('.bottom-nav .nav-label')]
                .map((label) => label.textContent.trim()),
        };
    });
    check('Bottom navigation labels fit at 320px', narrowChrome.clippedLabels.length === 0,
        narrowChrome.clippedLabels.join(', '));
    check('Dashboard title fits in the 320px topbar', !narrowChrome.titleClipped,
        narrowChrome.titleClipped ? 'title is clipped' : '');
    const localizedNavSets = [
        'Home|Training|Create|Social|Profile',
        'Inicio|Entreno|Crear|Social|Perfil',
        'Home|Training|Crea|Social|Profilo',
    ];
    check('Mobile navigation is fully localized',
        localizedNavSets.includes(narrowChrome.labels.join('|')),
        narrowChrome.labels.join('|'));

    const userMenu = touchPage.locator('details.user-menu');
    await touchPage.locator('details.user-menu > summary').click();
    const mobileUserMenu = await touchPage.evaluate(() => {
        const details = document.querySelector('details.user-menu');
        const summary = details?.querySelector(':scope > summary');
        const panel = details?.querySelector('.user-menu-panel');
        const nav = document.querySelector('.bottom-nav');
        if (!details || !summary || !panel || !nav) return { valid: false, label: '', minItem: 0 };
        const panelRect = panel.getBoundingClientRect();
        const navRect = nav.getBoundingClientRect();
        const itemHeights = [...panel.querySelectorAll('[data-menu-view]:not([hidden]) a, [data-menu-view]:not([hidden]) button')]
            .map((item) => item.getBoundingClientRect().height);
        return {
            valid: details.open && panelRect.left >= 0 && panelRect.right <= innerWidth
                && panelRect.bottom <= navRect.top && Math.min(...itemHeights) >= 44,
            label: summary.getAttribute('aria-label') || '',
            minItem: Math.round(Math.min(...itemHeights)),
        };
    });
    check('Mobile user menu fits above navigation with accessible controls',
        mobileUserMenu.valid && /^(User menu|Menú de usuario|Menu utente)/.test(mobileUserMenu.label),
        `label: ${mobileUserMenu.label}, min item: ${mobileUserMenu.minItem}px`);
    await touchPage.locator('.topbar-page-title').click();
    check('Mobile user menu closes on outside tap', await userMenu.getAttribute('open') === null);

    const quickMenu = touchPage.locator('details.bottom-nav-plus');
    await touchPage.locator('details.bottom-nav-plus > summary').click();
    await touchPage.locator('.mobile-sheet-backdrop').waitFor({ state: 'visible' });
    await touchPage.waitForTimeout(80);
    const mobileQuickMenu = await touchPage.evaluate(() => {
        const panel = document.querySelector('.bottom-nav-plus-menu');
        const nav = document.querySelector('.bottom-nav');
        if (!panel || !nav) return { valid: false, width: 0, minItem: 0 };
        const panelRect = panel.getBoundingClientRect();
        const navRect = nav.getBoundingClientRect();
        const itemHeights = [...panel.querySelectorAll('[data-menu-view="main"]:not([hidden]) .mobile-quick-nav, [data-menu-view="main"]:not([hidden]) .mobile-quick-action')]
            .map((item) => item.getBoundingClientRect().height);
        return {
            valid: panelRect.width >= innerWidth - 22 && panelRect.left >= 0
                && panelRect.right <= innerWidth && panelRect.bottom <= navRect.top
                && Math.min(...itemHeights) >= 44,
            width: Math.round(panelRect.width),
            minItem: Math.round(Math.min(...itemHeights)),
        };
    });
    check('Mobile quick actions form a full-width touch sheet', mobileQuickMenu.valid,
        `width: ${mobileQuickMenu.width}px, min item: ${mobileQuickMenu.minItem}px`);

    await touchPage.locator('.mobile-sheet-backdrop').click({ position: { x: 4, y: 4 } });
    await touchPage.locator('details.dashboard-mobile-controls > summary').click();
    const exclusiveMenus = await touchPage.evaluate(() => ({
        quickClosed: !document.querySelector('details.bottom-nav-plus')?.open,
        contextOpen: Boolean(document.querySelector('details.dashboard-mobile-controls')?.open),
    }));
    await touchPage.keyboard.press('Escape');
    const escapeMenuState = await touchPage.evaluate(() => ({
        closed: !document.querySelector('details.dashboard-mobile-controls')?.open,
        focusReturned: document.activeElement === document.querySelector('details.dashboard-mobile-controls > summary'),
    }));
    check('Mobile menus are exclusive and Escape restores trigger focus',
        exclusiveMenus.quickClosed && exclusiveMenus.contextOpen
            && escapeMenuState.closed && escapeMenuState.focusReturned);

    await touchPage.setViewportSize({ width: 320, height: 480 });
    const contextMenuProblems = [];
    for (const target of ['analytics', 'entries&mode=calendar', 'gallery&gallery_view=recent']) {
        await touchPage.goto(`${BASE}/?page=${target}`);
        await touchPage.waitForLoadState('networkidle');
        const contextDetails = touchPage.locator('details.topbar-context');
        if (await contextDetails.count() !== 1) {
            contextMenuProblems.push(`${target}:missing`);
            continue;
        }
        await touchPage.locator('details.topbar-context > summary').click();
        const menuState = await touchPage.evaluate(() => {
            const panel = document.querySelector('.topbar-context-panel');
            const nav = document.querySelector('.bottom-nav');
            if (!panel || !nav) return { valid: false, minItem: 0 };
            const rect = panel.getBoundingClientRect();
            const navRect = nav.getBoundingClientRect();
            const controls = [...panel.querySelectorAll('a, button, input:not([type="hidden"]), select')]
                .filter((control) => getComputedStyle(control).display !== 'none')
                .map((control) => control.getBoundingClientRect().height);
            return {
                valid: rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0
                    && rect.bottom <= navRect.top && (controls.length === 0 || Math.min(...controls) >= 44),
                minItem: controls.length === 0 ? 0 : Math.round(Math.min(...controls)),
            };
        });
        if (!menuState.valid) contextMenuProblems.push(`${target}:${menuState.minItem}px`);
        await touchPage.keyboard.press('Escape');
    }
    check('Context menus fit a short 320px phone with 44px targets',
        contextMenuProblems.length === 0, contextMenuProblems.join(', '));
    await touchPage.setViewportSize({ width: 320, height: 568 });

    await touchPage.goto(`${BASE}/?page=entries&mode=data`);
    await touchPage.waitForLoadState('networkidle');
    const entryDateLayout = await touchPage.evaluate(() => {
        const dateInput = document.querySelector('.entry-date-nav input');
        const timeInput = document.querySelector('.entry-time-inline input');
        const arrows = [...document.querySelectorAll('.entry-day-arrow')];
        if (!dateInput || !timeInput || arrows.length !== 2) {
            return { valid: false, dateWidth: 0 };
        }
        const dateRect = dateInput.getBoundingClientRect();
        const timeRect = timeInput.getBoundingClientRect();
        const overlaps = (a, b) => a.left < b.right && a.right > b.left
            && a.top < b.bottom && a.bottom > b.top;
        return {
            valid: arrows.every((arrow) => {
                const rect = arrow.getBoundingClientRect();
                return !overlaps(rect, dateRect) && !overlaps(rect, timeRect);
            }) && dateRect.width >= 100,
            dateWidth: Math.round(dateRect.width),
        };
    });
    check('Entry date controls stay legible at 320px', entryDateLayout.valid,
        `date width: ${entryDateLayout.dateWidth}px`);

    const currentTouchTheme = await touchPage.locator('body').getAttribute('data-theme');
    if (currentTouchTheme !== 'dark') {
        await touchPage.locator('.user-menu > summary').click();
        await touchPage.locator('[data-menu-open="user-appearance"]').click();
        await touchPage.locator('[data-theme-toggle]').click();
        await touchPage.waitForTimeout(250);
    }
    const brandContrast = await touchPage.evaluate(() => {
        const mark = document.querySelector('.brand-mark');
        if (!mark) return 0;
        const parse = (value) => (value.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
        const luminance = (rgb) => {
            const values = rgb.map((value) => {
                const channel = value / 255;
                return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
            });
            return (0.2126 * values[0]) + (0.7152 * values[1]) + (0.0722 * values[2]);
        };
        const style = getComputedStyle(mark);
        const foreground = luminance(parse(style.color));
        const background = luminance(parse(style.backgroundColor));
        return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
    });
    check('Dark-mode brand mark keeps readable contrast', brandContrast >= 4.5,
        `contrast ${brandContrast.toFixed(2)}:1`);

    await touchPage.goto(`${BASE}/?page=notifications`);
    await touchPage.waitForLoadState('networkidle');
    const visibleNotificationHeadings = await touchPage.evaluate(() => [...document.querySelectorAll('h1')]
        .filter((heading) => getComputedStyle(heading).display !== 'none')
        .map((heading) => heading.textContent.trim()));
    check('Notifications has one visible mobile page heading', visibleNotificationHeadings.length === 1,
        visibleNotificationHeadings.join(' | '));
    await touchContext.close();
    check('No uncaught JS errors', jsErrors.length === 0, jsErrors.slice(0, 2).join(' | '));

    await browser.close();

    const failed = results.filter((r) => !r.pass);
    console.log(`
${results.length - failed.length}/${results.length} checks passed`
        + (skipped.length ? `, ${skipped.length} skipped` : ''));
    process.exit(failed.length === 0 ? 0 : 1);
})().catch((error) => {
    console.error('Runner crashed:', error.message);
    process.exit(1);
});
