<?php

namespace bensomething\namecheck\services;

use bensomething\namecheck\enums\Mode;
use bensomething\namecheck\fields\Namecheck as NamecheckField;
use bensomething\namecheck\Matcher;
use bensomething\namecheck\models\NamecheckValue;
use bensomething\namecheck\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;
use yii\caching\TagDependency;

/**
 * The names of the entries being linked to, per site, and who they belong to.
 *
 * @phpstan-type Target array{url: ?string, mode: string, enabled: bool, postDate: ?int, expiryDate: ?int}
 */
class Names extends Component
{
    public const CACHE_TAG = 'namecheck';

    /** @var array<int, array{targets: array<int, Target>, matcher: Matcher}> */
    private array $_sites = [];

    /**
     * @return array<int, Target> Entry ID => what's needed to link it
     */
    public function getTargets(int $siteId): array
    {
        return $this->_site($siteId)['targets'];
    }

    public function getMatcher(int $siteId): Matcher
    {
        return $this->_site($siteId)['matcher'];
    }

    /**
     * Whether a target can be linked to right now. Pending and expired entries
     * are kept in the map, so they start and stop being linked on time
     * without waiting for a save.
     *
     * @param Target $target
     */
    public function isLive(array $target): bool
    {
        $now = time();
        return $target['enabled']
            && $target['url'] !== null
            && ($target['postDate'] === null || $target['postDate'] <= $now)
            && ($target['expiryDate'] === null || $target['expiryDate'] > $now);
    }

    /**
     * Finds the entries that go by each of the given names, by title or other
     * name, ignoring case. Each name must match in full, so this suits lists
     * where every item is a name, such as a line-up. Entries set to never be
     * linked aren't found; "only where it's related" doesn't apply.
     *
     * @param string[] $names
     * @return array<string, Entry> Each name that matched => its entry
     */
    public function findEntries(array $names, ?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $matcher = $this->getMatcher($siteId);

        $ids = [];
        foreach ($names as $name) {
            $id = $matcher->keyFor((string)$name, true);
            if ($id !== null) {
                $ids[(string)$name] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $entries = Entry::find()
            ->id(array_values(array_unique($ids)))
            ->siteId($siteId)
            ->indexBy('id')
            ->all();

        $found = [];
        foreach ($ids as $name => $id) {
            if (isset($entries[$id])) {
                $found[$name] = $entries[$id];
            }
        }
        return $found;
    }

    public function isTarget(ElementInterface $element): bool
    {
        return $element instanceof Entry
            && in_array($element->getSection()?->handle, Plugin::getInstance()->getSettings()->targetSections, true);
    }

    /**
     * Whether a save changed what an entry is matched by, so content saved
     * before it may now mention it (or no longer does).
     */
    public function hasNamingChanged(ElementInterface $element): bool
    {
        if ($element->firstSave || $element->isAttributeDirty('title')) {
            return true;
        }
        $field = $this->getField($element);
        return $field !== null && $element->isFieldDirty($field->handle);
    }

    public function getField(ElementInterface $element): ?NamecheckField
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof NamecheckField) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Narrows a list of entry IDs down to those related to an element, either way round.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function getRelatedIds(ElementInterface $element, array $ids): array
    {
        if ($ids === [] || !$element->id) {
            return [];
        }
        $related = Entry::find()
            ->id($ids)
            ->relatedTo($element)
            ->siteId($element->siteId)
            ->status(null)
            ->ids();
        return array_map('intval', $related);
    }

    public function invalidate(): void
    {
        $this->_sites = [];
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    /**
     * @return array{targets: array<int, Target>, matcher: Matcher}
     */
    private function _site(int $siteId): array
    {
        return $this->_sites[$siteId] ??= Craft::$app->getCache()->getOrSet(
            "namecheck:names:$siteId",
            fn() => $this->_build($siteId),
            0,
            new TagDependency(['tags' => [self::CACHE_TAG]]),
        );
    }

    /**
     * @return array{targets: array<int, Target>, matcher: Matcher}
     */
    private function _build(int $siteId): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $targets = [];
        $names = [];

        if ($settings->targetSections !== []) {
            $entries = Entry::find()
                ->section($settings->targetSections)
                ->siteId($siteId)
                ->status(null)
                ->all();

            foreach ($entries as $entry) {
                $field = $this->getField($entry);
                $value = $field ? $entry->getFieldValue($field->handle) : null;
                $value = $value instanceof NamecheckValue ? $value : new NamecheckValue();
                if ($value->mode === Mode::Never) {
                    continue;
                }

                $targets[$entry->id] = [
                    'url' => $entry->getUrl(),
                    'mode' => $value->mode->value,
                    'enabled' => $entry->enabled && $entry->getEnabledForSite(),
                    'postDate' => $entry->postDate?->getTimestamp(),
                    'expiryDate' => $entry->expiryDate?->getTimestamp(),
                ];
                $names[$entry->id] = [(string)$entry->title, ...$value->aliases];
            }
        }

        return [
            'targets' => $targets,
            'matcher' => new Matcher($names, $settings->caseSensitive),
        ];
    }
}
