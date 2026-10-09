// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (ticketcheck).
 *
 * Ported from mobilitycheck/tests/e2e/helpers/atlas-contrast-probe.mjs +
 * snackcheck/e2e/helpers/atlas-contrast-probe.mjs.
 *
 * Logs in as the e2e agent (plus a second guest context for the portal),
 * walks representative TicketCheck pages, and measures the COMPUTED WCAG 2.1
 * contrast of semantic chrome: status badges, primary/danger CTAs, control
 * borders (incl. composite shells + role="button" dropzones), survey-star
 * glyphs, invalid-field borders, and the upload-error ink — across
 * light / dark / light-highcontrast user themes.
 *
 * Theme is applied client-side on <body> the way Nextcloud theming emits it
 * (theme--*, data-theme-*, color-scheme) — identical computed pixels to a
 * server-pinned theme without mutating shared dev-instance user state.
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA; >=3:1 for large text/glyphs)
 *   borders    >= 3.0:1  (WCAG 1.4.11)
 *
 * Usage (from the app dir):
 *   node e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Requires E2E_USER + E2E_PASSWORD (and E2E_GUEST_* for the portal leg) in
 * e2e/.env and http://localhost:8081.
 */
import { writeFileSync, mkdirSync, existsSync, readFileSync } from 'node:fs'
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

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const USER = process.env.E2E_USER
const PASS = process.env.E2E_PASS || process.env.E2E_PASSWORD
const GUEST_USER = process.env.E2E_GUEST_USER
const GUEST_PASS = process.env.E2E_GUEST_PASSWORD
const THEME_IDS = ['light', 'dark', 'light-highcontrast']

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  // Chrome serialises color-mix() as color(srgb r g b / a) — floats 0..1.
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBg(el) {
  // Walk ancestors for the first non-transparent background.
  let n = el
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ancestorBg(el) {
  // Effective background BEHIND the element (skip its own fill) — a control
  // boundary is judged against the surface it sits on, not its own fill.
  let n = el.parentElement
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
window.__tcProbe = { hexToRgb, lum, effBg, ancestorBg, alphaOf, blend, ratio, borderRatio }
`

/** Apply a user theme client-side (theme--* / data-theme-* / color-scheme). */
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
	await page.waitForTimeout(150)
}

/** Snapshot the semantic tokens the theme emits — catches token-level regressions. */
async function tokenSnapshot(page) {
	return page.evaluate(() => {
		const cs = getComputedStyle(document.body)
		const names = [
			'--color-main-background', '--color-main-text', '--color-text-maxcontrast',
			'--color-error', '--color-error-text', '--color-element-error',
			'--color-warning', '--color-warning-text', '--color-element-warning',
			'--color-success', '--color-success-text', '--color-element-success',
			'--color-border', '--color-border-dark', '--color-border-maxcontrast',
			'--color-primary-element', '--color-primary-element-text',
			'--tc-form-border', '--tc-error-text', '--tc-error-element',
			'--tc-warning-text', '--tc-warning-element', '--tc-success-text', '--tc-success-element',
			'--tc-danger-ink', '--tc-muted', '--tc-border',
		]
		const out = {}
		for (const n of names) {
			let v = cs.getPropertyValue(n).trim() || null
			if (v && v.startsWith('var(')) v = `(unresolved) ${v}`
			out[n] = v
		}
		// Resolved ratios for the tokens that carry ink/fill duties.
		const probe = document.createElement('span')
		probe.style.display = 'none'
		document.body.appendChild(probe)
		const r = {}
		for (const [key, token, bgTok] of [
			['error_text_vs_bg', '--tc-error-text', '--color-main-background'],
			['warning_text_vs_bg', '--tc-warning-text', '--color-main-background'],
			['success_text_vs_bg', '--tc-success-text', '--color-main-background'],
			['form_border_vs_bg', '--tc-form-border', '--color-main-background'],
			['muted_vs_bg', '--tc-muted', '--color-main-background'],
		]) {
			probe.style.color = `var(${token})`
			probe.style.backgroundColor = `var(${bgTok})`
			const c = getComputedStyle(probe)
			r[key] = window.__tcProbe.ratio(c.color, c.backgroundColor)
		}
		probe.remove()
		return { values: out, ratios: r }
	})
}

/** Programmatic login (same flow as e2e/helpers/auth-guard.js). */
async function login(page, user, pass) {
	await page.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' })
	const userField = page.locator('input#user, input[name="user"]').first()
	await userField.waitFor({ state: 'visible', timeout: 30_000 })
	await userField.fill(user)
	await page.locator('input#password, input[name="password"]').first().fill(pass)
	await page.getByRole('button', { name: /log in|anmelden/i }).first().click()
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
}

const STAFF_PROBES = [
	{
		page: '/index.php/apps/ticketcheck/tickets',
		label: 'tickets-list',
		ready: '#app-content',
		rows: [
			{ sel: '.helpdesk-badge, .tc-badge', kind: 'badge', what: 'text' },
			{ sel: '.helpdesk-filter-toggle', kind: 'filter-toggle', what: 'text+border' },
			{ sel: '.helpdesk-form-control', kind: 'control-border', what: 'border' },
			{ sel: '.helpdesk-btn--secondary, .tc-btn--secondary, .tc-view-toggle button', kind: 'secondary-cta', what: 'text+border' },
			{ sel: '.helpdesk-alert', kind: 'alert', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/ticketcheck/tickets/create',
		label: 'ticket-create',
		ready: '#app-content',
		rows: [
			{ sel: '.helpdesk-form-control', kind: 'control-border', what: 'border' },
			{ sel: '.helpdesk-upload-dropzone', kind: 'dropzone-boundary', what: 'border' },
			{ sel: '.helpdesk-btn--primary', kind: 'primary-cta', what: 'text+border' },
			{ sel: '.helpdesk-form-help, .helpdesk-text-muted', kind: 'muted-ink', what: 'text' },
		],
	},
	{
		page: '/index.php/apps/ticketcheck/tickets/143',
		label: 'ticket-detail',
		ready: '#app-content',
		optional: true,
		rows: [
			{ sel: '.helpdesk-badge, .tc-badge', kind: 'badge', what: 'text' },
			{ sel: 'button.helpdesk-btn--danger, a.helpdesk-btn--danger, .tc-btn--danger', kind: 'danger-cta', what: 'text+border' },
			{ sel: 'button.helpdesk-btn--secondary, a.helpdesk-btn--secondary', kind: 'secondary-cta', what: 'text+border' },
			{ sel: '.ticket-detail-upload-dropzone', kind: 'dropzone-boundary', what: 'border' },
			{ sel: '.helpdesk-form-control', kind: 'control-border', what: 'border' },
			{ sel: '.helpdesk-alert', kind: 'alert', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/ticketcheck/dashboard',
		label: 'dashboard',
		ready: '#app-content',
		optional: true,
		rows: [
			{ sel: '.helpdesk-card--interactive, .portal-card-clickable, .helpdesk-metric-card--clickable', kind: 'interactive-card', what: 'border' },
			{ sel: '.helpdesk-quick-action-card', kind: 'quick-action', what: 'text' },
			{ sel: '.tc-dashboard-kpi__icon, .helpdesk-dashboard-metric-icon', kind: 'kpi-icon', what: 'glyph' },
			{ sel: '.helpdesk-btn--primary', kind: 'primary-cta', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/ticketcheck/settings',
		label: 'settings',
		ready: '#app-content',
		optional: true,
		rows: [
			{ sel: '.helpdesk-form-control', kind: 'control-border', what: 'border' },
			{ sel: '.helpdesk-btn--primary, .helpdesk-btn--secondary', kind: 'cta', what: 'text+border' },
			{ sel: '.helpdesk-alert', kind: 'alert', what: 'text+border' },
		],
	},
]

const GUEST_PROBES = [
	{
		page: '/index.php/apps/ticketcheck/portal',
		label: 'portal-home',
		ready: '#app-content',
		rows: [
			{ sel: '.portal-card-clickable, .helpdesk-card--interactive', kind: 'portal-card', what: 'text+border' },
			{ sel: '.helpdesk-badge, .tc-badge', kind: 'badge', what: 'text' },
			{ sel: '.helpdesk-btn--primary, .helpdesk-btn--secondary', kind: 'portal-cta', what: 'text+border' },
		],
	},
	{
		page: '/index.php/apps/ticketcheck/portal/tickets/create',
		label: 'portal-create',
		ready: '#app-content',
		rows: [
			{ sel: '.helpdesk-form-control', kind: 'control-border', what: 'border' },
			{ sel: '.helpdesk-upload-dropzone', kind: 'dropzone-boundary', what: 'border' },
			{ sel: '.helpdesk-btn--primary', kind: 'primary-cta', what: 'text+border' },
		],
	},
]

async function measure(page, probes, theme) {
	const results = []
	for (const p of probes) {
		const res = await page.goto(`${BASE}${p.page}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
		if (!res || res.status() >= 400) {
			if (p.optional) {
				results.push({ page: p.label, skipped: `HTTP ${res ? res.status() : 'nav-fail'}` })
				continue
			}
			throw new Error(`probe page ${p.page} -> HTTP ${res ? res.status() : 'nav-fail'}`)
		}
		await applyTheme(page, theme) // navigation resets body theme attrs
		await page.waitForSelector(p.ready || '#app-content', { timeout: 30_000 })
		await page.waitForTimeout(400)
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 8)) {
						const cs = getComputedStyle(el)
						const bg = window.__tcProbe.effBg(el)
						const surround = window.__tcProbe.ancestorBg(el)
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 90),
							fg: cs.color,
							bg,
							surroundBg: surround,
							bgSelf: cs.backgroundColor,
							fillAlpha: window.__tcProbe.alphaOf(cs.backgroundColor),
							borderColor: cs.borderColor,
							borderWidth: cs.borderWidth,
							fontSize: cs.fontSize,
							fontWeight: cs.fontWeight,
						}
						if (what !== 'border') {
							item.textRatio = window.__tcProbe.ratio(cs.color, bg)
						}
						// Boundary vs the surface the control sits on (1.4.11),
						// not vs its own fill — a same-colour border on a solid
						// fill is invisible but harmless when the fill edge is
						// the boundary (fill-vs-surround check below).
						if (what !== 'text' && what !== 'glyph' && parseFloat(cs.borderWidth) > 0) {
							const bAlpha = window.__tcProbe.alphaOf(cs.borderColor)
							item.borderAlpha = bAlpha
							item.borderTransparent = bAlpha === 0
							if (bAlpha > 0) {
								item.borderRatio = window.__tcProbe.borderRatio(cs.borderColor, surround)
							}
						}
						if (item.fillAlpha > 0) {
							item.fillRatio = window.__tcProbe.ratio(cs.backgroundColor, surround)
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what },
			)
			results.push({ page: p.label, kind: row.kind, selector: row.sel, what: row.what, found: found.length, samples: found })
		}
	}
	return results
}

