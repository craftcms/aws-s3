# Upgrading to Amazon S3 3.0 (Craft 6)

Craft 6 replaces filesystems with [Laravel disks](https://laravel.com/docs/filesystem), so Amazon S3 storage is now a disk that uses Laravel’s `s3` driver, configured in `config/filesystems.php`. This plugin no longer provides a filesystem type. It keeps the rest of what it did: automatic focal points with Amazon Rekognition, and CloudFront invalidation.

## Requirements

- Craft CMS 6.0.0-beta.1 or later
- PHP 8.5 or later
- `craftcms/yii2-adapter` is no longer needed

## Upgrade steps

1. Update the plugin as part of upgrading your project to Craft 6: `composer require craftcms/aws-s3:^3.0`.
2. When Craft’s upgrade stops and lists filesystems it can’t convert, add a disk with the same handle to `config/filesystems.php` for each Amazon S3 filesystem, using the [settings below](#settings-and-config), then run the upgrade again. Craft then points your volumes at the disks and removes the filesystem definitions from your project config.
3. Select the volumes that used **Attempt to set the focal point automatically?** in **Settings** → **Plugins** → **Amazon S3**.
4. Add the `cloudfront_distribution_id` and `cloudfront_path_prefix` keys to the disks of filesystems that used CloudFront.

## Breaking changes

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\awss3\Plugin` | `CraftCms\AwsS3\Plugin` |
| `craft\awss3\Fs` | Removed. Use a disk with Laravel’s `s3` driver. |
| `craft\awss3\Fs::invalidateCdnPath()` | `CraftCms\AwsS3\CloudFront\CloudFrontInvalidations::add()` |
| `craft\awss3\Fs::detectFocalPoint()` | Removed. Detection runs in the `CraftCms\AwsS3\Jobs\DetectFocalPoint` queue job. |
| `craft\awss3\S3Client` | Removed. It refreshed the temporary session tokens 2.x cached, and Laravel’s `s3` driver uses your credentials directly. |
| `craft\awss3\AwsS3Bundle` | Removed |
| `craft\awss3\controllers\BucketsController` | Removed |

### Settings and config

Each Amazon S3 filesystem setting maps to a key on its disk:

| Filesystem setting | Disk config |
| --- | --- |
| Access Key ID (`keyId`) | `key`. Leave it out to use the AWS SDK’s default credentials, such as an IAM role, web identity or ECS task role. |
| Secret Access Key (`secret`) | `secret` |
| Bucket (`bucket`) | `bucket` |
| Region (`region`) | `region` |
| Subfolder (`subfolder`) | `root` |
| Base URL (`url`) | `url`. Laravel adds the disk’s `root` to it, as **Add the subfolder to the Base URL?** did. |
| Make Uploads Public? (`makeUploadsPublic`) | `'visibility' => 'public'` or `'private'` |
| Cache Duration (`expires`) | `'options' => ['CacheControl' => 'max-age=<seconds>']` |
| CloudFront Distribution ID (`cfDistributionId`) | `cloudfront_distribution_id` |
| CloudFront Path Prefix (`cfPrefix`) | `cloudfront_path_prefix` |
| Attempt to set the focal point automatically? (`autoFocalPoint`) | The plugin’s **Detect faces in these volumes** setting, or `volumes` in `config/craft/aws-s3.php` |

For example:

```php
// config/filesystems.php
'disks' => [
    'images' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'root' => 'images',
        'url' => env('AWS_URL'),
        'visibility' => 'public',
        'options' => [
            'CacheControl' => 'max-age=604800',
            // 2.x sent this header with every upload
            'ServerSideEncryption' => 'AES256',
        ],
        'cloudfront_distribution_id' => env('CLOUDFRONT_DISTRIBUTION_ID'),
        'cloudfront_path_prefix' => 'assets',
    ],
],
```

If **Add the subfolder to the Base URL?** was off, the Base URL already pointed inside the subfolder. Laravel always adds `root` to `url`, so set `url` to the address of the bucket’s top level instead.

Files on these disks keep the same keys, so assets don’t need to be moved.

The plugin now has its own settings: the volumes to detect faces in, and optional AWS credentials and a Rekognition region. Set them in the control panel, or publish a config file with `php artisan vendor:publish --tag=aws-s3` and edit `config/craft/aws-s3.php`.

### Routes and action URLs

The `aws-s3/buckets/load-bucket-data` action, which filled the filesystem settings’ bucket list, was removed.

## Removed

- The Amazon S3 filesystem type, and its control panel settings. Use a disk with Laravel’s `s3` driver.
- The bucket list in the control panel.

## Need help?

[Open an issue](https://github.com/craftcms/aws-s3/issues) on GitHub.
