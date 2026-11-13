<?php
/**
 * Tests for the usage of constants for the credentials of Google Cloud Storage.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit\GoogleCloudStorage;

use ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage;
use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;

/**
 * Object to test the usage of constants for the credentials of Google Cloud Storage.
 *
 * The constants of PHP could not be removed after they have been defined. Therefore, each test
 * uses its own prefix for the constant names via the hook "efml_service_field_constant_name".
 */
class Constants extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * The prefix for the constants of the actual test.
	 *
	 * @var string
	 */
	private string $prefix = '';

	/**
	 * Counter for unique prefixes.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// bail if the main plugin does not support the checks of the target.
		if ( ! method_exists( GoogleCloudStorage::get_instance(), 'is_target_blocked_by_constants' ) ) {
			$this->markTestSkipped( 'Requires "External Files in Media Library" 6.0.0 or newer.' );
		}

		// use a unique prefix for the constants of this test.
		++self::$counter;
		$this->prefix = 'EFMLGCSTEST' . self::$counter . '_';
		add_filter( 'efml_service_field_constant_name', array( $this, 'set_constant_name' ) );
		add_filter( 'efml_service_connections_constant_name', array( $this, 'set_constant_name' ) );
		GoogleCloudStorage::get_instance()->set_fields( array() );
	}

	/**
	 * Reset the environment after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'efml_service_field_constant_name', array( $this, 'set_constant_name' ) );
		remove_filter( 'efml_service_connections_constant_name', array( $this, 'set_constant_name' ) );
		GoogleCloudStorage::get_instance()->set_fields( array() );
		parent::tear_down();
	}

	/**
	 * Use the prefix of the actual test for the constant names.
	 *
	 * @param string $constant_name The name of the constant.
	 *
	 * @return string
	 */
	public function set_constant_name( string $constant_name ): string {
		return $this->prefix . $constant_name;
	}

	/**
	 * Test that only the authentication JSON is required.
	 *
	 * @return void
	 */
	public function test_required_fields(): void {
		$this->assertEquals( array( 'authentication_json' ), GoogleCloudStorage::get_instance()->get_required_fields_for_constants() );
	}

	/**
	 * Test the constant for the JSON without bucket: every bucket could be used.
	 *
	 * @return void
	 */
	public function test_json_without_bucket(): void {
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_AUTHENTICATION_JSON', '{"type":"service_account"}' );

		$gcs = GoogleCloudStorage::get_instance();
		$gcs->set_fields(
			array(
				'authentication_json' => array( 'value' => '{"type":"other"}' ),
				'bucket'              => array( 'value' => 'any-bucket' ),
			)
		);
		$fields = $gcs->get_fields();
		$this->assertEquals( '{"type":"service_account"}', $fields['authentication_json']['value'] );
		$this->assertEquals( 'any-bucket', $gcs->get_bucket_name() );
		$this->assertFalse( $gcs->is_target_blocked_by_constants( '', $fields ) );
	}

	/**
	 * Test the constants for JSON and bucket: other buckets are blocked.
	 *
	 * @return void
	 */
	public function test_json_with_bucket(): void {
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_AUTHENTICATION_JSON', '{"type":"service_account"}' );
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_BUCKET', 'constant-bucket' );

		$gcs = GoogleCloudStorage::get_instance();

		// without fields from outside: the constants are used.
		$this->assertEquals( 'constant-bucket', $gcs->get_bucket_name() );

		// another bucket: the constants are not used, the source is blocked.
		$other = array(
			'authentication_json' => array( 'value' => '{"type":"other"}' ),
			'bucket'              => array( 'value' => 'other-bucket' ),
		);
		$gcs->set_fields( $other );
		$this->assertEquals( '{"type":"other"}', $gcs->get_fields()['authentication_json']['value'] );
		$this->assertEquals( 'other-bucket', $gcs->get_bucket_name() );
		$this->assertTrue( $gcs->is_target_blocked_by_constants( '', $other ) );
	}

	/**
	 * Test several connections: the authentication JSON and the bucket of the chosen or matching connection are used.
	 *
	 * @return void
	 */
	public function test_several_connections(): void {
		// skip the test, if the main plugin does not support several connections.
		if ( ! method_exists( GoogleCloudStorage::get_instance(), 'get_connections' ) ) {
			$this->markTestSkipped( 'Requires "External Files in Media Library" 6.0.0 or newer.' );
		}

		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_CONNECTIONS', 'images,downloads' );
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_IMAGES_AUTHENTICATION_JSON', '{"type":"service_account","name":"images"}' );
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_IMAGES_BUCKET', 'images-bucket' );
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_DOWNLOADS_AUTHENTICATION_JSON', '{"type":"service_account","name":"downloads"}' );
		define( $this->prefix . 'EFML_GOOGLE_CLOUD_STORAGE_DOWNLOADS_BUCKET', 'downloads-bucket' );

		$gcs = GoogleCloudStorage::get_instance();
		$this->assertEquals( array( 'images', 'downloads' ), array_keys( $gcs->get_connections() ) );
		$get_json = new \ReflectionMethod( $gcs, 'get_authentication_json' );
		$get_json->setAccessible( true );

		// the chosen connection is used.
		$gcs->set_fields(
			array(
				'efml_connection'     => array( 'value' => 'downloads' ),
				'authentication_json' => array( 'value' => '' ),
				'bucket'              => array( 'value' => '' ),
			)
		);
		$this->assertEquals( 'downloads-bucket', $gcs->get_bucket_name() );
		$this->assertEquals( '{"type":"service_account","name":"downloads"}', $get_json->invoke( $gcs ) );

		// without a chosen connection, the connection is detected by the bucket.
		$gcs->set_fields(
			array(
				'authentication_json' => array( 'value' => '' ),
				'bucket'              => array( 'value' => 'images-bucket' ),
			)
		);
		$this->assertEquals( '{"type":"service_account","name":"images"}', $get_json->invoke( $gcs ) );

		// another bucket is blocked.
		$other = array(
			'authentication_json' => array( 'value' => '' ),
			'bucket'              => array( 'value' => 'other-bucket' ),
		);
		$this->assertTrue( $gcs->is_target_blocked_by_constants( '', $other ) );
	}
}
