<?php

use yii\db\Migration;

/**
 * Class m191219_213759_add_more_attributes_to_subscription_table
 */
class m191219_213759_add_more_attributes_to_subscription_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('subscriptions', 'cancel_at_period_end', $this->integer()->after('quantity')->defaultValue(0) );        
        $this->addColumn('subscriptions', 'current_period_end', $this->timestamp()->after('cancel_at_period_end')->null() );        
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('subscriptions', 'cancel_at_period_end');
        $this->dropColumn('subscriptions', 'current_period_end');
    }
}
