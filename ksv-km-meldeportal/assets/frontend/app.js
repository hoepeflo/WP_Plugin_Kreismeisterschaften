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
	const $ = (sel, el) => (el || document).querySelector(sel);
	const $$ = (sel, el) => Array.from((el || document).querySelectorAll(sel));

	function toast(text, art) {
		const t = document.getElementById('kmm-toast');
		t.textContent = text;
		t.className = 'kmm-toast ' + (art || 'ok');
		t.hidden = false;
		clearTimeout(toast.timer);
		toast.timer = setTimeout(() => { t.hidden = true; }, art === 'error' ? 8000 : 3500);
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

	const dialogButtons = (label) => `<div class="kmm-dialog-fehler kmm-fehler" aria-live="polite"></div><div class="kmm-dialog-buttons"><button type="submit" value="abbrechen" class="kmm-button kmm-button-secondary" formnovalidate>Abbrechen</button><button type="submit" value="ok" class="kmm-button">${h(label)}</button></div>`;

	// ----- Kopf, Banner, Tabs ------------------------------------------------------------------

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
			banner = `<div class="kmm-hinweis">Meldeschluss: ${h(m.sportjahr.meldeschluss)} Uhr</div>`;
		}
		if (m.konflikte > 0) {
			banner += `<div class="kmm-alert kmm-alert-error">${m.konflikte} Meldung(en) haben einen Konflikt nach einer Regeländerung oder kein Startrecht mehr. Bitte unter „Meldung“ prüfen.</div>`;
		}
		$('#kmm-banner').innerHTML = banner;
		$$('#kmm-tabs button').forEach((b) => b.classList.toggle('is-active', b.dataset.tab === state.tab));
		$$('.kmm-tab').forEach((s) => { s.hidden = s.id !== 'kmm-tab-' + state.tab; });
	}

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
		state.tab = b.dataset.tab;
		renderKopf();
		startplanTimer();
		window.scrollTo({ top: 0 });
	});

	// ----- 1 Schützen ------------------------------------------------------------------------

	function renderSchuetzen() {
		const el = $('#kmm-tab-schuetzen');
		const rw = schreibbar();
		let html = `<div class="kmm-toolbar"><h2>Schützenliste</h2>${rw ? '<button type="button" class="kmm-button" data-action="schuetze-neu">Neuer Schütze</button>' : ''}</div>`;
		html += '<p class="kmm-muted">Die Schützenliste bleibt über die Jahre erhalten. Höhermeldungen gelten nur für dieses Sportjahr.</p>';
		if (state.schuetzen.length === 0) {
			html += '<p>Noch keine Schützen angelegt.</p>';
		} else {
			html += '<div class="kmm-liste">' + state.schuetzen.map((s) => `
				<div class="kmm-karte">
					<div class="kmm-karte-haupt">
						<strong>${h(s.nachname)}, ${h(s.vorname)}</strong>
						<span class="kmm-muted">${h(datum(s.geburtsdatum))} (${s.alter} J.) · ${s.geschlecht === 'm' ? 'männlich' : 'weiblich'} · Nr. ${h(s.mitgliedsnummer)}</span>
						${Object.entries(s.hoehermeldungen || {}).map(([b, st]) => `<span class="kmm-badge kmm-badge-hm">Höhermeldung ${h(BEREICHE[b] || b)}: ${h((s.hoehermeldung_angebot[b] || {})[st] || st)}</span>`).join(' ')}
						${(s.warnungen || []).map((w) => `<div class="kmm-warn">⚠ ${h(w)}</div>`).join('')}
						${s.meldungen ? `<span class="kmm-muted">${s.meldungen} Meldung(en)</span>` : ''}
					</div>
					<div class="kmm-karte-aktionen">
						${rw ? `<button type="button" class="kmm-button kmm-button-small" data-action="schuetze-melden" data-id="${s.id}">Melden</button>
						<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="schuetze-bearbeiten" data-id="${s.id}">Bearbeiten</button>
						${s.meldungen ? '' : `<button type="button" class="kmm-button kmm-button-small kmm-button-danger" data-action="schuetze-loeschen" data-id="${s.id}">Löschen</button>`}` : ''}
					</div>
				</div>`).join('') + '</div>';
		}
		el.innerHTML = html;
	}

	function schuetzeFormular(s) {
		const angebot = s ? s.hoehermeldung_angebot || {} : {};
		const hm = s ? s.hoehermeldungen || {} : {};
		let hmHtml = '';
		Object.keys(angebot).forEach((b) => {
			hmHtml += `<label>Höhermeldung ${h(BEREICHE[b] || b)}</label><select name="hm_${b}"><option value="">– keine –</option>${Object.entries(angebot[b]).map(([st, name]) => `<option value="${h(st)}" ${hm[b] === st ? 'selected' : ''}>${h(name)}</option>`).join('')}</select>`;
		});
		if (!s) {
			hmHtml = '<p class="kmm-muted">Höhermeldungen können nach dem Anlegen über „Bearbeiten“ gesetzt werden.</p>';
		} else if (!hmHtml) {
			hmHtml = '<p class="kmm-muted">Für dieses Alter ist keine Höhermeldung möglich.</p>';
		}
		return `<h3>${s ? 'Schütze bearbeiten' : 'Neuer Schütze'}</h3>
			<label>Nachname</label><input name="nachname" required value="${h(s ? s.nachname : '')}">
			<label>Vorname</label><input name="vorname" required value="${h(s ? s.vorname : '')}">
			<label>Geburtsdatum</label><input type="date" name="geburtsdatum" required value="${h(s ? s.geburtsdatum : '')}">
			<label>Geschlecht</label><select name="geschlecht" required><option value="">– bitte wählen –</option><option value="m" ${s && s.geschlecht === 'm' ? 'selected' : ''}>männlich</option><option value="w" ${s && s.geschlecht === 'w' ? 'selected' : ''}>weiblich</option></select>
			<label>Mitgliedsnummer (9 Ziffern)</label><input name="mitgliedsnummer" required inputmode="numeric" pattern="[0-9]{9}" value="${h(s ? s.mitgliedsnummer : '')}">
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
			if (!window.confirm(`${s.nachname}, ${s.vorname} löschen?`)) { return; }
			try {
				const r = await api('DELETE', `schuetzen/${id}`);
				state.schuetzen = r.schuetzen;
				renderAll();
				toast('Schütze gelöscht.');
			} catch (e) { toast(e.message, 'error'); }
		} else if (b.dataset.action === 'schuetze-melden') {
			state.tab = 'meldung';
			renderKopf();
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
			teil += '<h2>Starter melden</h2>';
			teil += `<div class="kmm-form kmm-inline"><label for="kmm-melden-schuetze">Schütze wählen, um Disziplinen zu melden</label>
				<select id="kmm-melden-schuetze"><option value="">– Schütze –</option>${state.schuetzen.map((s) => `<option value="${s.id}" ${state.angebotFuer === s.id ? 'selected' : ''}>${h(s.nachname)}, ${h(s.vorname)}</option>`).join('')}</select></div>`;
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
					teil += `<details class="kmm-gruppe-details" data-gruppe="${h(code)}" ${offen ? 'open' : ''}><summary>${h(labels[code])} <span class="kmm-muted">(${liste.length} Disziplinen${gemeldet ? ', ' + gemeldet + ' gemeldet' : ''})</span></summary><div class="kmm-liste kmm-liste-kompakt">`;
					teil += liste.map((a) => `
					<div class="kmm-karte ${a.gemeldet_id ? 'is-gemeldet' : ''}">
						<div class="kmm-karte-haupt">
							<strong>${h(a.kennzahl)} ${h(a.bezeichnung)}</strong>
							<span class="kmm-muted">${a.startrecht ? `${h(a.klasse)}${a.startklasse !== a.klasse ? ' → ' + h(a.startklasse) : ''} (${h(a.kennzahl_voll)})${a.mannschaftspool ? ' · Mannschaft: ' + h(a.mannschaftspool) : ''}${a.typ === 'mixteam' ? '' : ' · ' + geld(a.startgeld)}` : 'nur als Para-Klasse'}</span>
							${(a.hinweise || []).map((x) => `<div class="kmm-hinweis-klein">${h(x)}</div>`).join('')}
							${a.para_optionen.length ? `<label class="kmm-muted">Para-Klasse (nur wenn zutreffend)</label><select data-para-for="${a.disziplin_id}"><option value="">${a.startrecht ? '– keine (reguläre Klasse) –' : '– bitte wählen –'}</option>${a.para_optionen.map((p) => `<option value="${p.id}">${h(p.bezeichnung)}</option>`).join('')}</select>` : ''}
						</div>
						<div class="kmm-karte-aktionen">${a.gemeldet_id ? '<span class="kmm-badge kmm-badge-ok">gemeldet</span>' : `<button type="button" class="kmm-button kmm-button-small" data-action="einzel-anlegen" data-disziplin="${a.disziplin_id}">Melden</button>`}</div>
					</div>`).join('');
					teil += '</div></details>';
					erste = false;
				});
			}
		}
			angebotHtml = teil;
		}
		html += `<h2>Gemeldete Starter (${m.einzelmeldungen.length})</h2>`;
		if (m.ohne_ergebnis > 0) {
			html += `<div class="kmm-alert kmm-alert-warn">${m.ohne_ergebnis} Meldung(en) ohne Meldeergebnis. Bitte das Ergebnis der Vereinsmeisterschaft eintragen – es wird für die Startplanung benötigt.</div>`;
		}
		if (m.einzelmeldungen.length === 0) {
			html += '<p>Noch keine Starter gemeldet.</p>';
		} else {
			let aktuell = null;
			const zeigeStatus = statusSichtbar();
			html += '<div class="kmm-tabelle-wrap"><table class="kmm-tabelle"><thead><tr><th>Name</th><th>Klasse</th><th>Startklasse</th><th>Ergebnis</th>' + (m.einstellungen.nicht_meldung_sichtbar ? '<th title="Nicht-Meldung">N-M</th>' : '') + '<th>Mannsch.</th><th class="r">Startgeld</th>' + (zeigeStatus ? '<th>Status</th>' : '') + '<th></th></tr></thead><tbody>';
			m.einzelmeldungen.forEach((e) => {
				if (e.kennzahl !== aktuell) {
					aktuell = e.kennzahl;
					html += `<tr class="kmm-gruppe"><th colspan="9">${h(e.kennzahl)} ${h(e.disziplin)}</th></tr>`;
				}
				const ergebnisPh = e.ergebnis_format === 'zehntel' ? '389,4' : '375';
				const abg = !!e.abgemeldet_am;
				const rwZ = rw && e.zustaendig !== false; // Referent: fremde Disziplin nur lesen
				html += `<tr class="${e.konflikt || !e.startrecht ? 'is-konflikt' : ''} ${abg ? 'is-abgemeldet' : ''}" data-id="${e.id}">
					<td><strong>${h(e.nachname)}, ${h(e.vorname)}</strong>${e.para ? `<div class="kmm-muted">${h(e.para)}</div>` : ''}${e.konflikt ? `<div class="kmm-fehler">${h(e.konflikt_text)}</div>` : ''}${(e.hinweise || []).filter((x) => !/^Höhermeldung/.test(x) || true).map((x) => `<div class="kmm-hinweis-klein">${h(x)}</div>`).join('')}</td>
					<td data-label="Klasse">${h(e.klasse)}${e.hoehermeldung ? ' <span class="kmm-badge kmm-badge-hm">HM</span>' : ''}</td>
					<td data-label="Startklasse">${h(e.startklasse)}<div class="kmm-muted">${h(e.kennzahl_voll)}</div></td>
					<td data-label="Ergebnis">${e.typ === 'mixteam' ? '–' : `<input type="text" inputmode="decimal" class="kmm-ergebnis ${e.meldeergebnis === '' ? 'is-leer' : ''}" data-action="ergebnis" data-id="${e.id}" value="${h(e.meldeergebnis)}" placeholder="${ergebnisPh}" ${rwZ ? '' : 'disabled'} size="6">`}</td>
					${m.einstellungen.nicht_meldung_sichtbar ? `<td data-label="Nicht-Meldung"><input type="checkbox" data-action="nicht-meldung" data-id="${e.id}" ${e.nicht_meldung ? 'checked' : ''} ${rwZ ? '' : 'disabled'}></td>` : ''}
					<td data-label="Mannschaft">${e.mannschaft_nummer ? 'M' + e.mannschaft_nummer : (e.mannschaft_moeglich ? '<span class="kmm-muted">–</span>' : '')}</td>
					<td class="r" data-label="Startgeld">${e.typ === 'mixteam' ? '–' : geld(e.startgeld)}</td>
					${zeigeStatus ? `<td data-label="Status">${abg ? `<span class="kmm-badge kmm-badge-warn">abgemeldet</span><div class="kmm-muted">${h(e.abgemeldet_am)}${e.abmeldegrund ? ' – ' + h(e.abmeldegrund) : ''}</div>${admin && rwZ ? `<label class="kmm-startgeld-schalter"><input type="checkbox" data-action="startgeld" data-id="${e.id}" ${e.startgeld_berechnen ? 'checked' : ''}> Startgeld berechnen</label>` : `<div class="kmm-muted">Startgeld ${e.startgeld_berechnen ? 'wird berechnet' : 'entfällt'}</div>`}` : `<span class="kmm-badge kmm-status-${h(e.verarbeitungsstatus)}">${h(STATUS_LABEL[e.verarbeitungsstatus] || e.verarbeitungsstatus)}</span>${e.verarbeitungsgrund ? `<div class="kmm-fehler">${h(e.verarbeitungsgrund)}</div>` : ''}`}${e.nachgemeldet ? '<div class="kmm-muted">Nachmeldung</div>' : ''}</td>` : ''}
					<td class="kmm-aktionen">${rwZ ? `${e.konflikt && e.startrecht ? `<button type="button" class="kmm-button kmm-button-small" data-action="konflikt-ok" data-id="${e.id}">OK</button> ` : ''}${admin ? (abg ? `<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="abmeldung-aufheben" data-id="${e.id}">Abmeldung aufheben</button> ` : `<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="abmelden" data-id="${e.id}">Abmelden</button> `) : ''}<button type="button" class="kmm-button kmm-button-small kmm-button-danger" data-action="einzel-loeschen" data-id="${e.id}" title="Meldung entfernen">✕</button>` : ''}</td>
				</tr>`;
			});
			html += '</tbody></table></div>';
		}
		el.innerHTML = html + angebotHtml;
	}

	$('#kmm-tab-meldung').addEventListener('change', async (ev) => {
		const t = ev.target;
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
				const warn = $('#kmm-tab-meldung .kmm-alert-warn');
				if (warn) { warn.hidden = state.meldung.ohne_ergebnis === 0; if (!warn.hidden) { warn.textContent = `${state.meldung.ohne_ergebnis} Meldung(en) ohne Meldeergebnis. Bitte das Ergebnis der Vereinsmeisterschaft eintragen – es wird für die Startplanung benötigt.`; } }
				if (t.dataset.action === 'ergebnis') { toast('Ergebnis gespeichert.'); }
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
				if (!window.confirm('Meldung entfernen?')) { return; }
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
				if (!window.confirm('Abmeldung aufheben? Die Meldung gilt danach wieder als aktiv (ein freigegebener Startplatz wird nicht automatisch neu gebucht).')) { return; }
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
			html += '<p>Keine Disziplin mit Mannschaftswertung gemeldet.</p>';
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
				<div class="kmm-karte-aktionen">${rw && t.zustaendig !== false ? `<button type="button" class="kmm-button kmm-button-small kmm-button-secondary" data-action="mannschaft-bearbeiten" data-id="${t.id}" data-disziplin="${dId}">Bearbeiten</button> <button type="button" class="kmm-button kmm-button-small kmm-button-danger" data-action="mannschaft-loeschen" data-id="${t.id}">✕</button>` : ''}</div>
			</div>`).join('');
			html += '</div>';
		});
		el.innerHTML = html;
	}

	async function mannschaftDialog(dId, teamId) {
		const kandidaten = await api('GET', `meldung/mannschaften/kandidaten?disziplin_id=${dId}${teamId ? '&mannschaft_id=' + teamId : ''}`);
		const team = teamId ? state.meldung.mannschaften.find((t) => t.id === teamId) : null;
		const groesse = team ? team.groesse : (kandidaten[0] ? state.meldung.einzelmeldungen.find((e) => e.disziplin_id === dId && e.typ === 'mixteam') ? 2 : 3 : 3);
		const info = state.meldung.einzelmeldungen.find((e) => e.disziplin_id === dId);
		dialog(`<h3>${team ? 'Mannschaft ' + team.nummer : 'Neue Mannschaft'} – ${h(info ? info.kennzahl + ' ' + info.disziplin : '')}</h3>
			<p class="kmm-muted">Bis zu ${groesse} Schützen derselben Mannschaftsklasse wählen. Die Liste zeigt nur passende Schützen; nach der ersten Auswahl bleiben nur Schützen mit gleicher Klasse wählbar.</p>
			${kandidaten.length === 0 ? '<p>Keine passenden Schützen ohne Mannschaft.</p>' : ''}
			<div class="kmm-kandidaten">${kandidaten.map((k) => `<label class="kmm-check"><input type="checkbox" name="mitglied" value="${k.id}" data-pool="${h(k.mannschaftspool || '')}" data-geschlecht="${h(k.geschlecht)}" ${k.mitglied ? 'checked' : ''}> ${h(k.nachname)}, ${h(k.vorname)} <span class="kmm-muted">${h(k.klasse)} · Pool ${h(k.mannschaftspool || '')}</span></label>`).join('')}</div>
			${dialogButtons('Speichern')}`, async (form) => {
			const ids = Array.from(form.querySelectorAll('input[name=mitglied]:checked')).map((c) => Number(c.value));
			const r = await api(team ? 'PUT' : 'POST', team ? `meldung/mannschaften/${team.id}` : 'meldung/mannschaften', { disziplin_id: dId, einzelmeldung_ids: ids });
			state.meldung = r.meldung;
			renderAll();
			toast(`Mannschaft ${r.mannschaft.nummer} gespeichert${r.mannschaft.vollstaendig ? '' : ' (noch unvollständig)'}.`);
		});
		// Auswahl auf gleichen Pool/Geschlechtsregel begrenzen.
		const d = document.getElementById('kmm-dialog');
		const mix = info && info.typ === 'mixteam';
		d.addEventListener('change', function limit() {
			const checked = Array.from(d.querySelectorAll('input[name=mitglied]:checked'));
			d.querySelectorAll('input[name=mitglied]').forEach((c) => {
				if (c.checked) { return; }
				let ok = checked.length < groesse;
				if (ok && checked.length) { ok = checked.every((x) => x.dataset.pool === c.dataset.pool); }
				if (ok && mix) { ok = !checked.some((x) => x.dataset.geschlecht === c.dataset.geschlecht); }
				c.disabled = !ok;
			});
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
				if (!window.confirm('Mannschaft auflösen? Die Einzelmeldungen bleiben erhalten.')) { return; }
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
			<form class="kmm-form" id="kmm-ansprechpartner"><label>Name</label><input name="name" required value="${h(m.ansprechpartner.name)}" ${rw ? '' : 'disabled'}>
			<label>E-Mail</label><input type="email" name="email" required value="${h(m.ansprechpartner.email)}" ${rw ? '' : 'disabled'}>
			<label>Telefon (optional)</label><input type="tel" name="telefon" value="${h(m.ansprechpartner.telefon)}" ${rw ? '' : 'disabled'}>
			${rw ? '<button type="submit" class="kmm-button kmm-button-secondary">Ansprechpartner speichern</button>' : ''}</form>`;
		html += `<h3>Startgeldvorschau (vorläufig)</h3><table class="kmm-tabelle kmm-summe"><tr><td>Einzelmeldungen (${m.einzelmeldungen.length})</td><td class="r">${geld(m.startgeld.einzel)}</td></tr><tr><td>Mannschaften (${m.mannschaften.length})</td><td class="r">${geld(m.startgeld.mannschaften)}</td></tr><tr><th>Summe</th><th class="r">${geld(m.startgeld.summe)}</th></tr></table><p class="kmm-muted">Maßgeblich ist die Abrechnung des KSV nach der Startrechtsprüfung.</p>`;
		html += '<h3>Prüfung</h3>';
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
		html += `<p class="kmm-muted" style="margin-top:16px"><a href="${h(cfg.pdfUrl)}">Meldung als PDF herunterladen</a></p>`;
		el.innerHTML = html;
	}

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
		const fd = new FormData(ev.target);
		try {
			const r = await api('PUT', 'meldung/ansprechpartner', { name: fd.get('name'), email: fd.get('email'), telefon: fd.get('telefon') });
			state.meldung = r.meldung;
			renderAll();
			toast('Ansprechpartner gespeichert.');
		} catch (e) { toast(e.message, 'error'); }
	});

	$('#kmm-tab-einreichen').addEventListener('click', async (ev) => {
		const b = ev.target.closest('button[data-action]');
		if (!b) { return; }
		try {
			if (b.dataset.action === 'einreichen') {
				const w = state.meldung.pruefung.warnungen;
				if (!window.confirm((w.length ? w.join('\n') + '\n\n' : '') + 'Meldung jetzt verbindlich einreichen?')) { return; }
				b.disabled = true;
				const r = await api('POST', 'meldung/einreichen');
				state.meldung = r.meldung;
				renderAll();
				toast('Meldung eingereicht. Bestätigung per E-Mail versandt.');
			} else if (b.dataset.action === 'oeffnen') {
				if (!window.confirm('Meldung wieder öffnen? Sie muss danach erneut eingereicht werden.')) { return; }
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
				if (!window.confirm('Platz freigeben? Ein anderer Verein kann ihn dann buchen.')) { return; }
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

	if (admin) {
		$('#kmm-tabs button[data-tab=einreichen]').textContent = '4 Ansprechpartner & Nachmeldung';
	}
	renderAll();
	if (admin || (state.meldung.einzelmeldungen.length > 0 && state.meldung.status !== 'eingereicht')) {
		state.tab = 'meldung';
		renderKopf();
	}
})();
