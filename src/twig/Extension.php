<?php

namespace bensomething\namecheck\twig;

use bensomething\namecheck\Plugin;
use craft\base\ElementInterface;
use craft\helpers\Template;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

class Extension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('namecheck', [$this, 'namecheck']),
        ];
    }

    public function namecheck(mixed $html, ?ElementInterface $context = null, array $options = []): Markup
    {
        return Template::raw(Plugin::getInstance()->getLinks()->link($html, $context, $options));
    }
}
