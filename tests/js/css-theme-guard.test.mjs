import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Theme-compliance guard (design system §0.1 / checklist).
 * Raw colours only allowed as var() fallbacks; every consumed custom
 * property must be defined in-app or provided by Nextcloud.
 */

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const cssRoot = join(appRoot, 'css')

const RAW_COLOR_PROPERTY_ALLOWLIST = new Set([])

const NC_PROVIDED_PREFIXES = [
	'--color-',
	'--border-radius',
	'--header-height',
	'--icon-',
	'--default-',
	'--body-container-',
	'--body-height',
	'--image-height',
	'--image-width',
	'--animation-',
	'--font-',
	'--background-',
]

function cssFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return cssFiles(path)
		}
		return entry.name.endsWith('.css') ? [path] : []
	})
}

function stripComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

function stripUrls(css) {
	return css.replace(/url\((?:[^()]|\([^()]*\))*\)/g, 'url(#stripped)')
}

function stripVarGroups(css) {
	let previous
	do {
		previous = css
		css = css.replace(/var\(\s*--[\w-]+\s*(?:,[^()]*(?:\([^()]*\)[^()]*)*)?\)/g, '')
	} while (css !== previous)
	return css
}

const files = cssFiles(cssRoot)

test('CSS uses no raw colours outside var() fallbacks', () => {
	const offenders = []
	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		for (const [index, rawLine] of source.split('\n').entries()) {
			const property = rawLine.match(/^\s*(--[\w-]+)\s*:/)?.[1]
			if (property && RAW_COLOR_PROPERTY_ALLOWLIST.has(property)) {
				continue
			}
			const line = stripVarGroups(stripUrls(rawLine))
			if (/#[0-9a-fA-F]{3,8}\b|(?<![\w-])(?:rgb|rgba|hsl|hsla)\(/.test(line)) {
				offenders.push(`${relative(appRoot, file)}:${index + 1}: ${rawLine.trim()}`)
			}
		}
	}
	assert.deepEqual(offenders, [], `Raw colours found (map to NC --color-* / --tc-* tokens):\n${offenders.join('\n')}`)
})

test('required design-system tokens are defined', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'common/tokens.css'), 'utf8'))
	const required = [
		'--tc-space-1',
		'--tc-radius-md',
		'--tc-bg-card',
		'--tc-text',
		'--tc-muted',
		'--tc-border',
		'--tc-tint-info',
		'--tc-tint-success',
		'--tc-tint-warning',
		'--tc-tint-critical',
		'--tc-scrim',
		'--tc-scrim-strong',
		'--tc-touch-min',
		'--tc-shell-max',
		'--tc-overlay-top',
	]
	const missing = required.filter((name) => !source.includes(`${name}:`))
	assert.deepEqual(missing, [], `Missing tokens in tokens.css:\n${missing.join('\n')}`)
})

test('tints mix into --color-main-background (not transparent)', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'common/tokens.css'), 'utf8'))
	for (const name of ['--tc-tint-info', '--tc-tint-success', '--tc-tint-warning', '--tc-tint-critical']) {
		const match = source.match(new RegExp(`${name}:\\s*([^;]+);`))
		assert.ok(match, `${name} must be defined`)
		assert.match(match[1], /color-main-background/, `${name} must mix into --color-main-background`)
		assert.doesNotMatch(match[1], /,\s*transparent\s*\)\s*$/, `${name} must not mix into transparent`)
	}
})