/**
 * Live invalid-state: submit the ticket-create form with required fields
 * empty → client-side form-validation pins aria-invalid + inline errors.
 * Measures the painted error border + error ink (WCAG 3.3.1/3.3.3/1.4.11).
 */
async function measureFieldError(page, theme) {
	await page.goto(`${BASE}/index.php/apps/ticketcheck/tickets/create`, { waitUntil: 'domcontentloaded' })
	await applyTheme(page, theme)
	await page.waitForSelector('#app-content', { timeout: 30_000 })
	const submit = page.locator(
		'form button[type="submit"], form input[type="submit"], form .helpdesk-btn--primary[type="submit"]',
	).first()
	if ((await submit.count()) === 0) return { skipped: 'no submit on create form' }
	// Bypass native required-attr blocking so the app's validator owns the state.
	await page.evaluate(() => {
		document.querySelectorAll('form').forEach((f) => f.setAttribute('novalidate', 'novalidate'))
	})
	await submit.click()
	await page.waitForFunction(
		() => document.querySelector('#app-content [aria-invalid="true"]')
			|| document.querySelector('#app-content .helpdesk-form-error-summary:not([hidden])'),
		null,
		{ timeout: 10_000 },
	).catch(() => {})
	// .helpdesk-form-control transitions border-color (~180ms) — wait for it
	// to settle so the artifact records the painted error color, not a
	// mid-transition gray→red interpolation sample.
	await page.waitForTimeout(400)
	return page.evaluate(() => {
		const input = document.querySelector('#app-content [aria-invalid="true"]')
		const err = document.querySelector('#app-content .tc-field-error, #app-content .helpdesk-form-error, #app-content .helpdesk-form-error-summary')
		const out = { ariaInvalid: input && input.getAttribute('aria-invalid') }
		if (!input && !err) return { skipped: 'no invalid state painted after empty submit' }
		if (input) {
			const cs = getComputedStyle(input)
			out.invalidBorder = {
				cls: (input.getAttribute('class') || '').slice(0, 80),
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg: window.__tcProbe.effBg(input),
				borderRatio: window.__tcProbe.borderRatio(cs.borderColor, window.__tcProbe.ancestorBg(input)),
			}
		}
		if (err && err.offsetParent !== null) {
			const cs = getComputedStyle(err)
			out.fieldErrorText = {
				cls: (err.getAttribute('class') || '').slice(0, 80),
				fg: cs.color,
				bg: window.__tcProbe.effBg(err),
				textRatio: window.__tcProbe.ratio(cs.color, window.__tcProbe.effBg(err)),
				text: (err.textContent || '').slice(0, 80),
			}
		}
		return out
	})
}

