<?php

namespace craft\awss3\controllers;

use Craft;
use craft\awss3\Fs;
use craft\helpers\App;
use craft\web\Controller as BaseController;
use yii\web\Response;

/**
 * This controller provides functionality to load data from AWS.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class BucketsController extends BaseController
{
    public $defaultAction = 'load-bucket-data';

    /**
     * Load bucket data for specified credentials.
     *
     * @return Response
     */
    public function actionLoadBucketData(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $keyId = App::parseEnv($request->getBodyParam('keyId'));
        $secret = App::parseEnv($request->getBodyParam('secret'));
        $region = App::parseEnv($request->getBodyParam('region'));
        $endpoint = App::parseEnv($request->getBodyParam('endpoint'));

        try {
            return $this->asJson([
                'buckets' => Fs::loadBucketList($keyId, $secret, $region, $endpoint),
            ]);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }
}
