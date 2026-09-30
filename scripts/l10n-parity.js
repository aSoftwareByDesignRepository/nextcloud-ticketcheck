#!/usr/bin/env node
/**
 * Fail if en.json and de.json translation keys diverge or are out of order.
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const enPath = path.join(root, 'l10n', 'en.json');
const dePath = path.join(root, 'l10n', 'de.json');

function loadTranslationKeys(file) {
	const raw = fs.readFileSync(file, 'utf8');
	const obj = JSON.parse(raw);
	const bag = obj.translations !== undefined && typeof obj.translations === 'object' ? obj.translations : obj;
	return Object.keys(bag);
}

const enKeys = loadTranslationKeys(enPath);
const deKeys = loadTranslationKeys(dePath);
const enSet = new Set(enKeys);
const deSet = new Set(deKeys);
const onlyEn = [...enSet].filter((k) => !deSet.has(k)).sort();
const onlyDe = [...deSet].filter((k) => !enSet.has(k)).sort();
const orderMismatch = enKeys.length === deKeys.length && enKeys.some((k, i) => k !== deKeys[i]);

if (onlyEn.length === 0 && onlyDe.length === 0 && !orderMismatch) {
	console.log('OK: l10n en.json and de.json have identical key sets and order (' + String(enKeys.length) + ' keys).');
	process.exit(0);
}

console.error('FAIL: l10n key parity (en vs de)\n');
if (onlyEn.length) {
	console.error('Keys in en.json but missing in de.json (' + onlyEn.length + '):');
	onlyEn.forEach((k) => console.error('  ' + k));
	console.error('');
}
if (onlyDe.length) {
	console.error('Keys in de.json but missing in en.json (' + onlyDe.length + '):');
	onlyDe.forEach((k) => console.error('  ' + k));
	console.error('');
}
if (orderMismatch) {
	console.error('Key order mismatch: de.json keys are not in the same order as en.json.');
}
process.exit(1);
