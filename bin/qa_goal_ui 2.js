/**
 * Focused browser QA for the ongoing UI simplification goal.
 *
 * It must run against a disposable database: layout preferences are saved while
 * checking that drag, remove and add survive an in-app navigation.
 */
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8120').replace(/\/$/, '');
const USERNAME = value('username', 'roberto');
const PASSWORD = value('password', 'Verify123!');

const checks = [];
const check = (name, pass, detail = '') => {
    checks.push({ name, pass, detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

const internalNavigate = async (page, href, readySelector) => {
    await page.evaluate((target) => {
        const link = document.createElement('a');
        link.href = target;
        link.textContent = 'QA navigation';
        link.style.position = 'fixed';
        link.style.inset = '0 auto auto 0';
        document.body.appendChild(link);
        link.click();
    }, href);
    await page.waitForURL((url) => url.href.includes(href.replace(/^\//, '')), { timeout: 10000 });
    await page.locator(readySelector).first().waitFor({ state: 'attached', timeout: 10000 });
    await page.waitForFunction(() => window.__fcPjaxBusy !== true);
};

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`);
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.searchParams.has('page') || url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page);

    const layouts = [
        {
            page: 'dashboard',
            container: '.dashboard-layout',
            item: '[data-dashboard-widget]',
            key: 'data-dashboard-widget',
            editor: '[data-dashboard-layout-editor]',
        },
        {
            page: 'analytics',
            container: '.analytics-page',
            item: '[data-analytics-section]',
            key: 'data-analytics-section',
            editor: '[data-analytics-layout-editor]',
        },
        {
            page: 'profile',
            container: '.profile-home-grid',
            item: '[data-profile-block]',
            key: 'data-profile-block',
            editor: '[data-profile-layout-editor]',
        },
        {
            page: 'team',
            container: '.team-layout-grid',
            item: '[data-team-widget]',
            key: 'data-team-widget',
            editor: '[data-team-layout-editor]',
        },
    ];

    for (const layout of layouts) {
        await internalNavigate(page, `/?page=${layout.page}&layout_edit=1`, layout.editor);
        const setup = await page.evaluate((cfg) => {
            const container = document.querySelector(cfg.container);
            const visible = container
                ? [...container.querySelectorAll(cfg.item)].filter((node) => !node.hidden && getComputedStyle(node).display !== 'none')
                : [];
            return {
                editClass: document.body.classList.contains('layout-edit-active'),
                ready: container?.dataset.layoutDragReady === '1',
                visible: visible.length,
                controls: container?.querySelectorAll(':scope [data-layout-card-controls]').length || 0,
                removeButtons: container?.querySelectorAll(':scope [data-layout-remove-card]').length || 0,
                visibilityButtons: document.querySelectorAll(`${cfg.editor} [data-layout-visibility-toggle]`).length,
                keys: visible
                    .map((node) => [node.getAttribute(cfg.key), parseInt(getComputedStyle(node).order || '0', 10)])
                    .sort((a, b) => a[1] - b[1])
                    .map(([key]) => key),
            };
        }, layout);
        check(`Editor desktop inicializado tras navegación interna: ${layout.page}`,
            setup.editClass && setup.ready && setup.visible >= 2 && setup.controls >= setup.visible
                && setup.removeButtons >= setup.visible && setup.visibilityButtons >= setup.visible,
            `${setup.visible} visibles, ${setup.controls} controles`);

        if (setup.visible >= 2) {
            // Saved layouts can make CSS order differ from DOM order. Resolve the
            // drag pair from the computed visual order so we never drop onto the
            // same card after a previous QA run.
            const sourceKey = setup.keys[0];
            const destinationKey = setup.keys[1];
            const first = page.locator(`${layout.container} ${layout.item}[${layout.key}="${sourceKey}"]`);
            const second = page.locator(`${layout.container} ${layout.item}[${layout.key}="${destinationKey}"]`);
            await first.scrollIntoViewIfNeeded();
            await page.waitForTimeout(100);
            const firstKey = sourceKey;
            const before = setup.keys.join(',');
            const handle = first.locator(':scope > [data-layout-card-controls] .layout-card-drag-handle');
            const handleBox = await handle.boundingBox();
            const targetBox = await second.boundingBox();
            if (handleBox && targetBox) {
                await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
                await page.mouse.down();
                await page.mouse.move(handleBox.x + handleBox.width / 2 + 20, handleBox.y + 30, { steps: 5 });
                await page.mouse.move(targetBox.x + targetBox.width - 4, targetBox.y + targetBox.height / 2, { steps: 12 });
                await page.mouse.up();
            }
            await page.waitForTimeout(250);
            const after = await page.evaluate((cfg) => [...document.querySelectorAll(`${cfg.container} ${cfg.item}`)]
                .filter((node) => !node.hidden && getComputedStyle(node).display !== 'none')
                .map((node) => [node.getAttribute(cfg.key), parseInt(getComputedStyle(node).order || '0', 10)])
                .sort((a, b) => a[1] - b[1])
                .map(([key]) => key).join(','), layout);
            check(`Drag real cambia el orden: ${layout.page}`, before !== after, `${before} -> ${after}`);

            await page.locator(`${layout.container} ${layout.item}[${layout.key}="${firstKey}"] [data-layout-remove-card]`).click();
            const removed = await page.evaluate(({ cfg, key }) => {
                const card = document.querySelector(`${cfg.container} ${cfg.item}[${cfg.key}="${CSS.escape(key)}"]`);
                const checkbox = document.querySelector(`${cfg.editor} input[type="checkbox"][value="${CSS.escape(key)}"]`);
                const button = checkbox?.closest(cfg.editor.includes('profile') ? '[data-profile-layout-item]' : cfg.editor.includes('analytics') ? '[data-analytics-layout-item]' : cfg.editor.includes('team') ? '[data-team-layout-item]' : '[data-dashboard-layout-item]')?.querySelector('[data-layout-visibility-toggle]');
                return { hidden: Boolean(card?.hidden), checked: Boolean(checkbox?.checked), label: button?.textContent?.trim() || '' };
            }, { cfg: layout, key: firstKey });
            check(`Quitar widget es inmediato: ${layout.page}`, removed.hidden && !removed.checked, removed.label);

            const checkbox = page.locator(`${layout.editor} input[type="checkbox"][value="${firstKey}"]`);
            const row = checkbox.locator('xpath=..').locator('xpath=..');
            const visibilityDetails = page.locator(`${layout.editor} details`).first();
            if (await visibilityDetails.count() && !(await visibilityDetails.evaluate((details) => details.open))) {
                await visibilityDetails.locator(':scope > summary').click();
            }
            await row.locator('[data-layout-visibility-toggle]').click();
            const added = await page.evaluate(({ cfg, key }) => {
                const card = document.querySelector(`${cfg.container} ${cfg.item}[${cfg.key}="${CSS.escape(key)}"]`);
                const input = document.querySelector(`${cfg.editor} input[type="checkbox"][value="${CSS.escape(key)}"]`);
                return !card?.hidden && Boolean(input?.checked);
            }, { cfg: layout, key: firstKey });
            check(`Volver a añadir widget es inmediato: ${layout.page}`, added);

            await Promise.all([
                page.waitForURL((url) => url.searchParams.get('layout_edit') !== '1'),
                page.locator(`${layout.editor} button[type="submit"]`).last().click(),
            ]);
            await page.waitForLoadState('networkidle');
            const persisted = await page.evaluate(({ cfg, key }) => {
                const card = document.querySelector(`${cfg.container} ${cfg.item}[${cfg.key}="${CSS.escape(key)}"]`);
                return Boolean(card) && !card.hidden && getComputedStyle(card).display !== 'none';
            }, { cfg: layout, key: firstKey });
            check(`Layout guardado conserva el widget: ${layout.page}`, persisted, firstKey || 'sin clave');
        }
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await internalNavigate(page, '/?page=week_editor&range=week', '.training-sheet-table');
    const sheet = await page.evaluate(() => {
        const rows = [...document.querySelectorAll('.training-sheet-table tbody tr')];
        const heights = rows.map((row) => Math.round(row.getBoundingClientRect().height));
        const habitLists = [...document.querySelectorAll('.sheet-habits-list')];
        return {
            heights,
            max: Math.max(0, ...heights),
            habitMax: Math.max(0, ...habitLists.map((list) => Math.round(list.getBoundingClientRect().height))),
            horizontal: Boolean(document.querySelector('.training-sheet-wrap')?.scrollWidth > document.querySelector('.training-sheet-wrap')?.clientWidth),
        };
    });
    check('Challenge Log móvil usa filas compactas', sheet.max <= 72, `filas ${sheet.heights.join(', ')}px`);
    check('Hábitos no vuelven a estirar la fila', sheet.habitMax <= 42, `${sheet.habitMax}px`);
    check('La tabla mantiene desplazamiento horizontal útil', sheet.horizontal);
    await page.screenshot({ path: path.join(__dirname, '..', 'e2e-report', 'ui-challenge-log-compact-mobile.png'), fullPage: true });

    await internalNavigate(page, '/?page=profile', '.profile-hero');
    const profileTraining = await page.evaluate(() => {
        const rank = document.querySelector('[data-profile-block="training_rank"]');
        const progress = document.querySelector('[data-profile-block="training_progress"]');
        const setup = document.querySelector('.profile-current-setup-card');
        const more = setup?.querySelector('.profile-setup-more');
        return {
            widgets: [rank, progress].filter((card) => card && getComputedStyle(card).display !== 'none').length,
            stats: progress?.querySelectorAll('.profile-training-stat-grid > span').length || 0,
            setupRows: setup?.querySelectorAll(':scope > .profile-home-facts > div').length || 0,
            moreRows: more?.querySelectorAll('.profile-home-facts > div').length || 0,
            moreClosed: Boolean(more && !more.open),
        };
    });
    check('Perfil incorpora widgets de rango y progreso', profileTraining.widgets === 2 && profileTraining.stats === 4,
        `${profileTraining.widgets} widgets · ${profileTraining.stats} métricas`);
    check('Configuración actual resume y ofrece Ver más', profileTraining.setupRows === 4 && profileTraining.moreRows >= 4 && profileTraining.moreClosed,
        `${profileTraining.setupRows} visibles + ${profileTraining.moreRows} ampliables`);
    await page.screenshot({ path: path.join(__dirname, '..', 'e2e-report', 'ui-profile-training-mobile.png'), fullPage: true });
    const friendRows = await page.evaluate(() => {
        const rows = [...document.querySelectorAll('.profile-friend-row')];
        return {
            count: rows.length,
            maxHeight: Math.max(0, ...rows.map((row) => Math.round(row.getBoundingClientRect().height))),
            standaloneCompare: document.querySelectorAll('.profile-friend-row > .profile-friend-actions > a[href*="compare="]').length,
            menuCompare: document.querySelectorAll('.profile-friend-row .kebab-menu-panel a[href*="compare="]').length,
        };
    });
    check('Las amistades mantienen una sola fila en móvil', friendRows.count === 0 || friendRows.maxHeight <= 66, `${friendRows.count} filas · ${friendRows.maxHeight}px`);
    check('Comparar vive dentro del menú de tres puntos', friendRows.standaloneCompare === 0,
        `${friendRows.menuCompare} accesos en menú`);

    await page.locator('[data-app-modal-open="profile-level-modal"]').click();
    await page.locator('#profile-level-modal.is-open').waitFor();
    const modalColors = await page.evaluate(() => {
        const card = document.querySelector('#profile-level-modal .app-modal-card');
        const head = card?.querySelector(':scope > .app-modal-head');
        if (!card || !head) return null;
        const cardStyle = getComputedStyle(card);
        const headStyle = getComputedStyle(head);
        return {
            card: cardStyle.backgroundColor,
            head: headStyle.backgroundColor,
            cardImage: cardStyle.backgroundImage,
            headImage: headStyle.backgroundImage,
        };
    });
    check('Cabecera y cuerpo del modal comparten color', Boolean(modalColors)
        && modalColors.card === modalColors.head && modalColors.cardImage === modalColors.headImage,
        modalColors ? `${modalColors.card} / ${modalColors.head}` : 'sin modal');
    await page.locator('#profile-level-modal [data-app-modal-close]').click();

    await page.locator('details.user-menu > summary').click();
    const userMenu = await page.evaluate(() => {
        const panel = document.querySelector('.topbar .user-menu-panel');
        const view = panel?.querySelector('[data-menu-view="main"]');
        const items = view ? [...view.children].filter((item) => !item.hidden) : [];
        const normalItems = items.filter((item) => !item.classList.contains('user-menu-level'));
        const last = normalItems.at(-1);
        return {
            count: normalItems.length,
            wraps: normalItems.filter((item) => item.scrollHeight > item.clientHeight + 1).map((item) => item.textContent.trim()),
            panelWidth: Math.round(panel?.getBoundingClientRect().width || 0),
            lastWidth: Math.round(last?.getBoundingClientRect().width || 0),
            admin: view?.classList.contains('has-admin-item') || false,
        };
    });
    check('Dropdown de usuario no corta ni duplica hileras', userMenu.wraps.length === 0, userMenu.wraps.join(', '));
    check('La salida cierra la última fila del menú admin', !userMenu.admin || userMenu.lastWidth >= userMenu.panelWidth * 0.9,
        `${userMenu.lastWidth}/${userMenu.panelWidth}px`);
    await page.screenshot({ path: path.join(__dirname, '..', 'e2e-report', 'ui-user-menu-mobile-clean.png'), fullPage: false });
    await page.locator('details.user-menu > summary').click();

    await page.setViewportSize({ width: 820, height: 900 });
    await internalNavigate(page, '/?page=profile&user_id=2', '.profile-hero');
    const externalHero = await page.evaluate(() => {
        const hero = document.querySelector('.profile-hero');
        const title = hero?.querySelector('.profile-title');
        const actions = hero?.querySelector('.profile-hero-actions');
        const hr = hero?.getBoundingClientRect();
        const tr = title?.getBoundingClientRect();
        const ar = actions?.getBoundingClientRect();
        return {
            kebabs: hero?.querySelectorAll('.profile-hero-actions .kebab-menu').length || 0,
            trainingWidgets: document.querySelectorAll('[data-profile-block="training_rank"], [data-profile-block="training_progress"]').length,
            contained: Boolean(hr && ar && ar.left >= hr.left - 1 && ar.right <= hr.right + 1 && ar.top >= hr.top - 1),
            sameRow: Boolean(tr && ar && Math.abs(tr.top - ar.top) < 28),
            heroHeight: Math.round(hr?.height || 0),
        };
    });
    check('Perfil ajeno usa un único menú de acciones', externalHero.kebabs === 1, `${externalHero.kebabs} menús`);
    check('Perfil ajeno conserva contexto público de Training', externalHero.trainingWidgets === 2, `${externalHero.trainingWidgets} widgets`);
    check('Hero de perfil tablet queda contenido y alineado', externalHero.contained && externalHero.sameRow && externalHero.heroHeight <= 260,
        `${externalHero.heroHeight}px`);
    await page.screenshot({ path: path.join(__dirname, '..', 'e2e-report', 'ui-external-profile-tablet.png'), fullPage: false });

    await page.setViewportSize({ width: 390, height: 844 });
    await internalNavigate(page, '/?page=team&section=stats', '.team-widget-metrics');
    const teamMetrics = await page.evaluate(() => {
        const cards = [...document.querySelectorAll('.team-widget-metrics > .metric-card:not(.team-mobile-combo-card)')];
        const leaderboard = [...document.querySelectorAll('.team-leaderboard-metrics')].map((grid) => {
            const heights = [...grid.children].map((item) => Math.round(item.getBoundingClientRect().height));
            return { heights, delta: heights.length ? Math.max(...heights) - Math.min(...heights) : 0 };
        });
        return {
            total: cards.length,
            visible: cards.filter((card) => getComputedStyle(card).display !== 'none' && card.getBoundingClientRect().height > 0).length,
            labels: cards.map((card) => card.textContent.trim().replace(/\s+/g, ' ').slice(0, 45)),
            leaderboard,
        };
    });
    check('Team muestra todas las estadísticas en móvil', teamMetrics.total >= 4 && teamMetrics.visible === teamMetrics.total,
        `${teamMetrics.visible}/${teamMetrics.total}`);
    check('Métricas del leaderboard tienen la misma altura', teamMetrics.leaderboard.every((row) => row.delta <= 1),
        teamMetrics.leaderboard.map((row) => row.heights.join('/')).join(' · '));
    await page.screenshot({ path: path.join(__dirname, '..', 'e2e-report', 'ui-team-stats-mobile.png'), fullPage: true });

    check('Sin errores JavaScript durante la suite', errors.length === 0, errors.slice(0, 3).join(' | '));
    await browser.close();
    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length > 0) process.exitCode = 1;
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
