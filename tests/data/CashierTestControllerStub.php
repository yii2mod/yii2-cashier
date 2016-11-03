<?php

namespace yii2mod\cashier\tests\data;

use yii2mod\cashier\controllers\WebhookController;

/**
 * Class CashierTestControllerStub
 * @package yii2mod\cashier\tests\data
 */
class CashierTestControllerStub extends WebhookController
{
    protected function eventExistsOnStripe($id)
    {
        return true;
    }
}