/**
 * Startplan-Matrix im Backend: Starter per Ziehen oder per zwei Klicks auf einen anderen
 * Platz setzen. Ist der Zielplatz belegt, tauschen beide – das entscheidet der Server.
 *
 * Ohne JavaScript bleibt die Matrix eine reine Ansicht; verschoben wird dann über das
 * Formular in der Buchungsliste darunter.
 */
(function () {
	'use strict';

	var tabelle = document.querySelector('table.kmm-matrix.is-bearbeitbar');
	if (!tabelle || typeof kmmStartplan === 'undefined') {
		return;
	}
	var status = document.querySelector('.kmm-matrix-status');
	var sportjahr = tabelle.dataset.sportjahr;
	var auswahl = null;
	var laeuft = false;

	function melden(text, art) {
		if (!status) { return; }
		status.textContent = text;
		status.className = 'kmm-matrix-status' + (art ? ' is-' + art : '');
	}

	function ziel(el) {
		return el ? el.closest('td.kmm-matrix-frei, td.kmm-matrix-belegt') : null;
	}

	function waehlen(td) {
		if (auswahl) { auswahl.classList.remove('is-auswahl'); }
		auswahl = td;
		if (td) {
			td.classList.add('is-auswahl');
			tabelle.classList.add('hat-auswahl');
			melden(td.querySelector('strong').textContent + ' – ' + kmmStartplan.texte.gewaehlt, 'info');
		} else {
			tabelle.classList.remove('hat-auswahl');
		}
	}

	function verschieben(quelle, zielZelle) {
		if (laeuft || !quelle || !zielZelle || quelle === zielZelle) { return; }
		laeuft = true;
		tabelle.classList.add('is-busy');
		melden(kmmStartplan.texte.laeuft, 'info');
		var daten = new URLSearchParams();
		daten.set('action', 'kmm_startplan_verschieben');
		daten.set('nonce', kmmStartplan.nonce);
		daten.set('sportjahr_id', sportjahr);
		daten.set('buchung_id', quelle.dataset.buchung);
		daten.set('durchgang_id', zielZelle.dataset.durchgang);
		daten.set('einheit_id', zielZelle.dataset.einheit);
		daten.set('position', zielZelle.dataset.position);
		fetch(kmmStartplan.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: daten.toString()
		}).then(function (r) {
			return r.json();
		}).then(function (antwort) {
			if (!antwort || !antwort.success) {
				melden((antwort && antwort.data && antwort.data.message) || kmmStartplan.texte.fehler, 'fehler');
				return;
			}
			tauschenImRaster(quelle, zielZelle);
			melden(antwort.data.message, 'ok');
		}).catch(function () {
			melden(kmmStartplan.texte.fehler, 'fehler');
		}).finally(function () {
			laeuft = false;
			tabelle.classList.remove('is-busy');
			waehlen(null);
		});
	}

	function belegen(td, inhalt, buchung) {
		td.innerHTML = inhalt;
		td.dataset.buchung = buchung;
		td.className = 'kmm-matrix-belegt';
		td.draggable = true;
		td.tabIndex = 0;
	}

	function leeren(td) {
		td.innerHTML = '<span>' + kmmStartplan.texte.frei + '</span>';
		delete td.dataset.buchung;
		td.className = 'kmm-matrix-frei';
		td.draggable = false;
		td.removeAttribute('tabindex');
	}

	/** Inhalte der beiden Felder tauschen, damit die Seite nicht neu geladen werden muss. */
	function tauschenImRaster(a, b) {
		var inhaltA = a.innerHTML;
		var buchungA = a.dataset.buchung;
		var inhaltB = b.innerHTML;
		var buchungB = b.dataset.buchung;
		var belegtB = b.classList.contains('kmm-matrix-belegt');
		belegen(b, inhaltA, buchungA);
		if (belegtB) {
			belegen(a, inhaltB, buchungB);
		} else {
			leeren(a);
		}
		a.classList.add('kmm-frisch');
		b.classList.add('kmm-frisch');
		window.setTimeout(function () {
			a.classList.remove('kmm-frisch');
			b.classList.remove('kmm-frisch');
		}, 1500);
	}

	// Ziehen mit der Maus
	tabelle.addEventListener('dragstart', function (ev) {
		var td = ziel(ev.target);
		if (!td || !td.classList.contains('kmm-matrix-belegt')) { ev.preventDefault(); return; }
		td.classList.add('is-zieht');
		tabelle.classList.add('hat-auswahl');
		auswahl = td;
		ev.dataTransfer.effectAllowed = 'move';
		ev.dataTransfer.setData('text/plain', td.dataset.buchung);
	});
	tabelle.addEventListener('dragend', function () {
		tabelle.querySelectorAll('.is-zieht, .is-ueber').forEach(function (el) {
			el.classList.remove('is-zieht', 'is-ueber');
		});
		tabelle.classList.remove('hat-auswahl');
		auswahl = null;
	});
	tabelle.addEventListener('dragover', function (ev) {
		var td = ziel(ev.target);
		if (!td || td === auswahl) { return; }
		ev.preventDefault();
		ev.dataTransfer.dropEffect = 'move';
		td.classList.add('is-ueber');
	});
	tabelle.addEventListener('dragleave', function (ev) {
		var td = ziel(ev.target);
		if (td) { td.classList.remove('is-ueber'); }
	});
	tabelle.addEventListener('drop', function (ev) {
		var td = ziel(ev.target);
		if (!td) { return; }
		ev.preventDefault();
		td.classList.remove('is-ueber');
		var quelle = auswahl;
		auswahl = null;
		verschieben(quelle, td);
	});

	// Zwei Klicks: erst den Starter, dann den Zielplatz. Geht auch mit Tastatur und Finger.
	tabelle.addEventListener('click', function (ev) {
		var td = ziel(ev.target);
		if (!td || laeuft) { return; }
		if (!auswahl) {
			if (td.classList.contains('kmm-matrix-belegt')) { waehlen(td); }
			return;
		}
		if (td === auswahl) { waehlen(null); melden('', ''); return; }
		var quelle = auswahl;
		auswahl = null;
		quelle.classList.remove('is-auswahl');
		tabelle.classList.remove('hat-auswahl');
		verschieben(quelle, td);
	});
	tabelle.addEventListener('keydown', function (ev) {
		if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
		var td = ziel(ev.target);
		if (!td) { return; }
		ev.preventDefault();
		td.click();
	});
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape' && auswahl) { waehlen(null); melden('', ''); }
	});
})();