/**
 * Dialog-state probe: open the merge dialog (appended to document.body —
 * outside #app-content) and measure the composite picker shell border +
 * ghost/primary action borders.
 */
async function measureDialog(page, theme) {
	await page.goto(`${BASE}/index.php/apps/ticketcheck/tickets/143`, { waitUntil: 'domcontentloaded' })
	await applyTheme(page, theme)
	await page.waitForSelector('#app-content', { timeout: 30_000 })
	const btn = page.locator('#merge-ticket-btn')
	if ((await btn.count()) === 0) return { skipped: 'no merge button (perms/ticket)' }
	await btn.click()
	const dialog = page.locator('dialog.helpdesk-dialog#merge-ticket-dialog')
	await dialog.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {})
	if (!(await dialog.isVisible().catch(() => false))) {
		return { skipped: 'merge dialog did not open' }
	}
	const out = await page.evaluate(() => {
		const grab = (el) => {
			const cs = getComputedStyle(el)
			const bg = window.__tcProbe.effBg(el)
			const surround = window.__tcProbe.ancestorBg(el)
			return {
				tag: el.tagName.toLowerCase(),
				cls: (el.getAttribute('class') || '').slice(0, 90),
				fg: cs.color,
				bg,
				surroundBg: surround,
				bgSelf: cs.backgroundColor,
				fillRatio: window.__tcProbe.ratio(cs.backgroundColor, surround),
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				borderAlpha: window.__tcProbe.alphaOf(cs.borderColor),
				borderRatio: window.__tcProbe.borderRatio(cs.borderColor, surround),
				textRatio: window.__tcProbe.ratio(cs.color, bg),
			}
		}
		const shell = document.querySelector('#merge-ticket-dialog .tc-entity-search-picker__shell')
		const ghost = document.querySelector('#merge-ticket-dialog .helpdesk-btn--ghost')
		const primary = document.querySelector('#merge-ticket-dialog .helpdesk-btn--primary')
		return {
			pickerShell: shell ? grab(shell) : null,
			ghostButton: ghost ? grab(ghost) : null,
			primaryButton: primary ? grab(primary) : null,
		}
	})
	await page.keyboard.press('Escape')
	return out
}

