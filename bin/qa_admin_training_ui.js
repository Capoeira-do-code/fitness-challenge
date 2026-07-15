/**
 * Browser QA for the Training & ranked admin surface.
 * Run only against a disposable DB: it creates and deactivates one exercise.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const value = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = value('base', 'http://127.0.0.1:8120').replace(/\/$/, '');
const USERNAME = value('username', 'roberto');
const PASSWORD = value('password', 'Verify123!');
const screenshotDir = path.join(__dirname, '..', 'e2e-report');

const checks = [];
const check = (name, pass, detail = '') => {
    checks.push({ name, pass, detail });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
};

(async () => {
    fs.mkdirSync(screenshotDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    page.setDefaultTimeout(12000);
    const pageErrors = [];
    const failedResponses = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('response', (response) => {
        if (response.status() >= 500) failedResponses.push(`${response.status()} ${response.url()}`);
    });
    page.on('dialog', (dialog) => dialog.accept());

    await login(page);
    await page.goto(`${BASE}/?page=admin&section=training`, { waitUntil: 'networkidle' });
    await page.locator('[data-spa-section="training"]:not([hidden])').waitFor();

    const desktop = await page.evaluate(() => {
        const section = document.querySelector('[data-spa-section="training"]:not([hidden])');
        return {
            tiers: section?.querySelectorAll('.admin-rank-tier-row').length || 0,
            seasons: section?.querySelectorAll('.admin-season-item').length || 0,
            exercises: section?.querySelectorAll('.admin-training-exercise-row').length || 0,
            hasCreate: Boolean(section?.querySelector('a[href*="exercise_id=new"]')),
            overflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });
    check('Admin Training reúne tiers, seasons y ejercicios', desktop.tiers === 8 && desktop.exercises >= 32 && desktop.hasCreate, `${desktop.tiers} tiers · ${desktop.seasons} seasons · ${desktop.exercises} exercises`);
    check('Admin Training desktop no desborda', desktop.overflow <= 1, `${desktop.overflow}px`);
    await page.screenshot({ path: path.join(screenshotDir, 'ui-admin-training-desktop.png'), fullPage: false });

    await page.locator('.admin-training-block').nth(1).locator(':scope > summary').click();
    const seasonUi = await page.evaluate(() => {
        const block = document.querySelectorAll('.admin-training-block')[1];
        return {
            open: Boolean(block?.open),
            dates: block?.querySelectorAll('input[type="date"]').length || 0,
            editForms: block?.querySelectorAll('input[name="season_id"]').length || 0,
        };
    });
    check('Seasons se gestionan sin abandonar la sección', seasonUi.open && seasonUi.dates >= 2 && seasonUi.editForms >= 1, `${seasonUi.editForms} editables`);

    const uniqueName = `QA Media Press ${Date.now()}`;
    const accentColor = '#2563eb';
    const visualMark = 'AX';
    await page.goto(`${BASE}/?page=admin&section=training&exercise_id=new`, { waitUntil: 'networkidle' });
    const createForm = page.locator('.admin-create-view[data-spa-value="new"]:not([hidden]) form.admin-training-exercise-form');
    await createForm.waitFor();
    await createForm.locator('input[name="name"]').fill(uniqueName);
    await createForm.locator('select[name="muscle_group"]').selectOption('chest');
    await createForm.locator('select[name="equipment"]').selectOption('dumbbell');
    await createForm.locator('textarea[name="summary"]').fill('A compact QA technique guide with real example media.');
    const adminLivePreview = createForm.locator('[data-workout-exercise-live-preview]');
    check('Editor admin incluye una vista previa contextual abierta en desktop', await adminLivePreview.count() === 1 && await adminLivePreview.evaluate((details) => details.open));
    check('Vista previa admin actualiza nombre y descripción sin guardar', (await adminLivePreview.locator('summary [data-workout-preview-name]').textContent()).trim() === uniqueName && (await adminLivePreview.locator('[data-workout-preview-summary]').textContent()).trim().startsWith('A compact QA'));
    const adminGuideBuilder = createForm.locator('[data-workout-guide-builder]');
    check('Editor admin agrupa la guía en tres submenus', await adminGuideBuilder.locator('[data-guide-section]').count() === 3 && await adminGuideBuilder.locator('[data-guide-section]').evaluateAll((sections) => sections.every((section) => !section.open)));
    const adminStepsBuilder = adminGuideBuilder.locator('[data-guide-key="steps"]');
    await adminStepsBuilder.locator('summary').click();
    await adminStepsBuilder.locator('[data-guide-add]').click();
    const adminFirstStep = adminStepsBuilder.locator('[data-guide-item-input]').first();
    await adminFirstStep.fill('Set the shoulder blades');
    await adminFirstStep.press('Enter');
    await adminStepsBuilder.locator('[data-guide-item-input]').nth(1).fill('Press with control');
    await adminStepsBuilder.locator('[data-guide-item-input]').nth(1).press('Enter');
    await adminStepsBuilder.locator('[data-guide-item-input]').nth(2).fill('Lower smoothly');
    check('Editor admin construye y sincroniza pasos', (await adminStepsBuilder.locator('[data-guide-count]').textContent()).trim() === '3' && await adminStepsBuilder.locator('[data-guide-output]').inputValue() === 'Set the shoulder blades\nPress with control\nLower smoothly');
    const defaultsDetails = createForm.locator('[data-workout-training-defaults]');
    check('Editor admin agrupa el objetivo inicial', await defaultsDetails.count() === 1 && !await defaultsDetails.evaluate((details) => details.open));
    await defaultsDetails.locator('summary').click();
    await defaultsDetails.locator('input[name="default_sets"]').fill('5');
    await defaultsDetails.locator('input[name="default_reps"]').fill('6');
    await defaultsDetails.locator('input[name="default_weight"]').fill('32.5');
    await defaultsDetails.locator('input[name="default_rest_seconds"]').fill('75');
    await defaultsDetails.locator('select[name="default_unit"]').selectOption('lb');
    await defaultsDetails.locator('textarea[name="default_notes"]').fill('Admin default cue');
    check('Editor admin actualiza el resumen del objetivo', (await defaultsDetails.locator('[data-workout-default-status]').textContent()).trim() === '5×6');
    check('Vista previa admin refleja el objetivo inicial', (await adminLivePreview.locator('[data-workout-preview-target]').first().textContent()).trim() === '5×6');
    const colorDetails = createForm.locator('.admin-training-color-details');
    check('Editor admin agrupa el color en un submenu', await colorDetails.count() === 1 && !await colorDetails.evaluate((details) => details.open));
    await colorDetails.locator('summary').click();
    check('Editor admin ofrece presets y color libre', await colorDetails.locator('[data-workout-color-preset]').count() === 8 && await colorDetails.locator('[data-workout-color-input]').count() === 1);
    check('Editor admin ofrece simbolos y marca libre', await colorDetails.locator('[data-workout-mark-preset]').count() === 8 && await colorDetails.locator('[data-workout-mark-input]').count() === 1);
    await colorDetails.locator('[data-workout-mark-input]').fill(visualMark);
    await colorDetails.locator('[data-workout-color-input]').fill(accentColor);
    check('Editor admin previsualiza identidad completa', await colorDetails.locator('[data-workout-color-output]').textContent() === accentColor.toUpperCase() && await colorDetails.evaluate((details) => getComputedStyle(details).getPropertyValue('--exercise-accent').trim()) === accentColor && (await colorDetails.locator('[data-workout-mark-preview]').textContent()).trim() === visualMark);
    await createForm.locator('input[name="video_url"]').fill('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    await createForm.locator('select[name="cover_mode"]').selectOption('video');
    await createForm.locator('[data-workout-gallery-editor] summary').click();
    await createForm.locator('input[name="image_position"][value="top"] + span').click();
    const imageFixture = path.join(screenshotDir, 'ui-workout-guide-mobile.png');
    const secondImageFixture = path.join(screenshotDir, 'ui-workout-library-mobile.png');
    await createForm.locator('input[name="exercise_images[]"]').setInputFiles([imageFixture, secondImageFixture]);
    const createGalleryItems = createForm.locator('[data-workout-gallery-item]');
    check('Editor admin previsualiza una galería ordenable', await createGalleryItems.count() === 2 && (await createForm.locator('[data-workout-gallery-status]').textContent()).trim().startsWith('2 / 4'));
    await createGalleryItems.nth(0).locator('[data-workout-gallery-caption]').fill('Preparación');
    await createGalleryItems.nth(1).locator('[data-workout-gallery-caption]').fill('Resultado');
    await createGalleryItems.nth(1).locator('[data-workout-gallery-cover]').check({ force: true });
    await createGalleryItems.nth(1).locator('[data-workout-gallery-move="up"]').click();
    await createGalleryItems.nth(1).locator('[data-workout-gallery-focus]').click();
    await createForm.locator('[data-workout-gallery-focal-x]').evaluate((input) => {
        input.value = '34';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await createForm.locator('[data-workout-gallery-focal-y]').evaluate((input) => {
        input.value = '76';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    check('Editor admin ajusta cada foto sin cambiar la portada', await createGalleryItems.locator('[data-workout-gallery-position]').evaluateAll((inputs) => inputs.map((input) => input.value).join('|')) === 'top|focal:34:76' && await createGalleryItems.first().locator('[data-workout-gallery-cover]').isChecked());
    check('Vista previa admin resuelve YouTube, color y símbolo antes de guardar', await adminLivePreview.locator('[data-workout-preview-panel="library"] [data-workout-preview-media]').getAttribute('data-preview-source') === 'video' && (await adminLivePreview.locator('[data-workout-preview-panel="library"] [data-workout-preview-image]').getAttribute('src') || '').includes('i.ytimg.com/vi/dQw4w9WgXcQ') && await adminLivePreview.evaluate((details) => getComputedStyle(details).getPropertyValue('--exercise-accent').trim()) === accentColor && (await adminLivePreview.locator('[data-workout-preview-mark]').first().textContent()).trim() === visualMark);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') === 'admin' && url.searchParams.get('exercise_id')),
        createForm.locator('button[type="submit"]').click(),
    ]);
    await page.waitForLoadState('networkidle');
    const created = await page.evaluate((name) => {
        const form = document.querySelector('.admin-detail-view:not([hidden]) form.admin-training-exercise-form');
        const galleryItems = [...(form?.querySelectorAll('[data-workout-gallery-item]') || [])];
        const coverItem = form?.querySelector('[data-workout-gallery-cover]:checked')?.closest('[data-workout-gallery-item]');
        const preview = coverItem?.querySelector('[data-workout-gallery-image]');
        return {
            id: Number(form?.querySelector('input[name="exercise_id"]')?.value || 0),
            name: form?.querySelector('input[name="name"]')?.value || '',
            galleryCount: galleryItems.length,
            galleryPaths: galleryItems.map((item) => item.querySelector('[data-workout-gallery-order]')?.value || ''),
            galleryPositions: galleryItems.map((item) => item.querySelector('[data-workout-gallery-position]')?.value || ''),
            galleryCaptions: galleryItems.map((item) => item.querySelector('[data-workout-gallery-caption]')?.value || ''),
            preview: preview?.getAttribute('src') || '',
            video: form?.querySelector('input[name="video_url"]')?.value || '',
            coverMode: form?.querySelector('select[name="cover_mode"]')?.value || '',
            imagePosition: form?.querySelector('input[name="image_position"]:checked')?.value || '',
            previewPosition: preview ? getComputedStyle(preview).objectPosition : '',
            accentColor: form?.querySelector('input[name="accent_color"]')?.value || '',
            accentPreview: form?.querySelector('.admin-training-color-details') ? getComputedStyle(form.querySelector('.admin-training-color-details')).getPropertyValue('--exercise-accent').trim() : '',
            visualMark: form?.querySelector('input[name="visual_mark"]')?.value || '',
            defaultSets: form?.querySelector('input[name="default_sets"]')?.value || '',
            defaultReps: form?.querySelector('input[name="default_reps"]')?.value || '',
            defaultWeight: form?.querySelector('input[name="default_weight"]')?.value || '',
            defaultRest: form?.querySelector('input[name="default_rest_seconds"]')?.value || '',
            defaultUnit: form?.querySelector('select[name="default_unit"]')?.value || '',
            defaultNotes: form?.querySelector('textarea[name="default_notes"]')?.value || '',
            guideSteps: [...(form?.querySelectorAll('[data-guide-key="steps"] [data-guide-item-input]') || [])].map((input) => input.value),
            expected: name,
        };
    }, uniqueName);
    check('Admin crea ejercicio con galería, vídeo e identidad visual', created.id > 0 && created.name === created.expected && created.galleryCount === 2 && new Set(created.galleryPaths).size === 2 && created.galleryPaths.every(Boolean) && created.galleryPositions.join('|') === 'top|focal:34:76' && created.galleryCaptions.join('|') === 'Resultado|Preparación' && created.preview !== '' && created.video.includes('youtube.com') && created.coverMode === 'video' && created.imagePosition === 'top' && created.previewPosition === '50% 18%' && created.accentColor === accentColor && created.accentPreview === accentColor && created.visualMark === visualMark, `#${created.id} · ${created.galleryCount} fotos · ${created.galleryPositions.join('/')} · ${created.galleryCaptions.join('/')} · ${created.coverMode} · ${created.accentColor} · ${created.visualMark}`);

    check('Objetivo inicial admin persiste completo', created.defaultSets === '5' && created.defaultReps === '6' && created.defaultWeight === '32.5' && created.defaultRest === '75' && created.defaultUnit === 'lb' && created.defaultNotes === 'Admin default cue', `${created.defaultSets}x${created.defaultReps} @ ${created.defaultWeight}${created.defaultUnit} · ${created.defaultRest}s`);
    check('Guía estructurada admin persiste en orden', created.guideSteps.join('|') === 'Set the shoulder blades|Press with control|Lower smoothly', created.guideSteps.join(' → '));

    await page.goto(`${BASE}/?page=workouts&view=library&q=${encodeURIComponent(uniqueName)}`, { waitUntil: 'networkidle' });
    const card = page.locator('.workouts-library-card').filter({ hasText: uniqueName }).first();
    await card.waitFor();
    const libraryMedia = await card.evaluate((node) => ({
        image: Boolean(node.querySelector('.workouts-library-media img')),
        videoCover: Boolean(node.querySelector('.workouts-library-media.is-video-thumbnail')),
        background: node.querySelector('.workouts-library-media')?.getAttribute('style') || '',
        accent: getComputedStyle(node).getPropertyValue('--exercise-accent').trim(),
        mark: node.querySelector('.workouts-library-video-fallback')?.textContent?.trim() || '',
    }));
    check('La biblioteca respeta YouTube y la identidad', !libraryMedia.image && libraryMedia.videoCover && libraryMedia.background.includes('i.ytimg.com/vi/dQw4w9WgXcQ') && libraryMedia.accent === accentColor && libraryMedia.mark === visualMark, `${libraryMedia.background} · ${libraryMedia.accent} · ${libraryMedia.mark}`);
    await Promise.all([
        page.waitForURL((url) => Number(url.searchParams.get('exercise_id')) === created.id),
        card.locator('a[href*="exercise_id"]').first().click(),
    ]);
    await page.waitForLoadState('networkidle');
    const adminGuideViewer = page.locator('.workouts-guide-media [data-workout-media-viewer]');
    check('La guía pública conserva los tres pasos estructurados', await page.locator('.workouts-guide-steps li p').evaluateAll((items) => items.map((item) => item.textContent.trim()).join('|')) === 'Set the shoulder blades|Press with control|Lower smoothly');
    const adminGuideGallery = adminGuideViewer.locator('[data-workout-media-gallery]');
    check('La guía pública conserva la galería admin', await adminGuideGallery.locator('[data-workout-gallery-slide]').count() === 2 && await adminGuideGallery.locator('[data-workout-gallery-viewer-thumb]').count() === 2);
    await adminGuideViewer.locator('[data-workout-media-tab="photo"]').click();
    check('La guía pública muestra el título de la portada admin', (await adminGuideGallery.locator('[data-workout-gallery-caption-slide]:visible').textContent()).trim() === 'Resultado');
    await adminGuideGallery.locator('[data-workout-gallery-viewer-move="next"]').click();
    check('La galería admin se puede recorrer', (await adminGuideGallery.locator('[data-workout-gallery-viewer-status]').textContent()).trim() === '2 / 2');
    check('El título admin cambia con la foto activa', (await adminGuideGallery.locator('[data-workout-gallery-caption-slide]:visible').textContent()).trim() === 'Preparación');
    check('La guía aplica el recorte de la foto secundaria', await adminGuideGallery.locator('[data-workout-gallery-slide]').nth(1).evaluate((image) => getComputedStyle(image).objectPosition === '34% 76%'));
    check('La guía difiere el iframe externo hasta reproducir', await adminGuideViewer.locator('iframe').count() === 0);
    await adminGuideViewer.locator('[data-workout-media-tab="video"]').click();
    await adminGuideViewer.locator('[data-workout-video-load]').click();
    await page.waitForSelector('.workouts-guide-media iframe[src*="youtube-nocookie.com"]');
    const guideMedia = await page.evaluate(() => {
        const media = document.querySelector('.workouts-guide-media');
        const frame = media?.querySelector('iframe');
        return {
            image: Boolean(media?.querySelector('img')),
            imagePosition: media?.querySelector('img') ? getComputedStyle(media.querySelector('img')).objectPosition : '',
            frame: frame?.getAttribute('src') || '',
            accent: document.querySelector('.workouts-exercise-hero') ? getComputedStyle(document.querySelector('.workouts-exercise-hero')).getPropertyValue('--exercise-accent').trim() : '',
            mark: document.querySelector('.workouts-exercise-hero-icon')?.textContent?.trim() || '',
            overflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });
    check('La guía integra multimedia e identidad visual', guideMedia.image && guideMedia.imagePosition === '50% 18%' && guideMedia.frame.includes('youtube-nocookie.com/embed/') && guideMedia.accent === accentColor && guideMedia.mark === visualMark, `${guideMedia.imagePosition} · ${guideMedia.accent} · ${guideMedia.mark} · ${guideMedia.frame}`);
    check('La guía multimedia no desborda en desktop', guideMedia.overflow <= 1, `${guideMedia.overflow}px`);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/?page=admin&section=training`, { waitUntil: 'networkidle' });
    const mobile = await page.evaluate(() => {
        const section = document.querySelector('[data-spa-section="training"]:not([hidden])');
        const rows = [...(section?.querySelectorAll('.admin-rank-tier-row') || [])];
        return {
            viewportOverflow: document.documentElement.scrollWidth - window.innerWidth,
            sectionOverflow: section ? section.scrollWidth - section.clientWidth : 999,
            minTierWidth: rows.length ? Math.min(...rows.map((row) => row.getBoundingClientRect().width)) : 0,
            maxTierWidth: rows.length ? Math.max(...rows.map((row) => row.getBoundingClientRect().width)) : 999,
            createVisible: Boolean(section?.querySelector('a[href*="exercise_id=new"]')),
        };
    });
    check('Admin Training móvil conserva una sola columna', mobile.viewportOverflow <= 1 && mobile.sectionOverflow <= 1 && mobile.createVisible, `${mobile.viewportOverflow}px viewport · ${mobile.sectionOverflow}px section`);
    check('Tiers móviles usan el ancho disponible', mobile.minTierWidth > 250 && mobile.maxTierWidth < 390, `${Math.round(mobile.minTierWidth)}–${Math.round(mobile.maxTierWidth)}px`);
    await page.locator('[data-spa-section="training"]:not([hidden])').screenshot({ path: path.join(screenshotDir, 'ui-admin-training-mobile.png') });

    await page.goto(`${BASE}/?page=admin&section=training&exercise_id=${created.id}`, { waitUntil: 'networkidle' });
    const mobileEditor = page.locator('.admin-detail-view:not([hidden]) form.admin-training-exercise-form');
    const mobileLivePreview = mobileEditor.locator('[data-workout-exercise-live-preview]');
    check('Vista previa admin móvil empieza compacta', !await mobileLivePreview.evaluate((details) => details.open) && await mobileLivePreview.evaluate((details) => details.getBoundingClientRect().height <= 68));
    await mobileLivePreview.locator('summary').click();
    await mobileLivePreview.locator('[data-workout-preview-mode="session"]').click();
    check('Vista previa admin móvil conserva sesión y objetivos táctiles', await mobileLivePreview.locator('[data-workout-preview-panel="session"]').isVisible() && (await mobileLivePreview.locator('[data-workout-preview-target]').last().textContent()).trim() === '5×6' && await mobileLivePreview.locator('summary, [role="tab"]').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 43.5)));
    check('Vista previa admin móvil no desborda', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await mobileLivePreview.screenshot({ path: path.join(screenshotDir, 'ui-admin-training-live-preview-mobile.png') });
    const mobileDefaults = mobileEditor.locator('[data-workout-training-defaults]');
    await mobileDefaults.locator('summary').click();
    check('Objetivo inicial admin usa una columna tactil', await mobileDefaults.locator('.workouts-custom-defaults-grid').evaluate((grid) => getComputedStyle(grid).gridTemplateColumns.split(' ').length === 1) && await mobileDefaults.locator('summary, input:not([type="hidden"]), select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }).every((node) => node.getBoundingClientRect().height >= 43.5)));
    const mobileGuideBuilder = mobileEditor.locator('[data-workout-guide-builder]');
    const mobileStepsBuilder = mobileGuideBuilder.locator('[data-guide-key="steps"]');
    await mobileStepsBuilder.locator('summary').click();
    check('Guía admin móvil usa filas táctiles sin overflow', await mobileStepsBuilder.locator('summary, [data-guide-item-input], button').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }).every((node) => node.getBoundingClientRect().height >= 43.5)) && await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await mobileStepsBuilder.screenshot({ path: path.join(screenshotDir, 'ui-admin-training-guide-builder-mobile.png') });
    const mobileGallery = mobileEditor.locator('[data-workout-gallery-editor]');
    await mobileGallery.locator('summary').click();
    check('Galería admin móvil restaura fotos y controles táctiles', await mobileGallery.locator('[data-workout-gallery-item]').count() === 2 && await mobileGallery.locator('[data-workout-gallery-focus], [data-workout-gallery-move], [data-workout-gallery-remove]').evaluateAll((nodes) => nodes.every((node) => {
        const rect = node.getBoundingClientRect();
        return rect.width >= 43.5 && rect.height >= 43.5;
    })));
    check('Títulos de galería admin son editables y táctiles en móvil', await mobileGallery.locator('[data-workout-gallery-caption]').evaluateAll((inputs) => inputs.every((input) => input.value.length > 0 && input.getBoundingClientRect().height >= 43.5)));
    await mobileGallery.locator('[data-workout-gallery-item]').nth(1).locator('[data-workout-gallery-focus]').click();
    check('Punto focal admin restaura coordenadas exactas', await mobileGallery.locator('[data-workout-gallery-focal-x]').inputValue() === '34' && await mobileGallery.locator('[data-workout-gallery-focal-y]').inputValue() === '76' && await mobileGallery.locator('[data-workout-gallery-focal-editor]').isVisible());
    check('Galería admin móvil no desborda', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await mobileGallery.screenshot({ path: path.join(screenshotDir, 'ui-admin-training-gallery-mobile.png') });
    check('Editor admin conserva cinco encuadres táctiles en móvil', await mobileEditor.locator('.workouts-image-focus-presets label > span').count() === 5 && await mobileEditor.locator('.workouts-image-focus-presets label > span').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 44)));
    await mobileEditor.locator('.admin-training-color-details summary').click();
    check('Selector de color admin es táctil en móvil', await mobileEditor.locator('[data-workout-color-preset]').count() === 8 && await mobileEditor.locator('.workouts-routine-color-options label > span').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 44)));
    check('Selector de símbolo admin es táctil en móvil', await mobileEditor.locator('[data-workout-mark-preset]').count() === 8 && await mobileEditor.locator('.workouts-exercise-mark-options input + span').evaluateAll((nodes) => nodes.every((node) => node.getBoundingClientRect().height >= 44)));
    check('Editor admin multimedia no desborda en móvil', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    const deleteForm = page.locator('.admin-detail-view:not([hidden]) form.admin-danger-zone');
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') === 'admin' && url.searchParams.get('section') === 'training' && !url.searchParams.has('exercise_id')),
        deleteForm.locator('button[type="submit"]').click(),
    ]);
    await page.waitForLoadState('networkidle');
    const hiddenRow = page.locator('.admin-training-exercise-row').filter({ hasText: uniqueName }).first();
    check('Eliminar conserva historia y oculta el ejercicio', await hiddenRow.count() === 1 && (await hiddenRow.textContent()).includes('Hidden'));

    check('Sin errores JavaScript', pageErrors.length === 0, pageErrors.join(' | '));
    check('Sin respuestas 5xx', failedResponses.length === 0, failedResponses.join(' | '));
    await browser.close();

    const failed = checks.filter((item) => !item.pass);
    if (failed.length) process.exit(1);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
