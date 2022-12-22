<?php

namespace yii2mod\cashier\behaviors;

use Carbon\Carbon;
use yii\base\Behavior;
use yii\db\ActiveRecord;

/**
 * CarbonBehavior automatically creates a Carbon Instance for one or multiple attributes of an ActiveRecord
 * object when `afterFind` event happen.
 *
 * To use CarbonBehavior, configure the [[attributes]] property which should specify the list of attributes
 * that need to be converted.
 *
 *  ```php
 *
 * public function behaviors()
 * {
 *     return [
 *         [
 *             'class' => CarbonBehavior::className(),
 *             'attributes' => [
 *                  'createdAt'
 *             ],
 *         ],
 *     ];
 * }
 *
 * $user = UserModel::findOne(1);
 *
 * var_dump($user->createdAt->year);
 * var_dump($user->createdAt->month);
 * var_dump($user->createdAt->day);
 *
 * // change date
 *
 * $user->createdAt->addYear();
 * $user->save();
 *
 * ```
 *
 * @see http://carbon.nesbot.com/docs/#api-introduction
 *
 * @package yii2mod\behaviors
 */
class CarbonBehavior extends Behavior
{
    /**
     * @var ActiveRecord the owner of this behavior
     */
    public $owner;

    /**
     * @var array list of attributes that will be converted to carbon instance
     */
    public $attributes = [];

    /**
     * @var string date format for carbon
     */
    public $dateFormat = 'Y-m-d H:i:s';

    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'attributesToCarbon',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'attributesToDefaultFormat',
            ActiveRecord::EVENT_AFTER_UPDATE => 'attributesToCarbon',
        ];
    }

    /**
     * Convert the model's attributes to an Carbon instance.
     *
     * @param $event
     *
     * @return static
     */
    public function attributesToCarbon($event)
    {
        foreach ($this->attributes as $attribute) {
            $value = $this->owner->$attribute;
            if (!empty($value)) {
                // If this value is an integer, we will assume it is a UNIX timestamp's value
                // and format a Carbon object from this timestamp.
                if (is_numeric($value)) {
                    $this->owner->$attribute = Carbon::createFromTimestamp($value);
                }

                // If the value is in simply year, month, day format, we will instantiate the
                // Carbon instances from that format.
                elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
                    $this->owner->$attribute = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
                } else {
                    $this->owner->$attribute = Carbon::createFromFormat($this->dateFormat, $this->owner->$attribute);
                }
            }
        }
    }

    /**
     * Handles owner 'beforeUpdate' event for converting attributes values to the default format
     *
     * @param $event
     *
     * @return bool
     */
    public function attributesToDefaultFormat($event)
    {
        $oldAttributes = $this->owner->oldAttributes;
        foreach ($this->attributes as $attribute) {
            $oldAttributeValue = $oldAttributes[$attribute];

            if ($this->owner->$attribute instanceof Carbon) {
                //If old attribute value is an integer, then we convert the current attribute value to the timestamp for prevent db errors
                if (is_numeric($oldAttributeValue)) {
                    $this->owner->$attribute = $this->owner->$attribute->timestamp;
                }
            }
        }
    }
}