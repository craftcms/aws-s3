Amazon S3 for Craft CMS
=======================

This plugin provides an [Amazon S3](https://aws.amazon.com/s3/) integration for [Craft CMS](https://craftcms.com/).


## Requirements

This plugin requires Craft CMS 3.0.0-RC4 or later.


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

Once you’ve created your S3 volume in the Control Panel, you can override its settings with different values for each environment.

First, add the following environment variables to your `.env` and `.env.example` files:

```
# The AWS API key with read/write access to S3
S3_API_KEY=""

# The AWS API key secret
S3_SECRET=""

# The name of the S3 bucket
S3_BUCKET=""

# The region the S3 bucket is in
S3_REGION=""
``` 

Then fill in the values in your `.env` file (leaving the values in `.env.example` blank).

Finally, create a `config/volumes.php` file containing references to these variables:

```php
<?php

return [
    'myS3VolumeHandle' => [
        'hasUrls' => true,
        'url' => 'https://'.getenv('S3_BUCKET').'.s3.amazonaws.com/',
        'keyId' => getenv('S3_API_KEY'),
        'secret' => getenv('S3_SECRET'),
        'bucket' => getenv('S3_BUCKET'),
        'region' => getenv('S3_REGION'),
    ],
];
```
