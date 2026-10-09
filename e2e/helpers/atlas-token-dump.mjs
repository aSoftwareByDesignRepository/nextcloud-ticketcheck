// @ts-check
/**
 * ATLAS ds_chrome — one-shot token dump (ticketcheck).
 * Logs in as the e2e agent, opens the tickets list, applies a theme the way
 * NC theming emits it on <body>, and prints the resolved --color-* tokens so
 * we can see which semantic vars exist on this NC build (35.x).
 *
 *   node e2e/helpers/atlas-token-dump.mjs
 */
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const HERE = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(HERE, '../..')
const ENV_PATH = resolve(APP_ROOT, 'e2e/.env')
if (existsSync(ENV_PATH)) {
	for (const line of readFileSync(ENV_PATH, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		const k = t.slice(0, eq).trim()
		let v = t.slice(eq + 1).trim()
		if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1)
		if (process.env[k] === undefined) process.env[k] = v
	}
}

const BASE = (process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '')
const USER = process.env.E2E_USER
const PASS = process.env.E2E_PASSWORD || process.env.E2E_PASS

async function applyTheme(page, themeId) {
	await page.evaluate((id) => {
		const body = document.body
		;['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c))
		;['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a))
		if (id === 'light') {
			body.classList.add('theme--light')
			body.setAttribute('data-theme-light', 'true')
			body.setAttribute('data-themes', 'default')
		} else if (id === 'dark') {
			body.classList.add('theme--dark')
			body.setAttribute('data-theme-dark', 'true')
			body.setAttribute('data-themes', 'dark')
		} else if (id === 'light-highcontrast') {
			body.classList.add('theme--light', 'theme--light-highcontrast')
			body.setAttribute('data-theme-light-highcontrast', 'true')
			body.setAttribute('data-themes', 'light-highcontrast')
		} else {
			body.classList.add('theme--dark', 'theme--dark-highcontrast')
			body.setAttribute('data-theme-dark-highcontrast', 'true')
			body.setAttribute('data-themes', 'dark-highcontrast')
		}
		document.documentElement.style.colorScheme = id.includes('dark') ? 'dark' : 'light'
	}, themeId)
	await page.waitForTimeout(200)
}

async function main() {
	if (!USER || !PASS) throw new Error('E2E_USER/E2E_PASSWORD missing (e2e/.env)')
	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	try {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await page.locator('input#user, input[name="user"]').first().fill(USER)
		await page.locator('input#password, input[name="password"]').first().fill(PASS)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30_000 })

		const names = [
			'--color-main-background', '--color-main-text', '--color-text-maxcontrast',
			'--color-error', '--color-error-text', '--color-element-error', '--color-error-element-text',
			'--color-warning', '--color-warning-text', '--color-element-warning', '--color-warning-element-text',
			'--color-success', '--color-success-text', '--color-element-success', '--color-success-hover', '--color-success-light',
			'--color-info', '--color-info-text',
			'--color-border', '--color-border-dark', '--color-border-maxcontrast',
			'--color-primary', '--color-primary-text', '--color-primary-element', '--color-primary-element-text', '--color-primary-element-hover',
			'--color-background-hover', '--color-background-dark',
			'--tc-error-text', '--tc-error-element', '--tc-warning-text', '--tc-warning-element',
			'--tc-success-text', '--tc-success-element', '--tc-form-border', '--tc-danger-ink',
		]
		for (const theme of ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']) {
			await page.goto('/apps/ticketcheck/tickets', { waitUntil: 'domcontentloaded' })
			await applyTheme(page, theme)
			await page.waitForSelector('#app-content', { timeout: 30_000 })
			const out = await page.evaluate((ns) => {
				const cs = getComputedStyle(document.body)
				const o = {}
				for (const n of ns) o[n] = cs.getPropertyValue(n).trim() || null
				return o
			}, names)
			console.log(`\n=== ${theme} ===`)
			for (const [k, v] of Object.entries(out)) console.log(`${k}: ${v}`)
		}
	} finally {
		await context.close()
		await browser.close()
	}
}

main().catch((e) => { console.error(e); process.exit(2) })
