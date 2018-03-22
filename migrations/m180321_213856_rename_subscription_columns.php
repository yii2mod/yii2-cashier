<?php

use yii\db\Migration;

/**
 * Class m180321_213856_rename_subscription_columns
 */
class m180321_213856_rename_subscription_columns extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->renameColumn('subscriptions', 'userId', 'user_id');
        $this->renameColumn('subscriptions', 'stripeId', 'stripe_id');
        $this->renameColumn('subscriptions', 'stripePlan', 'stripe_plan');
        $this->renameColumn('subscriptions', 'trialEndAt', 'trial_ends_at');
        $this->renameColumn('subscriptions', 'endAt', 'ends_at');
        $this->renameColumn('subscriptions', 'createdAt', 'created_at');
        $this->renameColumn('subscriptions', 'updatedAt', 'updated_at');

        if (Yii::$app->db->schema->getTableSchema('user')) {
            $this->renameColumn('user', 'stripeId', 'stripe_id');
            $this->renameColumn('user', 'cardBrand', 'card_brand');
            $this->renameColumn('user', 'cardLastFour', 'card_last_four');
            $this->renameColumn('user', 'trialEndAt', 'trial_ends_at');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->renameColumn('subscriptions', 'user_id', 'userId');
        $this->renameColumn('subscriptions', 'stripe_id', 'stripeId');
        $this->renameColumn('subscriptions', 'stripe_plan', 'stripePlan');
        $this->renameColumn('subscriptions', 'trial_ends_at', 'trialEndAt');
        $this->renameColumn('subscriptions', 'ends_at', 'endAt');
        $this->renameColumn('subscriptions', 'created_at', 'createdAt');
        $this->renameColumn('subscriptions', 'updated_at', 'updatedAt');

        if (Yii::$app->db->schema->getTableSchema('user')) {
            $this->renameColumn('user', 'stripe_id', 'stripeId');
            $this->renameColumn('user', 'card_brand', 'cardBrand');
            $this->renameColumn('user', 'card_last_four', 'cardLastFour');
            $this->renameColumn('user', 'trial_ends_at', 'trialEndAt');
        }
    }
}
