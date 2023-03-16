# Release Notes for Amazon S3 for Craft CMS

## Unreleased

- Buckets that don’t include a `.` in their name now use the new virtual host URL format by default. ([#128](https://github.com/craftcms/aws-s3/issues/128))

## 1.3.0 - 2021-10-21

### Changed
- Amazon S3 now requires Craft 3.4 or later.

### Fixed
- Fixed a bug where Cloudfront invalidations did not include the configured subfolder.

## 1.2.15 - 2021-07-30

### Added
- If no credentials are set, the plugin will now also check the `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` environment variable to determine if IAM ECS authorization is possible. ([#122](https://github.com/craftcms/aws-s3/pull/122))

## 1.2.14 - 2021-07-29

### Fixed
- Fixed a bug with a missing class import when using credential-less EC2 access.

## 1.2.13 - 2021-07-29

### Fixed
- Fixed a regression introduced by [#118](https://github.com/craftcms/aws-s3/pull/118) that would prevent credential-less EC2 access from working. ([#120](https://github.com/craftcms/aws-s3/issues/120))

## 1.2.12 - 2021-07-20

### Added
- If no credentials are set, the plugin will now also check the `AWS_WEB_IDENTITY_TOKEN_FILE` and `AWS_ROLE_ARN` environment variables. ([#118](https://github.com/craftcms/aws-s3/pull/118))

### Fixed
- Fixed a bug that could sometimes occur if automatic focal point detection was turned on. ([#101](https://github.com/craftcms/aws-s3/issues/101))
- Fixed an error that would cause incorrect URLs to be auto-generated for volumes. ([#109](https://github.com/craftcms/aws-s3/issues/109))

## 1.2.11 - 2020-08-24

### Changed
- Amazon S3 now requires `league/flysystem-aws-s3-v3` to be at least on version 1.0.28.

### Fixed
- Fixed a bug where it was impossible to download assets from volumes that were hosted on AWS S3. ([#95](https://github.com/craftcms/aws-s3/pull/95))

## 1.2.10 - 2020-08-20

### Fixed
- Fixed a bug where it was impossible to install the plugin correctly when updating from Craft 2. ([#54](https://github.com/craftcms/aws-s3/issues/54))

## 1.2.9 - 2020-07-23

### Fixed
- Fixed a bug where focal point detection would not work if aliases were used in the subfolder setting. ([#86](https://github.com/craftcms/aws-s3/pull/86))
- Fixed a bug where installing the plugin could fail if the plugin had been installed and uninstalled before. ([#92](https://github.com/craftcms/aws-s3/issues/92))

## 1.2.8 - 2020-05-18

### Added
- Added the `addSubfolderToRootUrl` setting which defaults to true and changes the behavior of whether adding the subfolder to the Base URL or not.

### Changed
- CDN invalidation paths are now batched instead of being executed one by one. ([#73](https://github.com/craftcms/aws-s3/issues/73))
- If enabled, volume’s URL is updated automatically when updating region or bucket manually. ([#68](https://github.com/craftcms/aws-s3/issues/68))
- The `must-revalidate` header is no longer added to uploads. ([#27](https://github.com/craftcms/aws-s3/issues/27))

## 1.2.7 - 2020-01-09

### Fixed
- Reverted file stream changes that broke Asset CP downloads. ([#77](https://github.com/craftcms/aws-s3/issues/77))

## 1.2.6 - 2020-01-09

### Fixed
- Fixed an error that could occur when installing the plugin on a site that was migrated from Craft 2. ([#75](https://github.com/craftcms/aws-s3/issues/75))
- Fixed a bug where opening a file stream would still transfer the whole file. ([#23](https://github.com/craftcms/aws-s3/pull/23))

## 1.2.5 - 2019-8-05

### Changed
- When an expired access token is used, generate a new one instead.

## 1.2.4 - 2019-07-17

### Fixed
- Fixed an error where access token was cached for a fixed amount of time, ignoring the actual token duration. ([#22](https://github.com/craftcms/aws-s3/issues/22))

## 1.2.3 - 2019-06-14

### Fixed
- Fixed a bug where facial detection (if enabled) was applied every time an Asset was saved. ([#59](https://github.com/craftcms/aws-s3/issues/59))

## 1.2.2 - 2019-03-27

### Added
- Added the `makeUploadsPublic` setting which defaults to true and determines whether the uploaded Assets are public on the bucket. ([#48](https://github.com/craftcms/aws-s3/issues/48))

### Fixed
- Fixed an error that could occur when installing the plugin on a site that was migrated from Craft 2. ([#47](https://github.com/craftcms/aws-s3/issues/47))

## 1.2.1 - 2019-03-05

### Changed
- Default URLs for buckets now use HTTPS. ([#34](https://github.com/craftcms/aws-s3/issues/34))

### Fixed
- Fixed an error that occurred if expiry time was set up incorrectly. ([#47](https://github.com/craftcms/aws-s3/issues/47))

## 1.2.0 - 2019-02-21

### Added
- Added the CloudFront Path Prefix setting. ([#46](https://github.com/craftcms/aws-s3/pull/46))
- The Bucket and Region settings can now be set to environment variables. ([#42](https://github.com/craftcms/aws-s3/issues/42))

## 1.1.3 - 2019-02-06

### Fixed
- Fixed a bug where migrations were making project config changes when they shouldn't have been. ([#43](https://github.com/craftcms/aws-s3/issues/43))

## 1.1.2 - 2019-02-04

### Changed
- Amazon S3 now requires Craft 3.1.5.
- Settings that can be set to environment variables now show a tip about that if the value is not already set to an environment variable or alias.

## 1.1.1 - 2019-02-01

### Fixed
- Fixed an error that occurred when installing this plugin with no volumes defined. ([#41](https://github.com/craftcms/aws-s3/issues/41))
- Fixed an error that occurred when updating to 1.1.0 in some cases. ([#40](https://github.com/craftcms/aws-s3/issues/40))

## 1.1.0 - 2019-02-01

### Added
- It's now possible to detect faces on upload and set the focal point accordingly.
- Access Key ID, Secret Access Key, Subfolder, and CloudFront Distribution ID settings can now be set to environment variables. ([#35](https://github.com/craftcms/aws-s3/issues/35))

### Changed
- Show validation error when creating a volume and not specify a bucket.

### Fixed
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
