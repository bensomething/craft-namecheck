<?php

namespace bensomething\namecheck\console\controllers;

use bensomething\namecheck\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Manages the record of which entries mention which.
 */
class MentionsController extends Controller
{
    /**
     * Re-indexes the mentions in every entry in the source sections.
     */
    public function actionRebuild(): int
    {
        $count = Plugin::getInstance()->getMentions()->rebuild(function(int $done, int $total) {
            if ($done === 1) {
                Console::startProgress(0, $total);
            }
            Console::updateProgress($done, $total);
            if ($done === $total) {
                Console::endProgress();
            }
        });

        $this->stdout("Indexed $count entries (counting each site separately).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
