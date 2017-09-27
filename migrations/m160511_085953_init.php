<?php

use yii\db\Migration;

/**
 * Class m160511_085953_init
 */
class m160511_085953_init extends Migration
{
    public function up()
    {
        $tableOptions = null;

        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%subscription}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'stripe_id' => $this->string()->notNull(),
            'stripe_plan' => $this->string()->notNull(),
            'quantity' => $this->integer()->notNull(),
            'trial_end_at' => $this->timestamp()->null(),
            'end_at' => $this->timestamp()->null(),
            'created_at' => $this->dateTime()->null(),
            'updated_at' => $this->dateTime()->null(),
        ], $tableOptions);

        $this->addColumn('{{%user}}', 'stripe_id', $this->string());
        $this->addColumn('{{%user}}', 'card_brand', $this->string());
        $this->addColumn('{{%user}}', 'card_last_four', $this->string());
        $this->addColumn('{{%user}}', 'trial_end_at', $this->timestamp()->null());
    }

    public function down()
    {
        $this->dropTable('{{%subscription}}');

        $this->dropColumn('{{%user}}', 'stripe_id');
        $this->dropColumn('{{%user}}', 'card_brand');
        $this->dropColumn('{{%user}}', 'card_last_four');
        $this->dropColumn('{{%user}}', 'trial_end_at');
    }
}
