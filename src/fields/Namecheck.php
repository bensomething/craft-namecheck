<?php

namespace bensomething\namecheck\fields;

use bensomething\namecheck\enums\Mode;
use bensomething\namecheck\models\NamecheckValue;
use bensomething\namecheck\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Entry;
use craft\helpers\Json;
use yii\db\Schema;

/**
 * Namecheck field: added to the entry types being linked to, it says when an
 * entry is linked and which other names it goes by. Entries without one are
 * linked wherever their title is mentioned.
 */
class Namecheck extends Field
{
    public static function displayName(): string
    {
        return Craft::t('namecheck', 'Namecheck');
    }

    public static function icon(): string
    {
        return 'comment-check';
    }

    public function useFieldset(): bool
    {
        return true;
    }

    public static function phpType(): string
    {
        return NamecheckValue::class;
    }

    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof NamecheckValue) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }
        $value = is_array($value) ? $value : [];

        $aliases = $value['aliases'] ?? [];
        if (is_string($aliases)) {
            $aliases = preg_split('/\R/', $aliases);
        }
        $aliases = array_values(array_unique(array_filter(
            array_map(fn($alias) => trim((string)$alias), (array)$aliases),
            fn(string $alias) => $alias !== '',
        )));

        return new NamecheckValue([
            'mode' => Mode::tryFrom((string)($value['mode'] ?? '')) ?? Mode::Always,
            'aliases' => $aliases,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof NamecheckValue) {
            return $value;
        }
        if ($value->isDefault()) {
            return null;
        }
        return [
            'mode' => $value->mode->value,
            'aliases' => $value->aliases,
        ];
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof NamecheckValue || $value->isDefault();
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        /** @var NamecheckValue $value */
        return Craft::$app->getView()->renderTemplate('namecheck/_field/input.twig', [
            'field' => $this,
            'name' => $this->handle,
            'value' => $value,
            'modes' => Mode::cases(),
            'sharedNamesWarning' => $this->_sharedNamesWarning($element),
        ]);
    }

    /**
     * A warning for the names this entry shares with other linked entries,
     * which aren't linked to any of them, as of its last save.
     */
    private function _sharedNamesWarning(?ElementInterface $element): ?string
    {
        $names = Plugin::getInstance()->getNames();
        if (!$element instanceof Entry || !$names->isTarget($element)) {
            return null;
        }

        $lines = [];
        foreach ($names->sharedNames($element) as $name => $others) {
            $links = array_map(function(Entry $other) {
                $label = $this->_escapeMarkdown($other->getUiLabel());
                $url = $other->getCpEditUrl();
                return $url ? "[$label]($url)" : $label;
            }, $others);
            $lines[] = Craft::t('namecheck', '**{name}** is also a name of {entries}, so it won’t be linked.', [
                'name' => $this->_escapeMarkdown($name),
                'entries' => implode(', ', $links),
            ]);
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    private function _escapeMarkdown(string $text): string
    {
        return addcslashes($text, '\\`*_[]()<>#!');
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return $value instanceof NamecheckValue ? implode(' ', $value->aliases) : '';
    }
}
