<?php

namespace yii2mod\cashier\tests;

use Carbon\Carbon;
use Stripe\Token;
use Yii;
use yii2mod\cashier\tests\data\User;

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
        $this->assertNotNull($user->subscription('main')->stripeId);
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
        $oldGracePeriod = $subscription->endAt;
        $subscription->updateAttributes(['endAt' => Carbon::now()->subDays(5)]);

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $subscription->updateAttributes(['endAt' => $oldGracePeriod]);

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

        $this->assertEquals('monthly-10-2', $subscription->stripePlan);

        // Invoice Tests
        $invoice = $user->invoices()[1];

        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function testCreatingSubscriptionWithCoupons()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->withCoupon('coupon-1')->create($this->getTestToken());
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
    }

    public function testGenericTrials()
    {
        $user = new User;
        $this->assertFalse($user->onGenericTrial());
        $user->trialEndAt = Carbon::tomorrow();
        $this->assertTrue($user->onGenericTrial());
        $user->trialEndAt = Carbon::today()->subDays(5);
        $this->assertFalse($user->onGenericTrial());
    }

    public function testCreatingSubscriptionWithTrial()
    {
        $user = User::findOne(['email' => 'johndoe@domain.com']);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->trialDays(7)->create($this->getTestToken());
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trialEndAt->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trialEndAt->day);
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
}