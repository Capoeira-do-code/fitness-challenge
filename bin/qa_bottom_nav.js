/**
 * Mobile bottom-navigation visual/interaction contract.
 *
 * Run against a disposable server:
 *   node bin/qa_bottom_nav.js --base http://127.0.0.1:8120
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
const check = (name, pass, detail = '') => {
    const ok = Boolean(pass);
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
    if (!ok) failures.push(`${name}${detail ? `: ${detail}` : ''}`);
};

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
};

const forceTheme = async (page, theme) => {
    await page.evaluate((nextTheme) => {
        document.body.dataset.theme = nextTheme;
        document.body.classList.toggle('theme-active-dark', nextTheme === 'dark');
        document.body.classList.toggle('theme-active-light', nextTheme === 'light');
        document.documentElement.style.colorScheme = nextTheme;
    }, theme);
    // The navigation deliberately animates its colour state. Measure the settled
    // theme so the contrast contract does not sample halfway through a transition.
    await page.waitForTimeout(220);
};

const navGeometry = (page) => page.evaluate(() => {
    const nav = document.querySelector('nav.bottom-nav.mobile-liquid-nav');
    const pill = nav?.querySelector(':scope > .liquid-nav-pill');
    const links = [...(pill?.querySelectorAll(':scope > .liquid-nav-item') || [])];
    const plus = pill?.querySelector(':scope > .liquid-nav-plus > summary');
    const targets = [...links, plus].filter(Boolean);
    const navRect = nav?.getBoundingClientRect();
    const pillRect = pill?.getBoundingClientRect();
    const targetRects = targets.map((target) => target.getBoundingClientRect());
    const linkCenters = links.map((link) => {
        const rect = link.getBoundingClientRect();
        return rect.top + rect.height / 2;
    });
    const plusRect = plus?.getBoundingClientRect();
    const plusCenter = plusRect ? plusRect.top + plusRect.height / 2 : 0;
    const linkCenter = linkCenters.length
        ? linkCenters.reduce((sum, value) => sum + value, 0) / linkCenters.length
        : 0;
    const labels = [...(pill?.querySelectorAll('.nav-label') || [])];
    const pillStyle = pill ? getComputedStyle(pill) : null;
    const rgb = (value) => (value.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
    const luminance = (value) => {
        const channels = rgb(value).map((channel) => {
            const normalized = channel / 255;
            return normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
        });
        return channels.length === 3 ? (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]) : 0;
    };
    const contrast = (a, b) => {
        const values = [luminance(a), luminance(b)].sort((left, right) => right - left);
        return (values[0] + 0.05) / (values[1] + 0.05);
    };
    const labelContrasts = labels.map((label) => contrast(getComputedStyle(label).color, pillStyle?.backgroundColor || 'rgb(255,255,255)'));
    return {
        destinations: links.length,
        create: Boolean(plus),
        active: links.filter((link) => link.getAttribute('aria-current') === 'page').length,
        centered: Boolean(navRect && Math.abs((navRect.left + navRect.width / 2) - innerWidth / 2) <= 1),
        width: Math.round(navRect?.width || 0),
        pillWidth: Math.round(pillRect?.width || 0),
        viewport: innerWidth,
        height: Math.round(navRect?.height || 0),
        pillHeight: Math.round(pillRect?.height || 0),
        bottomGap: Math.round(innerHeight - (navRect?.bottom || innerHeight)),
        equalSlots: targetRects.length === 5
            && Math.max(...targetRects.map((rect) => rect.width)) - Math.min(...targetRects.map((rect) => rect.width)) <= 1.5,
        targetWidths: targetRects.map((rect) => Math.round(rect.width)),
        minTarget: targetRects.length ? Math.round(Math.min(...targetRects.map((rect) => Math.min(rect.width, rect.height)))) : 0,
        plusAligned: Math.abs(plusCenter - linkCenter) <= 1.5,
        labelsFit: labels.every((label) => label.scrollWidth <= label.clientWidth + 1),
        minLabelContrast: labelContrasts.length ? Math.min(...labelContrasts) : 0,
        labelColors: labels.map((label) => getComputedStyle(label).color),
        pillMatches: Boolean(navRect && pillRect && Math.abs(navRect.width - pillRect.width) <= 1
            && Math.abs(navRect.height - pillRect.height) <= 1),
        surface: pillStyle?.backgroundColor || '',
        overflow: document.documentElement.scrollWidth > innerWidth + 1,
    };
});

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

        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width === 320 ? 568 : 844 });
            await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
            await forceTheme(page, 'light');
            const state = await navGeometry(page);
            const phone = width <= 700;
            const expectedWidth = phone ? width : 620;
            const expectedGap = phone ? 0 : 10;
            check(`Barra nativa estable a ${width}px`, state.destinations === 4 && state.create
                && state.active === 1 && state.centered && state.width === expectedWidth
                && state.height === 68 && state.bottomGap === expectedGap
                && state.equalSlots && state.minTarget >= 60
                && state.plusAligned && state.labelsFit && state.minLabelContrast >= 4.5
                && state.pillMatches && !state.overflow,
            JSON.stringify(state));
        }

        const routes = [
            ['dashboard', 'dashboard'],
            ['workouts', 'table'],
            ['social', 'social'],
            ['profile', 'profile'],
        ];
        await page.setViewportSize({ width: 390, height: 844 });
        for (const [route, destination] of routes) {
            await page.goto(`${BASE}/?page=${route}`, { waitUntil: 'networkidle' });
            const activeDestination = await page.locator('.mobile-liquid-nav [aria-current="page"]').getAttribute('data-nav-destination');
            check(`${route} activa un único destino correcto`, activeDestination === destination, activeDestination || 'none');
        }

        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        await forceTheme(page, 'light');
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-bottom-nav-light-v4.png'), fullPage: false });
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await page.waitForTimeout(120);
        const endState = await page.evaluate(() => {
            const nav = document.querySelector('nav.bottom-nav.mobile-liquid-nav');
            const last = document.querySelector('main .screen > :last-child');
            const navRect = nav?.getBoundingClientRect();
            const lastRect = last?.getBoundingClientRect();
            return {
                navVisible: Boolean(navRect && navRect.top >= 0 && navRect.bottom <= innerHeight + 1),
                contentClear: Boolean(navRect && lastRect && lastRect.bottom <= navRect.top - 8),
                gap: Math.round((navRect?.top || 0) - (lastRect?.bottom || 0)),
            };
        });
        check('El final del contenido puede quedar completamente sobre la barra', endState.navVisible && endState.contentClear,
            JSON.stringify(endState));

        const plus = page.locator('details.bottom-nav-plus');
        await plus.locator(':scope > summary').click();
        await page.locator('.bottom-nav-plus-menu:visible').waitFor();
        const menuState = await page.evaluate(() => {
            const panel = document.querySelector('.bottom-nav-plus-menu');
            const view = panel?.querySelector('[data-menu-view="main"]');
            const panelRect = panel?.getBoundingClientRect();
            const viewRect = view?.getBoundingClientRect();
            const columns = panel ? getComputedStyle(panel).gridTemplateColumns.split(/\s+/).filter(Boolean).length : 0;
            const choices = [...(view?.querySelectorAll(':scope > [data-menu-open]') || [])];
            return {
                centered: Boolean(panelRect && Math.abs((panelRect.left + panelRect.width / 2) - innerWidth / 2) <= 1),
                columns,
                viewUsesWidth: Boolean(panelRect && viewRect && viewRect.width >= panelRect.width - 22),
                choices: choices.length,
                aligned: choices.every((choice) => {
                    const rect = choice.getBoundingClientRect();
                    return viewRect && Math.abs(rect.left - viewRect.left) <= 1 && Math.abs(rect.right - viewRect.right) <= 1;
                }),
            };
        });
        check('El menú + usa una única columna centrada y completa', menuState.centered
            && menuState.columns === 1 && menuState.viewUsesWidth && menuState.choices === 2 && menuState.aligned,
        JSON.stringify(menuState));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-bottom-nav-menu-v4.png'), fullPage: false });
        await page.keyboard.press('Escape');

        await forceTheme(page, 'dark');
        const darkState = await navGeometry(page);
        check('La barra mantiene contraste y una superficie sólida en oscuro', darkState.surface !== 'rgba(0, 0, 0, 0)'
            && darkState.surface !== 'transparent' && darkState.minLabelContrast >= 4.5,
        `${darkState.surface} · contraste ${darkState.minLabelContrast.toFixed(2)}:1`);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-bottom-nav-dark-v4.png'), fullPage: false });

        const keyboardSimulation = await page.evaluate(() => {
            const input = document.createElement('input');
            input.type = 'text';
            input.setAttribute('aria-label', 'Keyboard QA');
            input.style.position = 'fixed';
            input.style.top = '0';
            document.body.appendChild(input);
            input.focus();
            if (!window.visualViewport) return false;
            try {
                Object.defineProperty(window.visualViewport, 'height', {
                    configurable: true,
                    value: Math.max(200, window.innerHeight - 220),
                });
                Object.defineProperty(window.visualViewport, 'offsetTop', { configurable: true, value: 0 });
                window.visualViewport.dispatchEvent(new Event('resize'));
                return true;
            } catch (_) {
                return false;
            }
        });
        await page.waitForTimeout(240);
        const keyboardState = await page.evaluate(() => {
            const nav = document.querySelector('nav.bottom-nav.mobile-liquid-nav');
            const style = nav ? getComputedStyle(nav) : null;
            return {
                classApplied: document.body.classList.contains('mobile-keyboard-open'),
                visibility: style?.visibility || '',
                pointerEvents: style?.pointerEvents || '',
                opacity: Number.parseFloat(style?.opacity || '1'),
                transform: style?.transform || '',
            };
        });
        check('La barra se retira cuando se abre el teclado móvil', keyboardSimulation && keyboardState.classApplied
            && keyboardState.visibility === 'hidden' && keyboardState.pointerEvents === 'none'
            && keyboardState.opacity === 0, JSON.stringify(keyboardState));

        await page.setViewportSize({ width: 844, height: 390 });
        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        const landscapeState = await navGeometry(page);
        check('La orientación horizontal conserva alineación y objetivos táctiles', landscapeState.centered
            && landscapeState.width === 620 && landscapeState.height === 58 && landscapeState.bottomGap === 10
            && landscapeState.minTarget >= 50 && landscapeState.plusAligned && !landscapeState.overflow,
        JSON.stringify(landscapeState));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-bottom-nav-landscape-v4.png'), fullPage: false });

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
        console.log('\nBarra inferior móvil validada.');
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
