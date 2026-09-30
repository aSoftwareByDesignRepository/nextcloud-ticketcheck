import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Kanban card layout guard.
 *
 * A board card must render as ONE bordered rectangle: the drag handle and
 * the keyboard status select live in a slim header strip inside the card,
 * and the ticket link below carries no card chrome of its own.
 */

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const css = readFileSync(join(appRoot, 'css/app.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
const template = readFileSync(join(appRoot, 'templates/tickets-kanban.php'), 'utf8')

function ruleBodies(selector) {
	const re = new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}', 'g')
	const bodies = [...css.matchAll(re)].map((m) => m[1])
	assert.ok(bodies.length > 0, `Missing CSS rule for ${selector}`)
	return bodies
}

function ruleBody(selector) {
	return ruleBodies(selector).join('\n')
}

test('template puts controls inside the card before the ticket link', () => {
	const cardStart = template.indexOf('class="tc-kanban__card"')
	const controls = template.indexOf('tc-kanban__card-controls')
	const handle = template.indexOf('tc-kanban__drag-handle')
	const link = template.indexOf('class="tc-card tc-card--interactive"')
	assert.ok(cardStart > -1, 'tc-kanban__card li missing')
	assert.ok(controls > cardStart, 'card controls must be inside the card')
	assert.ok(handle > controls && handle < link, 'drag handle must sit in the card header before the link')
	assert.ok(link > controls, 'ticket link must follow the controls strip')
})

test('kanban card is a single bordered container', () => {
	const body = ruleBody('#app-content.tc-app .tc-kanban__card')
	assert.match(body, /flex-direction:\s*column/, 'card must stack controls above the link')
	assert.match(body, /border:\s*1px solid/, 'card must own the border')
	assert.match(body, /border-radius:\s*var\(--tc-radius-md\)/, 'card must own the radius')
	assert.match(body, /box-shadow:\s*var\(--tc-shadow-sm\)/, 'card must own the shadow')
	assert.match(body, /overflow:\s*hidden/, 'card must clip the header strip to its radius')
})

test('controls render as a card header strip with a divider', () => {
	const bodies = ruleBodies('#app-content.tc-app .tc-kanban__card-controls')
	const strip = bodies.find((body) => /display:\s*flex/.test(body))
	assert.ok(strip, 'controls must be a flex row')
	assert.doesNotMatch(strip, /flex-direction:\s*column/, 'controls must not be a side rail')
	assert.match(strip, /border-bottom:\s*1px solid/, 'controls strip must be separated from the card body')
})

test('status select is pushed to the end of the header strip', () => {
	const wrap = ruleBody('#app-content.tc-app .tc-kanban__status-wrap')
	assert.match(wrap, /margin-inline-start:\s*auto/, 'status pill must align to the strip end (RTL-safe)')
	assert.match(wrap, /position:\s*relative/, 'wrap must anchor the dot and chevron overlays')
	const body = ruleBody('#app-content.tc-app .tc-kanban__status-select')
	assert.match(body, /min-height:\s*var\(--tc-touch-min/, 'select must keep the 44px touch target')
})

test('status select renders as a status pill', () => {
	const body = ruleBody('#app-content.tc-app .tc-kanban__status-select')
	assert.match(body, /appearance:\s*none/, 'native arrow must be replaced by the icon chevron')
	assert.match(body, /border-radius:\s*var\(--tc-radius-pill/, 'select must render as a pill')
	assert.match(body, /var\(--tc-kanban-accent/, 'pill must take the column accent tint')
	const chevron = ruleBody('#app-content.tc-app .tc-kanban__status-chevron')
	assert.match(chevron, /pointer-events:\s*none/, 'chevron overlay must not block the select')
	const dot = ruleBody('#app-content.tc-app .tc-kanban__status-wrap::before')
	assert.match(dot, /pointer-events:\s*none/, 'accent dot must not block the select')
	assert.match(dot, /var\(--tc-kanban-accent/, 'accent dot must use the column accent')
	assert.ok(
		template.includes('tc-kanban__status-wrap') && template.includes('tc-kanban__status-chevron'),
		'template must render the status pill wrap + chevron',
	)
})

test('ticket link inside a kanban card carries no card chrome', () => {
	const body = ruleBody('#app-content.tc-app .tc-kanban__card .tc-card')
	assert.match(body, /border:\s*0/, 'inner link must not draw a second border')
	assert.match(body, /box-shadow:\s*none/, 'inner link must not draw a second shadow')
	const focus = ruleBody('#app-content.tc-app .tc-kanban__card .tc-card--interactive:focus-visible')
	assert.match(focus, /outline:\s*3px solid var\(--color-primary-element/, 'link focus ring must stay 3px primary')
	assert.match(focus, /outline-offset:\s*-3px/, 'link focus ring must render inside the clipped card')
})

test('drag handle keeps its 44px touch target', () => {
	const body = ruleBody('#app-content.tc-app .tc-kanban__drag-handle')
	assert.match(body, /min-width:\s*var\(--tc-touch-min/, 'handle min-width must stay --tc-touch-min')
	assert.match(body, /min-height:\s*var\(--tc-touch-min/, 'handle min-height must stay --tc-touch-min')
	assert.match(body, /cursor:\s*grab/, 'handle must keep the grab cursor affordance')
})
