<?php

namespace bensomething\namecheck;

use bensomething\namecheck\fields\Namecheck as NamecheckField;
use bensomething\namecheck\jobs\RebuildMentions;
use bensomething\namecheck\models\Settings;
use bensomething\namecheck\services\Links;
use bensomething\namecheck\services\Mentions;
use bensomething\namecheck\services\Names;
use bensomething\namecheck\twig\Extension;
use bensomething\namecheck\variables\NamecheckVariable;
use Craft;
use craft\base\Model;
use craft\events\ElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\services\Elements;
use craft\services\Fields;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;

/**
 * Namecheck: links the names of your entries wherever your content mentions
 * them, and keeps track of who mentioned whom.
 *
 * @property-read Names $names
 * @property-read Links $links
 * @property-read Mentions $mentions
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    private bool $_rebuildQueued = false;

    public static function config(): array
    {
        return [
            'components' => [
                'names' => Names::class,
                'links' => Links::class,
                'mentions' => Mentions::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->getView()->registerTwigExtension(new Extension());

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = NamecheckField::class;
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                $event->sender->set('namecheck', NamecheckVariable::class);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event) {
                $element = $event->element;
                if (ElementHelper::isDraftOrRevision($element)) {
                    return;
                }
                if ($this->getNames()->isTarget($element)) {
                    $this->getNames()->invalidate();
                    // Content saved earlier may mention this entry by its new name.
                    if ($this->getNames()->hasNamingChanged($element)) {
                        $this->queueRebuild();
                    }
                }
                if ($this->getMentions()->isSource($element)) {
                    $this->getMentions()->index($element);
                }
            }
        );

        $onTargetGoneOrBack = function(ElementEvent $event) {
            if ($this->getNames()->isTarget($event->element)) {
                $this->getNames()->invalidate();
            }
        };
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $onTargetGoneOrBack);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $onTargetGoneOrBack);

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            static function(RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => 'namecheck',
                    'label' => Craft::t('namecheck', 'Namecheck names and links'),
                    'action' => static function() {
                        Plugin::getInstance()->getNames()->invalidate();
                    },
                ];
            }
        );
    }

    public function setSettings(array $settings): void
    {
        // An empty checkbox select posts an empty string.
        foreach (['targetSections', 'sourceSections'] as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = array_values(array_filter((array)$settings[$key]));
            }
        }
        parent::setSettings($settings);
    }

    /**
     * Re-indexes every source entry's mentions in the queue, once per request.
     */
    public function queueRebuild(): void
    {
        if ($this->_rebuildQueued || $this->getSettings()->sourceSections === []) {
            return;
        }
        Queue::push(new RebuildMentions());
        $this->_rebuildQueued = true;
    }

    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        $this->getNames()->invalidate();
        $this->queueRebuild();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sections[] = ['label' => $section->name, 'value' => $section->handle];
        }

        return Craft::$app->getView()->renderTemplate('namecheck/_settings', [
            'settings' => $this->getSettings(),
            'sections' => $sections,
        ]);
    }

    public function getNames(): Names
    {
        return $this->get('names');
    }

    public function getLinks(): Links
    {
        return $this->get('links');
    }

    public function getMentions(): Mentions
    {
        return $this->get('mentions');
    }
}
