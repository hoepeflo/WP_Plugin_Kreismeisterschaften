<?php
/**
 * WP-Cron-Ereignisse des Plugins (Präfix kmm_).
 *
 * Stündliches Ereignis kmm_hourly (Erinnerungsmail, Fallback der Sammelmail) und
 * tägliches Ereignis kmm_sammelmail zur eingestellten Uhrzeit (Phase 2). Auf dem Server
 * sollte WP-Cron über einen echten Cronjob angestoßen werden, siehe docs/INSTALLATION.md.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM;

use KSV\KMM\Support\Settings;

final class Cron {

	public const HOURLY_HOOK = 'kmm_hourly';
	public const DAILY_HOOK  = 'kmm_sammelmail';

	public static function register(): void {
		add_action(self::HOURLY_HOOK, [self::class, 'run_hourly']);
		add_action(self::DAILY_HOOK, [self::class, 'run_daily']);
		// Absicherung: falls das Ereignis (z. B. nach einem Cron-Reset) fehlt, neu planen.
		add_action('init', [self::class, 'schedule']);
		add_action('update_option_' . Settings::OPTION, [self::class, 'reschedule_daily'], 10, 2);
	}

	public static function schedule(): void {
		if (!wp_next_scheduled(self::HOURLY_HOOK)) {
			wp_schedule_event(time() + 300, 'hourly', self::HOURLY_HOOK);
		}
		if (!wp_next_scheduled(self::DAILY_HOOK)) {
			wp_schedule_event(self::naechster_taeglicher_lauf(), 'daily', self::DAILY_HOOK);
		}
	}

	public static function unschedule(): void {
		foreach ([self::HOURLY_HOOK, self::DAILY_HOOK] as $hook) {
			$timestamp = wp_next_scheduled($hook);
			while ($timestamp !== false) {
				wp_unschedule_event($timestamp, $hook);
				$timestamp = wp_next_scheduled($hook);
			}
		}
	}

	/**
	 * Nächster Zeitpunkt (Unix-Zeit) der eingestellten Sammelmail-Uhrzeit in der
	 * WordPress-Zeitzone.
	 */
	public static function naechster_taeglicher_lauf(?int $jetzt = null): int {
		$jetzt ??= time();
		$uhrzeit = (string) Settings::get('sammelmail_uhrzeit');
		[$h, $m] = preg_match('/^(\d{1,2}):(\d{2})$/', $uhrzeit, $t) === 1 ? [(int) $t[1], (int) $t[2]] : [18, 0];
		$tz = wp_timezone();
		$lauf = (new \DateTimeImmutable('@' . $jetzt))->setTimezone($tz)->setTime($h, $m, 0);
		if ($lauf->getTimestamp() <= $jetzt) {
			$lauf = $lauf->modify('+1 day');
		}
		return $lauf->getTimestamp();
	}

	/**
	 * Bei geänderter Uhrzeit das tägliche Ereignis neu planen.
	 *
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function reschedule_daily($old, $new): void {
		$alt = is_array($old) ? ($old['sammelmail_uhrzeit'] ?? null) : null;
		$neu = is_array($new) ? ($new['sammelmail_uhrzeit'] ?? null) : null;
		if ($alt === $neu) {
			return;
		}
		$timestamp = wp_next_scheduled(self::DAILY_HOOK);
		while ($timestamp !== false) {
			wp_unschedule_event($timestamp, self::DAILY_HOOK);
			$timestamp = wp_next_scheduled(self::DAILY_HOOK);
		}
		wp_schedule_event(self::naechster_taeglicher_lauf(), 'daily', self::DAILY_HOOK);
	}

	/** Tägliche Aufgaben (Sammelmail); konkrete Jobs hängen sich über diese Action ein. */
	public static function run_daily(): void {
		do_action('kmm_daily_tasks');
	}

	/** Stündliche Aufgaben; konkrete Jobs hängen sich über diese Action ein. */
	public static function run_hourly(): void {
		do_action('kmm_hourly_tasks');
	}
}
