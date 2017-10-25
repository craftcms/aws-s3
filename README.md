Amazon S3 for Craft CMS
=======================

This plugin provides an [Amazon S3](https://aws.amazon.com/s3/) integration for [Craft CMS](https://craftcms.com/).


## Requirements

This plugin requires Craft CMS 3.0.0-beta.20 or later.


## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require craftcms/aws-s3

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for Amazon S3.

## Setup

To create a new asset volume for your Amazon S3 bucket, go to Settings → Assets, create a new volume, and set the Volume Type setting to “Amazon S3”.


### Environment Variables

It's possible to manage the configuration for this plugin in the environment - just make sure you have the following environment variables:

* `AWS_KEY` - the API key with read/write access to S3
* `AWS_SECRET` - the API secret with read/write access to S3
* `AWS_BUCKET` - the name of the bucket you wish to use
* `AWS_REGION` - the region which the bucket above resides

And then create a `config/volumes.php` file containing references to these variables:

```php
<?php

return [

    // The key below needs to be the same as your "handle" when creating a new volume
    
    'awsS3' => [
        'hasUrls' => true,
        
        'url' => 'https://s3-eu-west-1.amazonaws.com/' . getenv('AWS_BUCKET') . '/',
        
        'keyId' => getenv('AWS_KEY'),
        
        'secret' => getenv('AWS_SECRET'),
        
        'bucket' => getenv('AWS_BUCKET'),
        
        'region' => getenv('AWS_REGION'),
    ],
];
```

Finally, make sure your bucket has a valid policy which can be used to display the images from the CMS. Head over to the [AWS Policy Generator](https://awspolicygen.s3.amazonaws.com/policygen.html) and generate a policy with the following details:

* Type of policy: **S3 Bucket Policy**
* Effect: **Allow**
* Principal: *****
* Actions: **GetObject**
* ARN: **arn:aws:s3:::{bucket}**

Once you have this policy, go to **S3** > **{bucket}** > **Permissions** > **Bucket Policy**, paste in your policy and save.
