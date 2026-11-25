/* KM-Portal – Vereinsoberfläche. Vanilla JS ohne Build-Schritt. */
(function () {
	'use strict';

	const root = document.getElementById('kmm-app');
	if (!root) {
		return;
	}
	const cfg = JSON.parse(root.getAttribute('data-config'));
	const state = { meldung: cfg.meldung, schuetzen: cfg.schuetzen, tab: 'schuetzen', angebot: null, angebotFuer: null };
	const BEREICHE = { uebrige: 'übrige Wettbewerbe', auflage: 'Auflage', bogen: 'Bogen' };
	const GRUPPEN = { freihand: 'Gewehr, Pistole, Flinte, Vorderlader', auflage: 'Auflage', fitasc: 'Flinte FITASC', lichtschiessen: 'Lichtschießen', blasrohr: 'Blasrohr', bogen: 'Bogen', para: 'Para' };
	const TEILE = { 1: 'Gewehr', 2: 'Pistole', 3: 'Flinte', 7: 'Vorderlader' };
	// Freihand ist groß: nach dem ersten Teil der Kennzahl unterteilen (1 Gewehr, 2 Pistole, 3 Flinte, 7 Vorderlader).
	const gruppeVon = (a) => {
		if (a.gruppe !== 'freihand') { return { key: a.gruppe, label: GRUPPEN[a.gruppe] || a.gruppe }; }
		const t = String(a.kennzahl).split('.')[0];
		return { key: 'freihand-' + t, label: (TEILE[t] || 'Weitere') };
	};

	// ----- Hilfen -------------------------------------------------------------------------

	const h = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const geld = (b) => Number(b || 0).toFixed(2).replace('.', ',') + ' €';
	const datum = (iso) => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? `${m[3]}.${m[2]}.${m[1]}` : ''; };
	// „1 Meldung“, „2 Meldungen“ – statt „Meldung(en)“.
	const mehrzahl = new Intl.PluralRules('de');
	const mz = (n, eins, viele) => `${n} ${mehrzahl.select(n) === 'one' ? eins : viele}`;
	// Suche ohne Rücksicht auf Groß-/Kleinschreibung und Umlaute/Akzente („muller“ findet „Müller“).
	const norm = (t) => String(t || '').toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ß/g, 'ss').trim();
	// Tolerante Suche: jedes Wort der Eingabe muss vorkommen, Reihenfolge, Komma und Leerzeichen egal
	// („Müller, Karin“, „karin muller“ und „mül ka“ finden „Müller, Karin“).
	const passtSuche = (text, q) => norm(q).split(/[\s,;]+/).filter(Boolean).every((w) => text.includes(w));
	// Ab so vielen Schützen erscheinen Suche und Filter.
	const SUCHE_AB = 8;
	const $ = (sel, el) => (el || document).querySelector(sel);
	const $$ = (sel, el) => Array.from((el || document).querySelectorAll(sel));

	function toast(text, art) {
		const t = document.getElementById('kmm-toast');
		t.textContent = text;
		t.className = 'kmm-toast is-da ' + (art || 'ok');
		clearTimeout(toast.timer);
		toast.timer = setTimeout(() => { t.classList.remove('is-da'); }, art === 'error' ? 8000 : 3500);
	}

	// Admin-Modus: Backend-Benutzer bearbeitet die Meldung eines Vereins (auch nach Meldeschluss).
	// Anmeldung über WordPress-Nonce statt Vereinssitzung; Verein und Sportjahr als Header.
	const admin = cfg.admin || null;

	async function api(method, path, body) {
		const headers = { 'Accept': 'application/json' };
		if (admin) {
			headers['X-WP-Nonce'] = admin.nonce;
			headers['X-KMM-Verein'] = String(admin.verein_id);
			headers['X-KMM-Sportjahr'] = String(admin.sportjahr_id);
		} else {
			headers['X-KMM-Token'] = cfg.csrf;
		}
		if (body) { headers['Content-Type'] = 'application/json'; }
		const res = await fetch(cfg.api + path, { method, credentials: 'same-origin', headers, body: body ? JSON.stringify(body) : undefined });
		let data = null;
		try { data = await res.json(); } catch (e) { data = null; }
		if (!res.ok) {
			const msg = (data && data.message) ? data.message : ('Fehler ' + res.status);
			if (res.status === 401 && !admin) {
				window.location.href = cfg.abmelden;
			}
			throw new Error(msg);
		}
		return data;
	}

	function schreibbar() {
		if (admin) {
			return state.meldung.phase.status !== 'abgeschlossen';
		}
		return state.meldung.phase.schreibbar && state.meldung.status !== 'eingereicht' && state.meldung.status !== 'verarbeitet';
	}
	const STATUS_LABEL = { ungeprueft: 'ungeprüft', verarbeitet: 'verarbeitet', nicht_startberechtigt: 'nicht startberechtigt' };
	// Verarbeitungsstatus wird angezeigt, sobald der Meldeschluss vorbei ist oder ein Status gesetzt wurde.
	function statusSichtbar() {
		const m = state.meldung;
		return !!admin || m.phase.status === 'geschlossen' || m.phase.status === 'abgeschlossen' || m.einzelmeldungen.some((e) => e.verarbeitungsstatus !== 'ungeprueft' || e.abgemeldet_am);
	}

	function dialog(html, onSubmit) {
		const d = document.getElementById('kmm-dialog');
		d.innerHTML = `<form method="dialog" class="kmm-form">${html}</form>`;
		const form = $('form', d);
		// Abbrechen ist ein normaler Button, kein Submit: Sonst wäre er der erste Submit-Button im
		// Formular, und Enter bzw. „Fertig“/„Los“ auf der Handy-Tastatur würde abbrechen statt speichern.
		$$('[data-dialog-abbrechen]', form).forEach((b) => b.addEventListener('click', () => d.close()));
		form.addEventListener('submit', async (ev) => {
			if (ev.submitter && ev.submitter.value === 'abbrechen') {
				return;
			}
			ev.preventDefault();
			const btn = ev.submitter || $('button[type=submit]', form);
			btn.disabled = true;
			try {
				await onSubmit(form);
				d.close();
			} catch (e) {
				$('.kmm-dialog-fehler', form).textContent = e.message;
			} finally {
				btn.disabled = false;
			}
		});
		d.showModal();
		const first = $('input, select', form);
		if (first) { first.focus(); }
	}

	const dialogButtons = (label) => `<div class="kmm-dialog-fehler kmm-fehler" aria-live="polite"></div><div class="kmm-dialog-buttons"><button type="button" data-dialog-abbrechen class="kmm-button kmm-button-secondary">Abbrechen</button><button type="submit" value="ok" class="kmm-button">${h(label)}</button></div>`;

	// Rückfrage im Stil des Portals statt des Browser-Dialogs: Frage, Folge, zwei klare Buttons.
	// Liefert ein Promise<boolean>.
	function bestaetigen(opt) {
		const o = typeof opt === 'string' ? { titel: opt } : opt;
		let d = document.getElementById('kmm-rueckfrage');
		if (!d) {
			d = document.createElement('dialog');
			d.id = 'kmm-rueckfrage';
			d.className = 'kmm-dialog kmm-rueckfrage';
			d.setAttribute('aria-labelledby', 'kmm-rueckfrage-titel');
			root.appendChild(d);
		}
		const liste = (o.liste || []).length ? `<ul class="kmm-rueckfrage-liste">${o.liste.map((x) => `<li>${h(x)}</li>`).join('')}</ul>` : '';
		d.innerHTML = `<form method="dialog"><h3 id="kmm-rueckfrage-titel">${h(o.titel)}</h3>${o.text ? `<p>${h(o.text)}</p>` : ''}${liste}
			<div class="kmm-dialog-buttons"><button type="submit" value="nein" class="kmm-button kmm-button-secondary">${h(o.abbrechen || 'Abbrechen')}</button><button type="submit" value="ja" class="kmm-button ${o.gefahr ? 'kmm-button-gefahr' : ''}">${h(o.ok || 'OK')}</button></div></form>`;
		return new Promise((resolve) => {
			d.addEventListener('close', () => resolve(d.returnValue === 'ja'), { once: true });
			d.returnValue = '';
			d.showModal();
			$('button[value=ja]', d).focus();
		});
	}

	// ----- Kopf, Banner, Tabs ------------------------------------------------------------------

	// Restzeit bis zu einem Zeitpunkt (ISO, UTC) in Worten: „noch 12 Tage“, „noch 5 Stunden“.
	function restzeit(iso) {
		const ziel = iso ? Date.parse(iso) : NaN;
		if (Number.isNaN(ziel)) { return null; }
		const ms = ziel - Date.now();
		if (ms <= 0) { return null; }
		const stunden = Math.floor(ms / 3600000);
		const tage = Math.floor(stunden / 24);
		let text;
		if (tage >= 2) { text = `noch ${tage} Tage`; } else if (stunden >= 24) { text = `noch 1 Tag und ${mz(stunden - 24, 'Stunde', 'Stunden')}`; } else if (stunden >= 1) { text = `noch ${mz(stunden, 'Stunde', 'Stunden')}`; } else { text = `noch ${mz(Math.max(1, Math.ceil(ms / 60000)), 'Minute', 'Minuten')}`; }
		return { ms, text };
	}

	function renderKopf() {
		const m = state.meldung;
		const label = { offen: 'Offen', entwurf: 'Entwurf', eingereicht: 'Eingereicht', verarbeitet: 'Verarbeitet' }[m.status] || m.status;
		$('#kmm-status').innerHTML = `<span class="kmm-badge kmm-badge-${h(m.status)}">${h(label)}</span>` + (m.status === 'eingereicht' && m.eingereicht_am ? `<div class="kmm-muted">am ${h(m.eingereicht_am)}</div>` : '');
		let banner = '';
		if (admin) {
			banner = m.phase.status === 'abgeschlossen'
				? '<div class="kmm-alert kmm-alert-warn">Das Sportjahr ist abgeschlossen. Die Meldung ist schreibgeschützt.</div>'
				: `<div class="kmm-alert kmm-alert-warn">Admin-Modus: Änderungen werden im Namen des KSV vorgenommen${m.nach_meldeschluss ? ', als Änderung nach Meldeschluss protokolliert und in die Sammelmail an den Verein aufgenommen' : ''}.${m.nachmeldung_bis ? ' Nachmeldung für den Verein freigeschaltet bis ' + h(m.nachmeldung_bis) + ' Uhr.' : ''}</div>`;
		} else if (!m.phase.schreibbar) {
			banner = `<div class="kmm-alert kmm-alert-warn">${h(m.phase.grund)} Die Meldung ist schreibgeschützt.</div>`;
		} else if (m.phase.status === 'nachmeldung') {
			banner = `<div class="kmm-alert kmm-alert-ok">Nachmeldung freigeschaltet bis ${h(m.nachmeldung_bis)} Uhr. Änderungen sind bis dahin möglich.</div>`;
		} else if (m.status === 'verarbeitet') {
			banner = '<div class="kmm-alert kmm-alert-ok">Ihre Meldung ist vollständig geprüft. Den Status je Schütze sehen Sie unter „Meldung“.</div>';
		} else if (m.status === 'eingereicht') {
			banner = `<div class="kmm-alert kmm-alert-ok">Ihre Meldung ist eingereicht. Änderungen sind bis zum Meldeschluss (${h(m.sportjahr.meldeschluss)} Uhr) möglich: unter „Prüfen &amp; Einreichen“ die Meldung wieder öffnen.</div>`;
		} else if (m.sportjahr.meldeschluss) {
			const rest = restzeit(m.sportjahr.meldeschluss_iso);
			const knapp = rest && rest.ms < 3 * 86400000;
			banner = `<div class="${knapp ? 'kmm-alert kmm-alert-warn' : 'kmm-hinweis'} kmm-countdown">Meldeschluss: ${h(m.sportjahr.meldeschluss)} Uhr${rest ? ` · <strong>${h(rest.text)}</strong>` : ''}${knapp && m.status !== 'eingereicht' ? '. Bitte rechtzeitig einreichen.' : ''}</div>`;
		}
		if (m.konflikte > 0) {
			banner += `<div class="kmm-alert kmm-alert-error">${mz(m.konflikte, 'Meldung hat', 'Meldungen haben')} einen Konflikt nach einer Regeländerung oder kein Startrecht mehr. Bitte unter „Meldung“ prüfen.</div>`;
		}
		$('#kmm-banner').innerHTML = banner;
		renderSchritte();
		$$('.kmm-tab').forEach((s) => { s.hidden = s.id !== 'kmm-tab-' + state.tab; });
	}

	// Schritt-Anzeiger: je Schritt Zustand (erledigt / Hinweis) und eine kurze Statuszeile.
	const SCHRITTE = ['schuetzen', 'meldung', 'mannschaften', 'einreichen', 'startplan'];
	function schrittStatus() {
		const m = state.meldung;
		const n = m.einzelmeldungen.length;
		const teams = m.mannschaften || [];
		const offeneTeams = teams.filter((t) => !t.vollstaendig).length;
		const eingereicht = m.status === 'eingereicht' || m.status === 'verarbeitet';
		const fehler = (m.pruefung && m.pruefung.fehler) || [];
		return {
			schuetzen: { info: state.schuetzen.length ? mz(state.schuetzen.length, 'Schütze', 'Schützen') : 'noch keine', fertig: state.schuetzen.length > 0 },
			meldung: { info: n ? mz(n, 'Meldung', 'Meldungen') + (m.ohne_ergebnis ? ` · ${m.ohne_ergebnis} ohne Ergebnis` : '') : 'noch keine', fertig: n > 0 && !m.ohne_ergebnis && !m.konflikte, achtung: n > 0 && (m.ohne_ergebnis > 0 || m.konflikte > 0) },
			mannschaften: { info: teams.length ? mz(teams.length, 'Mannschaft', 'Mannschaften') + (offeneTeams ? ` · ${offeneTeams} unvollständig` : '') : 'optional', fertig: teams.length > 0 && !offeneTeams, achtung: offeneTeams > 0 },
			einreichen: admin
				? { info: eingereicht ? 'eingereicht' : 'nicht eingereicht', fertig: eingereicht }
				: { info: eingereicht ? 'eingereicht' : (fehler.length ? 'noch nicht bereit' : 'bereit zum Einreichen'), fertig: eingereicht },
			startplan: { info: 'Plätze buchen', fertig: false },
		};
	}

	function renderSchritte() {
		const st = schrittStatus();
		$$('#kmm-tabs button[data-tab]').forEach((b) => {
			const key = b.dataset.tab;
			const s = st[key] || {};
			const aktiv = key === state.tab;
			b.classList.toggle('is-active', aktiv);
			b.classList.toggle('is-fertig', !!s.fertig && !aktiv);
			b.classList.toggle('is-achtung', !!s.achtung);
			if (aktiv) {
				b.setAttribute('aria-current', 'step');
				// Auf dem Handy ist die Leiste wischbar: aktiven Schritt ins Bild holen.
				const leiste = b.parentElement;
				if (leiste.scrollWidth > leiste.clientWidth) { leiste.scrollLeft = b.offsetLeft - 16; }
			} else { b.removeAttribute('aria-current'); }
			const info = $('.kmm-schritt-info', b);
			if (info) { info.textContent = s.info || ''; }
			const nr = $('.kmm-schritt-nr', b);
			if (nr) { nr.textContent = s.fertig && !aktiv ? '✓' : String(SCHRITTE.indexOf(key) + 1); }
		});
		renderSchrittNav();
	}

	// Leiste unter jedem Schritt: zurück, weiter – im letzten Schritt der Weg zum Einreichen.
	function sichtbareSchritte() {
		return SCHRITTE.filter((k) => { const b = $(`#kmm-tabs button[data-tab=${k}]`); return b && !b.hidden; });
	}
	function renderSchrittNav() {
		const nav = $('#kmm-schritt-nav');
		if (!nav) { return; }
		const liste = sichtbareSchritte();
		const i = liste.indexOf(state.tab);
		const name = (k) => { const b = $(`#kmm-tabs button[data-tab=${k}] .kmm-schritt-name`); return b ? b.textContent : k; };
		const zurueck = i > 0 ? `<button type="button" class="kmm-button kmm-button-secondary" data-schritt="${liste[i - 1]}"><span aria-hidden="true">←</span> ${h(name(liste[i - 1]))}</button>` : '<span></span>';
		let weiter = '';
		if (i >= 0 && i < liste.length - 1) {
			weiter = `<button type="button" class="kmm-button" data-schritt="${liste[i + 1]}">Weiter zu ${h(name(liste[i + 1]))} <span aria-hidden="true">→</span></button>`;
		}
		let hinweis = '';
		if (state.tab === 'meldung' && state.angebotFuer && schreibbar()) {
			const idx = state.schuetzen.findIndex((x) => x.id === state.angebotFuer);
			const naechster = idx >= 0 ? state.schuetzen[idx + 1] : null;
			if (naechster) {
				hinweis = `<button type="button" class="kmm-link" data-naechster="${naechster.id}">Nächster Schütze: ${h(naechster.nachname)}, ${h(naechster.vorname)}</button>`;
			}
		}
		nav.innerHTML = `${zurueck}<div class="kmm-schritt-nav-mitte">${hinweis}</div>${weiter}`;
	}

	$('#kmm-schritt-nav').addEventListener('click', async (ev) => {
		const b = ev.target.closest('button');
		if (!b) { return; }
		if (b.dataset.schritt) {
			tabWechsel(b.dataset.schritt);
			$('#kmm-tabs').scrollIntoView({ block: 'start', behavior: 'smooth' });
		} else if (b.dataset.naechster) {
			await angebotLaden(Number(b.dataset.naechster));
			const sel = $('#kmm-tab-meldung select');
			if (sel) { sel.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
		}
	});

	function renderAll() {
		renderKopf();
		renderSchuetzen();
		renderMeldung();
		renderMannschaften();
		renderEinreichen();
		renderStartplan();
	}

	$('#kmm-tabs').addEventListener('click', (ev) => {
		const b = ev.target.closest('button[data-tab]');
		if (!b) { return; }
		tabWechsel(b.dataset.tab);
	});

	// Beim Wechsel bleibt der Blick auf den Tabs; nach ganz oben zu springen hieße auf dem
	// Handy, Vereinsname und Hinweis jedes Mal wieder wegzuscrollen.
	function tabWechsel(tab) {
		state.tab = tab;
		renderKopf();
		const tabs = $('#kmm-tabs');
		if (tabs.getBoundingClientRect().top < 0) { tabs.scrollIntoView(); }
		// Gibt es nur einen Wettkampftag, muss ihn niemand auswählen.
		const sp = state.startplan;
		if (tab === 'startplan' && sp && !sp.tagId && sp.tage.length === 1) {
			sp.tagId = sp.tage[0].id;
			renderStartplan();
			rasterLaden(true).then(startplanTimer);
			return;
		}
		startplanTimer();
	}

	// ----- 1 Schützen ------------------------------------------------------------------------

	function renderSchuetzen() {
		const el = $('#kmm-tab-schuetzen');
		const rw = schreibbar();
		let html = `<div class="kmm-toolbar"><h2>Schützenliste</h2>${rw ? '<button type="button" class="kmm-button" data-action="schuetze-neu">Neuer Schütze</button>' : ''}</div>`;
		html += '<p class="kmm-muted">Die Schützenliste bleibt über die Jahre erhalten. Höhermeldungen gelten nur für dieses Sportjahr.</p>';
		if (state.schuetzen.length >= SUCHE_AB) {
			const f = state.schuetzenFilter || 'alle';
			const ohne = state.schuetzen.filter((s) => !s.meldungen).length;
			html += `<div class="kmm-suchleiste" role="search">
				<label class="kmm-suche"><span class="kmm-sr">Schützen suchen</span><input type="search" id="kmm-suche-schuetzen" placeholder="Name oder Mitgliedsnummer suchen" autocomplete="off" value="${h(state.schuetzenSuche || '')}"></label>
				<div class="kmm-filter-chips" role="group" aria-label="Schützen filtern">
					${[['alle', `Alle (${state.schuetzen.length})`], ['ohne', `Noch nicht gemeldet (${ohne})`], ['mit', `Gemeldet (${state.schuetzen.length - ohne})`]].map(([k, l]) => `<button type="button" class="kmm-chip" data-schuetzen-filter="${k}" aria-pressed="${f === k}">${h(l)}</button>`).join('')}
				</div>
				<span class="kmm-muted kmm-suche-treffer" aria-live="polite"></span>
			</div>`;
		}
		if (state.schuetzen.length === 0) {
			html += `<div class="kmm-leer"><strong>Noch keine Schützen angelegt.</strong>${rw ? 'Mit „Neuer Schütze“ beginnen – die Liste bleibt für die nächsten Jahre erhalten.' : ''}</div>`;
		} else {
			html += '<div class="kmm-liste" id="kmm-schuetzenliste">' + state.schuetzen.map((s) => `
				<div class="kmm-karte" data-suche="${h(norm(s.nachname + ' ' + s.vorname + ' ' + s.vorname + ' ' + s.nachname + ' ' + (s.mitgliedsnummer || '')))}" data-gemeldet="${s.meldungen ? 1 : 0}">
					<div class="kmm-karte-haupt">
						<strong>${h(s.nachname)}, ${h(s.vorname)}</strong>
						<span class="kmm-muted">${h(datum(s.geburtsdatum))} (${s.alter} J.) · ${s.geschlecht === 'm' ? 'männlich' : 'weiblich'} · Nr. ${h(s.mitgliedsnummer)}</span>
						${Object.entries(s.hoehermeldungen || {}).map(([b, st]) => `<span class="kmm-badge kmm-badge-hm">Höhermeldung ${h(BEREICHE[b] || b)}: ${h((s.hoehermeldung_angebot[b] || {})[st] || st)}</span>`).join(' ')}
						${(s.warnungen || []).map((w) => `<div class="kmm-warn">⚠ ${h(w)}</div>`).join('')}
						${s.meldungen ? `<span class="kmm-muted">${mz(s.meldungen, 'Meldung', 'Meldungen')}</span>` : ''}
					</div>
					<div class="kmm-karte-aktionen">
						${rw ? `<button type="button" class="kmm-button kmm-button-small" data-action="schuetze-melden" data-id="${s.id}">Melden</button>
						<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="schuetze-bearbeiten" data-id="${s.id}">Bearbeiten</button>
						${s.meldungen ? '' : `<button type="button" class="kmm-button kmm-button-small kmm-button-danger" data-action="schuetze-loeschen" data-id="${s.id}">Löschen</button>`}` : ''}
					</div>
				</div>`).join('') + '</div>';
		}
		html += '<div class="kmm-leer kmm-suche-leer" hidden><strong>Kein Schütze gefunden.</strong>Suchbegriff ändern oder Filter auf „Alle“ stellen.</div>';
		el.innerHTML = html;
		schuetzenFiltern();
	}

	function schuetzenFiltern() {
		const liste = $('#kmm-schuetzenliste');
		if (!liste) { return; }
		const q = norm(state.schuetzenSuche);
		const f = state.schuetzen.length >= SUCHE_AB ? (state.schuetzenFilter || 'alle') : 'alle';
		let n = 0;
		$$('.kmm-karte', liste).forEach((k) => {
			const passt = (!q || passtSuche(k.dataset.suche, q)) && (f === 'alle' || (f === 'mit') === (k.dataset.gemeldet === '1'));
			k.hidden = !passt;
			if (passt) { n++; }
		});
		const aktiv = q !== '' || f !== 'alle';
		const t = $('.kmm-suche-treffer');
		if (t) { t.textContent = aktiv ? mz(n, 'Treffer', 'Treffer') : ''; }
		const leer = $('#kmm-tab-schuetzen .kmm-suche-leer');
		if (leer) { leer.hidden = n > 0; }
	}

	$('#kmm-tab-schuetzen').addEventListener('input', (ev) => {
		if (ev.target.id === 'kmm-suche-schuetzen') {
			state.schuetzenSuche = ev.target.value;
			schuetzenFiltern();
		}
	});

	function schuetzeFormular(s) {
		const angebot = s ? s.hoehermeldung_angebot || {} : {};
		const hm = s ? s.hoehermeldungen || {} : {};
		let hmHtml = '';
		Object.keys(angebot).forEach((b) => {
			hmHtml += `<label for="kmm-s-hm-${b}">Höhermeldung ${h(BEREICHE[b] || b)}</label><select id="kmm-s-hm-${b}" name="hm_${b}"><option value="">– keine –</option>${Object.entries(angebot[b]).map(([st, name]) => `<option value="${h(st)}" ${hm[b] === st ? 'selected' : ''}>${h(name)}</option>`).join('')}</select>`;
		});
		if (!s) {
			hmHtml = '<p class="kmm-muted">Höhermeldungen können nach dem Anlegen über „Bearbeiten“ gesetzt werden.</p>';
		} else if (!hmHtml) {
			hmHtml = '<p class="kmm-muted">Für dieses Alter ist keine Höhermeldung möglich.</p>';
		}
		return `<h3>${s ? 'Schütze bearbeiten' : 'Neuer Schütze'}</h3>
			<label for="kmm-s-nachname">Nachname</label><input id="kmm-s-nachname" name="nachname" required autocomplete="off" value="${h(s ? s.nachname : '')}">
			<label for="kmm-s-vorname">Vorname</label><input id="kmm-s-vorname" name="vorname" required autocomplete="off" value="${h(s ? s.vorname : '')}">
			<label for="kmm-s-geburtsdatum">Geburtsdatum</label><input id="kmm-s-geburtsdatum" type="date" name="geburtsdatum" required value="${h(s ? s.geburtsdatum : '')}">
			<label for="kmm-s-geschlecht">Geschlecht</label><select id="kmm-s-geschlecht" name="geschlecht" required><option value="">– bitte wählen –</option><option value="m" ${s && s.geschlecht === 'm' ? 'selected' : ''}>männlich</option><option value="w" ${s && s.geschlecht === 'w' ? 'selected' : ''}>weiblich</option></select>
			<label for="kmm-s-nr">Mitgliedsnummer (9 Ziffern)</label><input id="kmm-s-nr" name="mitgliedsnummer" required inputmode="numeric" pattern="[0-9]{9}" maxlength="9" autocomplete="off" value="${h(s ? s.mitgliedsnummer : '')}">
			${hmHtml}
			${dialogButtons('Speichern')}`;
	}

	async function schuetzeSpeichern(form, id) {
		const fd = new FormData(form);
		const daten = { nachname: fd.get('nachname'), vorname: fd.get('vorname'), geburtsdatum: fd.get('geburtsdatum'), geschlecht: fd.get('geschlecht'), mitgliedsnummer: fd.get('mitgliedsnummer'), hoehermeldungen: {} };
		Object.keys(BEREICHE).forEach((b) => { if (fd.get('hm_' + b)) { daten.hoehermeldungen[b] = fd.get('hm_' + b); } });
		const r = await api(id ? 'PUT' : 'POST', id ? `schuetzen/${id}` : 'schuetzen', daten);
		state.schuetzen = r.schuetzen;
		if (r.konflikte) {
			state.meldung = await api('GET', 'meldung');
		}
		renderAll();
		toast(r.warnungen && r.warnungen.length ? 'Gespeichert. ' + r.warnungen.join(' ') : 'Schütze gespeichert.', r.warnungen && r.warnungen.length ? 'warn' : 'ok');
	}

	$('#kmm-tab-schuetzen').addEventListener('click', (ev) => {
		const chip = ev.target.closest('[data-schuetzen-filter]');
		if (!chip) { return; }
		state.schuetzenFilter = chip.dataset.schuetzenFilter;
		$$('#kmm-tab-schuetzen [data-schuetzen-filter]').forEach((b) => b.setAttribute('aria-pressed', String(b === chip)));
		schuetzenFiltern();
	});

	$('#kmm-tab-schuetzen').addEventListener('click', async (ev) => {
		const b = ev.target.closest('button[data-action]');
		if (!b) { return; }
		const id = Number(b.dataset.id || 0);
		const s = state.schuetzen.find((x) => x.id === id);
		if (b.dataset.action === 'schuetze-neu') {
			dialog(schuetzeFormular(null), (form) => schuetzeSpeichern(form, 0));
		} else if (b.dataset.action === 'schuetze-bearbeiten') {
			dialog(schuetzeFormular(s), (form) => schuetzeSpeichern(form, id));
		} else if (b.dataset.action === 'schuetze-loeschen') {
			if (!await bestaetigen({ titel: `${s.nachname}, ${s.vorname} löschen?`, text: 'Der Schütze wird aus der Schützenliste des Vereins entfernt.', ok: 'Löschen', gefahr: true })) { return; }
			try {
				const r = await api('DELETE', `schuetzen/${id}`);
				state.schuetzen = r.schuetzen;
				renderAll();
				toast('Schütze gelöscht.');
			} catch (e) { toast(e.message, 'error'); }
		} else if (b.dataset.action === 'schuetze-melden') {
			tabWechsel('meldung');
			await angebotLaden(id);
		}
	});

	// ----- 2 Meldung ------------------------------------------------------------------------------

	async function angebotLaden(schuetzeId) {
		state.angebotFuer = schuetzeId;
		state.angebot = null;
		renderMeldung();
		try {
			state.angebot = await api('GET', `schuetzen/${schuetzeId}/angebot`);
		} catch (e) {
			toast(e.message, 'error');
			state.angebotFuer = null;
		}
		renderMeldung();
		renderSchrittNav();
	}

	function renderMeldung() {
		const el = $('#kmm-tab-meldung');
		const m = state.meldung;
		const rw = schreibbar();
		let html = '';
		let angebotHtml = '';
		{
			let teil = '';
		if (rw) {
			teil += '<div class="kmm-melden"><h2>Starter melden</h2>';
			const gross = state.schuetzen.length >= SUCHE_AB;
			const option = (s) => `<option value="${s.id}" ${state.angebotFuer === s.id ? 'selected' : ''}>${h(s.nachname)}, ${h(s.vorname)}${s.meldungen ? ` · ${mz(s.meldungen, 'Meldung', 'Meldungen')}` : ''}</option>`;
			const ohne = state.schuetzen.filter((s) => !s.meldungen);
			const mit = state.schuetzen.filter((s) => s.meldungen);
			// Bei großen Vereinen: noch nicht gemeldete Schützen zuerst, damit man die Liste abarbeiten kann.
			const optionen = gross && ohne.length && mit.length
				? `<optgroup label="Noch nicht gemeldet (${ohne.length})">${ohne.map(option).join('')}</optgroup><optgroup label="Schon gemeldet (${mit.length})">${mit.map(option).join('')}</optgroup>`
				: state.schuetzen.map(option).join('');
			teil += `<div class="kmm-form kmm-inline kmm-schuetze-wahl"><label for="kmm-melden-schuetze">Schütze wählen, um Disziplinen zu melden</label>
				${gross ? '<input type="search" id="kmm-melden-suche" class="kmm-melden-suche" placeholder="Namen tippen, z. B. Müller oder Karin" autocomplete="off" aria-controls="kmm-melden-schuetze kmm-melden-treffer"><div id="kmm-melden-treffer" class="kmm-melden-treffer" aria-live="polite"></div><span class="kmm-muted kmm-melden-oder">oder aus der Liste wählen:</span>' : ''}
				<select id="kmm-melden-schuetze"><option value="">– Schütze –</option>${optionen}</select></div>`;
			if (state.angebotFuer && state.angebot === null) {
				teil += '<p class="kmm-muted">Lade Disziplinen …</p>';
			} else if (state.angebot) {
				const s = state.schuetzen.find((x) => x.id === state.angebotFuer);
				teil += `<h3>Disziplinen mit Startrecht für ${h(s ? s.nachname + ', ' + s.vorname : '')}</h3>`;
				if (state.angebot.length === 0) {
					teil += '<p>Keine angebotene Disziplin mit Startrecht.</p>';
				}
				const gruppen = new Map();
				const labels = {};
				state.angebot.forEach((a) => { const g = gruppeVon(a); labels[g.key] = g.label; if (!gruppen.has(g.key)) { gruppen.set(g.key, []); } gruppen.get(g.key).push(a); });
				let erste = true;
				state.offeneGruppen = state.offeneGruppen || {};
				$$('#kmm-tab-meldung details.kmm-gruppe-details').forEach((d) => { state.offeneGruppen[d.dataset.gruppe] = d.open; });
				gruppen.forEach((liste, code) => {
					const gemeldet = liste.filter((a) => a.gemeldet_id).length;
					const offen = code in state.offeneGruppen ? state.offeneGruppen[code] : (erste || gemeldet);
					teil += `<details class="kmm-gruppe-details" data-gruppe="${h(code)}" ${offen ? 'open' : ''}><summary>${h(labels[code])} <span class="kmm-muted">(${mz(liste.length, 'Disziplin', 'Disziplinen')}${gemeldet ? ', ' + gemeldet + ' gemeldet' : ''})</span></summary><div class="kmm-liste kmm-liste-kompakt">`;
					teil += liste.map((a) => `
					<div class="kmm-karte ${a.gemeldet_id ? 'is-gemeldet' : ''}">
						<div class="kmm-karte-haupt">
							<strong>${h(a.kennzahl)} ${h(a.bezeichnung)}</strong>
							<span class="kmm-muted">${a.startrecht ? `${h(a.klasse)}${a.startklasse !== a.klasse ? ' → ' + h(a.startklasse) : ''} (${h(a.kennzahl_voll)})${a.mannschaftspool ? ' · Mannschaft: ' + h(a.mannschaftspool) : ''}${a.typ === 'mixteam' ? '' : ' · ' + geld(a.startgeld)}` : 'nur als Para-Klasse'}</span>
							${(a.hinweise || []).map((x) => `<div class="kmm-hinweis-klein">${h(x)}</div>`).join('')}
							${a.para_optionen.length ? (() => {
								const sel = `<select class="kmm-select" id="kmm-para-${a.disziplin_id}" data-para-for="${a.disziplin_id}"><option value="">${a.startrecht ? '– keine (reguläre Klasse) –' : '– bitte wählen –'}</option>${a.para_optionen.map((p) => `<option value="${p.id}">${h(p.bezeichnung)}</option>`).join('')}</select>`;
								// Mit regulärem Startrecht ist Para die Ausnahme: zugeklappt, damit die Liste ruhig bleibt.
								return a.startrecht
									? `<details class="kmm-para"><summary>Para-Klasse angeben (nur wenn zutreffend)</summary><label class="kmm-sr" for="kmm-para-${a.disziplin_id}">Para-Klasse</label>${sel}</details>`
									: `<label class="kmm-muted" for="kmm-para-${a.disziplin_id}">Para-Klasse</label>${sel}`;
							})() : ''}
						</div>
						<div class="kmm-karte-aktionen">${a.gemeldet_id ? '<span class="kmm-badge kmm-badge-ok">gemeldet</span>' : `<button type="button" class="kmm-button kmm-button-small" data-action="einzel-anlegen" data-disziplin="${a.disziplin_id}">Melden</button>`}</div>
					</div>`).join('');
					teil += '</div></details>';
					erste = false;
				});
			}
			teil += '</div>';
		}
			angebotHtml = teil;
		}
		html += `<h2>Gemeldete Starter (${m.einzelmeldungen.length})</h2>`;
		if (m.einzelmeldungen.length >= 15) {
			html += `<div class="kmm-suchleiste" role="search"><label class="kmm-suche"><span class="kmm-sr">Gemeldete Starter durchsuchen</span><input type="search" id="kmm-suche-meldungen" placeholder="Name oder Disziplin suchen" autocomplete="off" value="${h(state.meldungenSuche || '')}"></label>
				<label class="kmm-check-klein"><input type="checkbox" id="kmm-nur-ohne-ergebnis" ${state.nurOhneErgebnis ? 'checked' : ''}> nur ohne Ergebnis</label>
				<span class="kmm-muted kmm-suche-treffer" aria-live="polite"></span></div>`;
		}
		if (m.ohne_ergebnis > 0) {
			html += `<div class="kmm-alert kmm-alert-warn" id="kmm-ohne-ergebnis">${ohneErgebnisText(m.ohne_ergebnis)}</div>`;
		}
		if (m.einstellungen.nicht_meldung_sichtbar && m.einzelmeldungen.length > 0) {
			html += '<p class="kmm-muted kmm-nm-hinweis">„Nicht zur LM“: Haken setzen, wenn ein Schütze in dieser Disziplin nicht zur Landesmeisterschaft weitergemeldet werden soll.</p>';
		}
		if (m.einzelmeldungen.length === 0) {
			html += `<div class="kmm-leer"><strong>Noch keine Starter gemeldet.</strong>${rw ? (state.schuetzen.length ? 'Oben einen Schützen wählen und die Disziplinen melden.' : 'Zuerst unter „1 Schützen“ die Schützen anlegen.') : ''}</div>`;
		} else {
			let aktuell = null;
			const zeigeStatus = statusSichtbar();
			html += '<div class="kmm-tabelle-wrap"><table class="kmm-tabelle kmm-meldetabelle"><thead><tr><th>Name</th><th>Startklasse</th><th>Ergebnis</th>' + (m.einstellungen.nicht_meldung_sichtbar ? '<th title="Haken setzen, wenn dieser Start nicht zur Landesmeisterschaft weitergemeldet werden soll.">Nicht zur LM</th>' : '') + '<th>Mannsch.</th><th class="r">Startgeld</th>' + (zeigeStatus ? '<th>Status</th>' : '') + '<th></th></tr></thead><tbody>';
			m.einzelmeldungen.forEach((e) => {
				if (e.kennzahl !== aktuell) {
					aktuell = e.kennzahl;
					html += `<tr class="kmm-gruppe"><th colspan="9">${h(e.kennzahl)} ${h(e.disziplin)}</th></tr>`;
				}
				// „z. B.“ davor: ein grauer Beispielwert allein sieht aus wie ein eingetragenes Ergebnis.
				const ergebnisPh = e.ergebnis_format === 'zehntel' ? 'z. B. 389,4' : 'z. B. 375';
				const abg = !!e.abgemeldet_am;
				const rwZ = rw && e.zustaendig !== false; // Referent: fremde Disziplin nur lesen
				html += `<tr class="${e.konflikt || !e.startrecht ? 'is-konflikt' : ''} ${abg ? 'is-abgemeldet' : ''}" data-id="${e.id}" data-suche="${h(norm(e.nachname + ' ' + e.vorname + ' ' + e.vorname + ' ' + e.nachname + ' ' + e.kennzahl + ' ' + e.disziplin))}" data-ohne="${e.typ !== 'mixteam' && e.meldeergebnis === '' ? 1 : 0}">
					<td class="kmm-z-name"><strong>${h(e.nachname)}, ${h(e.vorname)}</strong>${e.para ? `<div class="kmm-muted">${h(e.para)}</div>` : ''}${e.konflikt ? `<div class="kmm-fehler">${h(e.konflikt_text)}</div>` : ''}${(e.hinweise || []).filter((x) => !/^Höhermeldung/.test(x) || true).map((x) => `<div class="kmm-hinweis-klein">${h(x)}</div>`).join('')}</td>
					<td class="kmm-z-klasse" data-label="Startklasse">${h(e.startklasse)}${e.hoehermeldung ? ' <span class="kmm-badge kmm-badge-hm" title="Höhermeldung">HM</span>' : ''} <span class="kmm-muted">${h(e.kennzahl_voll)}</span>${e.klasse !== e.startklasse ? `<div class="kmm-hinweis-klein">Altersklasse ${h(e.klasse)}</div>` : ''}</td>
					<td class="kmm-z-ergebnis" data-label="Ergebnis">${e.typ === 'mixteam' ? '<span class="kmm-muted" title="MixTeams haben keine Einzelwertung; ein Meldeergebnis wird nicht benötigt.">nicht nötig (MixTeam)</span>' : `<input type="text" inputmode="decimal" enterkeyhint="done" autocomplete="off" class="kmm-ergebnis ${e.meldeergebnis === '' ? 'is-leer' : ''}" data-action="ergebnis" data-id="${e.id}" value="${h(e.meldeergebnis)}" placeholder="${ergebnisPh}" aria-label="Meldeergebnis ${h(e.nachname)}, ${h(e.vorname)}, ${h(e.kennzahl)}" ${rwZ ? '' : 'disabled'}>`}</td>
					${m.einstellungen.nicht_meldung_sichtbar ? `<td class="kmm-z-nm" data-label="Nicht zur LM melden"><input type="checkbox" data-action="nicht-meldung" data-id="${e.id}" aria-label="${h(e.nachname)}, ${h(e.vorname)}, ${h(e.kennzahl)} nicht zur Landesmeisterschaft melden" ${e.nicht_meldung ? 'checked' : ''} ${rwZ ? '' : 'disabled'}></td>` : ''}
					<td class="kmm-z-team" data-label="Mannschaft">${e.mannschaft_nummer ? 'M<span class="kmm-lang">annschaft </span>' + e.mannschaft_nummer : (e.mannschaft_moeglich ? '<span class="kmm-muted kmm-kein">–</span>' : '')}</td>
					<td class="r kmm-z-geld" data-label="Startgeld">${e.typ === 'mixteam' ? '–' : geld(e.startgeld)}</td>
					${zeigeStatus ? `<td class="kmm-z-status" data-label="Status">${abg ? `<span class="kmm-badge kmm-badge-warn">abgemeldet</span><div class="kmm-muted">${h(e.abgemeldet_am)}${e.abmeldegrund ? ' – ' + h(e.abmeldegrund) : ''}</div>${admin && rwZ ? `<label class="kmm-startgeld-schalter"><input type="checkbox" data-action="startgeld" data-id="${e.id}" ${e.startgeld_berechnen ? 'checked' : ''}> Startgeld berechnen</label>` : `<div class="kmm-muted">Startgeld ${e.startgeld_berechnen ? 'wird berechnet' : 'entfällt'}</div>`}` : `<span class="kmm-badge kmm-status-${h(e.verarbeitungsstatus)}">${h(STATUS_LABEL[e.verarbeitungsstatus] || e.verarbeitungsstatus)}</span>${e.verarbeitungsgrund ? `<div class="kmm-fehler">${h(e.verarbeitungsgrund)}</div>` : ''}`}${e.nachgemeldet ? '<div class="kmm-muted">Nachmeldung</div>' : ''}</td>` : ''}
					<td class="kmm-aktionen">${rwZ ? `${e.konflikt && e.startrecht ? `<button type="button" class="kmm-button kmm-button-small" data-action="konflikt-ok" data-id="${e.id}">OK</button> ` : ''}${admin ? (abg ? `<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="abmeldung-aufheben" data-id="${e.id}">Abmeldung aufheben</button> ` : `<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="abmelden" data-id="${e.id}">Abmelden</button> `) : ''}<button type="button" class="kmm-button kmm-button-small kmm-button-danger kmm-button-icon" data-action="einzel-loeschen" data-id="${e.id}" title="Meldung entfernen" aria-label="Meldung von ${h(e.nachname)}, ${h(e.vorname)} in ${h(e.kennzahl)} entfernen">✕</button>` : ''}</td>
				</tr>`;
			});
			html += '</tbody></table></div>';
		}
		el.innerHTML = angebotHtml + html;
		meldungenFiltern();
		const such = $('#kmm-melden-suche');
		if (such && state.meldenSuche) { such.value = state.meldenSuche; schuetzenAuswahlKuerzen(); }
	}

	function meldungenFiltern() {
		const tab = $('#kmm-tab-meldung .kmm-meldetabelle');
		if (!tab) { return; }
		// Ohne sichtbare Suchfelder (weniger Meldungen) darf kein alter Filter mehr greifen.
		if (!$('#kmm-suche-meldungen')) { state.meldungenSuche = ''; state.nurOhneErgebnis = false; }
		const q = norm(state.meldungenSuche);
		const nurOhne = !!state.nurOhneErgebnis;
		let n = 0;
		let kopf = null;
		let kopfSichtbar = false;
		const kopfSetzen = () => { if (kopf) { kopf.hidden = !kopfSichtbar; } };
		$$('tbody tr', tab).forEach((tr) => {
			if (tr.classList.contains('kmm-gruppe')) {
				kopfSetzen();
				kopf = tr;
				kopfSichtbar = false;
				return;
			}
			const passt = (!q || passtSuche(tr.dataset.suche, q)) && (!nurOhne || tr.dataset.ohne === '1');
			tr.hidden = !passt;
			if (passt) { n++; kopfSichtbar = true; }
		});
		kopfSetzen();
		const t = $('#kmm-tab-meldung .kmm-suche-treffer');
		if (t) { t.textContent = q || nurOhne ? mz(n, 'Treffer', 'Treffer') : ''; }
	}

	// Auswahlliste im Meldeschritt auf passende Namen kürzen; bei genau einem Treffer direkt wählen.
	function schuetzenAuswahlKuerzen() {
		const sel = $('#kmm-melden-schuetze');
		const q = norm(state.meldenSuche);
		if (!sel) { return null; }
		const treffer = [];
		$$('option', sel).forEach((o) => {
			if (!o.value) { return; }
			const s = state.schuetzen.find((x) => String(x.id) === o.value);
			const passt = !q || (s && passtSuche(norm(s.nachname + ' ' + s.vorname + ' ' + (s.mitgliedsnummer || '')), q));
			o.hidden = !passt;
			o.disabled = !passt;
			if (passt) { treffer.push(o.value); }
		});
		$$('optgroup', sel).forEach((g) => { g.hidden = !$$('option', g).some((o) => !o.hidden); });
		// Treffer zusätzlich als Buttons zeigen: Das Kürzen der Auswahlliste sieht man auf dem
		// Handy/iPad nicht, und ein Name soll sich mit einem Tipp wählen lassen.
		const box = $('#kmm-melden-treffer');
		if (box) {
			const MAX = 8;
			const knoepfe = treffer.slice(0, MAX).map((v) => {
				const s = state.schuetzen.find((x) => String(x.id) === v);
				return `<button type="button" class="kmm-chip" data-action="melden-treffer" data-id="${h(v)}" aria-pressed="${Number(v) === state.angebotFuer}">${h(s.nachname)}, ${h(s.vorname)}</button>`;
			}).join('');
			box.innerHTML = !q ? '' : (treffer.length === 0
				? '<span class="kmm-muted">Kein Schütze gefunden. Ist er unter „1 Schützen“ angelegt?</span>'
				: knoepfe + (treffer.length > MAX ? `<span class="kmm-muted">und ${treffer.length - MAX} weitere – weiter tippen</span>` : ''));
		}
		return treffer;
	}

	$('#kmm-tab-meldung').addEventListener('input', async (ev) => {
		const t = ev.target;
		if (t.id === 'kmm-suche-meldungen') {
			state.meldungenSuche = t.value;
			meldungenFiltern();
		} else if (t.id === 'kmm-melden-suche') {
			state.meldenSuche = t.value;
			const treffer = schuetzenAuswahlKuerzen();
			// Bleibt genau ein Name übrig, wird er nach einer kurzen Tipppause gewählt
			// (sonst gingen beim Neuzeichnen getippte Buchstaben verloren).
			clearTimeout(meldenSucheTimer);
			if (treffer && treffer.length === 1 && Number(treffer[0]) !== state.angebotFuer) {
				meldenSucheTimer = setTimeout(async () => {
					await angebotLaden(Number(treffer[0]));
					const neu = $('#kmm-melden-suche');
					if (neu) { neu.focus(); neu.setSelectionRange(neu.value.length, neu.value.length); }
				}, 700);
			}
		}
	});
	let meldenSucheTimer = 0;

	const ohneErgebnisText = (n) => `${mz(n, 'Meldung', 'Meldungen')} ohne Meldeergebnis. Bitte das Ergebnis der Vereinsmeisterschaft eintragen – es wird für die Startplanung benötigt.`;

	$('#kmm-tab-meldung').addEventListener('change', async (ev) => {
		const t = ev.target;
		if (t.id === 'kmm-nur-ohne-ergebnis') {
			state.nurOhneErgebnis = t.checked;
			meldungenFiltern();
			return;
		}
		if (t.id === 'kmm-melden-schuetze') {
			if (t.value) { await angebotLaden(Number(t.value)); } else { state.angebotFuer = null; state.angebot = null; renderMeldung(); }
			return;
		}
		if (t.dataset.action === 'startgeld') {
			try {
				const r = await api('PUT', `meldung/einzel/${Number(t.dataset.id)}/startgeld`, { berechnen: t.checked });
				state.meldung = r.meldung;
				renderAll();
				toast(t.checked ? 'Startgeld wird berechnet.' : 'Startgeld entfällt.');
			} catch (e) { toast(e.message, 'error'); renderAll(); }
			return;
		}
		if (t.dataset.action === 'ergebnis' || t.dataset.action === 'nicht-meldung') {
			const id = Number(t.dataset.id);
			const body = t.dataset.action === 'ergebnis' ? { meldeergebnis: t.value } : { nicht_meldung: t.checked };
			t.classList.add('is-busy');
			try {
				const r = await api('PUT', `meldung/einzel/${id}`, body);
				state.meldung = r.meldung;
				// Nur die betroffene Zeile und die Zusammenfassung aktualisieren – die Tabelle bleibt
				// stehen, damit ein bereits angeklicktes nächstes Feld nicht verschwindet.
				const em = r.einzelmeldung || state.meldung.einzelmeldungen.find((x) => x.id === id);
				if (em && t.dataset.action === 'ergebnis') {
					t.value = em.meldeergebnis;
					t.classList.toggle('is-leer', em.meldeergebnis === '');
				}
				renderKopf();
				renderEinreichen();
				const warn = $('#kmm-ohne-ergebnis');
				if (warn) { warn.hidden = state.meldung.ohne_ergebnis === 0; if (!warn.hidden) { warn.textContent = ohneErgebnisText(state.meldung.ohne_ergebnis); } }
				// Kein Toast: bei 30 Ergebnissen hintereinander wäre das 30-mal dieselbe Meldung.
				// Das Feld selbst zeigt kurz, dass gespeichert wurde.
				if (t.dataset.action === 'ergebnis') {
					t.classList.add('is-gespeichert');
					setTimeout(() => { t.classList.remove('is-gespeichert'); }, 1400);
				}
			} catch (e) {
				toast(e.message, 'error');
				t.focus();
			} finally {
				t.classList.remove('is-busy');
			}
		}
	});

	$('#kmm-tab-meldung').addEventListener('click', async (ev) => {
		const b = ev.target.closest('button[data-action]');
		if (!b) { return; }
		const id = Number(b.dataset.id || 0);
		try {
			if (b.dataset.action === 'melden-treffer') {
				clearTimeout(meldenSucheTimer);
				await angebotLaden(id);
				return;
			}
			if (b.dataset.action === 'einzel-anlegen') {
				const dId = Number(b.dataset.disziplin);
				const paraSel = $(`select[data-para-for="${dId}"]`);
				b.disabled = true;
				b.textContent = '…';
				const r = await api('POST', 'meldung/einzel', { schuetze_id: state.angebotFuer, disziplin_id: dId, para_klasse_id: paraSel && paraSel.value ? Number(paraSel.value) : null });
				state.meldung = r.meldung;
				const a = (state.angebot || []).find((x) => x.disziplin_id === dId);
				if (a) { a.gemeldet_id = r.einzelmeldung.id; }
				const sch = state.schuetzen.find((x) => x.id === state.angebotFuer);
				if (sch) { sch.meldungen = (sch.meldungen || 0) + 1; }
				renderAll();
				toast(`${r.einzelmeldung.kennzahl} gemeldet (${r.einzelmeldung.kennzahl_voll}).`);
				api('GET', 'schuetzen').then((liste) => { state.schuetzen = liste; renderSchuetzen(); }).catch(() => {});
			} else if (b.dataset.action === 'einzel-loeschen') {
				if (!await bestaetigen({ titel: 'Meldung entfernen?', text: 'Der Starter wird aus dieser Disziplin entfernt.', ok: 'Entfernen', gefahr: true })) { return; }
				b.disabled = true;
				const alt = state.meldung.einzelmeldungen.find((x) => x.id === id);
				const r = await api('DELETE', `meldung/einzel/${id}`);
				state.meldung = r.meldung;
				if (alt) {
					const a = (state.angebot || []).find((x) => x.disziplin_id === alt.disziplin_id && x.gemeldet_id === id);
					if (a) { a.gemeldet_id = null; }
					const sch = state.schuetzen.find((x) => x.id === alt.schuetze_id);
					if (sch && sch.meldungen) { sch.meldungen--; }
				}
				renderAll();
				api('GET', 'schuetzen').then((liste) => { state.schuetzen = liste; renderSchuetzen(); }).catch(() => {});
			} else if (b.dataset.action === 'konflikt-ok') {
				const r = await api('POST', `meldung/einzel/${id}/bestaetigen`);
				state.meldung = r.meldung;
				renderAll();
			} else if (b.dataset.action === 'abmelden') {
				const e = state.meldung.einzelmeldungen.find((x) => x.id === id);
				dialog(`<h3>Abmelden: ${h(e ? e.nachname + ', ' + e.vorname + ' – ' + e.kennzahl + ' ' + e.disziplin : '')}</h3>
					<label>Grund (optional)</label><input name="grund" maxlength="255" placeholder="z. B. verletzt, auf Wunsch des Vereins">
					<label>Startgeld berechnen</label><select name="startgeld"><option value="">Standard (vor Veröffentlichung des Startplans: nein, danach: ja)</option><option value="1">ja</option><option value="0">nein</option></select>
					<p class="kmm-muted">Ein gebuchter Startplatz wird frei; eine Mannschaft wird ggf. als unvollständig markiert. Die Abmeldung kann aufgehoben werden.</p>
					${dialogButtons('Abmelden')}`, async (form) => {
					const fd = new FormData(form);
					const sg = fd.get('startgeld');
					const r = await api('POST', `meldung/einzel/${id}/abmelden`, { grund: fd.get('grund'), startgeld_berechnen: sg === '' ? null : sg === '1' });
					state.meldung = r.meldung;
					renderAll();
					toast(`Abgemeldet. Startgeld ${r.einzelmeldung.startgeld_berechnen ? 'wird berechnet' : 'entfällt'}.`);
				});
			} else if (b.dataset.action === 'abmeldung-aufheben') {
				if (!await bestaetigen({ titel: 'Abmeldung aufheben?', text: 'Die Meldung gilt danach wieder als aktiv. Ein freigegebener Startplatz wird nicht automatisch neu gebucht.', ok: 'Abmeldung aufheben' })) { return; }
				const r = await api('POST', `meldung/einzel/${id}/abmeldung-aufheben`);
				state.meldung = r.meldung;
				renderAll();
				toast('Abmeldung aufgehoben.');
			}
		} catch (e) { toast(e.message, 'error'); }
	});

	// ----- 3 Mannschaften ------------------------------------------------------------------------

	function renderMannschaften() {
		const el = $('#kmm-tab-mannschaften');
		const m = state.meldung;
		const rw = schreibbar();
		const disziplinen = new Map();
		m.einzelmeldungen.forEach((e) => {
			if (!e.mannschaft_moeglich) { return; }
			if (!disziplinen.has(e.disziplin_id)) { disziplinen.set(e.disziplin_id, { kennzahl: e.kennzahl, disziplin: e.disziplin, typ: e.typ, frei: 0, gesamt: 0, zustaendig: e.zustaendig !== false }); }
			const d = disziplinen.get(e.disziplin_id);
			d.gesamt++;
			if (!e.mannschaft_id && e.startrecht && !e.konflikt) { d.frei++; }
		});
		let html = '<h2>Mannschaften</h2><p class="kmm-muted">Mannschaften werden aus den gemeldeten Startern gebildet. Wählbar sind nur Schützen derselben Mannschaftsklasse. MixTeams bestehen aus genau einem Mann und einer Frau. Beim Einreichen müssen alle Mannschaften vollständig sein.</p>';
		if (disziplinen.size === 0) {
			html += '<div class="kmm-leer"><strong>Keine Disziplin mit Mannschaftswertung gemeldet.</strong>Mannschaften entstehen aus den Startern unter „2 Meldung“.</div>';
		}
		disziplinen.forEach((d, dId) => {
			const teams = m.mannschaften.filter((t) => t.disziplin_id === dId);
			html += `<div class="kmm-block"><div class="kmm-toolbar"><h3>${h(d.kennzahl)} ${h(d.disziplin)} <span class="kmm-muted">(${d.gesamt} gemeldet, ${d.frei} ohne Mannschaft)</span></h3>${rw && d.zustaendig && d.frei > 0 ? `<button type="button" class="kmm-button kmm-button-small" data-action="mannschaft-neu" data-disziplin="${dId}">Neue Mannschaft</button>` : ''}</div>`;
			if (teams.length === 0) {
				html += '<p class="kmm-muted">Noch keine Mannschaft.</p>';
			}
			html += teams.map((t) => `<div class="kmm-karte ${t.vollstaendig ? '' : 'is-unvollstaendig'}">
				<div class="kmm-karte-haupt"><strong>Mannschaft ${t.nummer}</strong> <span class="kmm-muted">${h(t.klasse)}</span>${t.vollstaendig ? '' : `<span class="kmm-badge kmm-badge-warn">unvollständig (${t.mitglieder.length}/${t.groesse})</span>${t.unvollstaendig_grund && t.mitglieder.length >= t.groesse ? `<div class="kmm-fehler">${h(t.unvollstaendig_grund)}</div>` : ''}`}
					<div>${t.mitglieder.map((x) => h(x.nachname + ', ' + x.vorname) + (x.konflikt ? ' <span class="kmm-fehler">(Konflikt)</span>' : '')).join(' · ')}</div>
					${t.startgeld > 0 ? `<div class="kmm-muted">Mannschaftsstartgeld ${geld(t.startgeld)}</div>` : ''}</div>
				<div class="kmm-karte-aktionen">${rw && t.zustaendig !== false ? `<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="mannschaft-bearbeiten" data-id="${t.id}" data-disziplin="${dId}">Bearbeiten</button> <button type="button" class="kmm-button kmm-button-small kmm-button-danger kmm-button-icon" data-action="mannschaft-loeschen" data-id="${t.id}" title="Mannschaft auflösen" aria-label="Mannschaft ${t.nummer} auflösen">✕</button>` : ''}</div>
			</div>`).join('');
			html += '</div>';
		});
		el.innerHTML = html;
	}

	function mixHinweis(info, groesse) {
		if (info && info.typ === 'mixteam') {
			return 'Ein MixTeam besteht aus genau einem Mann und einer Frau derselben Mannschaftsklasse. Nach der ersten Auswahl sperrt die Liste alle, die nicht dazupassen – mit Grund.';
		}
		return `Bis zu ${groesse} Schützen derselben Mannschaftsklasse wählen. Nach der ersten Auswahl sperrt die Liste alle, die nicht dazupassen – mit Grund.`;
	}

	async function mannschaftDialog(dId, teamId) {
		const kandidaten = await api('GET', `meldung/mannschaften/kandidaten?disziplin_id=${dId}${teamId ? '&mannschaft_id=' + teamId : ''}`);
		const team = teamId ? state.meldung.mannschaften.find((t) => t.id === teamId) : null;
		const groesse = team ? team.groesse : (kandidaten[0] ? state.meldung.einzelmeldungen.find((e) => e.disziplin_id === dId && e.typ === 'mixteam') ? 2 : 3 : 3);
		const info = state.meldung.einzelmeldungen.find((e) => e.disziplin_id === dId);
		dialog(`<h3>${team ? 'Mannschaft ' + team.nummer : 'Neue Mannschaft'} – ${h(info ? info.kennzahl + ' ' + info.disziplin : '')}</h3>
			<p class="kmm-muted">${mixHinweis(info, groesse)}</p>
			${kandidaten.length === 0 ? '<p>Keine passenden Schützen ohne Mannschaft.</p>' : ''}
			<div class="kmm-kandidaten-status" role="status" aria-live="polite"></div>
			<div class="kmm-kandidaten">${kandidaten.map((k) => `<label class="kmm-check"><input type="checkbox" name="mitglied" value="${k.id}" data-pool="${h(k.mannschaftspool || '')}" data-geschlecht="${h(k.geschlecht)}" ${k.mitglied ? 'checked' : ''}> <span class="kmm-check-text"><strong>${h(k.nachname)}, ${h(k.vorname)}</strong><span class="kmm-muted">${h(k.klasse)}${k.mannschaftspool && k.mannschaftspool !== k.klasse ? ' · Mannschaftsklasse ' + h(k.mannschaftspool) : ''}</span><em class="kmm-check-grund"></em></span></label>`).join('')}</div>
			${dialogButtons('Speichern')}`, async (form) => {
			const ids = Array.from(form.querySelectorAll('input[name=mitglied]:checked')).map((c) => Number(c.value));
			const r = await api(team ? 'PUT' : 'POST', team ? `meldung/mannschaften/${team.id}` : 'meldung/mannschaften', { disziplin_id: dId, einzelmeldung_ids: ids });
			state.meldung = r.meldung;
			renderAll();
			toast(`Mannschaft ${r.mannschaft.nummer} gespeichert${r.mannschaft.vollstaendig ? '' : ' (noch unvollständig)'}.`);
		});
		// Auswahl auf gleichen Pool und die MixTeam-Regel begrenzen. Gesperrte Zeilen sagen,
		// warum sie gesperrt sind – sonst sieht man der Liste nicht an, was passiert ist.
		const d = document.getElementById('kmm-dialog');
		const mix = info && info.typ === 'mixteam';
		const status = d.querySelector('.kmm-kandidaten-status');
		d.addEventListener('change', function limit() {
			const checked = Array.from(d.querySelectorAll('input[name=mitglied]:checked'));
			const pool = checked.length ? checked[0].dataset.pool : '';
			d.querySelectorAll('input[name=mitglied]').forEach((c) => {
				const zeile = c.closest('.kmm-check');
				const grund = zeile.querySelector('.kmm-check-grund');
				if (c.checked) {
					c.disabled = false;
					zeile.className = 'kmm-check is-gewaehlt';
					grund.textContent = '';
					return;
				}
				let ok = true;
				let text = '';
				if (checked.length && c.dataset.pool !== pool) {
					ok = false;
					text = 'andere Mannschaftsklasse';
				} else if (mix && checked.some((x) => x.dataset.geschlecht === c.dataset.geschlecht)) {
					ok = false;
					text = c.dataset.geschlecht === 'w' ? 'MixTeam: eine Schützin ist schon dabei' : 'MixTeam: ein Schütze ist schon dabei';
				} else if (checked.length >= groesse) {
					ok = false;
					text = 'Mannschaft ist voll';
				}
				c.disabled = !ok;
				zeile.className = 'kmm-check' + (ok ? (checked.length ? ' is-waehlbar' : '') : ' is-gesperrt');
				grund.textContent = text;
			});
			if (status) {
				if (!checked.length) {
					status.textContent = '';
					status.hidden = true;
					return;
				}
				const rest = groesse - checked.length;
				status.hidden = false;
				status.textContent = (mix ? 'MixTeam' : pool) + ' · ' + checked.length + ' von ' + groesse + ' gewählt'
					+ (rest > 0 ? ', noch ' + rest + ' möglich' : ' – vollständig');
			}
		});
		d.dispatchEvent(new Event('change'));
	}

	$('#kmm-tab-mannschaften').addEventListener('click', async (ev) => {
		const b = ev.target.closest('button[data-action]');
		if (!b) { return; }
		try {
			if (b.dataset.action === 'mannschaft-neu') {
				await mannschaftDialog(Number(b.dataset.disziplin), null);
			} else if (b.dataset.action === 'mannschaft-bearbeiten') {
				await mannschaftDialog(Number(b.dataset.disziplin), Number(b.dataset.id));
			} else if (b.dataset.action === 'mannschaft-loeschen') {
				if (!await bestaetigen({ titel: 'Mannschaft auflösen?', text: 'Die Einzelmeldungen bleiben erhalten.', ok: 'Auflösen', gefahr: true })) { return; }
				const r = await api('DELETE', `meldung/mannschaften/${b.dataset.id}`);
				state.meldung = r.meldung;
				renderAll();
			}
		} catch (e) { toast(e.message, 'error'); }
	});

	// ----- 4 Prüfen & Einreichen ------------------------------------------------------------------

	function renderEinreichen() {
		const el = $('#kmm-tab-einreichen');
		const m = state.meldung;
		const rw = schreibbar();
		const p = m.pruefung;
		let html = '<h2>Prüfen und einreichen</h2>';
		html += `<h3>Ansprechpartner</h3><p class="kmm-muted">In der Regel der Sportleiter, der die Meldung erstellt. Pflicht beim Einreichen; erhält alle Mails zur Meldung.</p>
			<form class="kmm-form" id="kmm-ansprechpartner"><label for="kmm-ap-name">Name</label><input id="kmm-ap-name" name="name" required autocomplete="name" value="${h(m.ansprechpartner.name)}" ${rw ? '' : 'disabled'}>
			<label for="kmm-ap-email">E-Mail</label><input id="kmm-ap-email" type="email" name="email" required autocomplete="email" autocapitalize="none" spellcheck="false" value="${h(m.ansprechpartner.email)}" ${rw ? '' : 'disabled'}>
			<label for="kmm-ap-telefon">Telefon (optional)</label><input id="kmm-ap-telefon" type="tel" name="telefon" autocomplete="tel" value="${h(m.ansprechpartner.telefon)}" ${rw ? '' : 'disabled'}>
			${rw ? '<p class="kmm-autosave" id="kmm-ap-status" role="status" aria-live="polite">Wird automatisch gespeichert, sobald ein Feld verlassen wird.</p><button type="submit" class="kmm-sr">Ansprechpartner speichern</button>' : ''}</form>`;
		html += `<h3>Startgeldvorschau (vorläufig)</h3><table class="kmm-tabelle kmm-summe"><tbody><tr><td>Einzelmeldungen (${m.einzelmeldungen.length})</td><td class="r">${geld(m.startgeld.einzel)}</td></tr><tr><td>Mannschaften (${m.mannschaften.length})</td><td class="r">${geld(m.startgeld.mannschaften)}</td></tr><tr><th scope="row">Summe</th><th class="r">${geld(m.startgeld.summe)}</th></tr></tbody></table><p class="kmm-muted">Maßgeblich ist die Abrechnung des KSV nach der Startrechtsprüfung.</p>`;
		html += `<div id="kmm-pruefung">${pruefungHtml()}</div>`;
		html += `<p class="kmm-muted" style="margin-top:16px"><a href="${h(cfg.pdfUrl)}">Meldung als PDF herunterladen</a></p>`;
		el.innerHTML = html;
	}

	function pruefungHtml() {
		const m = state.meldung;
		const rw = schreibbar();
		const p = m.pruefung;
		let html = '<h3>Prüfung</h3>';
		if (p.fehler.length) {
			html += '<div class="kmm-alert kmm-alert-error"><strong>Einreichen noch nicht möglich:</strong><ul>' + p.fehler.map((f) => `<li>${h(f)}</li>`).join('') + '</ul></div>';
		}
		if (p.warnungen.length) {
			html += '<div class="kmm-alert kmm-alert-warn"><ul>' + p.warnungen.map((f) => `<li>${h(f)}</li>`).join('') + '</ul></div>';
		}
		if (!p.fehler.length && !p.warnungen.length && m.status !== 'eingereicht') {
			html += '<div class="kmm-alert kmm-alert-ok">Alles vollständig. Die Meldung kann eingereicht werden.</div>';
		}
		if (admin) {
			if (m.status === 'eingereicht' || m.status === 'verarbeitet') {
				html += `<div class="kmm-alert kmm-alert-ok">Eingereicht am ${h(m.eingereicht_am)} Uhr.</div>`;
			} else {
				html += '<div class="kmm-alert kmm-alert-warn">Die Meldung ist nicht eingereicht (Status ' + h(m.status) + '). Einreichen kann nur der Verein.</div>';
			}
			html += m.admin_voll ? `<h3>Nachmeldung freischalten</h3>` : '';
			html += m.admin_voll ? `<p class="kmm-muted">Der Verein kann seine Meldung bis zum angegebenen Zeitpunkt selbst wieder bearbeiten (auch nach Meldeschluss). Leer lassen, um die Freischaltung zu beenden.</p>
				<form class="kmm-form kmm-inline" id="kmm-nachmeldung" ${rw && m.admin_voll ? '' : 'hidden'}><label for="kmm-nachmeldung-bis">Freigeschaltet bis</label><input type="datetime-local" id="kmm-nachmeldung-bis" name="bis" value="${h(m.nachmeldung_bis_input || '')}"> <button type="submit" class="kmm-button kmm-button-secondary">Speichern</button></form>` : '';
		} else if (m.status === 'eingereicht') {
			html += `<div class="kmm-alert kmm-alert-ok">Eingereicht am ${h(m.eingereicht_am)} Uhr. Eine Bestätigung wurde per E-Mail versandt.</div>`;
			if (m.phase.schreibbar) {
				html += '<button type="button" class="kmm-button kmm-button-secondary" data-action="oeffnen">Meldung wieder öffnen</button>';
			}
		} else if (rw) {
			html += `<button type="button" class="kmm-button" data-action="einreichen" ${p.fehler.length ? 'disabled' : ''}>Meldung verbindlich einreichen</button>`;
		}
		return html;
	}

	// Ansprechpartner speichern, ohne das Formular neu zu zeichnen (der Fokus bleibt, wo er ist).
	let apGespeichert = null;
	let apLaeuft = Promise.resolve();
	// Speichervorgänge laufen nacheinander, damit eine ältere Antwort keinen neueren Stand überschreibt.
	function ansprechpartnerSpeichern(form, ausdruecklich) {
		apLaeuft = apLaeuft.then(() => ansprechpartnerSpeichernJetzt(form, ausdruecklich));
		return apLaeuft;
	}
	async function ansprechpartnerSpeichernJetzt(form, ausdruecklich) {
		const fd = new FormData(form);
		const daten = { name: String(fd.get('name') || '').trim(), email: String(fd.get('email') || '').trim(), telefon: String(fd.get('telefon') || '').trim() };
		const schluessel = JSON.stringify(daten);
		const a = state.meldung.ansprechpartner;
		apGespeichert = apGespeichert || JSON.stringify({ name: a.name || '', email: a.email || '', telefon: a.telefon || '' });
		const status = $('#kmm-ap-status');
		if (schluessel === apGespeichert && !ausdruecklich) { return; }
		if (!daten.name || !daten.email || !form.checkValidity()) {
			if (ausdruecklich) { form.reportValidity(); }
			if (status) { status.textContent = 'Noch nicht gespeichert: Name und gültige E-Mail-Adresse fehlen.'; status.className = 'kmm-autosave is-offen'; }
			return;
		}
		if (status) { status.textContent = 'Wird gespeichert …'; status.className = 'kmm-autosave'; }
		try {
			const r = await api('PUT', 'meldung/ansprechpartner', daten);
			state.meldung = r.meldung;
			apGespeichert = schluessel;
			renderKopf();
			const pr = $('#kmm-pruefung');
			if (pr) { pr.innerHTML = pruefungHtml(); }
			if (status) { status.textContent = '✓ Gespeichert'; status.className = 'kmm-autosave is-ok'; }
		} catch (e) {
			if (status) { status.textContent = e.message; status.className = 'kmm-autosave is-fehler'; }
			toast(e.message, 'error');
		}
	}

	$('#kmm-tab-einreichen').addEventListener('focusout', (ev) => {
		const form = ev.target.closest && ev.target.closest('#kmm-ansprechpartner');
		if (form && schreibbar()) { ansprechpartnerSpeichern(form, false); }
	});

	$('#kmm-tab-einreichen').addEventListener('submit', async (ev) => {
		if (ev.target.id === 'kmm-nachmeldung') {
			ev.preventDefault();
			try {
				const r = await api('PUT', 'meldung/nachmeldung', { bis: new FormData(ev.target).get('bis') });
				state.meldung = r.meldung;
				renderAll();
				toast(r.meldung.nachmeldung_bis ? 'Nachmeldung freigeschaltet bis ' + r.meldung.nachmeldung_bis + ' Uhr.' : 'Freischaltung beendet.');
			} catch (e) { toast(e.message, 'error'); }
			return;
		}
		if (ev.target.id !== 'kmm-ansprechpartner') { return; }
		ev.preventDefault();
		// Enter im Feld: sofort speichern.
		await ansprechpartnerSpeichern(ev.target, true);
	});

	$('#kmm-tab-einreichen').addEventListener('click', async (ev) => {
		const b = ev.target.closest('button[data-action]');
		if (!b) { return; }
		try {
			if (b.dataset.action === 'einreichen') {
				const w = state.meldung.pruefung.warnungen;
				if (!await bestaetigen({ titel: 'Meldung jetzt verbindlich einreichen?', text: w.length ? 'Bitte vorher diese Hinweise prüfen:' : 'Der Ansprechpartner erhält eine Bestätigung per E-Mail. Bis zum Meldeschluss lässt sich die Meldung wieder öffnen.', liste: w, ok: 'Verbindlich einreichen' })) { return; }
				b.disabled = true;
				const r = await api('POST', 'meldung/einreichen');
				state.meldung = r.meldung;
				renderAll();
				toast('Meldung eingereicht. Bestätigung per E-Mail versandt.');
			} else if (b.dataset.action === 'oeffnen') {
				if (!await bestaetigen({ titel: 'Meldung wieder öffnen?', text: 'Sie muss danach erneut eingereicht werden.', ok: 'Wieder öffnen' })) { return; }
				const r = await api('POST', 'meldung/oeffnen');
				state.meldung = r.meldung;
				renderAll();
				toast('Meldung wieder geöffnet.');
			}
		} catch (e) { toast(e.message, 'error'); b.disabled = false; }
	});

	// ----- 5 Startplätze (sichtbar erst nach Freigabe eines Wettkampftags) ---------------------------
	// Sofortbuchung: jeder Klick ist eine Buchung; die Datenbank lässt pro Platz und pro Meldung
	// nur eine zu. Ist ein anderer Verein schneller, scheitert nur dieser Klick, das Raster lädt neu.

	state.startplan = { tage: cfg.startplan || [], tagId: null, raster: null, auswahl: null, laden: false };

	function startplanTimer() {
		clearInterval(state.startplan.timer);
		if (state.tab !== 'startplan' || !state.startplan.tagId) { return; }
		const sek = (state.startplan.raster && state.startplan.raster.intervall) || 10;
		state.startplan.timer = setInterval(() => { rasterLaden(false); }, sek * 1000);
	}

	async function rasterLaden(zeigeLaden) {
		const sp = state.startplan;
		if (!sp.tagId || sp.laden) { return; }
		sp.laden = true;
		try {
			if (zeigeLaden) { sp.raster = null; renderStartplan(); }
			sp.raster = await api('GET', `startplan/${sp.tagId}`);
			// Auswahl halten, falls die Meldung noch buchbar ist.
			if (sp.auswahl && !sp.raster.meldungen.some((m) => m.id === sp.auswahl)) { sp.auswahl = null; }
			renderStartplan();
		} catch (e) {
			toast(e.message, 'error');
		} finally {
			sp.laden = false;
		}
	}

	function renderStartplan() {
		const el = $('#kmm-tab-startplan');
		const sp = state.startplan;
		$('#kmm-tab-button-startplan').hidden = sp.tage.length === 0;
		if (sp.tage.length === 0) { el.innerHTML = ''; return; }
		let html = '<h2>Startplätze buchen</h2>';
		html += `<div class="kmm-form kmm-inline"><label for="kmm-sp-tag">Wettkampftag</label><select id="kmm-sp-tag"><option value="">– bitte wählen –</option>${sp.tage.map((t) => `<option value="${t.id}" ${sp.tagId === t.id ? 'selected' : ''}>${h(t.datum_lang)} ${h(t.bezeichnung)} (${t.ohne_platz} von ${t.meldungen} ohne Platz)</option>`).join('')}</select></div>`;
		if (!sp.tagId) {
			html += '<p class="kmm-muted">Wählen Sie einen Wettkampftag. Angezeigt werden nur Tage, für die Ihr Verein Starter gemeldet hat.</p>';
			el.innerHTML = html; return;
		}
		if (!sp.raster) { el.innerHTML = html + '<p class="kmm-muted">Lade Startplan …</p>'; return; }
		const r = sp.raster;
		const rw = r.buchbar;
		html += `<div class="kmm-hinweis"><strong>${h(r.tag.bezeichnung)}</strong>${r.tag.ort ? ' · ' + h(r.tag.ort) : ''}${r.tag.buchungsfrist ? ' · Buchungsfrist ' + h(r.tag.buchungsfrist) + ' Uhr' : ''}${r.tag.hinweis ? `<div>${h(r.tag.hinweis)}</div>` : ''}</div>`;
		if (!rw) { html += `<div class="kmm-alert kmm-alert-warn">${h(r.grund)}</div>`; }
		const ohne = r.meldungen.filter((m) => !m.buchung);
		html += `<h3>Starter (${r.meldungen.length}, ${ohne.length} ohne Platz)</h3><p class="kmm-muted">${rw ? '1. Starter antippen, 2. freien Platz im passenden Durchgang antippen. Ein Starter mit Platz kann umgebucht oder freigegeben werden.' : 'Die Buchung ist geschlossen.'}</p>`;
		html += '<div class="kmm-sp-starter">' + r.meldungen.map((m) => {
			const dg = m.buchung ? r.durchgaenge.find((d) => d.id === m.buchung.durchgang_id) : null;
			const e = m.buchung ? r.einheiten.find((x) => x.id === m.buchung.einheit_id) : null;
			return `<button type="button" class="kmm-sp-melder ${m.buchung ? 'has-platz' : 'no-platz'} ${sp.auswahl === m.id ? 'is-auswahl' : ''}" data-em="${m.id}" ${rw ? '' : 'disabled'}><strong>${h(m.name)}</strong><span>${h(m.kennzahl)} ${h(m.disziplin)} · ${h(m.startklasse)}</span><span class="kmm-sp-platz">${m.buchung ? `Durchgang ${dg ? dg.nummer : '?'} (${dg ? h(dg.zeit) : ''}) · ${e ? h(e.bezeichnung) : ''}${e && e.kapazitaet > 1 ? ' Pos. ' + m.buchung.position : ''}` : 'noch kein Platz'}</span></button>`;
		}).join('') + '</div>';
		const gewaehlt = sp.auswahl ? r.meldungen.find((m) => m.id === sp.auswahl) : null;
		if (gewaehlt && gewaehlt.buchung && rw) {
			html += `<p><button type="button" class="kmm-button kmm-button-secondary kmm-button-small" data-action="platz-freigeben" data-buchung="${gewaehlt.buchung.id}">Platz von ${h(gewaehlt.name)} freigeben</button></p>`;
		}
		html += `<h3>Durchgänge und Plätze</h3>${gewaehlt ? `<p class="kmm-hinweis-klein">Gewählt: <strong>${h(gewaehlt.name)}</strong> (${h(gewaehlt.kennzahl)}). Passende Durchgänge sind hervorgehoben.</p>` : '<p class="kmm-muted">Zuerst oben einen Starter wählen.</p>'}`;
		r.durchgaenge.forEach((d) => {
			const passt = gewaehlt ? gewaehlt.durchgang_ids.includes(d.id) : false;
			const spalten = r.einheiten.filter((e) => d.einheit_ids.includes(e.id));
			html += `<div class="kmm-sp-durchgang ${passt ? 'is-passend' : ''} ${gewaehlt && !passt ? 'is-unpassend' : ''}"><div class="kmm-sp-kopf"><strong>Durchgang ${d.nummer}</strong> ${h(d.zeit)} Uhr${d.bezeichnung ? ' · ' + h(d.bezeichnung) : ''}<div class="kmm-muted">${h(d.zulassungen.join('; '))}</div></div>`;
			// Spaltenzahl = Zahl der Plätze im Durchgang; am PC sollen 12 Stände in eine Zeile passen.
			const plaetze = spalten.reduce((n, e) => n + e.kapazitaet, 0);
			html += `<div class="kmm-sp-raster" style="--kmm-sp-spalten:${Math.max(1, Math.min(plaetze, 12))}">` + spalten.map((e) => {
				let zellen = '';
				for (let pos = 1; pos <= e.kapazitaet; pos++) {
					const b = (d.belegung[e.id] || {})[pos];
					const label = e.kapazitaet > 1 ? `${h(e.bezeichnung)} · ${pos}` : h(e.bezeichnung);
					if (b && b.eigen) {
						zellen += `<button type="button" class="kmm-sp-zelle is-eigen ${gewaehlt && gewaehlt.id === b.einzelmeldung_id ? 'is-auswahl' : ''}" data-em="${b.einzelmeldung_id}" title="eigener Starter – antippen, um ihn zu wählen"><span class="kmm-sp-label">${label}</span><strong>${h(b.name)}</strong><span class="kmm-muted">${h(b.kennzahl)}</span></button>`;
					} else if (b && b.name) {
						// Nach der Veröffentlichung sind auch fremde Starter mit Namen sichtbar.
						zellen += `<div class="kmm-sp-zelle is-belegt"><span class="kmm-sp-label">${label}</span><strong>${h(b.name)}</strong><span class="kmm-muted">${h(b.verein || '')}</span></div>`;
					} else if (b) {
						zellen += `<div class="kmm-sp-zelle is-belegt"><span class="kmm-sp-label">${label}</span><span>belegt</span></div>`;
					} else {
						const aktiv = rw && gewaehlt && passt;
						zellen += `<button type="button" class="kmm-sp-zelle is-frei" data-action="buchen" data-durchgang="${d.id}" data-einheit="${e.id}" data-position="${pos}" ${aktiv ? '' : 'disabled'}><span class="kmm-sp-label">${label}</span><span>frei</span></button>`;
					}
				}
				return zellen;
			}).join('') + '</div></div>';
		});
		html += `<p class="kmm-muted kmm-sp-stand">Stand: ${h(new Date().toLocaleTimeString('de-DE'))} · aktualisiert sich alle ${r.intervall} Sekunden. <button type="button" class="kmm-link" data-action="raster-neu">Jetzt aktualisieren</button></p>`;
		el.innerHTML = html;
	}

	$('#kmm-tab-startplan').addEventListener('change', async (ev) => {
		if (ev.target.id === 'kmm-sp-tag') {
			state.startplan.tagId = ev.target.value ? Number(ev.target.value) : null;
			state.startplan.auswahl = null;
			state.startplan.raster = null;
			renderStartplan();
			await rasterLaden(true);
			startplanTimer();
		}
	});

	$('#kmm-tab-startplan').addEventListener('click', async (ev) => {
		const sp = state.startplan;
		const em = ev.target.closest('[data-em]');
		if (em && !em.disabled) {
			sp.auswahl = sp.auswahl === Number(em.dataset.em) ? null : Number(em.dataset.em);
			renderStartplan();
			return;
		}
		const b = ev.target.closest('button[data-action]');
		if (!b) { return; }
		try {
			if (b.dataset.action === 'raster-neu') {
				await rasterLaden(false);
			} else if (b.dataset.action === 'buchen') {
				if (!sp.auswahl) { toast('Bitte zuerst einen Starter wählen.', 'error'); return; }
				b.disabled = true;
				const r = await api('POST', `startplan/${sp.tagId}/buchen`, { durchgang_id: Number(b.dataset.durchgang), einheit_id: Number(b.dataset.einheit), position: Number(b.dataset.position), einzelmeldung_id: sp.auswahl });
				sp.raster = r.raster;
				sp.auswahl = null;
				renderStartplan();
				toast(r.umgebucht ? 'Umgebucht.' : 'Platz gebucht.');
				state.startplan.tage = await api('GET', 'startplan');
				renderStartplan();
			} else if (b.dataset.action === 'platz-freigeben') {
				if (!await bestaetigen({ titel: 'Platz freigeben?', text: 'Ein anderer Verein kann ihn dann buchen.', ok: 'Platz freigeben', gefahr: true })) { return; }
				const r = await api('DELETE', `startplan/buchung/${Number(b.dataset.buchung)}?tag=${sp.tagId}`);
				sp.raster = r.raster;
				sp.auswahl = null;
				renderStartplan();
				toast('Platz freigegeben.');
				state.startplan.tage = await api('GET', 'startplan');
				renderStartplan();
			}
		} catch (e) {
			toast(e.message, 'error');
			await rasterLaden(false);
		}
	});

	renderAll();
	if (admin || (state.meldung.einzelmeldungen.length > 0 && state.meldung.status !== 'eingereicht')) {
		state.tab = 'meldung';
		renderKopf();
	}
})();
