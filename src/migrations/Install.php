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
        // Update any old S3 configs
        $projectConfig = Craft::$app->getProjectConfig();
        $fsConfigs = $projectConfig->get(ProjectConfig::PATH_FS) ?? [];

        foreach ($fsConfigs as $uid => $config) {
            if (
                in_array($config['type'], ['craft\awss3\Volume', Fs::class]) &&
                isset($config['settings']) &&
                is_array($config['settings'])
            ) {
                $config['type'] = Fs::class;
                $settings = &$config['settings'];

                if (array_key_exists('urlPrefix', $settings)) {
                    $config['url'] = (($config['hasUrls'] ?? false) && $settings['urlPrefix']) ? $settings['urlPrefix'] : null;
                }

                if (array_key_exists('location', $settings)) {
                    $config['region'] = $settings['location'];
                }

                if (
                    isset($settings['expires']) &&
                    preg_match('/^([\d]+)([a-z]+)$/', $settings['expires'], $matches)
                ) {
                    $settings['expires'] = sprintf('%s %s', $matches[1], $matches[2]);
                }

                unset($settings['urlPrefix'], $settings['location'], $settings['storageClass']);
                $projectConfig->set(sprintf('%s.%s', ProjectConfig::PATH_FS, $uid), $config);
            }
        }

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
