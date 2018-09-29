<?php

namespace craft\awss3\migrations;

use craft\awss3\Volume;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;

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
        $volumes = (new Query())
            ->select([
                'id',
                'settings',
            ])
            ->where(['type' => Volume::class])
            ->from(['{{%volumes}}'])
            ->all();


        foreach ($volumes as $volume) {
            $settings = Json::decode($volume['settings']);

            if ($settings !== null) {
                unset($settings['storageClass']);
                $settings = Json::encode($settings);
                $this->update('{{%volumes}}', ['settings' => $settings], ['id' => $volume['id']]);
            }
        }
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
