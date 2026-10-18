/**
 * Shared check-dates test vectors — SYNCED FILE.
 * Canonical: scripts/check-dates/check-dates-vectors.mjs.
 *
 * Each app ships a thin test file that loads its synced
 * js/common/check-dates.js in a vm sandbox and calls
 *   runTimezoneMatrix({ assert, makeInstance })
 * where makeInstance(timezone) returns a CheckDates instance bound to a
 * stub document carrying data-locale='en-US', data-timezone=timezone.
 */

export const MATRIX_TZ = [
	'UTC',
	'America/New_York',     // UTC-4/5 — the reported failure zone
	'Pacific/Kiritimati',   // UTC+14 — max positive offset
	'Asia/Kathmandu',       // UTC+5:45 — odd offset
	'Australia/Sydney',     // DST boundaries
];

export const CALENDAR_DAYS = [
	'2026-10-20', // reported case
	'2026-01-01', // year boundary
	'2026-03-31', // month boundary
	'2026-10-04', // Sydney spring-forward day
	'2026-04-05', // Sydney fall-back day
	'2024-02-29', // leap day
];

/**
 * Golden full-date render for a UTC-anchored calendar day, at the app's
 * displayDateStyle. Comparing the whole string (not a day substring) keeps
 * the assertion sound in numeric locales where month and day-1 coincide
 * (e.g. "04/05/2026").
 */
function goldenDate(iso, locale, dateStyle) {
	const p = iso.split('-').map(Number);
	const anchor = new Date(Date.UTC(p[0], p[1] - 1, p[2], 12));
	const opts = dateStyle === 'numeric'
		? { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }
		: { dateStyle: 'medium', timeZone: 'UTC' };
	return new Intl.DateTimeFormat(locale, opts).format(anchor);
}

/**
 * @param {object} t
 * @param {*} t.assert node:assert/strict
 * @param {*} t.describe node:test describe
 * @param {*} t.it node:test it
 * @param {(tz: string) => object} t.makeInstance CheckDates instance bound to a stub document at that TZ
 * @param {string} [t.naiveMode] expected naiveMode of the app under test
 * @param {string} [t.dateStyle] 'medium' (default) or 'numeric' — must match the app's displayDateStyle
 */
export function runTimezoneMatrix({ assert, describe, it, makeInstance, naiveMode, dateStyle }) {
	describe('check-dates calendar-day invariant', () => {
		for (const tz of MATRIX_TZ) {
			it(`date-only renders the same day in ${tz}`, () => {
				const d = makeInstance(tz);
				for (const iso of CALENDAR_DAYS) {
					const out = d.formatDisplayDate(iso);
					const expected = goldenDate(iso, 'en-US', dateStyle);
					assert.equal(out, expected, `${tz}: ${iso} → "${out}" should equal "${expected}"`);
				}
			});
		}

		it('isoForDateInput passes date-only values through verbatim in every TZ', () => {
			for (const tz of MATRIX_TZ) {
				const d = makeInstance(tz);
				assert.equal(d.isoForDateInput('2026-10-20'), '2026-10-20');
			}
		});

		it('instants still shift days across zones (regression guard)', () => {
			const ny = makeInstance('America/New_York');
			const kiribati = makeInstance('Pacific/Kiritimati');
			// 2026-10-20T00:30:00Z is Oct 19 20:30 in NY (UTC-4) and Oct 20 14:30 in Kiritimati (UTC+14).
			assert.ok(ny.formatDisplayDateTime('2026-10-20T00:30:00Z').includes('19'));
			assert.ok(kiribati.formatDisplayDateTime('2026-10-20T00:30:00Z').includes('20'));
		});

		it('formatYearMonth keeps the calendar month in every TZ', () => {
			for (const tz of MATRIX_TZ) {
				const d = makeInstance(tz);
				const out = d.formatYearMonth('2026-01');
				assert.match(out, /January|Jan/i);
				assert.match(out, /2026/);
			}
		});

		it('formatWeekday follows the calendar day, not a TZ edge', () => {
			for (const tz of MATRIX_TZ) {
				const d = makeInstance(tz);
				// 2026-10-19 is a Monday everywhere.
				assert.match(d.formatWeekday('2026-10-19'), /monday/i);
			}
		});

		it('garbage in → no throw, sane fallback', () => {
			const d = makeInstance('America/New_York');
			assert.equal(d.formatDisplayDate(null), '');
			assert.equal(d.formatDisplayDate(''), '');
			assert.doesNotThrow(() => d.formatDisplayDateTime('not a date'));
			assert.equal(d.isoForDateInput('garbage'), '');
		});

		it('classifyTemporal separates the three temporal kinds', () => {
			const d = makeInstance('UTC');
			assert.equal(d.classifyTemporal('2026-10-20'), 'date');
			assert.equal(d.classifyTemporal('2026-10'), 'month');
			assert.equal(d.classifyTemporal('2026-10-20T08:00:00Z'), 'instant');
			assert.equal(d.classifyTemporal('2026-10-20 08:00:00'), 'naive');
			assert.equal(d.classifyTemporal(null), 'invalid');
		});

		it('pins the app naiveMode contract', () => {
			if (!naiveMode) return;
			const d = makeInstance('America/New_York');
			assert.equal(d.naiveMode, naiveMode);
			const instant = d.toInstant('2026-10-20 12:00:00');
			assert.ok(instant instanceof Date);
			if (naiveMode === 'utc') {
				assert.equal(instant.toISOString(), '2026-10-20T12:00:00.000Z');
			} else {
				// local wall time: naive fields are anchored to the *environment*
				// TZ (legacy `new Date('YYYY-MM-DDTHH:mm:ss')` contract), so they
				// must render back identically when formatted without an
				// explicit timeZone — regardless of which TZ the process runs in.
				const parts = new Intl.DateTimeFormat('en-US', {
					year: 'numeric', month: '2-digit', day: '2-digit',
					hour: '2-digit', minute: '2-digit', hour12: false,
				}).formatToParts(instant);
				const get = (t) => parts.find((p) => p.type === t).value;
				assert.equal(`${get('year')}-${get('month')}-${get('day')}`, '2026-10-20');
				assert.equal(`${get('hour')}:${get('minute')}`, '12:00');
			}
		});
	});
}
