# Release Notes for Amazon S3 for Craft CMS

## Unreleased

### Fixed
- Fixed an error that occurred if expiry time was set up incorrectly. ([#47](https://github.com/craftcms/aws-s3/issues/47))

## 1.2.0 - 2019-02-21

### Added
- Added the CloudFront Path Prefix setting. ([#46](https://github.com/craftcms/aws-s3/pull/46))
- The Bucket and Region settings can now be set to environment variables. ([#42](https://github.com/craftcms/aws-s3/issues/42))

## 1.1.3 - 2019-02-06

### Fixed
- Fixed a bug where migrations were making project config changes when they shouldn't have been. ([#43](https://github.com/craftcms/aws-s3/issues/43))

## 1.1.2 - 2019-02-04

### Changed
- Amazon S3 now requires Craft 3.1.5.
- Settings that can be set to environment variables now show a tip about that if the value is not already set to an environment variable or alias.

## 1.1.1 - 2019-02-01

### Fixed
- Fixed an error that occurred when installing this plugin with no volumes defined. ([#41](https://github.com/craftcms/aws-s3/issues/41))
- Fixed an error that occurred when updating to 1.1.0 in some cases. ([#40](https://github.com/craftcms/aws-s3/issues/40))

## 1.1.0 - 2019-02-01

### Added
- It's now possible to detect faces on upload and set the focal point accordingly.
- Access Key ID, Secret Access Key, Subfolder, and CloudFront Distribution ID settings can now be set to environment variables. ([#35](https://github.com/craftcms/aws-s3/issues/35))

### Changed
- Show validation error when creating a volume and not specify a bucket.

### Fixed
- Fixed an error that occurred when updating from Craft 2 to Craft 3.1 when using this plugin. ([#38](https://github.com/craftcms/aws-s3/issues/38))
- Fixed a migration error. ([#39](https://github.com/craftcms/aws-s3/issues/39))

## 1.0.8 - 2018-01-02

### Added
- Amazon S3 volumes’ Base URL settings are now parsed for [aliases](http://www.yiiframework.com/doc-2.0/guide-concept-aliases.html) (e.g. `@web`).

## 1.0.7 - 2017-11-20

### Changed
- Loosened up the `league/flysystem-aws-s3-v3` version requirement.

## 1.0.6 - 2017-08-15

### Changed
- Craft 3 Beta 24 compatibility.

## 1.0.5 - 2017-07-31

### Fixed
- Fixed a bug where cache duration information was not being saved for Volumes. ([#6](https://github.com/craftcms/aws-s3/issues/6))
- Fixed a bug where it was not possible to list buckets when using newer AWS SDK versions.

## 1.0.4 - 2017-07-07

### Changed
- Craft 3 Beta 20 compatibility.

### Fixed
- Fixed a bug where invalidating a CDN path might prevent Craft from thinking the file was deleted.

## 1.0.3 - 2017-02-17

### Added
- Added AWS access token caching.

### Fixed
- Fixed a bug where the asset bundle was trying to load a non-existing CSS file.
- Fixed compatibility with Craft >= 3.0.0-beta.4.

## 1.0.2 - 2017-02-02

### Fixed
- Fixed a bug where the Edit Volume page would 404 on Craft Personal and Client editions when this plugin was installed. 

## 1.0.1 - 2017-01-31

### Fixed
- Fixed typo in “CloudFront distribution ID” label.

## 1.0.0.1 - 2017-01-31

### Fixed
- Fixed Composer installation support  

## 1.0.0 - 2017-01-31

Initial release.
