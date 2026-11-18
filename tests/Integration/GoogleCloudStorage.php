<?php
/**
 * Integration tests for Google Cloud Storage against a real Google Cloud Storage emulator.
 *
 * These tests need a running emulator of the JSON API of Google Cloud Storage, e.g. "gcp-storage-emulator".
 * Its endpoint is set in the environment variable EFMLGCS_INTEGRATION_ENDPOINT (e.g. "http://127.0.0.1:9023").
 * Without the endpoint the tests are skipped.
 *
 * The emulator does not check any credentials, so the requests are sent without authentication. The emulator
 * delivers public files below its endpoint (e.g. "http://127.0.0.1:9023/bucket/file.jpg"), so the public URLs
 * of the plugin are changed for these tests via the hook "efmlgcs_service_google_cloud_storage_public_url".
 *
 * Run them with: composer test-integration
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Integration;

use ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export;
use ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol;
use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;

/**
 * Object to test Google Cloud Storage against a real Google Cloud Storage emulator.
 */
class GoogleCloudStorage extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * Prepare each test: create a new public bucket and send the requests to the emulator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// bail if no emulator is configured.
		if ( ! $this->get_endpoint() ) {
			$this->markTestSkipped( 'Set the environment variable EFMLGCS_INTEGRATION_ENDPOINT to run the integration tests.' );
		}

		// send the requests to the emulator instead of the local mock of the unit tests.
		remove_filter( 'efmlgcs_storage_client_config', array( externalFilesFromGoogleCloudStorageTests::class, 'set_http_handler' ), PHP_INT_MAX );
		add_filter( 'efmlgcs_storage_client_config', array( $this, 'use_emulator' ), PHP_INT_MAX );
		add_filter( 'efmlgcs_service_google_cloud_storage_public_url', array( $this, 'get_public_url' ), 10, 3 );

		// allow the HTTP requests to the emulator.
		remove_filter( 'pre_http_request', array( externalFilesFromGoogleCloudStorageTests::class, 'filter_http_requests' ) );

		// create a new bucket for this test and make it public readable, like it is necessary for the export.
		$this->bucket         = 'efml-test-' . strtolower( wp_generate_password( 8, false ) );
		$bucket               = $this->get_storage()->createBucket( $this->bucket );
		$policy               = $bucket->iam()->policy();
		$policy['bindings'][] = array(
			'role'    => 'roles/storage.objectViewer',
			'members' => array( 'allUsers' ),
		);
		$bucket->iam()->setPolicy( $policy );
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'efmlgcs_storage_client_config', array( $this, 'use_emulator' ), PHP_INT_MAX );
		remove_filter( 'efmlgcs_service_google_cloud_storage_public_url', array( $this, 'get_public_url' ) );
		add_filter( 'pre_http_request', array( externalFilesFromGoogleCloudStorageTests::class, 'filter_http_requests' ), 10, 3 );
		parent::tear_down();
	}

	/**
	 * Send the requests of the client to the emulator, without authentication.
	 *
	 * @param array<string,mixed> $config The configuration of the client.
	 *
	 * @return array<string,mixed>
	 */
	public function use_emulator( array $config ): array {
		$config['apiEndpoint']        = $this->get_endpoint();
		$config['credentialsFetcher'] = new InsecureCredentials();
		$config['retries']            = 0;
		return $config;
	}

	/**
	 * Return the public URL of a file on the emulator.
	 *
	 * @param string $url         The URL.
	 * @param string $bucket_name The name of the bucket.
	 * @param string $file_name   The name of the file.
	 *
	 * @return string
	 */
	public function get_public_url( string $url, string $bucket_name, string $file_name ): string {
		return $this->get_endpoint() . '/' . $bucket_name . '/' . $file_name;
	}

	/**
	 * Return the endpoint of the emulator without trailing slash.
	 *
	 * @return string
	 */
	private function get_endpoint(): string {
		return rtrim( (string) getenv( 'EFMLGCS_INTEGRATION_ENDPOINT' ), '/' );
	}

	/**
	 * Return a client for the emulator, independent of the plugin.
	 *
	 * @return StorageClient
	 */
	private function get_storage(): StorageClient {
		return new StorageClient(
			array(
				'apiEndpoint'        => $this->get_endpoint(),
				'credentialsFetcher' => new InsecureCredentials(),
				'projectId'          => 'my-project',
			)
		);
	}

	/**
	 * Return the bucket of the actual test, independent of the plugin.
	 *
	 * @return Bucket
	 */
	private function get_bucket(): Bucket {
		return $this->get_storage()->bucket( $this->bucket );
	}

	/**
	 * Return the credentials like the main plugin uses them for the export.
	 *
	 * @return array<string,mixed>
	 */
	private function get_credentials(): array {
		return array(
			'fields'    => $this->get_fields(),
			'directory' => \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance()->get_directory(),
		);
	}

	/**
	 * Test the directory listing.
	 *
	 * @return void
	 */
	public function test_directory_listing(): void {
		$google_cloud_storage = $this->connect();
		$this->get_bucket()->upload(
			(string) file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			array(
				'name'     => 'dir/example.jpg',
				'metadata' => array( 'contentType' => 'image/jpeg' ),
			)
		);

		$listing = $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() );

		$this->assertEmpty( $this->get_error_messages() );
		$this->assertArrayHasKey( 'dir/', $listing );
		$this->assertEquals( 'https://console.cloud.google.com/storage/browser/' . $this->bucket . '/dir/example.jpg', $listing['dir/']['files'][0]['file'] );
		$this->assertEquals( 'image/jpeg', $listing['dir/']['files'][0]['mime-type'] );
	}

	/**
	 * Test the complete connection test.
	 *
	 * @return void
	 */
	public function test_connection_test(): void {
		$google_cloud_storage = $this->connect();

		foreach ( $google_cloud_storage->run_connection_test() as $step ) {
			$this->assertTrue( $step['success'], $step['title'] . ': ' . $step['message'] );
		}

		// the test file has been deleted.
		$this->assertEmpty( iterator_to_array( $this->get_bucket()->objects(), false ) );
	}

	/**
	 * Test the export, the import and the deletion of a file.
	 *
	 * @return void
	 */
	public function test_export_import_and_delete(): void {
		$this->do_login();
		$credentials   = $this->get_credentials();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$export        = Export::get_instance();

		// test 1: the export.
		$url = $export->export_file( $attachment_id, $credentials['directory'], $credentials );
		$this->assertEquals( $this->get_endpoint() . '/' . $this->bucket . '/' . $file_name, $url );
		$this->assertTrue( $this->get_bucket()->object( $file_name )->exists() );
		$this->assertEquals( 'image/jpeg', $this->get_bucket()->object( $file_name )->info()['contentType'] );
		$this->assertEquals( 200, wp_remote_retrieve_response_code( wp_remote_get( (string) $url ) ) );

		// test 2: the import of the exported file.
		$protocol = new Protocol( 'https://console.cloud.google.com/storage/browser/' . $this->bucket . '/' . $file_name );
		$protocol->set_fields( $this->get_fields() );
		$results = $protocol->get_url_infos();
		$this->assertCount( 1, $results );
		$this->assertFalse( $results[0]['local'] );
		$this->assertEquals( $url, $results[0]['url'] );
		$this->assertEquals( 'image/jpeg', $results[0]['mime-type'] );
		$this->assertFileExists( $results[0]['tmp-file'] );
		$this->assertEquals( filesize( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), filesize( $results[0]['tmp-file'] ) );
		wp_delete_file( $results[0]['tmp-file'] );

		// test 3: the deletion.
		$this->assertTrue( $export->delete_exported_file( (string) $url, $credentials, $attachment_id ) );
		$this->assertFalse( $this->get_bucket()->object( $file_name )->exists() );
	}

	/**
	 * Test the export into a folder.
	 *
	 * @return void
	 */
	public function test_export_into_folder(): void {
		$this->do_login();
		$credentials   = $this->get_credentials();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );

		$url = Export::get_instance()->export_file( $attachment_id, $credentials['directory'] . '2026/11/' . $file_name, $credentials );

		$this->assertEquals( $this->get_endpoint() . '/' . $this->bucket . '/2026/11/' . $file_name, $url );
		$this->assertTrue( $this->get_bucket()->object( '2026/11/' . $file_name )->exists() );
		$this->assertFalse( $this->get_bucket()->object( $file_name )->exists() );
		$this->assertTrue( Export::get_instance()->delete_exported_file( (string) $url, $credentials, $attachment_id ) );
	}

	/**
	 * Test the export of a large file, which is uploaded as resumable upload in several requests.
	 *
	 * @return void
	 */
	public function test_export_of_large_file(): void {
		$this->do_login();
		$credentials = $this->get_credentials();

		// create an attachment with a file larger than 5 MB.
		$file   = get_temp_dir() . 'efmlgcs-large-' . wp_generate_password( 6, false ) . '.pdf';
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		for ( $i = 0; $i < 6 * 1024; $i++ ) {
			fwrite( $handle, random_bytes( 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$attachment_id = (int) self::factory()->attachment->create_upload_object( $file );
		wp_delete_file( $file );
		$local_file = (string) get_attached_file( $attachment_id, true );

		$url = Export::get_instance()->export_file( $attachment_id, $credentials['directory'], $credentials );

		$this->assertIsString( $url );
		$tmp_file = wp_tempnam();
		$this->get_bucket()->object( basename( $local_file ) )->downloadToFile( $tmp_file );
		$this->assertEquals( md5_file( $local_file ), md5_file( $tmp_file ) );
		wp_delete_file( $tmp_file );
	}

	/**
	 * Test the import of all files of the bucket.
	 *
	 * @return void
	 */
	public function test_import_of_bucket(): void {
		$this->do_login();
		foreach ( array( 'one.jpg', 'dir/two.jpg' ) as $name ) {
			$this->get_bucket()->upload(
				(string) file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				array( 'name' => $name )
			);
		}

		$protocol = new Protocol( 'https://www.googleapis.com/storage/v1/b/' . $this->bucket . '/' );
		$protocol->set_fields( $this->get_fields() );
		$results = $protocol->get_url_infos();

		$this->assertCount( 2, $results );
		foreach ( $results as $result ) {
			$this->assertEquals( filesize( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), filesize( $result['tmp-file'] ) );
			wp_delete_file( $result['tmp-file'] );
		}
	}

	/**
	 * Test the import of a bucket with many files via import run: all files are imported, also in the background,
	 * and a second import skips them.
	 *
	 * The count of files could be changed with the environment variable EFMLGCS_INTEGRATION_IMPORT_COUNT.
	 *
	 * @return void
	 */
	public function test_import_of_bucket_with_many_files(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'efml_import_runs' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'eml_queue' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// use the protocol, like for users with the capability.
		add_filter( 'efml_protocols', array( \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance(), 'add_protocol' ) );

		// upload the files.
		$count = getenv( 'EFMLGCS_INTEGRATION_IMPORT_COUNT' ) ? absint( getenv( 'EFMLGCS_INTEGRATION_IMPORT_COUNT' ) ) : 1100;
		$body  = (string) file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->get_bucket()->upload(
				$body,
				array(
					'name'     => 'many/file-' . $i . '.jpg',
					'metadata' => array( 'contentType' => 'image/jpeg' ),
				)
			);
		}
		$before = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `post_type` = %s', array( $wpdb->posts, 'attachment' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// import the bucket and process the run in the background until it is finished.
		$this->do_login();
		$url    = 'https://console.cloud.google.com/storage/browser/' . $this->bucket . '/';
		$import = \ExternalFilesInMediaLibrary\ExternalFiles\Import::get_instance();
		$import->set_fields( $this->get_fields() );
		$this->assertTrue( $import->add_url( $url ) );
		$runs = \ExternalFilesInMediaLibrary\ExternalFiles\Import_Runs::get_instance();
		for ( $i = 0; $i < 100 && $runs->get_progress()['runs'] > 0; $i++ ) {
			$runs->process();
		}

		$this->assertEquals( 0, $runs->get_progress()['runs'] );
		$this->assertEquals( $before + $count, absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `post_type` = %s', array( $wpdb->posts, 'attachment' ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\File::class, \ExternalFilesInMediaLibrary\ExternalFiles\Files::get_instance()->get_file_by_url( $this->get_endpoint() . '/' . $this->bucket . '/many/file-' . $count . '.jpg' ) );

		// a second import does not import them again.
		$this->assertFalse( $import->add_url( $url ) );
		$import->set_fields( array() );
		remove_filter( 'efml_protocols', array( \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance(), 'add_protocol' ) );
	}

	/**
	 * Test a not existing bucket: the listing returns an error.
	 *
	 * @return void
	 */
	public function test_not_existing_bucket(): void {
		$this->do_login();
		$this->set_mode( 'manually' );
		$google_cloud_storage = \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance();
		$google_cloud_storage->set_fields( $this->get_fields( array( 'bucket' => 'efml-not-existing-bucket' ) ) );

		$this->assertEmpty( $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() ) );
		$this->assertNotEmpty( $this->get_error_messages() );
	}
}
