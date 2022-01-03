<?php

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Fs;
use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Json;
use craft\services\ProjectConfig;

/**
 * m180929_165000_remove_storageclass_setting migration.
 */
class m180929_165000_remove_storageclass_setting extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Don't make the same config changes twice
        $schemaVersion = $projectConfig->get('plugins.aws-s3.schemaVersion', true);
        if (version_compare($schemaVersion, '1.1', '>=')) {
            return true;
        }

        $projectConfig->muteEvents = true;
        $volumes = $projectConfig->get(ProjectConfig::PATH_FILESYSTEMS) ?? [];

        foreach ($volumes as $uid => &$volume) {
            if ($volume['type'] === Fs::class && isset($volume['settings']) && is_array($volume['settings'])) {
                unset($volume['settings']['storageClass']);

                $this->update(Table::FILESYSTEMS, [
                    'settings' => Json::encode($volume['settings']),
                ], ['uid' => $uid]);

                $projectConfig->set(ProjectConfig::PATH_FILESYSTEMS . '.' . $uid, $volume);
            }
        }

        $projectConfig->muteEvents = false;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m180929_165000_remove_storageclass_setting cannot be reverted.\n";
        return false;
    }
}
