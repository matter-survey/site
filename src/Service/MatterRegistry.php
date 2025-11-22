<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Registry for Matter cluster and device type name lookups.
 */
class MatterRegistry
{
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

    private const DEVICE_TYPE_NAMES = [
        // Utility/System Device Types
        10 => 'Door Lock',                       // 0x000A
        11 => 'Door Lock Controller',            // 0x000B
        14 => 'Aggregator',                      // 0x000E
        15 => 'Generic Switch',                  // 0x000F
        17 => 'Power Source',                    // 0x0011
        18 => 'OTA Requestor',                   // 0x0012
        19 => 'Bridged Node',                    // 0x0013
        20 => 'OTA Provider',                    // 0x0014
        21 => 'Contact Sensor',                  // 0x0015
        22 => 'Root Node',                       // 0x0016
        23 => 'Solar Power',                     // 0x0017
        24 => 'Battery Storage',                 // 0x0018
        25 => 'Secondary Network Interface',     // 0x0019

        // Media Device Types
        34 => 'Speaker',                         // 0x0022
        35 => 'Casting Video Player',            // 0x0023
        36 => 'Content App',                     // 0x0024
        39 => 'Mode Select',                     // 0x0027
        40 => 'Basic Video Player',              // 0x0028
        41 => 'Casting Video Client',            // 0x0029
        42 => 'Video Remote Control',            // 0x002A

        // HVAC Device Types (0x002x range)
        43 => 'Fan',                             // 0x002B
        44 => 'Air Quality Sensor',              // 0x002C
        45 => 'Air Purifier',                    // 0x002D

        // Sensor Device Types (0x004x range)
        65 => 'Water Freeze Detector',           // 0x0041
        66 => 'Water Valve',                     // 0x0042
        67 => 'Water Leak Detector',             // 0x0043
        68 => 'Rain Sensor',                     // 0x0044

        // Appliance Device Types (0x007x range)
        112 => 'Refrigerator',                   // 0x0070
        113 => 'Temperature Controlled Cabinet', // 0x0071
        114 => 'Room Air Conditioner',           // 0x0072
        115 => 'Laundry Washer',                 // 0x0073
        116 => 'Robotic Vacuum Cleaner',         // 0x0074
        117 => 'Dishwasher',                     // 0x0075
        118 => 'Smoke/CO Alarm',                 // 0x0076
        119 => 'Cook Surface',                   // 0x0077
        120 => 'Cooktop',                        // 0x0078
        121 => 'Microwave Oven',                 // 0x0079
        122 => 'Extractor Hood',                 // 0x007A
        123 => 'Oven',                           // 0x007B
        124 => 'Laundry Dryer',                  // 0x007C

        // Network Infrastructure
        145 => 'Thread Border Router',           // 0x0091

        // Lighting Device Types (0x010x range)
        256 => 'On/Off Light',                   // 0x0100
        257 => 'Dimmable Light',                 // 0x0101
        259 => 'On/Off Light Switch',            // 0x0103
        260 => 'Dimmer Switch',                  // 0x0104
        261 => 'Color Dimmer Switch',            // 0x0105
        262 => 'Light Sensor',                   // 0x0106
        263 => 'Occupancy Sensor',               // 0x0107
        266 => 'On/Off Plug-in Unit',            // 0x010A
        267 => 'Dimmable Plug-in Unit',          // 0x010B
        268 => 'Color Temperature Light',        // 0x010C
        269 => 'Extended Color Light',           // 0x010D
        271 => 'Mounted On/Off Control',         // 0x010F
        272 => 'Mounted Dimmable Load Control',  // 0x0110

        // Closure Device Types (0x020x range)
        514 => 'Window Covering',                // 0x0202
        515 => 'Window Covering Controller',     // 0x0203

        // HVAC Device Types (0x030x range)
        768 => 'Heating/Cooling Unit',           // 0x0300
        769 => 'Thermostat',                     // 0x0301
        770 => 'Temperature Sensor',             // 0x0302
        771 => 'Pump',                           // 0x0303
        772 => 'Pump Controller',                // 0x0304
        773 => 'Pressure Sensor',                // 0x0305
        774 => 'Flow Sensor',                    // 0x0306
        775 => 'Humidity Sensor',                // 0x0307
        777 => 'Heat Pump',                      // 0x0309
        778 => 'Thermostat Controller',          // 0x030A

        // Energy Management Device Types (0x050x range)
        1292 => 'EVSE',                          // 0x050C
        1293 => 'Device Energy Management',      // 0x050D
        1295 => 'Water Heater',                  // 0x050F
        1296 => 'Electrical Sensor',             // 0x0510

        // Controller Device Types (0x08xx range)
        2112 => 'Control Bridge',                // 0x0840
        2128 => 'On/Off Sensor',                 // 0x0850
    ];

    public function getClusterName(int $id): string
    {
        return self::CLUSTER_NAMES[$id] ?? sprintf('Cluster 0x%04X', $id);
    }

    public function getDeviceTypeName(int $id): string
    {
        return self::DEVICE_TYPE_NAMES[$id] ?? "Device Type $id";
    }

    public function getAllClusterNames(): array
    {
        return self::CLUSTER_NAMES;
    }

    public function getAllDeviceTypeNames(): array
    {
        return self::DEVICE_TYPE_NAMES;
    }
}
