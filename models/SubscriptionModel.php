<?php

namespace yii2mod\cashier\models;

use Carbon\Carbon;
use DateTimeInterface;
use LogicException;
use Yii;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii2mod\behaviors\CarbonBehavior;

/**
 * This is the model class for table "Subscription".
 *
 * @property integer $id
 * @property integer $userId
 * @property string $name
 * @property string $stripeId
 * @property string $stripePlan
 * @property integer $quantity
 * @property Carbon $trialEndAt
 * @property Carbon $endAt
 * @property integer $createdAt
 * @property integer $updatedAt
 *
 * @property \yii\db\ActiveRecord $user
 */
class SubscriptionModel extends ActiveRecord
{
    /**
     * Indicates if the plan change should be prorated.
     *
     * @var bool
     */
    protected $prorate = true;

    /**
     * The date on which the billing cycle should be anchored.
     *
     * @var string|null
     */
    protected $billingCycleAnchor = null;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'Subscription';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['userId', 'name', 'stripeId', 'stripePlan', 'quantity'], 'required'],
            [['userId', 'quantity'], 'integer'],
            [['trialEndAt', 'endAt'], 'safe'],
            [['name', 'stripeId', 'stripePlan'], 'string', 'max' => 255],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'userId' => Yii::t('app', 'User ID'),
            'name' => Yii::t('app', 'Name'),
            'stripeId' => Yii::t('app', 'Stripe ID'),
            'stripePlan' => Yii::t('app', 'Stripe Plan'),
            'quantity' => Yii::t('app', 'Quantity'),
            'trialEndAt' => Yii::t('app', 'Trial End At'),
            'endAt' => Yii::t('app', 'End At'),
            'createdAt' => Yii::t('app', 'Created At'),
            'updatedAt' => Yii::t('app', 'Updated At'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => 'yii\behaviors\TimestampBehavior',
                'createdAtAttribute' => 'createdAt',
                'updatedAtAttribute' => 'updatedAt',
                'value' => function () {
                    $currentDateExpression = Yii::$app->db->getDriverName() === 'sqlite' ? "DATETIME('now')" : 'NOW()';

                    return new Expression($currentDateExpression);
                }
            ],
            'carbon' => [
                'class' => CarbonBehavior::className(),
                'attributes' => [
                    'trialEndAt',
                    'endAt'
                ],
            ],
        ];
    }

    /**
     * User relation
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(Yii::$app->user->identityClass, ['id' => 'userId']);
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid()
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active()
    {
        return is_null($this->endAt) || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return !is_null($this->endAt);
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (!is_null($this->trialEndAt)) {
            return Carbon::today()->lt($this->trialEndAt);
        } else {
            return false;
        }
    }


    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        if (!is_null($endAt = $this->endAt)) {
            return Carbon::now()->lt(Carbon::instance($endAt));
        } else {
            return false;
        }
    }

    /**
     * Increment the quantity of the subscription.
     *
     * @param int $count
     * @return $this
     */
    public function incrementQuantity($count = 1)
    {
        $this->updateQuantity($this->quantity + $count);
        return $this;
    }

    /**
     *  Increment the quantity of the subscription, and invoice immediately.
     *
     * @param int $count
     * @return $this
     */
    public function incrementAndInvoice($count = 1)
    {
        $this->incrementQuantity($count);
        $this->user->invoice();
        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param int $count
     * @return $this
     */
    public function decrementQuantity($count = 1)
    {
        $this->updateQuantity(max(1, $this->quantity - $count));
        return $this;
    }

    /**
     * Update the quantity of the subscription.
     *
     * @param int $quantity
     * @return $this
     */
    public function updateQuantity($quantity)
    {
        $subscription = $this->asStripeSubscription();
        $subscription->quantity = $quantity;
        $subscription->prorate = $this->prorate;
        $subscription->save();

        $this->quantity = $quantity;
        $this->save();

        return $this;
    }

    /**
     * Indicate that the plan change should not be prorated.
     *
     * @return $this
     */
    public function noProrate()
    {
        $this->prorate = false;

        return $this;
    }

    /**
     * Change the billing cycle anchor on a plan change.
     *
     * @param  int|string $date
     * @return $this
     */
    public function anchorBillingCycleOn($date = 'now')
    {
        if ($date instanceof DateTimeInterface) {
            $date = $date->getTimestamp();
        }

        $this->billingCycleAnchor = $date;

        return $this;
    }

    /**
     * Swap the subscription to a new Stripe plan.
     *
     * @param  string $plan
     * @return $this
     */
    public function swap($plan)
    {
        $subscription = $this->asStripeSubscription();

        $subscription->plan = $plan;

        $subscription->prorate = $this->prorate;

        if (!is_null($this->billingCycleAnchor)) {
            $subscription->billingCycleAnchor = $this->billingCycleAnchor;
        }

        // If no specific trial end date has been set, the default behavior should be
        // to maintain the current trial state, whether that is "active" or to run
        // the swap out with the exact number of days left on this current plan.
        if ($this->onTrial()) {
            $subscription->trial_end = $this->trialEndAt->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        // Again, if no explicit quantity was set, the default behaviors should be to
        // maintain the current quantity onto the new plan. This is a sensible one
        // that should be the expected behavior for most developers with Stripe.
        if ($this->quantity) {
            $subscription->quantity = $this->quantity;
        }

        $subscription->save();

        $this->user->invoice();

        $this->stripePlan = $plan;
        $this->endAt = null;
        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription at the end of the billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->cancel(['at_period_end' => true]);

        // If the user was on trial, we will set the grace period to end when the trial
        // would have ended. Otherwise, we'll retrieve the end of the billing period
        // period and make that the end of the grace period for this current user.
        if ($this->onTrial()) {
            $this->endAt = $this->trialEndAt;
        } else {
            $this->endAt = Carbon::createFromTimestamp(
                $subscription->current_period_end
            );
        }

        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->cancel();

        $this->markAsCancelled();

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->endAt = Carbon::now();
        $this->save();
    }

    /**
     * Resume the cancelled subscription.
     *
     * @return $this
     *
     * @throws LogicException
     */
    public function resume()
    {
        if (!$this->onGracePeriod()) {
            throw new LogicException('Unable to resume subscription that is not within grace period.');
        }

        $subscription = $this->asStripeSubscription();

        // To resume the subscription we need to set the plan parameter on the Stripe
        // subscription object. This will force Stripe to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $subscription->plan = $this->stripePlan;

        if ($this->onTrial()) {
            $subscription->trial_end = $this->trialEndAt->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        $subscription->save();

        // Finally, we will remove the ending timestamp from the user's record in the
        // local database to indicate that the subscription is active again and is
        // no longer "cancelled". Then we will save this record in the database.
        $this->endAt = null;
        $this->save();

        return $this;
    }


    /**
     * Get the subscription as a Stripe subscription object.
     *
     * @return \Stripe\Subscription
     */
    public function asStripeSubscription()
    {
        return $this->user->asStripeCustomer()->subscriptions->retrieve($this->stripeId);
    }

}