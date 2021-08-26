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
use craft\helpers\Json;
use craft\services\Volumes;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.2
 */
class m190305_133000_cleanup_expires_config extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Cleanup failed conversions
        $projectConfig = Craft::$app->getProjectConfig();

        $schemaVersion = $projectConfig->get('plugins.aws-s3.schemaVersion', true);
        $projectConfig->muteEvents = true;

        $volumes = $projectConfig->get(Volumes::CONFIG_VOLUME_KEY, true) ?? [];

        foreach ($volumes as $uid => &$volume) {
            if ($volume['type'] === Volume::class && !empty($volume['settings']) && is_array($volume['settings']) && array_key_exists('expires', $volume['settings'])) {
                if (preg_match('/^([\d]+)([a-z]+)$/', $volume['settings']['expires'], $matches)) {
                    $volume['settings']['expires'] = $matches[1] . ' ' . $matches[2];

                    $this->update('{{%volumes}}', [
                        'settings' => Json::encode($volume['settings'])
                    ], ['uid' => $uid]);

                    // If project config schema up to date, don't update project config
                    if (!version_compare($schemaVersion, '1.2', '>=')) {
                        $projectConfig->set(Volumes::CONFIG_VOLUME_KEY . '.' . $uid, $volume);
                    }
                }
            }
        }

        $projectConfig->muteEvents = false;

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        return true;
    }
}
