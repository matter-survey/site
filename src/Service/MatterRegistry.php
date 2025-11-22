<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Repository\ClusterRepository;
use App\Repository\DeviceTypeRepository;

/**
 * Registry for Matter cluster and device type name lookups.
 *
 * Data is loaded from the database which contains comprehensive information
 * extracted from the Matter 1.4 Device Library Specification.
 */
class MatterRegistry
{
    /**
     * Extended device type data loaded from database.
     * @var array<int, array>|null
     */
    private ?array $extendedDeviceTypes = null;

    /**
     * Cluster data loaded from database.
     * @var array<int, array>|null
     */
    private ?array $clusters = null;

    public function __construct(
        private ?DeviceTypeRepository $deviceTypeRepository = null,
        private ?ClusterRepository $clusterRepository = null,
    ) {}

    private const CLUSTER_NAMES = [
        0x0003 => 'Identify',
        0x0004 => 'Groups',
        0x0005 => 'Scenes',
        0x0006 => 'On/Off',
        0x0008 => 'Level Control',
        0x001D => 'Descriptor',
        0x001E => 'Binding',
        0x001F => 'Access Control',
        0x0028 => 'Basic Information',
        0x0029 => 'OTA Software Update Provider',
        0x002A => 'OTA Software Update Requestor',
        0x002B => 'Localization Configuration',
        0x002C => 'Time Format Localization',
        0x002D => 'Unit Localization',
        0x002E => 'Power Source Configuration',
        0x002F => 'Power Source',
        0x0030 => 'General Commissioning',
        0x0031 => 'Network Commissioning',
        0x0032 => 'Diagnostic Logs',
        0x0033 => 'General Diagnostics',
        0x0034 => 'Software Diagnostics',
        0x0035 => 'Thread Network Diagnostics',
        0x0036 => 'WiFi Network Diagnostics',
        0x0037 => 'Ethernet Network Diagnostics',
        0x0038 => 'Time Synchronization',
        0x0039 => 'Bridged Device Basic Information',
        0x003C => 'Administrator Commissioning',
        0x003E => 'Node Operational Credentials',
        0x003F => 'Group Key Management',
        0x0040 => 'Fixed Label',
        0x0041 => 'User Label',
        0x0045 => 'Boolean State',
        0x0050 => 'Mode Select',
        0x0101 => 'Door Lock',
        0x0102 => 'Window Covering',
        0x0200 => 'Pump Configuration and Control',
        0x0201 => 'Thermostat',
        0x0202 => 'Fan Control',
        0x0204 => 'Thermostat User Interface Configuration',
        0x0300 => 'Color Control',
        0x0301 => 'Ballast Configuration',
        0x0400 => 'Illuminance Measurement',
        0x0402 => 'Temperature Measurement',
        0x0403 => 'Pressure Measurement',
        0x0404 => 'Flow Measurement',
        0x0405 => 'Relative Humidity Measurement',
        0x0406 => 'Occupancy Sensing',
        0x0500 => 'IAS Zone',
        0x0503 => 'Wake on LAN',
        0x0504 => 'Channel',
        0x0505 => 'Target Navigator',
        0x0506 => 'Media Playback',
        0x0507 => 'Media Input',
        0x0508 => 'Low Power',
        0x0509 => 'Keypad Input',
        0x050A => 'Content Launcher',
        0x050B => 'Audio Output',
        0x050C => 'Application Launcher',
        0x050D => 'Application Basic',
        0x050E => 'Account Login',
    ];

