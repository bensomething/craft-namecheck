<?php

namespace bensomething\namecheck\services;

use bensomething\namecheck\enums\Mode;
use bensomething\namecheck\HtmlLinker;
use bensomething\namecheck\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use yii\caching\TagDependency;

/**
 * Links mentions in HTML as it's rendered.
 */
class Links extends Component
{
    /**
     * @param mixed $html Anything that renders as HTML, such as a CKEditor field's value
     * @param ElementInterface|null $context The element the HTML belongs to. It's
     * never linked to itself, and it decides which "only where it's related"
     * entries are linked.
     * @param array{firstOnly?: bool, limit?: int, attributes?: array, exclude?: array} $options
     */
    public function link(mixed $html, ?ElementInterface $context = null, array $options = []): string
    {
        $html = (string)$html;
        if (trim($html) === '') {
            return $html;
        }

        $siteId = $context->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $options['exclude'] = array_map(
            fn($element) => $element instanceof ElementInterface ? $element->getCanonicalId() : (int)$element,
            (array)($options['exclude'] ?? []),
        );

        $key = 'namecheck:links:' . md5(serialize([
            $html,
            $siteId,
            $context?->id,
            $context?->dateUpdated?->getTimestamp(),
            $options,
        ]));

        return Craft::$app->getCache()->getOrSet(
            $key,
            fn() => $this->_link($html, $siteId, $context, $options),
            null,
            new TagDependency(['tags' => [Names::CACHE_TAG]]),
        );
    }

    private function _link(string $html, int $siteId, ?ElementInterface $context, array $options): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $names = $plugin->getNames();

        $matcher = $names->getMatcher($siteId);
        $targets = $names->getTargets($siteId);

        $firstOnly = (bool)($options['firstOnly'] ?? $settings->firstOnly);
        $limit = isset($options['limit']) ? (int)$options['limit'] : null;
        $attributes = array_merge($settings->linkAttributes, $options['attributes'] ?? []);
        $exclude = $options['exclude'];
        if ($context !== null) {
            $exclude[] = $context->getCanonicalId();
        }

        $linked = [];
        $related = null;

        $resolve = function(int $id) use ($names, $targets, $context, $firstOnly, $limit, $attributes, $exclude, &$linked, &$related): ?array {
            $target = $targets[$id] ?? null;
            if ($target === null || !$names->isLive($target) || in_array($id, $exclude, true)) {
                return null;
            }
            if ($firstOnly && isset($linked[$id])) {
                return null;
            }
            if ($limit !== null && count($linked) >= $limit && !isset($linked[$id])) {
                return null;
            }
            if ($target['mode'] === Mode::Related->value) {
                if ($context === null) {
                    return null;
                }
                $related ??= $names->getRelatedIds($context, array_keys(array_filter(
                    $targets,
                    fn(array $target) => $target['mode'] === Mode::Related->value,
                )));
                if (!in_array($id, $related, true)) {
                    return null;
                }
            }

            $linked[$id] = true;
            return array_merge($attributes, ['href' => $target['url']]);
        };

        return (new HtmlLinker($matcher, $settings->skipTags))->link($html, $resolve);
    }
}