test('every consumed CSS variable is defined in-app or provided by Nextcloud', () => {
	const defined = new Set()
	const consumed = new Map()

	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		for (const match of source.matchAll(/(--[\w-]+)\s*:/g)) {
			defined.add(match[1])
		}
		for (const match of source.matchAll(/var\(\s*(--[\w-]+)/g)) {
			if (!consumed.has(match[1])) {
				consumed.set(match[1], relative(appRoot, file))
			}
		}
	}

	// Known aliases / NC-ish tokens used as soft fallbacks across legacy layers
	const softAllow = new Set([
		'--tc-accent',
		'--color-primary',
		'--color-background',
		'--color-primary-light',
		'--color-error-hover',
		'--color-success-hover',
		'--color-warning-element-text',
		'--color-error-element-text',
		'--color-primary-element-light',
		'--color-text-error',
		'--color-text-warning',
		'--color-text-success',
		'--color-element-error',
		'--color-element-warning',
		'--color-element-success',
		'--hd-error-rgb',
		'--hd-warning-rgb',
		'--hd-success-rgb',
		'--hd-info-rgb',
	])

	const undefinedVars = [...consumed.entries()]
		.filter(([name]) => !defined.has(name)
			&& !softAllow.has(name)
			&& !NC_PROVIDED_PREFIXES.some((prefix) => name.startsWith(prefix)))
		.map(([name, firstUse]) => `${name} (first used in ${firstUse})`)

	assert.deepEqual(undefinedVars, [],
		`Undefined CSS variables (add to tokens.css):\n${undefinedVars.join('\n')}`)
})

test('JS writes no hardcoded colours to style/fill/stroke', () => {
	const jsRoot = join(appRoot, 'js')
	const offenders = []
	for (const entry of readdirSync(jsRoot, { recursive: true })) {
		if (!String(entry).endsWith('.js')) {
			continue
		}
		const file = join(jsRoot, String(entry))
		const source = readFileSync(file, 'utf8')
		for (const [index, line] of source.split('\n').entries()) {
			if (!/style\.(color|background|backgroundColor|borderColor|fill|stroke)\s*=|strokeStyle|fillStyle/.test(line)) {
				continue
			}
			if (!/#[0-9a-fA-F]{3,8}\b|(?:rgb|rgba|hsl|hsla)\(/.test(line)) {
				continue
			}
			offenders.push(`${relative(appRoot, file)}:${index + 1}: ${line.trim()}`)
		}
	}
	assert.deepEqual(offenders, [], `Hardcoded colours in JS:\n${offenders.join('\n')}`)
})

test('status badges include a leading shape cue (::before)', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	assert.match(source, /\.helpdesk-badge::before/, 'helpdesk-badge must have ::before status dot')
	assert.match(source, /\.tc-badge::before/, 'tc-badge must have ::before status dot')
	assert.match(source, /--tc-bdg-dot/, 'badge tokens must define --tc-bdg-dot')
})

test('grids collapse to one column at the design-system md breakpoint (768px)', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	const mdCollapse = /@media\s*\(\s*max-width:\s*767\.98px\s*\)\s*\{[^}]*\.tc-quick-action-grid[^}]*grid-template-columns:\s*1fr/s
	assert.match(source, mdCollapse, 'quick-action grid must collapse at ≤767.98px')
	assert.match(
		source,
		/@media\s*\(\s*max-width:\s*767\.98px\s*\)\s*\{[^}]*\.tc-dashboard-kpis[^}]*grid-template-columns:\s*1fr/s,
		'dashboard KPIs must collapse at ≤767.98px',
	)
})

test('modal close controls meet 44px touch target', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	assert.match(
		source,
		/\.helpdesk-modal__close\s*\{[^}]*min-(?:width|height):\s*var\(--tc-touch-min/s,
		'helpdesk-modal__close must use --tc-touch-min',
	)
	assert.match(
		source,
		/\.tc-modal__close\s*\{[^}]*min-width:\s*44px/s,
		'tc-modal__close must keep 44px min size',
	)
})

test('global focus-visible ring is at least 3px primary', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	assert.match(
		source,
		/#app-content\.tc-app :focus-visible[\s\S]{0,200}outline:\s*3px solid var\(--color-primary-element\)/,
		'focus-visible outline must be 3px primary',
	)
})

