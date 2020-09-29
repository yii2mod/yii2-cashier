<?php

namespace yii2mod\cashier;

use Carbon\Carbon;
use Exception;
use InvalidArgumentException;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Error\Card;
use Stripe\Error\InvalidRequest;
use Stripe\Invoice as StripeInvoice;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\Refund as StripeRefund;
use Stripe\Token;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii2mod\cashier\models\SubscriptionModel;
use yii\helpers\Url;

/**
 * Class Billable
 *
 * @package yii2mod\cashier
 */
trait Billable
{
    /**
     * The Stripe API key.
     *
     * @var string
     */
    protected static $stripeKey;

    /**
     * Mapping between attributes inside Stripe's metadata and a subscriptionModel attributes
     * They will be updated by webhook controller , for example:  'metadata_id' => 'student_id'  
     *
     * @return Array
     */
    public static function billableMapMetadataAttributes()
    {
        return [];
    }
    
    /**
     * Get the Stripe customer instance for the current user and token.
     * (copied from SubscriptionBuilder)
     *
     * @return \Stripe\Customer
     */
    public function getStripeCustomer($token=null)
    {
        if (!$this->stripe_id) {
            $customer = $this->createAsStripeCustomer($token);
        } else {
            $customer = $this->asStripeCustomer();
        }

        return $customer;
    }

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param int $amount
     * @param array $options
     *
     * @return \Stripe\Checkout\Session
     *
     * @throws Card
     */
    public function createCheckoutSessionForSubscription($planID, array $optionsParam = []): \Stripe\Checkout\Session
    {
        $customer = $this->getStripeCustomer();
        if (!$this->stripe_id) {
            throw new InvalidArgumentException('No stripe customer provided.');
        }

        $controller_url_name = '';
        if(array_key_exists('controller_url_name', $optionsParam)){
            $controller_url_name = $optionsParam['controller_url_name'] . '/';
        }
        $url_base = Url::base(true);
        if(array_key_exists('url_base', $optionsParam)){
            $url_base = $optionsParam['url_base'] . '/';
        }

        $options = [
            'customer' => $this->stripe_id,
            'payment_method_types' => ['card'],
            'subscription_data' => [
              'items'=> [
                ['plan'=> $planID, 'quantity'=> 1]
              ],
            ],
            'success_url' => $url_base . $controller_url_name . 'success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' =>  $url_base . $controller_url_name . 'cancel',
        ];

        if(array_key_exists('couponID', $optionsParam)){
            $options['subscription_data']['coupon'] = $optionsParam['couponID'] ;
        }

        if(array_key_exists('success_url', $optionsParam)){
            $options['success_url'] = $optionsParam['success_url'];
        }
        if(array_key_exists('cancel_url', $optionsParam)){
            $options['cancel_url'] = $optionsParam['cancel_url'];
        }   
        if(array_key_exists('client_reference_id', $optionsParam)){
            $options['client_reference_id'] = strval($optionsParam['client_reference_id']);
        }   
        if(array_key_exists('metadata', $optionsParam)){
            $options['subscription_data']['metadata'] = $optionsParam['metadata'];
        }   

        // if($this->email){
        //     $options['customer_email'] = 'max1000@gmail.com';
        // }

        $session = \Stripe\Checkout\Session::create($options, ['api_key' => $this->getStripeKey()] );
        return $session;
    }

    /**
     * Logic code for Yii app. To be overwritten
     */
    public function handlerAfterSubscriptionsUpdated(): void
    {
        
    }

