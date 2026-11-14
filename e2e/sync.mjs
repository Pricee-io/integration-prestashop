// Back-office sync, driven in a real browser by e2e/run.sh: log in, save the
// module settings, select the test category and run the sync, which sends
// one request per batch from the page's JavaScript.
//
// Env: SHOP_URL, ADMIN_DIR, ADMIN_EMAIL, ADMIN_PASSWD, MODULE_PATH (module
// page path with its admin token), SYNC_CATEGORY, SYNC_PRODUCTS.
import { chromium } from 'playwright';

const env = (name) => {
    const value = process.env[name];
    if (!value) {
        throw new Error(`${name} is not set`);
    }

    return value;
};

const adminUrl = `${env('SHOP_URL')}/${env('ADMIN_DIR')}/`;
const category = env('SYNC_CATEGORY');
const products = env('SYNC_PRODUCTS');

const browser = await chromium.launch();
const page = await browser.newPage();
page.setDefaultTimeout(60000);

try {
    // Login form: same field names from PrestaShop 1.7.8 to 9
    await page.goto(adminUrl);
    await page.locator('input[name="email"]').fill(env('ADMIN_EMAIL'));
    await page.locator('input[name="passwd"], input[name="password"]').first().fill(env('ADMIN_PASSWD'));
    await Promise.all([
        page.waitForURL((url) => !/controller=AdminLogin|\/login/.test(url.href)),
        page.locator('input[name="passwd"], input[name="password"]').first().press('Enter'),
    ]);
    console.log('ok - logged in to the back office');

    await page.goto(adminUrl + env('MODULE_PATH'));
    await page.locator('#PRICEE_CLIENT_ID').waitFor();
    console.log('ok - module page opened');

    // Settings form, as a merchant fills it in
    await page.locator('#PRICEE_CLIENT_ID').fill('e2e-client');
    await page.locator('#PRICEE_API_KEY').fill('e2e-key');
    // The switch label can sit under the sticky back-office header
    await page.locator('#PRICEE_WEBHOOK_ENABLED_on').check({ force: true });
    await page.locator('#PRICEE_WEBHOOK_SECRET').fill('e2e-webhook-secret');
    await Promise.all([
        page.waitForLoadState('load'),
        page.locator('button[name="submitPriceeModule"]').click(),
    ]);
    await page.locator('.alert-success', { hasText: 'Réglages enregistrés' }).waitFor();
    console.log('ok - settings saved');

    // Sync of the test category: more products than one batch
    const checkbox = page.locator('.pricee-sync-categories:not([disabled]) .form-check', { hasText: `${category} (${products})` }).locator('input');
    await checkbox.check();
    await page.locator('#pricee-sync-form button[type="submit"]').click();
    const result = page.locator('#pricee-sync-result.alert-success');
    await result.waitFor({ timeout: 120000 });
    const message = (await result.innerText()).trim();
    if (message !== `${products} produits synchronisés`) {
        throw new Error(`unexpected sync result: ${message}`);
    }
    console.log(`ok - sync finished: ${message}`);
} catch (error) {
    await page.screenshot({ path: '/output/failure.png', fullPage: true }).catch(() => {});
    console.error(`FAIL: ${error.message} (page: ${page.url()}, screenshot: e2e/output/failure.png)`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
