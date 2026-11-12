<?php
/**
 * Tests for the complete connection test of Google Cloud Storage.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit\GoogleCloudStorage;

use ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage;
use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;

/**
 * Object to test the connection test of Google Cloud Storage.
 */
class ConnectionTest extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * The URL for uploads.
	 *
	 * @var string
	 */
	private string $upload_url = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o';

	/**
	 * The name of the test file.
	 *
	 * @var string
	 */
	private string $file_name = 'efml-connection-test.txt';

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'efml_connection_test_file_name', array( $this, 'get_file_name' ) );
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'efml_connection_test_file_name', array( $this, 'get_file_name' ) );
		parent::tear_down();
	}

	/**
	 * Return the name of the test file.
	 *
	 * @return string
	 */
	public function get_file_name(): string {
		return $this->file_name;
	}

	/**
	 * Run the connection test and return the steps.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function run_test(): array {
		$this->connect();
		return GoogleCloudStorage::get_instance()->run_connection_test();
	}

	/**
	 * Test the connection test with a public bucket.
	 *
	 * @return void
	 */
	public function test_connection_test(): void {
		$this->assertTrue( GoogleCloudStorage::get_instance()->supports_connection_test() );
		$this->add_object_list( array( 'example.jpg' ) );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( $this->file_name ), array( 'uploadType' => 'multipart' ) );
		$this->add_iam_policy( true );
		$this->add_google_response( 'DELETE', '/o/' . $this->file_name, 204 );

		$steps = $this->run_test();

		foreach ( $steps as $step ) {
			$this->assertTrue( $step['success'], $step['title'] . ': ' . $step['message'] );
		}
		$this->assertStringContainsString( 'https://storage.googleapis.com/my-bucket/' . $this->file_name, $steps[1]['message'] );
		$this->assertEquals( array( 'GET', 'POST', 'GET', 'DELETE' ), wp_list_pluck( $this->get_google_requests(), 'method' ) );
	}

	/**
	 * Test the connection test with a bucket, which is not public.
	 *
	 * @return void
	 */
	public function test_connection_test_with_private_bucket(): void {
		$this->add_object_list( array() );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( $this->file_name ), array( 'uploadType' => 'multipart' ) );
		$this->add_iam_policy( false );
		$this->add_google_response( 'DELETE', '/o/' . $this->file_name, 204 );

		$steps = $this->run_test();

		$this->assertFalse( $steps[2]['success'] );
		$this->assertStringContainsString( 'Save files privately', $steps[2]['message'] );
		$this->assertTrue( $steps[3]['success'] );
	}

	/**
	 * Test the connection test with errors.
	 *
	 * @return void
	 */
	public function test_connection_test_with_errors(): void {
		// the bucket could not be listed.
		$this->add_google_response( 'GET', '/o', 403, $this->get_error_body( 403 ) );
		$steps = $this->run_test();
		$this->assertFalse( $steps[0]['success'] );
		$this->assertStringContainsString( 'could not be listed', $steps[0]['message'] );
		$this->assertTrue( $steps[1]['skipped'] );
	}

	/**
	 * Test the connection test with an error during the deletion.
	 *
	 * @return void
	 */
	public function test_connection_test_with_error_during_deletion(): void {
		$this->add_object_list( array() );
		$this->add_google_response( 'POST', $this->upload_url, 200, $this->get_object_data( $this->file_name ), array( 'uploadType' => 'multipart' ) );
		$this->add_iam_policy( true );
		$this->add_google_response( 'DELETE', '/o/' . $this->file_name, 403, $this->get_error_body( 403 ) );

		$steps = $this->run_test();

		$this->assertFalse( $steps[3]['success'] );
		$this->assertStringContainsString( 'manually', $steps[3]['message'] );
	}

	/**
	 * Test the global actions offer the connection test.
	 *
	 * @return void
	 */
	public function test_global_actions(): void {
		$actions = $this->call_method( GoogleCloudStorage::get_instance(), 'get_global_actions' );
		$this->assertContains( 'Run connection test', array_column( $actions, 'label' ) );
	}
}
