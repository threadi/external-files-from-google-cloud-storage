# Define credentials via constants

You can define the credentials of Google Cloud Storage as constants, e.g. in your `wp-config.php`. This keeps them out of the database and out of database backups, and allows different values per environment.

Requires _External Files in Media Library_ 6.0.0 or newer. See also the [documentation of the main plugin](https://github.com/threadi/external-files-in-media-library/blob/master/docs/constants.md).

## How it works

* **All required fields:** The constants are only used if a constant is defined for all required fields (below without "optional"). If one is missing, no constant is used and the settings page shows which are missing.
* A constant replaces the value from the plugin settings, the user profile or a saved external source.
* The location of the credentials could not be changed then. The settings show hints instead of input fields.
* Values of credential fields are never sent to the browser.
* Without `EFML_GOOGLE_CLOUD_STORAGE_BUCKET` every bucket of the service account could be used. With it, saved external sources for other buckets are not usable as long as the constants are defined (no opening, import, synchronization or export), files imported before are still delivered. Manual imports from other buckets are not possible either.

The name of each constant is `EFML_` + the name of the service + `_` + the name of the field, all in uppercase.

## Constants

```php
define( 'EFML_GOOGLE_CLOUD_STORAGE_AUTHENTICATION_JSON', '{"type":"service_account", ...}' );
define( 'EFML_GOOGLE_CLOUD_STORAGE_BUCKET', 'your-bucket' ); // optional
```

## Several connections

You can define several connections, e.g. for two service accounts. List the names of the connections in `EFML_GOOGLE_CLOUD_STORAGE_CONNECTIONS` and add the name of the connection to the names of its constants. If more than one connection is complete, the form for a new external source shows the field "Connection". Without a chosen connection, the first connection matching the bucket is used (a connection without bucket matches every bucket). Details are in the [documentation of the main plugin](https://github.com/threadi/external-files-in-media-library/blob/master/docs/constants.md#several-connections).

```php
define( 'EFML_GOOGLE_CLOUD_STORAGE_CONNECTIONS', 'images,downloads' );

define( 'EFML_GOOGLE_CLOUD_STORAGE_IMAGES_AUTHENTICATION_JSON', '{"type":"service_account", ...}' );
define( 'EFML_GOOGLE_CLOUD_STORAGE_IMAGES_BUCKET', 'your-image-bucket' );

define( 'EFML_GOOGLE_CLOUD_STORAGE_DOWNLOADS_AUTHENTICATION_JSON', '{"type":"service_account", ...}' );
define( 'EFML_GOOGLE_CLOUD_STORAGE_DOWNLOADS_BUCKET', 'your-download-bucket' );
```
