# Changelog

## [2.0.0] - 22.11.2026

### Added

Most of the following requires External Files in Media Library 6.0.0.

- Added migration from other offload plugins via the quickstart
- Added export of image sizes and original images
- Added private files, also for buckets which are not public
- Added folder structure for exported files
- Added header "Cache-Control" and the content type for exported files
- Added credentials and bucket via constants, also for several connections
- Added setup help "Where do I find these details?" for the service account
- Added connection test, naming missing permissions
- Added link to the Google Cloud Console for each file
- Added import of large buckets file by file, also in the background
- Added search terms for the command palette
- Added new hooks
- Added integration tests with a Google Cloud Storage emulator

### Changed

- Encrypted values are bound to their purpose
- Updated German translations

### Fixed

- Fixed missing delivery of exported files via the proxy
- Fixed delivery of private files to visitors and users without the capability for Google Cloud Storage
- Fixed high memory usage with large files during export and import
- Fixed imports in scheduled tasks without logged-in user

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
