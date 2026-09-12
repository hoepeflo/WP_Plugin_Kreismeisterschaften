<?php
/**
 * Referenten (Konzept 10): WordPress-Benutzer mit der Rolle „KM-Referent“, denen der Admin
 * Zuständigkeiten (Wettbewerbsgruppen oder Disziplinen) und Einzelrechte zuweist.
 * Lesen und PDF-Listen im eigenen Bereich sind immer erlaubt; Verarbeitungsstatus setzen,
 * Meldungen bearbeiten und Startplan bearbeiten schaltet der Admin je Person frei.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Application;

use KSV\KMM\Auth\Capabilities;
use KSV\KMM\Auth\Rechte;
use KSV\KMM\Infrastructure\Repository\ReferentRepository;
use KSV\KMM\Infrastructure\Repository\ReferentZustaendigkeitRepository;
use KSV\KMM\Support\Clock;

final class ReferentService {

	private ReferentRepository $referenten;
	private ReferentZustaendigkeitRepository $zustaendigkeiten;

	public function __construct() {
		$this->referenten = new ReferentRepository();
		$this->zustaendigkeiten = new ReferentZustaendigkeitRepository();
	}

	/**
	 * Alle Referenten mit Benutzerdaten und Zuständigkeiten.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function liste(): array {
		$out = [];
		foreach ($this->referenten->where([], 'id ASC') as $r) {
			$user = get_userdata((int) $r['user_id']);
			$r['benutzer'] = $user instanceof \WP_User ? $user : null;
			$r['name'] = $user instanceof \WP_User ? $user->display_name : sprintf('(Benutzer #%d gelöscht)', (int) $r['user_id']);
			$r['rolle_ok'] = $user instanceof \WP_User && (in_array(Capabilities::ROLE_REFERENT, $user->roles, true) || user_can($user, Capabilities::MANAGE));
			$r['zustaendigkeiten'] = $this->zustaendigkeiten->by_referent((int) $r['id']);
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Benutzer mit der Rolle KM-Referent, die noch keinen Referenten-Eintrag haben.
	 *
	 * @return list<\WP_User>
	 */
	public function kandidaten(): array {
		$vergeben = array_map(static fn(array $r): int => (int) $r['user_id'], $this->referenten->where([]));
		$users = get_users(['role' => Capabilities::ROLE_REFERENT, 'orderby' => 'display_name', 'fields' => 'all']);
		return array_values(array_filter($users, static fn($u): bool => $u instanceof \WP_User && !in_array((int) $u->ID, $vergeben, true)));
	}

	/**
	 * Referenten anlegen oder ändern.
	 *
	 * @param array{darf_status?: bool, darf_meldungen?: bool, darf_startplan?: bool} $rechte
	 * @param list<string> $gruppen     Codes der Wettbewerbsgruppen
	 * @param list<string> $disziplinen Kennzahlen
	 * @return int Referenten-ID
	 */
	public function speichern(int $id, int $user_id, array $rechte, array $gruppen, array $disziplinen, string $notiz = ''): int {
		$user = get_userdata($user_id);
		if (!$user instanceof \WP_User) {
			throw new \InvalidArgumentException('Benutzer nicht gefunden.');
		}
		if (!in_array(Capabilities::ROLE_REFERENT, $user->roles, true) && !user_can($user, Capabilities::MANAGE)) {
			throw new \InvalidArgumentException(sprintf('%s hat nicht die Rolle „KM-Referent“. Bitte zuerst unter Benutzer die Rolle zuweisen.', $user->display_name));
		}
		$vorhanden = $this->referenten->by_user($user_id);
		if ($vorhanden !== null && (int) $vorhanden['id'] !== $id) {
			throw new \InvalidArgumentException(sprintf('%s ist bereits als Referent eingetragen.', $user->display_name));
		}
		$gruppen = array_values(array_unique(array_filter(array_map(static fn(string $g): string => sanitize_key($g), $gruppen))));
		$disziplinen = array_values(array_unique(array_filter(array_map('trim', $disziplinen))));
		if ($gruppen === [] && $disziplinen === []) {
			throw new \InvalidArgumentException('Bitte mindestens eine Wettbewerbsgruppe oder Disziplin zuweisen.');
		}
		$satz = [
			'user_id'        => $user_id,
			'darf_status'    => !empty($rechte['darf_status']),
			'darf_meldungen' => !empty($rechte['darf_meldungen']),
			'darf_startplan' => !empty($rechte['darf_startplan']),
			'notiz'          => mb_substr(trim($notiz), 0, 255),
			'updated_at'     => Clock::now_utc(),
		];
		if ($id > 0) {
			if ($this->referenten->find($id) === null) {
				throw new \RuntimeException('Referent nicht gefunden.');
			}
			$this->referenten->update($id, $satz);
			$aktion = 'referent.aendern';
		} else {
			$satz['created_at'] = Clock::now_utc();
			$id = $this->referenten->insert($satz);
			$aktion = 'referent.anlegen';
		}
		$eintraege = [];
		foreach ($gruppen as $g) {
			$eintraege[] = ['typ' => ReferentZustaendigkeitRepository::TYP_GRUPPE, 'schluessel' => $g];
		}
		foreach ($disziplinen as $d) {
			$eintraege[] = ['typ' => ReferentZustaendigkeitRepository::TYP_DISZIPLIN, 'schluessel' => $d];
		}
		$this->zustaendigkeiten->setzen($id, $eintraege);
		Rechte::cache_leeren();
		$rechte_text = implode(', ', array_keys(array_filter(['Status' => $satz['darf_status'], 'Meldungen' => $satz['darf_meldungen'], 'Startplan' => $satz['darf_startplan']])));
		Protokoll::admin($aktion, sprintf('%s: Gruppen [%s], Disziplinen [%s], Rechte [%s]', $user->display_name, implode(', ', $gruppen), implode(', ', $disziplinen), $rechte_text !== '' ? $rechte_text : 'nur lesen'), null, null, 'referent', $id, ['user_id' => $user_id]);
		return $id;
	}

	public function loeschen(int $id): void {
		$r = $this->referenten->find($id);
		if ($r === null) {
			throw new \RuntimeException('Referent nicht gefunden.');
		}
		$this->zustaendigkeiten->delete_where(['referent_id' => $id]);
		$this->referenten->delete($id);
		Rechte::cache_leeren();
		$user = get_userdata((int) $r['user_id']);
		Protokoll::admin('referent.loeschen', $user instanceof \WP_User ? $user->display_name : ('#' . (int) $r['user_id']), null, null, 'referent', $id);
	}
}
