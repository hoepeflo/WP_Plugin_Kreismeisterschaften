<?php
/**
 * WP-Cron-Ereignisse des Plugins (Präfix kmm_).
 *
 * In Phase 1 läuft ein stündliches Ereignis, an das die Erinnerungsmail
 * (Meilenstein 7) gehängt wird. Auf dem Server sollte WP-Cron über einen echten
 * Cronjob angestoßen werden, siehe docs/INSTALLATION.md.
 *
 * @package KSV\KMM
 */

declare(strict_types=1);

namespace KSV\KMM;

final class Cron {

	public const HOURLY_HOOK = 'kmm_hourly';

	public static function register(): void {
		add_action(self::HOURLY_HOOK, [self::class, 'run_hourly']);
		// Absicherung: falls das Ereignis (z. B. nach einem Cron-Reset) fehlt, neu planen.
		add_action('init', [self::class, 'schedule']);
	}

	public static function schedule(): void {
		if (!wp_next_scheduled(self::HOURLY_HOOK)) {
			wp_schedule_event(time() + 300, 'hourly', self::HOURLY_HOOK);
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled(self::HOURLY_HOOK);
		while ($timestamp !== false) {
			wp_unschedule_event($timestamp, self::HOURLY_HOOK);
			$timestamp = wp_next_scheduled(self::HOURLY_HOOK);
		}
	}

	/** Stündliche Aufgaben; konkrete Jobs hängen sich über diese Action ein. */
	public static function run_hourly(): void {
		do_action('kmm_hourly_tasks');
	}
}
