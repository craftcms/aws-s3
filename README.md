<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Amazon S3 for Craft CMS icon"></p>

<h1 align="center">Amazon S3 for Craft CMS</h1>

This plugin provides an [Amazon S3](https://aws.amazon.com/s3/) integration for [Craft CMS](https://craftcms.com/).

## Requirements

This plugin requires Craft CMS 3.1.5 or later.

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

> **Tip:** The Base URL, Access Key ID, Secret Access Key, Bucket, Region, Subfolder, CloudFront Distribution ID, and CloudFront Path Prefix settings can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs to learn more about that.

### Using the automatic focal point detection

This plugin can use the AWS Rekognition service to detect faces in an image and automatically set the focal point accordingly. This requires the image to be either a jpg or a png file. To enable this feature, just turn it on the volume settings.

:warning: ️Using this will incur extra cost for each upload

:warning: ️Using this requires the <code>rekognition:DetectFaces</code> action to be allowed.
