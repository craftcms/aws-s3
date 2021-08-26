<?php

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Volume;
use craft\db\Migration;
use craft\helpers\Json;
use craft\services\Volumes;

/**
 * m180929_165000_remove_storageclass_setting migration.
 */
class m180929_165000_remove_storageclass_setting extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Don't make the same config changes twice
        $schemaVersion = $projectConfig->get('plugins.aws-s3.schemaVersion', true);
        if (version_compare($schemaVersion, '1.1', '>=')) {
            return true;
        }

        $projectConfig->muteEvents = true;
        $volumes = $projectConfig->get(Volumes::CONFIG_VOLUME_KEY) ?? [];

        foreach ($volumes as $uid => &$volume) {
            if ($volume['type'] === Volume::class && isset($volume['settings']) && is_array($volume['settings'])) {
                unset($volume['settings']['storageClass']);

                $this->update('{{%volumes}}', [
                    'settings' => Json::encode($volume['settings']),
                ], ['uid' => $uid]);

                $projectConfig->set(Volumes::CONFIG_VOLUME_KEY . '.' . $uid, $volume);
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
        echo "m180929_165000_remove_storageclass_setting cannot be reverted.\n";
        return false;
    }
}
