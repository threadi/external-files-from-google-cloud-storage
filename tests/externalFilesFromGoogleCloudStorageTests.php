<?php
/**
 * File to handle the main object for each test class.
 *
 * @package external-files-from-google-cloud-storage
 */

namespace ExternalFilesFromGoogleCloudStorage\Tests;

use ExternalFilesFromGoogleCloudStorage\GoogleCloudStorage;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use WP_Error;
use WP_HTTP_Requests_Response;
use WP_UnitTestCase;

/**
 * Object to handle the preparations for each test class.
 */
abstract class externalFilesFromGoogleCloudStorageTests extends WP_UnitTestCase {
	/**
	 * The name of the bucket in the tests.
	 *
	 * @var string
	 */
	protected string $bucket = 'my-bucket';

	/**
	 * The base URL of the Google Cloud Storage JSON API for the bucket.
	 *
	 * @var string
	 */
	protected string $api_url = 'https://storage.googleapis.com/storage/v1/b/my-bucket';

	/**
	 * The authentication JSON of a service account for the tests.
	 *
	 * @var string
	 */
	protected static string $authentication_json = '';

	/**
	 * List of HTTP responses the actual test expects, with the URL as index.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $http_responses = array();

	/**
	 * List of HTTP requests via WordPress during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $http_requests = array();

	/**
	 * List of responses for requests to Google Cloud during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $google_responses = array();

	/**
	 * List of requests to Google Cloud during the actual test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $google_requests = array();

	/**
	 * List of requests during the actual test, for which no response is configured.
	 *
	 * The tests fail with them in tear_down(), as the code under test could catch the failure of the request.
	 *
	 * @var array<int,string>
	 */
	private static array $unexpected_requests = array();

	/**
	 * Prepare the test environment for each test class.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// create the authentication JSON of a service account with its own private key.
		if ( empty( self::$authentication_json ) ) {
			$key = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			);
			openssl_pkey_export( $key, $private_key );
			self::$authentication_json = (string) wp_json_encode(
				array(
					'type'           => 'service_account',
					'project_id'     => 'my-project',
					'private_key_id' => 'key123',
					'private_key'    => $private_key,
					'client_email'   => 'service@my-project.iam.gserviceaccount.com',
					'client_id'      => '123456789',
					'token_uri'      => 'https://oauth2.googleapis.com/token',
				)
			);
		}

		// prepare to load just one time.
		if ( ! did_action( 'efmlgcs_test_preparation_loaded' ) ) {
			// enable error reporting.
			error_reporting( E_ALL );

			// enable the extended log mode.
			update_option( 'eml_log_mode', 2 );

			// initialize the main plugin.
			\ExternalFilesInMediaLibrary\Plugin\Install::get_instance()->activation();

			// initialize this plugin (adds the capability for Google Cloud Storage).
			\ExternalFilesFromGoogleCloudStorage\Plugin\Init::get_instance()->activation();

			// start with an empty log, as its table is kept between test runs.
			\ExternalFilesInMediaLibrary\Plugin\Log::get_instance()->truncate_log();

			// the service only registers its hooks for users with the capability for it, so we initialize it as admin.
			wp_set_current_user( 1 );
			GoogleCloudStorage::get_instance()->init();

			// run initialization.
			do_action( 'init' );
			wp_set_current_user( 0 );

			// prevent any HTTP requests to external sources.
			add_filter( 'pre_http_request', array( self::class, 'filter_http_requests' ), 10, 3 );

			// prevent sending of emails.
			add_filter( 'wp_mail', array( self::class, 'filter_wp_mail' ) );

			// mark as loaded.
			do_action( 'efmlgcs_test_preparation_loaded' );
		}
	}

	/**
	 * Prepare each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// the GoogleCloudStorage object is a singleton, so we reset its state before each test.
		$this->reset_google_cloud_storage();

		// reset the lists for the mocks.
		self::$http_responses      = array();
		self::$http_requests       = array();
		self::$google_responses    = array();
		self::$google_requests     = array();
		self::$unexpected_requests = array();

		// answer all requests to Google Cloud locally (the hooks are restored after each test).
		add_filter( 'efmlgcs_storage_client_config', array( self::class, 'set_http_handler' ), PHP_INT_MAX );
	}

	/**
	 * Cleanup after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->assertEmpty( self::$unexpected_requests, 'Requests without configured response: ' . implode( ', ', self::$unexpected_requests ) );
		$this->do_logout();
		parent::tear_down();
	}

	/**
	 * Set an HTTP handler for the Google Cloud Storage client, which answers all requests locally.
	 *
	 * @param array<string,mixed> $config The configuration of the client.
	 *
	 * @return array<string,mixed>
	 */
	public static function set_http_handler( array $config ): array {
		$config['httpHandler']     = array( self::class, 'handle_google_request' );
		$config['authHttpHandler'] = array( self::class, 'handle_google_request' );
		$config['retries']         = 0;
		return $config;
	}

