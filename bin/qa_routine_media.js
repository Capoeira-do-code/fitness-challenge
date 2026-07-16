/**
 * Browser QA for custom routine photos, video links and responsive covers.
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
const BASE = value('base', 'http://127.0.0.1:8128').replace(/\/$/, '');
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
const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', 'roberto');
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
    page.setDefaultTimeout(18000);
    const pageErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('console', (message) => {
        const source = message.location().url || '';
        if (message.type() === 'error' && source.startsWith(BASE) && !message.text().includes('404')) {
            pageErrors.push(message.text());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    page.on('dialog', (dialog) => dialog.accept());

    const routineName = `QA Multimedia ${Date.now()}`;
    const youtubeUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    const tinyPng = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR42mP8z8AARAwMjDAGAAANHQEDasKb6QAAAABJRU5ErkJggg==',
        'base64',
    );
    let routineId = 0;
    let sessionId = 0;
    let uploadedMediaPath = '';

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.locator('[data-app-modal-open="wk-new-routine-modal"]').first().click();
        const modal = page.locator('#wk-new-routine-modal');
        const form = modal.locator('form[data-workout-media-editor]');
        ensure(await modal.isVisible(), 'crear rutina abre el editor móvil');
        await form.locator('input[name="name"]').fill(routineName);
        await form.locator('input[name="description"]').fill('Rutina con portada personalizable');
        await form.locator('.workouts-routine-create-media > summary').click();
        ensure(await form.locator('.workouts-routine-create-media').getAttribute('open') !== null, 'multimedia opcional se abre bajo demanda');
        await form.locator('input[name="routine_image"]').setInputFiles({
            name: 'routine-cover.png',
            mimeType: 'image/png',
            buffer: tinyPng,
        });
        await form.locator('input[name="video_url"]').fill(youtubeUrl);
        await form.locator('input[name="cover_mode"][value="photo"] + span').click();
        await form.locator('input[name="image_position"][value="top"] + span').click();

        const previewImage = form.locator('[data-workout-image-preview]');
        const previewFrame = form.locator('[data-workout-video-preview] iframe');
        await previewFrame.waitFor({ state: 'attached' });
        ensure((await previewImage.getAttribute('src') || '').startsWith('blob:'), 'previsualización inmediata de la foto');
        ensure(await previewImage.evaluate((image) => getComputedStyle(image).objectPosition === '50% 18%'), 'encuadre se aplica en vivo a la previsualización');
        ensure((await previewFrame.getAttribute('src') || '').includes('youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'previsualización segura de YouTube');
        const mediaTargets = await form.locator('.workouts-routine-create-media summary, .workouts-custom-cover-picker label > span, .workouts-image-focus-picker label > span, input[name="routine_image"], input[name="video_url"], [data-workout-clear-video]').evaluateAll((nodes) => nodes.map((node) => {
            const rect = node.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        }));
        check('editor multimedia mantiene objetivos táctiles de 44 px', mediaTargets.every(([, height]) => height >= 44), JSON.stringify(mediaTargets));
        ensure((await overflow(page)) <= 1, 'editor multimedia sin overflow a 390 px');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-create-mobile.png'), fullPage: true });

        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            form.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));
        ensure(routineId > 0, 'rutina multimedia creada', `#${routineId}`);

        const summaryCover = page.locator('.workouts-routine-summary-cover');
        ensure(await summaryCover.locator('img').isVisible(), 'detalle muestra la foto de portada');
        ensure(await summaryCover.locator('img').evaluate((image) => getComputedStyle(image).objectPosition === '50% 18%'), 'detalle respeta el encuadre superior');
        ensure(await summaryCover.locator('span').count() === 1, 'detalle indica que también hay vídeo');
        ensure(await page.locator('.workouts-routine-summary-actions a[target="_blank"]').count() === 1, 'detalle ofrece abrir el vídeo original');
        const uploadedImageUrl = await summaryCover.locator('img').getAttribute('src');
        ensure(Boolean(uploadedImageUrl), 'foto subida expone una URL');
        uploadedMediaPath = new URL(uploadedImageUrl, BASE).searchParams.get('path') || '';
        const uploadedResponse = await page.request.get(new URL(uploadedImageUrl, BASE).href);
        check('foto subida se sirve correctamente', uploadedResponse.status() === 200, `${uploadedResponse.status()}`);
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-overview-mobile.png'), fullPage: true });

        const startForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).first();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) > 0),
            startForm.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        sessionId = Number(new URL(page.url()).searchParams.get('session_id'));
        ensure(sessionId > 0, 'sesión iniciada desde la rutina personalizada', `#${sessionId}`);
        ensure(await page.locator('.workouts-session-routine-cover img').isVisible(), 'sesión activa conserva la portada de rutina');
        ensure(await page.locator('.workouts-session-routine-cover img').evaluate((image) => getComputedStyle(image).objectPosition === '50% 18%'), 'sesión activa conserva el encuadre');
        ensure(await page.locator('.workouts-session-routine-cover b').count() === 1, 'sesión activa conserva el indicador de vídeo');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-session-mobile.png'), fullPage: true });
        const finishForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_finish"]') });
        await Promise.all([
            page.waitForLoadState('networkidle'),
            finishForm.locator('button[type="submit"]').click(),
        ]);
        sessionId = 0;

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        let card = page.locator('.workouts-routine-card').filter({ hasText: routineName }).first();
        ensure(await card.locator('.workouts-routine-card-cover img').isVisible(), 'Inicio de Entreno muestra la portada personalizada');
        ensure(await card.locator('.workouts-routine-card-cover img').evaluate((image) => getComputedStyle(image).objectPosition === '50% 18%'), 'tarjeta de rutina conserva el encuadre');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-cards-mobile.png'), fullPage: true });

        const themeToggle = page.locator('[data-theme-toggle]');
        const initialTheme = await page.locator('body').getAttribute('data-theme');
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'dark');
        }
        card = page.locator('.workouts-routine-card').filter({ hasText: routineName }).first();
        const darkCard = await card.evaluate((node) => getComputedStyle(node).backgroundColor);
        check('portada conserva superficie en tema oscuro', darkCard !== 'rgba(0, 0, 0, 0)', darkCard);
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-cards-mobile-dark.png'), fullPage: true });
        if (initialTheme !== 'dark') {
            await themeToggle.evaluate((button) => button.click());
            await page.waitForFunction(() => document.body.dataset.theme === 'light');
        }

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=media`, { waitUntil: 'networkidle' });
        const editForm = page.locator('form.workouts-routine-form');
        ensure(await editForm.locator('[data-workout-image-preview]').isVisible(), 'editor recupera la foto guardada');
        ensure(await editForm.locator('[data-workout-video-preview] iframe').count() === 1, 'editor recupera la previsualización de vídeo');
        ensure(await editForm.locator('input[name="image_position"][value="top"]:checked').count() === 1, 'editor recupera el encuadre guardado');
        await editForm.locator('input[name="image_position"][value="bottom"] + span').click();
        ensure(await editForm.locator('[data-workout-image-preview]').evaluate((image) => getComputedStyle(image).objectPosition === '50% 82%'), 'cambio de encuadre actualiza la foto al instante');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            editForm.locator('button[type="submit"]').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-routine-summary-cover img').evaluate((image) => getComputedStyle(image).objectPosition === '50% 82%'), 'nuevo encuadre inferior persiste en la rutina');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-focus-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=media`, { waitUntil: 'networkidle' });
        const videoModeForm = page.locator('form.workouts-routine-form');
        await videoModeForm.locator('input[name="cover_mode"][value="video"] + span').click();
        await Promise.all([
            page.waitForLoadState('networkidle'),
            videoModeForm.locator('button[type="submit"]').click(),
        ]);
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const videoCoverSrc = await page.locator('.workouts-routine-summary-cover img').getAttribute('src');
        ensure((videoCoverSrc || '').includes('i.ytimg.com/vi/dQw4w9WgXcQ/'), 'modo vídeo usa miniatura de YouTube', videoCoverSrc || 'sin portada');
        ensure(await page.locator('.workouts-routine-summary-cover img').evaluate((image) => getComputedStyle(image).objectPosition === '50% 50%'), 'miniatura de vídeo mantiene encuadre neutro');
        await page.screenshot({ path: path.join(reportDir, 'ui-routine-media-youtube-cover-mobile.png'), fullPage: true });

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [
                '/?page=workouts',
                `/?page=workouts&routine_id=${routineId}`,
                `/?page=workouts&routine_id=${routineId}&section=settings&settings_view=media`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('portadas de rutina sin overflow de 320 a 1440 px', responsiveFailures.length === 0, responsiveFailures.join(', '));

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`, { waitUntil: 'networkidle' });
        const deleteForm = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
        await Promise.all([
            page.waitForURL((url) => !url.searchParams.has('routine_id')),
            deleteForm.locator('button[type="submit"]').click(),
        ]);
        routineId = 0;
        check('QA elimina la rutina temporal', true);

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de multimedia de rutinas', false, error.stack || error.message);
    } finally {
        if (sessionId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const finish = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_finish"]') });
                if (await finish.count()) await finish.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best-effort cleanup */ }
        }
        if (routineId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanup = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await cleanup.count()) await cleanup.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best-effort cleanup */ }
        }
        await browser.close();
        if (uploadedMediaPath) {
            const uploadRoot = path.resolve(__dirname, '..', 'storage', 'uploads');
            const uploadFile = path.resolve(uploadRoot, uploadedMediaPath);
            if (uploadFile.startsWith(`${uploadRoot}${path.sep}`) && path.basename(uploadFile).startsWith('routine_')) {
                try { fs.unlinkSync(uploadFile); } catch (_) { /* already absent */ }
            }
        }
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
