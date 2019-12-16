<?php

namespace yii2mod\cashier\tests;

use Carbon\Carbon;
use Stripe\Token;
use Yii;
use yii2mod\cashier\tests\data\CashierTestControllerStub;
use yii2mod\cashier\tests\data\User;
use yii2mod\cashier\StripeCheckoutSubsciption;
use yii2mod\cashier\SubscriptionBuilder;

class CashierTest extends TestCase
{
    protected function getTestToken()
    {
        return Token::create([
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 5,
                'exp_year' => 2020,
                'cvc' => '123',
            ],
        ], ['api_key' => Yii::$app->params['stripe']['apiKey']])->id;
    }

    // Tests:

    public function testSubscriptionsCanBeCreated()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->stripe_id);
        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribedToPlan('monthly-10-1', 'main'));
        $this->assertFalse($user->subscribedToPlan('monthly-10-1', 'something'));
        $this->assertFalse($user->subscribedToPlan('monthly-10-2', 'main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->updateAttributes(['ends_at' => Carbon::now()->subDays(5)]);

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $subscription->updateAttributes(['ends_at' => $oldGracePeriod]);

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Increment & Decrement
        $subscription->incrementQuantity();

        $this->assertEquals(2, $subscription->quantity);

        $subscription->decrementQuantity();

        $this->assertEquals(1, $subscription->quantity);

        // Swap Plan
        $subscription->swap('monthly-10-2');

        $this->assertEquals('monthly-10-2', $subscription->stripe_plan);

        // Invoice Tests
        $invoice = $user->invoices()[1];
        $stripeSubscription = $subscription->asStripeSubscription();
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());

        $this->assertEquals('1000', $invoice->total);
        $this->assertTrue($stripeSubscription->discount==null);
    }

    public function testCreatingSubscriptionWithCoupons()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->withCoupon('coupon-1')
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Invoice Tests
        $invoice = $user->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$5.00', $invoice->total());
        $this->assertEquals('$5.00', $invoice->amountOff());
        $this->assertFalse($invoice->discountIsPercentage());

        $this->assertTrue($invoice->discount!==null);
        $this->assertEquals('500', $invoice->total);
        $this->assertEquals('500', $invoice->amount_paid);

        $stripeSubscription = $subscription->asStripeSubscription();
        $this->assertTrue($stripeSubscription->discount->coupon->percent_off===null);
    }

    public function testGenericTrials()
    {
        $user = new User();
        $this->assertFalse($user->onGenericTrial());

        $user->trial_ends_at = Carbon::tomorrow();
        $this->assertTrue($user->onGenericTrial());

        $user->trial_ends_at = Carbon::today()->subDays(5);
        $this->assertFalse($user->onGenericTrial());
    }

    public function testCreatingSubscriptionWithTrial()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->trialDays(7)
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);
    }

    public function testApplyingCouponsToExistingCustomers()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->create($this->getTestToken());
        $user->applyCoupon('coupon-1');
        $customer = $user->asStripeCustomer();

        $this->assertEquals('coupon-1', $customer->discount->coupon->id);
    }

    public function testMarkingAsCancelledFromWebhook()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        $user->newSubscription('main', 'monthly-10-1')
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        Yii::$app->request->rawBody = json_encode([
            'id' => 'foo',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $subscription->stripe_id,
                    'customer' => $user->stripe_id,
                ],
            ],
        ]);
        $controller = new CashierTestControllerStub('webhook', Yii::$app);
        $response = $controller->actionHandleWebhook();

        $this->assertEquals(200, $response->getStatusCode());

        $user->refresh();
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    public function testCreatingOneOffInvoices()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Invoice
        $user->createAsStripeCustomer($this->getTestToken());
        $user->invoiceFor('Yii2mod Cashier', 1000);

        // Invoice Tests
        $invoice = $user->invoices()[0];

        $this->assertEquals('$10.00', $invoice->total());
        $this->assertEquals('Yii2mod Cashier', $invoice->invoiceItems()[0]->asStripeInvoiceItem()->description);
    }

    public function testRefunds()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Invoice
        $user->createAsStripeCustomer($this->getTestToken());
        $invoice = $user->invoiceFor('Yii2mod Cashier', 1000);

        // Create the refund
        $refund = $user->refund($invoice->charge);

        // Refund Tests
        $this->assertEquals(1000, $refund->amount);
    }

    public function testSessionAndStripeCheckoutWidget()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);
        $session = $user->createCheckoutSessionForSubscription(
            'monthly-10-1',
            [
                'url_base' => 'http://localhost',
                'controller_url_name' => 'stripe',
                'client_reference_id' => 999,
                'metadata' => [
                    "student_id" => 666,
                    "student_name" => 'name surname',
                    "user_id" => 999,
                ]
            ]
        );
        $out = StripeCheckoutSubsciption::widget([
            'session' => $session
        ]);
        $apiKey = Yii::$app->params['stripe']['pubKey'];
        
        $expected = <<<EOT