/**
 * Upload-error ink: apply the exact inline style the JS pins on upload errors
 * to the live #file-count-text node, measure, then restore — proves the token
 * recipe resolves to readable ink in every theme.
 */
async function measureUploadErrorInk(page, theme) {
	await page.goto(`${BASE}/index.php/apps/ticketcheck/tickets/create`, { waitUntil: 'domcontentloaded' })
	await applyTheme(page, theme)
	await page.waitForSelector('#app-content', { timeout: 30_000 })
	return page.evaluate(() => {
		const el = document.getElementById('file-count-text')
		if (!el) return { skipped: 'no #file-count-text on create form' }
		const prev = { color: el.style.color, weight: el.style.fontWeight }
		// Mirror of notifyUploadError()/appendFiles() inline style in
		// js/ticket-form.js (post-fix recipe: -text token with legacy fallback).
		el.style.color = 'var(--color-error-text, var(--tc-danger-ink, var(--color-error)))'
		el.style.fontWeight = '600'
		const cs = getComputedStyle(el)
		const out = {
			fg: cs.color,
			bg: window.__tcProbe.effBg(el),
			textRatio: window.__tcProbe.ratio(cs.color, window.__tcProbe.effBg(el)),
		}
		el.style.color = prev.color
		el.style.fontWeight = prev.weight
		return out
	})
}

