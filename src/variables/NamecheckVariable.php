<?php

namespace bensomething\namecheck\variables;

use bensomething\namecheck\Plugin;
use craft\base\ElementInterface;
use craft\elements\db\EntryQuery;

/**
 * `craft.namecheck`
 */
class NamecheckVariable
{
    /**
     * Entries that mention the given one: `craft.namecheck.mentionedIn(entry).section('news').all()`
     */
    public function mentionedIn(ElementInterface $target): EntryQuery
    {
        return Plugin::getInstance()->getMentions()->mentionedIn($target);
    }

    /**
     * Entries the given one mentions: `craft.namecheck.mentionedBy(entry).all()`
     */
    public function mentionedBy(ElementInterface $source): EntryQuery
    {
        return Plugin::getInstance()->getMentions()->mentionedBy($source);
    }
}
