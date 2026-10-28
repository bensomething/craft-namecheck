<?php

namespace bensomething\namecheck;

/**
 * Finds whole-word mentions of a set of names in plain text.
 *
 * Each key (an element ID) owns one or more names. Longer names win over
 * shorter ones that start at the same place, so "Pulp Fiction" beats "Pulp".
 * A name claimed by two keys is ambiguous and is dropped for both.
 */
class Matcher
{
    /**
     * Names per regex. Keeps each compiled pattern well under PCRE's size limit
     * on sites with thousands of names.
     */
    private const CHUNK_SIZE = 500;

    /** Letters, numbers and underscores: a name can't start or end next to one. */
    private const WORD_CHAR = '[\p{L}\p{N}_]';

    /** @var array<string, int> Normalised name => key */
    private array $lookup = [];

    /** @var array<string, int>|null Lowercased name => key, built the first time it's needed */
    private ?array $caselessLookup = null;

    /** @var string[] */
    private array $patterns = [];

    /**
     * @param array<int, string[]> $names Key => the names it goes by
     */
    public function __construct(array $names, private bool $caseSensitive = true)
    {
        $ambiguous = [];
        $originals = [];

        foreach ($names as $key => $keyNames) {
            foreach ($keyNames as $name) {
                $name = trim((string)$name);
                if ($name === '') {
                    continue;
                }
                $normalised = $this->normalise($name);
                if (isset($this->lookup[$normalised]) && $this->lookup[$normalised] !== $key) {
                    $ambiguous[$normalised] = true;
                    continue;
                }
                $this->lookup[$normalised] = $key;
                $originals[$normalised] = $name;
            }
        }

        foreach (array_keys($ambiguous) as $normalised) {
            unset($this->lookup[$normalised], $originals[$normalised]);
        }

        uasort($originals, fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $flags = $this->caseSensitive ? 'u' : 'iu';
        foreach (array_chunk($originals, self::CHUNK_SIZE) as $chunk) {
            $alternation = implode('|', array_map(fn(string $name) => $this->quote($name), $chunk));
            $this->patterns[] = sprintf('/(?<!%1$s)(?:%2$s)(?!%1$s)/%3$s', self::WORD_CHAR, $alternation, $flags);
        }
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /**
     * Returns the key a whole name belongs to, for text that is a name in
     * full (such as an item in a list) rather than text that mentions one.
     */
    public function keyFor(string $name, bool $ignoreCase = false): ?int
    {
        $normalised = $this->normalise(trim($name));
        if (!$ignoreCase || !$this->caseSensitive) {
            return $this->lookup[$normalised] ?? null;
        }

        if ($this->caselessLookup === null) {
            // Names that differ only by case and belong to different keys are ambiguous.
            $this->caselessLookup = [];
            $ambiguous = [];
            foreach ($this->lookup as $known => $key) {
                $lower = mb_strtolower($known);
                if (isset($this->caselessLookup[$lower]) && $this->caselessLookup[$lower] !== $key) {
                    $ambiguous[$lower] = true;
                }
                $this->caselessLookup[$lower] = $key;
            }
            $this->caselessLookup = array_diff_key($this->caselessLookup, $ambiguous);
        }
        return $this->caselessLookup[mb_strtolower($normalised)] ?? null;
    }

    /**
     * Returns every mention in the text, in order, without overlaps.
     *
     * @return array<int, array{offset: int, length: int, key: int, text: string}>
     * Offsets and lengths are in bytes.
     */
    public function find(string $text): array
    {
        if ($this->patterns === [] || trim($text) === '') {
            return [];
        }

        $matches = [];
        foreach ($this->patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($found[0] as [$match, $offset]) {
                $key = $this->lookup[$this->normalise($match)] ?? null;
                if ($key === null) {
                    continue;
                }
                $matches[] = [
                    'offset' => $offset,
                    'length' => strlen($match),
                    'key' => $key,
                    'text' => $match,
                ];
            }
        }

        if (count($this->patterns) === 1) {
            return $matches;
        }

        // Matches from different chunks can overlap. Keep the earliest, then the longest.
        usort($matches, fn(array $a, array $b) => [$a['offset'], $b['length']] <=> [$b['offset'], $a['length']]);
        $kept = [];
        $end = 0;
        foreach ($matches as $match) {
            if ($match['offset'] < $end) {
                continue;
            }
            $kept[] = $match;
            $end = $match['offset'] + $match['length'];
        }
        return $kept;
    }

    /**
     * Folds the differences a name can be written with but still mean the same:
     * runs of whitespace, curly apostrophes, and (when matching ignores case) case.
     */
    private function normalise(string $name): string
    {
        $name = preg_replace('/[\s\x{00A0}]+/u', ' ', $name);
        $name = str_replace(['’', '‘'], "'", $name);
        return $this->caseSensitive ? $name : mb_strtolower($name);
    }

    private function quote(string $name): string
    {
        $parts = preg_split('/[\s\x{00A0}]+/u', $name);
        $parts = array_map(fn(string $part) => str_replace("'", "['‘’]", preg_quote(str_replace(['’', '‘'], "'", $part), '/')), $parts);
        return implode('[\s\x{00A0}]+', $parts);
    }
}