/** Guest portal survey-star glyphs (radiogroup) — find a resolved ticket. */
async function measureSurveyStars(page, theme) {
	await page.goto(`${BASE}/index.php/apps/ticketcheck/portal/tickets`, { waitUntil: 'domcontentloaded' })
	const href = await page.evaluate(() => {
		const a = document.querySelector('a[href*="/portal/tickets/"]')
		return a ? a.getAttribute('href') : null
	})
	if (!href) return { skipped: 'no portal ticket links' }
	await page.goto(`${BASE}${href}`, { waitUntil: 'domcontentloaded' })
	await applyTheme(page, theme)
	await page.waitForSelector('#app-content', { timeout: 30_000 })
	return page.evaluate(() => {
		const stars = Array.from(document.querySelectorAll('.helpdesk-survey-star'))
			.filter((n) => n.offsetParent !== null)
		if (stars.length === 0) return { skipped: 'no survey stars rendered on this ticket' }
		return stars.slice(0, 5).map((el) => {
			const cs = getComputedStyle(el)
			const bg = window.__tcProbe.effBg(el)
			return {
				cls: (el.getAttribute('class') || '').slice(0, 90),
				fg: cs.color,
				bg,
				glyphRatio: window.__tcProbe.ratio(cs.color, bg),
			}
		})
	})
}

