<?php

namespace craft\awss3;

use Craft;
use craft\errors\VolumeException;
use craft\events\RegisterComponentTypesEvent;


/**
 * Plugin represents the Amazon S3 volume plugin.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */

class Plugin extends \craft\base\Plugin
{
    // Public Methods
    // =========================================================================
    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Craft::$app->getVolumes()->on('registerVolumeTypes', function(RegisterComponentTypesEvent $event) {
            $event->types[] = Volume::class;
        });
    }

    /**
     * Convert the legacy Amazon S3 volumes
     *
     * @throws VolumeException
     * @return void
     */
    public function afterInstall()
    {
        $volumes = Craft::$app->getVolumes();
        $allVolumes = $volumes->getAllVolumes();

        foreach ($allVolumes as $volume) {
            /** @var Volume $volume */
            if ($volume->className() == 'craft\volumes\MissingVolume' && $volume->expectedType == 'craft\volumes\AwsS3') {
                /** @var Volume $convertedVolume */
                $convertedVolume = $volumes->createVolume([
                    'id' => $volume->id,
                    'type' => Volume::class,
                    'name' => $volume->name,
                    'handle' => $volume->handle,
                    'hasUrls' => $volume->hasUrls,
                    'url' => $volume->url,
                    'settings' => $volume->settings
                ]);
                $convertedVolume->setFieldLayout($volume->getFieldLayout());

                if (!$volumes->saveVolume($convertedVolume)) {
                   throw new VolumeException('Unable to convert the legacy “{volume}” Amazon S3 volume.', ['volume' => $volume->name]);
                }
            }
        }
    }
}
