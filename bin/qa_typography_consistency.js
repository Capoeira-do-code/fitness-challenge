/**
 * Shared mobile navigation and typography contract.
 *
 * Run against a disposable fixture:
 *   node bin/qa_typography_consistency.js --base http://127.0.0.1:8121
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8121').replace(/\/$/, '');
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
    await page.fill('input[name="username"]', 'roberto');
    await page.fill('input[name="password"]', 'Verify123!');
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
    check('Inicio de sesión para auditoría tipográfica', !page.url().includes('page=login'), page.url());
};

const navMetrics = (page, rootSelector, titleSelector, detailSelector) => page.evaluate((selectors) => {
    const root = document.querySelector(selectors.root);
    const titles = [...document.querySelectorAll(`${selectors.root} ${selectors.title}`)]
        .filter((node) => node.getClientRects().length > 0);
    const details = [...document.querySelectorAll(`${selectors.root} ${selectors.detail}`)]
        .filter((node) => node.getClientRects().length > 0);
    const rows = titles.map((title) => title.closest('a')).filter(Boolean);
    const icons = [...(root?.querySelectorAll('.hierarchy-nav-icon, .settings-nav-icon') || [])]
        .filter((node) => node.getClientRects().length > 0);
    const analyticsMenu = root?.querySelector('.mobile-hub-section-grid');
    const analyticsMetrics = root?.querySelector('.analytics-mobile-kpis');
    const readFonts = (nodes) => [...new Set(nodes.map((node) => Number.parseFloat(getComputedStyle(node).fontSize).toFixed(2)))];
    return {
        visible: Boolean(root && root.getClientRects().length > 0),
        titleFonts: readFonts(titles),
        detailFonts: readFonts(details),
        wrappedTitles: titles.filter((title) => title.getBoundingClientRect().height > Number.parseFloat(getComputedStyle(title).lineHeight) * 1.45)
            .map((title) => title.textContent.trim()),
        rowHeights: rows.map((row) => Math.round(row.getBoundingClientRect().height)),
        svgIcons: icons.length > 0 && icons.every((icon) => Boolean(icon.querySelector('svg'))),
        menuBeforeMetrics: analyticsMenu && analyticsMetrics
            ? Boolean(analyticsMenu.compareDocumentPosition(analyticsMetrics) & Node.DOCUMENT_POSITION_FOLLOWING)
            : null,
        overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
    };
}, { root: rootSelector, title: titleSelector, detail: detailSelector });

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const jsErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) jsErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);
        const surfaces = [
            ['Perfil', '/?page=profile', '.profile-mobile-root', '.hierarchy-nav-copy strong', '.hierarchy-nav-copy small'],
            ['Analytics', '/?page=analytics', '.analytics-mobile-root', '.hierarchy-nav-copy strong', '.hierarchy-nav-copy small'],
            ['Equipo', '/?page=team', '.team-mobile-root', '.hierarchy-nav-copy strong', '.hierarchy-nav-copy small'],
            ['Social', '/?page=social', '.social-section-grid', '.hierarchy-nav-copy strong', '.hierarchy-nav-copy small'],
            ['Entreno', '/?page=workouts', '.workouts-section-grid', '.hierarchy-nav-copy strong', '.hierarchy-nav-copy small'],
            ['Ajustes', '/?page=settings', '.settings-nav-grid', '.settings-nav-copy strong', '.settings-nav-copy small'],
        ];
        const allMetrics = [];
        for (const [name, url, root, title, detail] of surfaces) {
            await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
            const metrics = await navMetrics(page, root, title, detail);
            allMetrics.push({ name, ...metrics });
            check(`${name}: menú visible y sin overflow`, metrics.visible && !metrics.overflow, JSON.stringify(metrics));
            check(`${name}: títulos y descripciones usan una sola escala`, metrics.titleFonts.length === 1 && metrics.detailFonts.length === 1,
                JSON.stringify({ titleFonts: metrics.titleFonts, detailFonts: metrics.detailFonts }));
            check(`${name}: filas e iconos siguen el mismo componente`, new Set(metrics.rowHeights).size === 1
                && metrics.rowHeights[0] === 88 && metrics.svgIcons, JSON.stringify({ rowHeights: metrics.rowHeights, svgIcons: metrics.svgIcons }));
            const screenshotSlug = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            await page.screenshot({ path: path.join(REPORT_DIR, `ui-typography-${screenshotSlug}-mobile.png`), fullPage: false });
        }
        const titleFonts = [...new Set(allMetrics.flatMap((item) => item.titleFonts))];
        const detailFonts = [...new Set(allMetrics.flatMap((item) => item.detailFonts))];
        check('Todos los hubs comparten tamaño de título', titleFonts.length === 1 && Number(titleFonts[0]) >= 13.5 && Number(titleFonts[0]) <= 14.5,
            JSON.stringify(titleFonts));
        check('Todos los hubs comparten tamaño de descripción legible', detailFonts.length === 1 && Number(detailFonts[0]) >= 11 && Number(detailFonts[0]) <= 12.5,
            JSON.stringify(detailFonts));
        const profile = allMetrics.find((item) => item.name === 'Perfil');
        check('Perfil usa etiquetas compactas de una línea', profile && profile.wrappedTitles.length === 0,
            JSON.stringify(profile?.wrappedTitles || []));
        const analytics = allMetrics.find((item) => item.name === 'Analytics');
        check('Analytics muestra su menú antes del bloque extenso de métricas', analytics?.menuBeforeMetrics === true,
            JSON.stringify(analytics));

        await page.goto(`${BASE}/?page=achievements`, { waitUntil: 'networkidle' });
        const achievementMetrics = await page.evaluate(() => {
            const header = document.querySelector('.achievements-page-header');
            const h1 = header?.querySelector('h1');
            const panelTitle = document.querySelector('.achievements-page-panel .panel-head h2');
            const cardTitle = document.querySelector('.achievements-page-panel .achievement-list-content h3');
            const cardText = document.querySelector('.achievements-page-panel .achievement-list-content p');
            const back = header?.querySelector('.hierarchy-back');
            const number = document.querySelector('.achievement-summary-card strong');
            const statusChips = [...document.querySelectorAll('.achievement-list-title-row > .achievement-chip')];
            const font = (node) => node ? Number.parseFloat(getComputedStyle(node).fontSize) : 0;
            return {
                headerHeight: Math.round(header?.getBoundingClientRect().height || 0),
                headerColumns: header ? getComputedStyle(header).gridTemplateColumns : '',
                pageTitle: font(h1),
                panelTitle: font(panelTitle),
                cardTitle: font(cardTitle),
                cardTitleWrap: cardTitle ? getComputedStyle(cardTitle).overflowWrap : '',
                cardText: font(cardText),
                metricNumber: font(number),
                wrappedStatusChips: statusChips.filter((chip) => {
                    const range = document.createRange();
                    range.selectNodeContents(chip);
                    const lines = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));
                    return lines.size > 1;
                }).map((chip) => chip.textContent.trim()),
                backSize: back ? [Math.round(back.getBoundingClientRect().width), Math.round(back.getBoundingClientRect().height)] : [0, 0],
                overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
            };
        });
        check('Logros usa una cabecera compacta con Volver en la misma fila', achievementMetrics.headerHeight <= 76
            && achievementMetrics.backSize[0] === 44 && achievementMetrics.backSize[1] === 44,
        JSON.stringify(achievementMetrics));
        check('Logros respeta la escala compartida de página, sección y tarjeta', achievementMetrics.pageTitle === 20
            && achievementMetrics.panelTitle === 16 && achievementMetrics.cardTitle >= 13.5 && achievementMetrics.cardTitle <= 14.5
            && achievementMetrics.cardText >= 11 && achievementMetrics.cardText <= 12.5,
        JSON.stringify(achievementMetrics));
        check('Los números de Logros destacan sin agrandar el texto de navegación', achievementMetrics.metricNumber > achievementMetrics.cardTitle
            && !achievementMetrics.overflow, JSON.stringify(achievementMetrics));
        check('Las etiquetas de estado de Logros no parten palabras', achievementMetrics.wrappedStatusChips.length === 0,
            JSON.stringify(achievementMetrics.wrappedStatusChips));
        check('Los títulos de Logros sólo parten por palabras completas', achievementMetrics.cardTitleWrap === 'normal',
            achievementMetrics.cardTitleWrap);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-typography-achievements-mobile.png'), fullPage: false });

        await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-typography-profile-menu-mobile.png'), fullPage: false });
        await page.goto(`${BASE}/?page=profile&section=goals`, { waitUntil: 'networkidle' });
        const profileSectionHead = await page.evaluate(() => {
            const header = document.querySelector('.profile-section-header');
            const title = header?.querySelector('[data-navigation-focus]');
            const back = header?.querySelector('.hierarchy-back');
            const action = header?.querySelector('.profile-section-action');
            return {
                title: title?.textContent.trim() || '',
                font: title ? Number.parseFloat(getComputedStyle(title).fontSize) : 0,
                clipped: Boolean(title && title.scrollWidth > title.clientWidth + 1),
                back: back ? Math.round(back.getBoundingClientRect().height) : 0,
                action: action ? Math.round(action.getBoundingClientRect().height) : 0,
            };
        });
        check('Las subpantallas de Perfil usan la misma cabecera sin cortar el título', profileSectionHead.title === 'Objetivos'
            && profileSectionHead.font === 16 && !profileSectionHead.clipped
            && profileSectionHead.back === 44 && profileSectionHead.action >= 44, JSON.stringify(profileSectionHead));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-typography-profile-section-mobile.png'), fullPage: false });
        await page.goto(`${BASE}/?page=settings`, { waitUntil: 'networkidle' });
        const settingsCopy = await page.evaluate(() => ({
            eyebrow: document.querySelector('.settings-compact-header .eyebrow')?.textContent.trim() || '',
            goal: [...document.querySelectorAll('.settings-nav-copy strong')].map((node) => node.textContent.trim()).find((text) => text === 'Objetivos') || '',
        }));
        check('Ajustes conserva contexto de Perfil y textos en español', settingsCopy.eyebrow === 'Perfil' && settingsCopy.goal === 'Objetivos',
            JSON.stringify(settingsCopy));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-typography-settings-menu-mobile.png'), fullPage: false });

        for (const width of [320, 360, 430, 768]) {
            await page.setViewportSize({ width, height: width === 768 ? 1024 : 844 });
            for (const [name, url, root, title, detail] of [surfaces[0], surfaces[5]]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const metrics = await navMetrics(page, root, title, detail);
                check(`${name} ${width}px mantiene escala, filas y ancho`, !metrics.overflow
                    && metrics.titleFonts.join() === '14.00' && metrics.detailFonts.join() === '11.52'
                    && new Set(metrics.rowHeights).size === 1 && metrics.rowHeights[0] === 88 && metrics.svgIcons
                    && (name !== 'Perfil' || metrics.wrappedTitles.length === 0),
                JSON.stringify(metrics));
            }
            await page.goto(`${BASE}/?page=achievements`, { waitUntil: 'networkidle' });
            const achievementWidthState = await page.evaluate(() => ({
                header: Math.round(document.querySelector('.achievements-page-header')?.getBoundingClientRect().height || 0),
                title: Number.parseFloat(getComputedStyle(document.querySelector('.achievements-page-header h1')).fontSize),
                overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
            }));
            check(`Logros ${width}px conserva su cabecera compacta`, achievementWidthState.header <= 76
                && achievementWidthState.title === 20 && !achievementWidthState.overflow, JSON.stringify(achievementWidthState));
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
        const darkSurface = await page.evaluate(() => {
            document.body.setAttribute('data-theme', 'dark');
            document.body.classList.add('theme-active-dark');
            document.body.classList.remove('theme-active-light');
            const row = document.querySelector('.profile-mobile-root .hierarchy-nav-row');
            const style = getComputedStyle(row);
            return { background: style.backgroundColor, color: style.color };
        });
        check('El sistema de menús conserva contraste en oscuro', darkSurface.background !== 'rgba(0, 0, 0, 0)'
            && darkSurface.color !== darkSurface.background, JSON.stringify(darkSurface));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-typography-profile-menu-dark.png'), fullPage: false });

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}/?page=achievements`, { waitUntil: 'networkidle' });
        const desktopOverflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1);
        check('Logros desktop conserva su layout sin overflow', !desktopOverflow);
        const desktopWrappedStatus = await page.evaluate(() => [...document.querySelectorAll('.achievement-list-title-row > .achievement-chip')]
            .filter((chip) => {
                const range = document.createRange();
                range.selectNodeContents(chip);
                const lines = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));
                return lines.size > 1;
            }).map((chip) => chip.textContent.trim()));
        check('Logros desktop mantiene estados en una línea', desktopWrappedStatus.length === 0, JSON.stringify(desktopWrappedStatus));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-typography-achievements-desktop.png'), fullPage: false });

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
        console.log('\nSistema tipográfico y menús coherentes validados.');
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
