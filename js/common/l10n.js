(function () {
	'use strict';

	/** English fallbacks when OC.L10N is not ready yet — never show raw msgids. */
	const FALLBACKS = {
		user_search_help: 'Type to search users.',
		user_search_loading: 'Searching users…',
		user_search_no_results: 'No matching users found.',
		user_search_results_count: '{count} users found.',
		user_search_failed: 'Could not search users. Try again.',
		project_search_help: 'Type to search projects.',
		project_search_loading: 'Searching projects…',
		project_search_no_results: 'No matching projects found.',
		project_search_results_count: '{count} projects found.',
		project_filter_search_help: 'Type to filter by project.',
		customer_filter_search_help: 'Type to filter by customer.',
		clear_selection: 'Clear selection',
		an_error_occurred: 'An error occurred',
	};

	function applyParams(text, params) {
		if (!params || typeof params !== 'object') {
			return text;
		}
		let out = text;
		Object.keys(params).forEach(function (paramKey) {
			out = out.replace(new RegExp('\\{' + paramKey + '\\}', 'g'), String(params[paramKey]));
		});
		return out;
	}

	function resolveFallback(key, fallback) {
		if (typeof fallback === 'string' && fallback !== '') {
			return fallback;
		}
		if (Object.prototype.hasOwnProperty.call(FALLBACKS, key)) {
			return FALLBACKS[key];
		}
		return '';
	}

	function t(key, fallback, params) {
		if (!key) {
			return resolveFallback(key, fallback);
		}
		if (window.helpdeskTranslations && window.helpdeskTranslations.translations) {
			const embedded = window.helpdeskTranslations.translations[key];
			if (typeof embedded === 'string' && embedded !== '' && embedded !== key) {
				return applyParams(embedded, params);
			}
		}
		if (typeof window.t === 'function') {
			const translated = window.t('ticketcheck', key, params || {});
			if (translated && translated !== key) {
				return translated;
			}
		}
		if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.get === 'function') {
			const fromOc = OC.L10N.get('ticketcheck', key, params || {});
			if (fromOc && fromOc !== key) {
				return fromOc;
			}
		}
		return applyParams(resolveFallback(key, fallback), params);
	}

	window.TicketCheckL10n = {
		t: t,
		fallbacks: FALLBACKS,
	};
})();
