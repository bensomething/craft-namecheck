<?php

namespace bensomething\namecheck\models;

use bensomething\namecheck\HtmlLinker;
use craft\base\Model;

class Settings extends Model
{
    /**
     * Handles of the sections whose entries are linked to when they're mentioned.
     *
     * @var string[]
     */
    public array $targetSections = [];

    /**
     * Handles of the sections whose entries are scanned for mentions when
     * they're saved, for `craft.namecheck.mentionedIn()`. Leave empty to
     * skip keeping track.
     *
     * @var string[]
     */
    public array $sourceSections = [];

    /**
     * Link only the first mention of each entry in a piece of content.
     */
    public bool $firstOnly = true;

    /**
     * Match names only with the same capitalisation, so "Pulp" isn't found in "pulp".
     */
    public bool $caseSensitive = true;

    /**
     * Elements whose text is never linked.
     *
     * @var string[]
     */
    public array $skipTags = HtmlLinker::DEFAULT_SKIP_TAGS;

    /**
     * Extra attributes for every link, such as `['class' => 'mention']`.
     *
     * @var array<string, string|bool|null>
     */
    public array $linkAttributes = [];

    public function rules(): array
    {
        return [
            [['firstOnly', 'caseSensitive'], 'boolean'],
            [['targetSections', 'sourceSections', 'skipTags', 'linkAttributes'], 'safe'],
        ];
    }
}