    /**
     * Creates a Stripe's customer portal session and get and url to redirect
     *
     * @param array $options['return_url'] url to return after customer portal
     *
     * @return \Stripe\Checkout\Session
     *
     * @throws Card
     */
    public function createCustomerPortalSession( array $optionsParam = []): \Stripe\BillingPortal\Session
    {
        $customer = $this->getStripeCustomer();
        if (!$this->stripe_id) {
            throw new InvalidArgumentException('No stripe customer provided.');
        }

        $options = [ 'customer' =>  $this->stripe_id ];
        if(array_key_exists('return_url', $optionsParam)){
            $options['return_url'] =  $optionsParam['return_url'];
        }

        \Stripe\Stripe::setApiKey(Yii::$app->params['stripe']['apiKey']);
        $session = \Stripe\BillingPortal\Session::create($options);
        return $session;
    }

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param int $amount
     * @param array $options
     *
     * @return Charge
     *
     * @throws Card
     */
    public function charge($amount, array $options = []): Charge
    {
        $options = array_merge([
            'currency' => $this->preferredCurrency(),
        ], $options);

        $options['amount'] = $amount;

        if (!array_key_exists('source', $options) && $this->stripe_id) {
            $options['customer'] = $this->stripe_id;
        }

        if (!array_key_exists('source', $options) && !array_key_exists('customer', $options)) {
            throw new InvalidArgumentException('No payment source provided.');
        }

        return Charge::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Refund a customer for a charge.
     *
     * @param $charge
     * @param array $options
     *
     * @return StripeRefund
     */
    public function refund($charge, array $options = []): StripeRefund
    {
        $options['charge'] = $charge;

        return StripeRefund::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile(): bool
    {
        return (bool) $this->card_brand;
    }

    /**
     * Invoice the customer for the given amount.
     *
     * @param string $description
     * @param int $amount
     * @param array $options
     *
     * @return bool|StripeInvoice
     *
     * @throws Card
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        if (!$this->stripe_id) {
            throw new InvalidArgumentException('User is not a customer. See the createAsStripeCustomer method.');
        }

        $options = array_merge([
            'customer' => $this->stripe_id,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        StripeInvoiceItem::create(
            $options, ['api_key' => $this->getStripeKey()]
        );

        return $this->invoice();
    }

    /**
     * Begin creating a new subscription.
     *
     * @param string $subscription
     * @param string $plan
     *
     * @return SubscriptionBuilder
     */
    public function newSubscription(string $subscription, string $plan): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Create a new subscription from checkout params.
     *
     * @param array $payloadDataObject
     *
     * @return SubscriptionModel
     */
    public function loadSubscriptionModel($subscriptionID, $clientReferenceId)
    {
        $subscriptionBuilder = new SubscriptionBuilder($this, '', '');
        return $subscriptionBuilder->loadSubscriptionModel($subscriptionID, $clientReferenceId);
    }

    /**
     * Create a new subscription from checkout params.
     *
     * @param array $conditions https://stripe.com/docs/api/subscriptions/list
     *
     * @return Boolean
     */
    public function updateSubscriptionModels($conditions = array())
    {
        if (!$this->stripe_id) {
            return false;
        }

        $conditions['customer'] = $this->stripe_id;
        \Stripe\Stripe::setApiKey(Yii::$app->params['stripe']['apiKey']);
        $stripeSubscriptions = \Stripe\Subscription::all($conditions) ;

        foreach ($stripeSubscriptions as $stripeSubscription) {
            $subscriptionBuilder = new SubscriptionBuilder($this, '', '');
            $subscriptionBuilder->updateSubscriptionModel($stripeSubscription, null);
        }

        return true;
    }

    /**
     * Determine if the user is on trial.
     *
     * @param string $subscription
     * @param string|null $plan
     *
     * @return bool
     */
    public function onTrial(string $subscription = 'default', ?string $plan = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }
        $subscription = $this->subscription($subscription);
        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
            $subscription->stripePlan === $plan;
    }

    /**
     * Determine if the user is on a "generic" trial at the user level.
     *
     * @return bool
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && Carbon::now()->lt(Carbon::createFromFormat('Y-m-d H:i:s', $this->trial_ends_at));
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param string $subscription
     * @param string|null $plan
     *
     * @return bool
     */
    public function subscribed(string $subscription = 'default', ?string $plan = null): bool
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
            $subscription->stripe_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $subscription
     *
     * @return SubscriptionModel|null
     */
    public function subscription(string $subscription = 'default'): ?SubscriptionModel
    {
        return $this->getSubscriptions()->where(['name' => $subscription])->one();
    }

    /**
     * Get a subscription instance by conditions.
     *
     * @param array $conditions
     *
     * @return SubscriptionModel|null
     */
    public function subscriptionByConditions($conditions): ?SubscriptionModel
    {
        return $this->getSubscriptions()->where($conditions)->one();
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $subscription
     *
     * @return SubscriptionModel|null
     */
    public function subscriptionByStripeID(string $stripe_id = 'default'): ?SubscriptionModel
    {
        return $this->getSubscriptions()->where(['stripe_id' => $stripe_id])->one();
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $subscription
     *
     * @return SubscriptionModel|null
     */
    public static function subscriptionOnlyByStripeID($stripe_id){
        return SubscriptionModel::find()->where(['stripe_id'=> $stripe_id])->one();    
    }

    /**
     * @return mixed
     */
    public function getSubscriptions()
    {
        return $this->hasMany(SubscriptionModel::class, ['user_id' => 'id'])->orderBy(['created_at' => SORT_DESC]);
    }

    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @return bool|StripeInvoice
     */
    public function invoice()
    {
        if ($this->stripe_id) {
            try {
                return StripeInvoice::create(['customer' => $this->stripe_id], $this->getStripeKey())->pay();
            } catch (InvalidRequest $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     *
     * @return Invoice|null
     */
    public function upcomingInvoice(): ?Invoice
    {
        try {
            $stripeInvoice = StripeInvoice::upcoming(
                ['customer' => $this->stripe_id], ['api_key' => $this->getStripeKey()]
            );

            return new Invoice($this, $stripeInvoice);
        } catch (InvalidRequest $e) {
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @param string $id
     *
     * @return Invoice|null
     */
    public function findInvoice(string $id): Invoice
    {
        try {
            return new Invoice($this, StripeInvoice::retrieve($id, $this->getStripeKey()));
        } catch (Exception $e) {
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param string $id
     *
     * @return Invoice
     *
     * @throws NotFoundHttpException
     */
    public function findInvoiceOrFail(string $id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param string $id
     * @param array $data
     *
     * @return Response
     */
    public function downloadInvoice(string $id, array $data)
    {
        return $this->findInvoiceOrFail($id)->download($data);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param bool $includePending
     * @param array $parameters
     *
     * @return array
     */
    public function invoices(bool $includePending = false, array $parameters = []): array
    {
        $invoices = [];

        $parameters = array_merge(['limit' => 24, 'customer' => $this->stripe_id], $parameters);

        \Stripe\Stripe::setApiKey(Yii::$app->params['stripe']['apiKey']);
        $stripeInvoices = \Stripe\Invoice::all($parameters);

        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (!is_null($stripeInvoices)) {
            foreach ($stripeInvoices->data as $invoice) {
                if ($invoice->paid || $includePending) {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }

        return $invoices;
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param array $parameters
     *
     * @return array
     */
    public function invoicesIncludingPending(array $parameters = []): array
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     *
     * @param string $token
     */
    public function updateCard(string $token): void
    {
        $customer = $this->asStripeCustomer();
        $token = Token::retrieve($token, ['api_key' => $this->getStripeKey()]);
        // If the given token already has the card as their default source, we can just
        // bail out of the method now. We don't need to keep adding the same card to
        // the user's account each time we go through this particular method call.
        if ($token->card->id === $customer->default_source) {
            return;
        }
        $card = $customer->sources->create(['source' => $token]);
        $customer->default_source = $card->id;
        $customer->save();
        // Next, we will get the default source for this user so we can update the last
        // four digits and the card brand on this user record in the database, which
        // is convenient when displaying on the front-end when updating the cards.
        $source = $customer->default_source
            ? $customer->sources->retrieve($customer->default_source)
            : null;

        $this->fillCardDetails($source);

        $this->save();
    }

    /**
     * Synchronises the customer's card from Stripe back into the database.
     *
     * @return $this
     */
    public function updateCardFromStripe()
    {
        $customer = $this->asStripeCustomer();
        $defaultCard = null;
        foreach ($customer->sources->data as $card) {
            if ($card->id === $customer->default_source) {
                $defaultCard = $card;
                break;
            }
        }
        if ($defaultCard) {
            $this->fillCardDetails($defaultCard)->save();
        } else {
            $this->card_brand = null;
            $this->card_last_four = null;
            $this->update(false);
        }

        return $this;
    }

    /**
     * Fills the user's properties with the source from Stripe.
     *
     * @param \Stripe\Card|null $card
     *
     * @return $this
     */
    protected function fillCardDetails($card)
    {
        if ($card) {
            $this->card_brand = $card->brand;
            $this->card_last_four = $card->last4;
        }

        return $this;
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param string $coupon
     */
    public function applyCoupon($coupon)
    {
        $customer = $this->asStripeCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     *
     * @param array|string $plans
     * @param string $subscription
     *
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default'): bool
    {
        $subscription = $this->subscription($subscription);

        if (!$subscription || !$subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->stripe_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param string $plan
     *
     * @return bool
     */
    public function onPlan($plan): bool
    {
        $plan = $this->getSubscriptions()->where(['stripe_plan' => $plan])->one();

        return !is_null($plan) && $plan->valid();
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId(): bool
    {
        return !is_null($this->stripe_id);
    }

    /**
     * Create a Stripe customer for the given user.
     *
     * @param string|null $token
     * @param array $options
     *
     * @return Customer
     */
    public function createAsStripeCustomer($token = null, array $options = []): Customer
    {
        $options = array_key_exists('email', $options)
            ? $options : array_merge($options, ['email' => $this->email]);

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $customer = Customer::create($options, $this->getStripeKey());

        $this->stripe_id = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Stripe using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (!is_null($token)) {
            $this->updateCard($token);
        }

        return $customer;
    }

    /**
     * Get the Stripe customer for the user.
     *
     * @return Customer
     */
    public function asStripeCustomer(): Customer
    {
        return Customer::retrieve($this->stripe_id, $this->getStripeKey());
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency(): string
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage(): int
    {
        return 0;
    }

    /**
     * Get the Stripe API key.
     *
     * @return string
     */
    public static function getStripeKey(): string
    {
        return static::$stripeKey ?: Yii::$app->params['stripe']['apiKey'];
    }

    /**
     * Set the Stripe API key.
     *
     * @param string $key
     */
    public static function setStripeKey($key): void
    {
        static::$stripeKey = $key;
    }
}
