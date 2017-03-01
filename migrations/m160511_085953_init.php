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
            'userId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'stripeId' => $this->string()->notNull(),
            'stripePlan' => $this->string()->notNull(),
            'quantity' => $this->integer()->notNull(),
            'trialEndAt' => $this->timestamp()->null(),
            'endAt' => $this->timestamp()->null(),
            'createdAt' => $this->timestamp()->null(),
            'updatedAt' => $this->timestamp()->null(),
        ], $tableOptions);

        $this->addColumn('{{%user}}', 'stripeId', $this->string());
        $this->addColumn('{{%user}}', 'cardBrand', $this->string());
        $this->addColumn('{{%user}}', 'cardLastFour', $this->string());
        $this->addColumn('{{%user}}', 'trialEndAt', $this->timestamp()->null());
    }

    public function down()
    {
        $this->dropTable('{{%subscription}}');

        $this->dropColumn('{{%user}}', 'stripeId');
        $this->dropColumn('{{%user}}', 'cardBrand');
        $this->dropColumn('{{%user}}', 'cardLastFour');
        $this->dropColumn('{{%user}}', 'trialEndAt');
    }

    /*
    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
    }

    public function safeDown()
    {
    }
    */
}
