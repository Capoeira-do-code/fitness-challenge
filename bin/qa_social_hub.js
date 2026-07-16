/**
 * Social hub density, responsiveness and real-action contract.
 *
 * Run against a disposable fixture:
 *   node bin/qa_social_hub.js --base http://127.0.0.1:8120
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

const forceTheme = (page, theme) => page.evaluate((nextTheme) => {
    document.body.dataset.theme = nextTheme;
    document.body.classList.toggle('theme-active-dark', nextTheme === 'dark');
    document.body.classList.toggle('theme-active-light', nextTheme === 'light');
    document.documentElement.style.colorScheme = nextTheme;
}, theme);

const rootState = (page) => page.evaluate(() => {
    const screen = document.querySelector('.social-hub-screen');
    const rootActions = [...document.querySelectorAll('.social-quick-actions a')];
    const categories = [...document.querySelectorAll('.social-section-grid .hierarchy-nav-row')];
    const cards = [...document.querySelectorAll('.social-dashboard-grid .social-overview-card')];
    const interactive = [...document.querySelectorAll([
        '.social-quick-actions a',
        '.social-section-grid a',
        '.social-dashboard-grid a',
    ].join(','))].filter((element) => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    });
    const targetSizes = interactive.map((element) => {
        const rect = element.getBoundingClientRect();
        return { href: element.getAttribute('href') || '', width: Math.round(rect.width), height: Math.round(rect.height) };
    });
    const emptyBlocks = [...document.querySelectorAll('.social-inline-empty')];
    const screenRect = screen?.getBoundingClientRect();
    const cardSurfaces = cards.map((card) => getComputedStyle(card).backgroundColor);

    return {
        actions: rootActions.length,
        actionsReal: rootActions.every((action) => {
            const href = action.getAttribute('href') || '';
            return href.startsWith('/?page=') && href !== '/?page=social' && !href.includes('javascript:');
        }),
        categories: categories.length,
        categorySections: categories.map((item) => item.getAttribute('href') || ''),
        cards: cards.length,
        teamName: document.querySelector('.social-team-overview .social-featured-row strong')?.textContent?.trim() || '',
        teamAvatars: document.querySelectorAll('.social-team-overview .social-avatar-list a').length,
        competitionMetrics: document.querySelectorAll('.social-competition-metrics a').length,
        communityUseful: Boolean(document.querySelector('.social-community-overview .social-preview-grid-root a')
            || document.querySelector('.social-community-overview .social-inline-empty a')),
        circleUseful: Boolean(document.querySelector('.social-circle-overview .social-people-list a')
            || document.querySelector('.social-circle-overview .social-inline-empty a')),
        activityUseful: Boolean(document.querySelector('.social-activity-overview .social-activity-list a')
            || document.querySelector('.social-activity-overview .social-inline-empty a')),
        emptyBlocksActionable: emptyBlocks.every((block) => Boolean(block.querySelector('a[href^="/?page="]'))),
        minTargetHeight: targetSizes.length ? Math.min(...targetSizes.map((target) => target.height)) : 0,
        shortTargets: targetSizes.filter((target) => target.height < 44),
        width: Math.round(screenRect?.width || 0),
        height: Math.round(Math.max(screen?.scrollHeight || 0, screenRect?.height || 0)),
        overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1,
        cardSurfaces,
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
        await page.goto(`${BASE}/?page=social`, { waitUntil: 'networkidle' });
        await forceTheme(page, 'light');

        const initial = await rootState(page);
        check('Social ofrece cuatro acciones rápidas reales', initial.actions === 4 && initial.actionsReal, JSON.stringify(initial.categorySections));
        check('Social conserva tres niveles de entrada claros', initial.categories === 3
            && initial.categorySections.every((href) => href.includes('page=social&section=')), JSON.stringify(initial.categorySections));
        check('Social muestra cinco módulos útiles', initial.cards === 5);
        check('El módulo de equipo usa datos reales', initial.teamName.length > 0 && initial.teamAvatars >= 2,
            `${initial.teamName} / ${initial.teamAvatars} miembros visibles`);
        check('El estado competitivo separa sus tres métricas', initial.competitionMetrics === 3);
        check('Comunidad, círculo y actividad nunca quedan mudos', initial.communityUseful && initial.circleUseful
            && initial.activityUseful && initial.emptyBlocksActionable);
        check('La raíz Social tiene contenido sin convertirse en feed infinito', initial.height >= 700 && initial.height <= 844 * 2.5,
            `${initial.height}px`);
        check('Social móvil no desborda', !initial.overflow);
        check('Objetivos táctiles de Social alcanzan 44px', initial.minTargetHeight >= 44,
            JSON.stringify(initial.shortTargets));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-social-hub-rich-mobile.png'), fullPage: true });

        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width === 320 ? 568 : 844 });
            await page.goto(`${BASE}/?page=social`, { waitUntil: 'networkidle' });
            const state = await rootState(page);
            check(`Social estable a ${width}px`, !state.overflow && state.actions === 4 && state.categories === 3
                && state.cards === 5 && state.minTargetHeight >= 44,
            JSON.stringify({ overflow: state.overflow, height: state.height, minTargetHeight: state.minTargetHeight, shortTargets: state.shortTargets }));
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=social`, { waitUntil: 'networkidle' });
        await forceTheme(page, 'dark');
        await page.waitForTimeout(100);
        const dark = await rootState(page);
        check('Las tarjetas conservan superficies sólidas en oscuro', dark.cardSurfaces.length === 5
            && dark.cardSurfaces.every((surface) => surface !== 'transparent' && surface !== 'rgba(0, 0, 0, 0)'),
        JSON.stringify(dark.cardSurfaces));
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-social-hub-rich-dark.png'), fullPage: true });

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}/?page=social`, { waitUntil: 'networkidle' });
        await forceTheme(page, 'light');
        const desktop = await rootState(page);
        check('Desktop recibe los mismos módulos sociales', desktop.actions === 4 && desktop.categories === 3
            && desktop.cards === 5 && !desktop.overflow);
        await page.screenshot({ path: path.join(REPORT_DIR, 'ui-social-hub-rich-desktop.png'), fullPage: true });

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
        console.log('\nHub Social enriquecido validado.');
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
