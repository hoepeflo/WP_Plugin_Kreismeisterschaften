<?php
/**
 * Regel Disziplin × Klasse (Wertobjekt der Regel-Engine).
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Engine;

use KSV\KMM\Domain\RegelModus;

final class Regel {

	public function __construct(
		public readonly int $disziplin_id,
		public readonly int $klasse_id,
		public readonly string $einzel_modus = RegelModus::KEINE,
		public readonly ?int $einzel_ziel_klasse_id = null,
		public readonly string $mannschaft_modus = RegelModus::KEINE,
		public readonly ?int $mannschaft_ziel_klasse_id = null,
		public readonly ?int $mindestalter = null,
		public readonly string $hinweis = '',
	) {
	}
}
