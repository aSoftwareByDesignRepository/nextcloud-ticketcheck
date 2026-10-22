/**
 * CheckDates — canonical date/time helpers for the *check app family.
 *
 * Canonical source: scripts/check-dates/ (workspace home for shared modules;
 * apps/_shared is tooling-internal). Distributed verbatim into each app's
 * js/common/check-dates.js by sync_check_dates.py.
 *
 * THE INVARIANT (why this module exists — "pick N, see N-1" report):
 *
 *   - 'YYYY-MM-DD' is a *zoneless calendar day*. new Date('YYYY-MM-DD')
 *     parses as UTC midnight; formatting that instant in the account
 *     timezone shifts it a day earlier for every UTC-negative user
 *     (America/*, etc.). Date-only values are therefore never routed
 *     through Date-at-local-TZ: they are field-extracted and rendered
 *     anchored at noon UTC with timeZone:'UTC'.
 *
 *   - Strings carrying a time component ('T', trailing 'Z', or a numeric
 *     ±hh:mm offset) are *real instants* — new Date() + account timezone.
 *
 *   - Naive 'YYYY-MM-DD HH:mm:ss' (space, no zone marker) follows the
 *     per-app server contract via `naiveMode`: 'utc' (mobilitycheck,
 *     deskcheck — server emits UTC SQL) or 'local' (ticketcheck,
 *     budgetcheck — naive wall time matches PHP string → DateTime
 *     default). Never mix the two; it is pinned by tests.
 */