    /**
     * Device type metadata with spec version, categories, icons, and descriptions.
     * Keys are decimal device type IDs (hex shown in comments).
     */
    private const DEVICE_TYPE_METADATA = [
        // Utility/System Device Types
        10 => [ // 0x000A
            'name' => 'Door Lock',
            'specVersion' => '1.0',
            'category' => 'closures',
            'displayCategory' => 'Security',
            'icon' => 'lock',
            'description' => 'Electronically controlled door lock',
        ],
        11 => [ // 0x000B
            'name' => 'Door Lock Controller',
            'specVersion' => '1.0',
            'category' => 'closures',
            'displayCategory' => 'Security',
            'icon' => 'lock',
            'description' => 'Controller for door lock devices',
        ],
        14 => [ // 0x000E
            'name' => 'Aggregator',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'hub',
            'description' => 'Aggregates multiple bridged endpoints',
        ],
        15 => [ // 0x000F
            'name' => 'Generic Switch',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'toggle-on',
            'description' => 'Generic switch or button device',
        ],
        17 => [ // 0x0011
            'name' => 'Power Source',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'battery',
            'description' => 'Power source information endpoint',
        ],
        18 => [ // 0x0012
            'name' => 'OTA Requestor',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'download',
            'description' => 'Over-the-air update requestor',
        ],
        19 => [ // 0x0013
            'name' => 'Bridged Node',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'bridge',
            'description' => 'Represents a bridged device from another protocol',
        ],
        20 => [ // 0x0014
            'name' => 'OTA Provider',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'upload',
            'description' => 'Over-the-air update provider',
        ],
        21 => [ // 0x0015
            'name' => 'Contact Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'door-open',
            'description' => 'Detects open/closed state of doors or windows',
        ],
        22 => [ // 0x0016
            'name' => 'Root Node',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'server',
            'description' => 'Root endpoint of a Matter node',
        ],
        23 => [ // 0x0017
            'name' => 'Solar Power',
            'specVersion' => '1.4',
            'category' => 'energy',
            'displayCategory' => 'Energy',
            'icon' => 'solar-panel',
            'description' => 'Solar panel or photovoltaic system',
        ],
        24 => [ // 0x0018
            'name' => 'Battery Storage',
            'specVersion' => '1.4',
            'category' => 'energy',
            'displayCategory' => 'Energy',
            'icon' => 'battery-full',
            'description' => 'Battery energy storage system',
        ],
        25 => [ // 0x0019
            'name' => 'Secondary Network Interface',
            'specVersion' => '1.4',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'network-wired',
            'description' => 'Secondary network commissioning interface',
        ],

        // Media Device Types
        34 => [ // 0x0022
            'name' => 'Speaker',
            'specVersion' => '1.0',
            'category' => 'media',
            'displayCategory' => 'Entertainment',
            'icon' => 'volume-up',
            'description' => 'Audio speaker device',
        ],
        35 => [ // 0x0023
            'name' => 'Casting Video Player',
            'specVersion' => '1.0',
            'category' => 'media',
            'displayCategory' => 'Entertainment',
            'icon' => 'tv',
            'description' => 'Video player that accepts cast content',
        ],
        36 => [ // 0x0024
            'name' => 'Content App',
            'specVersion' => '1.0',
            'category' => 'media',
            'displayCategory' => 'Entertainment',
            'icon' => 'play-circle',
            'description' => 'Content application on a video player',
        ],
        39 => [ // 0x0027
            'name' => 'Mode Select',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'sliders',
            'description' => 'Device with selectable operating modes',
        ],
        40 => [ // 0x0028
            'name' => 'Basic Video Player',
            'specVersion' => '1.0',
            'category' => 'media',
            'displayCategory' => 'Entertainment',
            'icon' => 'tv',
            'description' => 'Basic video playback device',
        ],
        41 => [ // 0x0029
            'name' => 'Casting Video Client',
            'specVersion' => '1.0',
            'category' => 'media',
            'displayCategory' => 'Entertainment',
            'icon' => 'cast',
            'description' => 'Client that casts content to video players',
        ],
        42 => [ // 0x002A
            'name' => 'Video Remote Control',
            'specVersion' => '1.0',
            'category' => 'media',
            'displayCategory' => 'Entertainment',
            'icon' => 'remote',
            'description' => 'Remote control for video devices',
        ],

        // HVAC Device Types (0x002x range)
        43 => [ // 0x002B
            'name' => 'Fan',
            'specVersion' => '1.2',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'fan',
            'description' => 'Controllable fan device',
        ],
        44 => [ // 0x002C
            'name' => 'Air Quality Sensor',
            'specVersion' => '1.2',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'wind',
            'description' => 'Measures air quality parameters',
        ],
        45 => [ // 0x002D
            'name' => 'Air Purifier',
            'specVersion' => '1.2',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'leaf',
            'description' => 'Air purification device',
        ],

        // Sensor Device Types (0x004x range)
        65 => [ // 0x0041
            'name' => 'Water Freeze Detector',
            'specVersion' => '1.3',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'snowflake',
            'description' => 'Detects freezing water conditions',
        ],
        66 => [ // 0x0042
            'name' => 'Water Valve',
            'specVersion' => '1.3',
            'category' => 'closures',
            'displayCategory' => 'Security',
            'icon' => 'faucet',
            'description' => 'Controllable water valve',
        ],
        67 => [ // 0x0043
            'name' => 'Water Leak Detector',
            'specVersion' => '1.3',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'droplet',
            'description' => 'Detects water leaks',
        ],
        68 => [ // 0x0044
            'name' => 'Rain Sensor',
            'specVersion' => '1.3',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'cloud-rain',
            'description' => 'Detects rain or precipitation',
        ],

        // Appliance Device Types (0x007x range)
        112 => [ // 0x0070
            'name' => 'Refrigerator',
            'specVersion' => '1.2',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'snowflake',
            'description' => 'Refrigerator or freezer appliance',
        ],
        113 => [ // 0x0071
            'name' => 'Temperature Controlled Cabinet',
            'specVersion' => '1.2',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'box',
            'description' => 'Temperature-controlled storage cabinet',
        ],
        114 => [ // 0x0072
            'name' => 'Room Air Conditioner',
            'specVersion' => '1.2',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'snowflake',
            'description' => 'Room air conditioning unit',
        ],
        115 => [ // 0x0073
            'name' => 'Laundry Washer',
            'specVersion' => '1.2',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'washing-machine',
            'description' => 'Clothes washing machine',
        ],
        116 => [ // 0x0074
            'name' => 'Robotic Vacuum Cleaner',
            'specVersion' => '1.2',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'robot',
            'description' => 'Autonomous vacuum cleaning robot',
        ],
        117 => [ // 0x0075
            'name' => 'Dishwasher',
            'specVersion' => '1.2',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'utensils',
            'description' => 'Automatic dishwashing appliance',
        ],
        118 => [ // 0x0076
            'name' => 'Smoke/CO Alarm',
            'specVersion' => '1.2',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'bell',
            'description' => 'Smoke and carbon monoxide detector',
        ],
        119 => [ // 0x0077
            'name' => 'Cook Surface',
            'specVersion' => '1.3',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'fire',
            'description' => 'Individual cooking surface or burner',
        ],
        120 => [ // 0x0078
            'name' => 'Cooktop',
            'specVersion' => '1.3',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'fire',
            'description' => 'Cooktop with multiple cooking surfaces',
        ],
        121 => [ // 0x0079
            'name' => 'Microwave Oven',
            'specVersion' => '1.3',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'microwave',
            'description' => 'Microwave cooking appliance',
        ],
        122 => [ // 0x007A
            'name' => 'Extractor Hood',
            'specVersion' => '1.3',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'wind',
            'description' => 'Kitchen ventilation hood',
        ],
        123 => [ // 0x007B
            'name' => 'Oven',
            'specVersion' => '1.3',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'fire',
            'description' => 'Conventional or convection oven',
        ],
        124 => [ // 0x007C
            'name' => 'Laundry Dryer',
            'specVersion' => '1.3',
            'category' => 'appliances',
            'displayCategory' => 'Appliances',
            'icon' => 'wind',
            'description' => 'Clothes drying appliance',
        ],

        // Network Infrastructure
        145 => [ // 0x0091
            'name' => 'Thread Border Router',
            'specVersion' => '1.4',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'router',
            'description' => 'Thread network border router',
        ],

        // Lighting Device Types (0x010x range)
        256 => [ // 0x0100
            'name' => 'On/Off Light',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'lightbulb',
            'description' => 'Simple on/off light',
        ],
        257 => [ // 0x0101
            'name' => 'Dimmable Light',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'lightbulb',
            'description' => 'Light with adjustable brightness',
        ],
        259 => [ // 0x0103
            'name' => 'On/Off Light Switch',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'toggle-on',
            'description' => 'Physical switch for on/off lights',
        ],
        260 => [ // 0x0104
            'name' => 'Dimmer Switch',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'sliders',
            'description' => 'Physical switch for dimmable lights',
        ],
        261 => [ // 0x0105
            'name' => 'Color Dimmer Switch',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'palette',
            'description' => 'Physical switch for color lights',
        ],
        262 => [ // 0x0106
            'name' => 'Light Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'sun',
            'description' => 'Measures ambient light level',
        ],
        263 => [ // 0x0107
            'name' => 'Occupancy Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'motion-sensor',
            'description' => 'Detects presence or motion',
        ],
        266 => [ // 0x010A
            'name' => 'On/Off Plug-in Unit',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'plug',
            'description' => 'Switchable plug-in outlet',
        ],
        267 => [ // 0x010B
            'name' => 'Dimmable Plug-in Unit',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'plug',
            'description' => 'Dimmable plug-in outlet',
        ],
        268 => [ // 0x010C
            'name' => 'Color Temperature Light',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'lightbulb',
            'description' => 'Light with adjustable color temperature',
        ],
        269 => [ // 0x010D
            'name' => 'Extended Color Light',
            'specVersion' => '1.0',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'palette',
            'description' => 'Full color RGB light',
        ],
        271 => [ // 0x010F
            'name' => 'Mounted On/Off Control',
            'specVersion' => '1.4',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'toggle-on',
            'description' => 'Wall-mounted on/off control',
        ],
        272 => [ // 0x0110
            'name' => 'Mounted Dimmable Load Control',
            'specVersion' => '1.4',
            'category' => 'lighting',
            'displayCategory' => 'Lights',
            'icon' => 'sliders',
            'description' => 'Wall-mounted dimmer control',
        ],

        // Closure Device Types (0x020x range)
        514 => [ // 0x0202
            'name' => 'Window Covering',
            'specVersion' => '1.0',
            'category' => 'closures',
            'displayCategory' => 'Security',
            'icon' => 'blinds',
            'description' => 'Motorized blinds, shades, or curtains',
        ],
        515 => [ // 0x0203
            'name' => 'Window Covering Controller',
            'specVersion' => '1.0',
            'category' => 'closures',
            'displayCategory' => 'Security',
            'icon' => 'blinds',
            'description' => 'Controller for window coverings',
        ],

        // HVAC Device Types (0x030x range)
        768 => [ // 0x0300
            'name' => 'Heating/Cooling Unit',
            'specVersion' => '1.0',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'thermometer',
            'description' => 'Heating or cooling unit',
        ],
        769 => [ // 0x0301
            'name' => 'Thermostat',
            'specVersion' => '1.0',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'thermometer',
            'description' => 'Temperature control thermostat',
        ],
        770 => [ // 0x0302
            'name' => 'Temperature Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'thermometer',
            'description' => 'Measures ambient temperature',
        ],
        771 => [ // 0x0303
            'name' => 'Pump',
            'specVersion' => '1.0',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'water',
            'description' => 'Controllable pump device',
        ],
        772 => [ // 0x0304
            'name' => 'Pump Controller',
            'specVersion' => '1.0',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'water',
            'description' => 'Controller for pump devices',
        ],
        773 => [ // 0x0305
            'name' => 'Pressure Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'gauge',
            'description' => 'Measures pressure',
        ],
        774 => [ // 0x0306
            'name' => 'Flow Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'water',
            'description' => 'Measures fluid flow rate',
        ],
        775 => [ // 0x0307
            'name' => 'Humidity Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'droplet',
            'description' => 'Measures relative humidity',
        ],
        777 => [ // 0x0309
            'name' => 'Heat Pump',
            'specVersion' => '1.4',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'thermometer',
            'description' => 'Heat pump heating/cooling system',
        ],
        778 => [ // 0x030A
            'name' => 'Thermostat Controller',
            'specVersion' => '1.4',
            'category' => 'hvac',
            'displayCategory' => 'Climate',
            'icon' => 'thermometer',
            'description' => 'External thermostat controller',
        ],

        // Energy Management Device Types (0x050x range)
        1292 => [ // 0x050C
            'name' => 'EVSE',
            'specVersion' => '1.3',
            'category' => 'energy',
            'displayCategory' => 'Energy',
            'icon' => 'charging-station',
            'description' => 'Electric vehicle charging station',
        ],
        1293 => [ // 0x050D
            'name' => 'Device Energy Management',
            'specVersion' => '1.4',
            'category' => 'energy',
            'displayCategory' => 'Energy',
            'icon' => 'bolt',
            'description' => 'Energy management controller',
        ],
        1295 => [ // 0x050F
            'name' => 'Water Heater',
            'specVersion' => '1.4',
            'category' => 'energy',
            'displayCategory' => 'Energy',
            'icon' => 'fire',
            'description' => 'Electric water heater',
        ],
        1296 => [ // 0x0510
            'name' => 'Electrical Sensor',
            'specVersion' => '1.4',
            'category' => 'energy',
            'displayCategory' => 'Energy',
            'icon' => 'bolt',
            'description' => 'Electrical power/energy sensor',
        ],

        // Controller Device Types (0x08xx range)
        2112 => [ // 0x0840
            'name' => 'Control Bridge',
            'specVersion' => '1.0',
            'category' => 'utility',
            'displayCategory' => 'System',
            'icon' => 'bridge',
            'description' => 'Bridge controller device',
        ],
        2128 => [ // 0x0850
            'name' => 'On/Off Sensor',
            'specVersion' => '1.0',
            'category' => 'sensors',
            'displayCategory' => 'Sensors',
            'icon' => 'toggle-on',
            'description' => 'Binary on/off state sensor',
        ],
    ];

