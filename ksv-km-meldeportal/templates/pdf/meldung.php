<?php
/**
 * PDF: eigene Meldung des Vereins.
 *
 * @var array<string, mixed> $z Zusammenfassung (MeldungService::zusammenfassung)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$geld = static fn(float $b): string => number_format($b, 2, ',', '.') . ' €';
$status = match ((string) $z['status']) {
	'eingereicht' => 'Eingereicht am ' . $z['eingereicht_am'],
	'entwurf' => 'Entwurf (noch nicht eingereicht)',
	default => 'Offen',
};
?>
<style>
	body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #111; }
	h1 { font-size: 16pt; margin: 0 0 4pt; }
	h2 { font-size: 12pt; margin: 14pt 0 4pt; border-bottom: 1px solid #999; }
	table { width: 100%; border-collapse: collapse; }
	th, td { border: 1px solid #bbb; padding: 3pt 4pt; text-align: left; vertical-align: top; }
	th { background: #eee; }
	td.r, th.r { text-align: right; }
	.klein { font-size: 8pt; color: #555; }
	.kopf td { border: 0; padding: 1pt 0; }
</style>
<h1>Meldung zur Kreisverbandsmeisterschaft <?php echo (int) $z['sportjahr']['jahr']; ?></h1>
<table class="kopf">
	<tr><td><strong><?php echo esc_html($z['verein']['name']); ?></strong> (VN <?php echo esc_html($z['verein']['vn_nummer']); ?>)</td><td class="r"><?php echo esc_html($status); ?></td></tr>
	<tr><td>Ansprechpartner: <?php echo esc_html(trim($z['ansprechpartner']['name'] . ' · ' . $z['ansprechpartner']['email'] . ' ' . $z['ansprechpartner']['telefon'], ' ·')); ?></td><td class="r klein">Stand: <?php echo esc_html(wp_date('d.m.Y H:i')); ?> Uhr</td></tr>
</table>

<h2>Einzelmeldungen (<?php echo count($z['einzelmeldungen']); ?>)</h2>
<table>
	<thead><tr><th>Kennzahl</th><th>Disziplin</th><th>Name</th><th>Klasse</th><th>Startklasse</th><th class="r">Ergebnis</th><th>Mannsch.</th><th class="r">Startgeld</th></tr></thead>
	<tbody>
	<?php foreach ($z['einzelmeldungen'] as $e) : ?>
		<tr>
			<td><?php echo esc_html($e['kennzahl_voll']); ?></td>
			<td><?php echo esc_html($e['disziplin']); ?></td>
			<td><?php echo esc_html($e['nachname'] . ', ' . $e['vorname']); ?><?php echo $e['para'] ? ' <span class="klein">(' . esc_html($e['para']) . ')</span>' : ''; ?></td>
			<td><?php echo esc_html($e['klasse']); ?><?php echo $e['hoehermeldung'] ? ' <span class="klein">HM</span>' : ''; ?></td>
			<td><?php echo esc_html($e['startklasse']); ?></td>
			<td class="r"><?php echo esc_html($e['meldeergebnis'] !== '' ? $e['meldeergebnis'] : '–'); ?></td>
			<td><?php echo $e['mannschaft_nummer'] ? 'M' . (int) $e['mannschaft_nummer'] : ''; ?></td>
			<td class="r"><?php echo esc_html($e['typ'] === 'mixteam' ? '–' : $geld((float) $e['startgeld'])); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ($z['mannschaften'] !== []) : ?>
<h2>Mannschaften (<?php echo count($z['mannschaften']); ?>)</h2>
<table>
	<thead><tr><th>Disziplin</th><th>Nr.</th><th>Mannschaftsklasse</th><th>Mitglieder</th><th class="r">Startgeld</th></tr></thead>
	<tbody>
	<?php foreach ($z['mannschaften'] as $m) : ?>
		<tr>
			<td><?php echo esc_html($m['kennzahl'] . ' ' . $m['disziplin']); ?></td>
			<td><?php echo (int) $m['nummer']; ?></td>
			<td><?php echo esc_html($m['klasse']); ?></td>
			<td><?php echo esc_html(implode(', ', array_map(static fn(array $x): string => $x['nachname'] . ', ' . $x['vorname'], $m['mitglieder']))); ?><?php echo $m['vollstaendig'] ? '' : ' <span class="klein">(unvollständig)</span>'; ?></td>
			<td class="r"><?php echo esc_html($geld((float) $m['startgeld'])); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<h2>Startgeld (vorläufig)</h2>
<table>
	<tr><td>Einzelmeldungen</td><td class="r"><?php echo esc_html($geld((float) $z['startgeld']['einzel'])); ?></td></tr>
	<tr><td>Mannschaften</td><td class="r"><?php echo esc_html($geld((float) $z['startgeld']['mannschaften'])); ?></td></tr>
	<tr><th>Summe</th><th class="r"><?php echo esc_html($geld((float) $z['startgeld']['summe'])); ?></th></tr>
</table>
<p class="klein">Die Startgeldvorschau ist vorläufig. Maßgeblich ist die Abrechnung des KSV nach der Startrechtsprüfung.</p>
