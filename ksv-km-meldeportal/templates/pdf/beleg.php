<?php
/**
 * PDF: Buchhaltungsbeleg eines Vereins (BelegService::positionen + belegnummer, erstellt_am).
 *
 * @var array<string, mixed> $p
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$geld = static fn(float $b): string => number_format($b, 2, ',', '.') . ' €';
?>
<style>
	body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #111; }
	h1 { font-size: 15pt; margin: 0 0 2pt; }
	h2 { font-size: 11.5pt; margin: 12pt 0 4pt; border-bottom: 1px solid #999; }
	table { width: 100%; border-collapse: collapse; }
	th, td { border: 1px solid #bbb; padding: 3pt 4pt; text-align: left; vertical-align: top; }
	th { background: #eee; }
	td.r, th.r { text-align: right; }
	tr.summe td { font-weight: bold; background: #f3f3f3; }
	.klein { font-size: 8pt; color: #555; }
	.kopf td { border: 0; padding: 1pt 0; }
	.hinweis { border: 1px solid #c90; background: #fff8e1; padding: 4pt 6pt; margin: 8pt 0; font-size: 9pt; }
	.abg { color: #777; }
</style>
<h1>Buchhaltungsbeleg Kreisverbandsmeisterschaft <?php echo (int) $p['sportjahr']['jahr']; ?></h1>
<div class="klein">Startgelder – Abrechnungsgrundlage für die Buchhaltung des KSV Fallingbostel (keine Rechnung)</div>
<table class="kopf" style="margin-top:8pt">
	<tr><td><strong><?php echo esc_html($p['verein']['name']); ?></strong> (VN <?php echo esc_html($p['verein']['vn_nummer']); ?>)</td><td class="r">Beleg-Nr. <strong><?php echo esc_html((string) ($p['belegnummer'] ?? '')); ?></strong></td></tr>
	<tr><td class="klein">Meldung: <?php echo esc_html(match ((string) $p['meldung_status']) { 'verarbeitet' => 'verarbeitet', 'eingereicht' => 'eingereicht', 'entwurf' => 'Entwurf (nicht eingereicht)', default => 'offen' }); ?><?php echo $p['ansprechpartner']['name'] !== '' ? ' · Ansprechpartner: ' . esc_html($p['ansprechpartner']['name']) : ''; ?></td><td class="r klein">Erstellt: <?php echo esc_html(\KSV\KMM\Support\Clock::format_local($p['erstellt_am'] ?? null)); ?> Uhr<?php echo !empty($p['erstellt_von']) ? ' von ' . esc_html((string) $p['erstellt_von']) : ''; ?></td></tr>
</table>

<?php if ((int) $p['ungeprueft'] > 0) : ?>
<div class="hinweis">Hinweis: <?php echo (int) $p['ungeprueft']; ?> Meldung(en) waren beim Erzeugen noch ungeprüft (Startrechtsprüfung offen). Der Beleg kann sich noch ändern.</div>
<?php endif; ?>

<h2>Einzelstarts (<?php echo (int) $p['anzahl_einzel']; ?>)</h2>
<?php if ($p['einzel'] === []) : ?>
<p class="klein">keine</p>
<?php else : ?>
<table>
	<thead><tr><th style="width:13%">Kennzahl</th><th>Disziplin</th><th>Startklasse</th><th class="r" style="width:10%">Anzahl</th><th class="r" style="width:12%">Betrag</th><th class="r" style="width:13%">Summe</th></tr></thead>
	<tbody>
	<?php foreach ($p['einzel'] as $g) : ?>
		<tr<?php echo $g['abgemeldet'] ? ' class="abg"' : ''; ?>>
			<td><?php echo esc_html($g['kennzahl_voll']); ?></td>
			<td><?php echo esc_html($g['disziplin']); ?><?php echo $g['abgemeldet'] ? ' <span class="klein">abgemeldet</span>' : ''; ?><div class="klein"><?php echo esc_html(implode('; ', $g['namen'])); ?></div></td>
			<td><?php echo esc_html($g['startklasse']); ?></td>
			<td class="r"><?php echo (int) $g['anzahl']; ?></td>
			<td class="r"><?php echo esc_html($geld((float) $g['betrag'])); ?></td>
			<td class="r"><?php echo esc_html($geld((float) $g['summe'])); ?></td>
		</tr>
	<?php endforeach; ?>
		<tr class="summe"><td colspan="5">Summe Einzelstarts</td><td class="r"><?php echo esc_html($geld((float) $p['summe_einzel'])); ?></td></tr>
	</tbody>
</table>
<?php endif; ?>

<h2>Mannschaften (<?php echo (int) $p['anzahl_mannschaften']; ?>)</h2>
<?php if ($p['mannschaften'] === []) : ?>
<p class="klein">keine</p>
<?php else : ?>
<table>
	<thead><tr><th style="width:13%">Kennzahl</th><th>Disziplin</th><th class="r" style="width:10%">Anzahl</th><th class="r" style="width:12%">Betrag</th><th class="r" style="width:13%">Summe</th></tr></thead>
	<tbody>
	<?php foreach ($p['mannschaften'] as $g) : ?>
		<tr>
			<td><?php echo esc_html($g['kennzahl']); ?></td>
			<td><?php echo esc_html($g['disziplin']); ?><?php echo (int) $g['unvollstaendig'] > 0 ? ' <span class="klein">(' . (int) $g['unvollstaendig'] . ' unvollständig)</span>' : ''; ?></td>
			<td class="r"><?php echo (int) $g['anzahl']; ?></td>
			<td class="r"><?php echo esc_html($geld((float) $g['betrag'])); ?></td>
			<td class="r"><?php echo esc_html($geld((float) $g['summe'])); ?></td>
		</tr>
	<?php endforeach; ?>
		<tr class="summe"><td colspan="4">Summe Mannschaften</td><td class="r"><?php echo esc_html($geld((float) $p['summe_mannschaften'])); ?></td></tr>
	</tbody>
</table>
<?php endif; ?>

<h2>Gesamt</h2>
<table>
	<tr><td>Einzelstarts</td><td class="r" style="width:20%"><?php echo esc_html($geld((float) $p['summe_einzel'])); ?></td></tr>
	<tr><td>Mannschaften</td><td class="r"><?php echo esc_html($geld((float) $p['summe_mannschaften'])); ?></td></tr>
	<tr class="summe"><td>Gesamtsumme Startgeld</td><td class="r"><?php echo esc_html($geld((float) $p['summe'])); ?></td></tr>
</table>
<?php if ((int) $p['nicht_startberechtigt'] > 0 || (int) $p['abgemeldet'] > 0 || (int) $p['konflikte'] > 0) : ?>
<p class="klein">
	<?php echo (int) $p['nicht_startberechtigt'] > 0 ? (int) $p['nicht_startberechtigt'] . ' nicht startberechtigte Meldung(en) sind nicht enthalten. ' : ''; ?>
	<?php echo (int) $p['abgemeldet'] > 0 ? (int) $p['abgemeldet'] . ' abgemeldete Meldung(en) sind mit dem jeweils festgelegten Betrag enthalten. ' : ''; ?>
	<?php echo (int) $p['konflikte'] > 0 ? (int) $p['konflikte'] . ' Meldung(en) mit offenem Konflikt oder ohne Startrecht sind nicht enthalten. ' : ''; ?>
</p>
<?php endif; ?>
