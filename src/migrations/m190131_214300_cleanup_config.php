<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Fs;
use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Json;
use craft\services\ProjectConfig;

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
    public function safeUp(): bool
    {
        // Cleanup failed conversions
        $this->_convertVolumes();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
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

        $schemaVersion = $projectConfig->get('plugins.aws-s3.schemaVersion', true);
        $projectConfig->muteEvents = true;

        $volumes = $projectConfig->get(ProjectConfig::PATH_FILESYSTEMS, true) ?? [];

        foreach ($volumes as $uid => &$volume) {
            if ($volume['type'] === Fs::class && !empty($volume['settings']) && is_array($volume['settings']) && array_key_exists('urlPrefix', $volume['settings'])) {
                $settings = $volume['settings'];

                $hasUrls = !empty($volume['hasUrls']);
                $url = ($hasUrls && !empty($settings['urlPrefix'])) ? $settings['urlPrefix'] : null;
                //$settings['region'] = $settings['location'];
                unset($settings['urlPrefix'], $settings['location'], $settings['storageClass']);

                $volume['url'] = $url;
                $volume['settings'] = $settings;

                $this->update(Table::FILESYSTEMS, [
                    'settings' => Json::encode($settings),
                    'url' => $url,
                ], ['uid' => $uid]);

                // If project config schema up to date, don't update project config
                if (!version_compare($schemaVersion, '1.1', '>=')) {
                    $projectConfig->set(ProjectConfig::PATH_FILESYSTEMS . '.' . $uid, $volume);
                }
            }
        }

        $projectConfig->muteEvents = false;
    }
}
