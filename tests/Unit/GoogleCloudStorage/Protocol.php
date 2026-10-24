<?php
/**
 * Tests for class ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit\GoogleCloudStorage;

use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol.
 */
class Protocol extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * The URL mark of Google Cloud Storage with the bucket.
	 *
	 * @var string
	 */
	private string $mark = 'https://console.cloud.google.com/storage/browser/my-bucket/';

	/**
	 * The URL of the bucket as directory.
	 *
	 * @var string
	 */
	private string $directory = 'https://www.googleapis.com/storage/v1/b/my-bucket/';

	/**
	 * Return the protocol object for the given URL with the fields of the tests.
	 *
	 * @param string                            $url    The URL.
	 * @param array<string,array<string,mixed>> $fields The fields (optional).
	 *
	 * @return \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol
	 */
	private function get_protocol( string $url, ?array $fields = null ): \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol {
		$protocol = new \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol( $url );
		$protocol->set_fields( is_null( $fields ) ? $this->get_fields() : $fields );
		return $protocol;
	}

	/**
	 * Return the content of the example file.
	 *
	 * @return string
	 */
	private function get_example_content(): string {
		return (string) file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the basic settings of the protocol.
	 *
	 * @return void
	 */
	public function test_protocol(): void {
		$protocol = $this->get_protocol( $this->mark . 'example.jpg' );
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\Protocol_Base::class, $protocol );
		$this->assertEquals( 'google-cloud-storage', $protocol->get_name() );
		$this->assertEquals( 'Google Cloud Storage', $protocol->get_title() );
		$this->assertTrue( $protocol->is_available() );
		$this->assertTrue( $protocol->should_be_saved_local() );
		$this->assertFalse( $protocol->can_change_hosting() );
		$this->assertFalse( $protocol->can_check_availability() );
		$this->assertTrue( $protocol->check_url( $this->mark . 'example.jpg' ) );
		$this->assertFalse( $protocol->check_url( '' ) );
	}

	/**
	 * Test if only Google Cloud Storage URLs are compatible.
	 *
	 * @return void
	 */
	public function test_is_url_compatible(): void {
		$this->connect();
		$this->assertTrue( $this->get_protocol( $this->mark . 'example.jpg' )->is_url_compatible() );
		$this->assertTrue( $this->get_protocol( $this->directory )->is_url_compatible() );
		$this->assertFalse( $this->get_protocol( 'https://example.com/example.jpg' )->is_url_compatible() );
	}

	/**
	 * Test the import of a single file from a private bucket, which is saved local.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_file(): void {
		$this->connect();
		$this->add_bucket();
		$this->add_iam_policy( false );
		$this->add_object( 'folder/example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'folder/example.jpg' )->get_url_infos();

		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertTrue( $infos[0]['local'] );
		$this->assertEquals( $this->mark . 'folder/example.jpg', $infos[0]['url'] );
		$this->assertEquals( 'image/jpeg', $infos[0]['mime-type'] );
		$this->assertEquals( 706, $infos[0]['filesize'] );
		$this->assertEquals( strtotime( '2024-01-02T03:04:05.000Z' ), $infos[0]['last-modified'] );
		$this->assertEquals( $this->get_example_content(), file_get_contents( $infos[0]['tmp-file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the import of a single file from a public bucket, which uses the public URL.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_public_file(): void {
		$this->connect();
		$this->add_bucket();
		$this->add_iam_policy( true );
		$this->add_object( 'folder/example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'folder/example.jpg' )->get_url_infos();

		$this->assertFalse( $infos[0]['local'] );

		// the public URL must contain the complete path of the file in the bucket.
		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/folder/example.jpg', $infos[0]['url'] );
	}

	/**
	 * Test the import of a single file with the settings of the global mode.
	 *
	 * @return void
	 */
	public function test_get_url_infos_in_global_mode(): void {
		$this->do_login();
		$this->set_mode( 'global' );
		update_option( 'eml_google_cloud_storage_json', \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance()->encrypt_value( self::$authentication_json ) );
		update_option( 'eml_google_cloud_storage_bucket', 'my-bucket' );
		$this->add_bucket();
		$this->add_iam_policy( false );
		$this->add_object( 'example.jpg', $this->get_example_content() );

		$infos = $this->get_protocol( $this->mark . 'example.jpg', array() )->get_url_infos();

		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
	}

	/**
	 * Test the import of a file, which does not exist.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_error(): void {
		$this->connect();
		$this->add_bucket();
		$this->add_google_response( 'GET', '/o/example.jpg', 404, $this->get_error_body( 404 ) );

		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'example.jpg' )->get_url_infos() );
		$this->assertStringContainsString( 'Error during request of file data', $this->get_log_entries( $this->mark . 'example.jpg' )[0]['log'] );
	}

	/**
	 * Test the import of a file, if the bucket does not exist or could not be loaded.
	 *
	 * @return void
	 */
	public function test_get_url_infos_with_bucket_error(): void {
		$this->connect();

		// test 1: the bucket does not exist.
		$this->add_bucket( 404 );
		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'example.jpg' )->get_url_infos() );

		// test 2: the access to the bucket is not allowed.
		$this->add_bucket( 403 );
		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'example.jpg' )->get_url_infos() );
		$this->assertStringContainsString( 'could not be loaded', $this->get_log_entries( $this->mark . 'example.jpg' )[0]['log'] );

		// test 3: without valid credentials.
		$this->assertEquals( array(), $this->get_protocol( $this->mark . 'example.jpg', $this->get_fields( array( 'authentication_json' => 'no json' ) ) )->get_url_infos() );
	}

	/**
	 * Test the import of all files in the bucket.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_bucket(): void {
		$this->connect();
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 0 );
		$this->add_bucket();
		$this->add_object_list( array( 'example.jpg', 'folder/', 'folder/photo.jpg' ) );
		$this->add_object( 'example.jpg', $this->get_example_content() );
		$this->add_object( 'folder/photo.jpg', 'content' );

		$infos = $this->get_protocol( $this->directory )->get_url_infos();

		// the directory itself is not imported as file.
		$this->assertCount( 2, $infos );
		$this->assertNotContains( $this->api_url . '/o/folder%2F', wp_list_pluck( $this->get_google_requests(), 'url' ) );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertEquals( $this->directory . 'example.jpg', $infos[0]['url'] );
		$this->assertEquals( 706, $infos[0]['filesize'] );
		$this->assertEquals( 'photo.jpg', $infos[1]['title'] );
		$this->assertEquals( $this->directory . 'folder/photo.jpg', $infos[1]['url'] );
		$this->assertEquals( 'content', file_get_contents( $infos[1]['tmp-file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the import of all files in the bucket, if a file could not be downloaded.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_bucket_with_error(): void {
		$this->connect();
		$this->add_bucket();
		$this->add_object_list( array( 'example.jpg', 'error.jpg' ) );
		$this->add_object( 'example.jpg', $this->get_example_content() );
		$this->add_google_response( 'GET', '/o/error.jpg', 403, $this->get_error_body( 403 ), array( 'alt' => 'media' ) );

		$infos = $this->get_protocol( $this->directory )->get_url_infos();

		$this->assertCount( 1, $infos );
		$this->assertEquals( 'example.jpg', $infos[0]['title'] );
		$this->assertNotEmpty( $this->get_log_entries( $this->directory . 'error.jpg' ) );
	}

	/**
	 * Test the import of all files, if the bucket could not be loaded.
	 *
	 * @return void
	 */
	public function test_get_url_infos_for_bucket_with_bucket_error(): void {
		$this->connect();
		$this->add_bucket( 403 );

		$this->assertEquals( array(), $this->get_protocol( $this->directory )->get_url_infos() );
		$this->assertStringContainsString( 'could not be loaded', $this->get_log_entries( $this->directory )[0]['log'] );
	}
}
