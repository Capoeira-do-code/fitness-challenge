/**
 * Stateful responsive checks for compact cards, disclosures and long content.
 * Run only against the disposable local QA database.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8121').replace(/\/$/, '');
const PASSWORD = value('password', 'Verify123!');
const checks = [];
const runtimeErrors = [];
const check = (name, pass, detail = '') => {
    checks.push({ name, pass: Boolean(pass), detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};
const ensure = (condition, name, detail = '') => {
    check(name, condition, detail);
    if (!condition) throw new Error(`${name}${detail ? `: ${detail}` : ''}`);
};
const overflow = (page) => page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body.scrollWidth,
) - window.innerWidth);

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

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 320, height: 844 }, hasTouch: true });
    const page = await context.newPage();
    page.setDefaultTimeout(18000);
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
    page.on('console', (message) => {
        const source = message.location().url || '';
        if (message.type() === 'error' && source.startsWith(BASE)) runtimeErrors.push(message.text());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) runtimeErrors.push(`${response.status()} ${response.url()}`);
    });

    try {
        await login(page);

        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        const lockedProgress = await page.locator('.dashboard-achievement-progress-row').evaluateAll((rows) => rows.map((row) => ({
            state: row.dataset.state,
            width: Number.parseFloat(row.querySelector('.goal-progress > span')?.style.width || '0'),
        })));
        ensure(lockedProgress.every((row) => row.width < 100), 'Inicio no conserva logros al 100 % como bloqueados', JSON.stringify(lockedProgress));

        const seasonStats = page.locator('[data-dashboard-widget="season"] .season-widget-summary > span');
        ensure(await seasonStats.count() === 3, 'Temporada resume XP, posición y siguiente hito');
        ensure(await page.locator('[data-dashboard-widget="season"] [role="progressbar"]').count() === 1, 'Temporada expone progreso accesible');

        const firstQuestToggle = page.locator('[data-quest-detail-toggle]').first();
        ensure(await firstQuestToggle.count() === 1, 'Misiones ofrecen detalle interactivo');
        await firstQuestToggle.focus();
        await page.keyboard.press('Enter');
        ensure(await firstQuestToggle.getAttribute('aria-expanded') === 'true', 'Detalle de misión funciona con teclado');
        ensure(await firstQuestToggle.locator('xpath=ancestor::li[1]').locator('[data-quest-detail]').isVisible(), 'Detalle de misión muestra estado, periodo y recompensa');

        await page.evaluate(() => {
            const long = 'Nombre de misión extraordinariamente largo con contexto de entrenamiento semanal y progreso acumulado '.repeat(3);
            document.querySelectorAll('.quest-top strong, .season-widget h2').forEach((node) => { node.textContent = long; });
            document.querySelectorAll('.compact-metrics-row strong').forEach((node) => { node.textContent = '999 999 999 999 kcal'; });
        });
        ensure(await overflow(page) <= 1, 'Inicio soporta nombres y cifras extremas a 320 px', `${await overflow(page)}px`);

        await page.goto(`${BASE}/?page=season`, { waitUntil: 'networkidle' });
        ensure(await page.locator('.season-ranking-summary > span').count() === 4, 'Clasificación incluye XP, posición, días e hito');
        ensure(await page.locator('.season-ranking-row.is-me').count() === 1, 'Clasificación resalta al usuario actual');
        await page.locator('.season-ranking-row a > strong').first().evaluate((node) => { node.textContent = 'Participante con un nombre excepcionalmente largo sin abreviaturas ni cortes '.repeat(4); });
        ensure(await overflow(page) <= 1, 'Clasificación tolera nombres largos a 320 px', `${await overflow(page)}px`);

        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        const routineCards = page.locator('.workouts-routine-card[data-state]');
        ensure(await routineCards.count() > 0, 'Rutinas publican un estado visible');
        const routineStates = await routineCards.evaluateAll((cards) => cards.map((card) => card.dataset.state));
        const allowedStates = new Set(['active', 'completed', 'scheduled', 'available', 'unavailable']);
        ensure(routineStates.every((state) => allowedStates.has(state)), 'Estados de rutina usan el contrato esperado', routineStates.join(', '));
        ensure(await page.locator('.workouts-routine-card .workouts-routine-state').count() === await routineCards.count(), 'Cada rutina presenta la etiqueta de estado');
        await page.evaluate(() => {
            const long = 'Rutina de fuerza y movilidad con un nombre deliberadamente muy largo para comprobar saltos de línea '.repeat(4);
            document.querySelectorAll('.workouts-routine-title-copy strong, .workouts-routine-desc').forEach((node) => { node.textContent = long; });
            document.querySelectorAll('.workouts-routine-card .badge').forEach((node) => { node.textContent = '999999 ejercicios'; });
        });
        ensure(await overflow(page) <= 1, 'Rutinas toleran contenido extremo a 320 px', `${await overflow(page)}px`);

        await page.goto(`${BASE}/?page=workouts&view=plan`, { waitUntil: 'networkidle' });
        const plannedRoutines = page.locator('.workouts-day-routine[data-state]');
        if (await plannedRoutines.count() > 0) {
            ensure(await plannedRoutines.evaluateAll((cards) => cards.every((card) => Boolean(card.dataset.state))), 'Agenda semanal conserva estados por rutina');
        } else {
            check('Agenda semanal conserva estados por rutina', true, 'sin rutinas programadas en la fixture');
        }
        ensure(await overflow(page) <= 1, 'Agenda semanal sin desbordamiento a 320 px', `${await overflow(page)}px`);

        await page.goto(`${BASE}/?page=duels`, { waitUntil: 'networkidle' });
        let duelCard = page.locator('.duel-card[data-state]').first();
        if (await duelCard.count() === 0) {
            const createForm = page.locator('form:has(input[name="action"][value="duel_create"])');
            if (await createForm.count() > 0 && await createForm.locator('select[name="opponent_id"] option').count() > 1) {
                const opponent = await createForm.locator('select[name="opponent_id"] option').nth(1).getAttribute('value');
                await createForm.locator('select[name="opponent_id"]').selectOption(opponent || '');
                await Promise.all([
                    page.waitForLoadState('networkidle'),
                    createForm.locator('button[type="submit"]').click(),
                ]);
                duelCard = page.locator('.duel-card[data-state]').first();
            }
        }
        if (await duelCard.count() > 0) {
            const detailToggle = duelCard.locator('[data-versus-details-toggle]');
            ensure(await detailToggle.getAttribute('aria-controls') !== null, 'Tarjeta de duelo enlaza control y detalle accesiblemente');
            await detailToggle.focus();
            await page.keyboard.press('Enter');
            ensure(await detailToggle.getAttribute('aria-expanded') === 'true', 'Detalle de duelo funciona con teclado');
            ensure(await duelCard.locator('[data-versus-details]').isVisible(), 'Detalle de duelo muestra metadatos secundarios');
            await duelCard.evaluate((card) => {
                card.querySelectorAll('strong').forEach((node) => { node.textContent = 'Competidor con nombre de longitud extrema '.repeat(5); });
            });
            ensure(await overflow(page) <= 1, 'Duelo tolera nombres largos a 320 px', `${await overflow(page)}px`);
        } else {
            check('Detalle de duelo funciona con teclado', true, 'sin rivales disponibles en la fixture');
        }

        await page.goto(`${BASE}/?page=competitions`, { waitUntil: 'networkidle' });
        const competitionCard = page.locator('.duel-card[data-state]').first();
        if (await competitionCard.count() > 0) {
            const detailToggle = competitionCard.locator('[data-versus-details-toggle]');
            await detailToggle.click();
            ensure(await detailToggle.getAttribute('aria-expanded') === 'true', 'Competición despliega detalles secundarios');
            ensure(await competitionCard.locator('[data-versus-details]').isVisible(), 'Competición conserva metadatos legibles');
        } else {
            check('Competición despliega detalles secundarios', true, 'sin competiciones en la fixture');
        }
        ensure(await overflow(page) <= 1, 'Competiciones sin desbordamiento a 320 px', `${await overflow(page)}px`);

        await page.goto(`${BASE}/?page=gallery&gallery_view=recent`, { waitUntil: 'networkidle' });
        const galleryTile = page.locator('.photos-gallery-tile:has(img[data-gallery-image])').first();
        if (await galleryTile.count() > 0) {
            await page.waitForFunction(() => !document.querySelector('.photos-gallery-tile.is-image-loading'));
            await galleryTile.locator('img').evaluate((image) => {
                image.removeAttribute('srcset');
                image.src = 'data:image/png;base64,invalid';
            });
            await page.waitForFunction(() => document.querySelector('.photos-gallery-tile.is-image-error'));
            ensure(await galleryTile.locator('[data-gallery-image-error]').isVisible(), 'Galería sustituye imágenes rotas por un estado visible');
        } else {
            check('Galería sustituye imágenes rotas por un estado visible', true, 'sin fotos en la fixture');
        }
        ensure(await page.locator('[data-gallery-load-error] [data-gallery-load-retry]').count() === 1, 'Galería incluye recuperación explícita de carga');
        await page.locator('[data-gallery-load-error]').evaluate((node) => { node.hidden = false; });
        ensure(await overflow(page) <= 1, 'Error y reintento de Galería caben a 320 px', `${await overflow(page)}px`);

        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(`${BASE}/?page=dashboard`, { waitUntil: 'networkidle' });
        ensure(await overflow(page) <= 1, 'Componentes con estado sin desbordamiento a 1440 px', `${await overflow(page)}px`);
        await page.evaluate(() => { document.body.dataset.theme = 'dark'; });
        ensure(await overflow(page) <= 1, 'Estados conservan el diseño en tema oscuro');

        const css = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'styles.css'), 'utf8');
        ensure(!css.includes('Cascade guarantees for compact primitives'), 'CSS compacto no conserva el bloque duplicado temporal');
        ensure(/--liquid-glass-blur:\s*12px/.test(css), 'Desenfoque global usa el límite de rendimiento previsto');
        ensure(runtimeErrors.length === 0, 'sin errores de consola ni HTTP 500', runtimeErrors.join(' | '));
    } catch (error) {
        console.error(error.stack || error.message);
        process.exitCode = 1;
    } finally {
        await browser.close();
        const failed = checks.filter((entry) => !entry.pass);
        console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones superadas.`);
        if (failed.length > 0) process.exitCode = 1;
    }
})();
