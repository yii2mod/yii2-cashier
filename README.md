<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://avatars0.githubusercontent.com/u/993323" height="100px">
    </a>
    <h1 align="center">Cashier Extension for Yii 2</h1>
    <br>
</p>

This extension is the port of [Laravel's Cashier package](https://laravel.com/docs/5.4/billing)

[![Latest Stable Version](https://poser.pugx.org/bigdropinc/yii2-cashier/v/stable)](https://packagist.org/packages/bigdropinc/yii2-cashier) [![Total Downloads](https://poser.pugx.org/bigdropinc/yii2-cashier/downloads)](https://packagist.org/packages/bigdropinc/yii2-cashier) [![License](https://poser.pugx.org/bigdropinc/yii2-cashier/license)](https://packagist.org/packages/bigdropinc/yii2-cashier)

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist bigdropinc/yii2-cashier "*"
```

or add

```
"bigdropinc/yii2-cashier": "*"
```

to the require section of your `composer.json` file.

Stripe Configuration
-----

> This project is inspired by the Laravel's Cashier package and tries to bring it's simplicity to the Yii framework.

**Database Migrations**

Before using Cashier, we'll also need to prepare the database.

```php
$tableOptions = null;

if ($this->db->driverName === 'mysql') {
    $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
}

$this->createTable('subscription', [
    'id' => $this->primaryKey(),
    'user_id' => $this->integer()->notNull(),
    'name' => $this->string()->notNull(),
    'stripe_id' => $this->string()->notNull(),
    'stripe_plan' => $this->string()->notNull(),
    'quantity' => $this->integer()->notNull(),
    'trial_end_at' => $this->timestamp()->null(),
    'end_at' => $this->timestamp()->null(),
    'created_at' => $this->dateTime()->null(),
    'updated_at' => $this->dateTime()->null()
], $tableOptions);

$this->addColumn('user', 'stripe_id', $this->string());
$this->addColumn('user', 'cardBrand', $this->string());
$this->addColumn('user', 'cardLastFour', $this->string());
$this->addColumn('user', 'trial_end_at', $this->timestamp()->null());
```
> Also you can apply migration by the following command:

```php
php yii migrate --migrationPath=@vendor/bigdropinc/yii2-cashier/migrations
```

**Model Setup**

Next, add the Billable trait to your User model definition:
```php
use bigdropinc\cashier\Billable;

class User extends ActiveRecord implements IdentityInterface
{
    use Billable;
}
```

**Provider Keys**

Next, you should configure your params section in your configuration file:

```php
'params' => [
        .....
        'stripe' => [
            'apiKey' => 'Your Secret Api Key'
        ],
    ],

```

Subscriptions
-------------

#### Creating Subscriptions

To create a subscription, first retrieve an instance of your billable model, which typically will be an instance of models\User. Once you have retrieved the model instance, you may use the newSubscription method to create the model's subscription:

```php
$user = User::findOne(1);

$user->newSubscription('main', 'monthly')->create($creditCardToken);
```

The first argument passed to the newSubscription method should be the name of the subscription. If your application only offers a single subscription, you might call this main or primary. The second argument is the specific Stripe plan the user is subscribing to. This value should correspond to the plan's identifier in Stripe.

The create method will automatically create the subscription, as well as update your database with the customer ID and other relevant billing information.

**Additional User Details**

If you would like to specify additional customer details, you may do so by passing them as the second argument to the create method:

```php
$user->newSubscription('main', 'monthly')->create($creditCardToken, [
    'description' => 'Customer for test@example.com'
]);
```

> To learn more about the additional fields supported by Stripe, check out Stripe's [documentation on customer creation](https://stripe.com/docs/api#create_customer)


**Coupons**

If you would like to apply a coupon when creating the subscription, you may use the withCoupon method:

```php
$user->newSubscription('main', 'monthly')
     ->withCoupon('code')
     ->create($creditCardToken);
```

#### Checking Subscription Status

Once a user is subscribed to your application, you may easily check their subscription status using a variety of convenient methods. First, the subscribed method returns true if the user has an active subscription, even if the subscription is currently within its trial period:

```php
if ($user->subscribed('main')) {
    //
}
```

If you would like to determine if a user is still within their trial period, you may use the onTrial method. This method can be useful for displaying a warning to the user that they are still on their trial period:

```php
if ($user->subscription('main')->onTrial()) {
    //
}
```

The subscribedToPlan method may be used to determine if the user is subscribed to a given plan based on a given Stripe plan ID. In this example, we will determine if the user's main subscription is actively subscribed to the monthly plan:

```php
if ($user->subscribedToPlan('monthly', 'main')) {
    //
}
```

**Cancelled Subscription Status**

To determine if the user was once an active subscriber, but has cancelled their subscription, you may use the cancelled method:

```php
if ($user->subscription('main')->cancelled()) {
    //
}
```

You may also determine if a user has cancelled their subscription, but are still on their "grace period" until the subscription fully expires. For example, if a user cancels a subscription on March 5th that was originally scheduled to expire on March 10th, the user is on their "grace period" until March 10th. Note that the subscribed method still returns true during this time.

```php
if ($user->subscription('main')->onGracePeriod()) {
    //
}
```

#### Changing Plans

After a user is subscribed to your application, they may occasionally want to change to a new subscription plan. To swap a user to a new subscription, use the swap method. For example, we may easily switch a user to the premium subscription:

```php
$user = User::findOne(1);

$user->subscription('main')->swap('provider-plan-id');
```

If the user is on trial, the trial period will be maintained. Also, if a "quantity" exists for the subscription, that quantity will also be maintained:

```php
$user->subscription('main')->swap('provider-plan-id');
```

If you would like to swap plans but skip the trial period on the plan you are swapping to, you may use the skipTrial method:

```php
$user->subscription('main')
        ->skipTrial()
        ->swap('provider-plan-id');
```

#### Subscription Quantity

Sometimes subscriptions are affected by "quantity". For example, your application might charge $10 per month per user on an account. To easily increment or decrement your subscription quantity, use the incrementQuantity and decrementQuantity methods:
```php
$user = User::findOne(1);

$user->subscription('main')->incrementQuantity();

// Add five to the subscription's current quantity...
$user->subscription('main')->incrementQuantity(5);

$user->subscription('main')->decrementQuantity();

// Subtract five to the subscription's current quantity...
$user->subscription('main')->decrementQuantity(5);
```
Alternatively, you may set a specific quantity using the updateQuantity method:

```php
$user->subscription('main')->updateQuantity(10);
```

For more information on subscription quantities, consult the [Stripe documentation](https://stripe.com/docs/subscriptions/guide#setting-quantities).

#### Subscription Taxes

With Cashier, it's easy to provide the tax_percent value sent to Stripe. To specify the tax percentage a user pays on a subscription, implement the taxPercentage method on your billable model, and return a numeric value between 0 and 100, with no more than 2 decimal places.

```php
public function taxPercentage() {
    return 20;
}
```

This enables you to apply a tax rate on a model-by-model basis, which may be helpful for a user base that spans multiple countries.


#### Cancelling Subscriptions

To cancel a subscription, simply call the cancel method on the user's subscription:

```php
$user->subscription('main')->cancel();
```

When a subscription is cancelled, Cashier will automatically set the ```end_at``` column in your database. This column is used to know when the subscribed method should begin returning false. For example, if a customer cancels a subscription on March 1st, but the subscription was not scheduled to end until March 5th, the subscribed method will continue to return true until March 5th.

You may determine if a user has cancelled their subscription but are still on their "grace period" using the onGracePeriod method:

```php
if ($user->subscription('main')->onGracePeriod()) {
    //
}
```


#### Resuming Subscriptions

If a user has cancelled their subscription and you wish to resume it, use the resume method. The user must still be on their grace period in order to resume a subscription:

```php
$user->subscription('main')->resume();
```

If the user cancels a subscription and then resumes that subscription before the subscription has fully expired, they will not be billed immediately. Instead, their subscription will simply be re-activated, and they will be billed on the original billing cycle.

Subscription Trials
-------------------

#### With Credit Card Up Front

If you would like to offer trial periods to your customers while still collecting payment method information up front, You should use the trialDays method when creating your subscriptions:

```php
$user = User::findOne(1);

$user->newSubscription('main', 'monthly')
            ->trialDays(10)
            ->create($creditCardToken);
```

This method will set the trial period ending date on the subscription record within the database, as well as instruct Stripe to not begin billing the customer until after this date.

> If the customer's subscription is not cancelled before the trial ending date they will be charged as soon as the trial expires, so you should notify your users of their trial ending date.
You may determine if the user is within their trial period using either the onTrial method of the user instance, or the onTrial method of the subscription instance. The two examples below are essentially identical in purpose:

```php
if ($user->onTrial('main')) {
    //
}

if ($user->subscription('main')->onTrial()) {
    //
}
```

#### Without Credit Card Up Front

If you would like to offer trial periods without collecting the user's payment method information up front, you may simply set the trial_end_at column on the user record to your desired trial ending date. For example, this is typically done during user registration:

```php
$user = new User([
    // Populate other user properties...
    'trial_end_at' => Carbon::now()->addDays(10),
]);
```

Cashier refers to this type of trial as a "generic trial", since it is not attached to any existing subscription. The onTrial method on the User instance will return true if the current date is not past the value of trial_end_at:

```php
if ($user->onTrial()) {
    // User is within their trial period...
}
```

You may also use the onGenericTrial method if you wish to know specifically that the user is within their "generic" trial period and has not created an actual subscription yet:

```php
if ($user->onGenericTrial()) {
    // User is within their "generic" trial period...
}
```

Once you are ready to create an actual subscription for the user, you may use the newSubscription method as usual:

```php
$user = User::findOne(1);

$user->newSubscription('main', 'monthly')->create($creditCardToken);
```

Handling Stripe Webhooks
------------------------

#### Failed Subscriptions

Just add the WebhookController to the ```controllerMap``` in your configuration file

```php
'controllerMap' => [
        //Stripe webhook
        'webhook' => 'bigdropinc\cashier\controllers\WebhookController',
    ],
```

That's it! Failed payments will be captured and handled by the controller.
The controller will cancel the customer's subscription when Stripe determines the subscription has failed (normally after three failed payment attempts).
Don't forget: you will need to configure the webhook URI, for example: ```yoursite.com/webhook/handle-webhook``` in your Stripe control panel settings.

Single Charges
--------------

#### Simple Charge

> When using Stripe, the charge method accepts the amount you would like to charge in the lowest denominator of the currency used by your application.

If you would like to make a "one off" charge against a subscribed customer's credit card, you may use the charge method on a billable model instance.

```php
// Stripe Accepts Charges In Cents...
$user->charge(100);
```

The ```charge``` method accepts an array as its second argument, allowing you to pass any options you wish to the underlying Stripe charge creation:

```php
$user->charge(100, [
    'custom_option' => $value,
]);
```

The charge method will throw an exception if the charge fails. If the charge is successful, the full Stripe response will be returned from the method:

```php
try {
    $response = $user->charge(100);
} catch (Exception $e) {
    //
}
```

#### Charge With Invoice

Sometimes you may need to make a one-time charge but also generate an invoice for the charge so that you may offer a PDF receipt to your customer. The invoiceFor method lets you do just that. For example, let's invoice the customer $5.00 for a "One Time Fee":

```php
// Stripe Accepts Charges In Cents...

$user->invoiceFor('One Time Fee', 500);
```

The invoice will be charged immediately against the user's credit card. The invoiceFor method also accepts an array as its third argument, allowing you to pass any options you wish to the underlying Stripe charge creation:

```php
$user->invoiceFor('One Time Fee', 500, [
    'custom-option' => $value,
]);
```

>  The invoiceFor method will create a Stripe invoice which will retry failed billing attempts. If you do not want invoices to retry failed charges, you will need to close them using the Stripe API after the first failed charge.

Invoices
--------

You may easily retrieve an array of a billable model's invoices using the invoices method:

```php
$invoices = $user->invoices();
```

When listing the invoices for the customer, you may use the invoice's helper methods to display the relevant invoice information. For example, you may wish to list every invoice in a GridView, allowing the user to easily download any of them:

```php
$dataProvider = new \yii\data\ArrayDataProvider([
            'allModels' => $invoices,
            'pagination' => [
                'pageSize' => 10,
            ],
        ]);

 echo yii\grid\GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            [
                'label' => 'Invoice Date',
                'value' => function ($model) {
                    return $model->date()->toFormattedDateString();
                }
            ],
            [
                'label' => 'Total',
                'value' => function ($model) {
                    return $model->total();
                }
            ],
            [
                'header' => 'Action',
                'class' => 'yii\grid\ActionColumn',
                'template' => '{download}',
                'buttons' => [
                    'download' => function ($url, $model, $key) {
                        $options = [
                            'title' => Yii::t('yii', 'Download Invoice'),
                            'data-pjax' => '0',
                        ];
                        $url = ['download-invoice', 'invoiceId' => $model->id];
                        return \yii\helpers\Html::a('<span class="glyphicon glyphicon-download"></span>', $url, $options);
                    }
                ],
            ],
        ],
 ]);
```

**Generating Invoice PDFs**

Create the ```download-invoice``` action in the your controller, for example:

```php
public function actionDownloadInvoice($invoiceId)
{
    return $user->downloadInvoice($invoiceId, [
        'vendor' => 'Your Company',
        'product' => 'Your Product',
    ]);
}
```

Refunds
------------

Refund objects allow you to refund a charge that has previously been created but not yet refunded. Funds will be refunded to the credit or debit card that was originally charged. The fees you were originally charged are also refunded.

```php
// Create Invoice
$invoice = $user->invoiceFor('Invoice Description', 1000);
 
// Create the refund
$refund = $user->refund($invoice->charge);

var_dump($refund->amount); // 1000
```
