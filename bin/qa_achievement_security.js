/**
 * HTTP-level authorization regression for achievement award deletion.
 * Run only against the disposable QA database: the final check deletes one fixture award.
 */
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
const check = (name, condition, detail = '') => {
    checks.push(Boolean(condition));
    console.log(`${condition ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

const login = async (page, username) => {
    await page.goto(`${BASE}/?page=login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('page') !== 'login'),
        page.click('button[type="submit"]'),
    ]);
};

const postDelete = async (page, target, csrf, awardId) => {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.evaluate(({ action, token, id }) => {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = action;
            for (const [name, value] of Object.entries({
                csrf_token: token,
                action: 'delete_achievement_award',
                award_id: String(id),
            })) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            }
            document.body.append(form);
            form.submit();
        }, { action: target, token: csrf, id: awardId }),
    ]);
};

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const adminContext = await browser.newContext();
    const userContext = await browser.newContext();
    const admin = await adminContext.newPage();
    const user = await userContext.newPage();
    try {
        await login(admin, 'roberto');
        await admin.goto(`${BASE}/?page=admin&section=achievements`, { waitUntil: 'networkidle' });
        const awardForm = admin.locator('form').filter({ has: admin.locator('input[name="action"][value="delete_achievement_award"]') }).first();
        const awardId = Number(await awardForm.locator('input[name="award_id"]').inputValue());
        const adminCsrf = await awardForm.locator('input[name="csrf_token"]').inputValue();
        check('la fixture contiene un premio auditable', awardId > 0, String(awardId));

        const awardExists = async () => {
            await admin.goto(`${BASE}/?page=admin&section=achievements`, { waitUntil: 'networkidle' });
            return admin.locator(`input[name="award_id"][value="${awardId}"]`).count();
        };

        await login(user, 'catalina');
        await user.goto(`${BASE}/?page=profile`, { waitUntil: 'networkidle' });
        const userCsrf = await user.locator('input[name="csrf_token"]').first().inputValue();

        await postDelete(user, `${BASE}/?page=profile`, userCsrf, awardId);
        check('una solicitud construida desde Perfil no borra el premio', await awardExists() === 1);

        await postDelete(user, `${BASE}/?page=achievements`, userCsrf, awardId);
        check('una solicitud construida desde Logros no borra el premio', await awardExists() === 1);

        await admin.goto(`${BASE}/?page=team`, { waitUntil: 'networkidle' });
        await postDelete(admin, `${BASE}/?page=team`, adminCsrf, awardId);
        check('ni el propietario de equipo puede borrar desde Equipo', await awardExists() === 1);

        await admin.goto(`${BASE}/?page=admin&section=achievements`, { waitUntil: 'networkidle' });
        await postDelete(admin, `${BASE}/?page=admin`, '', awardId);
        check('Administración rechaza el borrado sin CSRF', await awardExists() === 1);

        await admin.goto(`${BASE}/?page=admin&section=achievements`, { waitUntil: 'networkidle' });
        await postDelete(admin, `${BASE}/?page=admin`, adminCsrf, awardId);
        check('solo Administración con CSRF borra el premio', await awardExists() === 0);

        await admin.goto(`${BASE}/?page=admin&section=audit`, { waitUntil: 'networkidle' });
        const auditText = (await admin.locator('body').innerText()).toLowerCase();
        check('el borrado autorizado deja auditoría', auditText.includes('achievement_award_deleted') || auditText.includes('achievement award deleted'));
    } finally {
        await adminContext.close();
        await userContext.close();
        await browser.close();
    }

    const passed = checks.filter(Boolean).length;
    console.log(`\n${passed}/${checks.length} comprobaciones de seguridad correctas.`);
    if (passed !== checks.length) process.exitCode = 1;
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
