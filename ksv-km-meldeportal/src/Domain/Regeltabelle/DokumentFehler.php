<?php
/**
 * Gesammelte Validierungsfehler eines Regeltabellen-Dokuments.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Domain\Regeltabelle;

final class DokumentFehler extends \InvalidArgumentException {

	/**
	 * @param string[] $fehler
	 */
	public function __construct(public readonly array $fehler) {
		parent::__construct("Regeltabelle ungültig:\n- " . implode("\n- ", $fehler));
	}
}
