<?php

namespace bensomething\namecheck\behaviors;

use yii\base\Behavior;

/**
 * Holds an entry's Mentioned In count when an element index selects it, so
 * the column doesn't need a query per row.
 */
class MentionCountBehavior extends Behavior
{
    /** How many entries mention this one, when the query selected it. */
    public ?int $namecheckMentionedIn = null;
}
