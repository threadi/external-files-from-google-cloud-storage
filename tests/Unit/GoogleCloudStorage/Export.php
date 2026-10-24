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
}
