<?php

namespace craft\awss3;

use craft\base\Element;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Volumes;
use yii\base\Event;


/**
 * Plugin represents the Amazon S3 volume plugin.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Plugin extends \craft\base\Plugin
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public ?string $schemaVersion = '1.2';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(Volumes::class, Volumes::EVENT_REGISTER_VOLUME_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Volume::class;
        });

        Event::on(Asset::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            if (!$event->isNew) {
                return;
            }

            /** @var Asset $asset */
            $asset = $event->sender;

            /** @var Volume $volume */
            $volume = $asset->getVolume();

            if (!$volume instanceof Volume) {
                return;
            }

            if (!$volume->autoFocalPoint) {
                return;
            }

            $fullPath = (!empty($volume->subfolder) ? rtrim($volume->subfolder, '/') . '/' : '') . $asset->getPath();

            $focalPoint = $volume->detectFocalPoint($fullPath);

            if (!empty($focalPoint)) {
                $assetRecord = \craft\records\Asset::findOne($asset->id);
                $assetRecord->focalPoint = min(max($focalPoint[0], 0), 1) . ';' . min(max($focalPoint[1], 0), 1);
                $assetRecord->save();
            }
        });
    }
}
