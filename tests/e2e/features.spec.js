import { test, expect } from '@playwright/test';

const user = process.env.WP_ADMIN_USER || '';
const password = process.env.WP_ADMIN_PASS || '';
const shots = process.env.E2E_SHOT_DIR || '/tmp/opencode/khse-shots';

test.describe('KH Speed feature tour', () => {
	test.skip(!user || !password, 'Set WP_ADMIN_USER and WP_ADMIN_PASS.');

	test.beforeEach(async ({ page }) => {
		await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
		await page.fill('#user_login', user);
		await page.fill('#user_pass', password);
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.click('#wp-submit'),
		]);
		await page.goto('/wp-admin/admin.php?page=kh-speed-engine-dashboard', { waitUntil: 'domcontentloaded' });
		await expect(page.locator('#khse-admin-root')).toBeVisible();
	});

	async function openView(page, area, view) {
		await page.locator('.khse-primary-nav__item', { hasText: area }).first().click();
		await page.getByRole('button', { name: view, exact: true }).first().click();
		await expect(page.locator('h1')).toContainText(view);
	}

	test('dashboard, caching, preload, rules and URL checker', async ({ page }) => {
		await expect(page.locator('h1')).toContainText('Dashboard');
		await page.screenshot({ path: `${shots}/dashboard.png`, fullPage: true });

		await openView(page, 'Caching', 'Cache');
		await expect(page.getByText('Enable page caching')).toBeVisible();
		await page.screenshot({ path: `${shots}/cache.png`, fullPage: true });

		await page.getByRole('button', { name: 'Preload', exact: true }).first().click();
		await expect(page.locator('h1')).toContainText('Preload');
		await expect(page.getByRole('button', { name: /run preload/i }).first()).toBeVisible();
		await expect(page.getByText('Background queue')).toBeVisible();
		await expect(page.getByRole('heading', { name: 'Scheduled preload' })).toBeVisible();
		await expect(page.getByRole('button', { name: /scheduled queue/i })).toBeVisible();
		await expect(page.getByRole('button', { name: /pause optional work|resume optional work/i })).toBeVisible();
		const queueStats = page.locator('.khse-queue-dashboard');
		await expect(queueStats.getByText('Pending', { exact: true })).toBeVisible();
		await expect(queueStats.getByText('Failed', { exact: true })).toBeVisible();
		await expect(queueStats.getByText('State', { exact: true })).toBeVisible();
		await page.screenshot({ path: `${shots}/preload.png`, fullPage: true });

		await page.getByRole('button', { name: 'Exclusion rules', exact: true }).first().click();
		await expect(page.locator('h1')).toContainText('Exclusion rules');
		await page.fill('#khse-url-checker', '/');
		await page.getByRole('button', { name: 'Check URL' }).click();
		await expect(page.getByText(/Will be cached|Bypassed/).first()).toBeVisible();
		await page.screenshot({ path: `${shots}/rules-url-check.png`, fullPage: true });
	});

	test('refresh reloads status without saving or overwriting edits', async ({ page }) => {
		await openView(page, 'Caching', 'Cache');

		const loggedInSwitch = page.getByRole('switch', { name: 'Cache pages for logged-in users' });
		const initiallyChecked = (await loggedInSwitch.getAttribute('aria-checked')) === 'true';
		const toggledValue = initiallyChecked ? 'false' : 'true';
		const restoredValue = initiallyChecked ? 'true' : 'false';

		await loggedInSwitch.click();
		await expect(page.getByText('Unsaved changes')).toBeVisible();

		await Promise.all([
			page.waitForResponse((response) => {
				const body = response.request().postData() || '';
				return response.url().includes('admin-ajax.php') && body.includes('khse_action_dashboard_status');
			}),
			page.getByTitle('Refresh status').click(),
		]);

		await expect(page.locator('.khse-toast')).toHaveCount(0);
		await expect(loggedInSwitch).toHaveAttribute('aria-checked', toggledValue);
		await expect(page.getByText('Unsaved changes')).toBeVisible();

		await page.getByRole('button', { name: 'Discard' }).click();
		await expect(loggedInSwitch).toHaveAttribute('aria-checked', restoredValue);
	});

	test('saving settings and cache actions work end to end', async ({ page }) => {
		await openView(page, 'Caching', 'Cache');

		const loggedInSwitch = page.getByRole('switch', { name: 'Cache pages for logged-in users' });
		const initiallyChecked = (await loggedInSwitch.getAttribute('aria-checked')) === 'true';
		const toggledValue = initiallyChecked ? 'false' : 'true';
		const restoredValue = initiallyChecked ? 'true' : 'false';

		await loggedInSwitch.click();
		await expect(page.getByRole('button', { name: 'Save changes' })).toBeVisible();
		await page.getByRole('button', { name: 'Save changes' }).click();
		await expect(page.locator('.khse-toast')).toContainText('Settings saved');

		await expect(loggedInSwitch).toHaveAttribute('aria-checked', toggledValue);
		await loggedInSwitch.click();
		await page.getByRole('button', { name: 'Save changes' }).click();
		await expect(page.locator('.khse-toast')).toContainText('Settings saved');
		await expect(loggedInSwitch).toHaveAttribute('aria-checked', restoredValue);

		await page.getByRole('button', { name: 'Preload', exact: true }).first().click();
		await page.getByRole('button', { name: 'Purge homepage' }).click();
		await expect(page.locator('.khse-toast')).toContainText('Homepage cache purged');

		await page.getByRole('button', { name: 'Run preload' }).click();
		await expect(page.locator('.khse-toast')).toContainText(/preload URLs queued|scheduled preload URLs|Scheduled preload runner started/i);
		await page.screenshot({ path: `${shots}/save-and-actions.png`, fullPage: true });
	});

	test('debug mode reveals preloaded pages on the preload view', async ({ page }) => {
		await openView(page, 'Tools', 'System & debug');

		const debugSwitch = page.getByRole('switch', { name: 'Debug mode' });

		if ((await debugSwitch.getAttribute('aria-checked')) !== 'true') {
			await debugSwitch.click();
			await expect(page.locator('.khse-toast')).toContainText('Debug mode enabled');
		}

		await openView(page, 'Caching', 'Preload');
		await expect(page.getByRole('heading', { name: 'Preloaded pages' })).toBeVisible();

		await openView(page, 'Tools', 'System & debug');
		await page.getByRole('switch', { name: 'Debug mode' }).click();
		await expect(page.locator('.khse-toast')).toContainText('Debug mode disabled');
	});

	test('optimization, statistics, tools, benchmark and about', async ({ page }) => {
		await openView(page, 'Optimization', 'Performance');
		await expect(page.getByText(/HTML|optimization/i).first()).toBeVisible();
		await page.screenshot({ path: `${shots}/performance.png`, fullPage: true });

		await page.getByRole('button', { name: 'Statistics', exact: true }).first().click();
		await expect(page.locator('h1')).toContainText('Statistics');
		await page.screenshot({ path: `${shots}/statistics.png`, fullPage: true });

		await openView(page, 'Tools', 'Expert mode');
		await expect(page.getByText(/Expert mode/i).first()).toBeVisible();
		await page.screenshot({ path: `${shots}/expert-mode.png`, fullPage: true });

		await page.getByRole('button', { name: 'Benchmark', exact: true }).first().click();
		await expect(page.locator('h1')).toContainText('Benchmark');
		await page.screenshot({ path: `${shots}/benchmark.png`, fullPage: true });

		await openView(page, 'Tools', 'System & debug');
		await expect(page.getByRole('heading', { name: 'Debug mode' })).toBeVisible();
		await expect(page.getByRole('heading', { name: 'Proxy & request topology' })).toBeVisible();
		await expect(page.getByRole('heading', { name: 'System configuration' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Download diagnostics' })).toBeVisible();
		const downloadPromise = page.waitForEvent('download');
		await page.getByRole('button', { name: 'Download diagnostics' }).click();
		const download = await downloadPromise;
		expect(download.suggestedFilename()).toMatch(/^khse-system-debug-/);
		await page.screenshot({ path: `${shots}/system-debug.png`, fullPage: true });

		await openView(page, 'About', 'About');
		await expect(page.getByText(/KH Speed Engine/i).first()).toBeVisible();
		await page.screenshot({ path: `${shots}/about.png`, fullPage: true });
	});
});
