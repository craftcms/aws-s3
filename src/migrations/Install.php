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
 * @since 1.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Convert any built-in S3 volumes to ours
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
     * Converts any old school S3 filesystems to a filesystem
     *
     * @return void
     */
    private function _convertVolumes()
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->muteEvents = true;

        $volumes = $projectConfig->get(ProjectConfig::PATH_FILESYSTEMS) ?? [];

        foreach ($volumes as $uid => &$volume) {
            if ($volume['type'] === Fs::class && isset($volume['settings']) && is_array($volume['settings'])) {
                $settings = $volume['settings'];

                // This is not a legacy S3 filesystem
                if (empty($settings['location'])) {
                    continue;
                }

                $hasUrls = !empty($volume['hasUrls']);
                $url = ($hasUrls && !empty($settings['urlPrefix'])) ? $settings['urlPrefix'] : null;
                $settings['region'] = $settings['location'];
                unset($settings['urlPrefix'], $settings['location'], $settings['storageClass']);

                if (array_key_exists('expires', $settings) && preg_match('/^([\d]+)([a-z]+)$/', $settings['expires'], $matches)) {
                    $settings['expires'] = $matches[1] . ' ' . $matches[2];
                }

                $volume['url'] = $url;
                $volume['settings'] = $settings;

                $this->update(Table::FILESYSTEMS, [
                    'settings' => Json::encode($settings),
                    'url' => $url,
                ], ['uid' => $uid]);

                $projectConfig->set(ProjectConfig::PATH_FILESYSTEMS . '.' . $uid, $volume);
            }
        }

        $projectConfig->muteEvents = false;
    }
}
