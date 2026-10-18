/**
 * check-dates timezone correctness — ticketcheck.
 * Loads the synced canonical module in a vm sandbox; vectors are shared.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import vm from 'node:vm';
import { runTimezoneMatrix } from './check-dates-vectors.mjs';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

function makeInstance(timezone) {
	const attrs = {
		'lang': 'en-US',
		'data-tc-timezone': timezone,
	};
	const document = {
		getElementById: (id) => (id === 'app-content'
			? { getAttribute: (n) => (Object.prototype.hasOwnProperty.call(attrs, n) ? attrs[n] : '') }
			: null),
		documentElement: { getAttribute: () => '', lang: 'en-US' },
	};
	const sandbox = {
		window: {},
		document,
		Intl,
		Date,
		Number,
		Math,
		String,
		console,
		OC: undefined,
		navigator: { language: 'en' },
	};
	sandbox.window = sandbox;
	vm.runInNewContext(read('js/common/check-dates.js'), sandbox, { filename: 'check-dates.js' });
	return sandbox.CheckDates.create({
		prefix: 'tc',
		naiveMode: 'local',
		displayDateStyle: 'numeric',
		attrs: { locale: 'lang', language: 'lang', timezone: 'data-tc-timezone' },
	});
}

runTimezoneMatrix({ assert, describe, it, makeInstance, naiveMode: 'local', dateStyle: 'numeric' });

describe('ticketcheck adapter contract', () => {
	it('dates.js delegates to CheckDates and keeps the TicketCheckDates API', () => {
		const src = read('js/common/dates.js');
		assert.match(src, /window\.CheckDates\.create\(/);
		assert.match(src, /naiveMode: 'local'/);
		for (const key of [
			'formatDisplayDate', 'formatDisplayDateTime', 'formatYearMonth',
			'parseDisplayDateToIso', 'isIsoCalendarDay', 'monthlyLedgerHelpLines',
		]) {
			assert.match(src, new RegExp(key));
		}
	});

	it('check-dates is loaded before common/dates', () => {
		const src = read('lib/Service/FrontEndAssetService.php');
		const iShared = src.indexOf("'common/check-dates'");
		const iDates = src.indexOf("'common/dates'");
		assert.ok(iShared > -1 && iDates > -1);
		assert.ok(iShared < iDates, 'check-dates must load before dates');
	});
});
