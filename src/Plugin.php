<?php

namespace justinholtweb\erpybusinesscentral;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\erpy\services\Connectors;
use justinholtweb\erpybusinesscentral\connectors\BusinessCentralConnector;
use yii\base\Event;

/**
 * Erpy for Business Central.
 *
 * A connector add-on and nothing else: no tables, no settings screen, no control panel section.
 * Erpy owns the sync engine, the identity map, the mapping UI and the log; this package's whole
 * job is to translate one vendor's API into Erpy's canonical documents. That is why it is free.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '5.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        Event::on(
            Connectors::class,
            Connectors::EVENT_REGISTER_CONNECTORS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = BusinessCentralConnector::class;
            },
        );
    }
}
