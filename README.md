<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Amazon S3 for Craft CMS icon"></p>

<h1 align="center">Amazon S3 for Craft CMS</h1>

This plugin provides an [Amazon S3](https://aws.amazon.com/s3/) integration for [Craft CMS](https://craftcms.com/).

## Requirements

This plugin requires Craft CMS 3.4.0 or later.

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
./craft plugin/install aws-s3
```

## Setup

To create a new asset volume for your Amazon S3 bucket, go to **Settings** → **Assets**, create a new volume, and choose “Amazon S3” for the **Volume Type** setting.

> 💡 The Base URL, Access Key ID, Secret Access Key, Bucket, Region, Subfolder, CloudFront Distribution ID, and CloudFront Path Prefix settings can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs to learn more about that.

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

If you want to allow the site administrator to list and select the bucket to use, you'll also have to add the `s3:ListAllMyBuckets` permission to the `arn:aws:s3:::` resource and the `s3:GetBucketLocation` permission to the specific bucket resource. Please note, that if a bucket lacks the `s3:GetBucketLocation` permission, it will not appear in the bucket selection list.

A typical IAM policy that grants the user to choose a bucket can look like this:
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
            "s3:PutObjectAcl"
        ],
        "Resource": [
            "arn:aws:s3:::bucketname/*"
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
### Using the automatic focal point detection

This plugin can use the [AWS Rekognition](https://aws.amazon.com/rekognition/) service to detect faces in an image and automatically set the focal point accordingly. This requires the image to be either a jpg or a png file. You can enable this feature via **Attempt to set the focal point automatically?** in the filesystem settings.

> ⚠️ ️Using this will incur extra cost for each upload, and requires the <code>rekognition:DetectFaces</code> action to be allowed.

### Assuming Role with OIDC

This plugin also has the ability to assume a role provided to the runtime with the `AWS_WEB_IDENTITY_TOKEN_FILE` and `AWS_ROLE_ARN` environment variables. If you provide no credentials to AWS and these environment variables exist, then the plugin will attempt to create a connection to AWS using the `CredentialProvider::assumeRoleWithWebIdentityCredentialProvider`. This is the ideal way to allow fine-grained access control for hosting Craft CMS in Kubernetes (for example). See [the IAM documentation on AWS for more details](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_roles_providers_create_oidc.html).

### Tasks running in ECS

This plugin is compatible with IAM roles for ECS tasks and will automatically use the `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` environment variable, if it’s available. See [the IAM Roles for Tasks documentation on AWS for more details](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-iam-roles.html).
