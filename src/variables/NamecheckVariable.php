<?php

namespace bensomething\namecheck\variables;

use bensomething\namecheck\Plugin;
use craft\base\ElementInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;

/**
 * `craft.namecheck`
 */
class NamecheckVariable
{
    /**
     * The entry a whole name belongs to, by title or other name: `craft.namecheck.find('Jesus and Mary Chain')`
     */
    public function find(string $name): ?Entry
    {
        return Plugin::getInstance()->getNames()->findEntries([$name])[$name] ?? null;
    }

    /**
     * The entries a list of whole names belong to, keyed by name, in one go:
     * `craft.namecheck.findAll(['Pulp', 'Jesus and Mary Chain'])`
     *
     * @param string[] $names
     * @return array<string, Entry>
     */
    public function findAll(array $names): array
    {
        return Plugin::getInstance()->getNames()->findEntries($names);
    }

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
