<?php
/**
 * Tests for class ExternalFilesFromGoogleCloudStorage\Plugin\Configuration.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit\Plugin;

use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;
use ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage;
use ExternalFilesInMediaLibrary\Services\Services;

/**
 * Object to test functions in class ExternalFilesFromGoogleCloudStorage\Plugin\Configuration.
 */
class Configuration extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * Test the name, title and hints of the configuration.
	 *
	 * @return void
	 */
	public function test_configuration(): void {
		$configuration = new \ExternalFilesFromGoogleCloudStorage\Plugin\Configuration();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Plugin\Configuration_Base::class, $configuration );
		$this->assertEquals( 'google_cloud_storage', $configuration->get_name() );
		$this->assertEquals( 'Use only Google Cloud Storage', $configuration->get_title() );
		$this->assertCount( 1, $configuration->get_dialog_hints() );
		$this->assertStringContainsString( 'Google Cloud Storage', $configuration->get_dialog_hints()[0] );
	}

	/**
	 * Test if all texts of the configuration use the text domain of this plugin.
	 *
	 * @return void
	 */
	public function test_text_domain(): void {
		$domains  = array();
		$callback = static function ( string $translation, string $text, string $domain ) use ( &$domains ): string {
			$domains[] = $domain;
			return $translation;
		};
		add_filter( 'gettext', $callback, 10, 3 );
		$configuration = new \ExternalFilesFromGoogleCloudStorage\Plugin\Configuration();
		$configuration->get_title();
		$configuration->get_dialog_hints();
		remove_filter( 'gettext', $callback );

		$this->assertNotEmpty( $domains );
		$this->assertEquals( array( 'external-files-from-google-cloud-storage' ), array_values( array_unique( $domains ) ) );
	}

	/**
	 * Test if the configuration allows only Google Cloud Storage and disables all other services.
	 *
	 * @return void
	 */
	public function test_run(): void {
		update_option( 'eml_disable_plugin_hints', 0 );

		( new \ExternalFilesFromGoogleCloudStorage\Plugin\Configuration() )->run();

		// Google Cloud Storage is allowed for its default roles.
		$this->assertEquals( GoogleCloudStorage::get_instance()->get_default_roles(), get_option( 'eml_service_google-cloud-storage_allowed_roles' ) );

		// all other services are disabled.
		foreach ( Services::get_instance()->get_services_as_objects() as $service_obj ) {
			if ( ! method_exists( $service_obj, 'get_name' ) || 'google-cloud-storage' === $service_obj->get_name() ) {
				continue;
			}
			$this->assertEquals( array(), get_option( 'eml_service_' . $service_obj->get_name() . '_allowed_roles' ), 'Service ' . $service_obj->get_name() . ' is not disabled.' );
		}

		// the hints for other plugins are disabled.
		$this->assertEquals( 1, absint( get_option( 'eml_disable_plugin_hints' ) ) );
	}
}
