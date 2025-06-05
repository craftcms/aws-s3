<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\awss3;

use craft\base\Element;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use craft\services\Fs as FsService;
use yii\base\Event;

/**
 * Plugin represents the Amazon S3 filesystem.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class Plugin extends \craft\base\Plugin
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '2.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(FsService::class, FsService::EVENT_REGISTER_FILESYSTEM_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Fs::class;
        });

        Event::on(
            Assets::class,
            Assets::EVENT_BEFORE_REPLACE_ASSET,
            function(ReplaceAssetEvent $event) {
                $asset = $event->asset;
                $fs = $asset->getVolume()->getFs();

                if (!$fs instanceof Fs) {
                    return;
                }

                $oldFilename = $asset->getFilename();
                $newFilename = $event->filename;

                // when replacing asset with another one with the same filename, invalidate the cdn path for the original file too
                // see https://github.com/craftcms/aws-s3/issues/184 for details
                if ($oldFilename === $newFilename) {
                    $fs->invalidateCdnPath($asset->getPath());
                }
            }
        );

        Event::on(Asset::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            if (!$event->isNew) {
                return;
            }

            /** @var Asset $asset */
            $asset = $event->sender;
            $volume = $asset->getVolume();
            $filesystem = $volume->getFs();

            if (!$filesystem instanceof Fs || !$filesystem->autoFocalPoint) {
                return;
            }

            $fullPath = (!empty($filesystem->subfolder) ? rtrim($filesystem->subfolder, '/') . '/' : '') .
                (method_exists($volume, 'getSubpath') ? $volume->getSubpath() : '') .
                $asset->getPath();

            $focalPoint = $filesystem->detectFocalPoint($fullPath);

            if (!empty($focalPoint)) {
                $assetRecord = \craft\records\Asset::findOne($asset->id);
                $assetRecord->focalPoint = min(max($focalPoint[0], 0), 1) . ';' . min(max($focalPoint[1], 0), 1);
                $assetRecord->save();
            }
        });
    }
}
