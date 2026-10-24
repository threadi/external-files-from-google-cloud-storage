<?php
/**
 * Tests for class ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests\Unit;

use easyDirectoryListingForWordPress\Crypt;
use ExternalFilesFromGoogleCloudStorage\Tests\externalFilesFromGoogleCloudStorageTests;
use Google\Cloud\Storage\StorageClient;

/**
 * Object to test functions in class ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage.
 */
class GoogleCloudStorage extends externalFilesFromGoogleCloudStorageTests {
	/**
	 * Return the GoogleCloudStorage object.
	 *
	 * @return \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage
	 */
	private function get_google_cloud_storage(): \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage {
		return \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance();
	}

	/**
	 * Save the user settings for the actual user like the main plugin does it.
	 *
	 * @param array<string,string> $settings The settings.
	 *
	 * @return void
	 */
	private function save_user_settings( array $settings ): void {
		foreach ( $settings as $name => $value ) {
			update_user_meta( get_current_user_id(), 'efml_' . $name, Crypt::get_instance()->encrypt( $value ) );
		}
	}

	/**
	 * Test if the returned object is always the same and is a service of the main plugin.
	 *
	 * @return void
	 */
	public function test_get_instance(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Services\Service_Base::class, $google_cloud_storage );
		$this->assertInstanceOf( \ExternalFilesInMediaLibrary\Services\Service::class, $google_cloud_storage );
		$this->assertSame( $google_cloud_storage, \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage::get_instance() );
		$this->assertEquals( 'google-cloud-storage', $google_cloud_storage->get_name() );
		$this->assertEquals( 'Google Cloud Storage', $google_cloud_storage->get_label() );
		$this->assertEquals( 'https://console.cloud.google.com/storage/browser/', $google_cloud_storage->get_url_mark() );
		$this->assertEquals( array( 'administrator', 'editor' ), $google_cloud_storage->get_default_roles() );
	}

	/**
	 * Test if the hooks of the service are registered.
	 *
	 * @return void
	 */
	public function test_init(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		$this->assertIsInt( has_filter( 'efml_protocols', array( $google_cloud_storage, 'add_protocol' ) ) );

		// the filter must use the name of the hook the plugin is using.
		$this->assertIsInt( has_filter( 'efmlgcs_service_googlecloudstorage_hide_file', array( $google_cloud_storage, 'prevent_not_allowed_files' ) ) );
	}

	/**
	 * Test if the protocol is added as first.
	 *
	 * @return void
	 */
	public function test_add_protocol(): void {
		$protocols = $this->get_google_cloud_storage()->add_protocol( array( 'Example\Protocol' ) );
		$this->assertEquals( array( 'ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Protocol', 'Example\Protocol' ), $protocols );
		$this->assertTrue( class_exists( $protocols[0] ) );
	}

	/**
	 * Test the bucket name in the different modes.
	 *
	 * @return void
	 */
	public function test_get_bucket_name(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		$this->do_login();

		// test 1: manual mode without fields.
		$this->set_mode( 'manually' );
		$this->assertEquals( '', $google_cloud_storage->get_bucket_name() );

		// test 2: global mode.
		$this->set_mode( 'global' );
		update_option( 'eml_google_cloud_storage_bucket', 'global-bucket' );
		$this->assertEquals( 'global-bucket', $google_cloud_storage->get_bucket_name() );

		// test 3: user mode.
		$this->set_mode( 'user' );
		$this->save_user_settings( array( 'google_cloud_storage_bucket' => 'user-bucket' ) );
		$this->assertEquals( 'user-bucket', $google_cloud_storage->get_bucket_name() );

		// test 4: the fields are used first.
		$google_cloud_storage->set_fields( $this->get_fields() );
		$this->assertEquals( 'my-bucket', $google_cloud_storage->get_bucket_name() );
	}

	/**
	 * Test the directory.
	 *
	 * @return void
	 */
	public function test_get_directory(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		$this->set_mode( 'manually' );

		// test 1: without bucket.
		$this->assertEquals( 'https://www.googleapis.com/storage/', $google_cloud_storage->get_directory() );

		// test 2: with bucket.
		$google_cloud_storage->set_fields( $this->get_fields() );
		$this->assertEquals( 'https://www.googleapis.com/storage/v1/b/my-bucket/', $google_cloud_storage->get_directory() );

		// test 3: with directory.
		$google_cloud_storage->set_directory( 'https://www.googleapis.com/storage/v1/b/my-bucket/folder/' );
		$this->assertEquals( 'https://www.googleapis.com/storage/v1/b/my-bucket/folder/', $google_cloud_storage->get_directory() );
	}

	/**
	 * Test the validation of the authentication JSON.
	 *
	 * @return void
	 */
	public function test_validate_json(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();

		$this->assertEquals( '', $google_cloud_storage->validate_json( null ) );
		$this->assertEquals( '', $google_cloud_storage->validate_json( '' ) );
		$this->assertEquals( '', $google_cloud_storage->validate_json( 'no json' ) );
		$this->assertEquals( '', $google_cloud_storage->validate_json( '{"project_id":"my-project"}' ) );
		$this->assertEquals( self::$authentication_json, $google_cloud_storage->validate_json( self::$authentication_json ) );

		// an encrypted value (the saved value) is also valid.
		$this->assertEquals( self::$authentication_json, $google_cloud_storage->validate_json( $google_cloud_storage->encrypt_value( self::$authentication_json ) ) );
	}

	/**
	 * Test the authentication JSON in the different modes.
	 *
	 * The value is saved encrypted in the global mode. It is decrypted by the settings object if the setting is
	 * registered, which is only the case for users with the capability for this service.
	 *
	 * @return void
	 */
	public function test_get_authentication_json(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		$this->do_login();

		// test 1: manual mode.
		$this->set_mode( 'manually' );
		$this->assertEquals( '', $google_cloud_storage->get_fields()['authentication_json']['value'] );

		// test 2: global mode, if the setting is not registered.
		$this->reset_google_cloud_storage();
		$this->set_mode( 'global' );
		update_option( 'eml_google_cloud_storage_json', $google_cloud_storage->encrypt_value( self::$authentication_json ) );
		$this->assertEquals( self::$authentication_json, $google_cloud_storage->get_fields()['authentication_json']['value'] );

		// test 3: global mode, if the setting is registered.
		$this->reset_google_cloud_storage();
		add_filter( 'option_eml_google_cloud_storage_json', array( $google_cloud_storage, 'decrypt_value' ) );
		$this->assertEquals( self::$authentication_json, $google_cloud_storage->get_fields()['authentication_json']['value'] );
		remove_filter( 'option_eml_google_cloud_storage_json', array( $google_cloud_storage, 'decrypt_value' ) );

		// test 4: user mode.
		$this->reset_google_cloud_storage();
		$this->set_mode( 'user' );
		$this->save_user_settings( array( 'google_cloud_storage_json' => self::$authentication_json ) );
		$this->assertEquals( self::$authentication_json, $google_cloud_storage->get_fields()['authentication_json']['value'] );
	}

	/**
	 * Test the fields.
	 *
	 * @return void
	 */
	public function test_get_fields(): void {
		$this->do_login();
		$this->set_mode( 'manually' );
		$fields = $this->get_google_cloud_storage()->get_fields();
		$this->assertEquals( array( 'authentication_json', 'bucket' ), array_keys( $fields ) );
		$this->assertFalse( $fields['authentication_json']['readonly'] );
		$this->assertTrue( $fields['authentication_json']['credential'] );
		$this->assertFalse( $fields['bucket']['readonly'] );
	}

	/**
	 * Test the storage object.
	 *
	 * @return void
	 */
	public function test_get_storage_object(): void {
		$google_cloud_storage = $this->connect();
		$credentials          = getenv( 'GOOGLE_APPLICATION_CREDENTIALS' );

		$this->assertInstanceOf( StorageClient::class, $google_cloud_storage->get_storage_object() );

		// the credentials are not saved in a file, which other applications could use.
		$this->assertEquals( $credentials, getenv( 'GOOGLE_APPLICATION_CREDENTIALS' ) );

		// the credentials of the service account are used.
		$this->add_bucket();
		$this->assertTrue( $google_cloud_storage->get_storage_object()->bucket( 'my-bucket' )->exists() );
		$authorization = $this->get_google_requests()[0]['headers']['authorization'][0];
		$this->assertStringStartsWith( 'Bearer ', $authorization );
		$jwt = json_decode( (string) base64_decode( strtr( explode( '.', $authorization )[1], '-_', '+/' ) ), true );
		$this->assertEquals( 'service@my-project.iam.gserviceaccount.com', $jwt['iss'] );
	}

	/**
	 * Test the storage object without valid authentication JSON.
	 *
	 * @return void
	 */
	public function test_get_storage_object_without_valid_json(): void {
		$google_cloud_storage = $this->connect();

		// test 1: empty JSON.
		$google_cloud_storage->set_fields( $this->get_fields( array( 'authentication_json' => '' ) ) );
		$this->assertFalse( $google_cloud_storage->get_storage_object() );

		// test 2: invalid JSON.
		$google_cloud_storage->set_fields( $this->get_fields( array( 'authentication_json' => 'no json' ) ) );
		$this->assertFalse( $google_cloud_storage->get_storage_object() );
		$this->assertEmpty( $this->get_google_requests() );
	}

	/**
	 * Test the directory listing.
	 *
	 * @return void
	 */
	public function test_get_directory_listing(): void {
		$google_cloud_storage = $this->connect();
		$this->add_bucket();
		$this->add_object_list( array( 'example.jpg', 'folder/', 'folder/photo.jpg', 'folder/sub/deep.png', 'program.unknownextension' ) );

		$listing = $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() );

		$this->assertTrue( $listing['completed'] );

		// the main directory contains the file without directory.
		$main = $listing['https://www.googleapis.com/storage/v1/b/my-bucket/'];
		$this->assertCount( 1, $main['files'] );
		$this->assertEquals( 'example.jpg', $main['files'][0]['title'] );
		$this->assertEquals( 'https://console.cloud.google.com/storage/browser/my-bucket/example.jpg', $main['files'][0]['file'] );
		$this->assertEquals( 'image/jpeg', $main['files'][0]['mime-type'] );
		$this->assertEquals( 7, $main['files'][0]['filesize'] );

		// the directories with their files.
		$this->assertEquals( 'folder', $listing['folder/']['title'] );
		$this->assertCount( 1, $listing['folder/']['files'] );
		$this->assertEquals( 'https://console.cloud.google.com/storage/browser/my-bucket/folder/photo.jpg', $listing['folder/']['files'][0]['file'] );
		$this->assertArrayHasKey( 'folder/sub/', $listing['folder/']['dirs'] );
		$this->assertCount( 1, $listing['folder/sub/']['files'] );
		$this->assertEquals( 'deep.png', $listing['folder/sub/']['files'][0]['title'] );
	}

	/**
	 * Test if files are hidden in the directory listing, which are not allowed.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_hides_not_allowed_files(): void {
		$google_cloud_storage = $this->connect();
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 1 );
		update_option( 'eml_allowed_mime_types', array( 'image/jpeg' ) );
		$this->add_bucket();
		$this->add_object_list( array( 'example.jpg', 'image.png' ) );

		$listing = $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() );

		$files = $listing['https://www.googleapis.com/storage/v1/b/my-bucket/']['files'];
		$this->assertCount( 1, $files );
		$this->assertEquals( 'example.jpg', $files[0]['title'] );
	}

	/**
	 * Test the directory listing, if the bucket does not exist or could not be loaded.
	 *
	 * @return void
	 */
	public function test_get_directory_listing_with_error(): void {
		$google_cloud_storage = $this->connect();

		// test 1: the bucket does not exist.
		$this->add_bucket( 404 );
		$this->assertEquals( array(), $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() ) );
		$this->assertContains( 'Given bucket does not exist.', $this->get_error_messages() );

		// test 2: the access to the bucket is not allowed.
		$this->reset_google_cloud_storage();
		$google_cloud_storage->set_fields( $this->get_fields() );
		$this->add_bucket( 403 );
		$this->assertEquals( array(), $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() ) );
		$this->assertStringContainsString( 'could not be loaded', implode( ' ', $this->get_error_messages() ) );

		// test 3: the list of files could not be loaded.
		$this->reset_google_cloud_storage();
		$google_cloud_storage->set_fields( $this->get_fields() );
		$this->add_bucket();
		$this->add_google_response( 'GET', '/o', 403, $this->get_error_body( 403 ) );
		$this->assertEquals( array(), $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() ) );
		$this->assertStringContainsString( 'could not be loaded', implode( ' ', $this->get_error_messages() ) );

		// test 4: without valid credentials.
		$this->reset_google_cloud_storage();
		$google_cloud_storage->set_fields( $this->get_fields( array( 'authentication_json' => '' ) ) );
		$this->assertEquals( array(), $google_cloud_storage->get_directory_listing( $google_cloud_storage->get_directory() ) );
		$this->assertStringContainsString( 'could not be loaded', implode( ' ', $this->get_error_messages() ) );
	}

	/**
	 * Test the filter for not allowed files.
	 *
	 * @return void
	 */
	public function test_prevent_not_allowed_files(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		update_option( 'eml_allowed_mime_types', array( 'image/jpeg' ) );

		// test 1: the setting is disabled.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 0 );
		$this->assertFalse( $google_cloud_storage->prevent_not_allowed_files( false, array( 'name' => 'document.pdf' ), '' ) );

		// test 2: the setting is enabled.
		update_option( 'eml_directory_listing_hide_not_supported_file_types', 1 );
		$this->assertTrue( $google_cloud_storage->prevent_not_allowed_files( false, array( 'name' => 'document.pdf' ), '' ) );
		$this->assertFalse( $google_cloud_storage->prevent_not_allowed_files( false, array( 'name' => 'folder/example.jpg' ), '' ) );
	}

	/**
	 * Test the check whether a bucket is public.
	 *
	 * @return void
	 */
	public function test_is_bucket_public(): void {
		$google_cloud_storage = $this->connect();
		$storage              = $google_cloud_storage->get_storage_object();

		// test 1: not public.
		$this->add_iam_policy( false );
		$this->assertFalse( $google_cloud_storage->is_bucket_public( $storage->bucket( 'my-bucket' ) ) );

		// test 2: public.
		$this->add_iam_policy( true );
		$this->assertTrue( $google_cloud_storage->is_bucket_public( $storage->bucket( 'my-bucket' ) ) );

		// test 3: error.
		$this->add_google_response( 'GET', '/iam', 403, $this->get_error_body( 403 ) );
		$this->assertFalse( $google_cloud_storage->is_bucket_public( $storage->bucket( 'my-bucket' ) ) );
		$this->assertStringContainsString( 'IAM', $this->get_log_entries()[0]['log'] );
	}

	/**
	 * Test the public URL of a file.
	 *
	 * @return void
	 */
	public function test_get_public_url_for_file(): void {
		$this->assertEquals( 'https://storage.googleapis.com/my-bucket/folder/example.jpg', $this->get_google_cloud_storage()->get_public_url_for_file( 'my-bucket', 'folder/example.jpg' ) );
	}

	/**
	 * Test if the cleanup does not delete the credential file of other applications.
	 *
	 * @return void
	 */
	public function test_cleanup_does_not_delete_other_credentials(): void {
		$file = wp_tempnam();
		file_put_contents( $file, '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		putenv( 'GOOGLE_APPLICATION_CREDENTIALS=' . $file ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		$this->get_google_cloud_storage()->cleanup_on_rest( array(), '', 'google-cloud-storage' );
		$this->get_google_cloud_storage()->cleanup_after_import();

		$this->assertFileExists( $file );
		putenv( 'GOOGLE_APPLICATION_CREDENTIALS' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		wp_delete_file( $file );
	}

	/**
	 * Test the rebuild of the tree for other services.
	 *
	 * @return void
	 */
	public function test_prepare_tree_building_for_other_service(): void {
		$listing = array( 'a' => array( 'title' => 'a' ) );
		$this->assertEquals( $listing, $this->get_google_cloud_storage()->prepare_tree_building( $listing, 'a', 'other' ) );
	}

	/**
	 * Test the user settings.
	 *
	 * @return void
	 */
	public function test_get_user_settings(): void {
		$settings = $this->get_google_cloud_storage()->get_user_settings();
		$this->assertEquals( array( 'google_cloud_storage_json', 'google_cloud_storage_bucket' ), array_keys( $settings ) );
		$this->assertEquals( 'textarea', $settings['google_cloud_storage_json']['field'] );
	}

	/**
	 * Test the output of the settings in the profile of the user.
	 *
	 * @return void
	 */
	public function test_add_user_settings(): void {
		$this->do_login();
		update_option( 'eml_user_settings', 1 );
		update_option( 'eml_user_settings_allowed_roles', array( 'administrator' ) );
		$user = wp_get_current_user();

		// test 1: user mode.
		$this->set_mode( 'user' );
		ob_start();
		$this->get_google_cloud_storage()->add_user_settings( $user );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'efml_google_cloud_storage_json', $output );
		$this->assertStringContainsString( 'efml_google_cloud_storage_bucket', $output );

		// test 2: other mode.
		$this->set_mode( 'global' );
		ob_start();
		$this->get_google_cloud_storage()->add_user_settings( $user );
		$this->assertEmpty( ob_get_clean() );
	}

	/**
	 * Test the form title and description.
	 *
	 * @return void
	 */
	public function test_form_texts(): void {
		$google_cloud_storage = $this->get_google_cloud_storage();
		$this->do_login();

		// test 1: manual mode.
		$this->set_mode( 'manually' );
		$this->assertEquals( 'Enter your access credentials', $google_cloud_storage->get_form_title() );
		$this->assertStringContainsString( 'console.cloud.google.com', $google_cloud_storage->get_form_description() );

		// test 2: global mode with JSON.
		$this->set_mode( 'global' );
		update_option( 'eml_google_cloud_storage_json', $google_cloud_storage->encrypt_value( self::$authentication_json ) );
		$this->assertEquals( 'Connect to your Google Cloud Storage', $google_cloud_storage->get_form_title() );
		$this->assertStringContainsString( 'already set', $google_cloud_storage->get_form_description() );
	}

	/**
	 * Test the export object.
	 *
	 * @return void
	 */
	public function test_get_export_object(): void {
		$this->assertSame( \ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage\Export::get_instance(), $this->get_google_cloud_storage()->get_export_object() );
	}
}
