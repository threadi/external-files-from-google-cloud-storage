# Changelog

## [Unreleased]

### Added

- Added possibility to define the authentication JSON and the bucket via constants, e.g. in wp-config.php (requires External Files in Media Library 6.0.0)
- If the bucket is defined via constant, saved external sources for other buckets are not usable
- Added documentation for the constants in docs/constants.md
- Added support for the folder structure of exported files (requires External Files in Media Library 6.0.0)
- Added support for private files (requires External Files in Media Library 6.0.0): files could be exported into buckets, which are not public, and are delivered via signed URLs or the cache of the proxy
- Added hook "efmlgcs_signed_url" to change the signed URLs
- Added link to the Google Cloud Console in the info box of each file (requires External Files in Media Library 6.0.0)
- Added hook "efmlgcs_upload_options" to change the options for the upload of exported files, e.g. to add "cacheControl" or "contentDisposition"
- Exported files get the content type of their attachment

### Changed

- Updated German translations

### Fixed

- Fixed missing delivery of exported files via the proxy, as the files could not be loaded for its cache
- Fixed high memory usage during the export of large files, they are now uploaded as stream (and as resumable upload for files with more than 5 MB)
- Fixed high memory usage during the import of large files, they are now saved directly into a local file

## [1.1.0] - 24.10.2026

### Added

- Added support for automatic updates via GitHub ("Update URI")
- Added PHPUnit tests and a GitHub workflow to run them
- Added hook "efmlgcs_storage_client_config" to customize the configuration of the Google Cloud Storage client

### Changed

- Credentials are no longer saved in a temporary file and no longer set as environment variable
- Uninstall now removes the cached update data

### Fixed

- Fixed deletion of the credential file of the server set in "GOOGLE_APPLICATION_CREDENTIALS"
- Fixed hiding of not allowed file types in directory listing
- Fixed usage of the encrypted authentication JSON in global mode
- Fixed wrong public URL for files in sub-directories
- Fixed missing error handling for errors from Google Cloud Storage during import and export
- Fixed import of directories as files during import of a bucket
- Fixed import of a bucket in global mode
- Fixed wrong log message for download errors
- Fixed wrong text domain in configuration hint

## [1.0.2] - 16.08.2026

### Changed

- Now requires PHP 8.2 or newer
- Compatibility with WordPress 7.1
- Updates translations

## [1.0.1] - 29.03.2026

### Added

- Added translations for all 5 german language packages in WordPress

### Changed

- Updated the settings library
- Set compatibility with WordPress 7.0

### Fixed

- Fixed wrong used text domain

## [1.0.0] - 14.01.2026

- Initial release
