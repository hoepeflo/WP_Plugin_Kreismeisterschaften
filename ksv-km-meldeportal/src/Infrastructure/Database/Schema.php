<?php
/**
 * Tabellenschema des Meldeportals (alle Tabellen mit Präfix kmm_).
 *
 * Reines PHP ohne WordPress-Abhängigkeit, damit die Definitionen unit-testbar sind.
 * Die SQL-Strings folgen den Formatvorgaben von dbDelta(): ein Feld pro Zeile,
 * "PRIMARY KEY  (id)" mit zwei Leerzeichen, "KEY" statt "INDEX", keine Backticks,
 * keine ENUMs (Status-Werte sind varchar mit PHP-Konstanten) und keine
 * FOREIGN-KEY-Constraints (dbDelta unterstützt sie nicht; referenzielle Integrität
 * wird in den Repositories sichergestellt).
 *
 * Zeitstempel werden als UTC in datetime-Spalten gespeichert.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM\Infrastructure\Database;

final class Schema {

	/**
	 * Schema-Version. Bei jeder Änderung an definitions() erhöhen; der Migrator
	 * führt dbDelta() erneut aus, sobald die gespeicherte Version abweicht.
	 */
	public const VERSION = 1;

	public const OPTION_VERSION = 'kmm_schema_version';

	/** Tabellen-Kurznamen (ohne Präfix) in Anlegereihenfolge. */
	public const TABLES = [
		'sportjahr',
		'wettbewerbsgruppe',
		'klasse',
		'disziplin',
		'regel',
		'startgeld_tarif',
		'verein',
		'verein_email',
		'magic_link',
		'sitzung',
		'schuetze',
		'hoehermeldung',
		'meldung',
		'einzelmeldung',
		'mannschaft',
		'protokoll',
		'export',
		'mail_log',
	];

	/**
	 * Vollständiger Tabellenname.
	 *
	 * @param string $table  Kurzname, z. B. "klasse".
	 * @param string $prefix WordPress-Tabellenpräfix ($wpdb->prefix).
	 */
	public static function table(string $table, string $prefix): string {
		if (!in_array($table, self::TABLES, true)) {
			throw new \InvalidArgumentException(sprintf('Unbekannte Tabelle "%s".', $table));
		}
		return $prefix . 'kmm_' . $table;
	}

	/**
	 * CREATE-TABLE-Anweisungen für dbDelta(), Kurzname => SQL.
	 *
	 * @param string $prefix          WordPress-Tabellenpräfix.
	 * @param string $charset_collate Ergebnis von $wpdb->get_charset_collate().
	 * @return array<string, string>
	 */
	public static function definitions(string $prefix, string $charset_collate): array {
		$t = static fn(string $name): string => self::table($name, $prefix);

		$defs = [];

		// --- Stammdaten, versioniert pro Sportjahr -------------------------------

		$defs['sportjahr'] = "CREATE TABLE {$t('sportjahr')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  jahr smallint(5) unsigned NOT NULL,
  bezeichnung varchar(100) NOT NULL DEFAULT '',
  ist_aktiv tinyint(1) unsigned NOT NULL DEFAULT 0,
  meldung_beginn datetime DEFAULT NULL,
  meldeschluss datetime DEFAULT NULL,
  erinnerung_am datetime DEFAULT NULL,
  erinnerung_gesendet_am datetime DEFAULT NULL,
  abgeschlossen_am datetime DEFAULT NULL,
  anonymisiert_am datetime DEFAULT NULL,
  regeln_geaendert_am datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY jahr (jahr),
  KEY ist_aktiv (ist_aktiv)
) {$charset_collate};";

		$defs['wettbewerbsgruppe'] = "CREATE TABLE {$t('wettbewerbsgruppe')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned NOT NULL,
  code varchar(32) NOT NULL,
  bezeichnung varchar(100) NOT NULL,
  hoehermeldung_bereich varchar(16) DEFAULT NULL,
  ist_para tinyint(1) unsigned NOT NULL DEFAULT 0,
  sortierung smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY sportjahr_code (sportjahr_id,code),
  KEY sportjahr_id (sportjahr_id)
) {$charset_collate};";

		$defs['klasse'] = "CREATE TABLE {$t('klasse')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned NOT NULL,
  gruppe_id bigint(20) unsigned NOT NULL,
  nummer smallint(5) unsigned NOT NULL,
  bezeichnung varchar(100) NOT NULL,
  geschlecht char(1) NOT NULL DEFAULT 'x',
  alter_von tinyint(3) unsigned DEFAULT NULL,
  alter_bis tinyint(3) unsigned DEFAULT NULL,
  tarifstufe varchar(16) NOT NULL DEFAULT 'erwachsene',
  stufe varchar(32) DEFAULT NULL,
  ist_para tinyint(1) unsigned NOT NULL DEFAULT 0,
  ist_teamklasse tinyint(1) unsigned NOT NULL DEFAULT 0,
  festgeschrieben tinyint(1) unsigned NOT NULL DEFAULT 0,
  hinweis varchar(255) NOT NULL DEFAULT '',
  sortierung smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY gruppe_nummer (gruppe_id,nummer),
  KEY sportjahr_id (sportjahr_id),
  KEY sportjahr_stufe (sportjahr_id,stufe)
) {$charset_collate};";

		$defs['disziplin'] = "CREATE TABLE {$t('disziplin')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned NOT NULL,
  gruppe_id bigint(20) unsigned NOT NULL,
  kennzahl varchar(16) NOT NULL,
  bezeichnung varchar(150) NOT NULL,
  typ varchar(16) NOT NULL DEFAULT 'normal',
  angeboten tinyint(1) unsigned NOT NULL DEFAULT 1,
  mannschaft_groesse tinyint(3) unsigned NOT NULL DEFAULT 3,
  ergebnis_format varchar(16) NOT NULL DEFAULT 'ganz',
  tarif_override decimal(6,2) DEFAULT NULL,
  mannschaft_startgeld decimal(6,2) NOT NULL DEFAULT 0.00,
  mixteam_kennzahl_modus varchar(16) DEFAULT NULL,
  hinweis text,
  sortierung smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY sportjahr_kennzahl (sportjahr_id,kennzahl),
  KEY gruppe_id (gruppe_id)
) {$charset_collate};";

		$defs['regel'] = "CREATE TABLE {$t('regel')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned NOT NULL,
  disziplin_id bigint(20) unsigned NOT NULL,
  klasse_id bigint(20) unsigned NOT NULL,
  einzel_modus varchar(16) NOT NULL DEFAULT 'keine',
  einzel_ziel_klasse_id bigint(20) unsigned DEFAULT NULL,
  mannschaft_modus varchar(16) NOT NULL DEFAULT 'keine',
  mannschaft_ziel_klasse_id bigint(20) unsigned DEFAULT NULL,
  mindestalter tinyint(3) unsigned DEFAULT NULL,
  hinweis varchar(255) NOT NULL DEFAULT '',
  quelle varchar(64) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY disziplin_klasse (disziplin_id,klasse_id),
  KEY sportjahr_id (sportjahr_id),
  KEY klasse_id (klasse_id)
) {$charset_collate};";

		$defs['startgeld_tarif'] = "CREATE TABLE {$t('startgeld_tarif')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned NOT NULL,
  tarifstufe varchar(16) NOT NULL,
  betrag decimal(6,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY  (id),
  UNIQUE KEY sportjahr_tarifstufe (sportjahr_id,tarifstufe)
) {$charset_collate};";

		// --- Vereine und Zugang ----------------------------------------------------

		$defs['verein'] = "CREATE TABLE {$t('verein')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  vn_nummer varchar(5) NOT NULL,
  ist_aktiv tinyint(1) unsigned NOT NULL DEFAULT 1,
  notiz text,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY vn_nummer (vn_nummer),
  KEY name (name)
) {$charset_collate};";

		$defs['verein_email'] = "CREATE TABLE {$t('verein_email')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  verein_id bigint(20) unsigned NOT NULL,
  email varchar(190) NOT NULL,
  sortierung smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY verein_email (verein_id,email),
  KEY email (email)
) {$charset_collate};";

		$defs['magic_link'] = "CREATE TABLE {$t('magic_link')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  verein_id bigint(20) unsigned NOT NULL,
  sportjahr_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  erstellt_am datetime NOT NULL,
  gueltig_bis datetime NOT NULL,
  widerrufen_am datetime DEFAULT NULL,
  zuletzt_verwendet_am datetime DEFAULT NULL,
  verwendungen int(10) unsigned NOT NULL DEFAULT 0,
  erstellt_von bigint(20) unsigned DEFAULT NULL,
  anlass varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY verein_sportjahr (verein_id,sportjahr_id)
) {$charset_collate};";

		$defs['sitzung'] = "CREATE TABLE {$t('sitzung')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  verein_id bigint(20) unsigned NOT NULL,
  magic_link_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  erstellt_am datetime NOT NULL,
  gueltig_bis datetime NOT NULL,
  letzte_aktivitaet_am datetime NOT NULL,
  beendet_am datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY verein_id (verein_id),
  KEY magic_link_id (magic_link_id)
) {$charset_collate};";

		// --- Schützenliste (jahresübergreifend) -----------------------------------

		$defs['schuetze'] = "CREATE TABLE {$t('schuetze')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  verein_id bigint(20) unsigned NOT NULL,
  nachname varchar(100) NOT NULL,
  vorname varchar(100) NOT NULL,
  geburtsdatum date NOT NULL,
  geschlecht char(1) NOT NULL,
  mitgliedsnummer varchar(9) NOT NULL DEFAULT '',
  zuletzt_gemeldet_jahr smallint(5) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY verein_name (verein_id,nachname,vorname),
  KEY zuletzt_gemeldet_jahr (zuletzt_gemeldet_jahr)
) {$charset_collate};";

		$defs['hoehermeldung'] = "CREATE TABLE {$t('hoehermeldung')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  schuetze_id bigint(20) unsigned NOT NULL,
  sportjahr_id bigint(20) unsigned NOT NULL,
  bereich varchar(16) NOT NULL,
  ziel_stufe varchar(32) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY schuetze_jahr_bereich (schuetze_id,sportjahr_id,bereich),
  KEY sportjahr_id (sportjahr_id)
) {$charset_collate};";

		// --- Meldungen ---------------------------------------------------------------

		$defs['meldung'] = "CREATE TABLE {$t('meldung')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  verein_id bigint(20) unsigned NOT NULL,
  sportjahr_id bigint(20) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'offen',
  ansprechpartner_name varchar(150) NOT NULL DEFAULT '',
  ansprechpartner_email varchar(190) NOT NULL DEFAULT '',
  ansprechpartner_telefon varchar(50) NOT NULL DEFAULT '',
  eingereicht_am datetime DEFAULT NULL,
  wieder_geoeffnet_am datetime DEFAULT NULL,
  nachmeldung_bis datetime DEFAULT NULL,
  letzte_sammelmail_am datetime DEFAULT NULL,
  startgeld_summe decimal(8,2) NOT NULL DEFAULT 0.00,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY verein_sportjahr (verein_id,sportjahr_id),
  KEY sportjahr_status (sportjahr_id,status)
) {$charset_collate};";

		$defs['einzelmeldung'] = "CREATE TABLE {$t('einzelmeldung')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  meldung_id bigint(20) unsigned NOT NULL,
  sportjahr_id bigint(20) unsigned NOT NULL,
  verein_id bigint(20) unsigned NOT NULL,
  schuetze_id bigint(20) unsigned DEFAULT NULL,
  disziplin_id bigint(20) unsigned NOT NULL,
  geschlecht char(1) NOT NULL,
  geburtsjahr smallint(5) unsigned DEFAULT NULL,
  klasse_id bigint(20) unsigned DEFAULT NULL,
  startklasse_id bigint(20) unsigned DEFAULT NULL,
  mannschaft_klasse_id bigint(20) unsigned DEFAULT NULL,
  para_klasse_id bigint(20) unsigned DEFAULT NULL,
  hoehermeldung_angewendet tinyint(1) unsigned NOT NULL DEFAULT 0,
  startrecht tinyint(1) unsigned NOT NULL DEFAULT 1,
  meldeergebnis decimal(6,1) DEFAULT NULL,
  nicht_meldung tinyint(1) unsigned NOT NULL DEFAULT 0,
  mannschaft_id bigint(20) unsigned DEFAULT NULL,
  startgeld decimal(6,2) NOT NULL DEFAULT 0.00,
  startgeld_berechnen tinyint(1) unsigned NOT NULL DEFAULT 1,
  konflikt tinyint(1) unsigned NOT NULL DEFAULT 0,
  konflikt_text varchar(255) NOT NULL DEFAULT '',
  verarbeitungsstatus varchar(24) NOT NULL DEFAULT 'ungeprueft',
  verarbeitungsgrund varchar(255) NOT NULL DEFAULT '',
  verarbeitet_am datetime DEFAULT NULL,
  abgemeldet_am datetime DEFAULT NULL,
  ergebnis_ref varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY meldung_schuetze_disziplin (meldung_id,schuetze_id,disziplin_id),
  KEY sportjahr_disziplin (sportjahr_id,disziplin_id),
  KEY verein_id (verein_id),
  KEY mannschaft_id (mannschaft_id),
  KEY schuetze_id (schuetze_id),
  KEY sportjahr_konflikt (sportjahr_id,konflikt)
) {$charset_collate};";

		$defs['mannschaft'] = "CREATE TABLE {$t('mannschaft')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  meldung_id bigint(20) unsigned NOT NULL,
  sportjahr_id bigint(20) unsigned NOT NULL,
  verein_id bigint(20) unsigned NOT NULL,
  disziplin_id bigint(20) unsigned NOT NULL,
  klasse_id bigint(20) unsigned NOT NULL,
  nummer smallint(5) unsigned NOT NULL,
  startgeld decimal(6,2) NOT NULL DEFAULT 0.00,
  unvollstaendig tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY meldung_disziplin_nummer (meldung_id,disziplin_id,nummer),
  KEY sportjahr_disziplin (sportjahr_id,disziplin_id)
) {$charset_collate};";

		// --- Protokoll, Exporte, Mails ----------------------------------------------

		$defs['protokoll'] = "CREATE TABLE {$t('protokoll')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned DEFAULT NULL,
  verein_id bigint(20) unsigned DEFAULT NULL,
  akteur_typ varchar(16) NOT NULL,
  akteur_id bigint(20) unsigned DEFAULT NULL,
  akteur_name varchar(150) NOT NULL DEFAULT '',
  aktion varchar(64) NOT NULL,
  objekt_typ varchar(32) NOT NULL DEFAULT '',
  objekt_id bigint(20) unsigned DEFAULT NULL,
  zusammenfassung varchar(255) NOT NULL DEFAULT '',
  details longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY sportjahr_verein (sportjahr_id,verein_id),
  KEY created_at (created_at),
  KEY aktion (aktion)
) {$charset_collate};";

		$defs['export'] = "CREATE TABLE {$t('export')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned NOT NULL,
  typ varchar(32) NOT NULL,
  disziplin_id bigint(20) unsigned DEFAULT NULL,
  gruppe_id bigint(20) unsigned DEFAULT NULL,
  parameter text,
  dateiname varchar(190) NOT NULL DEFAULT '',
  zeilen int(10) unsigned NOT NULL DEFAULT 0,
  erstellt_von bigint(20) unsigned DEFAULT NULL,
  erstellt_am datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY sportjahr_typ (sportjahr_id,typ,erstellt_am)
) {$charset_collate};";

		$defs['mail_log'] = "CREATE TABLE {$t('mail_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sportjahr_id bigint(20) unsigned DEFAULT NULL,
  verein_id bigint(20) unsigned DEFAULT NULL,
  typ varchar(32) NOT NULL,
  betreff varchar(255) NOT NULL DEFAULT '',
  empfaenger_anzahl smallint(5) unsigned NOT NULL DEFAULT 0,
  erfolgreich tinyint(1) unsigned NOT NULL DEFAULT 0,
  fehler varchar(255) NOT NULL DEFAULT '',
  gesendet_am datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY sportjahr_verein_typ (sportjahr_id,verein_id,typ)
) {$charset_collate};";

		return $defs;
	}
}
