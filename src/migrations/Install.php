<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\awss3\migrations;

use Craft;
use craft\awss3\Volume;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Convert any built-in S3 volumes to ours
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
        $volumes = (new Query())
            ->select([
                'id',
                'fieldLayoutId',
                'settings',
            ])
            ->where(['type' => 'craft\volumes\AwsS3'])
            ->from(['{{%volumes}}'])
            ->all();

        $dbConnection = Craft::$app->getDb();

        foreach ($volumes as $volume) {

            $settings = Json::decode($volume['settings']);

            if ($settings !== null) {
                $hasUrls = !empty($settings['publicURLs']);
                $url = ($hasUrls && !empty($settings['urlPrefix'])) ? $settings['urlPrefix'] : null;
                $settings['region'] = $settings['location'];
                unset($settings['publicURLs'], $settings['urlPrefix'], $settings['location']);

                $values = [
                    'type' => Volume::class,
                    'hasUrls' => $hasUrls,
                    'url' => $url,
                    'settings' => Json::encode($settings)
                ];

                $dbConnection->createCommand()
                    ->update('{{%volumes}}', $values, ['id' => $volume['id']])
                    ->execute();
            }

        }
    }
}
