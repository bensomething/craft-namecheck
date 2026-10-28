<?php

namespace bensomething\namecheck\services;

use bensomething\namecheck\enums\Mode;
use bensomething\namecheck\fields\Namecheck as NamecheckField;
use bensomething\namecheck\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use craft\helpers\Db;

/**
 * Keeps track of which entries mention which, so each side can list the other.
 */
class Mentions extends Component
{
    public const TABLE = '{{%namecheck_mentions}}';

    public function isSource(ElementInterface $element): bool
    {
        return $element instanceof Entry
            && in_array($element->getSection()?->handle, Plugin::getInstance()->getSettings()->sourceSections, true);
    }

    /**
     * Records the entries an element mentions, replacing what it mentioned before.
     */
    public function index(ElementInterface $element): void
    {
        $ids = $this->findMentions($element);
        $sourceId = $element->getCanonicalId();

        Craft::$app->getDb()->transaction(function() use ($ids, $sourceId, $element) {
            Db::delete(self::TABLE, [
                'sourceId' => $sourceId,
                'sourceSiteId' => $element->siteId,
            ]);
            if ($ids !== []) {
                Db::batchInsert(self::TABLE, ['sourceId', 'sourceSiteId', 'targetId'], array_map(
                    fn(int $id) => [$sourceId, $element->siteId, $id],
                    $ids,
                ));
            }
        });
    }

    /**
     * Returns the IDs of the entries an element mentions, by its title and the
     * text of its fields. Relation fields are left out: picking an entry isn't
     * mentioning it.
     *
     * @return int[]
     */
    public function findMentions(ElementInterface $element): array
    {
        $names = Plugin::getInstance()->getNames();
        $matcher = $names->getMatcher($element->siteId);
        $targets = $names->getTargets($element->siteId);
        if ($matcher->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($matcher->find($this->_text($element)) as $match) {
            $ids[$match['key']] = true;
        }
        unset($ids[$element->getCanonicalId()]);

        $relatedOnly = array_keys(array_filter(
            array_intersect_key($targets, $ids),
            fn(array $target) => $target['mode'] === Mode::Related->value,
        ));
        if ($relatedOnly !== []) {
            $related = $names->getRelatedIds($element, $relatedOnly);
            foreach (array_diff($relatedOnly, $related) as $id) {
                unset($ids[$id]);
            }
        }

        return array_keys($ids);
    }

    /**
     * Re-indexes every entry in the source sections, after the settings or a
     * target's names have changed.
     *
     * @param callable(int $done, int $total): void|null $progress
     * @return int How many entries were indexed, counting each site separately
     */
    public function rebuild(?callable $progress = null): int
    {
        $sections = Plugin::getInstance()->getSettings()->sourceSections;
        if ($sections === []) {
            Db::delete(self::TABLE);
            return 0;
        }

        $query = Entry::find()
            ->section($sections)
            ->site('*')
            ->unique(false)
            ->status(null);

        // Sources that have since left the source sections.
        Db::delete(self::TABLE, ['not in', 'sourceId', $query->ids()]);

        $total = (int)$query->count();
        $done = 0;
        foreach ($query->each() as $entry) {
            /** @var Entry $entry */
            $this->index($entry);
            $done++;
            if ($progress) {
                $progress($done, $total);
            }
        }
        return $done;
    }

    /**
     * Entries that mention the given one, ready to be narrowed further.
     */
    public function mentionedIn(ElementInterface $target): EntryQuery
    {
        $sourceIds = (new Query())
            ->select('sourceId')
            ->from(self::TABLE)
            ->where([
                'targetId' => $target->getCanonicalId(),
                'sourceSiteId' => $target->siteId,
            ]);

        return Entry::find()
            ->siteId($target->siteId)
            ->andWhere(['elements.id' => $sourceIds]);
    }

    /**
     * Entries the given one mentions, ready to be narrowed further.
     */
    public function mentionedBy(ElementInterface $source): EntryQuery
    {
        $targetIds = (new Query())
            ->select('targetId')
            ->from(self::TABLE)
            ->where([
                'sourceId' => $source->getCanonicalId(),
                'sourceSiteId' => $source->siteId,
            ]);

        return Entry::find()
            ->siteId($source->siteId)
            ->andWhere(['elements.id' => $targetIds]);
    }

    private function _text(ElementInterface $element): string
    {
        $parts = [(string)$element->title];
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof BaseRelationField || $field instanceof NamecheckField) {
                continue;
            }
            $parts[] = $field->getSearchKeywords($element->getFieldValue($field->handle), $element);
        }

        // Tags become spaces to keep words in neighbouring elements apart.
        $text = preg_replace('/<[^>]*>/', ' ', implode("\n", $parts));
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