	/**
	 * Answer a request to Google Cloud with the configured response.
	 *
	 * @param RequestInterface    $request The request.
	 * @param array<string,mixed> $options The options.
	 *
	 * @return ResponseInterface
	 */
	public static function handle_google_request( RequestInterface $request, array $options = array() ): ResponseInterface {
		// get the query.
		$query = array();
		parse_str( $request->getUri()->getQuery(), $query );

		// get the URL without query.
		$url = (string) $request->getUri()->withQuery( '' );

		// log the request.
		self::$google_requests[] = array(
			'method'  => $request->getMethod(),
			'url'     => $url,
			'query'   => $query,
			'headers' => $request->getHeaders(),
			'body'    => (string) $request->getBody(),
		);

		// search the response for this request.
		foreach ( self::$google_responses as $index => $response ) {
			// bail if method or URL does not match.
			if ( $response['method'] !== $request->getMethod() || $response['url'] !== $url ) {
				continue;
			}

			// bail if the query does not match.
			if ( array_intersect_assoc( $response['query'], $query ) !== $response['query'] ) {
				continue;
			}

			// remove responses, which could only be used a limited number of times.
			if ( $response['times'] > 0 && 0 === --self::$google_responses[ $index ]['times'] ) {
				unset( self::$google_responses[ $index ] );
			}

			// get the response.
			$result = new Response( $response['status'], $response['headers'], $response['body'] );

			// throw an exception for errors, like the Guzzle handler of Google Cloud does it.
			if ( $response['status'] >= 400 ) {
				throw \GuzzleHttp\Exception\RequestException::create( $request, $result );
			}

			// return the response.
			return $result;
		}

		// show error if no response is configured for this request.
		self::$unexpected_requests[] = $request->getMethod() . ' ' . $request->getUri();
		self::fail( 'Trying a request to Google Cloud, which is not configured in the test: ' . $request->getMethod() . ' ' . $request->getUri() );
	}

	/**
	 * Set the response for a request to Google Cloud.
	 *
	 * The first matching response is used.
	 *
	 * @param string               $method  The HTTP method.
	 * @param string               $url     The URL without query. Relative URLs are relative to the bucket in the JSON API.
	 * @param int                  $status  The HTTP status.
	 * @param array<mixed>|string  $body    The body (arrays will be converted to JSON).
	 * @param array<string,string> $query   Query params the request must contain.
	 * @param array<string,string> $headers The response headers.
	 * @param int                  $times   How often the response could be used (0 for unlimited).
	 *
	 * @return void
	 */
	protected function add_google_response( string $method, string $url, int $status, array|string $body = '', array $query = array(), array $headers = array(), int $times = 0 ): void {
		// add the API URL to relative URLs.
		if ( ! str_starts_with( $url, 'https://' ) ) {
			$url = $this->api_url . $url;
		}

		// convert arrays to JSON.
		if ( is_array( $body ) ) {
			$body                    = (string) wp_json_encode( $body );
			$headers['Content-Type'] = 'application/json; charset=UTF-8';
		}

		// add new responses as first, so they overwrite older ones.
		array_unshift(
			self::$google_responses,
			array(
				'method'  => $method,
				'url'     => $url,
				'query'   => $query,
				'status'  => $status,
				'headers' => $headers,
				'body'    => $body,
				'times'   => $times,
			)
		);
	}

	/**
	 * Set the response for the check whether the bucket exists.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return void
	 */
	protected function add_bucket( int $status = 200 ): void {
		$this->add_google_response( 'GET', '', $status, 200 === $status ? array( 'name' => $this->bucket ) : $this->get_error_body( $status ) );
	}

	/**
	 * Set the response for the IAM policy of the bucket.
	 *
	 * @param bool $is_public Whether the bucket is public.
	 *
	 * @return void
	 */
	protected function add_iam_policy( bool $is_public ): void {
		$bindings = array(
			array(
				'role'    => 'roles/storage.legacyBucketOwner',
				'members' => array( 'projectOwner:my-project' ),
			),
		);
		if ( $is_public ) {
			$bindings[] = array(
				'role'    => 'roles/storage.objectViewer',
				'members' => array( 'allUsers' ),
			);
		}
		$this->add_google_response( 'GET', '/iam', 200, array( 'bindings' => $bindings ) );
	}

