<?php

declare(strict_types=1);

namespace KSV\KMM\Tests\Integration;

use KSV\KMM\Infrastructure\Database\Migrator;
use KSV\KMM\Infrastructure\Database\Schema;
use KSV\KMM\Infrastructure\Database\Tables;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase {

	protected function setUp(): void {
		if (!KMM_INTEGRATION) {
			$this->markTestSkipped('KMM_WP_ROOT nicht gesetzt.');
		}
		Migrator::maybe_migrate();
		$this->truncate_all();
		wp_set_current_user(1);
	}

	protected function truncate_all(): void {
		global $wpdb;
		foreach (Schema::TABLES as $t) {
			$wpdb->query('TRUNCATE TABLE ' . Tables::name($t)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
