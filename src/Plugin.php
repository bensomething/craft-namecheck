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
use craft\base\Element;
use craft\base\Model;
use craft\elements\Entry;
use craft\events\ConfigEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Queue;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\ProjectConfig;
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
    public string $schemaVersion = '1.0.1';
    public bool $hasCpSettings = true;

    /** How many mentioning entries the sidebar panel lists. */
    private const SIDEBAR_LIMIT = 10;

    /** How far Craft pulls sidebar legends left of their fieldset's content. */
    private const SIDEBAR_OUTDENT = 'calc(var(--m) - var(--padding))';

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

        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $event->html .= $this->_mentionedInHtml($entry);
            }
        );

        // Settings changed by `project-config/apply` never reach afterSaveSettings().
        $settingsPath = ProjectConfig::PATH_PLUGINS . '.' . $this->handle . '.settings';
        $onSettingsChanged = function(ConfigEvent $event) {
            $this->setSettings((array)($event->newValue ?? []));
            $this->getNames()->invalidate();
            $this->queueRebuild();
        };
        Craft::$app->getProjectConfig()
            ->onAdd($settingsPath, $onSettingsChanged)
            ->onUpdate($settingsPath, $onSettingsChanged);
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

    /**
     * The "Mentioned In" sidebar panel for a linked entry: the entries that
     * mention it, newest first, leaving out any the user can't view.
     */
    private function _mentionedInHtml(Entry $entry): string
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (
            !$user
            || $this->getSettings()->sourceSections === []
            || $entry->getIsUnpublishedDraft()
            || !$this->getNames()->isTarget($entry)
        ) {
            return '';
        }

        $query = $this->getMentions()->mentionedIn($entry)
            ->status(null)
            ->orderBy(['entries.postDate' => SORT_DESC]);
        $total = (int)$query->count();
        $sources = array_filter(
            $query->limit(self::SIDEBAR_LIMIT)->all(),
            fn(Entry $source) => $source->canView($user),
        );

        if ($sources === []) {
            $body = $this->_sidebarNote(Craft::t('namecheck', 'Not mentioned anywhere yet.'), '0');
        } else {
            $fields = $this->getMentions()->fieldsMentionedIn(
                $entry,
                array_map(fn(Entry $source) => $source->id, $sources),
            );
            $rows = implode('', array_map(
                fn(Entry $source) => $this->_sidebarRow(
                    Html::tag('span', '', ['class' => ['status', $source->getStatus()]]) .
                    ($source->getCpEditUrl()
                        ? Html::a(Html::encode($source->getUiLabel()), $source->getCpEditUrl())
                        : Html::encode($source->getUiLabel())),
                    Html::encode(implode(', ', $this->_fieldLabels($source, $fields[$source->id] ?? []))),
                ),
                $sources,
            ));
            $body = Html::tag('dl', $rows, ['class' => ['meta', 'read-only']]);
            if ($total > self::SIDEBAR_LIMIT) {
                $body .= $this->_sidebarNote(Craft::t('namecheck', 'And {count, number} more.', [
                    'count' => $total - self::SIDEBAR_LIMIT,
                ]), 'var(--s)');
            }
        }

        // Craft pulls sidebar legends and metadata labels out by
        // `--padding - --m` past the fieldset's content edge. The fieldset is
        // widened by the same amount on each side, so its bottom border spans
        // what's visible, and padded back so the rows stay put. Core sets the
        // margin with !important, hence the !important here.
        return Html::tag('fieldset',
            Html::tag('legend', Craft::t('namecheck', 'Mentioned In'), ['class' => 'h6']) . $body,
            ['style' => [
                'margin-inline' => self::SIDEBAR_OUTDENT . ' !important',
                'padding' => '0 calc(var(--padding) - var(--m)) var(--spacing)',
                'border-block-end' => '1px solid var(--border-hairline)',
            ]],
        );
    }

    /**
     * A line of muted text in the panel, lined up with the legend.
     */
    private function _sidebarNote(string $text, string $marginTop): string
    {
        return Html::tag('p', Html::encode($text), [
            'class' => 'light',
            'style' => [
                'margin-block' => "$marginTop 0",
                'margin-inline-start' => self::SIDEBAR_OUTDENT,
            ],
        ]);
    }

    private function _sidebarRow(string $headingHtml, string $valueHtml): string
    {
        return Html::tag('div',
            Html::tag('dt', $headingHtml, ['class' => 'heading']) .
            Html::tag('dd', $valueHtml, ['class' => 'value']),
            ['class' => 'data'],
        );
    }

    /**
     * The labels of the fields a source mentions an entry in, as its own
     * layout names them.
     *
     * @param string[] $handles
     * @return string[]
     */
    private function _fieldLabels(Entry $source, array $handles): array
    {
        $layout = $source->getFieldLayout();
        return array_map(function(string $handle) use ($layout) {
            if ($handle === 'title') {
                return Craft::t('app', 'Title');
            }
            return $layout?->getFieldByHandle($handle)->name ?? $handle;
        }, $handles);
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
