<?php
/**
 * PDF-Erzeugung über mPDF (Composer-Abhängigkeit in vendor/).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

final class Pdf {

	public static function verfuegbar(): bool {
		return class_exists(\Mpdf\Mpdf::class);
	}

	/**
	 * @param string             $format  mPDF-Format, z. B. „A4“ oder „A4-L“ (Querformat)
	 * @param array<string, int> $raender Optionale Ränder in mm (left, right, top, bottom)
	 * @throws \RuntimeException wenn mPDF fehlt.
	 */
	public static function aus_html(string $html, string $format = 'A4', array $raender = []): string {
		if (!self::verfuegbar()) {
			throw new \RuntimeException('Die PDF-Bibliothek (mPDF) ist nicht installiert. Bitte das Plugin mit vendor/ ausliefern (tools/build-zip.sh).');
		}
		$tmp = trailingslashit(wp_upload_dir()['basedir']) . 'kmm-tmp';
		if (!is_dir($tmp)) {
			wp_mkdir_p($tmp);
			file_put_contents($tmp . '/index.php', "<?php\n// Silence is golden.\n");
			file_put_contents($tmp . '/.htaccess', "Deny from all\n");
		}
		$mpdf = new \Mpdf\Mpdf([
			'mode'          => 'utf-8',
			'format'        => $format,
			'tempDir'       => $tmp,
			'margin_left'   => $raender['left'] ?? 15,
			'margin_right'  => $raender['right'] ?? 15,
			'margin_top'    => $raender['top'] ?? 18,
			'margin_bottom' => $raender['bottom'] ?? 18,
			'default_font'  => 'dejavusans',
		]);
		$mpdf->SetTitle('KM-Portal');
		$mpdf->SetCreator('KSV KM-Portal');
		$mpdf->WriteHTML($html);
		return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
	}
}
