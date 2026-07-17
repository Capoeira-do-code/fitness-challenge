/**
 * Browser QA for the active workout → contextual library hierarchy.
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
const BASE = value('base', 'http://127.0.0.1:8117').replace(/\/$/, '');
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
const cancelActiveSession = async (page) => {
    await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
    const resume = page.locator('.workouts-resume-banner');
    if (!await resume.count()) return;
    await resume.click();
    await page.waitForLoadState('networkidle');
    const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
    if (await cancel.count()) {
        await Promise.all([page.waitForLoadState('networkidle'), cancel.locator('button[type="submit"]').click()]);
    }
};

(async () => {
    fs.mkdirSync(reportDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('dialog', (dialog) => dialog.accept());
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

    let sessionId = 0;
    let customExerciseId = 0;
    const customExerciseName = `QA En sesión ${Date.now()}`;

    try {
        await login(page);
        await cancelActiveSession(page);
        const start = page.locator('form.workouts-start-card').filter({ has: page.locator('input[name="action"][value="session_start"]') });
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) > 0),
            start.locator('button[type="submit"]').click(),
        ]);
        sessionId = Number(new URL(page.url()).searchParams.get('session_id'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(350);
        ensure(sessionId > 0, 'inicia una sesión vacía compartible', `#${sessionId}`);
        ensure(await page.locator('.workouts-session-add-exercise').count() === 1, 'sesión ofrece una única fila para añadir ejercicios');
        ensure(await page.locator('.workouts-session-add-exercise select').count() === 0, 'sesión elimina el selector largo del catálogo');
        const addHref = await page.locator('.workouts-session-add-exercise').getAttribute('href');
        ensure((addHref || '').includes(`target_session_id=${sessionId}`), 'la fila abre la biblioteca con la sesión destino');
        const addHeight = await page.locator('.workouts-session-add-exercise').evaluate((node) => node.getBoundingClientRect().height);
        ensure(addHeight >= 44, 'añadir ejercicio mantiene un objetivo táctil amplio', `${Math.round(addHeight)}px`);
        await page.screenshot({ path: path.join(reportDir, 'ui-session-empty-mobile.png'), fullPage: true });

        await page.goto(`${BASE}${addHref}`, { waitUntil: 'networkidle' });
        ensure(Number(new URL(page.url()).searchParams.get('target_session_id')) === sessionId, 'biblioteca conserva la sesión activa');
        ensure(await page.locator('.workouts-library-target').count() === 1, 'biblioteca identifica el entrenamiento destino');
        ensure(await page.locator('.workouts-library-grid.is-contextual').count() === 1, 'biblioteca usa filas compactas en sesión');
        ensure(await page.locator('.workouts-library-card').count() <= 12, 'biblioteca mantiene la paginación de doce');
        ensure(await page.locator('.workouts-library-add select').count() === 0, 'no repite selectores dentro de las tarjetas');

        const firstCard = page.locator('.workouts-library-card').first();
        const exerciseName = (await firstCard.locator('h3').innerText()).trim();
        const guideHref = await firstCard.locator('h3 a').getAttribute('href');
        ensure((guideHref || '').includes(`target_session_id=${sessionId}`), 'la guía conserva la sesión activa');
        await Promise.all([
            page.waitForLoadState('networkidle'),
            firstCard.locator('.workouts-library-add.is-contextual button').click(),
        ]);
        ensure(Number(new URL(page.url()).searchParams.get('target_session_id')) === sessionId, 'añadir permite seguir explorando la biblioteca');
        const addedCard = page.locator('.workouts-library-card').filter({ hasText: exerciseName }).first();
        ensure(await addedCard.locator('.workouts-library-add button:disabled').count() === 1, 'el ejercicio añadido cambia de estado');
        ensure(await page.locator('[role="status"], .flash, .toast').filter({ hasText: /workout|entrenamiento|allenamento/i }).count() >= 1, 'el aviso usa el contexto de entrenamiento');
        await page.waitForTimeout(350);
        await page.screenshot({ path: path.join(reportDir, 'ui-session-library-context-mobile.png'), fullPage: true });

        await page.goto(`${BASE}${guideHref}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-exercise-add-form.is-contextual button:disabled').count() === 1, 'la guía conoce que el ejercicio ya está en la sesión');
        const guideBack = await page.locator('.workouts-hero > a').getAttribute('href');
        ensure((guideBack || '').includes(`target_session_id=${sessionId}`), 'volver desde la guía mantiene la biblioteca contextual');

        await page.goto(`${BASE}/?page=workouts&view=library&target_session_id=${sessionId}`, { waitUntil: 'networkidle' });
        const customHref = await page.locator('.workouts-library-actions a').getAttribute('href');
        ensure((customHref || '').includes(`target_session_id=${sessionId}`), 'crear ejercicio propio conserva la sesión destino');
        await page.goto(`${BASE}${customHref}`, { waitUntil: 'networkidle' });
        const customForm = page.locator('form.workouts-custom-editor');
        ensure(await customForm.locator('input[name="target_session_id"]').inputValue() === String(sessionId), 'editor personalizado recibe la sesión');
        await customForm.locator('input[name="name"]').fill(customExerciseName);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) === sessionId),
            customForm.locator('button[type="submit"]').click(),
        ]);
        const customRow = page.locator('.workouts-session-exercise').filter({ has: page.locator('.workouts-session-exercise-head a').filter({ hasText: customExerciseName }) }).first();
        ensure(await customRow.count() === 1, 'ejercicio propio se añade directamente a la sesión');
        const customGuideHref = await customRow.locator('a[href*="?page=workouts&exercise_id="]').getAttribute('href');
        customExerciseId = Number(new URL(customGuideHref, BASE).searchParams.get('exercise_id'));
        ensure(customExerciseId > 0, 'ejercicio propio conserva su guía', `#${customExerciseId}`);
        ensure(await page.locator('.workouts-session-exercise').count() === 2, 'la sesión refleja ambos ejercicios sin recargar formularios');

        const unauthorizedContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const unauthorizedPage = await unauthorizedContext.newPage();
        await login(unauthorizedPage, 'catalina');
        await unauthorizedPage.goto(`${BASE}/?page=workouts&view=library&target_session_id=${sessionId}`, { waitUntil: 'networkidle' });
        ensure(await unauthorizedPage.locator('.workouts-library-target').count() === 0, 'otra persona no puede usar la sesión como destino');
        ensure(await unauthorizedPage.locator('input[name="target_session_id"]').count() === 0, 'contexto ajeno se elimina de los formularios');
        await unauthorizedContext.close();

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            for (const url of [
                `/?page=workouts&session_id=${sessionId}`,
                `/?page=workouts&view=library&target_session_id=${sessionId}`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}:${url}:${excess}`);
            }
        }
        check('sesión y biblioteca no desbordan entre 320 y 1024 px', responsiveFailures.length === 0, responsiveFailures.join(', '));

        await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle' });
        const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
        await Promise.all([page.waitForLoadState('networkidle'), cancel.locator('button[type="submit"]').click()]);
        await page.goto(`${BASE}/?page=workouts&view=library&target_session_id=${sessionId}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-library-target').count() === 0, 'una sesión cerrada deja de ser un destino válido');

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo jerárquico completo de sesión', false, error.stack || error.message);
    } finally {
        if (sessionId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanupSession = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
                if (await cleanupSession.count()) await cleanupSession.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        if (customExerciseId > 0 && !page.isClosed()) {
            try {
                await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${customExerciseId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const cleanupExercise = page.locator('.workouts-custom-danger');
                if (await cleanupExercise.count()) await cleanupExercise.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
