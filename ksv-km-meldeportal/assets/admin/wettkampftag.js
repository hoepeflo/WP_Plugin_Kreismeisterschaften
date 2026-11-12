/**
 * Wettkampftage: Shortcode kopieren. Reiter „Durchgänge“: Bearbeitungsfelder in der Karte
 * eines Durchgangs öffnen, ohne die Seite neu zu laden. Die Links führen ohne JavaScript zur ganzen Seite
 * mit geöffnetem Feld – hier wird nur dasselbe HTML-Stück nachgeladen.
 */
(function () {
	'use strict';

	// Shortcode in die Zwischenablage; der Knopf bestätigt kurz. Ohne Zugriff darauf wird der Text markiert.
	document.addEventListener('click', function (e) {
		var knopf = e.target.closest('[data-kmm-kopieren]');
		if (!knopf) { return; }
		var text = knopf.getAttribute('data-kmm-kopieren');
		var fertig = function () {
			var vorher = knopf.textContent;
			knopf.textContent = knopf.getAttribute('data-kmm-kopiert');
			knopf.disabled = true;
			setTimeout(function () { knopf.textContent = vorher; knopf.disabled = false; }, 1500);
		};
		var markieren = function () {
			var code = knopf.parentNode.querySelector('code');
			if (!code) { return; }
			var bereich = document.createRange();
			bereich.selectNodeContents(code);
			var auswahl = window.getSelection();
			auswahl.removeAllRanges();
			auswahl.addRange(bereich);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(fertig, markieren);
		} else {
			markieren();
		}
	});

	var liste = document.querySelector('.kmm-dg-liste');
	var cfg = window.kmmStartplan;
	if (!liste || !cfg) { return; }

	function karte(el) { return el.closest('.kmm-dg'); }

	function schliessen(k) {
		k.querySelector('.kmm-dg-panel').innerHTML = '';
		k.classList.remove('is-offen', 'is-laedt');
	}

	// Lädt das Feld für die Adresse eines Links (oder Auswahlformulars) in die Karte.
	function laden(k, params, ersatzUrl) {
		params.set('action', 'kmm_dg_panel');
		params.set('nonce', cfg.nonce);
		params.delete('page');
		k.classList.add('is-laedt');
		fetch(cfg.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
			.then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.text(); })
			.then(function (html) {
				liste.querySelectorAll('.kmm-dg.is-offen').forEach(function (andere) { if (andere !== k) { schliessen(andere); } });
				var panel = k.querySelector('.kmm-dg-panel');
				panel.innerHTML = html;
				k.classList.remove('is-laedt');
				k.classList.add('is-offen');
				var feld = panel.querySelector('select, input:not([type=hidden])');
				if (feld) { feld.focus({ preventScroll: true }); }
				// Die ganze Karte ins Bild holen; ihr Abstand nach oben hält sie unter den Reitern.
				k.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			})
			.catch(function () { window.location.href = ersatzUrl; });
	}

	liste.addEventListener('click', function (e) {
		var auf = e.target.closest('a[data-kmm-panel]');
		if (auf) {
			e.preventDefault();
			laden(karte(auf), new URL(auf.href).searchParams, auf.href);
			return;
		}
		var zu = e.target.closest('[data-kmm-panel-zu]');
		if (zu) {
			e.preventDefault();
			schliessen(karte(zu));
			return;
		}
		var alle = e.target.closest('[data-kmm-weitere-alle]');
		if (alle) {
			var kaestchen = alle.closest('.kmm-zul-weitere').querySelectorAll('input[type=checkbox]');
			var an = Array.prototype.some.call(kaestchen, function (c) { return !c.checked; });
			kaestchen.forEach(function (c) { c.checked = an; });
			markieren(alle.closest('form'));
		}
	});

	// Marken färben sich nach ihrem Haken.
	function markieren(form) {
		form.querySelectorAll('label.kmm-stand').forEach(function (l) {
			var c = l.querySelector('input[type=checkbox]');
			if (c) { l.classList.toggle('is-aktiv', c.checked); }
		});
	}

	liste.addEventListener('change', function (e) {
		var wahl = e.target.closest('select[data-kmm-zul-wahl]');
		if (wahl) {
			var params = new URLSearchParams(new FormData(wahl.form));
			laden(karte(wahl), params, wahl.form.action.split('#')[0] + '?' + params.toString() + '#' + karte(wahl).id);
			return;
		}
		var form = e.target.closest('.kmm-zul-form');
		if (!form || e.target.type !== 'checkbox') { return; }
		// „alle Startklassen“ und einzelne Klassen schließen sich aus.
		var alle = form.querySelector('input[name=alle]');
		var einzeln = form.querySelectorAll('input[name="startklasse_ids[]"]');
		if (e.target === alle && alle.checked) {
			einzeln.forEach(function (c) { c.checked = false; });
		} else if (e.target.name === 'startklasse_ids[]' && e.target.checked) {
			alle.checked = false;
		}
		markieren(form);
	});

	// Esc schließt das offene Feld.
	liste.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		var k = karte(e.target);
		if (k && k.classList.contains('is-offen')) { schliessen(k); }
	});
})();
