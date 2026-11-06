<?php

namespace bensomething\namecheck\services;

use bensomething\namecheck\enums\Mode;
use bensomething\namecheck\fields\Namecheck as NamecheckField;
use bensomething\namecheck\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use craft\helpers\Db;
use craft\helpers\Json;

/**
 * Keeps track of which entries mention which, so each side can list the other.
 */
class Mentions extends Component
{
    public const TABLE = '{{%namecheck_mentions}}';

    /** How many levels of nested entries are read, as a guard against loops. */
    private const MAX_DEPTH = 5;

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
        $mentions = $this->findMentions($element);
        $sourceId = $element->getCanonicalId();

        Craft::$app->getDb()->transaction(function() use ($mentions, $sourceId, $element) {
            Db::delete(self::TABLE, [
                'sourceId' => $sourceId,
                'sourceSiteId' => $element->siteId,
            ]);
            if ($mentions !== []) {
                $rows = [];
                foreach ($mentions as $targetId => $fields) {
                    $rows[] = [$sourceId, $element->siteId, $targetId, $fields];
                }
                Db::batchInsert(self::TABLE, ['sourceId', 'sourceSiteId', 'targetId', 'fields'], $rows);
            }
        });
    }

    /**
     * Returns the entries an element mentions, by its title and the text of
     * its fields, as the handles of the fields each was found in (`title` for
     * the title). Relation fields are left out: picking an entry isn't
     * mentioning it.
     *
     * @return array<int, string[]> Field handles, keyed by the mentioned entry's ID
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
        foreach ($this->_texts($element) as $handle => $text) {
            foreach ($matcher->find($text) as $match) {
                $ids[$match['key']][$handle] = true;
            }
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

        return array_map(fn(array $handles) => array_map('strval', array_keys($handles)), $ids);
    }

    /**
     * The handles of the fields each source mentions the target in, keyed by
     * source ID, with `title` standing for the title.
     *
     * @param int[] $sourceIds
     * @return array<int, string[]>
     */
    public function fieldsMentionedIn(ElementInterface $target, array $sourceIds): array
    {
        $rows = (new Query())
            ->select(['sourceId', 'fields'])
            ->from(self::TABLE)
            ->where([
                'targetId' => $target->getCanonicalId(),
                'sourceSiteId' => $target->siteId,
                'sourceId' => $sourceIds,
            ])
            ->pairs();

        return array_map(fn($fields) => (array)(Json::decodeIfJson((string)$fields) ?: []), $rows);
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

    /**
     * An element's title and the text of each of its fields, keyed by field
     * handle. Entries nested in a field count towards that field.
     *
     * @return array<string, string>
     */
    private function _texts(ElementInterface $element): array
    {
        $html = ['title' => (string)$element->title];
        foreach ($this->_searchableFields($element) as $field) {
            $html[$field->handle] = $this->_fieldHtml($element, $field, 0);
        }
        return array_map(fn(string $part) => $this->_toText($part), $html);
    }

    private function _toText(string $html): string
    {
        // Tags become spaces to keep words in neighbouring elements apart.
        $text = preg_replace('/<[^>]*>/', ' ', $html);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return FieldInterface[]
     */
    private function _searchableFields(ElementInterface $element): array
    {
        return array_filter(
            $element->getFieldLayout()?->getCustomFields() ?? [],
            fn(FieldInterface $field) => !$field instanceof BaseRelationField && !$field instanceof NamecheckField,
        );
    }

    private function _fieldHtml(ElementInterface $element, FieldInterface $field, int $depth): string
    {
        $value = $element->getFieldValue($field->handle);
        return $field instanceof ElementContainerFieldInterface
            ? $this->_nestedHtml($value, $depth)
            : (string)$field->getSearchKeywords($value, $element);
    }

    /**
     * An element's title and field text, with the text of any entries nested
     * in its Matrix or CKEditor fields, however deep. Nested fields count
     * whether or not they're searchable.
     */
    private function _html(ElementInterface $element, int $depth = 0): string
    {
        $parts = [(string)$element->title];
        foreach ($this->_searchableFields($element) as $field) {
            $parts[] = $this->_fieldHtml($element, $field, $depth);
        }
        return implode("\n", $parts);
    }

    private function _nestedHtml(mixed $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return '';
        }

        // CKEditor: its markup, with the entries embedded between it.
        if (is_object($value) && method_exists($value, 'getChunks')) {
            $parts = [];
            foreach ($value->getChunks() as $chunk) {
                $entry = method_exists($chunk, 'getEntry') ? $chunk->getEntry() : null;
                $parts[] = $entry ? $this->_html($entry, $depth + 1) : $chunk->getHtml();
            }
            return implode("\n", $parts);
        }

        if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection) {
            return implode("\n", array_map(
                fn(ElementInterface $nested) => $this->_html($nested, $depth + 1),
                $value->all(),
            ));
        }

        return '';
    }
}
