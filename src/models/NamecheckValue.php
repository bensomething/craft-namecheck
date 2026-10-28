<?php

namespace bensomething\namecheck\models;

use bensomething\namecheck\enums\Mode;
use craft\base\Model;

/**
 * A Namecheck field's value: when its entry is linked, and the other names it goes by.
 */
class NamecheckValue extends Model
{
    public Mode $mode = Mode::Always;

    /** @var string[] */
    public array $aliases = [];

    public function isDefault(): bool
    {
        return $this->mode === Mode::Always && $this->aliases === [];
    }

    public function __toString(): string
    {
        return implode(', ', $this->aliases);
    }
}
