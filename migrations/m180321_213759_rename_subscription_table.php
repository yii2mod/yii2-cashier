<?php

use yii\db\Migration;

/**
 * Class m180321_213759_rename_subscription_table
 */
class m180321_213759_rename_subscription_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->renameTable('subscription', 'subscriptions');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->renameTable('subscriptions', 'subscription');
    }
}