    public function getClusterName(int $id): string
    {
        // Try database first
        $clusters = $this->loadClusters();
        if (isset($clusters[$id])) {
            return $clusters[$id]['name'];
        }

        // Fallback to hardcoded names
        return self::CLUSTER_NAMES[$id] ?? \sprintf('Cluster 0x%04X', $id);
    }

    /**
     * Get full metadata for a cluster.
     *
     * @return array{id: int, hexId: string, name: string, description: ?string, specVersion: ?string, category: ?string, isGlobal: bool}|null
     */
    public function getClusterMetadata(int $id): ?array
    {
        $clusters = $this->loadClusters();
        return $clusters[$id] ?? null;
    }

    /**
     * Get the description for a cluster.
     */
    public function getClusterDescription(int $id): ?string
    {
        $cluster = $this->getClusterMetadata($id);
        return $cluster['description'] ?? null;
    }

    /**
     * Get the category for a cluster.
     */
    public function getClusterCategory(int $id): ?string
    {
        $cluster = $this->getClusterMetadata($id);
        return $cluster['category'] ?? null;
    }

    /**
     * Check if a cluster is a global/utility cluster.
     */
    public function isGlobalCluster(int $id): bool
    {
        $cluster = $this->getClusterMetadata($id);
        return $cluster['isGlobal'] ?? false;
    }