async function runLeg({ user, pass, probes, extra = [], label }) {
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const leg = { pages: {}, notes: [] }
	try {
		await login(page, user, pass)
		await context.addInitScript(EVAL_FN)
		for (const theme of THEME_IDS) {
			const themeRes = { pages: await measure(page, probes, theme) }
			themeRes.tokens = await tokenSnapshot(page)
			for (const fn of extra) {
				try {
					const r = await fn(page, theme)
					themeRes[fn.name.replace(/^measure/, '').replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`).replace(/^_/, '')] = r
				} catch (e) {
					leg.notes.push(`${label}/${theme}/${fn.name}: ${e && e.message || e}`)
				}
			}
			leg.pages[theme] = themeRes
		}
	} finally {
		await context.close()
		await browser.close()
	}
	return leg
}

async function main() {
	if (!USER || !PASS) throw new Error('E2E_USER + E2E_PASSWORD required (e2e/.env)')
	const report = {
		app: 'ticketcheck',
		probe: 'live-computed-contrast',
		base: BASE,
		generated_at: new Date().toISOString(),
		legs: {},
	}

	report.legs.staff = await runLeg({
		user: USER, pass: PASS, label: 'staff',
		probes: STAFF_PROBES,
		extra: [measureFieldError, measureDialog, measureUploadErrorInk],
	})
	if (GUEST_USER && GUEST_PASS) {
		report.legs.guest = await runLeg({
			user: GUEST_USER, pass: GUEST_PASS, label: 'guest',
			probes: GUEST_PROBES,
			extra: [measureSurveyStars],
		})
	} else {
		report.legs.guest = { skipped: 'E2E_GUEST_* not set' }
	}

	// Verdict
	const TEXT_MIN = 4.5
	const TEXT_MIN_LARGE = 3.0
	const BORDER_MIN = 3.0
	const GLYPH_MIN = 3.0
	const findings = []
	const GLYPH_KINDS = new Set(['kpi-icon', 'survey-star-glyph'])
	function isLarge(s) {
		const px = parseFloat(s.fontSize || '0')
		const bold = parseInt(s.fontWeight || '400', 10) >= 700
		return px >= 24 || (px >= 18.66 && bold)
	}
	function checkSample(leg, theme, page, kind, s) {
		const ctx = { leg, theme, page, kind, cls: s.cls }
		if (s.textRatio !== undefined && s.textRatio !== null) {
			const min = GLYPH_KINDS.has(kind) ? GLYPH_MIN : (isLarge(s) ? TEXT_MIN_LARGE : TEXT_MIN)
			if (s.textRatio < min) findings.push({ ...ctx, what: GLYPH_KINDS.has(kind) ? 'glyph' : 'text', fg: s.fg, bg: s.bg, ratio: s.textRatio, min })
		}
		if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
			// A faint border is only a violation when the element's own fill
			// doesn't already supply a >=3:1 boundary (solid-fill CTAs).
			const fillCarries = s.fillRatio !== undefined && s.fillRatio !== null && s.fillRatio >= BORDER_MIN
			if (!fillCarries) {
				findings.push({ ...ctx, what: 'border', border: s.borderColor, bg: s.surroundBg, ratio: s.borderRatio, min: BORDER_MIN })
			}
		}
		if (s.borderTransparent === true && !(s.fillRatio !== undefined && s.fillRatio !== null && s.fillRatio >= BORDER_MIN)) {
			// Control expected to paint a boundary but has neither a visible
			// border nor a >=3:1 fill edge — silently frameless.
			findings.push({ ...ctx, what: 'no-visible-boundary', ratio: 0, min: BORDER_MIN })
		}
	}
	for (const [legName, leg] of Object.entries(report.legs)) {
		if (!leg.pages) continue
		for (const [theme, t] of Object.entries(leg.pages)) {
			for (const row of t.pages || []) {
				for (const s of row.samples || []) checkSample(legName, theme, row.page, row.kind, s)
			}
			const fe = t.field_error
			if (fe && !fe.skipped) {
				if (fe.invalidBorder && fe.invalidBorder.borderRatio !== null && fe.invalidBorder.borderRatio < BORDER_MIN) {
					findings.push({ leg: legName, theme, page: 'ticket-create', kind: 'invalid-border', ratio: fe.invalidBorder.borderRatio, min: BORDER_MIN })
				}
				if (fe.fieldErrorText && fe.fieldErrorText.textRatio !== null && fe.fieldErrorText.textRatio < TEXT_MIN) {
					findings.push({ leg: legName, theme, page: 'ticket-create', kind: 'field-error-text', ratio: fe.fieldErrorText.textRatio, min: TEXT_MIN })
				}
			}
			const dlg = t.dialog
			if (dlg && !dlg.skipped) {
				if (dlg.pickerShell && dlg.pickerShell.borderRatio !== null && dlg.pickerShell.borderRatio < BORDER_MIN) {
					findings.push({ leg: legName, theme, page: 'merge-dialog', kind: 'picker-shell-border', ratio: dlg.pickerShell.borderRatio, min: BORDER_MIN })
				}
				for (const [k, s] of [['primary', dlg.primaryButton]]) {
					if (s && s.textRatio !== null && s.textRatio < TEXT_MIN) {
						findings.push({ leg: legName, theme, page: 'merge-dialog', kind: `${k}-cta-text`, ratio: s.textRatio, min: TEXT_MIN })
					}
				}
			}
			const ink = t.upload_error_ink
			if (ink && !ink.skipped && ink.textRatio !== null && ink.textRatio < TEXT_MIN) {
				findings.push({ leg: legName, theme, page: 'ticket-create', kind: 'upload-error-ink', fg: ink.fg, ratio: ink.textRatio, min: TEXT_MIN })
			}
			const stars = t.survey_stars
			if (Array.isArray(stars)) {
				for (const s of stars) {
					if (s.glyphRatio !== null && s.glyphRatio < GLYPH_MIN) {
						findings.push({ leg: legName, theme, page: 'portal-ticket', kind: 'survey-star-glyph', cls: s.cls, ratio: s.glyphRatio, min: GLYPH_MIN })
					}
				}
			}
		}
	}
	// Token-level findings (recipe check independent of sampled DOM):
	// unresolved app tokens + text/border tokens that can't carry their duty.
	for (const [legName, leg] of Object.entries(report.legs)) {
		if (!leg.pages) continue
		for (const [theme, t] of Object.entries(leg.pages)) {
			const tok = t.tokens
			if (!tok) continue
			for (const [name, v] of Object.entries(tok.values || {})) {
				if (v && String(v).startsWith('(unresolved)')) {
					findings.push({ leg: legName, theme, kind: 'unresolved-token', token: name, value: v })
				}
			}
			const r = tok.ratios || {}
			if (r.error_text_vs_bg !== null && r.error_text_vs_bg < TEXT_MIN) {
				findings.push({ leg: legName, theme, kind: 'token-error-text', ratio: r.error_text_vs_bg, min: TEXT_MIN })
			}
			if (r.warning_text_vs_bg !== null && r.warning_text_vs_bg < TEXT_MIN) {
				findings.push({ leg: legName, theme, kind: 'token-warning-text', ratio: r.warning_text_vs_bg, min: TEXT_MIN })
			}
			if (r.success_text_vs_bg !== null && r.success_text_vs_bg < TEXT_MIN) {
				findings.push({ leg: legName, theme, kind: 'token-success-text', ratio: r.success_text_vs_bg, min: TEXT_MIN })
			}
			if (r.form_border_vs_bg !== null && r.form_border_vs_bg < BORDER_MIN) {
				findings.push({ leg: legName, theme, kind: 'token-form-border', ratio: r.form_border_vs_bg, min: BORDER_MIN })
			}
			if (r.muted_vs_bg !== null && r.muted_vs_bg < TEXT_MIN) {
				findings.push({ leg: legName, theme, kind: 'token-muted-ink', ratio: r.muted_vs_bg, min: TEXT_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(findings.length > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