(function () {
	'use strict';

	function pad2(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function isValidYmd(y, mo, d) {
		if (!Number.isFinite(y) || !Number.isFinite(mo) || !Number.isFinite(d)) return false;
		if (mo < 1 || mo > 12 || d < 1 || d > 31) return false;
		const dt = new Date(Date.UTC(y, mo - 1, d));
		return dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d;
	}

	function partsFromIsoDate(iso) {
		const m = String(iso || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
		if (!m) return null;
		const y = Number.parseInt(m[1], 10);
		const mo = Number.parseInt(m[2], 10);
		const d = Number.parseInt(m[3], 10);
		if (!isValidYmd(y, mo, d)) return null;
		return { y, mo, d };
	}

	function isIsoDateOnly(value) {
		return partsFromIsoDate(value) !== null;
	}

	function isoFromYmd(y, mo, d) {
		if (!isValidYmd(y, mo, d)) return null;
		return y + '-' + pad2(mo) + '-' + pad2(d);
	}

	const RE_NAIVE = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/;
	const RE_INSTANT = /T\d{2}:\d{2}|Z$|[+-]\d{2}:?\d{2}$/;
	const RE_MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

	/**
	 * 'date' | 'month' | 'instant' | 'naive' | 'other'
	 * 'other' covers Date objects, epoch numbers, and non-empty strings the
	 * engine may still parse (e.g. RFC strings) — treat as instant attempt.
	 */
	function classifyTemporal(value) {
		if (value === null || value === undefined || value === '') return 'invalid';
		if (value instanceof Date) return Number.isNaN(value.getTime()) ? 'invalid' : 'instant';
		if (typeof value === 'number') return Number.isFinite(value) ? 'instant' : 'invalid';
		const s = String(value).trim();
		if (s === '') return 'invalid';
		if (partsFromIsoDate(s)) return 'date';
		if (RE_MONTH.test(s)) return 'month';
		if (RE_INSTANT.test(s)) return 'instant';
		if (RE_NAIVE.test(s)) return 'naive';
		return 'other';
	}

	/** Noon-UTC anchor for a calendar day — formatting with timeZone:'UTC' is day-exact, and noon never hits a DST-nonexistent local midnight. */
	function calendarDayAnchor(y, mo, d) {
		return new Date(Date.UTC(y, mo - 1, d, 12, 0, 0));
	}

	function isEnUs(langLower) {
		return langLower === 'en-us' || langLower.indexOf('en-us-') === 0;
	}

	const DEFAULT_LANG_REGION = {
		de: 'de-DE', fr: 'fr-FR', it: 'it-IT', es: 'es-ES', nl: 'nl-NL', pl: 'pl-PL', pt: 'pt-PT',
		sv: 'sv-SE', da: 'da-DK', fi: 'fi-FI', cs: 'cs-CZ', sk: 'sk-SK', hu: 'hu-HU', ro: 'ro-RO',
		tr: 'tr-TR', ru: 'ru-RU', uk: 'uk-UA', el: 'el-GR', en: 'en-GB', ja: 'ja-JP', ko: 'ko-KR',
		zh: 'zh-CN', nb: 'nb-NO', nn: 'nb-NO',
	};

	/**
	 * Bare two-letter lang ('de') makes Chromium render en-US-style date
	 * fields in some builds; map to a default region ('de-DE').
	 */
	function enrichTemporalHtmlLang(tag) {
		const t = String(tag || '').replace(/_/g, '-').trim();
		if (!t) return 'de-DE';
		const parts = t.split('-');
		if (parts.length >= 2 && parts[1].length === 2 && /^[A-Za-z]{2}$/.test(parts[1])) {
			return parts[0].toLowerCase() + '-' + parts[1].toUpperCase();
		}
		if (parts.length >= 3 && parts[2].length === 2 && /^[A-Za-z]{2}$/.test(parts[2])) {
			return t;
		}
		const base = parts[0].toLowerCase();
		return DEFAULT_LANG_REGION[base] || t;
	}

	/**
	 * @param {object} opts
	 *   rootId         element carrying the data attrs (default 'app-content')
	 *   prefix         CSS class prefix for the input wrapper ('dc' → .dc-date-field)
	 *   attrs          attr names on the root element:
	 *                  { locale:'data-locale', language:'data-language',
	 *                    timezone:'data-timezone', firstDay:'data-first-day-of-week',
	 *                    time24h:'data-check-time-24h', timeInputLang:'data-check-time-input-lang' }
	 *   naiveMode      'utc' | 'local' — contract for naive 'YYYY-MM-DD HH:mm:ss'
	 *   displayDateStyle 'medium' (Intl dateStyle) | 'numeric' (dd.mm.yyyy-style fields)
	 *   hour24         boolean | () => boolean — force 24h time formatting
	 */
	function create(opts) {
		const o = opts || {};
		const rootId = o.rootId || 'app-content';
		const prefix = o.prefix || 'check';
		const naiveMode = o.naiveMode === 'local' ? 'local' : 'utc';
		const dateStyle = o.displayDateStyle === 'numeric' ? 'numeric' : 'medium';
		const attrs = Object.assign({
			locale: 'data-locale',
			language: 'data-language',
			timezone: 'data-timezone',
			firstDay: 'data-first-day-of-week',
			time24h: 'data-check-time-24h',
			timeInputLang: 'data-check-time-input-lang',
		}, o.attrs || {});

		function doc() {
			return typeof document !== 'undefined' ? document : null;
		}

		function rootEl() {
			const d = doc();
			return d ? d.getElementById(rootId) : null;
		}

		function htmlAttr(name, fallback) {
			const el = rootEl();
			const fromRoot = el ? el.getAttribute(name) : '';
			if (fromRoot && String(fromRoot).trim() !== '') return String(fromRoot).trim();
			const d = doc();
			const html = d ? d.documentElement : null;
			const value = html ? html.getAttribute(name) : '';
			return value && String(value).trim() !== '' ? String(value).trim() : fallback;
		}

		function normalizeLang(locale) {
			const s = String(locale || '').replace('_', '-').trim();
			if (s) return s;
			const d = doc();
			return (d && d.documentElement.lang) || 'en';
		}

		function currentLocale() {
			const v = htmlAttr(attrs.locale, '');
			if (v) return v;
			if (typeof OC !== 'undefined' && OC.getLocale) {
				const oc = OC.getLocale();
				if (oc) return String(oc).replace('_', '-');
			}
			return (typeof navigator !== 'undefined' && navigator.language) || 'en';
		}

		function currentLanguage() {
			const v = htmlAttr(attrs.language, '');
			if (v) return v;
			const lang = htmlAttr('lang', '');
			if (lang) return lang;
			if (typeof OC !== 'undefined' && OC.getLanguage) {
				const oc = OC.getLanguage();
				if (oc) return String(oc).replace('_', '-');
			}
			return 'en';
		}

		function currentFirstDayOfWeek() {
			const n = Number(htmlAttr(attrs.firstDay, ''));
			if (Number.isInteger(n) && n >= 0 && n <= 6) return n;
			try {
				const Info = Intl.Locale;
				if (typeof Info === 'function') {
					const weekInfo = new Info(currentLocale()).weekInfo;
					if (weekInfo && typeof weekInfo.firstDay === 'number') {
						return weekInfo.firstDay === 7 ? 0 : weekInfo.firstDay;
					}
				}
			} catch (e) { /* fall through */ }
			return 1;
		}

		function currentTimezone() {
			const tz = htmlAttr(attrs.timezone, '');
			if (tz) return tz;
			try {
				return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
			} catch (e) {
				return 'UTC';
			}
		}

		function use24HourTimeInputs() {
			if (typeof o.hour24 === 'function') return !!o.hour24();
			if (typeof o.hour24 === 'boolean') return o.hour24;
			return htmlAttr(attrs.time24h, '') === '1';
		}

		function timeInputLocale() {
			const explicit = htmlAttr(attrs.timeInputLang, '');
			if (explicit) return explicit;
			return use24HourTimeInputs() ? 'en-GB' : currentLocale();
		}

		/* ---------- parsing ---------- */

		/**
		 * Instant-only parse. Returns null for date-only/month values —
		 * callers must classify first (calendar values are not instants).
		 */
		function toInstant(value) {
			const kind = classifyTemporal(value);
			if (kind === 'instant') {
				const d = value instanceof Date ? value : (typeof value === 'number' ? new Date(value) : new Date(String(value).trim()));
				return Number.isNaN(d.getTime()) ? null : d;
			}
			if (kind === 'naive') {
				const s = String(value).trim();
				if (naiveMode === 'utc') {
					const d = new Date(s.replace(' ', 'T') + (s.length === 16 ? ':00' : '') + 'Z');
					return Number.isNaN(d.getTime()) ? null : d;
				}
				const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
				if (!m) return null;
				const d = new Date(
					Number(m[1]), Number(m[2]) - 1, Number(m[3]),
					Number(m[4]), Number(m[5]), m[6] ? Number(m[6]) : 0,
				);
				return Number.isNaN(d.getTime()) ? null : d;
			}
			if (kind === 'other') {
				const d = new Date(String(value).trim());
				return Number.isNaN(d.getTime()) ? null : d;
			}
			return null; // 'date', 'month', 'invalid'
		}

		/**
		 * Calendar-day anchor (noon UTC) for date-only input; null otherwise.
		 * Render it ONLY with timeZone:'UTC' — never local fields.
		 */
		function toCalendarAnchor(value) {
			const p = partsFromIsoDate(value);
			return p ? calendarDayAnchor(p.y, p.mo, p.d) : null;
		}

		/* ---------- display formatters ---------- */

		function fmt(locale, options, date) {
			try {
				return new Intl.DateTimeFormat(locale, options).format(date);
			} catch (e) {
				return date.toISOString().slice(0, 10); // tz-audit-ok: noon-UTC anchor, UTC-day extraction is the contract
			}
		}

		function dateOptions() {
			return dateStyle === 'numeric'
				? { day: '2-digit', month: '2-digit', year: 'numeric' }
				: { dateStyle: 'medium' };
		}

		/**
		 * Calendar-day-safe date formatter. Date-only input renders the same
		 * y/m/d in every timezone; instants render in the account timezone.
		 */
		function formatDisplayDate(value, localeOverride) {
			const kind = classifyTemporal(value);
			const loc = normalizeLang(localeOverride || currentLocale());
			if (kind === 'date') {
				const anchor = toCalendarAnchor(value);
				const opts = Object.assign({ timeZone: 'UTC' }, dateOptions());
				return fmt(loc, opts, anchor);
			}
			if (kind === 'invalid') return '';
			const d = toInstant(value);
			if (!d) return String(value || '');
			const opts = Object.assign({ timeZone: currentTimezone() }, dateOptions());
			return fmt(loc, opts, d);
		}

		function formatDisplayDateTime(value, localeOverride) {
			const kind = classifyTemporal(value);
			const loc = normalizeLang(localeOverride || currentLocale());
			if (kind === 'date') return formatDisplayDate(value, loc);
			if (kind === 'invalid') return '';
			const d = toInstant(value);
			if (!d) return String(value || '');
			const base = { timeZone: currentTimezone() };
			const opts = use24HourTimeInputs()
				? Object.assign(base, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
				: Object.assign(base, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
			try {
				return new Intl.DateTimeFormat(loc, opts).format(d);
			} catch (e) {
				return d.toISOString().replace('T', ' ').slice(0, 16);
			}
		}

		function formatDisplayTime(value, localeOverride) {
			const d = toInstant(value);
			if (!d) return '';
			const loc = normalizeLang(localeOverride || currentLocale());
			const opts = { timeZone: currentTimezone(), hour: '2-digit', minute: '2-digit' };
			if (use24HourTimeInputs()) opts.hour12 = false;
			try {
				return new Intl.DateTimeFormat(loc, opts).format(d);
			} catch (e) {
				return d.toISOString().slice(11, 16);
			}
		}

		function formatYearMonth(value, localeOverride) {
			const s = String(value || '').trim();
			const loc = normalizeLang(localeOverride || currentLocale());
			let anchor = null;
			const m = s.match(/^(\d{4})-(0[1-9]|1[0-2])$/);
			if (m) {
				anchor = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, 1));
			} else {
				const anchorDate = toCalendarAnchor(s);
				if (anchorDate) anchor = new Date(Date.UTC(anchorDate.getUTCFullYear(), anchorDate.getUTCMonth(), 1));
			}
			if (anchor) {
				return fmt(loc, { month: 'long', year: 'numeric', timeZone: 'UTC' }, anchor);
			}
			const d = toInstant(s);
			if (!d) return String(value || '');
			return fmt(loc, { month: 'long', year: 'numeric', timeZone: currentTimezone() }, d);
		}

		/** Weekday follows the calendar day (noon-UTC anchor), named in the UI language. */
		function formatWeekday(value, localeOverride) {
			const kind = classifyTemporal(value);
			let anchor = null;
			if (kind === 'date') {
				anchor = toCalendarAnchor(value);
			} else {
				const d = toInstant(value);
				if (d) anchor = d;
			}
			if (!anchor) return '';
			const loc = normalizeLang(localeOverride || currentLanguage());
			const opts = { weekday: 'long' };
			if (kind === 'date') opts.timeZone = 'UTC';
			else opts.timeZone = currentTimezone();
			try {
				return new Intl.DateTimeFormat(loc, opts).format(anchor);
			} catch (e) {
				return '';
			}
		}

		/** Compact range: same-day → "date · t – t". */
		function formatDateTimeRange(start, end, localeOverride) {
			const s = toInstant(start);
			const e = toInstant(end);
			if (!s && !e) return '';
			if (!s) return formatDisplayDateTime(end, localeOverride);
			if (!e) return formatDisplayDateTime(start, localeOverride);
			if (isoForDateInput(start) === isoForDateInput(end)) {
				return formatDisplayDate(start, localeOverride) + ' · '
					+ formatDisplayTime(start, localeOverride) + ' – ' + formatDisplayTime(end, localeOverride);
			}
			return formatDisplayDateTime(start, localeOverride) + ' – ' + formatDisplayDateTime(end, localeOverride);
		}

		/* ---------- iso helpers ---------- */

		/** en-CA parts in a timezone → stable Y-m-d. */
		function isoInZone(date, tz) {
			try {
				const parts = new Intl.DateTimeFormat('en-CA', {
					timeZone: tz,
					year: 'numeric', month: '2-digit', day: '2-digit',
				}).formatToParts(date);
				const map = {};
				parts.forEach((p) => { map[p.type] = p.value; });
				return `${map.year}-${map.month}-${map.day}`;
			} catch (e) {
				return date.toISOString().slice(0, 10); // tz-audit-ok: fallback extracts the UTC day of a noon-UTC-anchored calendar date
			}
		}

		/** ISO for <input type=date>. Date-only passes through verbatim; instants resolve to the account-TZ day. */
		function isoForDateInput(value, tzOverride) {
			const kind = classifyTemporal(value);
			if (kind === 'date') return String(value).trim();
			if (kind === 'invalid') return '';
			const d = toInstant(value);
			return d ? isoInZone(d, tzOverride || currentTimezone()) : '';
		}

		/** 'YYYY-MM-DDTHH:mm' for <input type=datetime-local> (browser-local wall time). */
		function isoForDateTimeInput(value) {
			const d = toInstant(value);
			if (!d) return '';
			return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
				+ 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
		}

		/** datetime-local value (browser-local wall time) → UTC ISO instant. */
		function fromDateTimeLocalInput(value) {
			if (!value) return '';
			const d = new Date(value);
			if (Number.isNaN(d.getTime())) return '';
			return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate())
				+ 'T' + pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes()) + ':' + pad2(d.getUTCSeconds()) + 'Z';
		}

		/** Local wall 'YYYY-MM-DD' + 'HH:mm' in account TZ → UTC SQL 'YYYY-MM-DD HH:mm:ss'. */
		function localToUtcSql(dateStr, timeStr, tzOverride) {
			if (!dateStr) return '';
			const p = partsFromIsoDate(dateStr);
			if (!p) return '';
			const tm = String(timeStr || '00:00').match(/^(\d{1,2}):(\d{2})/);
			const hh = tm ? Math.min(23, Number(tm[1])) : 0;
			const mm = tm ? Math.min(59, Number(tm[2])) : 0;
			const guess = new Date(Date.UTC(p.y, p.mo - 1, p.d, hh, mm, 0));
			const tz = tzOverride || currentTimezone();
			const parts = new Intl.DateTimeFormat('en-US', {
				timeZone: tz,
				year: 'numeric', month: '2-digit', day: '2-digit',
				hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
			}).formatToParts(guess);
			const get = (t) => Number((parts.find((x) => x.type === t) || {}).value || 0);
			const asUtcLike = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour') % 24, get('minute'), get('second'));
			const desired = Date.UTC(p.y, p.mo - 1, p.d, hh, mm, 0);
			const corrected = new Date(guess.getTime() + (desired - asUtcLike));
			return corrected.getUTCFullYear() + '-' + pad2(corrected.getUTCMonth() + 1) + '-' + pad2(corrected.getUTCDate())
				+ ' ' + pad2(corrected.getUTCHours()) + ':' + pad2(corrected.getUTCMinutes()) + ':' + pad2(corrected.getUTCSeconds());
		}

		function todayIsoDate() {
			return isoInZone(new Date(), currentTimezone());
		}

		function currentYearMonth() {
			const d = new Date();
			return d.getFullYear() + '-' + pad2(d.getMonth() + 1);
		}

		function isoDate(value) {
			const d = value instanceof Date ? value : new Date(value);
			if (Number.isNaN(d.getTime())) return '';
			return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
		}

		/* ---------- relative ---------- */

		function relative(value, localeOverride) {
			const d = toInstant(value);
			if (!d) return '';
			const diff = d.getTime() - Date.now();
			const abs = Math.abs(diff);
			const units = [
				{ s: 60 * 1000, u: 'minute' },
				{ s: 60 * 60 * 1000, u: 'hour' },
				{ s: 24 * 60 * 60 * 1000, u: 'day' },
				{ s: 7 * 24 * 60 * 60 * 1000, u: 'week' },
				{ s: 30 * 24 * 60 * 60 * 1000, u: 'month' },
				{ s: 365 * 24 * 60 * 60 * 1000, u: 'year' },
			];
			let chosen = units[0];
			for (const u of units) {
				if (abs >= u.s) chosen = u;
			}
			try {
				return new Intl.RelativeTimeFormat(normalizeLang(localeOverride || currentLanguage()), { numeric: 'auto' })
					.format(Math.round(diff / chosen.s), chosen.u);
			} catch (e) {
				return '';
			}
		}

		function formatRelativeMinutes(diffMinutes, localeOverride) {
			if (!Number.isFinite(diffMinutes)) return '';
			try {
				const rtf = new Intl.RelativeTimeFormat(normalizeLang(localeOverride || currentLanguage()), { numeric: 'auto' });
				const minutes = Math.round(diffMinutes);
				if (Math.abs(minutes) < 60) return rtf.format(minutes, 'minute');
				const hours = Math.round(minutes / 60);
				if (Math.abs(hours) < 48) return rtf.format(hours, 'hour');
				const days = Math.round(hours / 24);
				return rtf.format(days, 'day');
			} catch (e) {
				return '';
			}
		}

		/* ---------- wall-clock time helpers ---------- */

		function formatClock24FromTimeString(value) {
			if (value === null || value === undefined || value === '') return '';
			const s = String(value).trim();
			const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
			if (!m) return s;
			const h = Number(m[1]);
			const min = Number(m[2]);
			if (!Number.isInteger(h) || !Number.isInteger(min) || h > 23 || min > 59) return s;
			return pad2(h) + ':' + pad2(min);
		}

		function formatClock24Range(start, end) {
			const a = formatClock24FromTimeString(start);
			const b = formatClock24FromTimeString(end);
			if (!a || !b) return `${start || ''} – ${end || ''}`.trim();
			return `${a} – ${b}`;
		}

		function wallClockMinutesFromTimeString(value) {
			const m = String(value ?? '').trim().match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
			if (!m) return null;
			const h = Number(m[1]);
			const min = Number(m[2]);
			if (!Number.isInteger(h) || !Number.isInteger(min) || h > 23 || min > 59) return null;
			return h * 60 + min;
		}

		function isOvernightWallClockShift(start, end) {
			const a = wallClockMinutesFromTimeString(start);
			const b = wallClockMinutesFromTimeString(end);
			if (a === null || b === null) return false;
			return b < a;
		}

		/* ---------- user-typed date parsing (display → ISO) ---------- */

		function expectedFormatPattern(localeOverride) {
			const lang = normalizeLang(localeOverride || currentLocale()).toLowerCase();
			if (lang.indexOf('de') === 0) return 'dd.mm.yyyy';
			if (isEnUs(lang)) return 'mm/dd/yyyy';
			if (lang.indexOf('en') === 0) return 'dd/mm/yyyy';
			return 'yyyy-mm-dd';
		}

		function parseDisplayDateToIso(raw, localeOverride) {
			const s = String(raw || '').trim();
			if (s === '') return '';
			if (partsFromIsoDate(s)) return s;
			const lang = normalizeLang(localeOverride || currentLocale()).toLowerCase();
			const dot = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
			if (dot) {
				if (isEnUs(lang)) return null;
				return isoFromYmd(Number(dot[3]), Number(dot[2]), Number(dot[1]));
			}
			const slash = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
			if (slash) {
				const a = Number(slash[1]);
				const b = Number(slash[2]);
				const y = Number(slash[3]);
				return isEnUs(lang) ? isoFromYmd(y, a, b) : isoFromYmd(y, b, a);
			}
			const dash = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
			if (dash) {
				const a = Number(dash[1]);
				const b = Number(dash[2]);
				const y = Number(dash[3]);
				if (isEnUs(lang)) return isoFromYmd(y, a, b);
				return isoFromYmd(y, b, a);
			}
			return null;
		}

		function dateInputPlaceholder(localeOverride) {
			const tag = String(localeOverride || enrichTemporalHtmlLang(currentLanguage()) || 'en').toLowerCase().replace('_', '-');
			if (['de', 'nl', 'da', 'nb', 'sv', 'pl', 'it', 'es', 'fr', 'pt', 'fi', 'cs'].some((p) => tag.indexOf(p) === 0)) {
				return 'TT.MM.JJJJ';
			}
			if (/^en-(gb|au|nz|ie)/.test(tag)) return 'dd/mm/yyyy';
			return 'mm/dd/yyyy';
		}

		/* ---------- temporal input chrome ---------- */

		function resolveTemporalInputLang(localeOverride) {
			const el = rootEl();
			const fromApp = el ? el.getAttribute('lang') : '';
			if (fromApp && String(fromApp).trim() !== '') {
				return enrichTemporalHtmlLang(String(fromApp).trim());
			}
			const d = doc();
			const fromDoc = d && d.documentElement ? d.documentElement.getAttribute('lang') : '';
			if (fromDoc && String(fromDoc).trim() !== '') {
				return enrichTemporalHtmlLang(normalizeLang(fromDoc));
			}
			return enrichTemporalHtmlLang(normalizeLang(localeOverride || currentLocale()));
		}

		/* ---------- programmatic-write chrome healing ---------- */

		// input.value = '…' and form.reset() fire NO input/change event —
		// without these hooks a mounted .{p}-date-display overlay keeps its
		// stale caption (placeholder or the previous date) while the real
		// value moved on. Class fix, 2026-10-22 dutycheck intake.
		const dateChromePaints = new WeakMap();
		const patchedValueInputs = new WeakSet();
		const resetWiredForms = new WeakSet();
		const defer = typeof setTimeout === 'function'
			? (fn) => setTimeout(fn, 0)
			: (fn) => fn();

		/** Find the 'value' descriptor on the own → prototype chain. */
		function valueDescriptor(input) {
			let holder = input;
			while (holder) {
				const desc = Object.getOwnPropertyDescriptor(holder, 'value');
				if (desc) return desc;
				holder = Object.getPrototypeOf(holder);
			}
			return null;
		}

		/**
		 * Shadow input.value so programmatic writes repaint like a user
		 * edit. The latest paint closure is looked up at call time, so a
		 * later applyLocaleToTemporalInputs (new locale) re-targets the
		 * same patch. Non-configurable/exotic hosts simply stay unpatched —
		 * repaintDateChrome remains the explicit escape hatch.
		 */
		function patchProgrammaticValueSet(input) {
			if (patchedValueInputs.has(input)) return;
			const desc = valueDescriptor(input);
			if (!desc || desc.configurable === false) return;
			const repaint = (el) => {
				const paint = dateChromePaints.get(el);
				if (paint) paint();
			};
			try {
				if (typeof desc.set === 'function' && typeof desc.get === 'function') {
					Object.defineProperty(input, 'value', {
						configurable: true,
						enumerable: desc.enumerable === true,
						get() { return desc.get.call(this); },
						set(v) { desc.set.call(this, v); repaint(this); },
					});
					patchedValueInputs.add(input);
				} else if (Object.prototype.hasOwnProperty.call(desc, 'value')) {
					let backing = desc.value;
					Object.defineProperty(input, 'value', {
						configurable: true,
						enumerable: desc.enumerable !== false,
						get() { return backing; },
						set(v) { backing = v; repaint(this); },
					});
					patchedValueInputs.add(input);
				}
			} catch (e) { /* host object refused the shadow — helpers still work */ }
		}

		/**
		 * form.reset() reverts every control to its attribute default
		 * without events. One delegated listener per owning form repaints
		 * all chromed date inputs after the default action ran.
		 */
		function wireFormResetRepaint(input) {
			const form = input
				&& (input.form
					|| (typeof input.closest === 'function' ? input.closest('form') : null));
			if (!form || resetWiredForms.has(form) || typeof form.addEventListener !== 'function') {
				return;
			}
			resetWiredForms.add(form);
			form.addEventListener('reset', () => {
				defer(() => {
					if (typeof form.querySelectorAll !== 'function') return;
					form.querySelectorAll('input[type="date"]').forEach((el) => {
						const paint = dateChromePaints.get(el);
						if (paint) paint();
					});
				});
			});
		}

		/**
		 * Repaint the .{p}-date-display overlay of one date input — the
		 * explicit fix for any programmatic write path the patches cannot
		 * see. Mounts chrome when missing, so it is safe on any date input.
		 */
		function repaintDateChrome(input, pickerLabel) {
			if (!input || input.type !== 'date') return;
			const paint = dateChromePaints.get(input);
			if (paint) {
				paint();
				return;
			}
			ensureDateChrome(
				input,
				enrichTemporalHtmlLang(currentLanguage() || currentLocale()),
				pickerLabel,
			);
		}

		/** Repaint every date input under root — or root itself when it is one. */
		function repaintTemporalChrome(root, options) {
			const scope = root || doc();
			if (!scope) return;
			if (scope.nodeType === 1
				&& String(scope.tagName || '').toUpperCase() === 'INPUT'
				&& scope.type === 'date') {
				repaintDateChrome(scope, options && options.pickerLabel);
				return;
			}
			if (typeof scope.querySelectorAll !== 'function') return;
			scope.querySelectorAll('input[type="date"]').forEach((input) => {
				repaintDateChrome(input, options && options.pickerLabel);
			});
		}

		function mountPickerSvg(btn) {
			// Inline calendar glyph — engine-independent affordance. Built via
			// createElementNS, not innerHTML (consumer lint forbids it).
			const NS = 'http://www.w3.org/2000/svg';
			const svg = document.createElementNS(NS, 'svg');
			svg.setAttribute('width', '16');
			svg.setAttribute('height', '16');
			svg.setAttribute('viewBox', '0 0 16 16');
			svg.setAttribute('fill', 'none');
			svg.setAttribute('stroke', 'currentColor');
			svg.setAttribute('stroke-width', '1.5');
			svg.setAttribute('aria-hidden', 'true');
			svg.setAttribute('focusable', 'false');
			const rect = document.createElementNS(NS, 'rect');
			rect.setAttribute('x', '1.5');
			rect.setAttribute('y', '2.5');
			rect.setAttribute('width', '13');
			rect.setAttribute('height', '12');
			rect.setAttribute('rx', '1.5');
			svg.appendChild(rect);
			const lines = [[1.5, 6, 14.5, 6], [5, 1, 5, 4], [11, 1, 11, 4]];
			for (const [x1, y1, x2, y2] of lines) {
				const line = document.createElementNS(NS, 'line');
				line.setAttribute('x1', String(x1));
				line.setAttribute('y1', String(y1));
				line.setAttribute('x2', String(x2));
				line.setAttribute('y2', String(y2));
				svg.appendChild(line);
			}
			btn.appendChild(svg);
		}

		function ensureDateChrome(input, dateLang, pickerLabel) {
			if (!input || input.type !== 'date') return;
			let wrap = input.closest('.' + prefix + '-date-field');
			if (!wrap) {
				wrap = document.createElement('div');
				wrap.className = prefix + '-date-field';
				input.parentNode.insertBefore(wrap, input);
				wrap.appendChild(input);
			}
			let overlay = wrap.querySelector(':scope > .' + prefix + '-date-display');
			const paint = () => {
				const iso = String(input.value || '');
				const text = iso
					? (formatDisplayDate(iso) || iso)
					: (input.getAttribute('data-' + prefix + '-date-placeholder') || dateInputPlaceholder(dateLang));
				if (overlay) {
					overlay.textContent = text;
					overlay.classList.toggle(prefix + '-date-display--empty', !iso);
				}
			};
			dateChromePaints.set(input, paint);
			patchProgrammaticValueSet(input);
			wireFormResetRepaint(input);
			if (!overlay) {
				overlay = document.createElement('span');
				overlay.className = prefix + '-date-display';
				overlay.setAttribute('aria-hidden', 'true');
				wrap.appendChild(overlay);
				input.addEventListener('change', paint);
				input.addEventListener('input', paint);
			}
			// Explicit, labelled picker trigger: visible on every engine
			// (Firefox/WebKit have no ::-webkit-calendar-picker-indicator).
			let btn = wrap.querySelector(':scope > .' + prefix + '-date-picker');
			if (!btn) {
				btn = document.createElement('button');
				btn.type = 'button';
				btn.className = prefix + '-date-picker';
				mountPickerSvg(btn);
				btn.setAttribute('aria-label', pickerLabel || 'Choose date');
				btn.addEventListener('click', (event) => {
					event.preventDefault();
					if (typeof input.showPicker === 'function') {
						try {
							input.showPicker();
							return;
						} catch (e) { /* fall back to focus */ }
					}
					input.focus();
				});
				wrap.appendChild(btn);
			} else if (pickerLabel) {
				btn.setAttribute('aria-label', pickerLabel);
			}
			paint();
		}

		/**
		 * Locale-aware chrome for temporal inputs under `root`:
		 *  - lang attr so the UA localizes the native widget
		 *  - date inputs get a .{p}-date-field wrapper with display overlay
		 *    + explicit picker button (opts.pickerLabel — translated!)
		 *  - removes legacy .{p}-date-locale-hint elements
		 */
		function applyLocaleToTemporalInputs(root, options) {
			const scope = root || document;
			const popts = options || {};
			const dateLang = enrichTemporalHtmlLang(currentLanguage() || currentLocale());
			const placeholder = dateInputPlaceholder(dateLang);
			scope.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="month"]').forEach((input) => {
				input.setAttribute('lang', dateLang);
				input.setAttribute('data-' + prefix + '-date-placeholder', placeholder);
				const parent = input.closest('.' + prefix + '-field') || input.parentElement;
				if (parent && parent.querySelectorAll) {
					parent.querySelectorAll(':scope > .' + prefix + '-date-locale-hint').forEach((hint) => hint.remove());
				}
				if (input.type === 'date') {
					ensureDateChrome(input, dateLang, popts.pickerLabel);
				}
			});
			const tLocale = timeInputLocale();
			scope.querySelectorAll('input[type="time"]').forEach((input) => {
				input.setAttribute('lang', tLocale);
				if (use24HourTimeInputs()) {
					input.setAttribute('data-' + prefix + '-time-24h', '1');
				}
			});
		}

		function applyLocaleToTemporalInput(input, localeOverride) {
			if (!input || (input.type !== 'date' && input.type !== 'month')) return;
			input.setAttribute('lang', resolveTemporalInputLang(localeOverride));
		}

		/* ---------- misc ---------- */

		function compareYearMonth(a, b) {
			if (!a || !b) return 0;
			return a < b ? -1 : (a > b ? 1 : 0);
		}

		function clampYearMonth(value, min, max) {
			if (!RE_MONTH.test(String(value || ''))) return null;
			if (min && value < min) return min;
			if (max && value > max) return max;
			return value;
		}

		return {
			naiveMode,
			prefix,
			// env
			currentLocale,
			currentLanguage,
			currentFirstDayOfWeek,
			currentTimezone,
			normalizeLang,
			use24HourTimeInputs,
			timeInputLocale,
			// classification / parsing
			classifyTemporal,
			partsFromIsoDate,
			isIsoDateOnly,
			isIsoCalendarDay: isIsoDateOnly,
			isoFromYmd,
			toInstant,
			toCalendarAnchor,
			parseDisplayDateToIso,
			expectedFormatPattern,
			// display
			formatDisplayDate,
			formatDisplayDateTime,
			formatDisplayTime,
			formatYearMonth,
			formatWeekday,
			formatDateTimeRange,
			relative,
			formatRelativeMinutes,
			// iso in/out
			isoForDateInput,
			isoForDateTimeInput,
			fromDateTimeLocalInput,
			localToUtcSql,
			todayIsoDate,
			currentYearMonth,
			isoDate,
			isoInZone,
			// wall-clock
			formatClock24FromTimeString,
			formatClock24Range,
			wallClockMinutesFromTimeString,
			isOvernightWallClockShift,
			// input chrome
			enrichTemporalHtmlLang,
			resolveTemporalInputLang,
			dateInputPlaceholder,
			applyLocaleToTemporalInput,
			applyLocaleToTemporalInputs,
			repaintDateChrome,
			repaintTemporalChrome,
			// misc
			compareYearMonth,
			clampYearMonth,
		};
	}

	window.CheckDates = {
		create,
		// env-free pure helpers usable without an instance
		partsFromIsoDate,
		isIsoDateOnly,
		isValidYmd,
		isoFromYmd,
		classifyTemporal,
		enrichTemporalHtmlLang,
	};
})();
