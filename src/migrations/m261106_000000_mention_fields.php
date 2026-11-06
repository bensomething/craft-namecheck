<?php

namespace bensomething\namecheck\migrations;

use bensomething\namecheck\jobs\RebuildMentions;
use bensomething\namecheck\services\Mentions;
use craft\db\Migration;
use craft\helpers\Queue;

/**
 * Records which fields each mention is found in, for the Mentioned In panel.
 */
class m261106_000000_mention_fields extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Mentions::TABLE, 'fields')) {
            $this->addColumn(Mentions::TABLE, 'fields', $this->json()->after('targetId'));
        }

        // Fill in the fields for mentions recorded before now.
        Queue::push(new RebuildMentions());

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Mentions::TABLE, 'fields')) {
            $this->dropColumn(Mentions::TABLE, 'fields');
        }
        return true;
    }
}
