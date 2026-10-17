#!/usr/bin/env node
// Maintainer-only: re-makes the screenshots in docs/images/ from the demo seed
// (spec.md §10 *README and docs*, Phase 52). Not needed to install or run
// Logbook. Run before each release:
//
//   npm ci && npx playwright install chromium
//   npm run screenshots              # builds the image first
//   npm run screenshots -- --no-build
//
// It builds the production image, starts it on SQLite with DEMO_MODE so the
// demo seed fills an empty volume, restarts it with DEMO_MODE off (so no demo
// banner shows), stores a kept Ask Logbook conversation with
// bin/tools/screenshot-ask.php (no model is called), signs in as `demo` and
// captures each page at a desktop and a phone size, light and dark. The
// container and its volume are removed afterwards. It fails if any image is
// over 300 KB. Needs Docker and Node.

import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'docs', 'images');
const image = 'logbook:screenshots';
const name = 'logbook-screenshots';
const volume = 'logbook-screenshots-data';
const port = process.env.SCREENSHOTS_PORT ?? '8097';
const base = `http://localhost:${port}`;
const password = randomBytes(12).toString('hex');
const limit = 300 * 1024;

const viewports = {
    desktop: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 },
    phone: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};
const themes = ['light', 'dark'];

// Each page: its path (vehicle pages use the Golf, the sample's longest history),
// the viewports it is taken at, and optionally one element to capture instead of the viewport,
// or one to scroll to the top first.
const pages = [
    { name: 'dashboard', path: () => '/', on: ['desktop', 'phone'] },
    { name: 'vehicle', path: (v) => `/vehicles/${v}`, on: ['desktop', 'phone'] },
    { name: 'fuel-log', path: (v) => `/vehicles/${v}/fuel`, on: ['desktop'] },
    { name: 'add-fill-up', path: (v) => `/vehicles/${v}/fuel/new`, on: ['phone'] },
    { name: 'coming-up', path: () => '/upcoming', on: ['desktop', 'phone'] },
    { name: 'cost-report', path: () => '/reports', on: ['desktop'], scroll: '.report-section' },
    { name: 'needs-attention', path: (v) => `/vehicles/${v}`, on: ['desktop'], element: '.card--attention' },
    { name: 'ask', path: (_v, ask) => ask, on: ['desktop', 'phone'] },
];

function docker(...args) {
    return execFileSync('docker', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, MSYS_NO_PATHCONV: '1' } }).trim();
}

function quietly(...args) {
    try {
        docker(...args);
    } catch {
        // Already gone.
    }
}

async function healthy() {
    for (let i = 0; i < 90; i++) {
        try {
            const response = await fetch(`${base}/health`);
            if (response.ok) {
                return;
            }
        } catch {
            // Not up yet.
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error(`Logbook did not answer on ${base}/health`);
}

async function start(demo) {
    quietly('rm', '-f', name);
    docker('run', '-d', '--name', name, '-p', `${port}:80`, '-v', `${volume}:/data`,
        '-e', `APP_URL=${base}`, '-e', 'AI_ENABLED=true',
        ...(demo ? ['-e', 'DEMO_MODE=true', '-e', `DEMO_PASSWORD=${password}`] : []),
        image);
    await healthy();
}

async function main() {
    if (!process.argv.includes('--no-build')) {
        execFileSync('docker', ['build', '-t', image, '.'], { cwd: root, stdio: 'inherit' });
    }
    quietly('rm', '-f', name);
    quietly('volume', 'rm', volume);

    await start(true);
    await start(false);
    docker('cp', join(root, 'bin', 'tools', 'screenshot-ask.php'), `${name}:/tmp/screenshot-ask.php`);
    const ask = docker('exec', '-u', 'www-data', name, 'php', '/tmp/screenshot-ask.php', '/var/www/html');

    mkdirSync(out, { recursive: true });
    const browser = await chromium.launch();
    const written = [];
    try {
        for (const [viewport, options] of Object.entries(viewports)) {
            for (const theme of themes) {
                const context = await browser.newContext({ ...options, colorScheme: theme, locale: 'en-GB', timezoneId: 'Europe/London' });
                const page = await context.newPage();
                await page.goto(`${base}/login`);
                await page.fill('#f-username', 'demo');
                await page.fill('#f-password', password);
                await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
                await page.goto(`${base}/garage`);
                const golf = await page.locator('a[href*="/vehicles/"]', { hasText: 'Golf' }).first().getAttribute('href');
                const vehicle = (golf ?? '/vehicles/1').match(/\/vehicles\/(\d+)/)?.[1] ?? '1';

                for (const shot of pages.filter((p) => p.on.includes(viewport))) {
                    await page.goto(base + shot.path(vehicle, ask), { waitUntil: 'networkidle' });
                    if (shot.scroll) {
                        await page.locator(shot.scroll).first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
                    }
                    // Charts animate in; let them settle.
                    await page.waitForTimeout(800);
                    const file = join(out, `${shot.name}-${viewport}-${theme}.png`);
                    if (shot.element) {
                        await page.locator(shot.element).first().screenshot({ path: file, animations: 'disabled' });
                    } else {
                        await page.screenshot({ path: file, animations: 'disabled' });
                    }
                    written.push(file);
                }
                await context.close();
            }
        }
    } finally {
        await browser.close();
        quietly('rm', '-f', name);
        quietly('volume', 'rm', volume);
    }

    const large = written.filter((file) => statSync(file).size > limit);
    for (const file of written) {
        console.log(`${(statSync(file).size / 1024).toFixed(0).padStart(4)} KB  ${file.slice(root.length + 1)}`);
    }
    if (large.length > 0) {
        console.error(`\n${large.length} image(s) over 300 KB.`);
        process.exit(1);
    }
}

main().catch((error) => {
    console.error(error);
    quietly('rm', '-f', name);
    quietly('volume', 'rm', volume);
    process.exit(1);
});