    /**
     * Get the hex ID for a cluster.
     */
    public function getClusterHexId(int $id): string
    {
        $cluster = $this->getClusterMetadata($id);
        return $cluster['hexId'] ?? \sprintf('0x%04X', $id);
    }

    /**
     * Load cluster data from database.
     *
     * @return array<int, array>
     */
    private function loadClusters(): array
    {
        if ($this->clusters !== null) {
            return $this->clusters;
        }

        $this->clusters = [];

        if ($this->clusterRepository === null) {
            return $this->clusters;
        }

        $clusterEntities = $this->clusterRepository->findAll();

        foreach ($clusterEntities as $cluster) {
            $this->clusters[$cluster->getId()] = $this->clusterEntityToArray($cluster);
        }

        return $this->clusters;
    }

    /**
     * Convert a Cluster entity to array format.
     */
    private function clusterEntityToArray(Cluster $cluster): array
    {
        return [
            'id' => $cluster->getId(),
            'hexId' => $cluster->getHexId(),
            'name' => $cluster->getName(),
            'description' => $cluster->getDescription(),
            'specVersion' => $cluster->getSpecVersion(),
            'category' => $cluster->getCategory(),
            'isGlobal' => $cluster->isGlobal(),
        ];
    }

    /**
     * Get the display name for a device type.
     */
    public function getDeviceTypeName(int $id): string
    {
        return self::DEVICE_TYPE_METADATA[$id]['name'] ?? "Device Type $id";
    }

