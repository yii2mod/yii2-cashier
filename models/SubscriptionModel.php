<?php

namespace yii2mod\cashier\models;

use Carbon\Carbon;
use DateTimeInterface;
use LogicException;
use Yii;
use yii\db\ActiveRecord;

// OLD lib
// from composer.json => "yii2mod/yii2-behaviors": "~2.0",
// use yii2mod\behaviors\CarbonBehavior;

use yii2mod\cashier\behaviors\CarbonBehavior;

/**
 * This is the model class for table "subscriptions".
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $stripe_id
 * @property string $stripe_plan
 * @property int $metadata_id
 * @property string $client_reference_id
 * @property int $quantity
 * @property int $cancel_at_period_end
 * @property int $current_period_end
 * @property Carbon $trial_ends_at
 * @property Carbon $ends_at
 * @property int $created_at
 * @property int $updated_at
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
    public static function tableName(): string
    {
        return 'subscriptions';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['user_id', 'name', 'stripe_id', 'stripe_plan', 'quantity'], 'required'], 
            [['user_id', 'quantity', 'metadata_id', 'cancel_at_period_end'], 'integer'],
            [['trial_ends_at', 'ends_at', 'current_period_end'], 'safe'],
            [['name', 'stripe_id', 'stripe_plan', 'client_reference_id', 'status'], 'string', 'max' => 255],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'name' => Yii::t('app', 'Name'),
            'stripe_id' => Yii::t('app', 'Stripe ID'),
            'stripe_plan' => Yii::t('app', 'Stripe Plan'),
            'status' => Yii::t('app', 'Status'),
            'metadata_id' => Yii::t('app', 'Metadata ID'),
            'client_reference_id' => Yii::t('app', 'Client reference ID'),
            'quantity' => Yii::t('app', 'Quantity'),
            'cancel_at_period_end' => Yii::t('app', 'Cancel at period end'),
            'current_period_end' => Yii::t('app', 'Current period end date'),
            'trial_ends_at' => Yii::t('app', 'Trial End At'),
            'ends_at' => Yii::t('app', 'End At'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'timestamp' => [
                'class' => 'yii\behaviors\TimestampBehavior',
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => function () {
                    return Carbon::now()->toDateTimeString();
                },
            ],
            'carbon' => [
                'class' => CarbonBehavior::class,
                'attributes' => [
                    'trial_ends_at',
                    'ends_at',
                ],
            ],
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(Yii::$app->user->identityClass, ['id' => 'user_id']);
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active(): bool
    {
        return is_null($this->ends_at) || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled(): bool
    {
        return !is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial(): bool
    {
        if (!is_null($this->trial_ends_at)) {
            return Carbon::today()->lt($this->trial_ends_at);
        } else {
            return false;
        }
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod(): bool
    {
        if (!is_null($endAt = $this->ends_at)) {
            return Carbon::now()->lt(Carbon::instance($endAt));
        } else {
            return false;
        }
    }

    /**
     * Increment the quantity of the subscription.
     *
     * @param int $count
     *
     * @return $this
     */
    public function incrementQuantity(int $count = 1)
    {
        $this->updateQuantity($this->quantity + $count);

        return $this;
    }

    /**
     *  Increment the quantity of the subscription, and invoice immediately.
     *
     * @param int $count
     *
     * @return $this
     */
    public function incrementAndInvoice(int $count = 1)
    {
        $this->incrementQuantity($count);
        $this->user->invoice();

        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param int $count
     *
     * @return $this
     */
    public function decrementQuantity(int $count = 1)
    {
        $this->updateQuantity(max(1, $this->quantity - $count));

        return $this;
    }

    /**
     * Update the quantity of the subscription.
     *
     * @param int $quantity
     *
     * @return $this
     */
    public function updateQuantity(int $quantity)
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
     * @param int|string $date
     *
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
     * @param string $plan
     *
     * @return $this
     */
    public function swap(string $plan)
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
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
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

        $this->stripe_plan = $plan;
        $this->ends_at = null;
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

        $subscription->cancel_at_period_end = true;
        $subscription->save();

        // If the user was on trial, we will set the grace period to end when the trial
        // would have ended. Otherwise, we'll retrieve the end of the billing period
        // period and make that the end of the grace period for this current user.
        if ($this->onTrial()) {
            $this->ends_at = $this->trial_ends_at;
        } else {
            $this->ends_at = Carbon::createFromTimestamp(
                $subscription->current_period_end
            );
        }

        $this->cancel_at_period_end = (int)$subscription->cancel_at_period_end;
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
     */
    public function markAsCancelled(): void
    {
        $this->ends_at = Carbon::now();
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
        $subscription->cancel_at_period_end = false;

        // To resume the subscription we need to set the plan parameter on the Stripe
        // subscription object. This will force Stripe to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $subscription->plan = $this->stripe_plan;

        if ($this->onTrial()) {
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        $subscription->save();

        // Finally, we will remove the ending timestamp from the user's record in the
        // local database to indicate that the subscription is active again and is
        // no longer "cancelled". Then we will save this record in the database.
        $this->ends_at = null;

        $current_period_end = null;
        if($subscription->current_period_end!=null){
            $current_period_end = date("Y-m-d H:m:s", $subscription->current_period_end);
        }
        $this->current_period_end = $current_period_end;
        $this->cancel_at_period_end = (int)$subscription->cancel_at_period_end;
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
        return $this->user->asStripeCustomer()->subscriptions->retrieve($this->stripe_id);
    }
}
