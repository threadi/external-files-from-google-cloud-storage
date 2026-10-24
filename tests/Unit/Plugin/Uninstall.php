<?php
/**
 * Tests for class ExternalFilesFromGoogleCloudStorage\Plugin\Uninstall.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit\Plugin;

use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleCloudStorage\Plugin\Uninstall.
 */
class Uninstall extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$instance = \ExternalFilesFromGoogleCloudStorage\Plugin\Uninstall::get_instance();
		$this->assertInstanceOf( \ExternalFilesFromGoogleCloudStorage\Plugin\Uninstall::class, $instance );
		$this->assertSame( $instance, \ExternalFilesFromGoogleCloudStorage\Plugin\Uninstall::get_instance() );
	}

	/**
	 * Test if the uninstallation removes the cached release data.
	 *
	 * @return void
	 */
	public function test_run(): void {
		set_site_transient( 'efmlgcs_latest_release', array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );

		\ExternalFilesFromGoogleCloudStorage\Plugin\Uninstall::get_instance()->run();

		$this->assertFalse( get_site_transient( 'efmlgcs_latest_release' ) );
	}
}
