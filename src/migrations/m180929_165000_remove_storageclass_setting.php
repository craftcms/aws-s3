<?php

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Volume;
use craft\db\Migration;
use craft\db\Query;
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
    public function safeUp()
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->muteEvents = true;

        foreach ($projectConfig->get(Volumes::CONFIG_VOLUME_KEY) as $uid => &$volume) {
            if ($volume['type'] === Volume::class && isset($volume['settings']) && is_array($volume['settings'])) {
                unset($volume['settings']['storageClass']);

                $this->update('{{%volumes}}', [
                    'settings' => Json::encode($volume['settings']),
                ], ['uid' => $uid]);

                $projectConfig->set(Volumes::CONFIG_VOLUME_KEY . '.' . $uid, $volume);
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
