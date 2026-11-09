<?php

namespace Icinga\Module\Map\ProvidedHook\Dashboards;

use Icinga\Application\Modules\Module;
use Icinga\Module\Dashboards\Hook\DashletControls;
use Icinga\Module\Dashboards\Hook\DashletHook;
use Icinga\Module\Map\Common\ModuleConfig;
use Icinga\Module\Map\ProvidedHook\Icingadb\IcingadbSupport;
use ipl\Web\Url;

/**
 * Offers the map as a dashlet type for the dashboards module.
 *
 * Nothing is drawn here: the route is `map`, which already renders compact when embedded (`showCompact`), and
 * which polls its own markers. What a dashlet sets is what the URL of the full map can carry.
 *
 * The cluster and popup options are left out on purpose: `module.js` keeps them as `window` globals, so with
 * two maps on one page the last one rendered would decide for both.
 */
class Map extends DashletHook
{
    private const ROUTE = 'map';

    private const OBJECT_TYPES = ['all' => 'Hosts and Services', 'host' => 'Hosts', 'service' => 'Services'];

    private const SHOW = ['all' => 'Everything', 'problems' => 'Problems Only'];

    private const STATE_TYPES = ['soft' => 'Soft States', 'hard' => 'Hard States'];

    /** @var string[] What the full map reads from its URL that no control here holds */
    private const UNSUPPORTED = [
        'min_zoom',
        'max_zoom',
        'max_native_zoom',
        'disable_cluster_at_zoom',
        'cluster_problem_count',
        'popup_mouseover',
        'tile_url',
        'showHost',
        'expand',
    ];

    public function getName(): string
    {
        return 'map';
    }

    public function getLabel(): string
    {
        return $this->translateInDomain('map', 'Map');
    }

    public function getDescription(): string
    {
        return $this->translateInDomain('map', 'Hosts and services with a location on a map.');
    }

    public function getModuleLabel(): ?string
    {
        return $this->translateInDomain('map', 'Map');
    }

    public function configure(DashletControls $controls): void
    {
        $config = ModuleConfig::getConfig();
        $defaults = ModuleConfig::DEFAULTS;

        $stored = ModuleConfig::getMapsConfig()->keys();

        if ($stored) {
            $options = ['' => $this->translateInDomain('map', 'None')];

            foreach ($stored as $name) {
                $options[$name] = $name;
            }

            $controls->addElement('select', 'load', [
                'label'   => $this->translateInDomain('map', 'Stored Map'),
                'options' => $options,
                'value'   => '',
            ]);
        }

        $objectTypes = [];

        foreach (self::OBJECT_TYPES as $name => $label) {
            $objectTypes[$name] = $this->translateInDomain('map', $label);
        }

        $controls->objectType($objectTypes);
        $controls->filter();

        $controls->addElement('select', 'show', [
            'label'   => $this->translateInDomain('map', 'Show'),
            'options' => array_map(function ($label) {
                return $this->translateInDomain('map', $label);
            }, self::SHOW),
            'value'   => 'all',
        ]);

        $controls->addElement('select', 'state_type', [
            'label'   => $this->translateInDomain('map', 'State Type'),
            'options' => array_map(function ($label) {
                return $this->translateInDomain('map', $label);
            }, self::STATE_TYPES),
            'value'   => $config->get('map', 'stateType', $defaults['stateType']),
        ]);

        $controls->heading($this->translateInDomain('map', 'Position'));

        // Added in a later dashboards release than the hook itself; calling a missing method is a fatal there
        if (method_exists($controls, 'interact')) {
            $controls->interact(
                $this->translateInDomain('map', 'Position on Dashlet'),
                $this->translateInDomain('map', 'Drag the map to choose its center')
            );
        }

        // Read-only: set by panning the dashlet, and drawn as `lat: …, long: …` rather than as fields
        foreach (['lat', 'long'] as $name) {
            $controls->addElement('input', $name, [
                'type'        => 'number',
                'step'        => 'any',
                'readonly'    => true,
                'label'       => $this->translateInDomain('map', $name),
                'placeholder' => (string) $config->get('map', "default_$name", $defaults["default_$name"]),
            ]);
        }

        $controls->addElement('input', 'zoom', [
            'type'  => 'range',
            'label' => $this->translateInDomain('map', 'Zoom'),
            'min'   => (int) $config->get('map', 'min_zoom', $defaults['min_zoom']),
            'max'   => (int) $config->get('map', 'max_zoom', $defaults['max_zoom']),
            'step'  => 1,
            'value' => (int) $config->get('map', 'default_zoom', $defaults['default_zoom']),
        ]);
    }

