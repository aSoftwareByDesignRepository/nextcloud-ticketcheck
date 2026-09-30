(function () {
	'use strict';

	function pad(n) { return n < 10 ? '0' + n : String(n); }

	/**
	 * BCP 47 language tag for Intl (Nextcloud passes e.g. de, en, en_US → en-US in page-start).
	 */
	function normalizeLang(locale) {
		const s = String(locale || '').replace('_', '-').trim();
		return s || (typeof document !== 'undefined' ? (document.documentElement.lang || 'en') : 'en');
	}

	function isValidYmd(y, mo, d) {
		if (!Number.isFinite(y) || !Number.isFinite(mo) || !Number.isFinite(d)) return false;
		if (mo < 1 || mo > 12 || d < 1 || d > 31) return false;
		const dt = new Date(y, mo - 1, d);
		return dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === d;
	}

	function partsFromIso(iso) {
		const m = String(iso || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
		if (!m) return null;
		const y = Number.parseInt(m[1], 10);
		const mo = Number.parseInt(m[2], 10);
		const d = Number.parseInt(m[3], 10);
		if (!isValidYmd(y, mo, d)) return null;
		return { y, mo, d };
	}

	function isoFromYmd(y, mo, d) {
		if (!isValidYmd(y, mo, d)) return null;
		return y + '-' + pad(mo) + '-' + pad(d);
	}

	function isoDate(date) {
		const d = (date instanceof Date) ? date : new Date(date);
		if (Number.isNaN(d.getTime())) return '';
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function currentYearMonth() {
		const d = new Date();
		return d.getFullYear() + '-' + pad(d.getMonth() + 1);
	}

	function clampYearMonth(value, min, max) {
		if (!/^[0-9]{4}-(0[1-9]|1[0-2])$/.test(String(value || ''))) {
			return null;
		}
		if (min && value < min) return min;
		if (max && value > max) return max;
		return value;
	}

	/**
	 * Human-readable month label (e.g. "Mai 2026" for de) — not a numeric-only day string.
	 */
	function formatYearMonth(value, locale) {
		if (!/^[0-9]{4}-(0[1-9]|1[0-2])$/.test(String(value || ''))) return String(value || '');
		const [y, m] = value.split('-').map(Number);
		const date = new Date(y, m - 1, 1);
		const lang = normalizeLang(locale);
		try {
			return new Intl.DateTimeFormat(lang, { year: 'numeric', month: 'long' }).format(date);
		} catch (_) {
			return value;
		}
	}

	/**
	 * Format a calendar day for display (tables, summaries). Uses local calendar fields from ISO date
	 * to avoid UTC off-by-one for YYYY-MM-DD strings.
	 * German (de*): typically dd.mm.yyyy via Intl.
	 */
	function formatDisplayDate(iso, locale) {
		const p = partsFromIso(iso);
		if (!p) return String(iso || '');
		const lang = normalizeLang(locale);
		const dt = new Date(p.y, p.mo - 1, p.d);
		try {
			return new Intl.DateTimeFormat(lang, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(dt);
		} catch (_) {
			return pad(p.d) + '.' + pad(p.mo) + '.' + p.y;
		}
	}

	/**
	 * Format API timestamps for tables (MySQL-style "YYYY-MM-DD HH:mm:ss", or ISO 8601 with T / Z).
	 * Uses the account locale; treats space-separated datetimes as **local** wall time (same as PHP string → DateTime default).
	 */
	function formatDisplayDateTime(raw, locale) {
		const s = String(raw || '').trim();
		if (s === '') return '';
		const lang = normalizeLang(locale);
		let dt = null;
		if (/^\d{4}-\d{2}-\d{2}T/.test(s) || s.endsWith('Z') || /[+-]\d{2}:?\d{2}$/.test(s)) {
			const parsed = new Date(s);
			if (!Number.isNaN(parsed.getTime())) dt = parsed;
		}
		if (!dt) {
			const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
			if (!m) return s;
			const y = Number.parseInt(m[1], 10);
			const mo = Number.parseInt(m[2], 10) - 1;
			const d = Number.parseInt(m[3], 10);
			const h = Number.parseInt(m[4], 10);
			const mi = Number.parseInt(m[5], 10);
			const sec = m[6] ? Number.parseInt(m[6], 10) : 0;
			dt = new Date(y, mo, d, h, mi, sec);
		}
		if (!dt || Number.isNaN(dt.getTime())) return s;
		try {
			return new Intl.DateTimeFormat(lang, {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
			}).format(dt);
		} catch (_) {
			return s;
		}
	}

	/** @deprecated use formatDisplayDate — kept for older bundles */
	function formatIso(iso, locale) {
		return formatDisplayDate(iso, locale);
	}

	function isEnUs(langLower) {
		return langLower === 'en-us' || langLower.startsWith('en-us-');
	}

	/**
	 * Pattern hint for visible help text (not a live mask). Matches parse rules below.
	 */
	function expectedFormatPattern(locale) {
		const lang = normalizeLang(locale).toLowerCase();
		if (lang.startsWith('de')) return 'dd.mm.yyyy';
		if (isEnUs(lang)) return 'mm/dd/yyyy';
		if (lang.startsWith('en')) return 'dd/mm/yyyy';
		return 'yyyy-mm-dd';
	}

	/**
	 * Parse user-entered calendar day to ISO yyyy-mm-dd. Empty string → ''.
	 * Accepts canonical ISO. For ambiguous separators, locale rules apply (en-US = m/d/y; most other = d/m/y).
	 * Dot-separated d.m.y is accepted for European locales (including de); not for en-US.
	 */
	function parseDisplayDateToIso(raw, locale) {
		const s = String(raw || '').trim();
		if (s === '') return '';
		if (partsFromIso(s)) return s;

		const lang = normalizeLang(locale).toLowerCase();
		const dot = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
		if (dot) {
			if (isEnUs(lang)) return null;
			const d = Number(dot[1]);
			const mo = Number(dot[2]);
			const y = Number(dot[3]);
			return isoFromYmd(y, mo, d);
		}
		const slash = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
		if (slash) {
			const a = Number(slash[1]);
			const b = Number(slash[2]);
			const y = Number(slash[3]);
			if (isEnUs(lang)) return isoFromYmd(y, a, b);
			return isoFromYmd(y, b, a);
		}
		const dash = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
		if (dash) {
			const a = Number(dash[1]);
			const b = Number(dash[2]);
			const y = Number(dash[3]);
			if (lang.startsWith('de')) return isoFromYmd(y, b, a);
			if (isEnUs(lang)) return isoFromYmd(y, a, b);
			return isoFromYmd(y, b, a);
		}
		return null;
	}

	/** True when `s` is a valid Gregorian calendar day in ISO yyyy-mm-dd form (wire value for `input type="date"`). */
	function isIsoCalendarDay(s) {
		return partsFromIso(s) !== null;
	}

	/**
	 * Bare two-letter `lang` (e.g. `de`) makes Chromium use en-US-style date fields in some builds;
	 * map to a default region (e.g. `de-DE`). Must stay aligned with `LocaleFormatService::canonicalHtmlLangFromLocaleString`.
	 */
	function enrichTemporalHtmlLang(tag) {
		const t = String(tag || '').replace(/_/g, '-').trim();
		if (!t) return 'de-DE';
		const parts = t.split('-');
		const n = parts.length;
		if (n >= 2 && parts[1].length === 2 && /^[A-Za-z]{2}$/.test(parts[1])) {
			return parts[0].toLowerCase() + '-' + parts[1].toUpperCase();
		}
		if (n >= 3 && parts[2].length === 2 && /^[A-Za-z]{2}$/.test(parts[2])) {
			return t;
		}
		const base = parts[0].toLowerCase();
		const map = {
			de: 'de-DE', fr: 'fr-FR', it: 'it-IT', es: 'es-ES', nl: 'nl-NL', pl: 'pl-PL', pt: 'pt-PT',
			sv: 'sv-SE', da: 'da-DK', fi: 'fi-FI', cs: 'cs-CZ', sk: 'sk-SK', hu: 'hu-HU', ro: 'ro-RO',
			tr: 'tr-TR', ru: 'ru-RU', uk: 'uk-UA', el: 'el-GR', en: 'en-GB', ja: 'ja-JP', ko: 'ko-KR', zh: 'zh-CN',
			nb: 'nb-NO', nn: 'nb-NO',
		};
		return map[base] || t;
	}

	/**
	 * BCP 47 tag for native date/month pickers: prefer #app-content[lang] (Nextcloud account language from page-start),
	 * then documentElement, then normalizeLocale argument. Always pass through enrichTemporalHtmlLang().
	 */
	function resolveTemporalInputLang(locale) {
		const app = typeof document !== 'undefined' ? document.getElementById('app-content') : null;
		const fromApp = app && app.getAttribute('lang');
		if (fromApp && String(fromApp).trim() !== '') return enrichTemporalHtmlLang(String(fromApp).trim());
		const fromDoc = typeof document !== 'undefined' ? document.documentElement.getAttribute('lang') : '';
		if (fromDoc && String(fromDoc).trim() !== '') return enrichTemporalHtmlLang(normalizeLang(fromDoc));
		return enrichTemporalHtmlLang(normalizeLang(locale));
	}

	/** Set `lang` on one native calendar control so the UA formats the widget for the Nextcloud account locale. */
	function applyLocaleToTemporalInput(input, locale) {
		if (!input || (input.type !== 'date' && input.type !== 'month')) return;
		input.setAttribute('lang', resolveTemporalInputLang(locale));
	}

	/** Apply to all date/month inputs under root (e.g. #app-content or modal dialog). */
	function applyLocaleToTemporalInputs(root, locale) {
		if (!root || !root.querySelectorAll) return;
		root.querySelectorAll('input[type="date"], input[type="month"]').forEach((el) => {
			applyLocaleToTemporalInput(el, locale);
		});
	}

	function compareYearMonth(a, b) {
		if (!a || !b) return 0;
		return a < b ? -1 : (a > b ? 1 : 0);
	}

	/**
	 * Human copy for month pickers: workspace booking span + selected month activity.
	 * Expects `summary.ledgerYearMonthSpan` and optional `summary.monthLedger` from monthly-summary or list-budgets (project).
	 *
	 * @param {*} summary
	 * @param {string} yearMonth
	 * @param {string} htmlLang
	 * @returns {{ spanLine: string, monthLine: string }}
	 */
	function monthlyLedgerHelpLines(summary, yearMonth, htmlLang) {
		const tr = typeof window.t === 'function' ? window.t : function (_, s) { return s; };
		const APP = 'ticketcheck';
		if (!summary || !summary.ledgerYearMonthSpan) {
			return { spanLine: '', monthLine: '' };
		}
		const span = summary.ledgerYearMonthSpan;
		const ml = summary.monthLedger || {};
		const count = typeof ml.transactionCount === 'number' ? ml.transactionCount : 0;
		const first = span.firstYearMonth || null;
		const last = span.lastYearMonth || null;
		const ymOk = yearMonth && /^\d{4}-(0[1-9]|1[0-2])$/.test(yearMonth);

		let spanLine = '';
		if (first && last) {
			/* Same calendar month twice ("May … through May …") adds nothing next to a single-month picker. */
			if (first !== last) {
				spanLine = tr(APP, 'Ledger bookings in this workspace run from {monthFrom} through {monthTo}.')
					.replace('{monthFrom}', formatYearMonth(first, htmlLang))
					.replace('{monthTo}', formatYearMonth(last, htmlLang));
			}
		} else {
			spanLine = tr(APP, 'No ledger transactions yet—each month is empty until you post one.');
		}

		if (!ymOk) {
			let monthLine = '';
			if (count === 0) {
				monthLine = tr(APP, 'No bookings in this date range yet.');
			} else {
				monthLine = tr(APP, '{count} bookings fall into this date range. Pick a calendar month to focus one month.')
					.replace('{count}', String(count));
			}
			return { spanLine: spanLine, monthLine: monthLine };
		}

		const monthLabel = formatYearMonth(yearMonth, htmlLang);

		let monthLine = '';
		if (count === 0) {
			if (first && compareYearMonth(yearMonth, first) < 0) {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. That calendar month is before your first ledger booking ({monthFrom}).')
					.replace('{monthLabel}', monthLabel)
					.replace('{monthFrom}', formatYearMonth(first, htmlLang));
			} else if (last && compareYearMonth(yearMonth, last) > 0) {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. That calendar month is after your latest ledger booking ({monthTo}).')
					.replace('{monthLabel}', monthLabel)
					.replace('{monthTo}', formatYearMonth(last, htmlLang));
			} else {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. You can still plan budgets and use month tools.')
					.replace('{monthLabel}', monthLabel);
			}
		} else if (count === 1) {
			monthLine = tr(APP, 'One booking in the ledger for {monthLabel}.').replace('{monthLabel}', monthLabel);
		} else {
			monthLine = tr(APP, '{count} bookings in the ledger for {monthLabel}.')
				.replace('{count}', String(count))
				.replace('{monthLabel}', monthLabel);
		}
		return { spanLine: spanLine, monthLine: monthLine };
	}

	window.TicketCheckDates = {
		normalizeLang,
		isoDate,
		currentYearMonth,
		clampYearMonth,
		formatYearMonth,
		formatDisplayDate,
		formatDisplayDateTime,
		formatIso,
		parseDisplayDateToIso,
		expectedFormatPattern,
		isIsoCalendarDay,
		resolveTemporalInputLang,
		enrichTemporalHtmlLang,
		applyLocaleToTemporalInput,
		applyLocaleToTemporalInputs,
		compareYearMonth,
		monthlyLedgerHelpLines,
	};
})();
