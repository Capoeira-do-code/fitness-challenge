/**
 * Focused browser regression for the live Home feed and Nutrition actions.
 * Run only against a disposable fixture database.
 *
 *   node bin/qa_feed_nutrition_ui.js --base http://127.0.0.1:8113
 */

const path = require('path');
const { chromium } = require(path.join(__dirname, '..', '.tools', 'qa-node', 'node_modules', 'playwright-core'));

const args = process.argv.slice(2);
const arg = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
};
const BASE = arg('base', 'http://127.0.0.1:8113').replace(/\/$/, '');
const USERNAME = arg('username', 'roberto');
const PASSWORD = arg('password', 'Verify123!');

const failures = [];
const check = (name, condition, detail = '') => {
    const passed = Boolean(condition);
    console.log(`${passed ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
    if (!passed) failures.push(`${name}${detail ? `: ${detail}` : ''}`);
};
const noOverflow = (page) => page.evaluate(() =>
    Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) <= innerWidth + 1);

const login = async (page) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', USERNAME);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);
    check('Inicio de sesión QA', !page.url().includes('page=login'), page.url());
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const jsErrors = [];
    const serverErrors = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            jsErrors.push(message.text());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });

    const marker = `QA feed meal ${Date.now()}`;
    const commentMarker = `QA live comment ${Date.now()}`;
    try {
        await login(page);

        await page.goto(`${BASE}/?page=dashboard&home=feed&feed=friends`, { waitUntil: 'networkidle' });
        const post = page.locator('.home-feed-post').first();
        check('El fixture ofrece un post para probar la conversación', await post.count() === 1);
        const feedUrl = page.url();
        const commentToggle = post.locator('[data-feed-comment-toggle]');
        await commentToggle.click();
        const composer = post.locator('[data-feed-comments] > .social-comment-composer');
        check('Abrir comentarios no enfoca el input', await page.evaluate(() => {
            const active = document.activeElement;
            return !(active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement);
        }));
        await composer.locator('input[name="comment"]').fill(commentMarker);
        await composer.locator('button[type="submit"]').click();
        const createdComment = post.locator('[data-social-comment]').filter({ hasText: commentMarker });
        await createdComment.waitFor({ state: 'visible' });
        check('Crear comentario actualiza el feed sin navegar', page.url() === feedUrl);

        let commentLike = createdComment.locator('[data-social-comment-like] button');
        await commentLike.click();
        await page.waitForFunction((text) => {
            const item = [...document.querySelectorAll('[data-social-comment]')]
                .find((node) => node.textContent?.includes(text));
            const button = item?.querySelector('[data-social-comment-like] button');
            return button?.getAttribute('aria-pressed') === 'true' && button.querySelector('span')?.textContent === '1';
        }, commentMarker);
        check('Like de comentario se persiste en vivo', page.url() === feedUrl);
        commentLike = post.locator('[data-social-comment]').filter({ hasText: commentMarker })
            .locator('[data-social-comment-like] button');
        await commentLike.click();
        await page.waitForFunction((text) => {
            const item = [...document.querySelectorAll('[data-social-comment]')]
                .find((node) => node.textContent?.includes(text));
            const button = item?.querySelector('[data-social-comment-like] button');
            return button?.getAttribute('aria-pressed') === 'false' && button.querySelector('span')?.textContent === '0';
        }, commentMarker);
        check('Unlike de comentario se refleja sin recarga', page.url() === feedUrl);

        page.once('dialog', (dialog) => dialog.accept());
        await post.locator('[data-social-comment]').filter({ hasText: commentMarker })
            .locator('form[data-social-comment-delete] button[type="submit"]').click();
        await createdComment.waitFor({ state: 'detached' });
        check('El autor puede borrar su comentario sin navegar', page.url() === feedUrl);

        const hasDock = await page.locator('.workouts-active-session-dock').count() > 0;
        if (hasDock) {
            check('La sesión activa tiene prioridad sobre el aviso PWA',
                await page.locator('.workouts-active-session-dock').isVisible()
                    && !await page.locator('.pwa-install-nudge').isVisible());
        }

        await page.goto(`${BASE}/?page=nutrition`, { waitUntil: 'networkidle' });
        await page.locator('[data-nutrition-open]').click();
        const createDialog = page.locator('[data-nutrition-dialog]');
        await createDialog.waitFor({ state: 'visible' });
        await createDialog.locator('input[name="calories"]').fill('647');
        await createDialog.locator('input[name="protein_g"]').fill('31,5');
        await createDialog.locator('input[name="carbs_g"]').fill('58,5');
        await createDialog.locator('input[name="fat_g"]').fill('18');
        await createDialog.locator('textarea[name="notes"]').fill(marker);
        await Promise.all([
            page.waitForLoadState('networkidle'),
            createDialog.locator('button[type="submit"]').click(),
        ]);

        let mealRow = page.locator('[data-nutrition-entry-row]').filter({ hasText: marker });
        await mealRow.waitFor({ state: 'visible' });
        const rowHeight = await mealRow.evaluate((node) => Math.round(node.getBoundingClientRect().height));
        check('La comida creada aparece en un historial compacto', rowHeight <= 130, `${rowHeight}px`);
        check('Nutrition no desborda en móvil', await noOverflow(page));

        const nutritionUrl = page.url();
        await mealRow.locator('.nutrition-entry-actions').click();
        check('Los tres puntos abren acciones sin navegar',
            await mealRow.locator('.nutrition-entry-menu').getAttribute('open') !== null && page.url() === nutritionUrl);
        await mealRow.getByRole('button', { name: /Ver detalles|View details|Vedi dettagli/i }).click();
        const detailsModal = page.locator('.nutrition-entry-detail-card').filter({ hasText: marker });
        await detailsModal.waitFor({ state: 'visible' });
        check('Ver detalles abre un modal in-page', page.url() === nutritionUrl);
        await detailsModal.locator('[data-app-modal-close]').last().evaluate((button) => button.click());
        await page.waitForTimeout(350);
        check('El detalle se cierra sin navegar',
            await detailsModal.locator('xpath=ancestor::*[contains(@class,"app-modal")][1]').getAttribute('hidden') !== null
                && page.url() === nutritionUrl);

        mealRow = page.locator('[data-nutrition-entry-row]').filter({ hasText: marker });
        await mealRow.locator('.nutrition-entry-actions').click();
        page.once('dialog', (dialog) => dialog.accept());
        await mealRow.locator('form[data-nutrition-row-action="archive"] button[type="submit"]').click();
        await mealRow.waitFor({ state: 'detached' });
        check('Archivar actualiza la lista sin recarga', page.url() === nutritionUrl);

        await Promise.all([
            page.waitForLoadState('networkidle'),
            page.locator('.nutrition-history-tabs a').filter({ hasText: /Archivadas|Archived|Archiviate/i }).click(),
        ]);
        mealRow = page.locator('[data-nutrition-entry-row]').filter({ hasText: marker });
        await mealRow.waitFor({ state: 'visible' });
        await mealRow.locator('.nutrition-entry-actions').click();
        page.once('dialog', (dialog) => dialog.accept());
        await mealRow.locator('form[data-nutrition-row-action="unarchive"] button[type="submit"]').click();
        await mealRow.waitFor({ state: 'detached' });
        check('Desarchivar actualiza la pestaña sin recarga', page.url().includes('nutrition_history=archived'));

        await page.goto(`${BASE}/?page=dashboard&home=feed&feed=friends`, { waitUntil: 'networkidle' });
        let mealPost = page.locator('.home-feed-post').filter({ hasText: marker });
        await mealPost.waitFor({ state: 'visible' });
        const detailHref = await mealPost.locator('.home-feed-post-title').getAttribute('href');
        check('Meal Update enlaza al detalle real de la comida',
            Boolean(detailHref?.includes('page=meal') && detailHref.includes('meal_id=') && !detailHref.includes('page=photo')),
        detailHref || 'sin enlace');
        check('El propietario puede eliminar el Meal Update',
            await mealPost.locator('.home-feed-meal-delete-form').count() === 1);

        await mealPost.locator('.home-feed-post-title').click();
        await page.waitForURL((url) => url.searchParams.get('page') === 'meal');
        check('El detalle conserva los datos reales de la comida',
            await page.locator('.nutrition-public-meal').filter({ hasText: marker }).count() === 1);
        await page.locator('.nutrition-public-meal-back').click();
        await page.waitForURL((url) => url.searchParams.get('page') === 'dashboard');
        check('Volver desde la comida recupera el feed de origen', page.url().includes('#feed-meal-'));

        mealPost = page.locator('.home-feed-post').filter({ hasText: marker });
        page.once('dialog', (dialog) => dialog.accept());
        await Promise.all([
            page.waitForLoadState('networkidle'),
            mealPost.locator('.home-feed-meal-delete-form button[type="submit"]').click(),
        ]);
        check('Borrar la comida elimina su publicación huérfana',
            await page.locator('.home-feed-post').filter({ hasText: marker }).count() === 0);

        check('Sin errores JavaScript', jsErrors.length === 0, jsErrors.join(' | '));
        check('Sin respuestas HTTP 5xx', serverErrors.length === 0, serverErrors.join(' | '));
    } catch (error) {
        check('Flujo Feed/Nutrition completado', false, error.stack || error.message);
    } finally {
        await browser.close();
    }

    if (failures.length > 0) {
        console.log(`\n${failures.length} fallo(s):`);
        failures.forEach((failure) => console.log(`- ${failure}`));
        process.exitCode = 1;
    }
})();
