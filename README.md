# Amazon S3 for Craft CMS

This plugin provides an [Amazon S3](https://aws.amazon.com/s3/) integration for [Craft CMS](https://craftcms.com/).


## Requirements

This plugin requires Craft CMS 3.1.0 or later.


## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Amazon S3”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/aws-s3

# tell Craft to install the plugin
./craft install/plugin aws-s3
```

## Setup

To create a new asset volume for your Amazon S3 bucket, go to Settings → Assets, create a new volume, and set the Volume Type setting to “Amazon S3”.

### Per-Environment Configuration

Amazon S3 volumes’ Base URL, Access Key ID, Secret Access Key, Subfolder, and CloudFront Distribution ID settings can be set to environment variables. 

First, add the following environment variables to your `.env` and `.env.example` files:

```bash
# The S3 volume's base URL
S3_BASE_URL=""

# The AWS API key with read/write access to S3
S3_API_KEY=""

# The AWS API key secret
S3_SECRET=""

# The S3 buckte subfolder the volume should be set to
S3_SUBFOLDER=""

# The CloudFront distribution ID the S3 bucket is cached by
S3_CLOUDFRONT_ID=""
``` 

Fill in the values in your `.env` file (leaving the values in `.env.example` blank).

Then when you create an Amazon S3 volume, you can reference these environment variables in the volume’s setting by typing `$` followed by the environment variable names.

Only the environment variable names will be saved to your database and `project.yaml` file, not their values.

### Overriding the Bucket and Region

Once you’ve created your Amazon S3 volume, you can override its bucket and/or region for an environment by adding two new environment variables:

```bash
# The name of the S3 bucket
S3_BUCKET=""

# The region the S3 bucket is in
S3_REGION=""
```

Then create a `config/volumes.php` file that overrides your volume’s `bucket` and `region` settings to the values provided by these environment variables:

```php
<?php

return [
    'myVolumeHandle' => array_filter([
        'bucket' => getenv('S3_BUCKET'),
        'region' => getenv('S3_REGION'),
    ]),
];
```

Now any environments that have `S3_BUCKET` and/or `S3_REGION` environment variables defined will override the volume’s `bucket` and `region` settings.

### Using the automatic focal point detection

This plugin can use the AWS Rekognition service to detect faces in an image and automatically set the focal point accordingly. This requires the image to be either a jpg or a png file. To enable this feature, just turn it on the volume settings.

:warning: ️Using this will incur extra cost for each upload

:warning: ️Using this requires the <code>rekognition:DetectFaces</code> action to be allowed.
