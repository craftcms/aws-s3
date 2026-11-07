<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Amazon S3 for Craft CMS icon"></p>

<h1 align="center">Amazon S3 for Craft CMS</h1>

This plugin adds two Amazon services to assets stored on [Amazon S3](https://aws.amazon.com/s3/) in [Craft CMS](https://craftcms.com/):

- It sets images’ focal points to faces that [Amazon Rekognition](https://aws.amazon.com/rekognition/) finds in them.
- It invalidates files in [Amazon CloudFront](https://aws.amazon.com/cloudfront/) when they’re replaced, moved, renamed, or deleted.

Craft 6 stores files on [Laravel disks](https://laravel.com/docs/filesystem), so you don’t need this plugin to store assets on S3. Earlier versions of the plugin provided an Amazon S3 filesystem type for Craft 4 and 5.

## Requirements

This plugin requires Craft CMS 6.0.0 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s control panel and search for “Amazon S3.” Press **Install** on the plugin’s detail page.

#### With Composer

Open your terminal and run the following commands:

```bash
cd /path/to/my-project.test

composer require craftcms/aws-s3
php artisan craft:plugin:install aws-s3
```

## Setup

### Store your volumes on S3

Define a disk that uses Laravel’s `s3` driver in `config/filesystems.php`, and choose it for your volume in **Settings** → **Assets**:

```php
'disks' => [
    'images' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'root' => 'images',
        'visibility' => 'public',
    ],
],
```

The plugin installs the `league/flysystem-aws-s3-v3` package the driver needs. See [Amazon S3 Compatible Filesystems](https://laravel.com/docs/filesystem#amazon-s3-compatible-filesystems) in the Laravel docs for every option.

### Choose volumes for face detection

Go to **Settings** → **Plugins** → **Amazon S3** and select the volumes whose new images should get focal points from detected faces. To set this in code, create `config/craft/aws-s3.php`:

```php
<?php

return [
    // Volume handles, or '*' for all volumes
    'volumes' => ['images'],
];
```

When a JPEG or PNG image without a focal point is uploaded to one of those volumes, a queue job asks Rekognition to find faces in it, reading the image straight from its bucket. The image’s focal point is set to the center of the first face Rekognition is at least 80% confident about. Images on disks that don’t use the `s3` driver are skipped.

> [!WARNING]
> Amazon charges for each image Rekognition analyzes.

### Invalidate files in CloudFront

If a CloudFront distribution serves a disk’s files, add its ID to the disk’s config, along with the path the distribution serves the bucket under, if any:

```php
'images' => [
    'driver' => 's3',
    // ...
    'cloudfront_distribution_id' => env('CLOUDFRONT_DISTRIBUTION_ID'),
    'cloudfront_path_prefix' => 'assets',
],
```

When an asset’s file is replaced, moved, renamed, or deleted, its old path is invalidated: the prefix, followed by the file’s full key in the bucket, including the disk’s `root`. Paths are invalidated together at the end of each request or queue job.

### AWS credentials

By default, Rekognition and CloudFront are called with the disk’s own credentials. Rekognition is called in the bucket’s region, and reads images straight from the bucket. Those credentials need these permissions on top of the S3 permissions the disk itself needs:

- `rekognition:DetectFaces`
- `s3:GetObject` on the volume’s objects, so Rekognition can read them
- `cloudfront:CreateInvalidation` on the distribution

To use other credentials, set **Access Key ID** and **Secret Access Key** in the plugin’s settings, or `keyId` and `secret` in `config/craft/aws-s3.php`. To call Rekognition in another region, set **Rekognition Region**, or `region`. Each can be an environment variable. With either, images are sent to Rekognition instead of read from the bucket, up to 5 MB.

Disks on S3-compatible services, such as Cloudflare R2 or MinIO (any disk with an `endpoint`), use the credentials from the plugin’s settings or, when those are blank, the AWS SDK’s default credentials, such as `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`. Face detection on them needs a **Rekognition Region**, and their images are always sent to Rekognition.

## Upgrading from Craft 5

Craft’s upgrade asks you to replace each filesystem with a disk in `config/filesystems.php`. Amazon S3 filesystems’ settings aren’t carried over:

- Select the volumes that used **Attempt to set the focal point automatically?** in the plugin’s settings.
- Move **CloudFront Distribution ID** and **CloudFront Path Prefix** to the disk’s `cloudfront_distribution_id` and `cloudfront_path_prefix` config keys. The prefix is now followed by the disk’s `root`, as it was followed by the filesystem’s **Subfolder**.