    /**
     * Get full metadata for a device type.
     *
     * @return array{name: string, specVersion: string, category: string, displayCategory: string, icon: string, description: string}|null
     */
    public function getDeviceTypeMetadata(int $id): ?array
    {
        return self::DEVICE_TYPE_METADATA[$id] ?? null;
    }

    /**
     * Get the Matter specification version for a device type.
     */
    public function getDeviceTypeSpecVersion(int $id): ?string
    {
        return self::DEVICE_TYPE_METADATA[$id]['specVersion'] ?? null;
    }

    /**
     * Get the icon identifier for a device type.
     */
    public function getDeviceTypeIcon(int $id): ?string
    {
        return self::DEVICE_TYPE_METADATA[$id]['icon'] ?? null;
    }

    /**
     * Get the description for a device type.
     */
    public function getDeviceTypeDescription(int $id): ?string
    {
        return self::DEVICE_TYPE_METADATA[$id]['description'] ?? null;
    }

    /**
     * Get the spec category for a device type (e.g., 'lighting', 'hvac', 'sensors').
     */
    public function getDeviceTypeCategory(int $id): ?string
    {
        return self::DEVICE_TYPE_METADATA[$id]['category'] ?? null;
    }

    /**
     * Get the display category for a device type (e.g., 'Lights', 'Climate', 'Sensors').
     */
    public function getDeviceTypeDisplayCategory(int $id): ?string
    {
        return self::DEVICE_TYPE_METADATA[$id]['displayCategory'] ?? null;
    }

