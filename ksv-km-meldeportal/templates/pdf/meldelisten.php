<?php
/**
 * PDF: Meldelisten (jede Disziplin auf neuer Seite).
 *
 * @var array<string, mixed> $s     Struktur aus PdfMeldelisten::struktur()
 * @var string               $titel
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}
$nach_verein = $s['gruppierung'] === 'verein';
?>
<style>
	body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #111; }
	h1 { font-size: 15pt; margin: 0 0 2pt; }
	h2 { font-size: 11pt; margin: 10pt 0 3pt; background: #eee; padding: 2pt 4pt; }
	table { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
	th, td { border: 1px solid #bbb; padding: 2pt 4pt; text-align: left; vertical-align: top; }
	th { background: #f3f3f3; }
	td.r, th.r { text-align: right; }
	.klein { font-size: 7.5pt; color: #555; }
	.kopf { color: #555; font-size: 8.5pt; margin-bottom: 6pt; }
	.leer { color: #777; font-style: italic; }
</style>
<?php if ($s['disziplinen'] === []) : ?>
	<h1><?php echo esc_html($titel); ?></h1>
	<p class="leer">Keine Meldungen im gewählten Umfang.</p>
<?php endif; ?>
<?php foreach ($s['disziplinen'] as $i => $d) : ?>
	<?php if ($i > 0) : ?><pagebreak /><?php endif; ?>
	<h1><?php echo esc_html($d['kennzahl'] . ' ' . $d['bezeichnung']); ?></h1>
	<div class="kopf"><?php echo esc_html($titel); ?> · <?php echo (int) $d['anzahl']; ?> Starter · gruppiert nach <?php echo $nach_verein ? 'Verein' : 'Startklasse'; ?> · Stand <?php echo esc_html($s['stand']); ?></div>
	<?php foreach ($d['gruppen'] as $g) : ?>
		<h2><?php echo esc_html($g['titel']); ?> <span class="klein">(<?php echo count($g['zeilen']); ?>)</span></h2>
		<table>
			<thead><tr>
				<th style="width:8%">Kennzahl</th>
				<th style="width:22%">Name</th>
				<?php if ($nach_verein) : ?><th style="width:20%">Startklasse</th><?php else : ?><th style="width:20%">Verein</th><?php endif; ?>
				<th style="width:8%">Jahrgang</th>
				<th style="width:11%">Mitgl.-Nr.</th>
				<th class="r" style="width:9%">Ergebnis</th>
				<th style="width:10%">Mannsch.</th>
				<th>Hinweis</th>
			</tr></thead>
			<tbody>
			<?php foreach ($g['zeilen'] as $z) : ?>
				<tr>
					<td><?php echo esc_html($z->kennzahl); ?></td>
					<td><?php echo esc_html($z->nachname . ', ' . $z->vorname); ?></td>
					<td>
						<?php if ($nach_verein) : ?>
							<?php echo esc_html($z->startklasse); ?><?php if ($z->klasse !== '' && $z->klasse !== $z->startklasse) : ?> <span class="klein">(<?php echo esc_html($z->klasse); ?>)</span><?php endif; ?>
						<?php else : ?>
							<?php echo esc_html($z->vn_name); ?><?php if ($z->klasse !== '' && $z->klasse !== $z->startklasse) : ?> <span class="klein">(<?php echo esc_html($z->klasse); ?>)</span><?php endif; ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html(substr($z->geburtsdatum, 0, 4)); ?></td>
					<td><?php echo esc_html($z->mitgliedsnummer); ?></td>
					<td class="r"><?php echo esc_html(KSV\KMM\Domain\Meldeergebnis::format($z->meldeergebnis, $z->ergebnis_format) ?: '–'); ?></td>
					<td><?php echo $z->mannschaft_nummer !== null ? 'M' . (int) $z->mannschaft_nummer . ($nach_verein ? '' : ' ' . esc_html($z->vn_nummer)) : ''; ?></td>
					<td class="klein"><?php echo esc_html(trim(($z->hoehermeldung ? 'Höhermeldung ' : '') . ($z->para ?? '') . ($z->status !== 'eingereicht' ? ' Entwurf' : ''))); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
<?php endforeach; ?>
