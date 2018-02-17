<?php

namespace craft\awss3;

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
    }
}
