Amazon S3 for Craft CMS
=======================

This plugin provides an [Amazon S3](https://aws.amazon.com/s3/) integration for [Craft CMS](https://craftcms.com/).


## Requirements

This plugin requires Craft CMS 3.0.0-beta.1 or later.


## Installation

### For Composer-based Craft installs

If you installed Craft via [Composer](https://getcomposer.org/), follow these instructions:

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to install the plugin:

        php composer.phar require craftcms/aws-s3

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for Amazon S3.


### For manual Craft installs

If you installed Craft manually, you will need to install this plugin manually as well.

1. [Download the zip](https://github.com/craftcms/aws-s3/archive/master.zip), and extract it to your craft/plugins/ folder, renamed to “awss3” (no hyphens or “master”).
2. Open your terminal and go to your craft/plugins/awss3/ folder:

        cd /path/to/project/craft/plugins/awss3 

3. Install Composer into the folder by running the commands listed at [getcomposer.org/download](https://getcomposer.org/download/).
    - **Note:** If you get an error running the first line, you may need to change `https` to `http`.

4. Once Composer is installed, tell it to install the plugin’s dependencies:

        php composer.phar install

5. In the Control Panel, go to Settings → Plugins and click the “Install” button for Amazon S3.

## Setup

To create a new asset volume for your Amazon S3 bucket, go to Settings → Assets, create a new volume, and set the Volume Type setting to “Amazon S3”.
