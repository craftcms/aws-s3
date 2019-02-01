<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Volume;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use craft\services\Volumes;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.2
 */
class m190131_214300_cleanup_config extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Cleanup failed conversions
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
     */
    private function _convertVolumes()
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->muteEvents = true;

        foreach ($projectConfig->get(Volumes::CONFIG_VOLUME_KEY) as $uid => &$volume) {
            if ($volume['type'] === Volume::class && !empty($volume['settings']) && is_array($volume['settings']) && array_key_exists('urlPrefix ', $volume['settings'])) {
                $settings = $volume['settings'];

                $hasUrls = !empty($volume['hasUrls']);
                $url = ($hasUrls && !empty($settings['urlPrefix'])) ? $settings['urlPrefix'] : null;
                $settings['region'] = $settings['location'];
                unset($settings['urlPrefix'], $settings['location'], $settings['storageClass']);

                $volume['url'] = $url;
                $volume['settings'] = $settings;

                $this->update('{{%volumes}}', [
                    'settings' => Json::encode($settings),
                    'url' => $url,
                ], ['uid' => $uid]);

                $projectConfig->set(Volumes::CONFIG_VOLUME_KEY . '.' . $uid, $volume);
            }
        }

        $projectConfig->muteEvents = false;
    }
}