    /**
     * Get all device types that belong to a specific spec category.
     *
     * @return array<int, array{name: string, specVersion: string, category: string, displayCategory: string, icon: string, description: string}>
     */
    public function getDeviceTypesByCategory(string $category): array
    {
        return array_filter(
            self::DEVICE_TYPE_METADATA,
            fn(array $meta) => $meta['category'] === $category
        );
    }

    /**
     * Get all device types that belong to a specific display category.
     *
     * @return array<int, array{name: string, specVersion: string, category: string, displayCategory: string, icon: string, description: string}>
     */
    public function getDeviceTypesByDisplayCategory(string $displayCategory): array
    {
        return array_filter(
            self::DEVICE_TYPE_METADATA,
            fn(array $meta) => $meta['displayCategory'] === $displayCategory
        );
    }

    /**
     * Get all device types introduced in a specific Matter specification version.
     *
     * @return array<int, array{name: string, specVersion: string, category: string, displayCategory: string, icon: string, description: string}>
     */
    public function getDeviceTypesBySpecVersion(string $specVersion): array
    {
        return array_filter(
            self::DEVICE_TYPE_METADATA,
            fn(array $meta) => $meta['specVersion'] === $specVersion
        );
    }

    /**
     * Get all unique spec categories.
     *
     * @return string[]
     */
    public function getAllCategories(): array
    {
        return array_values(array_unique(
            array_column(self::DEVICE_TYPE_METADATA, 'category')
        ));
    }

    /**
     * Get all unique display categories.
     *
     * @return string[]
     */
    public function getAllDisplayCategories(): array
    {
        return array_values(array_unique(
            array_column(self::DEVICE_TYPE_METADATA, 'displayCategory')
        ));
    }

    /**
     * Get all unique spec versions.
     *
     * @return string[]
     */
    public function getAllSpecVersions(): array
    {
        $versions = array_unique(
            array_column(self::DEVICE_TYPE_METADATA, 'specVersion')
        );
        usort($versions, 'version_compare');

        return array_values($versions);
    }

    public function getAllClusterNames(): array
    {
        return self::CLUSTER_NAMES;
    }

    /**
     * Get all device type names (backward compatible).
     *
     * @return array<int, string>
     */
    public function getAllDeviceTypeNames(): array
    {
        return array_map(
            fn(array $meta) => $meta['name'],
            self::DEVICE_TYPE_METADATA
        );
    }

    /**
     * Get all device type metadata.
     *
     * @return array<int, array{name: string, specVersion: string, category: string, displayCategory: string, icon: string, description: string}>
     */
    public function getAllDeviceTypeMetadata(): array
    {
        return self::DEVICE_TYPE_METADATA;
    }

