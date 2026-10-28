<?php

namespace bensomething\namecheck\enums;

use Craft;

/**
 * When an entry's name is linked.
 */
enum Mode: string
{
    /** Wherever it's mentioned. */
    case Always = 'always';

    /** Only in content that relates the entry, for names that are also everyday words. */
    case Related = 'related';

    /** Never: the entry isn't linked or counted as mentioned. */
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Always => Craft::t('namecheck', 'Wherever it’s mentioned'),
            self::Related => Craft::t('namecheck', 'Only where it’s related'),
            self::Never => Craft::t('namecheck', 'Never'),
        };
    }
}
