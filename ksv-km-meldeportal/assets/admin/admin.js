/* KM-Portal – Backend: Rückfragen im Stil des Portals statt Browser-Dialog.
 *
 * Formulare oder Buttons mit data-kmm-confirm="Frage" fragen vor dem Absenden nach.
 * data-kmm-gefahr="1"   → roter Bestätigungsbutton
 * data-kmm-eingabe="X"  → Bestätigung erst nach Eintippen von X (für Unumkehrbares)
 */
(function () {
	'use strict';

	const texte = (window.kmmAdmin && window.kmmAdmin.texte) || {};
	const t = (k, d) => texte[k] || d;

	function dialog() {
		let d = document.getElementById('kmm-rueckfrage');
		if (!d) {
			d = document.createElement('dialog');
			d.id = 'kmm-rueckfrage';
			d.className = 'kmm-rueckfrage';
			d.setAttribute('aria-labelledby', 'kmm-rueckfrage-titel');
			(document.querySelector('.kmm-admin') || document.body).appendChild(d);
		}
		return d;
	}

	function fragen(el, ausloeser) {
		const frage = el.getAttribute('data-kmm-confirm') || '';
		const gefahr = el.getAttribute('data-kmm-gefahr') === '1';
		const eingabe = el.getAttribute('data-kmm-eingabe') || '';
		const d = dialog();
		d.innerHTML = '';
		const form = document.createElement('form');
		form.method = 'dialog';
		const h = document.createElement('h2');
		h.id = 'kmm-rueckfrage-titel';
		h.textContent = frage;
		form.appendChild(h);
		let feld = null;
		if (eingabe) {
			const p = document.createElement('p');
			p.textContent = t('eingabe', 'Zur Bestätigung bitte %s eintippen:').replace('%s', eingabe);
			form.appendChild(p);
			feld = document.createElement('input');
			feld.type = 'text';
			feld.className = 'regular-text';
			feld.autocomplete = 'off';
			feld.setAttribute('aria-label', p.textContent);
			form.appendChild(feld);
		}
		const leiste = document.createElement('div');
		leiste.className = 'kmm-rueckfrage-buttons';
		const nein = document.createElement('button');
		nein.type = 'submit';
		nein.value = 'nein';
		nein.className = 'button';
		nein.textContent = t('abbrechen', 'Abbrechen');
		const ja = document.createElement('button');
		ja.type = 'submit';
		ja.value = 'ja';
		ja.className = 'button button-primary' + (gefahr ? ' kmm-button-gefahr' : '');
		const quelle = ausloeser || (el instanceof HTMLButtonElement || el instanceof HTMLInputElement ? el : null);
		const beschriftung = el.getAttribute('data-kmm-ok') || (quelle ? (quelle.value && quelle instanceof HTMLInputElement ? quelle.value : quelle.textContent) : '');
		ja.textContent = (beschriftung || '').trim() || t('ok', 'Ja, fortfahren');
		if (feld) {
			ja.disabled = true;
			feld.addEventListener('input', () => { ja.disabled = feld.value.trim() !== eingabe; });
		}
		leiste.append(nein, ja);
		form.appendChild(leiste);
		d.appendChild(form);
		return new Promise((resolve) => {
			d.addEventListener('close', () => resolve(d.returnValue === 'ja'), { once: true });
			d.returnValue = '';
			d.showModal();
			(feld || ja).focus();
		});
	}

	// Formulare: beim Absenden fragen; nach „Ja“ mit demselben Button erneut absenden.
	document.addEventListener('submit', async (ev) => {
		const form = ev.target;
		if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-kmm-confirm') || form.dataset.kmmBestaetigt === '1') {
			return;
		}
		ev.preventDefault();
		const submitter = ev.submitter || null;
		if (await fragen(form, submitter)) {
			form.dataset.kmmBestaetigt = '1';
			if (submitter && submitter.form === form) { form.requestSubmit(submitter); } else { form.requestSubmit(); }
		}
	}, true);

	// Einzelne Buttons in größeren Formularen (z. B. Klasse löschen).
	document.addEventListener('click', async (ev) => {
		const b = ev.target.closest('button[data-kmm-confirm], input[type=submit][data-kmm-confirm]');
		if (!b || b.dataset.kmmBestaetigt === '1') {
			return;
		}
		ev.preventDefault();
		if (await fragen(b)) {
			b.dataset.kmmBestaetigt = '1';
			b.form.requestSubmit(b);
		}
	}, true);

	// Reiter innerhalb eines Formulars ([data-kmm-reiter]): alle Felder bleiben im Formular,
	// es wird nur einer der Bereiche gezeigt. Ohne JavaScript sind alle Bereiche sichtbar.
	document.querySelectorAll('[data-kmm-reiter]').forEach((box) => {
		const links = Array.from(box.querySelectorAll('.kmm-reiter-nav a[href^="#"]'));
		const bereiche = links.map((a) => box.querySelector(a.getAttribute('href'))).filter(Boolean);
		if (!links.length || bereiche.length !== links.length) {
			return;
		}
		const zeigen = (id, fokus) => {
			links.forEach((a) => {
				const aktiv = a.getAttribute('href') === '#' + id;
				a.classList.toggle('nav-tab-active', aktiv);
				if (aktiv) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
			});
			bereiche.forEach((b) => { b.hidden = b.id !== id; });
			const merker = box.querySelector('input[name=kmm_reiter]');
			if (merker) { merker.value = id; }
			if (fokus) { history.replaceState(null, '', '#' + id); }
		};
		links.forEach((a) => a.addEventListener('click', (ev) => {
			ev.preventDefault();
			zeigen(a.getAttribute('href').slice(1), true);
		}));
		// Ungültige Eingabe in einem versteckten Bereich: dorthin wechseln, damit der Hinweis sichtbar wird.
		box.addEventListener('invalid', (ev) => {
			const b = ev.target.closest('.kmm-reiter-inhalt');
			if (b && b.hidden) { zeigen(b.id, true); }
		}, true);
		const start = location.hash.slice(1);
		zeigen(bereiche.some((b) => b.id === start) ? start : bereiche[0].id, false);
		box.classList.add('kmm-reiter-aktiv');
	});
})();
