<?php
/**
 * Tests for class ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit\GoogleCloudStorage;

use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;

/**
 * Object to test functions in class ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export.
 */
class Export extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * The URL for uploads.
	 *
	 * @var string
	 */
	private string $upload_url = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o';

	/**
	 * The target used in the tests.
	 *
	 * @var string
	 */
	private string $target = 'https://console.cloud.google.com/storage/browser/my-bucket/';

	/**
	 * Return the export object.
	 *
	 * @return \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export
	 */
	private function get_export(): \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export {
		return \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export::get_instance();
	}

	/**
	 * Return the credentials like the main plugin uses them for the export.
	 *
	 * @return array<string,mixed>
	 */
	private function get_credentials(): array {
		return array( 'fields' => $this->get_fields() );
	}

	/**
	 * Test if the returned object is always the same.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$export = $this->get_export();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\ExternalFiles\Export_Base::class, $export );
		$this->assertSame( $export, \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export::get_instance() );
	}

	/**
	 * Test the export of a file into a public bucket.
	 *
	 * @return void
	 */
	public function test_export_file(): void {
		$this->do_login();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_bucket();
		$this->add_iam_policy( true );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( $file_name ), array( 'uploadType' => 'multipart' ) );

		$url = $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() );

		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/' . $file_name, $url );
		$this->assertEquals( $file_name, get_post_meta( $attachment_id, 'efml_google_cloud_storage', true ) );

		// check the upload.
		$upload = $this->get_google_requests()[2];
		$this->assertEquals( $this->upload_url, $upload['url'] );
		$this->assertStringContainsString( file_get_contents( UNIT_TESTS_DATA_PLUGIN_DIR . 'example.jpg' ), $upload['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Test the export into a bucket, which is not public.
	 *
	 * @return void
	 */
	public function test_export_file_into_private_bucket(): void {
		$attachment_id = $this->create_local_attachment();
		$this->add_bucket();
		$this->add_iam_policy( false );

		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() ) );
		$this->assertStringContainsString( 'not to be public', $this->get_log_entries( $this->target )[0]['log'] );
	}

	/**
	 * Test the export of a file, if Google Cloud Storage returns an error.
	 *
	 * @return void
	 */
	public function test_export_file_with_error(): void {
		$attachment_id = $this->create_local_attachment();
		$this->add_bucket();
		$this->add_iam_policy( true );
		$this->add_google_response( 'POST', $this->upload_url, 403, $this->get_error_body( 403 ), array( 'uploadType' => 'multipart' ) );

		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() ) );
		$this->assertNotEmpty( $this->get_log_entries( $this->target ) );
		$this->assertEmpty( get_post_meta( $attachment_id, 'efml_google_cloud_storage', true ) );
	}

	/**
	 * Test the export, if the bucket does not exist or could not be loaded.
	 *
	 * @return void
	 */
	public function test_export_file_with_bucket_error(): void {
		$attachment_id = $this->create_local_attachment();

		// test 1: the bucket does not exist.
		$this->add_bucket( 404 );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() ) );

		// test 2: the access to the bucket is not allowed.
		$this->add_bucket( 403 );
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() ) );
		$this->assertStringContainsString( 'could not be loaded', $this->get_log_entries( $this->target )[0]['log'] );

		// test 3: without credentials.
		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->target, array() ) );
	}

	/**
	 * Test the export of an attachment with a not existing file.
	 *
	 * @return void
	 */
	public function test_export_file_without_file(): void {
		$attachment_id = $this->create_local_attachment();
		update_attached_file( $attachment_id, '/not/existing/example.jpg' );
		$this->add_bucket();
		$this->add_iam_policy( true );

		$this->assertFalse( $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() ) );
		$this->assertStringContainsString( 'does not exist', $this->get_log_entries( $this->target )[0]['log'] );
	}

	/**
	 * Test the deletion of an exported file.
	 *
	 * @return void
	 */
	public function test_delete_exported_file(): void {
		$attachment_id = $this->create_local_attachment();
		update_post_meta( $attachment_id, 'efml_google_cloud_storage', 'example.jpg' );

		// the file exists before the deletion, but not after it.
		$this->add_google_response( 'GET', '/o/example.jpg', 404, $this->get_error_body( 404 ) );
		$this->add_google_response( 'GET', '/o/example.jpg', 200, $this->get_object_data( 'example.jpg' ), array(), array(), 1 );
		$this->add_google_response( 'DELETE', '/o/example.jpg', 204 );

		$this->assertTrue( $this->get_export()->delete_exported_file( 'https://storage.googleapis.com/my-bucket/example.jpg', $this->get_credentials(), $attachment_id ) );
		$this->assertContains( 'DELETE', wp_list_pluck( $this->get_google_requests(), 'method' ) );
	}

	/**
	 * Test the deletion of an exported file with errors.
	 *
	 * @return void
	 */
	public function test_delete_exported_file_with_error(): void {
		$url           = 'https://storage.googleapis.com/my-bucket/example.jpg';
		$attachment_id = $this->create_local_attachment();

		// test 1: without file name.
		$this->assertFalse( $this->get_export()->delete_exported_file( $url, $this->get_credentials(), $attachment_id ) );
		$this->assertEmpty( $this->get_google_requests() );

		// test 2: the file does not exist.
		update_post_meta( $attachment_id, 'efml_google_cloud_storage', 'example.jpg' );
		$this->add_google_response( 'GET', '/o/example.jpg', 404, $this->get_error_body( 404 ) );
		$this->assertFalse( $this->get_export()->delete_exported_file( $url, $this->get_credentials(), $attachment_id ) );

		// test 3: the deletion is not allowed.
		$this->add_google_response( 'GET', '/o/example.jpg', 200, $this->get_object_data( 'example.jpg' ) );
		$this->add_google_response( 'DELETE', '/o/example.jpg', 403, $this->get_error_body( 403 ) );
		$this->assertFalse( $this->get_export()->delete_exported_file( $url, $this->get_credentials(), $attachment_id ) );
		$this->assertNotEmpty( $this->get_log_entries( $url ) );

		// test 4: without credentials.
		$this->assertFalse( $this->get_export()->delete_exported_file( $url, array(), $attachment_id ) );
	}

	/**
	 * Test the export of a file into a folder below the directory of the external source.
	 *
	 * @return void
	 */
	public function test_export_file_into_folder(): void {
		$this->do_login();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_bucket();
		$this->add_iam_policy( true );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( '2026/11/' . $file_name ), array( 'uploadType' => 'multipart' ) );
		$this->assertTrue( $this->get_export()->supports_folders() );

		$credentials              = $this->get_credentials();
		$credentials['directory'] = $this->target;
		$url                      = $this->get_export()->export_file( $attachment_id, $this->target . '2026/11/' . $file_name, $credentials );

		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/2026/11/' . $file_name, $url );
		$this->assertEquals( '2026/11/' . $file_name, get_post_meta( $attachment_id, 'efml_google_cloud_storage', true ) );

		// the name of the uploaded object contains the folder.
		$this->assertStringContainsString( '"name":"2026\\/11\\/' . $file_name . '"', $this->get_google_requests()[2]['body'] );
	}

	/**
	 * Test if relative folders in the target are never used for the name.
	 *
	 * @return void
	 */
	public function test_get_folder_from_target(): void {
		$credentials = array( 'directory' => $this->target );
		$this->assertEquals( '2026/11/', $this->call_method( $this->get_export(), 'get_folder_from_target', array( $this->target . '2026/11/file.jpg', $credentials ) ) );
		$this->assertEquals( '', $this->call_method( $this->get_export(), 'get_folder_from_target', array( $this->target . 'file.jpg', $credentials ) ) );
		$this->assertEquals( '', $this->call_method( $this->get_export(), 'get_folder_from_target', array( $this->target . '../2026/file.jpg', $credentials ) ) );
		$this->assertEquals( '', $this->call_method( $this->get_export(), 'get_folder_from_target', array( $this->target . '2026/file.jpg', array() ) ) );
	}

	/**
	 * Test the export of a private file into a bucket, which is not public.
	 *
	 * @return void
	 */
	public function test_export_private_file_into_private_bucket(): void {
		$this->do_login();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_bucket();
		$this->add_iam_policy( false );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( $file_name ), array( 'uploadType' => 'multipart' ) );

		// the service supports private files.
		$this->assertTrue( $this->get_export()->supports_private_files() );

		// export the file as private file.
		$credentials            = $this->get_credentials();
		$credentials['private'] = true;
		$url                    = $this->get_export()->export_file( $attachment_id, $this->target, $credentials );

		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/' . $file_name, $url );
		$this->assertEquals( $file_name, get_post_meta( $attachment_id, 'efml_google_cloud_storage', true ) );
	}

	/**
	 * Test the export of a large file, which is uploaded by the SDK as resumable upload from the file stream.
	 *
	 * @return void
	 */
	public function test_export_large_file(): void {
		$this->do_login();

		// create a file with more than 5 MB.
		$file   = trailingslashit( get_temp_dir() ) . 'efmlgcs-large-export.pdf';
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $handle, '%PDF-1.4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		for ( $i = 0; $i < 6 * 1024; $i++ ) {
			fwrite( $handle, random_bytes( 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$attachment_id = (int) self::factory()->attachment->create_upload_object( $file );
		wp_delete_file( $file );
		$local_file = (string) get_attached_file( $attachment_id, true );
		$file_name  = basename( $local_file );

		// the SDK starts a resumable upload and sends the file to the returned location.
		$this->add_bucket();
		$this->add_iam_policy( true );
		$this->add_google_response( 'POST', $this->upload_url, 200, '', array( 'uploadType' => 'resumable' ), array( 'Location' => $this->upload_url . '?uploadType=resumable&upload_id=efmlgcs-test' ) );
		$this->add_google_response( 'PUT', $this->upload_url, 200, $this->get_object_data( $file_name ), array( 'upload_id' => 'efmlgcs-test' ) );

		$url = $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() );

		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/' . $file_name, $url );

		// the complete file has been sent.
		$uploads = array_values( array_filter( $this->get_google_requests(), static fn( array $request ) => 'PUT' === $request['method'] ) );
		$this->assertCount( 1, $uploads );
		$this->assertEquals( md5_file( $local_file ), md5( $uploads[0]['body'] ) );
	}

	/**
	 * Test the export of a file, which could not be opened.
	 *
	 * @return void
	 */
	public function test_export_file_not_readable(): void {
		$attachment_id = $this->create_local_attachment();
		$this->add_bucket();
		$this->add_iam_policy( true );

		// make the file a directory, which exists but could not be opened as file.
		$file = (string) get_attached_file( $attachment_id, true );
		wp_delete_file( $file );
		mkdir( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = @$this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		rmdir( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// the export fails without a fatal error.
		$this->assertFalse( $result );
		$this->assertEquals( 'error', $this->get_log_entries( $this->target )[0]['state'] );
	}

	/**
	 * Test the options for the upload: the content type of the attachment and further metadata via hook.
	 *
	 * @return void
	 */
	public function test_export_file_with_upload_options(): void {
		$this->do_login();
		$attachment_id = $this->create_local_attachment();
		$file_name     = basename( (string) get_attached_file( $attachment_id, true ) );
		$this->add_bucket();
		$this->add_iam_policy( true );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( $file_name ), array( 'uploadType' => 'multipart' ) );

		$callback = static function ( array $options, int $id ) use ( $attachment_id ): array {
			if ( $id === $attachment_id ) {
				$options['metadata']['cacheControl'] = 'public, max-age=31536000';
			}
			return $options;
		};
		add_filter( 'efmlgcs_upload_options', $callback, 10, 2 );
		$url = $this->get_export()->export_file( $attachment_id, $this->target, $this->get_credentials() );
		remove_filter( 'efmlgcs_upload_options', $callback );

		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/' . $file_name, $url );

		// the metadata is part of the multipart upload.
		$upload = $this->get_google_requests()[2];
		$this->assertStringContainsString( '"contentType":"image\/jpeg"', $upload['body'] );
		$this->assertStringContainsString( '"cacheControl":"public, max-age=31536000"', $upload['body'] );
		$this->assertStringContainsString( '"name":"' . $file_name . '"', $upload['body'] );
	}
}