	/**
	 * Return the data of an object like Google Cloud Storage returns it.
	 *
	 * @param string $name    The name of the object.
	 * @param string $content The content of the object.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_object_data( string $name, string $content = '' ): array {
		return array(
			'kind'       => 'storage#object',
			'name'       => $name,
			'bucket'     => $this->bucket,
			'size'       => (string) strlen( $content ),
			'generation' => '1704164645000000',
			'updated'    => '2024-01-02T03:04:05.000Z',
		);
	}

	/**
	 * Set the responses for a single object: its data and its content.
	 *
	 * @param string $name    The name of the object.
	 * @param string $content The content of the object.
	 *
	 * @return void
	 */
	protected function add_object( string $name, string $content ): void {
		$this->add_google_response( 'GET', '/o/' . rawurlencode( $name ), 200, array_merge( $this->get_object_data( $name, $content ), array( 'contentType' => 'image/jpeg' ) ) );
		$this->add_google_response( 'GET', '/o/' . rawurlencode( $name ), 200, $content, array( 'alt' => 'media' ) );
	}

	/**
	 * Set the response for the list of objects in the bucket.
	 *
	 * @param array<int,string> $names The names of the objects.
	 *
	 * @return void
	 */
	protected function add_object_list( array $names ): void {
		$items = array();
		foreach ( $names as $name ) {
			$items[] = $this->get_object_data( $name, 'content' );
		}
		$this->add_google_response( 'GET', '/o', 200, array( 'items' => $items ) );
	}

	/**
	 * Return the body of an error response.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_error_body( int $status ): array {
		return array(
			'error' => array(
				'code'    => $status,
				'message' => 'Error ' . $status,
				'errors'  => array(
					array(
						'message' => 'Error ' . $status,
						'reason'  => 'error',
					),
				),
			),
		);
	}

	/**
	 * Return the requests to Google Cloud during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_google_requests(): array {
		return self::$google_requests;
	}

	/**
	 * Filter any HTTP request during the tests to prevent any external communication.
	 *
	 * Tests can set the response for a specific URL with add_http_response().
	 *
	 * @param false|array|WP_Error $response    The return value.
	 * @param array                $parsed_args The used parameters.
	 * @param string               $url         The requested URL.
	 *
	 * @return false|array|WP_Error
	 */
	public static function filter_http_requests( false|array|WP_Error $response, array $parsed_args, string $url ): false|array|WP_Error {
		// let requests through, which a test has already answered.
		if ( false !== $response ) {
			return $response;
		}

		// log the request.
		self::$http_requests[] = array(
			'url'    => $url,
			'method' => isset( $parsed_args['method'] ) ? (string) $parsed_args['method'] : 'GET',
			'args'   => $parsed_args,
		);

		// show error if no response is configured for this URL, as we do not want any external URL connection during PHP Unit Tests.
		if ( ! isset( self::$http_responses[ $url ] ) ) {
			self::fail( 'Trying an external URL connection, which should not happen during PHP Unit Tests. Used URL: ' . $url );
		}

		// return the configured response.
		$configured_response = self::$http_responses[ $url ];
		return self::get_http_response( $url, $configured_response['code'], $configured_response['headers'], $configured_response['body'] );
	}

	/**
	 * Filter any email communication from WordPress.
	 *
	 * @param array $args The used arguments.
	 *
	 * @return array
	 */
	public static function filter_wp_mail( array $args ): array {
		$args['to'] = 'info@example.com';
		return $args;
	}

