<?php

/**
 * @copyright Copyright Victor Demin, 2014
 * @license https://github.com/ruskid/yii2-stripe/LICENSE
 * @link https://github.com/ruskid/yii2-stripe#readme
 */

namespace yii2mod\cashier;

use Yii;
use yii\helpers\Html;

/**
 * Yii stripe simple form checkout class.
 * https://stripe.com/docs/checkout#integration-simple
 *
 * @author Victor Demin <demmbox@gmail.com>
 */
class StripeCheckoutSubsciption extends \yii\base\Widget {
    /**
     * The Stripe API key.
     *
     * @var string
     */
    protected static $stripePubKey;    

    /**
     * Stripe's plan ID, from stripe admin
     * @var string plan ID
     */
    public $plainId = "";

    /**
     * Url redirect on success
     * @var string succes url
     */
    public $successUrl = "/";

    /**
     * Url redirect on cancel
     * @var string cancel url
     */
    public $cancelUrl = "/";

    /**
     * Additional options to be added.
     * @var array additional options.
     */
    public $options = [];

    /**
     * Stripe session
     * @var \Stripe\Checkout\Session
     */
    public $session = null;

    /**
     * @see Stripe's javascript location
     * @var string url to stripe's javascript
     */
    public $stripeJs = "https://js.stripe.com/v3";

    const BUTTON_CLASS = 'stripe-button';

    /**
     * @see Init extension default
     */
    public function init() {
        parent::init();
    }

    /**
     * Will show the Stripe's simple form modal
     */
    public function run() {      
        echo Html::script('', [
                    'src' => $this->stripeJs,
        ]);
        
        echo $this->generateButton();
        echo Html::script("
            (function() {
                var stripe = Stripe('{$this->getStripePubKey()}');
                var checkoutButton = document.getElementById('checkout-button-{$this->plainId}');
                checkoutButton.addEventListener('click', function () {
                // When the customer clicks on the button, redirect
                // them to Checkout.
                stripe.redirectToCheckout({
                    // Instead use one of the strategies described in
                    // https://stripe.com/docs/payments/checkout/fulfillment
                    // successUrl: 'https://your-website.com/success',
                    // cancelUrl: 'https://your-website.com/canceled',

                    sessionId: '{$this->session->id}'
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
        ", ['defer' => true]);
        echo Html::tag('div', '', [
            'id'=> 'stripe-checkout-error-message',
            // 'class' => "alert alert-danger",
            'role' => "alert"
        ]);
    }

    /**
     * Will generate the stripe form
     * @return string the generated stripe's modal form
     */
    private function generateButton() {
        return Html::button(Yii::t('app', 'Subscribe'), [
            'class' => 'btn btn-primary btn-flat',
            "id"    => "checkout-button-" . $this->plainId,
            "role"    => "link"
        ]);
    }

    /**
     * Get the Stripe API key.
     *
     * @return string
     */
    public static function getStripePubKey(): string
    {
        return static::$stripePubKey ?: Yii::$app->params['stripe']['pubKey'];
    }

}