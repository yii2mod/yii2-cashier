<?php

namespace yii2mod\cashier\controllers;

use Exception;
use Stripe\Event as StripeEvent;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\Inflector;
use yii\web\Controller;
use yii\web\Response;
use yii2mod\cashier\models\SubscriptionModel;

/**
 * Class WebhookController
 *
 * Based on:
 * 
 * Payment proccess:
 * https://stripe.com/docs/payments/checkout/fulfillment#webhooks
 * 
 * Request verification:
 * https://stackoverflow.com/a/48694589
 * https://stripe.com/docs/webhooks/signatures
 * 
 * @package yii2mod\cashier\controllers
 */
class WebhookController extends Controller
{
    /**
     * @var bool whether to enable CSRF validation for the actions in this controller
     */
    public $enableCsrfValidation = false;

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'handle-webhook' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Processing Stripe's callback and making membership updates according to payment data
     *
     * @return void|Response
     */
    public function actionHandleWebhook()
    {
        // file_put_contents('/tmp/last-stripe-payload.txt', Yii::$app->request->getRawBody(), FILE_APPEND );
        $payload = json_decode(Yii::$app->request->getRawBody(), true);

        if (!$this->eventExistsOnStripe($payload['id'])) {
            return;
        }

        $method = 'handle' . Inflector::camelize(str_replace('.', '_', $payload['type']));

        if (method_exists($this, $method)) {
            return $this->{$method}($payload);
        }

        return $this->missingMethod();
    }

    /**
     * Handle a cancelled customer from a Stripe subscription.
     *
     * @param array $payload
     *
     * @return Response
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $user = $this->getUserByStripeId($payload['data']['object']['customer']);
        if ($user) {
            $hasUpdated = false;
            $subscriptions = $user->getSubscriptions()->all();
            /* @var $subscription SubscriptionModel */
            foreach ($subscriptions as $subscription) {
                if ($subscription->stripe_id === $payload['data']['object']['id']) {
                    $subscription->markAsCancelled();
                    $hasUpdated = true;
                }
            }
            if($hasUpdated){
                $user->handlerAfterSubscriptionsUpdated();
            }
        }

        return new Response([
            'statusCode' => 200,
            'statusText' => 'Webhook Handled',
        ]);
    }

    /**
     * Handle a cancelled customer from a Stripe subscription.
     *
     * @param array $payload
     *
     * @return Response
     */
    protected function handleCheckoutSessionCompleted(array $payload)
    {
        $user = $this->getUserByStripeId($payload['data']['object']['customer']);
        if ($user) {
            $dataObj = $payload['data']['object'];
            if($dataObj['mode']=="subscription"){
                $subscriptionModel = $user->subscriptionByStripeID($dataObj['subscription']);
                if (!$subscriptionModel) {
                    $subscriptionModel = $user->loadSubscriptionModel($dataObj['subscription'], $dataObj['client_reference_id']);
                    $user->handlerAfterSubscriptionsUpdated();
                }
            }
        }

        return new Response([
            'statusCode' => 200,
            'statusText' => 'Webhook Handled',
        ]);
    }

    /**
     * Get the billable entity instance by Stripe ID.
     *
     * @param string $stripeId
     *
     * @return null|static
     */
    protected function getUserByStripeId($stripeId)
    {
        $model = Yii::$app->user->identityClass;

        return $model::findOne(['stripe_id' => $stripeId]);
    }

    /**
     * Verify with Stripe that the event is genuine.
     *
     * @param string $id
     *
     * @return bool
     */
    protected function eventExistsOnStripe($id)
    {
        try {
            return !is_null(StripeEvent::retrieve($id, Yii::$app->params['stripe']['apiKey']));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @return mixed
     */
    public function missingMethod()
    {
        return new Response([
            'statusCode' => 400,
        ]);
    }
}