test('interactive size variants never drop below 44px touch target', () => {
	const offenders = []
	const patterns = [
		{ re: /\.helpdesk-btn--sm[^{]*\{[^}]*min-height:\s*([^;]+);/gs, name: 'helpdesk-btn--sm' },
		{ re: /\.helpdesk-filter-toggle[^{]*\{[^}]*min-height:\s*([^;]+);/gs, name: 'helpdesk-filter-toggle' },
		{ re: /\.kb-editor-btn[^{]*\{[^}]*min-height:\s*([^;]+);/gs, name: 'kb-editor-btn' },
	]
	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		for (const { re, name } of patterns) {
			re.lastIndex = 0
			let match
			while ((match = re.exec(source)) !== null) {
				const value = match[1].trim()
				const ok = /var\(--tc-touch-min|var\(--helpdesk-touch-target|var\(--tc-touch-lg|2\.75rem|44px|48px|3rem/.test(value)
					|| /^\d+(\.\d+)?(?:rem|em|px)$/.test(value) === false
				if (!ok) {
					const px = value.endsWith('px') ? parseFloat(value) : (value.endsWith('rem') ? parseFloat(value) * 16 : NaN)
					if (!Number.isNaN(px) && px < 44) {
						offenders.push(`${relative(appRoot, file)}: ${name} min-height ${value}`)
					} else if (!/var\(/.test(value) && !Number.isNaN(px) && px < 44) {
						offenders.push(`${relative(appRoot, file)}: ${name} min-height ${value}`)
					}
				}
				if (/^\d+(\.\d+)?px$/.test(value) && parseFloat(value) < 44) {
					offenders.push(`${relative(appRoot, file)}: ${name} min-height ${value}`)
				}
			}
		}
	}
	assert.deepEqual([...new Set(offenders)], [], `Touch targets < 44px:\n${[...new Set(offenders)].join('\n')}`)
})

test('modals and lightboxes avoid raw 100vw width (scrollbar overflow)', () => {
	const offenders = []
	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		for (const [index, rawLine] of source.split('\n').entries()) {
			const line = rawLine.trim()
			if (!/(width|max-width)\s*:\s*100vw\s*;/.test(line)) {
				continue
			}
			// Intentional: calc(100vw - …) / min(…, 100vw) keep gutter room
			offenders.push(`${relative(appRoot, file)}:${index + 1}: ${line}`)
		}
	}
	assert.deepEqual(offenders, [], `Raw 100vw width causes horizontal overflow:\n${offenders.join('\n')}`)
})

test('guest portal resets inherited center alignment', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	// core guest.css sets body{text-align:center} for centered login boxes;
	// the portal wrapper reset must cut that inheritance or every label floats mid-field
	// (selectors carry pre-boot twins between them — allow intervening list items)
	const wrapperReset = /body\.guest-user\s+\.wrapper[\s\S]*?body\.guest-user\s+\.v-align[^{]*\{([^}]*)\}/
	const block = source.match(wrapperReset)?.[1]
	assert.ok(block, 'guest wrapper/v-align reset block must exist')
	assert.match(block, /text-align:\s*start\s*!important/, 'guest wrapper reset must restore start text-align')
	// core guest.css centers `form fieldset legend` explicitly; a scoped override must win
	assert.match(
		source,
		/body\.guest-user\s+\.tc-app\s+fieldset\s+legend[\s\S]{0,300}?text-align:\s*start/,
		'guest fieldset legends must be start-aligned (project picker, survey rating)',
	)
	// the guest .helpdesk-form-control shorthand re-sets padding/line-height at
	// higher specificity, which breaks select vertical centring — the re-assert
	// must exist after it
	const guestSelect = source.match(/body\.guest-user\s+select\.helpdesk-form-control[^{]*\{([^}]*)\}/s)?.[1]
	assert.ok(guestSelect, 'guest select.helpdesk-form-control override must exist')
	assert.match(guestSelect, /line-height:\s*1em/, 'guest selects must keep 1em line-height')
	assert.match(guestSelect, /padding:[^;]*--helpdesk-touch-target[^;]*--helpdesk-spacing-xl/, 'guest selects must re-assert tuned padding (chevron gutter)')
})

test('every body.guest-user selector carries its pre-boot twin', () => {
	// FOUC guard: core layout.guest.php emits <body id="body-login"> with no
	// class; nav.js adds .guest-user ~370ms after first paint. Guest CSS must
	// also match the parse-time marker #app-content[data-tc-nav-mode="guest"]
	// or the portal renders as NC's centered login card until JS lands.
	const files = ['app.css', 'common/tokens.css'].map((f) => join(cssRoot, f))
	const missing = []
	for (const file of files) {
		const source = stripComments(readFileSync(file, 'utf8'))
		// every "X {" where X isn't an at-rule is a selector list (media-wrapped
		// rules yield the media prelude first, then the inner selector)
		for (const match of source.matchAll(/([^{}]+)\{/g)) {
			const selectorList = match[1]
			if (/@[a-z-]+\s*$/.test(selectorList.trim())) continue
			const pieces = selectorList.split(',').map((p) => p.replace(/\s+/g, ' ').trim())
			pieces.forEach((piece) => {
				if (!/body\.guest-user/.test(piece)) return
				// the twin keeps the same prefix + tail and swaps guest-user for
				// the parse-time marker selector; it may sit adjacent or grouped
				const tail = piece.slice(piece.indexOf('body.guest-user') + 'body.guest-user'.length)
				const prefix = piece.slice(0, piece.indexOf('body.guest-user'))
				const expectedTwin = `${prefix}body#body-login:has([data-tc-nav-mode="guest"])${tail}`
				if (!pieces.includes(expectedTwin)) {
					missing.push(`${file.split('/').pop()}: ${piece}`)
				}
			})
		}
	}
	assert.deepEqual(missing, [], `guest selectors missing pre-boot twin:\n${missing.join('\n')}`)
})

test('dark themes lift primary-element badge ink above WCAG AA', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	// .tc-badge--guest/--agent ink (#0091f2) on the info tint (#132b3a) measured
	// 4.41:1 — just under the 4.5 floor. The lift must cover both the explicit
	// dark themes AND the "default" theme going dark via media query.
	assert.match(
		source,
		/\[data-theme-dark\]\s+\.tc-badge--guest,[\s\S]{0,200}--tc-bdg-ink:\s*color-mix\(in srgb,\s*var\(--color-primary-element\)/,
		'dark themes must lift primary-element badge ink via color-mix',
	)
	assert.match(
		source,
		/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{[^}]*body\[data-theme-default\]\s+\.tc-badge--guest/,
		'default-theme dark mode (media query) must get the same badge ink lift',
	)
})

test('funding wordmark inverts under every dark theme path', () => {
	const source = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'))
	// The Prototype Fund wordmark ships black-on-transparent. Explicit dark
	// themes AND the default theme going dark via media query must both
	// invert it — otherwise the funding logo is invisible on dark.
	assert.match(
		source,
		/\[data-theme-dark\][^{]*\.tc-about__ptf-logo[^{]*\{[^}]*filter:\s*invert\(1\)/,
		'explicit dark themes must invert the PF wordmark',
	)
	assert.match(
		source,
		/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*body\[data-theme-default\][^{]*\.tc-about__ptf-logo[^{]*\{[^}]*filter:\s*invert\(1\)/,
		'default-theme dark mode (media query) must invert the PF wordmark too',
	)
})

test('forced-colors media queries cover license, redirect, and app shell', () => {
	const license = readFileSync(join(cssRoot, 'license-settings.css'), 'utf8')
	const redirect = readFileSync(join(cssRoot, 'redirect.css'), 'utf8')
	const app = readFileSync(join(cssRoot, 'app.css'), 'utf8')
	assert.match(license, /@media\s*\(\s*forced-colors:\s*active\s*\)/, 'license-settings.css needs forced-colors')
	assert.match(redirect, /@media\s*\(\s*forced-colors:\s*active\s*\)/, 'redirect.css needs forced-colors')
	assert.match(app, /@media\s*\(\s*forced-colors:\s*active\s*\)[\s\S]*helpdesk-btn/, 'app.css needs forced-colors for buttons')
})
