/* KM-Portal – Startplan-Matrix: Starter eines Vereins oder per Name hervorheben.
 * Arbeitet nur mit dem, was in der Matrix steht, und läuft nach jedem Verschieben neu.
 */
(function () {
	'use strict';

	document.querySelectorAll('.kmm-matrix-filter').forEach((leiste) => {
		const huelle = leiste.nextElementSibling;
		const tabelle = huelle && huelle.querySelector('table.kmm-matrix');
		if (!tabelle) {
			return;
		}
		const auswahl = leiste.querySelector('[data-kmm-matrix-verein]');
		const suche = leiste.querySelector('[data-kmm-matrix-suche]');
		const treffer = leiste.querySelector('.kmm-matrix-treffer');
		const norm = (s) => (s || '').toLocaleLowerCase('de').normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

		function vereineEintragen() {
			const gewaehlt = auswahl.value;
			const namen = new Set();
			tabelle.querySelectorAll('.kmm-matrix-belegt .kmm-matrix-verein').forEach((el) => { if (el.textContent.trim()) { namen.add(el.textContent.trim()); } });
			const liste = Array.from(namen).sort((a, b) => a.localeCompare(b, 'de'));
			while (auswahl.options.length > 1) { auswahl.remove(1); }
			liste.forEach((n) => auswahl.add(new Option(n, n, false, n === gewaehlt)));
		}

		function anwenden() {
			const verein = auswahl.value;
			const text = norm(suche.value);
			const aktiv = verein !== '' || text !== '';
			let n = 0;
			tabelle.classList.toggle('is-gefiltert', aktiv);
			tabelle.querySelectorAll('.kmm-matrix-belegt').forEach((td) => {
				const v = (td.querySelector('.kmm-matrix-verein') || {}).textContent || '';
				const name = (td.querySelector('strong') || {}).textContent || '';
				const passt = aktiv && (verein === '' || v.trim() === verein) && (text === '' || norm(name).includes(text) || norm(v).includes(text));
				td.classList.toggle('is-treffer', passt);
				if (passt) { n++; }
			});
			treffer.textContent = aktiv ? (n === 1 ? '1 Starter' : n + ' Starter') : '';
		}

		vereineEintragen();
		auswahl.addEventListener('change', anwenden);
		suche.addEventListener('input', anwenden);
		// Nach dem Verschieben (Zellen werden neu geschrieben) Liste und Markierung auffrischen.
		let geplant = false;
		new MutationObserver(() => {
			if (geplant) { return; }
			geplant = true;
			requestAnimationFrame(() => { geplant = false; vereineEintragen(); anwenden(); });
		}).observe(tabelle, { childList: true, subtree: true, characterData: true });
		leiste.hidden = false;
	});
})();
