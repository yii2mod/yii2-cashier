<?php

use yii\db\Migration;

/**
 * Class m191209_213759_add_attributes_to_subscription_table
 */
class m191209_213759_add_attributes_to_subscription_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('subscriptions', 'client_reference_id', $this->string()->after('stripe_plan') );
        $this->addColumn('subscriptions', 'status', $this->string()->after('stripe_plan') );
        $this->addColumn('subscriptions', 'metadata_id', $this->integer()->after('status') );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('subscriptions', 'client_reference_id');
        $this->dropColumn('subscriptions', 'status');
        $this->dropColumn('subscriptions', 'metadata_id');
    }
}
