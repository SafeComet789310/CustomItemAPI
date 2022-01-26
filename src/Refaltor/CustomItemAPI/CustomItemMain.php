<?php

namespace Refaltor\CustomItemAPI;

use pocketmine\inventory\CreativeInventory;
use pocketmine\item\ArmorTypeInfo;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\StringToItemParser;
use pocketmine\item\ToolTier;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\protocol\ItemComponentPacket;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemComponentPacketEntry;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\plugin\PluginBase;
use Refaltor\CustomItemAPI\Events\Listeners\ItemCreationEventExample;
use Refaltor\CustomItemAPI\Events\Listeners\PacketListener;
use Refaltor\CustomItemAPI\Events\Listeners\PlayerListener;
use Refaltor\CustomItemAPI\Items\ArmorItem;
use Refaltor\CustomItemAPI\Items\AxeItem;
use Refaltor\CustomItemAPI\Items\BasicItem;
use Refaltor\CustomItemAPI\Items\FoodItem;
use Refaltor\CustomItemAPI\Items\HoeItem;
use Refaltor\CustomItemAPI\Items\PickaxeItem;
use Refaltor\CustomItemAPI\Items\ShovelItem;
use Refaltor\CustomItemAPI\Items\StructureItem;
use Refaltor\CustomItemAPI\Items\SwordItem;
use ReflectionClass;
use ReflectionProperty;

class CustomItemMain extends PluginBase
{

    public ?ItemComponentPacket $packet = null;
    protected array $registered = [];
    protected ReflectionProperty $coreToNetMap;
    protected ReflectionProperty $netToCoreMap;
    protected array $coreToNetValues = [];
    protected array $netToCoreValues = [];
    protected ReflectionProperty $itemTypeMap;
    protected array $packetEntries = [];
    protected array $itemTypeEntries = [];

    public array $items = [];
    private static ?self $instance = null;

    private function loadConfigurationFile(): void
    {
        $array = $this->getConfig()->getAll();
        foreach ($array['items'] as $name => $values)
        {
            switch (strtolower($values['type']))
            {
                case 'basic':
                    $name = strval($values['name']);
                    $id = intval($values['id']);
                    $meta = intval($values['meta']);
                    $max_stack_size = intval($values['max_stack_size']);
                    $texture_path = strval($values['texture_path']);
                    (new BasicItem(new ItemIdentifier($id, $meta), $name, $texture_path, $max_stack_size))->addToServer();
                    break;
                case 'armor':
                    $name = strval($values['name']);
                    $id = intval($values['id']);
                    $meta = intval($values['meta']);
                    $texture_path = strval($values['texture_path']);
                    $armorGroup = strtolower(strval($values['armor_group']));
                    $defense_points = intval($values['defense_points']);
                    $max_durability = intval($values['max_durability']);

                    $item = match ($armorGroup) {
                        'helmet' => new ArmorItem(new ItemIdentifier($id, $meta), $name, new ArmorTypeInfo($defense_points, $max_durability, 0), $texture_path),
                        'chestplate' => new ArmorItem(new ItemIdentifier($id, $meta), $name, new ArmorTypeInfo($defense_points, $max_durability, 1), $texture_path),
                        'leggings' => new ArmorItem(new ItemIdentifier($id, $meta), $name, new ArmorTypeInfo($defense_points, $max_durability, 2), $texture_path),
                        'boots' => new ArmorItem(new ItemIdentifier($id, $meta), $name, new ArmorTypeInfo($defense_points, $max_durability, 3), $texture_path),
                    };

                    $item->addToServer();
                    break;
                case 'food':
                    $name = strval($values['name']);
                    $id = intval($values['id']);
                    $meta = intval($values['meta']);
                    $max_stack_size = intval($values['max_stack_size']);
                    $texture_path = strval($values['texture_path']);
                    $food_restore = intval($values['food_restore']);
                    $saturation_restore = floatval($values['saturation_restore']);
                    (new FoodItem(new ItemIdentifier($id, $meta), $name, $food_restore, $saturation_restore, $texture_path, $max_stack_size))->addToServer();
                    break;
                case 'tool':
                    $name = strval($values['name']);
                    $id = intval($values['id']);
                    $meta = intval($values['meta']);
                    $texture_path = strval($values['texture_path']);
                    $tier = match (strval($values['tier'])) {
                        'diamond' => ToolTier::DIAMOND(),
                        'gold' => ToolTier::GOLD(),
                        'iron' => ToolTier::IRON(),
                        'stone' => ToolTier::STONE(),
                        'wood' => ToolTier::WOOD()
                    };

                    $item = match (strtolower(strval($values['tool_group']))) {
                        'pickaxe' => new PickaxeItem(new ItemIdentifier($id, $meta), $name, $tier, floatval($values['mining_efficiency']), intval($values['max_durability']), $texture_path),
                        'sword' => new SwordItem(new ItemIdentifier($id, $meta), $name, $tier, intval($values['max_durability']), intval($values['attack_points']), $texture_path),
                        'axe' => new AxeItem(new ItemIdentifier($id, $meta), $name, $tier, floatval($values['mining_efficiency']),$texture_path),
                        'shovel' => new ShovelItem(new ItemIdentifier($id, $meta), $name, $tier, intval($values['max_durability']), floatval($values['mining_efficiency']),$texture_path),
                        'hoe' => new HoeItem(new ItemIdentifier($id, $meta), $name, $tier, intval($values['max_durability']), $texture_path),
                    };

                    $item->addToServer();
                    break;
            }
        }
    }

