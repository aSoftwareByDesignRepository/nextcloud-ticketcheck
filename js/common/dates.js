/**
 * TicketCheckDates — adapter over the shared CheckDates module
 * (js/common/check-dates.js, loaded before this file).
 *
 * Contract (audited): date-only `YYYY-MM-DD` is a calendar day — it renders
 * the same in every timezone. Space-separated API datetimes are LOCAL wall
 * time (naiveMode 'local'); ISO strings with T/Z carry an instant.
 */
(function () {
	'use strict';

	const D = window.CheckDates.create({
		prefix: 'tc',
		naiveMode: 'local',
		displayDateStyle: 'numeric',
		attrs: {
			locale: 'lang',
			language: 'lang',
			timezone: 'data-tc-timezone',
		},
	});

	/** @deprecated use formatDisplayDate — kept for older bundles */
	function formatIso(iso, locale) {
		return D.formatDisplayDate(iso, locale);
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
					.replace('{monthFrom}', D.formatYearMonth(first, htmlLang))
					.replace('{monthTo}', D.formatYearMonth(last, htmlLang));
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

		const monthLabel = D.formatYearMonth(yearMonth, htmlLang);

		let monthLine = '';
		if (count === 0) {
			if (first && D.compareYearMonth(yearMonth, first) < 0) {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. That calendar month is before your first ledger booking ({monthFrom}).')
					.replace('{monthLabel}', monthLabel)
					.replace('{monthFrom}', D.formatYearMonth(first, htmlLang));
			} else if (last && D.compareYearMonth(yearMonth, last) > 0) {
				monthLine = tr(APP, 'Nothing booked for {monthLabel}. That calendar month is after your latest ledger booking ({monthTo}).')
					.replace('{monthLabel}', monthLabel)
					.replace('{monthTo}', D.formatYearMonth(last, htmlLang));
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
		normalizeLang: D.normalizeLang,
		isoDate: D.isoDate,
		currentYearMonth: D.currentYearMonth,
		clampYearMonth: D.clampYearMonth,
		formatYearMonth: D.formatYearMonth,
		formatDisplayDate: D.formatDisplayDate,
		formatDisplayDateTime: D.formatDisplayDateTime,
		formatIso,
		parseDisplayDateToIso: D.parseDisplayDateToIso,
		expectedFormatPattern: D.expectedFormatPattern,
		isIsoCalendarDay: D.isIsoCalendarDay,
		resolveTemporalInputLang: D.resolveTemporalInputLang,
		enrichTemporalHtmlLang: window.CheckDates.enrichTemporalHtmlLang,
		applyLocaleToTemporalInput: D.applyLocaleToTemporalInput,
		applyLocaleToTemporalInputs: D.applyLocaleToTemporalInputs,
		compareYearMonth: D.compareYearMonth,
		monthlyLedgerHelpLines,
	};
})();
