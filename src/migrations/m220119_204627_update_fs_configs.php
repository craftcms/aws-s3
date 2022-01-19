<?php

namespace craft\awss3\migrations;

use Craft;
use craft\db\Migration;

/**
 * m220119_204627_update_fs_configs migration.
 */
class m220119_204627_update_fs_configs extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Don't make the same changes twice
        $schemaVersion = Craft::$app->getProjectConfig()->get('plugins.aws-s3.schemaVersion', true);
        if (version_compare($schemaVersion, '2.0', '>=')) {
            return true;
        }

        // Just re-run the install migration
        (new Install())->safeUp();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m220119_204627_update_fs_configs cannot be reverted.\n";
        return false;
    }
}
