<?php

namespace bensomething\namecheck\fields;

use bensomething\namecheck\enums\Mode;
use bensomething\namecheck\models\NamecheckValue;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
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
        return 'link';
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
        ]);
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return $value instanceof NamecheckValue ? implode(' ', $value->aliases) : '';
    }
}