<script src="https://js.stripe.com/v3"></script><button type="button" id="checkout-button-" class="btn btn-primary btn-flat" role="link">Subscribe</button><script defer>
            (function() {
                var stripe = Stripe('$apiKey');
                var checkoutButton = document.getElementById('checkout-button-');
                checkoutButton.addEventListener('click', function () {
                // When the customer clicks on the button, redirect
                // them to Checkout.
                stripe.redirectToCheckout({
                    // Instead use one of the strategies described in
                    // https://stripe.com/docs/payments/checkout/fulfillment
                    // successUrl: 'https://your-website.com/success',
                    // cancelUrl: 'https://your-website.com/canceled',

                    sessionId: '$session->id'
                })
                .then(function (result) {
                    if (result.error) {
                    // If `redirectToCheckout` fails due to a browser or network
                    // error, display the localized error message to your customer.
                    var displayError = document.getElementById('stripe-checkout-error-message');
                    displayError.textContent = result.error.message;
                    }
                });
                });
            })();
        </script><div id="stripe-checkout-error-message" role="alert"></div>
EOT;

        $this->assertEquals($expected, $out);
    }

    public function testCreateSubscriptionFromWebhookUsingStripeCheckout()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);        
        // $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $customer = $user->getStripeCustomer($this->getTestToken());
        $subscriptionBuilder = new SubscriptionBuilder($user, 'main', 'monthly-10-1');        

        $payload = $subscriptionBuilder->buildPayload();
        $payload["metadata"] = [
            "student_id" => "1017",
            "student_name" =>  "name surname",
            "user_id" => "888",
            "some_entity_id_from_other_table" => 777
        ];
        $subscription = $customer->subscriptions->create($payload);
        
        // $user->newSubscription('main', 'monthly-10-1')
        //     ->create($this->getTestToken());

        // $subscription = $user->subscription('main');

        Yii::$app->request->rawBody = json_encode([
            'id' => 'foo',
            "type" => "checkout.session.completed",
            'data' => [
                'object' => [
                    'customer' => $user->stripe_id,
                    "mode" => "subscription",
                    'client_reference_id' => 'some string id',
                    'name' => 'monthly-10-1',
                    'subscription' => $subscription->id
                ],
            ],
        ]);
        $controller = new CashierTestControllerStub('webhook', Yii::$app);
        $response = $controller->actionHandleWebhook();
        $this->assertEquals(200, $response->getStatusCode());

        $user->refresh();
        $subscriptionModel = $user->subscription($subscription->plan->product);

        // doing again does not creates a new one
        $controller = new CashierTestControllerStub('webhook', Yii::$app);
        $response = $controller->actionHandleWebhook();
        $this->assertEquals(200, $response->getStatusCode());

        $this->assertEquals($subscription->id, $subscriptionModel->stripe_id);
        $this->assertEquals(777, $subscriptionModel->metadata_id);

        $updatedSubscriptionModel = $subscriptionBuilder->loadSubscriptionModel($subscriptionModel->stripe_id);
        $this->assertEquals(777, $updatedSubscriptionModel->metadata_id);
    }
}