    /**
     * Load extended device type data from database.
     *
     * @return array<int, array>
     */
    private function loadExtendedDeviceTypes(): array
    {
        if ($this->extendedDeviceTypes !== null) {
            return $this->extendedDeviceTypes;
        }

        $this->extendedDeviceTypes = [];

        if ($this->deviceTypeRepository === null) {
            return $this->extendedDeviceTypes;
        }

        $deviceTypes = $this->deviceTypeRepository->findAll();

        foreach ($deviceTypes as $deviceType) {
            $this->extendedDeviceTypes[$deviceType->getId()] = $this->entityToArray($deviceType);
        }

        return $this->extendedDeviceTypes;
    }

    /**
     * Convert a DeviceType entity to the array format used throughout the codebase.
     */
    private function entityToArray(DeviceType $deviceType): array
    {
        return [
            'id' => $deviceType->getId(),
            'hexId' => $deviceType->getHexId(),
            'name' => $deviceType->getName(),
            'description' => $deviceType->getDescription(),
            'specVersion' => $deviceType->getSpecVersion(),
            'category' => $deviceType->getCategory(),
            'displayCategory' => $deviceType->getDisplayCategory(),
            'class' => $deviceType->getDeviceClass(),
            'scope' => $deviceType->getScope(),
            'superset' => $deviceType->getSuperset(),
            'icon' => $deviceType->getIcon(),
            'mandatoryServerClusters' => $deviceType->getMandatoryServerClusters(),
            'optionalServerClusters' => $deviceType->getOptionalServerClusters(),
            'mandatoryClientClusters' => $deviceType->getMandatoryClientClusters(),
            'optionalClientClusters' => $deviceType->getOptionalClientClusters(),
        ];
    }

    /**
     * Get extended device type data including cluster requirements.
     *
     * @return array|null The full device type data with cluster information
     */
    public function getExtendedDeviceType(int $id): ?array
    {
        $extended = $this->loadExtendedDeviceTypes();
        return $extended[$id] ?? null;
    }

    /**
     * Get all extended device type data.
     *
     * @return array<int, array>
     */
    public function getAllExtendedDeviceTypes(): array
    {
        return $this->loadExtendedDeviceTypes();
    }

    /**
     * Get mandatory server clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getMandatoryServerClusters(int $deviceTypeId): array
    {
        $extended = $this->getExtendedDeviceType($deviceTypeId);
        return $extended['mandatoryServerClusters'] ?? [];
    }

    /**
     * Get optional server clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getOptionalServerClusters(int $deviceTypeId): array
    {
        $extended = $this->getExtendedDeviceType($deviceTypeId);
        return $extended['optionalServerClusters'] ?? [];
    }

    /**
     * Get mandatory client clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getMandatoryClientClusters(int $deviceTypeId): array
    {
        $extended = $this->getExtendedDeviceType($deviceTypeId);
        return $extended['mandatoryClientClusters'] ?? [];
    }

    /**
     * Get optional client clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getOptionalClientClusters(int $deviceTypeId): array
    {
        $extended = $this->getExtendedDeviceType($deviceTypeId);
        return $extended['optionalClientClusters'] ?? [];
    }

    /**
     * Get device type superset (parent device type name).
     */
    public function getDeviceTypeSuperset(int $id): ?string
    {
        $extended = $this->getExtendedDeviceType($id);
        return $extended['superset'] ?? null;
    }

    /**
     * Get device type class (Simple, Utility, Node, Dynamic).
     */
    public function getDeviceTypeClass(int $id): ?string
    {
        $extended = $this->getExtendedDeviceType($id);
        return $extended['class'] ?? null;
    }

    /**
     * Get device type scope (Endpoint, Node).
     */
    public function getDeviceTypeScope(int $id): ?string
    {
        $extended = $this->getExtendedDeviceType($id);
        return $extended['scope'] ?? null;
    }

    /**
     * Get the hex ID string for a device type (e.g., "0x0100").
     */
    public function getDeviceTypeHexId(int $id): string
    {
        $extended = $this->getExtendedDeviceType($id);
        return $extended['hexId'] ?? sprintf('0x%04X', $id);
    }

    /**
     * Check if extended data is available for a device type.
     */
    public function hasExtendedData(int $id): bool
    {
        return $this->getExtendedDeviceType($id) !== null;
    }
}
