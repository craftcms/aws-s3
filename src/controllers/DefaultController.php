<?php

namespace craft\awss3\controllers;

use Craft;
use craft\awss3\Volume;
use craft\web\Controller as BaseController;
use yii\web\Response;

/**
 * This controller provides functionality to load data from AWS.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class DefaultController extends BaseController
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->defaultAction = 'load-bucket-data';
    }

    /**
     * Load bucket data for specified credentials.
     *
     * @return Response
     */
    public function actionLoadBucketData()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $keyId = $request->getRequiredBodyParam('keyId');
        $secret = $request->getRequiredBodyParam('secret');

        try {
            return $this->asJson(Volume::loadBucketList($keyId, $secret));
        } catch (\Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }
    }
}
