<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   MIT
 */

namespace craft\awss3;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the Dashboard
 */
class AwsS3Bundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@craft/awss3/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/editVolume.js',
        ];

        parent::init();
    }
}
