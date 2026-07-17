/**
 * Browser QA for personal exercises with guide, image and video.
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
const login = async (page, username) => {
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

    const unique = `Press personal UI ${Date.now()}`;
    const updatedName = `${unique} editado`;
    const initialAccent = '#7c3aed';
    const updatedAccent = '#d946ef';
    const initialMark = '🐉';
    const updatedMark = 'ZEN';
    let exerciseId = 0;
    let routineId = 0;

    try {
        await login(page, 'roberto');
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        await page.locator('[data-app-modal-open="wk-new-routine-modal"]').first().click();
        const routineModal = page.locator('#wk-new-routine-modal');
        await routineModal.locator('input[name="name"]').fill(`QA Custom ${Date.now()}`);
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('routine_id')) > 0),
            routineModal.locator('button[type="submit"]').click(),
        ]);
        routineId = Number(new URL(page.url()).searchParams.get('routine_id'));
        await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=new`, { waitUntil: 'networkidle' });
        const editor = page.locator('form.workouts-custom-editor');
        await editor.waitFor();
        ensure(await editor.locator('.workouts-custom-section').count() === 3, 'editor personal organiza basicos, guia y medios');
        ensure(await editor.locator('input[name="cover_mode"]').count() === 4, 'editor permite elegir la portada de biblioteca');
        ensure(await page.locator('body.mobile-immersive-mode').count() === 1
            && await page.locator('.bottom-nav').evaluate((nav) => getComputedStyle(nav).display === 'none'), 'editor móvil usa una pantalla de detalle sin barra inferior superpuesta');
        const initialImagePositionCount = await editor.locator('input[name="image_position"]').count();
        ensure(initialImagePositionCount === 5, 'editor permite elegir el encuadre de la foto', String(initialImagePositionCount));
        ensure((await overflow(page)) <= 1, 'editor nuevo no desborda en 390 px');

        const draftStatus = editor.locator('[data-workout-draft-status]');
        const draftKey = await editor.getAttribute('data-workout-draft-key');
        ensure(Boolean(draftKey) && await draftStatus.count() === 1, 'editor ofrece borrador aislado para este contexto');
        await editor.locator('input[name="name"]').fill('Borrador para descartar');
        await page.waitForFunction(() => document.querySelector('[data-workout-draft-status]')?.dataset.state === 'saved');
        ensure(await page.evaluate((key) => Boolean(localStorage.getItem(key)), draftKey), 'borrador se guarda tras editar');
        const editorUrl = page.url();
        await page.evaluate((key) => sessionStorage.setItem('fitness-challenge:exercise-draft:pending-clear', JSON.stringify({
            version: 1,
            key,
            action: 'save',
            success: { route: 'exercise' },
            createdAt: Date.now(),
        })), draftKey);
        await page.goto(`${BASE}/?page=workouts`, { waitUntil: 'networkidle' });
        ensure(await page.evaluate((key) => Boolean(localStorage.getItem(key)), draftKey)
            && !await page.evaluate(() => Boolean(sessionStorage.getItem('fitness-challenge:exercise-draft:pending-clear'))), 'una navegación que no confirma el guardado conserva el borrador');
        await page.goto(editorUrl, { waitUntil: 'networkidle' });
        ensure(await draftStatus.getAttribute('data-state') === 'found'
            && await draftStatus.locator('[data-workout-draft-restore]').isVisible()
            && await draftStatus.locator('[data-workout-draft-discard]').isVisible(), 'volver al editor ofrece recuperar o descartar el borrador');
        await draftStatus.locator('[data-workout-draft-discard]').click();
        ensure(!await page.evaluate((key) => Boolean(localStorage.getItem(key)), draftKey)
            && await editor.locator('input[name="name"]').inputValue() === '', 'descartar elimina el borrador sin alterar el formulario limpio');

        await editor.locator('input[name="name"]').fill('Borrador recuperable');
        await editor.locator('[data-workout-editor-step-trigger="guide"]').click();
        await editor.locator('textarea[name="summary"]').fill('Resumen recuperado del dispositivo.');
        const draftSteps = editor.locator('[data-guide-key="steps"]');
        await draftSteps.locator('summary').click();
        await draftSteps.locator('[data-guide-add]').click();
        await draftSteps.locator('[data-guide-item-input]').fill('Paso guardado en borrador');
        await draftSteps.locator('summary').click();
        await page.waitForFunction(() => document.querySelector('[data-workout-draft-status]')?.dataset.state === 'saved');
        await page.reload({ waitUntil: 'networkidle' });
        ensure(await draftStatus.getAttribute('data-state') === 'found', 'borrador reaparece tras una recarga real');
        await page.waitForFunction(() => {
            const draft = document.querySelector('[data-workout-draft-status]');
            const topbar = document.querySelector('.topbar');
            if (!draft || !topbar) return false;
            const draftRect = draft.getBoundingClientRect();
            const topbarRect = topbar.getBoundingClientRect();
            return draftRect.top >= topbarRect.bottom - 1 && draftRect.bottom <= window.innerHeight;
        });
        ensure(true, 'aviso de recuperación queda completo bajo la cabecera fija');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-draft-mobile.png') });
        await draftStatus.locator('[data-workout-draft-restore]').click();
        await page.waitForFunction(() => ['restored', 'saved'].includes(document.querySelector('[data-workout-draft-status]')?.dataset.state || ''));
        ensure(await editor.locator('input[name="name"]').inputValue() === 'Borrador recuperable'
            && await editor.locator('textarea[name="summary"]').inputValue() === 'Resumen recuperado del dispositivo.'
            && await editor.locator('[data-guide-key="steps"] [data-guide-item-input]').inputValue() === 'Paso guardado en borrador'
            && await editor.locator('[data-workout-editor-step-trigger="guide"]').getAttribute('aria-pressed') === 'true', 'recuperar restaura campos, guía y subpantalla activa');
        ensure((await editor.locator('[data-workout-preview-name]').first().textContent()).trim() === 'Borrador recuperable', 'vista previa reacciona al borrador recuperado');

        await editor.locator('[data-workout-editor-step-trigger="basics"]').click();
        await editor.locator('input[name="name"]').fill(unique);
        await editor.locator('select[name="muscle_group"]').selectOption('chest');
        await editor.locator('select[name="equipment"]').selectOption('dumbbell');
        await editor.locator('select[name="difficulty"]').selectOption('intermediate');
        await editor.locator('input[name="secondary_muscles[]"][value="triceps"] + span').click();
        const defaultsDetails = editor.locator('[data-workout-training-defaults]');
        ensure(await defaultsDetails.count() === 1 && !await defaultsDetails.evaluate((details) => details.open), 'objetivo inicial se resume sin alargar datos basicos');
        ensure((await defaultsDetails.locator('[data-workout-default-status]').textContent()).trim() === '3×10', 'resumen muestra el preset por tipo');
        await defaultsDetails.locator('summary').click();
        await defaultsDetails.locator('input[name="default_sets"]').fill('4');
        await defaultsDetails.locator('input[name="default_reps"]').fill('8');
        await defaultsDetails.locator('input[name="default_weight"]').fill('24');
        await defaultsDetails.locator('select[name="default_unit"]').selectOption('lb');
        await defaultsDetails.locator('input[name="default_rest_seconds"]').fill('75');
        await defaultsDetails.locator('textarea[name="default_notes"]').fill('Pausa y empuja estable');
        ensure((await defaultsDetails.locator('[data-workout-default-status]').textContent()).trim() === '4×8', 'preset se actualiza mientras se edita');
        await editor.locator('select[name="exercise_type"]').selectOption('cardio');
        ensure(await defaultsDetails.locator('[data-workout-default-panel="cardio"]').first().isVisible() && !await defaultsDetails.locator('[data-workout-default-panel="strength,bodyweight,freeform"]').isVisible(), 'campos cambian al elegir cardio');
        ensure((await defaultsDetails.locator('[data-workout-default-status]').textContent()).trim() === '4×20 min', 'cardio usa duración legible');
        await editor.locator('select[name="exercise_type"]').selectOption('strength');
        ensure((await defaultsDetails.locator('[data-workout-default-status]').textContent()).trim() === '4×8', 'volver a fuerza conserva el objetivo escrito');
        ensure(await defaultsDetails.locator('summary, input:not([type="hidden"]), select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
            const rect = node.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
        }).every((node) => node.getBoundingClientRect().height >= 43.5)), 'preset mantiene controles tactiles de 44 px');
        await defaultsDetails.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-defaults-mobile.png') });
        await editor.locator('[data-workout-editor-step-trigger="guide"]').click();
        await editor.locator('textarea[name="summary"]').fill('Press personal con una guia clara y editable.');
        const guideBuilder = editor.locator('[data-workout-guide-builder]');
        const guideSections = guideBuilder.locator('[data-guide-section]');
        ensure(await guideSections.count() === 3 && await guideSections.evaluateAll((sections) => sections.every((section) => !section.open)), 'guia organiza pasos, consejos y errores en submenus');
        ensure(await guideBuilder.evaluate((node) => node.getBoundingClientRect().height) < 250, 'guia cerrada ocupa un bloque compacto');
        const stepsBuilder = guideBuilder.locator('[data-guide-key="steps"]');
        await stepsBuilder.locator('summary').click();
        await stepsBuilder.locator('[data-guide-add]').click();
        const firstStepInput = stepsBuilder.locator('[data-guide-item-input]').first();
        await firstStepInput.fill('Apoya los pies');
        await firstStepInput.press('Enter');
        await stepsBuilder.locator('[data-guide-item-input]').nth(1).fill('Baja con control');
        await stepsBuilder.locator('[data-guide-item-input]').nth(1).press('Enter');
        await stepsBuilder.locator('[data-guide-item-input]').nth(2).fill('Empuja sin despegar la espalda');
        ensure((await stepsBuilder.locator('[data-guide-count]').textContent()).trim() === '3', 'contador de pasos se actualiza al escribir');
        await stepsBuilder.locator('[data-guide-item]').nth(2).locator('[data-guide-move="up"]').click();
        ensure(await stepsBuilder.locator('[data-guide-output]').inputValue() === 'Apoya los pies\nEmpuja sin despegar la espalda\nBaja con control', 'pasos se pueden reordenar sin editar texto plano');
        await stepsBuilder.locator('[data-guide-item]').nth(1).locator('[data-guide-move="down"]').click();
        const tipsBuilder = guideBuilder.locator('[data-guide-key="tips"]');
        await tipsBuilder.locator('summary').click();
        await tipsBuilder.locator('[data-guide-add]').click();
        await tipsBuilder.locator('[data-guide-item-input]').fill('Mantén las muñecas neutras');
        ensure(!await stepsBuilder.evaluate((details) => details.open), 'abrir consejos cierra pasos en móvil');
        const mistakesBuilder = guideBuilder.locator('[data-guide-key="mistakes"]');
        await mistakesBuilder.locator('summary').click();
        await mistakesBuilder.locator('[data-guide-add]').click();
        await mistakesBuilder.locator('[data-guide-item-input]').fill('Abrir los codos en exceso');
        ensure(await mistakesBuilder.locator('summary, textarea, button').evaluateAll((nodes) => nodes.filter((node) => {
            const rect = node.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
        }).every((node) => node.getBoundingClientRect().height >= 43.5)), 'editor de guía mantiene controles táctiles de 44 px');
        await mistakesBuilder.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-guide-builder-mobile.png') });
        const guideBuilderResponsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            const guideLayout = await mistakesBuilder.evaluate((section) => {
                const rect = section.getBoundingClientRect();
                return {
                    left: rect.left,
                    right: rect.right,
                    viewport: window.innerWidth,
                    overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth,
                };
            });
            if (guideLayout.left < -1 || guideLayout.right > guideLayout.viewport + 1 || guideLayout.overflow > 1) {
                guideBuilderResponsiveFailures.push(`${width}:${JSON.stringify(guideLayout)}`);
            }
        }
        ensure(guideBuilderResponsiveFailures.length === 0, 'editor de guía no desborda entre 320 y 768 px', guideBuilderResponsiveFailures.join(' | '));
        await page.setViewportSize({ width: 390, height: 844 });
        const guideEditorTheme = await page.evaluate(() => ({ className: document.body.className, theme: document.body.getAttribute('data-theme') }));
        const darkGuideEditorSurface = await page.evaluate(() => {
            document.body.classList.remove('theme-active-light');
            document.body.classList.add('theme-active-dark');
            document.body.setAttribute('data-theme', 'dark');
            const section = document.querySelector('[data-guide-key="mistakes"]');
            section.scrollIntoView({ block: 'center', inline: 'nearest' });
            return getComputedStyle(section).backgroundColor;
        });
        ensure(darkGuideEditorSurface !== 'rgb(255, 255, 255)' && darkGuideEditorSurface !== 'rgba(0, 0, 0, 0)', 'editor de guía conserva superficie oscura', darkGuideEditorSurface);
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-guide-builder-mobile-dark.png'), fullPage: false });
        await page.evaluate((theme) => {
            document.body.className = theme.className;
            if (theme.theme === null) document.body.removeAttribute('data-theme');
            else document.body.setAttribute('data-theme', theme.theme);
        }, guideEditorTheme);
        await mistakesBuilder.locator('summary').click();
        ensure(await guideBuilder.evaluate((node) => node.getBoundingClientRect().height) < 250, 'submenus de guía vuelven a su resumen compacto');

        await editor.locator('[data-workout-editor-step-trigger="media"]').click();
        const mediaSection = editor.locator('.workouts-custom-media');
        const photoDetails = editor.locator('[data-workout-photo-details]');
        const videoDetails = editor.locator('[data-workout-video-details]');
        const livePreview = editor.locator('[data-workout-exercise-live-preview]');
        ensure(await photoDetails.count() === 1 && await videoDetails.count() === 1, 'multimedia separa foto y video en submenus');
        ensure(!await photoDetails.evaluate((details) => details.open) && !await videoDetails.evaluate((details) => details.open), 'submenus multimedia empiezan cerrados');
        ensure(await livePreview.count() === 1 && !await livePreview.evaluate((details) => details.open), 'vista previa final empieza resumida en móvil');
        ensure((await livePreview.locator('summary [data-workout-preview-name]').textContent()).trim() === unique, 'resumen de vista previa refleja el nombre sin guardar');
        ensure(await mediaSection.evaluate((section) => section.getBoundingClientRect().height) < 620, 'raiz multimedia cabe en una pantalla compacta');
        await mediaSection.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-media-compact-mobile.png') });
        await livePreview.locator('summary').click();
        ensure(await livePreview.locator('[role="tab"]').count() === 2 && await livePreview.locator('[data-workout-preview-panel="library"]').isVisible(), 'vista previa alterna biblioteca y sesión dentro del editor');
        ensure((await livePreview.locator('[data-workout-preview-target]').first().textContent()).trim() === '4×8', 'vista previa hereda el objetivo inicial editado');
        await livePreview.locator('summary').click();
        const colorDetails = editor.locator('.workouts-custom-color-details');
        ensure(await colorDetails.count() === 1, 'editor agrupa el color en un submenu compacto');
        ensure(!await colorDetails.evaluate((details) => details.open), 'submenu de color empieza cerrado');
        await colorDetails.locator('summary').click();
        ensure(await colorDetails.locator('[data-workout-mark-preset]').count() === 8, 'selector ofrece ocho simbolos rapidos');
        await colorDetails.locator('[data-workout-mark-input]').fill(initialMark);
        ensure(await colorDetails.locator('[data-workout-mark-preview]').textContent() === initialMark, 'simbolo libre se previsualiza al instante');
        ensure(await colorDetails.locator('[data-workout-color-preset]').count() === 8, 'selector ofrece ocho acentos rapidos');
        await colorDetails.locator('[data-workout-color-input]').fill(initialAccent);
        ensure(await colorDetails.locator('[data-workout-color-output]').textContent() === initialAccent.toUpperCase(), 'color libre se previsualiza al instante');
        ensure(await colorDetails.evaluate((details) => getComputedStyle(details).getPropertyValue('--exercise-accent').trim()) === initialAccent, 'submenu adopta el color elegido');
        ensure((await livePreview.locator('[data-workout-preview-head-mark]').textContent()).trim() === initialMark && await livePreview.evaluate((details) => getComputedStyle(details).getPropertyValue('--exercise-accent').trim()) === initialAccent, 'vista previa adopta símbolo y color antes de guardar');
        ensure(await colorDetails.locator('.workouts-exercise-mark-options input + span').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 44)), 'simbolos mantienen objetivos tactiles');
        await photoDetails.locator('summary').click();
        await page.waitForFunction(() => !document.querySelector('.workouts-custom-color-details')?.open);
        ensure(!await colorDetails.evaluate((details) => details.open), 'abrir foto cierra identidad en movil');
        ensure((await photoDetails.locator('[data-workout-gallery-status]').textContent()).trim().startsWith('0 / 4'), 'galeria muestra capacidad vacia antes de elegir archivos');
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR42mP8z8AARAwMjDAGAAANHQEDasKb6QAAAABJRU5ErkJggg==', 'base64');
        const galleryInput = editor.locator('input[name="exercise_images[]"]');
        const galleryFiles = [
            { name: 'press-front-qa.png', mimeType: 'image/png', buffer: png },
            { name: 'press-side-qa.png', mimeType: 'image/png', buffer: png },
            { name: 'press-setup-qa.png', mimeType: 'image/png', buffer: png },
        ];
        await galleryInput.setInputFiles(galleryFiles);
        await page.waitForFunction(() => document.querySelectorAll('[data-workout-gallery-item] [data-workout-gallery-image][src^="blob:"]').length === 3);
        ensure(await photoDetails.locator('[data-workout-gallery-item]').count() === 3, 'galeria previsualiza tres fotos antes de guardar');
        await page.waitForFunction((key) => {
            try { return JSON.parse(localStorage.getItem(key) || 'null')?.hasFiles === true; } catch (_) { return false; }
        }, draftKey);
        ensure(await page.evaluate(({ key, names }) => {
            const raw = localStorage.getItem(key) || '';
            return names.every((name) => !raw.includes(name));
        }, { key: draftKey, names: galleryFiles.map((file) => file.name) }), 'borrador avisa de fotos pendientes sin almacenar archivos ni nombres locales');
        ensure((await photoDetails.locator('[data-workout-gallery-status]').textContent()).trim().startsWith('3 / 4'), 'contador de galeria cambia al instante');
        const initialGalleryCaptions = photoDetails.locator('[data-workout-gallery-caption]');
        await initialGalleryCaptions.nth(0).fill('Posición inicial');
        await initialGalleryCaptions.nth(1).fill('Vista lateral');
        await initialGalleryCaptions.nth(2).fill('Bloqueo final');
        await photoDetails.locator('[data-workout-gallery-item]').last().locator('[data-workout-gallery-remove]').click();
        ensure(await photoDetails.locator('[data-workout-gallery-item]').count() === 2, 'una foto nueva se puede retirar sin limpiar el resto');
        ensure(await photoDetails.locator('[data-workout-gallery-caption]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|')) === 'Posición inicial|Vista lateral', 'retirar una foto conserva los títulos de las restantes');
        await galleryInput.setInputFiles(galleryFiles);
        const galleryItems = photoDetails.locator('[data-workout-gallery-item]');
        await photoDetails.locator('[data-workout-gallery-caption]').nth(2).fill('Bloqueo final');
        await galleryItems.nth(1).locator('[data-workout-gallery-cover]').check({ force: true });
        await galleryItems.nth(2).locator('[data-workout-gallery-move="up"]').click();
        ensure(await galleryItems.evaluateAll((items) => new Set(items.map((item) => item.querySelector('[data-workout-gallery-order]')?.value)).size === 3), 'galeria conserva un orden explicito sin duplicados');
        const reorderedGalleryCaptions = await photoDetails.locator('[data-workout-gallery-caption]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|'));
        ensure(reorderedGalleryCaptions === 'Posición inicial|Bloqueo final|Vista lateral', 'reordenar fotos mueve también sus títulos', reorderedGalleryCaptions);
        await editor.locator('input[name="image_position"][value="right"] + span').click();
        ensure(await photoDetails.locator('[data-workout-gallery-cover]:checked').locator('xpath=ancestor::*[@data-workout-gallery-item]').locator('[data-workout-gallery-image]').evaluate((image) => getComputedStyle(image).objectPosition === '82% 50%'), 'encuadre derecho se aplica a la portada elegida');
        await galleryItems.first().locator('[data-workout-gallery-focus]').click();
        ensure((await photoDetails.locator('[data-workout-gallery-focus-status]').textContent()).includes('1'), 'selector de recorte identifica la foto activa sin cambiar la portada');
        await editor.locator('input[name="image_position"][value="left"] + span').click();
        ensure(await galleryItems.first().locator('[data-workout-gallery-image]').evaluate((image) => getComputedStyle(image).objectPosition === '18% 50%'), 'una foto secundaria conserva su propio encuadre izquierdo');
        const focalEditor = photoDetails.locator('[data-workout-gallery-focal-editor]');
        const focalSurface = focalEditor.locator('[data-workout-gallery-focal-surface]');
        ensure(await focalEditor.isVisible() && await focalSurface.getAttribute('aria-label'), 'ajuste fino muestra una vista previa táctil y accesible');
        const focalBox = await focalSurface.boundingBox();
        ensure(Boolean(focalBox), 'vista previa focal tiene un área interactiva real');
        await focalSurface.click({ position: { x: focalBox.width * 0.27, y: focalBox.height * 0.71 } });
        const tappedFocalPosition = await galleryItems.first().locator('[data-workout-gallery-position]').inputValue();
        ensure(/^focal:\d{1,3}:\d{1,3}$/.test(tappedFocalPosition), 'tocar la foto coloca un punto focal libre', tappedFocalPosition);
        await focalEditor.locator('[data-workout-gallery-focal-x]').evaluate((input) => {
            input.value = '27';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await focalEditor.locator('[data-workout-gallery-focal-y]').evaluate((input) => {
            input.value = '71';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        ensure(await galleryItems.first().locator('[data-workout-gallery-position]').inputValue() === 'focal:27:71'
            && await galleryItems.first().locator('[data-workout-gallery-image]').evaluate((image) => getComputedStyle(image).objectPosition === '27% 71%'), 'controles finos actualizan el recorte exacto al instante');
        ensure((await focalEditor.locator('[data-workout-gallery-focal-value]').textContent()).includes('27%')
            && (await focalEditor.locator('[data-workout-gallery-focal-value]').textContent()).includes('71%'), 'coordenadas del punto focal son legibles');
        ensure(await photoDetails.locator('[data-workout-gallery-cover]:checked').locator('xpath=ancestor::*[@data-workout-gallery-item]').locator('[data-workout-gallery-image]').evaluate((image) => getComputedStyle(image).objectPosition === '82% 50%'), 'ajustar otra foto no modifica el encuadre de portada');
        ensure(await galleryItems.locator('[data-workout-gallery-position]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|')) === 'focal:27:71|center|right', 'cada elemento envía su encuadre alineado con el orden');
        ensure(await livePreview.locator('[data-workout-preview-panel="library"] [data-workout-preview-image]').evaluate((image) => image.getAttribute('src')?.startsWith('blob:') && getComputedStyle(image).objectPosition === '82% 50%'), 'tarjeta viva conserva la portada aunque se edite otra foto');
        ensure(await photoDetails.locator('.workouts-image-focus-presets label > span, .workouts-image-focal-controls input').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 44)), 'encuadres mantienen objetivos tactiles');
        ensure(await photoDetails.locator('[data-workout-gallery-focus], [data-workout-gallery-move], [data-workout-gallery-remove]').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 43.5 && node.getBoundingClientRect().width >= 43.5)), 'ajuste, orden y borrado mantienen objetivos tactiles');
        ensure(await galleryItems.evaluateAll((items) => items.every((item) => item.getBoundingClientRect().height < 210)), 'tarjetas de foto móviles conservan acciones en un formato compacto');
        await photoDetails.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-gallery-editor-mobile.png') });
        await focalEditor.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-focal-editor-mobile.png') });
        const focalResponsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1024 });
            const layout = await focalEditor.evaluate((node) => {
                const rect = node.getBoundingClientRect();
                return {
                    left: rect.left,
                    right: rect.right,
                    viewport: window.innerWidth,
                    overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth,
                };
            });
            if (layout.left < -1 || layout.right > layout.viewport + 1 || layout.overflow > 1) focalResponsiveFailures.push(`${width}:${JSON.stringify(layout)}`);
        }
        ensure(focalResponsiveFailures.length === 0, 'punto focal no desborda entre 320 y 768 px', focalResponsiveFailures.join(' | '));
        await page.setViewportSize({ width: 390, height: 844 });
        const focalTheme = await page.evaluate(() => ({ className: document.body.className, theme: document.body.getAttribute('data-theme') }));
        const focalDarkSurface = await page.evaluate(() => {
            document.body.classList.remove('theme-active-light');
            document.body.classList.add('theme-active-dark');
            document.body.setAttribute('data-theme', 'dark');
            const controls = document.querySelector('[data-workout-gallery-focal-editor] .workouts-image-focal-controls');
            return controls ? getComputedStyle(controls).backgroundColor : '';
        });
        ensure(focalDarkSurface !== 'rgb(255, 255, 255)' && focalDarkSurface !== 'rgba(0, 0, 0, 0)', 'punto focal conserva una superficie oscura real', focalDarkSurface);
        await focalEditor.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-focal-editor-mobile-dark.png') });
        await page.evaluate((theme) => {
            document.body.className = theme.className;
            if (theme.theme === null) document.body.removeAttribute('data-theme');
            else document.body.setAttribute('data-theme', theme.theme);
        }, focalTheme);

        const videoInput = editor.locator('input[name="video_url"]');
        await videoDetails.locator('summary').click();
        await page.waitForFunction(() => !document.querySelector('[data-workout-photo-details]')?.open);
        ensure(!await photoDetails.evaluate((details) => details.open) && await videoDetails.evaluate((details) => details.open), 'abrir video cierra foto en movil');
        await videoInput.fill('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        await page.waitForSelector('[data-workout-video-preview] iframe[src*="youtube-nocookie.com/embed/dQw4w9WgXcQ"]');
        check('previsualizacion privada de YouTube antes de guardar', true);
        ensure(await videoDetails.locator('[data-workout-video-status]').textContent() === await videoDetails.locator('[data-workout-video-status]').getAttribute('data-ready-label'), 'estado de video cambia al instante');
        await editor.locator('input[name="cover_mode"][value="video"] + span').click();

        await livePreview.locator('summary').click();
        ensure(await livePreview.locator('[data-workout-preview-panel="library"] [data-workout-preview-media]').getAttribute('data-preview-source') === 'video', 'portada Vídeo cambia la tarjeta viva sin guardar');
        ensure((await livePreview.locator('[data-workout-preview-panel="library"] [data-workout-preview-image]').getAttribute('src') || '').includes('i.ytimg.com/vi/dQw4w9WgXcQ'), 'vista previa usa la miniatura de YouTube');
        const previewSessionTab = livePreview.locator('[data-workout-preview-mode="session"]');
        await previewSessionTab.click();
        ensure(await livePreview.locator('[data-workout-preview-panel="session"]').isVisible() && (await livePreview.locator('[data-workout-preview-panel="session"] [data-workout-preview-target]').textContent()).trim() === '4×8', 'contexto sesión enseña portada y objetivo reales');
        await previewSessionTab.press('ArrowLeft');
        ensure(await livePreview.locator('[data-workout-preview-mode="library"]').getAttribute('aria-selected') === 'true' && await livePreview.locator('[data-workout-preview-mode="library"]').evaluate((tab) => document.activeElement === tab), 'selector de contexto funciona con teclado y restaura foco');
        const previewResponsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1000 });
            const layout = await livePreview.evaluate((details) => {
                const rect = details.getBoundingClientRect();
                return { left: rect.left, right: rect.right, viewport: innerWidth, overflow: document.documentElement.scrollWidth - innerWidth };
            });
            if (layout.left < -1 || layout.right > layout.viewport + 1 || layout.overflow > 1) previewResponsiveFailures.push(`${width}:${JSON.stringify(layout)}`);
        }
        ensure(previewResponsiveFailures.length === 0, 'vista previa no desborda entre 320 y 768 px', previewResponsiveFailures.join(' | '));
        await page.setViewportSize({ width: 390, height: 844 });
        await livePreview.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-live-preview-mobile.png') });
        const livePreviewTheme = await page.evaluate(() => ({ className: document.body.className, theme: document.body.getAttribute('data-theme') }));
        const darkLiveSurface = await page.evaluate(() => {
            document.body.classList.remove('theme-active-light');
            document.body.classList.add('theme-active-dark');
            document.body.setAttribute('data-theme', 'dark');
            const root = document.querySelector('[data-workout-exercise-live-preview]');
            const card = root?.querySelector('[data-workout-preview-panel="library"]');
            return {
                rootImage: root ? getComputedStyle(root).backgroundImage : 'none',
                cardColor: card ? getComputedStyle(card).backgroundColor : '',
            };
        });
        ensure(darkLiveSurface.rootImage !== 'none' && darkLiveSurface.cardColor !== 'rgb(255, 255, 255)' && darkLiveSurface.cardColor !== 'rgba(0, 0, 0, 0)', 'vista previa conserva una superficie oscura real', JSON.stringify(darkLiveSurface));
        await livePreview.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-live-preview-mobile-dark.png') });
        await page.evaluate((theme) => {
            document.body.className = theme.className;
            if (theme.theme === null) document.body.removeAttribute('data-theme');
            else document.body.setAttribute('data-theme', theme.theme);
        }, livePreviewTheme);

        const undersizedControls = await editor.evaluate((form) => [...form.querySelectorAll('button, .btn, summary, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea, .chip, .workouts-custom-cover-picker label > span, .workouts-image-focus-presets label > span, .workouts-routine-color-options label > span, .workouts-exercise-mark-options input + span')]
            .filter((element) => {
                const rect = element.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0 && rect.height < 43.5;
            })
            .map((element) => `${element.tagName.toLowerCase()}[${element.getAttribute('name') || element.className || element.type}]=${Math.round(element.getBoundingClientRect().height)}`));
        check('controles tactiles del editor alcanzan 44 px', undersizedControls.length === 0, undersizedControls.join(', '));
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-editor-mobile.png'), fullPage: true });

        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('exercise_id')) > 0),
            editor.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        exerciseId = Number(new URL(page.url()).searchParams.get('exercise_id'));
        ensure(exerciseId > 0, 'creacion personal redirige a una URL compartible', `#${exerciseId}`);
        ensure(!await page.evaluate((key) => Boolean(localStorage.getItem(key)), draftKey)
            && !await page.evaluate(() => Boolean(sessionStorage.getItem('fitness-challenge:exercise-draft:pending-clear'))), 'guardar limpia el borrador completado');
        ensure(await page.locator('.workouts-exercise-hero').filter({ hasText: unique }).count() === 1, 'guia muestra el ejercicio creado');
        ensure(await page.locator('.workouts-exercise-hero').evaluate((hero) => getComputedStyle(hero).getPropertyValue('--exercise-accent').trim()) === initialAccent, 'guia usa el color personal guardado');
        ensure((await page.locator('.workouts-exercise-hero-icon').textContent()).trim() === initialMark, 'guia usa el simbolo personal guardado');
        const createdGuideSteps = await page.locator('.workouts-guide-steps li p').allInnerTexts();
        ensure(JSON.stringify(createdGuideSteps) === JSON.stringify(['Apoya los pies', 'Baja con control', 'Empuja sin despegar la espalda']), 'guia personal muestra los pasos en el orden elegido', createdGuideSteps.join(' → '));
        const guideAddForm = page.locator('.workouts-exercise-add-form').filter({ has: page.locator('select[name="routine_id"]') });
        ensure(await guideAddForm.locator('input[name="target_sets"]').inputValue() === '4' && await guideAddForm.locator('input[name="target_reps"]').inputValue() === '8', 'guia propone series y repeticiones personales');
        ensure(await guideAddForm.locator('input[name="target_weight"]').inputValue() === '24' && await guideAddForm.locator('input[name="rest_seconds"]').inputValue() === '75' && await guideAddForm.locator('input[name="unit"]').inputValue() === 'lb', 'guia conserva carga, descanso y unidad del preset');
        const guideViewer = page.locator('.workouts-guide-media [data-workout-media-viewer]');
        ensure(await guideViewer.count() === 1 && await guideViewer.locator('[role="tab"]').count() === 2, 'guia agrupa foto y video en un visor compacto');
        ensure(await guideViewer.locator('[data-workout-media-panel="video"]').isVisible(), 'portada video abre el medio elegido');
        ensure(await guideViewer.locator('iframe').count() === 0, 'YouTube no se carga antes de pedirlo');
        await guideViewer.locator('[data-workout-video-load]').click();
        await page.waitForSelector('.workouts-guide-media iframe[src*="youtube-nocookie.com"]');
        ensure(await guideViewer.locator('iframe[src*="youtube-nocookie.com"]').count() === 1, 'guia carga el video seguro bajo demanda');
        await guideViewer.locator('[data-workout-media-tab="photo"]').click();
        ensure(await guideViewer.locator('[data-workout-media-panel="photo"]').isVisible(), 'selector cambia del video a la foto');
        const guideGallery = guideViewer.locator('[data-workout-media-gallery]');
        ensure(await guideGallery.locator('[data-workout-gallery-slide]').count() === 3 && await guideGallery.locator('[data-workout-gallery-viewer-thumb]').count() === 3, 'guia muestra las tres fotos custom en una galeria compacta');
        ensure(await guideGallery.locator('[data-workout-gallery-caption-slide]').count() === 3
            && (await guideGallery.locator('[data-workout-gallery-caption-slide]').first().textContent()).trim() === 'Vista lateral', 'guia muestra el título de la portada elegida');
        ensure(await guideGallery.locator('[data-workout-gallery-slide]').first().evaluate((image) => getComputedStyle(image).objectPosition === '82% 50%'), 'guia conserva el encuadre derecho de la portada');
        await guideGallery.locator('[data-workout-gallery-viewer-move="next"]').click();
        ensure((await guideGallery.locator('[data-workout-gallery-viewer-status]').textContent()).trim() === '2 / 3' && await guideGallery.locator('[data-workout-gallery-slide]').nth(1).isVisible(), 'flechas recorren la galeria sin abandonar la guia');
        ensure((await guideGallery.locator('[data-workout-gallery-caption-slide]:visible').textContent()).trim() === 'Posición inicial', 'el título visible sigue a la foto activa');
        ensure(await guideGallery.locator('[data-workout-gallery-slide]').nth(1).evaluate((image) => getComputedStyle(image).objectPosition === '27% 71%'), 'guia conserva el punto focal fino de una foto secundaria');
        const secondGalleryThumb = guideGallery.locator('[data-workout-gallery-viewer-thumb]').nth(1);
        await secondGalleryThumb.focus();
        await secondGalleryThumb.press('Home');
        ensure(await guideGallery.locator('[data-workout-gallery-viewer-thumb]').first().getAttribute('aria-pressed') === 'true' && await guideGallery.locator('[data-workout-gallery-viewer-thumb]').first().evaluate((thumb) => document.activeElement === thumb), 'miniaturas permiten navegar con teclado y restauran el foco');
        ensure(await guideViewer.locator('[data-workout-media-tab]').evaluateAll((tabs) => tabs.every((tab) => tab.getBoundingClientRect().height >= 43.5)), 'tabs multimedia mantienen objetivos tactiles');
        const guidePhotoTab = guideViewer.locator('[data-workout-media-tab="photo"]');
        await guidePhotoTab.focus();
        await guidePhotoTab.press('ArrowRight');
        ensure(await guideViewer.locator('[data-workout-media-tab="video"]').getAttribute('aria-selected') === 'true' && await guideViewer.locator('[data-workout-media-tab="video"]').evaluate((tab) => document.activeElement === tab), 'teclado cambia a Video y conserva foco');
        await guideViewer.locator('[data-workout-media-tab="video"]').press('ArrowLeft');
        ensure(await guidePhotoTab.getAttribute('aria-selected') === 'true', 'teclado vuelve a Foto');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-gallery-guide-mobile.png'), fullPage: true });
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-guide-mobile.png'), fullPage: true });
        await page.evaluate(() => {
            document.body.dataset.theme = 'dark';
            document.body.classList.add('theme-active-dark');
        });
        const darkGuideSurface = await page.locator('.workouts-guide-media').evaluate((node) => getComputedStyle(node).backgroundColor);
        check('visor multimedia conserva superficie oscura', darkGuideSurface !== 'rgb(255, 255, 255)', darkGuideSurface);
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-guide-mobile-dark.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=library&scope=mine&q=${encodeURIComponent(unique)}`, { waitUntil: 'networkidle' });
        const videoCoverCard = page.locator('.workouts-library-card.is-personal').filter({ hasText: unique });
        ensure(await videoCoverCard.evaluate((card) => getComputedStyle(card).getPropertyValue('--exercise-accent').trim()) === initialAccent, 'biblioteca propaga el color personal');
        ensure(await videoCoverCard.getAttribute('data-cover-mode') === 'video', 'modo Vídeo persiste en la tarjeta');
        ensure(await videoCoverCard.locator('.workouts-library-media.is-video-thumbnail').count() === 1, 'YouTube se convierte en portada aunque exista una foto');
        ensure((await videoCoverCard.locator('.workouts-library-video-fallback').textContent()).trim() === initialMark, 'portada de video conserva el simbolo como fallback');
        const videoCoverStyle = await videoCoverCard.locator('.workouts-library-media').getAttribute('style');
        ensure((videoCoverStyle || '').includes('i.ytimg.com/vi/dQw4w9WgXcQ'), 'portada usa la miniatura segura de YouTube');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-youtube-cover-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${exerciseId}`, { waitUntil: 'networkidle' });
        const editForm = page.locator('form.workouts-custom-editor');
        ensure(await editForm.locator('input[name="cover_mode"][value="video"]:checked').count() === 1, 'selector restaura la portada elegida');
        ensure(await editForm.locator('input[name="image_position"][value="right"]:checked').count() === 1, 'selector restaura el encuadre elegido');
        const editDefaults = editForm.locator('[data-workout-training-defaults]');
        ensure((await editDefaults.locator('[data-workout-default-status]').textContent()).trim() === '4×8', 'editor restaura el objetivo inicial');
        await editDefaults.locator('summary').click();
        ensure(await editDefaults.locator('input[name="default_weight"]').inputValue() === '24' && await editDefaults.locator('input[name="default_rest_seconds"]').inputValue() === '75' && await editDefaults.locator('textarea[name="default_notes"]').inputValue() === 'Pausa y empuja estable', 'editor restaura carga, descanso y notas');
        await editForm.locator('[data-workout-editor-step-trigger="media"]').click();
        ensure(await editForm.locator('[data-workout-photo-details]').evaluate((details) => !details.open && details.classList.contains('has-media')), 'foto guardada se resume sin ocupar espacio');
        ensure(await editForm.locator('[data-workout-gallery-item]').count() === 3 && (await editForm.locator('[data-workout-gallery-status]').textContent()).trim().startsWith('3 / 4'), 'editor restaura las tres fotos y su capacidad');
        ensure(await editForm.locator('[data-workout-gallery-position]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|')) === 'right|focal:27:71|center', 'editor restaura el encuadre independiente de cada foto');
        ensure(await editForm.locator('[data-workout-gallery-caption]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|')) === 'Vista lateral|Posición inicial|Bloqueo final', 'editor restaura los títulos asociados al orden guardado');
        ensure(await editForm.locator('[data-workout-video-details]').evaluate((details) => !details.open && details.classList.contains('has-media')), 'video guardado se resume sin ocupar espacio');
        const editColorDetails = editForm.locator('.workouts-custom-color-details');
        await editColorDetails.locator('summary').click();
        ensure(await editColorDetails.locator('[data-workout-color-input]').inputValue() === initialAccent, 'selector restaura el color elegido');
        ensure(await editColorDetails.locator('[data-workout-mark-input]').inputValue() === initialMark, 'selector restaura el simbolo elegido');
        await editColorDetails.locator('[data-workout-color-input]').fill(updatedAccent);
        await editColorDetails.locator('[data-workout-mark-input]').fill(updatedMark);
        await editForm.locator('[data-workout-editor-step-trigger="basics"]').click();
        await editForm.locator('input[name="name"]').fill(updatedName);
        await editForm.locator('[data-workout-editor-step-trigger="guide"]').click();
        await editForm.locator('textarea[name="summary"]').fill('Guia actualizada desde el editor personal.');
        const editGuideBuilder = editForm.locator('[data-workout-guide-builder]');
        const editStepsBuilder = editGuideBuilder.locator('[data-guide-key="steps"]');
        ensure(await editStepsBuilder.locator('[data-guide-item-input]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|')) === 'Apoya los pies|Baja con control|Empuja sin despegar la espalda', 'editor restaura cada elemento de la guía');
        await editStepsBuilder.locator('summary').click();
        await editStepsBuilder.locator('[data-guide-item]').nth(2).locator('[data-guide-move="up"]').click();
        await editForm.locator('[data-workout-editor-step-trigger="media"]').click();
        await editForm.locator('[data-workout-photo-details] summary').click();
        const editGalleryItems = editForm.locator('[data-workout-gallery-item]');
        await editGalleryItems.last().locator('[data-workout-gallery-remove]').click();
        ensure(await editGalleryItems.count() === 2, 'edicion permite retirar una foto guardada');
        await editGalleryItems.nth(1).locator('[data-workout-gallery-cover]').check({ force: true });
        await editForm.locator('input[name="image_position"][value="bottom"] + span').click();
        ensure(await editForm.locator('[data-workout-gallery-item].is-cover [data-workout-gallery-image]').evaluate((image) => getComputedStyle(image).objectPosition === '50% 82%'), 'edicion actualiza portada y encuadre en vivo');
        await editForm.locator('[data-workout-video-details] summary').click();
        await editForm.locator('input[name="video_url"]').fill('https://vimeo.com/123456789');
        await page.waitForSelector('[data-workout-video-preview] iframe[src*="player.vimeo.com/video/123456789"]');
        await editForm.locator('input[name="cover_mode"][value="photo"] + span').click();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('exercise_id')) === exerciseId),
            editForm.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        ensure(await page.locator('.workouts-exercise-hero').filter({ hasText: updatedName }).count() === 1, 'edicion personal persiste contenido');
        ensure(await page.locator('.workouts-guide-steps li p').evaluateAll((items) => items.map((item) => item.textContent.trim()).join('|')) === 'Apoya los pies|Empuja sin despegar la espalda|Baja con control', 'edicion persiste el nuevo orden de la guía');
        ensure(await page.locator('.workouts-exercise-hero').evaluate((hero) => getComputedStyle(hero).getPropertyValue('--exercise-accent').trim()) === updatedAccent, 'edicion persiste el nuevo color');
        ensure((await page.locator('.workouts-exercise-hero-icon').textContent()).trim() === updatedMark, 'edicion persiste el nuevo simbolo');
        const updatedGuideViewer = page.locator('.workouts-guide-media [data-workout-media-viewer]');
        ensure(await updatedGuideViewer.locator('[data-workout-media-panel="photo"]').isVisible(), 'portada foto restaura la vista visual elegida');
        ensure(await updatedGuideViewer.locator('[data-workout-gallery-slide]').count() === 2, 'edicion persiste la retirada de una foto');
        ensure(await updatedGuideViewer.locator('[data-workout-gallery-slide]').first().evaluate((image) => getComputedStyle(image).objectPosition === '50% 82%'), 'edicion persiste la nueva portada con encuadre inferior');
        ensure(await updatedGuideViewer.locator('[data-workout-gallery-slide]').nth(1).evaluate((image) => getComputedStyle(image).objectPosition === '82% 50%'), 'guia conserva el encuadre previo de la foto secundaria');
        await updatedGuideViewer.locator('[data-workout-media-tab="video"]').click();
        await updatedGuideViewer.locator('[data-workout-video-load]').click();
        await page.waitForSelector('.workouts-guide-video iframe[src*="player.vimeo.com"]');
        ensure(await updatedGuideViewer.locator('iframe[src*="player.vimeo.com"]').count() === 1, 'edicion cambia el proveedor de video');

        const outsider = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const outsiderPage = await outsider.newPage();
        await login(outsiderPage, 'catalina');
        await outsiderPage.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${exerciseId}`, { waitUntil: 'networkidle' });
        check('otro usuario no puede abrir el editor personal', await outsiderPage.locator('form.workouts-custom-editor').count() === 0 && outsiderPage.url().includes('scope=mine'));
        await outsider.close();

        const encodedName = encodeURIComponent(updatedName);
        await page.goto(`${BASE}/?page=workouts&view=library&scope=mine&q=${encodedName}`, { waitUntil: 'networkidle' });
        const card = page.locator('.workouts-library-card.is-personal').filter({ hasText: updatedName });
        ensure(await card.count() === 1, 'filtro Mis ejercicios encuentra el ejercicio custom');
        ensure(await card.getAttribute('data-cover-mode') === 'photo', 'portada se puede cambiar de vídeo a foto');
        ensure(await card.locator('.workouts-library-media img').count() === 1, 'tarjeta personal conserva la foto');
        ensure(await card.locator('.workouts-library-media img').evaluate((image) => getComputedStyle(image).objectPosition === '50% 82%'), 'tarjeta personal aplica el encuadre guardado');
        ensure(await card.locator('.workouts-library-media-badges em').count() >= 2, 'tarjeta distingue contenido propio y video');
        ensure(await card.evaluate((node) => getComputedStyle(node).getPropertyValue('--exercise-accent').trim()) === updatedAccent, 'tarjeta actualizada conserva el color libre');
        ensure((await card.locator('.workouts-training-default-chip').textContent()).trim() === '4×8', 'tarjeta muestra el objetivo inicial personal');
        const scopeAfterFilter = await page.locator('.workouts-library-filters input[name="scope"]').inputValue();
        check('busqueda conserva el scope personal', scopeAfterFilter === 'mine');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercises-library-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${exerciseId}`, { waitUntil: 'networkidle' });
        const simpleCoverForm = page.locator('form.workouts-custom-editor');
        await simpleCoverForm.locator('[data-workout-editor-step-trigger="media"]').click();
        await simpleCoverForm.locator('input[name="cover_mode"][value="simple"] + span').click();
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('exercise_id')) === exerciseId),
            simpleCoverForm.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        ensure((await page.locator('.workouts-exercise-hero-icon').textContent()).trim() === updatedMark, 'guia simple conserva la marca visual');
        await page.goto(`${BASE}/?page=workouts&view=library&scope=mine&q=${encodedName}`, { waitUntil: 'networkidle' });
        const simpleCard = page.locator('.workouts-library-card.is-personal').filter({ hasText: updatedName });
        ensure(await simpleCard.getAttribute('data-cover-mode') === 'simple', 'modo simple deja protagonismo al simbolo');
        ensure((await simpleCard.locator('.workouts-library-media.is-placeholder > span').first().textContent()).trim() === updatedMark, 'biblioteca muestra el simbolo personalizado');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-symbol-mobile.png'), fullPage: true });

        const responsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1024 });
            for (const url of [
                `/?page=workouts&view=library&custom_exercise=${exerciseId}`,
                `/?page=workouts&view=library&scope=mine&q=${encodedName}`,
                `/?page=workouts&exercise_id=${exerciseId}`,
            ]) {
                await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
                const excess = await overflow(page);
                if (excess > 1) responsiveFailures.push(`${width}px:${excess}px`);
            }
        }
        check('editor, biblioteca y visor responden entre 320 y 768 px', responsiveFailures.length === 0, responsiveFailures.join(', '));

        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${exerciseId}`, { waitUntil: 'networkidle' });
        await page.evaluate(() => {
            document.body.dataset.theme = 'dark';
            document.body.classList.add('theme-active-dark');
        });
        const editorBackground = await page.locator('.workouts-custom-section').first().evaluate((node) => getComputedStyle(node).backgroundColor);
        check('editor personal conserva tema oscuro', editorBackground !== 'rgb(255, 255, 255)', editorBackground);
        await page.locator('[data-workout-editor-step-trigger="media"]').click();
        await page.locator('.workouts-custom-color-details summary').click();
        ensure(await page.locator('.workouts-custom-color-details').evaluate((details) => getComputedStyle(details).getPropertyValue('--exercise-accent').trim()) === updatedAccent, 'color personal se conserva en tema oscuro');
        ensure((await page.locator('[data-workout-mark-preview]').first().textContent()).trim() === updatedMark, 'simbolo personal se conserva en tema oscuro');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-editor-mobile-dark.png'), fullPage: true });

        await page.goto(`${BASE}/?page=workouts&view=library&scope=mine&q=${encodedName}`, { waitUntil: 'networkidle' });
        const addForm = page.locator('.workouts-library-card.is-personal').filter({ hasText: updatedName }).locator('form.workouts-library-add');
        if (await addForm.count()) {
            await addForm.locator('select[name="routine_id"]').selectOption(String(routineId));
            await Promise.all([
                page.waitForLoadState('networkidle'),
                addForm.locator('button[type="submit"]').click(),
            ]);
            check('ejercicio personal se puede anadir a una rutina', true);
        } else {
            check('ejercicio personal se puede anadir a una rutina', false, 'no hay rutina activa en fixture');
        }

        await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}`, { waitUntil: 'networkidle' });
        const routineExerciseRow = page.locator('.workouts-exercise-row').filter({ hasText: updatedName });
        ensure(await routineExerciseRow.count() === 1, 'rutina muestra el ejercicio personal');
        ensure(await routineExerciseRow.evaluate((row) => getComputedStyle(row).getPropertyValue('--exercise-accent').trim()) === updatedAccent, 'rutina usa el color propio del ejercicio');
        ensure((await routineExerciseRow.locator('.workouts-exercise-cover').textContent()).trim() === updatedMark, 'rutina usa el simbolo propio del ejercicio');
        const routinePrescription = (await routineExerciseRow.textContent()).replace(/\s+/g, ' ');
        ensure(routinePrescription.includes('4×8') && routinePrescription.includes('24lb') && routinePrescription.includes('75s') && routinePrescription.includes('Pausa y empuja estable'), 'rutina hereda objetivo, carga, descanso y nota personal', routinePrescription);
        const startSessionForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_start"]') }).filter({ has: page.locator(`input[name="routine_id"][value="${routineId}"]`) });
        await Promise.all([
            page.waitForURL((url) => Number(url.searchParams.get('session_id')) > 0),
            startSessionForm.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        const sessionExercise = page.locator('.workouts-session-exercise').filter({ hasText: updatedName });
        ensure(await sessionExercise.count() === 1, 'sesion muestra el ejercicio personal');
        ensure(await sessionExercise.evaluate((article) => getComputedStyle(article).getPropertyValue('--exercise-accent').trim()) === updatedAccent, 'sesion usa el color propio del ejercicio');
        ensure((await sessionExercise.locator('.workouts-exercise-cover').textContent()).trim() === updatedMark, 'sesion usa el simbolo propio del ejercicio');
        ensure(await sessionExercise.locator('.workouts-set-row').count() === 4, 'sesion crea las cuatro series del preset');
        ensure(await sessionExercise.locator('.workouts-set-row input[name="weight"]').evaluateAll((inputs) => inputs.every((input) => input.value === '24')) && await sessionExercise.locator('.workouts-set-row input[name="reps"]').evaluateAll((inputs) => inputs.every((input) => input.value === '8')), 'sesion precarga peso y repeticiones personales');
        const sessionPrescription = (await sessionExercise.locator('.workouts-session-prescription').textContent()).replace(/\s+/g, ' ');
        ensure(sessionPrescription.includes('75') && sessionPrescription.includes('Pausa y empuja estable'), 'sesion muestra descanso y nota personal');
        const sessionTechnique = sessionExercise.locator('[data-workout-session-technique]');
        ensure(await sessionTechnique.count() === 1 && !await sessionTechnique.evaluate((details) => details.open), 'tecnica inline empieza compacta durante la sesion');
        await sessionTechnique.locator('summary').click();
        ensure(await sessionTechnique.locator('[data-workout-media-viewer]').count() === 1, 'sesion abre foto y video sin abandonar el entrenamiento');
        ensure(await sessionTechnique.locator('[data-workout-media-tab]').count() === 2, 'sesion conserva el selector Foto/Video');
        const sessionGallery = sessionTechnique.locator('[data-workout-media-gallery]');
        ensure(await sessionGallery.locator('[data-workout-gallery-slide]').count() === 2, 'sesion conserva la galeria de tecnica editada');
        ensure((await sessionGallery.locator('[data-workout-gallery-caption-slide]:visible').textContent()).trim() === 'Posición inicial', 'sesion muestra el título de la técnica activa');
        await sessionGallery.locator('[data-workout-gallery-viewer-move="next"]').click();
        ensure((await sessionGallery.locator('[data-workout-gallery-viewer-status]').textContent()).trim() === '2 / 2', 'galeria de sesion se recorre sin salir del registro');
        ensure((await sessionGallery.locator('[data-workout-gallery-caption-slide]:visible').textContent()).trim() === 'Vista lateral', 'sesion actualiza el título al cambiar de ángulo');
        await sessionTechnique.locator('[data-workout-media-tab="video"]').click();
        ensure(await sessionTechnique.locator('iframe').count() === 0, 'sesion tampoco precarga el proveedor externo');
        await sessionTechnique.locator('[data-workout-video-load]').click();
        await page.waitForSelector('.workouts-session-technique iframe[src*="player.vimeo.com"]');
        ensure(await sessionTechnique.locator('iframe[src*="player.vimeo.com"]').count() === 1, 'video personalizado se reproduce dentro de la sesion');
        ensure(await sessionTechnique.locator('iframe').evaluate((frame) => document.activeElement === frame), 'reproductor recibe foco tras pulsar reproducir');
        ensure(await sessionTechnique.locator('summary, [data-workout-media-tab], [data-workout-video-load]').evaluateAll((nodes) => nodes.filter((node) => {
            const rect = node.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
        }).every((node) => node.getBoundingClientRect().height >= 43.5)), 'controles de tecnica inline mantienen 44 px');
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-session-mobile.png'), fullPage: true });
        await page.evaluate(() => {
            document.body.dataset.theme = 'dark';
            document.body.classList.add('theme-active-dark');
        });
        const darkSessionTechnique = await sessionTechnique.evaluate((node) => getComputedStyle(node).backgroundColor);
        check('tecnica inline conserva tema oscuro', darkSessionTechnique !== 'rgb(255, 255, 255)', darkSessionTechnique);
        await page.screenshot({ path: path.join(reportDir, 'ui-custom-exercise-session-mobile-dark.png'), fullPage: true });

        const sessionUrl = page.url();
        const sessionResponsiveFailures = [];
        for (const width of [320, 360, 390, 430, 768]) {
            await page.setViewportSize({ width, height: width < 700 ? 844 : 1024 });
            await page.goto(sessionUrl, { waitUntil: 'networkidle' });
            const responsiveTechnique = page.locator('.workouts-session-exercise.is-active [data-workout-session-technique]');
            await responsiveTechnique.locator('summary').click();
            const excess = await overflow(page);
            if (excess > 1) sessionResponsiveFailures.push(`${width}px:${excess}px`);
        }
        check('tecnica inline no desborda entre 320 y 768 px', sessionResponsiveFailures.length === 0, sessionResponsiveFailures.join(', '));
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(sessionUrl, { waitUntil: 'networkidle' });
        const cancelSessionForm = page.locator('form').filter({ has: page.locator('input[name="action"][value="session_cancel"]') });
        if (await cancelSessionForm.count()) {
            await Promise.all([
                page.waitForLoadState('networkidle'),
                cancelSessionForm.locator('button[type="submit"]').click(),
            ]);
        }

        await page.goto(`${BASE}/?page=workouts&view=library&custom_exercise=${exerciseId}`, { waitUntil: 'networkidle' });
        const deleteForm = page.locator('form.workouts-custom-danger');
        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('scope') === 'mine' && !url.searchParams.has('custom_exercise')),
            deleteForm.locator('button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('networkidle');
        check('borrado vuelve a Mis ejercicios', page.url().includes('scope=mine'));
        check('ejercicio eliminado desaparece de la biblioteca', await page.getByText(updatedName, { exact: true }).count() === 0);

        check('sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
        check('sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('flujo completo de ejercicios personalizados', false, error.stack || error.message);
    } finally {
        if (!page.isClosed() && routineId > 0) {
            try {
                await page.goto(`${BASE}/?page=workouts&routine_id=${routineId}&section=settings`, { waitUntil: 'networkidle', timeout: 5000 });
                const removeRoutine = page.locator('.workouts-routine-danger form').filter({ has: page.locator('input[name="action"][value="routine_delete"]') });
                if (await removeRoutine.count()) await removeRoutine.locator('button[type="submit"]').click({ timeout: 3000 });
            } catch (_) { /* best effort */ }
        }
        await browser.close();
    }

    const failed = checks.filter((item) => !item.pass);
    console.log(`\n${checks.length - failed.length}/${checks.length} comprobaciones correctas.`);
    if (failed.length) process.exitCode = 1;
})();
