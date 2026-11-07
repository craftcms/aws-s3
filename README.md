<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Amazon S3 for Craft CMS icon"></p>

<h1 align="center">Amazon S3 for Craft CMS</h1>

This plugin sets the focal points of images stored on [Amazon S3](https://aws.amazon.com/s3/) in [Craft CMS](https://craftcms.com/), using faces that [Amazon Rekognition](https://aws.amazon.com/rekognition/) finds in them.

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

### AWS credentials

By default, Rekognition is called with the disk’s own credentials, in the bucket’s region, and reads images straight from the bucket. Those credentials need these permissions on top of the S3 permissions the disk itself needs:

- `rekognition:DetectFaces`
- `s3:GetObject` on the volume’s objects, so Rekognition can read them

To call Rekognition with other credentials or in another region, set **Access Key ID**, **Secret Access Key**, and **Region** in the plugin’s settings, or `keyId`, `secret`, and `region` in `config/craft/aws-s3.php`. Each can be an environment variable. The image is then sent to Rekognition instead, up to 5 MB.

Disks on S3-compatible services, such as Cloudflare R2 or MinIO (any disk with an `endpoint`), need a **Region**. Their images are always sent to Rekognition, which is called with the credentials from the plugin’s settings or, when those are blank, the AWS SDK’s default credentials, such as `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`.

## Upgrading from Craft 5

Craft’s upgrade asks you to replace each filesystem with a disk in `config/filesystems.php`. Amazon S3 filesystems’ **Attempt to set the focal point automatically?** setting isn’t carried over, so select the volumes that used it in the plugin’s settings. CloudFront invalidation is no longer provided.
