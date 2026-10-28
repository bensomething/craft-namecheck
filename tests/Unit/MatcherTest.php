<?php

namespace bensomething\namecheck\tests\Unit;

use bensomething\namecheck\Matcher;
use PHPUnit\Framework\TestCase;

class MatcherTest extends TestCase
{
    private function keys(Matcher $matcher, string $text): array
    {
        return array_column($matcher->find($text), 'key');
    }

    public function testFindsWholeWordsOnly(): void
    {
        $matcher = new Matcher([1 => ['Pulp'], 2 => ['Air']]);

        $this->assertSame([1, 2], $this->keys($matcher, 'Pulp played after Air.'));
        $this->assertSame([], $this->keys($matcher, 'Pulpy Airline'));
        $this->assertSame([1], $this->keys($matcher, 'Pulp’s new album'));
    }

    public function testMatchesCaseUnlessToldNotTo(): void
    {
        $names = [1 => ['Pulp']];

        $this->assertSame([], $this->keys(new Matcher($names), 'orange juice with pulp'));
        $this->assertSame([1], $this->keys(new Matcher($names, false), 'orange juice with pulp'));
    }

    public function testPrefersTheLongestName(): void
    {
        $matcher = new Matcher([1 => ['Pulp'], 2 => ['Pulp Fiction']]);

        $matches = $matcher->find('Pulp Fiction and Pulp');
        $this->assertSame([2, 1], array_column($matches, 'key'));
        $this->assertSame('Pulp Fiction', $matches[0]['text']);
    }

    public function testAliasesAndPunctuatedNames(): void
    {
        $matcher = new Matcher([1 => ['The Beatles', 'Beatles'], 2 => ['O.'], 3 => ['Mk.Gee']]);

        $this->assertSame([1, 1], $this->keys($matcher, 'The Beatles, or just Beatles'));
        $this->assertSame([2, 3], $this->keys($matcher, 'Support from O. and Mk.Gee'));
    }

    public function testApostrophesAndSpacesMatchEitherWay(): void
    {
        $matcher = new Matcher([1 => ["Guns N' Roses"]]);

        $this->assertSame([1], $this->keys($matcher, 'Guns N’ Roses'));
        $this->assertSame([1], $this->keys($matcher, "Guns\u{00A0}N' Roses"));
    }

    public function testDropsNamesClaimedTwice(): void
    {
        $matcher = new Matcher([1 => ['Khruangbin', 'KB'], 2 => ['Khruangbin']]);

        $this->assertSame([1], $this->keys($matcher, 'Khruangbin, aka KB'));
    }

    public function testOffsetsAreBytes(): void
    {
        $matches = (new Matcher([1 => ['Björk']]))->find('Héllo Björk');

        $this->assertSame(strlen('Héllo '), $matches[0]['offset']);
        $this->assertSame(strlen('Björk'), $matches[0]['length']);
    }

    public function testEmpty(): void
    {
        $matcher = new Matcher([1 => ['', '  ']]);

        $this->assertTrue($matcher->isEmpty());
        $this->assertSame([], $matcher->find('anything'));
    }
}
