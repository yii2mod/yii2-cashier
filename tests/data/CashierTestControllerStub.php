<?php

namespace bigdropinc\cashier\tests\data;

use bigdropinc\cashier\controllers\WebhookController;

/**
 * Class CashierTestControllerStub
 *
 * @package bigdropinc\cashier\tests\data
 */
class CashierTestControllerStub extends WebhookController
{
    protected function eventExistsOnStripe($id)
    {
        return true;
    }
}
