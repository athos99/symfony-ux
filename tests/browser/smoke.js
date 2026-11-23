import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import net from 'node:net';
import AxeBuilder from '@axe-core/playwright';
import { chromium } from 'playwright';

async function findAvailablePort() {
    const server = net.createServer();

    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });

    const { port } = server.address();
    await new Promise((resolve, reject) =>
        server.close((error) => (error ? reject(error) : resolve())),
    );

    return port;
}

const port = await findAvailablePort();
const baseUrl = process.env.SMOKE_BASE_URL ?? `http://127.0.0.1:${port}/`;
const server = process.env.SMOKE_BASE_URL
    ? null
    : spawn(
          'php',
          [
              '-S',
              `127.0.0.1:${port}`,
              '-t',
              'public',
              'tests/browser/router.php',
          ],
          {
              env: { ...process.env, APP_ENV: 'test', APP_DEBUG: '0' },
              stdio: 'ignore',
          },
      );
let browser;

async function waitForServer() {
    for (let attempt = 0; attempt < 40; attempt += 1) {
        try {
            await fetch(baseUrl);

            return;
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 250));
        }
    }

    throw new Error(`Le serveur Symfony n'a pas démarré : ${baseUrl}`);
}

function collectPageErrors(page) {
    const errors = [];

    page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(`console.error: ${message.text()}`);
        }
    });
    page.on('requestfailed', (request) => {
        errors.push(
            `requestfailed: ${request.method()} ${request.url()} (${request.failure()?.errorText ?? 'n/a'})`,
        );
    });

    return errors;
}

try {
    if (server) {
        await waitForServer();
    }

    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();
    const pageErrors = collectPageErrors(page);

    const response = await page.goto(baseUrl, { waitUntil: 'networkidle' });

    assert.equal(
        response?.status(),
        200,
        `La page d'accueil doit répondre 200 (${baseUrl}).`,
    );
    await assert.doesNotReject(() =>
        page.getByRole('link', { name: 'Test Stimulus' }).waitFor(),
    );
    await assert.doesNotReject(() =>
        page.getByRole('link', { name: 'Test Tailwind & DaisyUI' }).waitFor(),
    );
    assert.match(
        await page.title(),
        /symfony base/,
        'Le titre de la page doit contenir « symfony base ».',
    );
    assert.equal(
        await page.locator('link[rel="icon"]').count(),
        1,
        'La page doit déclarer un favicon (link[rel="icon"]).',
    );

    for (const path of ['test_stimulus', 'test_tailwind']) {
        const routeResponse = await page.goto(new URL(path, baseUrl).href, {
            waitUntil: 'networkidle',
        });

        assert.equal(
            routeResponse?.status(),
            200,
            `La route /${path} doit répondre 200.`,
        );
        assert.equal(
            await page.locator('body').isVisible(),
            true,
            `Le contenu de /${path} doit être visible.`,
        );
        assert.match(
            await page.title(),
            /symfony base/,
            `Le titre de /${path} doit contenir « symfony base ».`,
        );
    }

    // Comportement Stimulus : la sortie doit se remplir progressivement.
    await page.goto(new URL('test_stimulus', baseUrl).href, {
        waitUntil: 'networkidle',
    });
    const output = page.locator('[data-stimulus-target="output"]');
    await page.waitForFunction(
        () =>
            (document.querySelector('[data-stimulus-target="output"]')
                ?.textContent?.length ?? 0) > 0,
        { timeout: 5000 },
    );
    assert.ok(
        (await output.textContent())?.length > 0,
        'Le contrôleur Stimulus doit animer la sortie (text non vide).',
    );

    // Navigation Turbo : le clic sur un lien doit changer l'URL sans rechargement complet.
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await Promise.all([
        page.waitForURL('**/test_stimulus', { timeout: 5000 }),
        page.getByRole('link', { name: 'Test Stimulus' }).click(),
    ]);
    assert.ok(
        page.url().endsWith('/test_stimulus'),
        `La navigation Turbo doit atteindre /test_stimulus (URL courante : ${page.url()}).`,
    );

    // Accessibilité : aucune violation axe bloquante (critical/serious).
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    const axeResults = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const blockingViolations = axeResults.violations.filter((violation) =>
        ['critical', 'serious'].includes(violation.impact),
    );
    assert.deepEqual(
        blockingViolations.map((violation) => ({
            id: violation.id,
            impact: violation.impact,
            nodes: violation.nodes.length,
        })),
        [],
        `Violations d'accessibilité bloquantes : ${blockingViolations
            .map((violation) => violation.id)
            .join(', ')}`,
    );

    assert.deepEqual(
        pageErrors,
        [],
        `Erreurs JavaScript/réseau dans le navigateur : ${pageErrors.join('; ')}`,
    );
    console.log(`Smoke test Playwright réussi : ${baseUrl}`);
} finally {
    await browser?.close();
    server?.kill('SIGTERM');
}