    protected function onLoad(): void
    {
        $this->saveDefaultConfig();
        if (is_null(self::$instance)) self::$instance = $this;
        @mkdir($this->getDataFolder() . 'debugs/');
        //@mkdir($this->getDataFolder() . 'Temps/');
        //@mkdir($this->getDataFolder() . 'resourcesTemps/');
        $this->loadConfigurationFile();
    }

    protected function onEnable(): void
    {
        foreach ([new PacketListener($this), new PlayerListener(), new ItemCreationEventExample($this)] as $event) $this->getServer()->getPluginManager()->registerEvents($event, $this);
        $ref = new ReflectionClass(ItemTranslator::class);
        $this->coreToNetMap = $ref->getProperty("simpleCoreToNetMapping");
        $this->netToCoreMap = $ref->getProperty("simpleNetToCoreMapping");
        $this->coreToNetMap->setAccessible(true);
        $this->netToCoreMap->setAccessible(true);
        $this->coreToNetValues = $this->coreToNetMap->getValue(ItemTranslator::getInstance());
        $this->netToCoreValues = $this->netToCoreMap->getValue(ItemTranslator::getInstance());
        $ref_1 = new ReflectionClass(ItemTypeDictionary::class);
        $this->itemTypeMap = $ref_1->getProperty("itemTypes");
        $this->itemTypeMap->setAccessible(true);
        $this->itemTypeEntries = $this->itemTypeMap->getValue(GlobalItemTypeDictionary::getInstance()->getDictionary());
        $this->packetEntries = [];
        $items = $this->getItemsInCache();
        foreach ($items as $item) {
            if (
                $item instanceof StructureItem
                || $item instanceof ArmorItem
                || $item instanceof FoodItem
                || $item instanceof SwordItem
                || $item instanceof PickaxeItem
                || $item instanceof AxeItem
                || $item instanceof ShovelItem
                || $item instanceof HoeItem
            ) {
                $runtimeId = $item->getId() + ($item->getId() > 0 ? 5000 : -5000);
                $this->coreToNetValues[$item->getId()] = $runtimeId;
                $this->netToCoreValues[$runtimeId] = $item->getId();
                $this->itemTypeEntries[] = new ItemTypeEntry("custom:" . $item->getName(), $runtimeId, true);
                $this->packetEntries[] = new ItemComponentPacketEntry("custom:" . $item->getName(), new CacheableNbt($item->getComponents()));;
                $this->registered[] = $item;
                $new = clone $item;
                StringToItemParser::getInstance()->register($item->getName(), fn() => $new);
                ItemFactory::getInstance()->register($item, true);
                CreativeInventory::getInstance()->add($item);
                $this->netToCoreMap->setValue(ItemTranslator::getInstance(), $this->netToCoreValues);
                $this->coreToNetMap->setValue(ItemTranslator::getInstance(), $this->coreToNetValues);
                $this->itemTypeMap->setValue(GlobalItemTypeDictionary::getInstance()->getDictionary(), $this->itemTypeEntries);
                $this->packet = ItemComponentPacket::create($this->packetEntries);
            }
        }
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    private function getItemsInCache(): array
    {
        return $this->items;
    }
}
