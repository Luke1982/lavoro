import {
    Wrench, Hammer, Drill, Axe, Shovel, Scissors, Ruler, Bolt, Nut, HardHat, Construction,
    Zap, Battery, BatteryCharging, Plug, Power, Cable, CircuitBoard, Cpu, Microchip,
    Lightbulb, Flashlight,
    Settings, Cog, Gauge, Filter, Magnet,
    Thermometer, Flame, FlameKindling, Snowflake, Wind, Droplets, Waves, Fuel,
    Box, Package, Boxes, Archive, Warehouse, Barcode, QrCode, Forklift,
    Building, Building2, Home, Factory, HardDrive, Server, Database,
    Car, Truck, Bus, Bike, Tractor,
    Shield, Lock, Key, AlertTriangle, Siren, HeartPulse,
    Scale, Weight, Pipette, Beaker, TestTube, Microscope,
    Paintbrush, PaintBucket, Palette,
    Leaf, Trees, Sprout,
    ShoppingCart, Receipt, Banknote, Tag, Bookmark, Star, Flag, Bell,
    Clock, Timer, Hourglass,
    Layers, Grid, List, WashingMachine, Radio,
} from '@lucide/vue'

export const ICON_MAP = {
    Wrench, Hammer, Drill, Axe, Shovel, Scissors, Ruler, Bolt, Nut, HardHat, Construction,
    Zap, Battery, BatteryCharging, Plug, Power, Cable, CircuitBoard, Cpu, Microchip,
    Lightbulb, Flashlight,
    Settings, Cog, Gauge, Filter, Magnet,
    Thermometer, Flame, FlameKindling, Snowflake, Wind, Droplets, Waves, Fuel,
    Box, Package, Boxes, Archive, Warehouse, Barcode, QrCode, Forklift,
    Building, Building2, Home, Factory, HardDrive, Server, Database,
    Car, Truck, Bus, Bike, Tractor,
    Shield, Lock, Key, AlertTriangle, Siren, HeartPulse,
    Scale, Weight, Pipette, Beaker, TestTube, Microscope,
    Paintbrush, PaintBucket, Palette,
    Leaf, Trees, Sprout,
    ShoppingCart, Receipt, Banknote, Tag, Bookmark, Star, Flag, Bell,
    Clock, Timer, Hourglass,
    Layers, Grid, List, WashingMachine, Radio,
}

export function getIconByName(name) {
    return ICON_MAP[name] ?? Package
}
