/**
 * Browser QA for per-user exercise favorites and editable catalogue copies.
 * Run only against a disposable QA database.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8113').replace(/\/$/, '');
const PASSWORD = value('password', 'Verify123!');
const reportDir = path.join(__dirname, '..', 'e2e-report');
const checks = [];

const check = (name, pass, detail = '') => {
    checks.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(name, condition, detail);
    if (!condition) throw new Error(`${name}${detail ? `: ${detail}` : ''}`);
};
const login = async (page, username = 'roberto') => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
};
const overflow = async (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

(async () => {
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const pageErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('console', (message) => {
        const source = message.location().url || '';
        if (message.type() === 'error' && source.startsWith(BASE)) pageErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    page.on('dialog', (dialog) => dialog.accept());

    let originalId = 0;
    let copyId = 0;
    let originalName = '';

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts&view=library`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-scope-tabs a').count() === 3, 'biblioteca separa Todos, Favoritos y Mis ejercicios');
        ensure(await page.locator('.workouts-library-card').count() <= 12, 'personalizacion conserva paginacion de 12');
        const initialTabs = await page.locator('.workouts-library-scope-tabs a').evaluateAll((links) => links.map((link) => ({
            text: link.textContent.trim(),
            height: Math.round(link.getBoundingClientRect().height),
        })));
        check('tabs personales tienen objetivos tactiles de 44 px', initialTabs.every((tab) => tab.height >= 44), JSON.stringify(initialTabs));

        const systemCard = page.locator('.workouts-library-card:not(.is-personal)').first();
        originalName = (await systemCard.locator('h3').innerText()).trim();
        const guideHref = await systemCard.locator('h3 a').getAttribute('href');
        originalId = Number(new URL(`${BASE}${guideHref}`).searchParams.get('exercise_id'));
        ensure(originalId > 0 && originalName !== '', 'ejercicio de catalogo disponible para personalizar', `${originalName} #${originalId}`);

        await Promise.all([
            page.waitForLoadState('networkidle'),
            systemCard.locator('.workouts-favorite-toggle').click(),
        ]);
        const favoritedCard = page.locator('.workouts-library-card').filter({ hasText: originalName }).first();
        ensure(await favoritedCard.locator('.workouts-favorite-toggle.is-active').count() === 1, 'estrella guarda favorito desde la tarjeta');

        await page.locator('.workouts-library-scope-tabs a[href*="scope=favorites"]').click();
        await page.waitForURL((url) => url.searchParams.get('scope') === 'favorites');
        await page.waitForLoadState('networkidle');
        ensure(await page.locator('.workouts-library-card').filter({ hasText: originalName }).count() === 1, 'vista Favoritos filtra por usuario');
        ensure((await overflow(page)) <= 1, 'Favoritos movil no desborda');

        const favoriteGuideHref = await page.locator('.workouts-library-card').filter({ hasText: originalName }).locator('h3 a').getAttribute('href');
        await page.goto(`${BASE}${favoriteGuideHref}`, { waitUntil: 'networkidle' });
        const cloneForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="exercise_clone"]') });
        ensure(await cloneForm.count() === 1, 'guia del catalogo ofrece Personalizar');
        ensure(await page.locator('.workouts-exercise-hero-actions button[aria-pressed="true"]').count() === 1, 'guia refleja estado favorito');
        await page.screenshot({ path: path.join(reportDir, 'ui-exercise-catalog-customize-mobile.png'), fullPage: true });

        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('custom_exercise')) > 0),
            cloneForm.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        copyId = Number(new URL(page.url()).searchParams.get('custom_exercise'));
        const copyEditor = page.locator('form.workouts-custom-editor');
        ensure(copyId > 0 && await copyEditor.count() === 1, 'Personalizar crea y abre una copia privada', `#${copyId}`);
        const copiedName = await copyEditor.locator('input[name="name"]').inputValue();
        const copiedSteps = (await copyEditor.locator('textarea[name="steps"]').inputValue()).split(/\r?\n/).filter(Boolean);
        const localizedCopySuffix = /(?:copia|copy)$/i.test(copiedName.trim());
        ensure(localizedCopySuffix && copiedSteps.length >= 3, 'copia conserva nombre localizado y guia completa', `${copiedName} · ${copiedSteps.length} pasos`);

        await copyEditor.locator('[data-workout-editor-step-trigger="media"]').click();
        await copyEditor.locator('[data-workout-photo-details] summary').click();
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR42mP8z8AARAwMjDAGAAANHQEDasKb6QAAAABJRU5ErkJggg==', 'base64');
        await copyEditor.locator('input[name="exercise_image"]').setInputFiles({ name: 'catalog-copy.png', mimeType: 'image/png', buffer: png });
        await copyEditor.locator('[data-workout-video-details] summary').click();
        await copyEditor.locator('input[name="video_url"]').fill('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        await page.waitForSelector('[data-workout-video-preview] iframe[src*="youtube-nocookie.com"]');
        await page.screenshot({ path: path.join(reportDir, 'ui-exercise-catalog-copy-editor-mobile.png'), fullPage: true });
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('exercise_id')) === copyId),
            copyEditor.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        const copiedViewer = page.locator('.workouts-guide-media [data-workout-media-viewer]');
        ensure(await copiedViewer.locator('[data-workout-media-panel="photo"] img').count() === 1, 'copia acepta una foto propia');
        ensure(await copiedViewer.locator('iframe').count() === 0, 'copia no carga YouTube antes de reproducir');
        await copiedViewer.locator('[data-workout-media-tab="video"]').click();
        await copiedViewer.locator('[data-workout-video-load]').click();
        await page.waitForSelector('.workouts-guide-video iframe[src*="youtube-nocookie.com"]');
        ensure(await copiedViewer.locator('iframe[src*="youtube-nocookie.com"]').count() === 1, 'copia acepta YouTube propio bajo demanda');
        ensure(await page.locator('.workouts-exercise-edit').count() === 1, 'copia se puede seguir editando');

        await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-card').count() === 2, 'copia nueva se fija automaticamente junto al original');
        const originalFavoriteCard = page.locator('.workouts-library-card').filter({ hasText: originalName }).filter({ hasNotText: 'Copia' }).first();
        await Promise.all([
            page.waitForLoadState('networkidle'),
            originalFavoriteCard.locator('.workouts-favorite-toggle').click(),
        ]);
        ensure(await page.locator('.workouts-library-card').count() === 1, 'quitar favorito actualiza la vista sin ruido');
        ensure(await page.locator('.workouts-library-card.is-personal').count() === 1, 'favoritos conserva solo la copia personal');
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(reportDir, 'ui-exercise-favorites-mobile.png'), fullPage: true });

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [
                '/?page=workouts&view=library',
                '/?page=workouts&view=library&scope=favorites',
                `/?page=workouts&exercise_id=${copyId}`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('personalizacion responde entre 320 y 1024 px', responsiveFailures.length === 0, responsiveFailures.join(', '));

        const outsider = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const outsiderPage = await outsider.newPage();
        await login(outsiderPage, 'catalina');
        await outsiderPage.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${copyId}`, { waitUntil: 'networkidle' });
        check('copia personalizada sigue siendo privada', await outsiderPage.locator('form.workouts-custom-editor').count() === 0);
        await outsider.close();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${copyId}`, { waitUntil: 'networkidle' });
        const deleteForm = page.locator('form.workouts-custom-danger');
        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('scope') === 'mine' && !url.searchParams.has('custom_exercise')),
            deleteForm.locator('button[type="submit"]').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts&view=library&scope=favorites`, { waitUntil: 'networkidle' });
        check('borrar la copia limpia sus preferencias', await page.locator('.workouts-library-card').count() === 0);

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de personalizacion de catalogo', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
