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

	/**
	 * Test the file list of the bucket page by page: directories are not listed, the token is used for the next page.
	 *
	 * @return void
	 */
	public function test_get_file_list(): void {
		$this->connect();
		$this->add_bucket();
		$this->add_iam_policy( true );
		$protocol = $this->get_protocol( $this->directory );
		$this->assertTrue( $protocol->supports_file_list() );
		$this->assertTrue( $protocol->is_directory() );
		$this->assertFalse( $this->get_protocol( $this->directory . 'example.jpg' )->is_directory() );

		// first page with the token for the next page.
		$this->add_google_response(
			'GET',
			'/o',
			200,
			array(
				'items'         => array( $this->get_object_data( 'example.jpg', 'content' ), $this->get_object_data( 'folder/', '' ) ),
				'nextPageToken' => 'page-2',
			),
			array( 'maxResults' => '500' )
		);
		$page = $protocol->get_file_list( '' );
		$this->assertIsArray( $page );
		$this->assertEquals( 'page-2', $page['cursor'] );
		$this->assertCount( 1, $page['files'] );
		$this->assertEquals( $this->directory . 'example.jpg', $page['files'][0]['url'] );
		$this->assertEquals( 7, $page['files'][0]['filesize'] );
		$this->assertEquals( strtotime( '2024-01-02T03:04:05.000Z' ), $page['files'][0]['last-modified'] );

		// the import saves files of public buckets with their public URL.
		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/example.jpg', $page['files'][0]['media-url'] );

		// last page.
		$this->add_google_response( 'GET', '/o', 200, array( 'items' => array( $this->get_object_data( 'folder/photo.jpg', 'content' ) ) ), array( 'pageToken' => 'page-2' ) );
		$page = $protocol->get_file_list( 'page-2' );
		$this->assertIsArray( $page );
		$this->assertEquals( '', $page['cursor'] );
		$this->assertEquals( $this->directory . 'folder/photo.jpg', $page['files'][0]['url'] );
	}

	/**
	 * Test the file list, if the bucket could not be loaded.
	 *
	 * @return void
	 */
	public function test_get_file_list_with_error(): void {
		$this->connect();
		$this->add_bucket( 403 );
		$this->assertFalse( $this->get_protocol( $this->directory )->get_file_list( '' ) );

		// error during a later page.
		$this->add_iam_policy( false );
		$this->add_google_response( 'GET', '/o', 403, $this->get_error_body( 403 ), array( 'pageToken' => 'page-2' ) );
		$this->assertFalse( $this->get_protocol( $this->directory )->get_file_list( 'page-2' ) );
	}

	/**
	 * Test the signed URL for a private file.
	 *
	 * @return void
	 */
	public function test_get_signed_url(): void {
		$url      = 'https://storage.googleapis.com/my-bucket/folder/example.jpg';
		$protocol = $this->get_protocol( $url );
		$start    = 1767225600;
		$expires  = $start + 7200;

		// get the signed URL.
		$signed_url = $protocol->get_signed_url( $url, $start, $expires );
		$this->assertStringStartsWith( 'https://storage.googleapis.com/my-bucket/folder/example.jpg?', $signed_url );
		$this->assertStringContainsString( 'X-Goog-Signature=', $signed_url );
		$this->assertStringContainsString( 'X-Goog-Expires=7200', $signed_url );
		$this->assertStringContainsString( 'X-Goog-Date=20260101T000000Z', $signed_url );

		// the same time range results in the same URL.
		$this->assertEquals( $signed_url, $protocol->get_signed_url( $url, $start, $expires ) );

		// invalid values.
		$this->assertEquals( '', $protocol->get_signed_url( '', $start, $expires ) );
		$this->assertEquals( '', $protocol->get_signed_url( $url, $expires, $start ) );

		// a validity of more than 7 days is not possible.
		$this->assertEquals( '', $protocol->get_signed_url( $url, $start, $start + 8 * DAY_IN_SECONDS ) );
	}

	/**
	 * Test that a temporary file of an exported public file is loaded via HTTP (before, no file was returned).
	 *
	 * @return void
	 */
	public function test_get_temp_file_of_public_file(): void {
		$url = 'https://storage.googleapis.com/my-bucket/example.jpg';
		$this->add_http_response( $url, 200, array( 'content-type' => 'image/jpeg' ), $this->get_example_content() );

		// get the temporary file.
		$tmp_file = $this->get_protocol( $url )->get_temp_file( $url, \ExternalFilesInMediaLibrary\Plugin\Helper::get_wp_filesystem() );
		$this->assertIsString( $tmp_file );
		wp_delete_file( $tmp_file );
	}

	/**
	 * Test the URL of the platform.
	 *
	 * @return void
	 */
	public function test_get_platform_url(): void {
		$this->assertEquals( 'https://console.cloud.google.com/storage/browser/', \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance()->get_platform_url() );
	}
}
