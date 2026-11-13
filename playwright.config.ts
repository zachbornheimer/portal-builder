import { defineConfig, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/** LocalWP cold pages often take 30–60s; suite steps need headroom. */
const TEST_TIMEOUT_MS = 180_000;
const ACTION_TIMEOUT_MS = 60_000;
const NAVIGATION_TIMEOUT_MS = 90_000;

const envPath = path.join('tests/config/env.local.json');
const examplePath = path.join('tests/config/env.example.json');
const cfg = JSON.parse(
	fs.readFileSync(fs.existsSync(envPath) ? envPath : examplePath, 'utf8')
);

export default defineConfig({
	testDir: './e2e',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
	use: {
		baseURL: cfg.baseUrl,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		actionTimeout: ACTION_TIMEOUT_MS,
		navigationTimeout: NAVIGATION_TIMEOUT_MS,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
	timeout: TEST_TIMEOUT_MS,
});