    public function getRoute(array $settings): ?Url
    {
        $url = Url::fromPath(self::ROUTE);

        if (($settings['load'] ?? '') !== '') {
            $url->setParam('load', $settings['load']);
        }

        $objectType = $settings[DashletControls::OBJECT_TYPE] ?? 'all';
        $url->setParam('objectType', isset(self::OBJECT_TYPES[$objectType]) ? $objectType : 'all');

        if (($settings['show'] ?? '') === 'problems') {
            $url->setParam('problems', true);
        }

        $stateType = $settings['state_type'] ?? '';

        if (isset(self::STATE_TYPES[$stateType])) {
            $url->setParam('stateType', $stateType);
        }

        // Left to the module's own default while empty, which is what a map with no position says
        foreach (['lat' => 90, 'long' => 180] as $name => $limit) {
            $value = $settings[$name] ?? '';

            if (is_numeric($value) && abs((float) $value) <= $limit) {
                $url->setParam("default_$name", $value);
            }
        }

        if (is_numeric($settings['zoom'] ?? null)) {
            $url->setParam('default_zoom', (int) $settings['zoom']);
        }

        return $url;
    }

    /**
     * Claim the map, with its position, filter and stored map kept
     *
     * Declines a URL that sets what no control here holds, so it arrives as a View dashlet instead of losing it.
     */
    public function capture(Url $url): ?array
    {
        if ($url->getPath() !== self::ROUTE && $url->getPath() !== self::ROUTE . '/index') {
            return null;
        }

        $params = $url->getParams();

        foreach (self::UNSUPPORTED as $name) {
            if ($params->has($name)) {
                return null;
            }
        }

        $settings = [];

        $objectType = $params->shift('objectType', '');

        if (isset(self::OBJECT_TYPES[$objectType])) {
            $settings[DashletControls::OBJECT_TYPE] = $objectType;
        } elseif ($objectType !== '') {
            return null;
        }

        if ($params->has('problems')) {
            $params->remove('problems');
            $settings['show'] = 'problems';
        }

        foreach (['load' => 'load', 'stateType' => 'state_type', 'default_lat' => 'lat',
                  'default_long' => 'long', 'default_zoom' => 'zoom'] as $param => $name) {
            $value = $params->shift($param);

            if ($value !== null) {
                $settings[$name] = (string) $value;
            }
        }

        return $settings;
    }

    public function getFilterSuggestionUrl(?string $objectType): ?Url
    {
        // The Monitoring module has no completion route to ask
        if (! Module::exists('icingadb') || ! IcingadbSupport::useIcingaDbAsBackend()) {
            return null;
        }

        return Url::fromPath($objectType === 'service' ? 'icingadb/services/complete' : 'icingadb/hosts/complete');
    }

    /**
     * Refuse a column the map would drop
     *
     * `module.js` hands only parameters starting with `host`, `service`, `_host` or `_service` on to the data
     * call (`isFilterParameter()`), so any other condition would be saved and then silently not applied.
     */
    public function resolveFilterColumn(?string $objectType, string $column): ?array
    {
        return preg_match('/^_?(host|service)/', $column) === 1 ? ['column' => $column, 'label' => $column] : null;
    }
}
