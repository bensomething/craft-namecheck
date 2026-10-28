<?php

namespace bensomething\namecheck\jobs;

use bensomething\namecheck\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Re-indexes every source entry's mentions.
 */
class RebuildMentions extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->getMentions()->rebuild(function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $done / max($total, 1));
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('namecheck', 'Updating mentions');
    }
}