	/**
	 * Set the response for an HTTP request via WordPress on the given URL for the actual test.
	 *
	 * @param string               $url         The URL.
	 * @param int                  $status_code The HTTP status code.
	 * @param array<string,string> $headers     The response headers.
	 * @param string               $body        The response body.
	 *
	 * @return void
	 */
	protected function add_http_response( string $url, int $status_code, array $headers = array(), string $body = '' ): void {
		self::$http_responses[ $url ] = array(
			'code'    => $status_code,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Return the list of HTTP requests via WordPress during the actual test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_http_requests(): array {
		return self::$http_requests;
	}

	/**
	 * Return a response array, like WordPress returns it for an HTTP request.
	 *
	 * @param string $url         The requested URL.
	 * @param int    $status_code The HTTP status code.
	 * @param array  $headers     The response headers.
	 * @param string $body        The response body.
	 *
	 * @return array
	 */
	protected static function get_http_response( string $url, int $status_code, array $headers = array(), string $body = '' ): array {
		$requests_response              = new \WpOrg\Requests\Response();
		$requests_response->status_code = $status_code;
		$requests_response->url         = $url;
		$requests_response->body        = $body;
		$requests_response->headers     = new \WpOrg\Requests\Response\Headers( $headers );
		$requests_response->success     = $status_code < 400;

		$http_response             = new WP_HTTP_Requests_Response( $requests_response, null );
		$response                  = $http_response->to_array();
		$response['http_response'] = $http_response;
		return $response;
	}

	/**
	 * Set the mode, where the credentials are stored.
	 *
	 * @param string $mode The mode (global, user or manually).
	 *
	 * @return void
	 */
	protected function set_mode( string $mode ): void {
		update_option( 'eml_google-cloud-storage_credentials_vault', $mode );
	}

	/**
	 * Return the fields with the credentials like the user enters them in the manual mode.
	 *
	 * @param array<string,string> $values Values to change.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_fields( array $values = array() ): array {
		$values = array_merge(
			array(
				'authentication_json' => self::$authentication_json,
				'bucket'              => $this->bucket,
			),
			$values
		);
		$fields = array();
		foreach ( $values as $name => $value ) {
			$fields[ $name ] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		return $fields;
	}

	/**
	 * Login as admin and set the credentials for the manual mode on the object.
	 *
	 * @return GoogleCloudStorage
	 */
	protected function connect(): GoogleCloudStorage {
		$this->do_login();
		$this->set_mode( 'manually' );
		$google_cloud_storage = GoogleCloudStorage::get_instance();
		$google_cloud_storage->set_fields( $this->get_fields() );
		return $google_cloud_storage;
	}

	/**
	 * Reset the state of the GoogleCloudStorage object.
	 *
	 * @return void
	 */
	protected function reset_google_cloud_storage(): void {
		$google_cloud_storage = GoogleCloudStorage::get_instance();
		$this->set_property( $google_cloud_storage, 'fields', array() );
		$this->set_property( $google_cloud_storage, 'directory', '' );
		$this->set_property( $google_cloud_storage, 'errors', array() );
		$this->set_property( $google_cloud_storage, 'user', false );
	}

	/**
	 * Set the value of a (not public) property of the given object.
	 *
	 * @param object $obj      The object.
	 * @param string $property The name of the property.
	 * @param mixed  $value    The value.
	 *
	 * @return void
	 */
	protected function set_property( object $obj, string $property, mixed $value ): void {
		$reflection = new ReflectionClass( $obj );
		while ( ! $reflection->hasProperty( $property ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$reflection->getProperty( $property )->setValue( $obj, $value );
	}

	/**
	 * Call a not public method of the given object.
	 *
	 * @param object           $obj    The object.
	 * @param string           $method The name of the method.
	 * @param array<int,mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	protected function call_method( object $obj, string $method, array $args = array() ): mixed {
		return ( new \ReflectionMethod( $obj, $method ) )->invokeArgs( $obj, $args );
	}

	/**
	 * Return the error messages of the GoogleCloudStorage object.
	 *
	 * @return array<int,string>
	 */
	protected function get_error_messages(): array {
		$messages = array();
		foreach ( GoogleCloudStorage::get_instance()->get_errors() as $error ) {
			$messages = array_merge( $messages, $error->get_error_messages() );
		}
		return $messages;
	}

	/**
	 * Return the log entries with the given URL, the newest first.
	 *
	 * @param string $url The URL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_log_entries( string $url = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'eml_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT `url`, `log`, `state` FROM `$table` WHERE `url` = %s ORDER BY `id` DESC", $url ), ARRAY_A );
	}

	/**
	 * Login in this session as admin.
	 *
	 * @return void
	 */
	protected function do_login(): void {
		wp_set_current_user( 1, 'admin' );
	}

	/**
	 * Logout from this session.
	 *
	 * @return void
	 */
	protected function do_logout(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * Create an attachment with a local file and return its ID.
	 *
	 * @param string $file_name The name of the file in tests/Data/.
	 *
	 * @return int
	 */
	protected function create_local_attachment( string $file_name = 'example.jpg' ): int {
		return (int) self::factory()->attachment->create_upload_object( UNIT_TESTS_DATA_PLUGIN_DIR . $file_name );
	}
}
