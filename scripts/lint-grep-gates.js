#!/usr/bin/env node
/**
 * CI grep gates for TicketCheck visual refactor (ARCH-19).
 */
const { execSync } = require('child_process');
const path = require('path');

const root = path.join(__dirname, '..');

function rg(pattern, glob, extraArgs = '') {
	try {
		const out = execSync(
			`rg -n ${extraArgs} '${pattern.replace(/'/g, "'\\''")}' ${glob} 2>/dev/null || true`,
			{ cwd: root, encoding: 'utf8', shell: '/bin/bash' }
		).trim();
		return out;
	} catch {
		return '';
	}
}

const gates = [
	{
		name: 'no prompt() in js/',
		pattern: 'prompt\\(',
		glob: 'js',
	},
	{
		name: 'no force-dark-mode',
		pattern: 'force-dark-mode',
		glob: '.',
		extraArgs:
			'--glob "!scripts/lint-grep-gates.js" --glob "!docs/**" --glob "!**/README.md" --glob "!pm/**"',
	},
	{
		name: 'no English date format in templates',
		pattern: "->format\\('M d, Y'|date\\('M d, Y'",
		glob: 'templates',
	},
	{
		name: 'no OC::$server in templates',
		pattern: 'OC::\\$server',
		glob: 'templates',
	},
	{
		name: 'no REQUEST_URI in templates',
		pattern: 'REQUEST_URI',
		glob: 'templates',
	},
	{
		name: 'no duplicate h1 outside shell/access-denied',
		pattern: '<h1 ',
		glob: 'templates',
		extraArgs: '--glob "!templates/common/page-start.php" --glob "!templates/access-denied.php" --glob "!templates/redirect.php" --glob "!templates/kb/index.php" --glob "!templates/portal/kb.php" --glob "!templates/needs-role.php"',
	},
	{
		name: 'no window.confirm in js',
		pattern: '\\bconfirm\\(',
		glob: 'js',
		extraArgs: '--glob "!js/common/components.js"',
	},
	{
		// Native alert() is a blocking modal — use TicketCheckMessaging via window.tcAlert
		name: 'no window.alert in js',
		pattern: '(?<![A-Za-z0-9_.$])alert\\(',
		glob: 'js',
		extraArgs: '-P --glob "!js/common/messaging.js"',
	},
	{
		name: 'no emoji in js UI',
		pattern: '📎|💾|✅|❌|🔒|⭐|🎉',
		glob: 'js',
	},
	{
		name: 'no English sentence keys in CustomerPortalController l10n',
		pattern: "\\$l->t\\('[A-Z][^']{8,}'\\)",
		glob: 'lib/Controller/CustomerPortalController.php',
	},
	{
		name: 'no raw fetch(OC.generateUrl) in js (use TicketCheckApi)',
		pattern: 'fetch\\(OC\\.generateUrl',
		glob: 'js',
		extraArgs: '--glob "!js/common/api.js"',
	},
	{
		// Templates may only register assets via PageRenderTrait/FrontEndAssetService.
		// Permitted exceptions: layout.guest.php (NC guest shell wrapper) and the
		// no-shell calm-state pages access-denied.php / needs-role.php (each loads
		// only app.css — there is no PageRenderTrait shell on those routes).
		name: 'no Util::addScript/Style in templates',
		pattern: 'Util::add(Script|Style)',
		glob: 'templates',
		extraArgs: '-P --glob "!templates/layout.guest.php" --glob "!templates/access-denied.php" --glob "!templates/needs-role.php"',
	},
	{
		// Templates must use IconCatalog / data-lucide — not hand-rolled SVG.
		name: 'no inline <svg> in templates',
		pattern: '<svg\\b',
		glob: 'templates',
		extraArgs: '--glob "!templates/layout.guest.php"',
	},
	{
		// Guest portal must rely on `tc-*` body/html classes (set by
		// `js/common/nav.js`) — never the legacy `helpdesk-has-nav` or
		// `helpdesk-nav-docked` markers, which are dead code.
		name: 'no helpdesk-has-nav / helpdesk-nav-docked in js',
		pattern: 'helpdesk-has-nav|helpdesk-nav-docked',
		glob: 'js',
	},
	{
		name: 'no body.guest-user helpdesk-nav selectors in app.css',
		pattern: 'body\\.guest-user.*helpdesk-nav',
		glob: 'css/app.css',
	},
	{
		name: 'no legacy NC button primary/secondary in templates',
		pattern: 'class="button (primary|secondary)"',
		glob: 'templates',
	},
	{
		name: 'no legacy NC button primary/secondary in lib',
		pattern: "'button primary'|\"button primary\"|'button secondary'|\"button secondary\"",
		glob: 'lib',
	},
	{
		name: 'no legacy NC button classes in js',
		pattern: "class: 'button",
		glob: 'js',
	},
];

let failed = false;
	for (const gate of gates) {
	const extra = (gate.extraArgs ? gate.extraArgs + ' ' : '') + '--glob "!vendor/**"';
	const hits = rg(gate.pattern, gate.glob, extra);
	if (hits) {
		console.error(`FAIL: ${gate.name}\n${hits}\n`);
		failed = true;
	} else {
		console.log(`OK: ${gate.name}`);
	}
}

process.exit(failed ? 1 : 0);
