<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Amazon S3 for Craft CMS icon"></p>

<h1 align="center">Amazon S3 for Craft CMS</h1>

This plugin exposes [Amazon S3](https://aws.amazon.com/s3/) as a configurable filesystem type, in [Craft CMS](https://craftcms.com/).

> [!DANGER]
> You are viewing an unreleased version of this plugin, compatible only with Craft 6.x.

## Requirements

This plugin requires Craft CMS 6.0.0 or later.
Earlier versions of the plugin support Craft 4.x and 5.x.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Amazon S3.” Press **Install** on the plugin’s detail page.

#### With Composer

Open your terminal and run the following commands:

```bash
cd /path/to/my-project.test

ddev composer require craftcms/aws-s3
ddev artisan craft:plugin:install aws-s3
```

## Setup

To create a new Amazon S3 filesystem to use with your volumes, visit **Settings** → **Filesystems**, and press **New filesystem**. Select “Amazon S3” for the **Filesystem Type** setting and configure as needed.

> 💡 The Base URL, Access Key ID, Secret Access Key, Bucket, Region, Subfolder, CloudFront Distribution ID, and CloudFront Path Prefix settings can be set to environment variables. See [Environmental Configuration](https://craftcms.com/docs/5.x/configure.html#control-panel-settings) in the Craft docs to learn more about that.

### AWS IAM Permissions

Setting up IAM permissions for use with this plugin differs from what options you want to be available.

Generally, you'll want an IAM policy that grants the following actions on the [resource(s)](https://docs.aws.amazon.com/AmazonS3/latest/dev/s3-arn-format.html) that you'll use:
* `s3:GetBucketLocation`
* `s3:ListBucket`
* `s3:PutObject`
* `s3:GetObject`
* `s3:DeleteObject`
* `s3:GetObjectAcl`
* `s3:PutObjectAcl`

If you want to allow the site administrator to list and select the bucket to use, you'll also have to add the `s3:ListAllMyBuckets` permission to the `arn:aws:s3:::` resource and the `s3:GetBucketLocation` permission to the specific bucket resource. Please note, that if a bucket lacks the `s3:GetBucketLocation` permission, it will not appear in the bucket selection list. You can still use that bucket by switching to the **Manual** selection mode and providing its name.

If you use [CloudFront](https://aws.amazon.com/cloudfront/) and would like Craft to automatically invalidate asset paths whenever they’re modified, you'll also need the following permissions:
* `cloudfront:ListInvalidations`
* `cloudfront:GetInvalidation`
* `cloudfront:CreateInvalidation`

An IAM policy that grants all the capabilities this filesystem provides would look something like this:

```
{
"Version": "2012-10-17",
"Statement": [
    {
        "Effect": "Allow",
        "Action": [
            "s3:ListAllMyBuckets"
        ],
        "Resource": "*"
    },
    {
        "Effect": "Allow",
        "Action": [
            "s3:GetBucketLocation",
            "s3:ListBucket",
            "s3:PutObject",
            "s3:GetObject",
            "s3:DeleteObject",
            "s3:GetObjectAcl",
            "s3:PutObjectAcl",
            "cloudfront:ListInvalidations",
            "cloudfront:GetInvalidation",
            "cloudfront:CreateInvalidation"
        ],
        "Resource": [
            "arn:aws:s3:::bucketname/*",
            "arn:aws:cloudfront::accountid:distribution/distributionid"
        ]
    },
    {
        "Effect": "Allow",
        "Action": [
            "s3:GetBucketLocation",
            "s3:ListBucket"
        ],
        "Resource": [
            "arn:aws:s3:::bucketname"
        ]
    }
]
}
```

### Using automatic focal point detection

This plugin can use the [AWS Rekognition](https://aws.amazon.com/rekognition/) service to detect faces in an image and automatically set the focal point accordingly. This requires the image to be either a jpg or a png file. You can enable this feature via **Attempt to set the focal point automatically?** in the filesystem settings.

> [!WARNING]
> Enabling face detection may incur additional costs for each upload. Your IAM policy must include the `rekognition:DetectFaces` action.

### Assuming Role with OIDC

If you provide no credentials when setting up the filesystem, the plugin will look for standard `AWS_WEB_IDENTITY_TOKEN_FILE` and `AWS_ROLE_ARN` environment variables and attempt to connect using the [web identity provider](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/assume-role-with-web-identity-provider.html). This is the ideal way to allow fine-grained access control for hosting Craft CMS in Kubernetes or other automatically-provisioned infrastructure. See the [IAM documentation](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_roles_providers_create_oidc.html) for more details.

### Tasks running in ECS

The filesystem will also automatically detect IAM roles for ECS tasks in the `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` environment variable, when available. See the [IAM Roles for Tasks](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-iam-roles.html) documentation for more details.
