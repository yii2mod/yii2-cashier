<?php

namespace yii2mod\cashier;

use Yii;
use Carbon\Carbon;
use yii\base\Exception;
use yii2mod\cashier\models\SubscriptionModel;

/**
 * Class SubscriptionBuilder
 *
 * @package yii2mod\cashier
 */
class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \yii\db\ActiveRecord
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The quantity of the subscription.
     *
     * @var int
     */
    protected $quantity = 1;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array|null
     */
    protected $metadata;

    /**
     * Create a new subscription builder instance.
     *
     * @param mixed $user
     * @param string $name
     * @param string $plan
     */
    public function __construct($user, $name, $plan)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
    }

    /**
     * Specify the quantity of the subscription.
     *
     * @param int $quantity
     *
     * @return $this
     */
    public function quantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param int $trialDays
     *
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param string $coupon
     *
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * The metadata to apply to a new subscription.
     *
     * @param array $metadata
     *
     * @return $this
     */
    public function withMetadata($metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Add a new Stripe subscription to the user.
     *
     * @param array $options
     *
     * @return SubscriptionModel
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Stripe subscription.
     *
     * @param string|null $token
     * @param array $options
     *
     * @return SubscriptionModel
     *
     * @throws Exception
     */
    public function create($token = null, array $options = [])
    {
        $customer = $this->getStripeCustomer($token, $options);
        $subscription = $customer->subscriptions->create($this->buildPayload());
        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }
        $subscriptionModel = new SubscriptionModel([
            'user_id' => $this->user->id,
            'name' => $this->name,
            'stripe_id' => $subscription->id,
            'stripe_plan' => $this->plan,
            'quantity' => $this->quantity,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ]);
        if ($subscriptionModel->save()) {
            return $subscriptionModel;
        } else {
            throw new Exception('Subscription was not saved.');
        }
    }

    /**
     * Loads or updates an Stripe subscription.
     *
     * @param string $subscriptionID
     *
     * @return SubscriptionModel
     *
     * @throws Exception
     */
    public function loadSubscriptionModel($subscriptionID, $clientReferenceId=null)
    {
        $customer = $this->getStripeCustomer();
        $stripeSubscription = \Stripe\Subscription::retrieve($subscriptionID, Yii::$app->params['stripe']['apiKey']);
        return $this->updateSubscriptionModel($stripeSubscription, $clientReferenceId);
    }

    /**
     * Loads or updates an Stripe subscription.
     *
     * @param string $subscriptionID
     *
     * @return SubscriptionModel
     *
     * @throws Exception
     */
    public function updateSubscriptionModel($stripeSubscription, $clientReferenceId=null)
    {
        // precalculate attributes
        $trial_ends_at = null;
        if($stripeSubscription->trial_end!=null){
            $trial_ends_at = date("Y-m-d H:m:s", $stripeSubscription->trial_end);
        }
    
        $ends_at = null;
        if($stripeSubscription->current_period_end!=null){
            $ends_at = date("Y-m-d H:m:s", $stripeSubscription->current_period_end);
        }

        $metadataMap = $this->user->billableMapMetadataAttributes();
        $metadata_id = null;
        if(array_key_exists('metadata_id', $metadataMap) && $stripeSubscription->metadata->offsetExists($metadataMap['metadata_id'])){
            $metadata_id = $stripeSubscription->metadata->{$metadataMap['metadata_id']};
        }
        
        // instace creation or update
        $subscriptionModel = SubscriptionModel::find()->where(['stripe_id' => $stripeSubscription->id])->one();
        if($subscriptionModel==null){
            $subscriptionModel = new SubscriptionModel();
        }
        else{
            $clientReferenceId = $subscriptionModel->client_reference_id;
        }

        $subscriptionModel->setAttributes([
            'user_id' => $this->user->id,
            'name' => $stripeSubscription->plan->product,
            'stripe_id' => $stripeSubscription->id,
            'stripe_plan' => $stripeSubscription->plan->id,
            'status' => $stripeSubscription->status,
            'metadata_id' => $metadata_id,
            'client_reference_id' => $clientReferenceId,
            'quantity' => $stripeSubscription->quantity,
            'trial_ends_at' => $trial_ends_at,
            'ends_at' => $ends_at,
        ]);
        if ($subscriptionModel->save()) {
            return $subscriptionModel;
        } else {
            throw new Exception('Subscription was not saved.');
        }
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param string|null $token
     * @param array $options
     *
     * @return \Stripe\Customer
     */
    protected function getStripeCustomer($token = null, array $options = [])
    {
        if (!$this->user->stripe_id) {
            $customer = $this->user->createAsStripeCustomer(
                $token, array_merge($options, array_filter(['coupon' => $this->coupon]))
            );
        } else {
            $customer = $this->user->asStripeCustomer();
            if ($token) {
                $this->user->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Build the payload for subscription creation.
     *
     * @return array
     */
    public function buildPayload()
    {
        return array_filter([
            'plan' => $this->plan,
            'quantity' => $this->quantity,
            'coupon' => $this->coupon,
            'trial_end' => $this->getTrialEndForPayload(),
            'tax_percent' => $this->getTaxPercentageForPayload(),
            'metadata' => $this->metadata,
        ]);
    }

    /**
     * Get the trial ending date for the Stripe payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        if ($this->skipTrial) {
            return 'now';
        }
        if ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays)->getTimestamp();
        }
    }

    /**
     * Get the tax percentage for the Stripe payload.
     *
     * @return int|null
     */
    protected function getTaxPercentageForPayload()
    {
        if ($taxPercentage = $this->user->taxPercentage()) {
            return $taxPercentage;
        }
    }
}
