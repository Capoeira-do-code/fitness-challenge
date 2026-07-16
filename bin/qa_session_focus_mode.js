/**
 * Browser QA for the mobile one-exercise-at-a-time workout session.
 * Run only against a disposable database.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8123').replace(/\/$/, '');
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
const overflow = (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

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

    const routineName = `QA Focus ${Date.now()}`;
    let routineId = 0;
    let sessionId = 0;

    const addExercise = async (query) => {
        await page.goto(`${BASE}/?page=workouts&view=library&target_routine_id=${routineId}&q=${encodeURIComponent(query)}`, { waitUntil: 'networkidle' });
        const card = page.locator('.workouts-library-card:not(.is-personal)').first();
        ensure(await card.count() === 1, `biblioteca encuentra ${query}`);
        const name = (await card.locator('h3').innerText()).trim();
        await Promise.all([
            page.waitForLoadState('networkidle'),
            card.locator('.workouts-library-add.is-contextual button').click(),
        ]);
        return name;
    };

    try {
        await login(page);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.locator('[data-app-modal-open="wk-new-routine-modal"]').first().click();
        const modal = page.locator('#wk-new-routine-modal');
        await modal.locator('input[name="name"]').fill(routineName);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            modal.locator('button[type="submit"]').click(),
        ]);
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));

        const exerciseNames = [];
        exerciseNames.push(await addExercise('bench'));
        exerciseNames.push(await addExercise('running'));
        exerciseNames.push(await addExercise('plank'));
        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-exercise-row').count() === 3, 'rutina de prueba contiene tres ejercicios');

        const startForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).first();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_exercise_id')) > 0),
            startForm.locator('button[type="submit"]').click(),
        ]);
        const startedUrl = new URL(page.url());
        sessionId = Number(startedUrl.searchParams.get('session_id'));
        const firstExerciseId = Number(startedUrl.searchParams.get('session_exercise_id'));
        ensure(sessionId > 0 && firstExerciseId > 0, 'iniciar sesión crea una URL canónica por ejercicio');
        ensure(await page.locator('.workouts-session-exercise').count() === 3, 'los tres ejercicios permanecen en el DOM');
        ensure(await page.locator('.workouts-session-exercise:visible').count() === 1, 'móvil muestra un único ejercicio');
        ensure(await page.locator('.workouts-session-exercise.is-active:visible').filter({ hasText: exerciseNames[0] }).count() === 1, 'la sesión comienza por el primer ejercicio');
        ensure(await page.locator('.workouts-session-exercise-nav:visible').count() === 1, 'navegador contextual visible con varios ejercicios');
        ensure(await page.locator('.workouts-session-exercise-rail a').count() === 3, 'la navegación directa representa todos los ejercicios');
        const restTimer = page.locator('[data-workout-rest-timer]');
        ensure(await restTimer.count() === 1 && await restTimer.isVisible(), 'sesión muestra el temporizador del descanso configurado');
        ensure(await restTimer.getAttribute('data-state') === 'idle' && (await restTimer.locator('[data-rest-timer-time]').innerText()).trim() === '01:30', 'temporizador parte del objetivo de 90 segundos');
        const restTouchTargets = await restTimer.locator('button').evaluateAll((buttons) => buttons.filter((button) => !button.hidden).map((button) => button.getBoundingClientRect().height));
        ensure(restTouchTargets.every((height) => height >= 43.5), 'temporizador mantiene controles táctiles de 44 px', restTouchTargets.map(Math.round).join(', '));
        const touchTargets = await page.locator('.workouts-session-exercise-nav a').evaluateAll((links) => links.map((link) => Math.min(link.getBoundingClientRect().width, link.getBoundingClientRect().height)));
        ensure(touchTargets.every((size) => size >= 43.5), 'navegador mantiene objetivos táctiles de 44 px', touchTargets.map(Math.round).join(', '));
        const initialFootprint = await page.evaluate(() => ({ height: document.documentElement.scrollHeight, viewport: window.innerHeight }));
        ensure(initialFootprint.height <= initialFootprint.viewport * 2.5, 'sesión raíz queda por debajo de 2,5 alturas', `${initialFootprint.height}/${initialFootprint.viewport}px`);
        await page.screenshot({ path: path.join(reportDir, 'ui-session-focus-first-mobile.png'), fullPage: true });

        const firstSetCount = await page.locator('.workouts-session-exercise.is-active .workouts-set-row').count();
        const firstSet = page.locator('.workouts-session-exercise.is-active .workouts-set-row').first();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_exercise_id')) === firstExerciseId),
            firstSet.locator('button[name="completed"]').click(),
        ]);
        const automaticRestState = await restTimer.evaluate((element) => ({
            state: element.dataset.state,
            sessionId: element.dataset.sessionId,
            stored: Object.fromEntries(Object.keys(sessionStorage).filter((key) => key.includes('workout-rest')).map((key) => [key, sessionStorage.getItem(key)])),
        }));
        ensure(automaticRestState.state === 'running', 'completar una serie inicia el descanso automáticamente', JSON.stringify(automaticRestState));
        const runningClock = (await restTimer.locator('[data-rest-timer-time]').innerText()).trim();
        ensure(/^01:(?:2[8-9]|30)$/.test(runningClock), 'cuenta atrás empieza sin perder el objetivo', runningClock);
        await restTimer.locator('[data-rest-timer-toggle]').click();
        ensure(await restTimer.getAttribute('data-state') === 'paused', 'descanso se puede pausar');
        const pausedSeconds = await restTimer.locator('[data-rest-timer-time]').evaluate((node) => Number(node.dateTime.replace(/\D/g, '')) || 0);
        await restTimer.locator('[data-rest-timer-adjust="15"]').click();
        const extendedSeconds = await restTimer.locator('[data-rest-timer-time]').evaluate((node) => Number(node.dateTime.replace(/\D/g, '')) || 0);
        ensure(extendedSeconds === pausedSeconds + 15, 'descanso permite añadir 15 segundos', `${pausedSeconds}s → ${extendedSeconds}s`);
        await page.reload({ waitUntil: 'networkidle' });
        ensure(await restTimer.getAttribute('data-state') === 'paused' && await restTimer.locator('[data-rest-timer-time]').evaluate((node) => Number(node.dateTime.replace(/\D/g, '')) || 0) === extendedSeconds, 'pausa y ajuste sobreviven a una recarga');
        await restTimer.locator('[data-rest-timer-toggle]').click();
        ensure(await restTimer.getAttribute('data-state') === 'running', 'temporizador puede continuar');
        await page.screenshot({ path: path.join(reportDir, 'ui-session-rest-timer-mobile.png'), fullPage: false });
        const timerResponsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            const timerLayout = await restTimer.evaluate((element) => {
                const rect = element.getBoundingClientRect();
                const buttons = [...element.querySelectorAll('button')].filter((button) => !button.hidden);
                return {
                    left: rect.left,
                    right: rect.right,
                    viewport: window.innerWidth,
                    minButton: Math.min(...buttons.map((button) => button.getBoundingClientRect().height)),
                    overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth,
                };
            });
            if (timerLayout.left < -1 || timerLayout.right > timerLayout.viewport + 1 || timerLayout.minButton < 43.5 || timerLayout.overflow > 1) {
                timerResponsiveFailures.push(`${width}:${JSON.stringify(timerLayout)}`);
            }
        }
        ensure(timerResponsiveFailures.length === 0, 'temporizador responde de 320 a 768 px sin overflow', timerResponsiveFailures.join(' | '));
        await page.setViewportSize({ width: 390, height: 844 });
        const originalTheme = await page.evaluate(() => ({ className: document.body.className, theme: document.body.getAttribute('data-theme') }));
        const darkTimerSurface = await page.evaluate(() => {
            document.body.classList.remove('theme-active-light');
            document.body.classList.add('theme-active-dark');
            document.body.setAttribute('data-theme', 'dark');
            const timerElement = document.querySelector('[data-workout-rest-timer]');
            return timerElement ? getComputedStyle(timerElement).backgroundColor : '';
        });
        ensure(darkTimerSurface !== '' && darkTimerSurface !== 'rgba(0, 0, 0, 0)', 'temporizador conserva una superficie legible en oscuro', darkTimerSurface);
        await page.screenshot({ path: path.join(reportDir, 'ui-session-rest-timer-mobile-dark.png'), fullPage: false });
        await page.evaluate((theme) => {
            document.body.className = theme.className;
            if (theme.theme === null) document.body.removeAttribute('data-theme');
            else document.body.setAttribute('data-theme', theme.theme);
        }, originalTheme);

        for (let index = 1; index < firstSetCount; index++) {
            const row = page.locator('.workouts-session-exercise.is-active .workouts-set-row').nth(index);
            await Promise.all([
                page.waitForURL((url) => Number(url.searchParams.get('session_exercise_id')) === firstExerciseId),
                row.locator('button[name="completed"]').click(),
            ]);
        }
        ensure(await page.locator('.workouts-session-exercise-rail a').first().evaluate((link) => link.classList.contains('is-complete')), 'progreso marca el ejercicio completado');
        const aggregateProgress = await page.locator('.workouts-session-exercise-nav-current > div > span').innerText();
        ensure(/\b1\s+(?:of|de|di)\s+3\b/i.test(aggregateProgress), 'progreso agregado se actualiza', aggregateProgress);

        const nextLink = page.locator('.workouts-session-exercise-nav-main > a').last();
        const secondHref = await nextLink.getAttribute('href');
        const secondExerciseId = Number(new URL(secondHref, BASE).searchParams.get('session_exercise_id'));
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_exercise_id')) === secondExerciseId),
            nextLink.click(),
        ]);
        ensure(await page.locator('.workouts-session-exercise.is-active:visible').filter({ hasText: exerciseNames[1] }).count() === 1, 'Siguiente cambia al segundo ejercicio');
        ensure(await page.locator('.workouts-session-exercise-rail a.is-active').getAttribute('aria-current') === 'step', 'el rail expone el paso activo');
        ensure(await restTimer.getAttribute('data-state') === 'running' && await restTimer.isVisible(), 'descanso continúa al cambiar de ejercicio');
        await restTimer.locator('[data-rest-timer-skip]').click();
        ensure(await restTimer.getAttribute('data-state') === 'complete' && (await restTimer.locator('[data-rest-timer-time]').innerText()).trim() === '00:00', 'descanso se puede omitir con feedback final');
        await restTimer.locator('[data-rest-timer-skip]').click();
        ensure(await restTimer.isHidden(), 'cerrar limpia el descanso en un ejercicio sin objetivo');

        const activeCard = page.locator('.workouts-session-exercise.is-active');
        const setsBefore = await activeCard.locator('.workouts-set-row').count();
        const addSetForm = activeCard.locator('form').filter({ has: page.locator('input[name="action"][value="session_add_set"]') });
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_exercise_id')) === secondExerciseId),
            addSetForm.locator('button[type="submit"]').click(),
        ]);
        ensure(await page.locator('.workouts-session-exercise.is-active .workouts-set-row').count() === setsBefore + 1, 'añadir serie conserva el ejercicio activo');
        await page.reload({ waitUntil: 'networkidle' });
        ensure(Number(new URL(page.url()).searchParams.get('session_exercise_id')) === secondExerciseId && await page.locator('.workouts-session-exercise.is-active:visible').filter({ hasText: exerciseNames[1] }).count() === 1, 'recargar conserva el ejercicio compartible');
        await page.screenshot({ path: path.join(reportDir, 'ui-session-focus-second-mobile.png'), fullPage: true });

        const invalidUrl = `${BASE}/?page=workouts&session_id=${sessionId}&session_exercise_id=999999`;
        await page.goto(invalidUrl, { waitUntil: 'networkidle' });
        ensure(await page.locator('.workouts-session-exercise.is-active:visible').count() === 1, 'identificador inválido usa un fallback seguro');

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768, 1024]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}&session_exercise_id=${secondExerciseId}`, { waitUntil: 'networkidle' });
            const state = await page.evaluate(() => ({
                overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth,
                visibleCards: [...document.querySelectorAll('.workouts-session-exercise')].filter((card) => getComputedStyle(card).display !== 'none').length,
                navVisible: getComputedStyle(document.querySelector('.workouts-session-exercise-nav')).display !== 'none',
            }));
            const expectedCards = width <= 700 ? 1 : 3;
            if (state.overflow > 1 || state.visibleCards !== expectedCards || state.navVisible !== (width <= 700)) {
                responsiveFailures.push(`${width}:${JSON.stringify(state)}`);
            }
        }
        check('focus móvil y sesión completa desktop responden de 320 a 1024 px', responsiveFailures.length === 0, responsiveFailures.join(' | '));

        const outsider = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const outsiderPage = await outsider.newPage();
        await login(outsiderPage, 'catalina');
        await outsiderPage.goto(`${BASE}/?page=workouts&session_id=${sessionId}&session_exercise_id=${secondExerciseId}`, { waitUntil: 'networkidle' });
        ensure(await outsiderPage.locator('.workouts-session-panel').count() === 0, 'otra persona no puede abrir el ejercicio de la sesión');
        await outsider.close();

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de sesión enfocada', false, error.stack || error.message);
    } finally {
        if (!page.isClosed() && sessionId > 0) {
            try {
                await page.goto(`${BASE}/?page=workouts&session_id=${sessionId}`, { waitUntil: 'networkidle', timeout: 5000 });
                const cancel = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
                if (await cancel.count()) await cancel.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        if (!page.isClosed() && routineId > 0) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings&settings_view=management`, { waitUntil: 'networkidle', timeout: 5000 });
                const remove = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await remove.count()) await remove.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
