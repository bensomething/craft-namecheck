<?php

namespace bensomething\namecheck;

use DOMDocument;
use DOMXPath;

/**
 * Wraps mentions found in an HTML fragment's text in links.
 *
 * Only text is touched: tags, attributes and anything inside a skipped
 * element (existing links, headings, code…) are left alone. HTML with
 * nothing to link comes back exactly as it went in.
 */
class HtmlLinker
{
    public const DEFAULT_SKIP_TAGS = [
        'a',
        'button',
        'code',
        'figcaption',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'kbd',
        'label',
        'option',
        'pre',
        'script',
        'select',
        'style',
        'textarea',
    ];

    /**
     * @param string[] $skipTags Elements whose text is never linked
     */
    public function __construct(
        private Matcher $matcher,
        private array $skipTags = self::DEFAULT_SKIP_TAGS,
    ) {
    }

    /**
     * @param callable(int $key, string $text): ?array $resolve Called for each
     * mention, in document order. Returns the link's attributes (`href`
     * included), or null to leave that mention as text.
     */
    public function link(string $html, callable $resolve): string
    {
        if ($this->matcher->isEmpty() || trim($html) === '') {
            return $html;
        }

        // A rough pass over the text first, so HTML that can't contain a
        // mention is never parsed. Tags become spaces to keep words apart.
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($this->matcher->find($text) === []) {
            return $html;
        }

        $doc = new DOMDocument();
        $useErrors = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }

        $skip = ' ' . implode(' ', array_map('strtolower', $this->skipTags)) . ' ';
        $nodes = (new DOMXPath($doc))->query(
            sprintf('//body//text()[not(ancestor::*[contains("%s", concat(" ", local-name(), " "))])]', $skip),
        );

        $changed = false;
        foreach (iterator_to_array($nodes) as $node) {
            $value = $node->nodeValue;
            if ($value === null || trim($value) === '') {
                continue;
            }

            $fragment = $doc->createDocumentFragment();
            $position = 0;
            foreach ($this->matcher->find($value) as $match) {
                $attributes = $resolve($match['key'], $match['text']);
                if ($attributes === null) {
                    continue;
                }

                if ($match['offset'] > $position) {
                    $fragment->appendChild($doc->createTextNode(substr($value, $position, $match['offset'] - $position)));
                }
                $link = $doc->createElement('a');
                foreach ($attributes as $name => $attribute) {
                    if ($attribute === null || $attribute === false) {
                        continue;
                    }
                    $link->setAttribute($name, $attribute === true ? '' : (string)$attribute);
                }
                $link->appendChild($doc->createTextNode($match['text']));
                $fragment->appendChild($link);
                $position = $match['offset'] + $match['length'];
            }

            if ($position === 0) {
                continue;
            }
            if ($position < strlen($value)) {
                $fragment->appendChild($doc->createTextNode(substr($value, $position)));
            }
            $node->parentNode->replaceChild($fragment, $node);
            $changed = true;
        }

        if (!$changed) {
            return $html;
        }

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }
        return $output;
    }
}
