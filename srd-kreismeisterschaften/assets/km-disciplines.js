(function () {
	'use strict';

	var cfg = window.srdKmDisciplines;
	if (!cfg) {
		return;
	}

	var panel = document.getElementById('srd-km-disciplines-panel');
	if (!panel) {
		return;
	}

	var cardRoot = panel.closest('.card');
	var cardsEl = document.getElementById('srd-km-disciplines-cards');
	var tbodyEl = document.getElementById('srd-km-disciplines-tbody');
	var documentsWrap = document.getElementById('srd-km-documents-wrap');
	var yearSelect = document.getElementById('srd-km-year-select');
	var filterGroup = cardRoot
		? cardRoot.querySelector('.srd-km-category-filter [role="group"]')
		: null;

	// wp_localize_script liefert Zahlen als Strings – ohne Normalisierung schlägt category === 0 fehl.
	function normalizeCategory(value) {
		var n = parseInt(value, 10);
		return isNaN(n) || n < 0 ? 0 : n;
	}

	function normalizeYear(value, fallback) {
		var n = parseInt(value, 10);
		if (!n || isNaN(n)) {
			return fallback || 0;
		}
		return n;
	}

	var state = {
		year: normalizeYear(cfg.year, 0),
		category: normalizeCategory(cfg.category),
		loading: false,
	};

	function parseStateFromLocation() {
		var year = state.year;
		var category = 0;
		var params = new URLSearchParams(window.location.search);
		if (params.has('km_category')) {
			category = normalizeCategory(params.get('km_category'));
		}
		if (params.has('km_year')) {
			year = normalizeYear(params.get('km_year'), year);
		}
		var pathMatch = window.location.pathname.match(/\/([0-9]{4})\/?$/);
		if (pathMatch) {
			year = normalizeYear(pathMatch[1], year);
		}
		return { year: year, category: category };
	}

	function buildListUrl(year, category) {
		var url = cfg.baseUrl;
		if (!url.endsWith('/')) {
			url += '/';
		}
		if (cfg.usePrettyUrls) {
			if (year) {
				url += String(year) + '/';
			}
			if (category > 0) {
				url += (url.indexOf('?') >= 0 ? '&' : '?') + 'km_category=' + String(category);
			}
			return url;
		}
		var params = new URLSearchParams();
		if (year) {
			params.set('km_year', String(year));
		}
		if (category > 0) {
			params.set('km_category', String(category));
		}
		var qs = params.toString();
		return qs ? url + '?' + qs : url;
	}

	function updateHistory() {
		var url = buildListUrl(state.year, state.category);
		if (url !== window.location.href) {
			window.history.pushState(
				{ srdKm: { year: state.year, category: state.category } },
				'',
				url
			);
		}
	}

	function setLoading(loading) {
		state.loading = loading;
		panel.classList.toggle('srd-km-disciplines-panel--loading', loading);
		if (yearSelect) {
			yearSelect.disabled = loading;
		}
		if (filterGroup) {
			filterGroup.querySelectorAll('a, button').forEach(function (el) {
				if (loading) {
					el.setAttribute('aria-disabled', 'true');
					el.classList.add('disabled');
				} else {
					el.removeAttribute('aria-disabled');
					el.classList.remove('disabled');
				}
			});
		}
	}

	function getItems() {
		return panel.querySelectorAll('.srd-km-discipline-item');
	}

	function applyCategoryFilter(category) {
		category = normalizeCategory(category);
		state.category = category;
		panel.setAttribute('data-category', String(category));

		var visible = 0;
		getItems().forEach(function (item) {
			var itemCat = parseInt(item.getAttribute('data-srd-km-category') || '0', 10);
			var show = category === 0 || itemCat === category;
			item.classList.toggle('d-none', !show);
			if (show) {
				visible += 1;
			}
		});

		updateEmptyStates(visible);
		updateCategoryButtons(category);
		updateDocumentHighlights(category);
		updateHistory();
	}

	function updateEmptyStates(visibleCount) {
		var emptyType = visibleCount === 0 ? (state.category > 0 ? 'category' : 'year') : '';
		panel.querySelectorAll('[data-srd-km-empty]').forEach(function (el) {
			var type = el.getAttribute('data-srd-km-empty');
			el.classList.toggle('d-none', type !== emptyType);
		});
	}

	function updateCategoryButtons(category) {
		if (!filterGroup) {
			return;
		}
		filterGroup.querySelectorAll('[data-srd-km-category]').forEach(function (btn) {
			var btnCat = parseInt(btn.getAttribute('data-srd-km-category'), 10);
			var active = btnCat === category;
			btn.classList.toggle('active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	}

	function replaceLists(cardsHtml, tbodyHtml) {
		if (cardsEl) {
			cardsEl.innerHTML = cardsHtml;
		}
		if (tbodyEl) {
			tbodyEl.innerHTML = tbodyHtml;
		}
	}

	function updateDocumentHighlights(category) {
		if (!documentsWrap) {
			return;
		}
		category = normalizeCategory(category);
		documentsWrap.querySelectorAll('[data-srd-km-doc-categories]').forEach(function (link) {
			var raw = link.getAttribute('data-srd-km-doc-categories') || '';
			var ids = raw.split(',').map(function (part) {
				return parseInt(part, 10);
			}).filter(function (n) {
				return !isNaN(n) && n > 0;
			});
			var highlight = category > 0 && ids.indexOf(category) >= 0;
			link.classList.toggle('srd-km-documents__link--highlight', highlight);
		});
	}

	function fetchYear(year) {
		setLoading(true);
		var body = new URLSearchParams();
		body.set('action', cfg.action);
		body.set('nonce', cfg.nonce);
		body.set('year', String(year));
		body.set('category', String(state.category));

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (!json || !json.success || !json.data) {
					throw new Error('invalid response');
				}
				state.year = normalizeYear(json.data.year, state.year);
				panel.setAttribute('data-year', String(state.year));
				if (yearSelect) {
					yearSelect.value = String(state.year);
				}
				replaceLists(json.data.cards || '', json.data.tbody || '');
				applyCategoryFilter(state.category);
			})
			.catch(function () {
				window.alert(cfg.strings.loadError);
				if (yearSelect) {
					yearSelect.value = String(state.year);
				}
			})
			.finally(function () {
				setLoading(false);
			});
	}

	function onYearChange(year) {
		year = normalizeYear(year, 0);
		if (!year || year === state.year || state.loading) {
			return;
		}
		fetchYear(year);
	}

	function onCategoryClick(category) {
		category = normalizeCategory(category);
		if (category === state.category || state.loading) {
			return;
		}
		applyCategoryFilter(category);
	}

	if (filterGroup) {
		filterGroup.addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-srd-km-category]');
			if (!btn || !filterGroup.contains(btn)) {
				return;
			}
			ev.preventDefault();
			onCategoryClick(btn.getAttribute('data-srd-km-category'));
		});
	}

	if (yearSelect) {
		yearSelect.addEventListener('change', function () {
			var opt = yearSelect.options[yearSelect.selectedIndex];
			var year = yearSelect.value;
			if (!year && opt && opt.getAttribute('data-fallback-url')) {
				window.location.href = opt.getAttribute('data-fallback-url');
				return;
			}
			onYearChange(year);
		});
	}

	window.addEventListener('popstate', function () {
		var parsed = parseStateFromLocation();
		if (parsed.year !== state.year) {
			state.category = parsed.category;
			fetchYear(parsed.year);
			return;
		}
		if (parsed.category !== state.category) {
			applyCategoryFilter(parsed.category);
		}
	});

	applyCategoryFilter(state.category);
})();
