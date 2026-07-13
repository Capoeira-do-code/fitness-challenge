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
    await page.locator('[data-workout-add]').click();
    await page.waitForTimeout(300);
    const rowsAfter = await page.locator('[data-workout-row]').count();
    const panelVisible = await page.locator('[data-workout-panel]').evaluate((el) => !el.hidden);
    check('Add workout adds a row after in-app navigation', rowsAfter === rowsBefore + 1 && panelVisible,
        `rows ${rowsBefore} -> ${rowsAfter}, panel visible: ${panelVisible}`);

    const select = page.locator('[data-workout-row] [data-workout-select]').first();
    const options = await select.locator('option').evaluateAll((els) =>
        els.map((el) => el.value).filter((v) => v && v !== '__custom__' && !v.startsWith('routine:')));
    if (options.length > 0) {
        await select.selectOption(options[0]);
        await page.fill('input[name="steps"]', '8123');
        await page.locator('[data-testid="entry-form"] button[type=submit]').first().click();
        await page.waitForLoadState('networkidle');
        const saved = await page.locator('.flash').first().textContent().catch(() => '');
        check('Workout entry saves and reports back', /saved|guardad|salvat/i.test(saved || ''), (saved || '').trim().slice(0, 50));
    } else {
        // A silently skipped check reads as a pass, which is worse than a failure.
        skip('Workout entry saves and reports back', 'no workout types exist in this database');
    }

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
    await page.locator('.dashboard-layout-visibility > summary').click().catch(() => {});
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
        { page: 'dashboard', item: '[data-dashboard-widget]', key: 'dashboardWidget', save: '.dashboard-editbar-actions button[type=submit]' },
        { page: 'analytics', item: '[data-analytics-section]', key: 'analyticsSection', save: '.dashboard-editbar-actions button[type=submit]' },
        { page: 'profile', item: '[data-profile-block]', key: 'profileBlock', save: '.dashboard-editbar-actions button[type=submit]' },
        { page: 'team', item: '[data-team-widget]', key: 'teamWidget', save: '[data-team-layout-editor] button[type=submit]:not([name])' },
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

        const first = page.locator(target.item).first();
        const second = page.locator(target.item).nth(1);
        await first.scrollIntoViewIfNeeded();
        const a = await first.boundingBox();
        const b = await second.boundingBox();
        await page.mouse.move(a.x + a.width / 2, a.y + 20);
        await page.mouse.down();
        await page.mouse.move(a.x + a.width / 2 + 20, a.y + 40, { steps: 5 });
        await page.mouse.move(b.x + b.width / 2, b.y + b.height - 15, { steps: 12 });
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

    /* ---------------- No horizontal overflow ----------------------------------- */
    const pages = ['dashboard', 'entries&mode=data', 'week_editor&range=week', 'analytics', 'gallery',
        'profile', 'friends', 'duels', 'competitions', 'workouts', 'team', 'achievements'];
    let overflow = [];
    for (const width of [320, 375, 390, 430, 768, 1024]) {
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
    check('No horizontal overflow on any page (320-1024px)', overflow.length === 0, overflow.join(', '));
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
