# Changelog

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
