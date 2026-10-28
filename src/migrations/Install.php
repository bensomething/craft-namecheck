<?php

namespace bensomething\namecheck\migrations;

use bensomething\namecheck\services\Mentions;
use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Mentions::TABLE)) {
            return true;
        }

        $this->createTable(Mentions::TABLE, [
            'id' => $this->primaryKey(),
            'sourceId' => $this->integer()->notNull(),
            'sourceSiteId' => $this->integer()->notNull(),
            'targetId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Mentions::TABLE, ['sourceId', 'sourceSiteId', 'targetId'], true);
        $this->createIndex(null, Mentions::TABLE, ['targetId', 'sourceSiteId']);

        $this->addForeignKey(null, Mentions::TABLE, ['sourceId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Mentions::TABLE, ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Mentions::TABLE, ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Mentions::TABLE);
        return true;
    }
}
