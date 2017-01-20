<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Volume;
use craft\base\Volume as BaseVolume;
use craft\db\Migration;
use craft\errors\VolumeException;
use craft\volumes\MissingVolume;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Convert any built-in S3 volumes to ours
        $this->_convertVolumes();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Converts any old school S3 volumes to this one
     *
     * @return void
     * @throws VolumeException if the new volume couldn't be saved
     */
    private function _convertVolumes()
    {
        $volumesService = Craft::$app->getVolumes();
        /** @var BaseVolume[] $volume */
        $allVolumes = $volumesService->getAllVolumes();

        foreach ($allVolumes as $volume) {
            if ($volume instanceof MissingVolume && $volume->expectedType === 'craft\volumes\AwsS3') {
                /** @var Volume $convertedVolume */
                $convertedVolume = $volumesService->createVolume([
                    'id' => $volume->id,
                    'type' => Volume::class,
                    'name' => $volume->name,
                    'handle' => $volume->handle,
                    'hasUrls' => $volume->hasUrls,
                    'url' => $volume->url,
                    'settings' => $volume->settings
                ]);
                $convertedVolume->setFieldLayout($volume->getFieldLayout());

                if (!$volumesService->saveVolume($convertedVolume)) {
                    throw new VolumeException('Unable to convert the legacy “{volume}” Amazon S3 volume.', ['volume' => $volume->name]);
                }
            }
        }
    }
}
