<?php

namespace bensomething\namecheck\tests\Unit;

use bensomething\namecheck\HtmlLinker;
use bensomething\namecheck\Matcher;
use PHPUnit\Framework\TestCase;

class HtmlLinkerTest extends TestCase
{
    private HtmlLinker $linker;

    protected function setUp(): void
    {
        $this->linker = new HtmlLinker(new Matcher([1 => ['Pulp'], 2 => ['Björk']]));
    }

    private function link(string $html, ?callable $resolve = null): string
    {
        return $this->linker->link($html, $resolve ?? fn(int $key) => ['href' => "/performers/$key"]);
    }

    public function testLinksMentionsInText(): void
    {
        $this->assertSame(
            '<p>See <a href="/performers/1">Pulp</a> and <strong><a href="/performers/2">Björk</a></strong> tonight.</p>',
            $this->link('<p>See Pulp and <strong>Björk</strong> tonight.</p>'),
        );
    }

    public function testLeavesSkippedElementsAlone(): void
    {
        $html = '<h2>Pulp</h2><p><a href="/x">Pulp live</a></p><figure><img src="a.jpg" alt="Pulp"><figcaption>Pulp</figcaption></figure>';

        $this->assertSame($html, $this->link($html));
    }

    public function testReturnsUnmatchedHtmlUntouched(): void
    {
        $html = "<p>Nothing&nbsp;here <br>at all</p>\n<div data-x='1'>ok</div>";

        $this->assertSame($html, $this->link($html));
    }

    public function testResolverCanDeclineAMention(): void
    {
        $linked = [];
        $html = $this->link('<p>Pulp, Pulp and Björk</p>', function(int $key) use (&$linked) {
            if (isset($linked[$key])) {
                return null;
            }
            $linked[$key] = true;
            return ['href' => "/p/$key", 'class' => 'mention'];
        });

        $this->assertSame('<p><a href="/p/1" class="mention">Pulp</a>, Pulp and <a href="/p/2" class="mention">Björk</a></p>', $html);
    }

    public function testKeepsEntitiesAndMultipleRootNodes(): void
    {
        $this->assertSame(
            '<p>Tom &amp; <a href="/performers/1">Pulp</a></p><p>“Quoted” text</p>',
            $this->link('<p>Tom &amp; Pulp</p><p>“Quoted” text</p>'),
        );
    }

    public function testHrefIsEscaped(): void
    {
        $html = $this->link('<p>Pulp</p>', fn() => ['href' => '/a?b=1&c="2"']);

        $this->assertSame('<p><a href="/a?b=1&amp;c=%222%22">Pulp</a></p>', $html);
    }
}
