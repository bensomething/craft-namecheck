<?php

namespace bensomething\namecheck\tests\Unit;

use bensomething\namecheck\fields\Namecheck;
use PHPUnit\Framework\TestCase;

class SharedNamesWarningTest extends TestCase
{
    public function testLinksTheOtherEntries(): void
    {
        $this->assertSame(
            '**Agathe Singer** is also a name of [Agathe Singer](https://example.test/admin/entries/461), so it won’t be linked.',
            Namecheck::sharedNamesWarning([
                'Agathe Singer' => [['label' => 'Agathe Singer', 'url' => 'https://example.test/admin/entries/461']],
            ]),
        );
    }

    public function testListsEveryOtherEntryAndEveryName(): void
    {
        $this->assertSame(
            '**Air** is also a name of [Air \\(band\\)](/a), Air Supply, so it won’t be linked. '
            . '**KB** is also a name of [Khruangbin](/k), so it won’t be linked.',
            Namecheck::sharedNamesWarning([
                'Air' => [['label' => 'Air (band)', 'url' => '/a'], ['label' => 'Air Supply', 'url' => null]],
                'KB' => [['label' => 'Khruangbin', 'url' => '/k']],
            ]),
        );
    }

    public function testEscapesMarkdownInNamesAndTitles(): void
    {
        $this->assertSame(
            '**\*NSYNC** is also a name of [\[Untitled\] \_draft\_](/u), so it won’t be linked.',
            Namecheck::sharedNamesWarning([
                '*NSYNC' => [['label' => '[Untitled] _draft_', 'url' => '/u']],
            ]),
        );
    }

    public function testNumericNamesStayNames(): void
    {
        // PHP turns the array key "311" into an integer.
        $this->assertSame(
            '**311** is also a name of [311 \\(band\\)](/3), so it won’t be linked.',
            Namecheck::sharedNamesWarning(['311' => [['label' => '311 (band)', 'url' => '/3']]]),
        );
    }

    public function testNothingSharedMeansNoWarning(): void
    {
        $this->assertNull(Namecheck::sharedNamesWarning([]));
    }

    public function testEscapeMarkdownLeavesPlainTextAlone(): void
    {
        $this->assertSame('Guns N’ Roses & Friends', Namecheck::escapeMarkdown('Guns N’ Roses & Friends'));
        $this->assertSame('a\\\\b \\`c\\` \\#1 \\!', Namecheck::escapeMarkdown('a\\b `c` #1 !'));
    }
}
