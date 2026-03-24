<?php

declare(strict_types=1);

namespace NexusPM\mapping;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

/**
 * Translates block runtime IDs between PMMP's native protocol and a target protocol.
 *
 * Following Nightfall's 2-step approach:
 *   1. Reverse: v924 network runtime ID → PMMP internal state ID
 *      (using PMMP's own BlockTranslator in reverse)
 *   2. Forward: PMMP internal state ID → v944 network runtime ID
 *      (using our target-version BlockStateDictionary)
 *
 * This is more correct than simple palette index comparison because
 * PMMP's internal state IDs are stable, while network runtime IDs
 * are just palette indices that differ between versions.
 */
class NexusBlockTranslator{

	/** @var int[] PMMP internal state ID → target network runtime ID */
	private array $internalToTargetCache = [];

	/** @var int[] server native network runtime ID → target network runtime ID */
	private array $nativeToTargetCache = [];

	/** @var int[] target network runtime ID → server native network runtime ID */
	private array $targetToNativeCache = [];

	private BlockTranslator $nativeTranslator;
	private BlockStateDictionary $targetDictionary;
	private int $targetFallbackId;

	public function __construct(string $targetPaletteNbt, string $targetMetaMapJson){
		$this->nativeTranslator = TypeConverter::getInstance()->getBlockTranslator();

		// Build target palette: standard v944 palette + custom blocks from Customies
		$targetPaletteNbt = $this->injectCustomBlocks($targetPaletteNbt);

		// Pad meta map to match palette size
		$paletteCount = count((new NetworkNbtSerializer())->readMultiple($targetPaletteNbt));
		$metaMap = json_decode($targetMetaMapJson, true);
		if(is_array($metaMap)){
			while(count($metaMap) < $paletteCount){
				$metaMap[] = 0;
			}
			$targetMetaMapJson = json_encode($metaMap);
		}

		$this->targetDictionary = BlockStateDictionary::loadFromString($targetPaletteNbt, $targetMetaMapJson);
		$this->targetFallbackId = 0;
		$this->buildCache();
	}

	/**
	 * Inject custom blocks (from Customies or similar plugins) into the target palette.
	 *
	 * Compares the native palette with the standard target palette to find
	 * blocks that exist in native but not in target — these are custom blocks.
	 * Inserts them into the target palette using fnv164 hash sort (same as Customies).
	 *
	 * Result: a "v944 + custom blocks" palette that the v944 client can use.
	 */
	private function injectCustomBlocks(string $targetPaletteNbt) : string{
		$netNbt = new NetworkNbtSerializer();

		$targetRoots = $netNbt->readMultiple($targetPaletteNbt);
		$nativeStates = $this->nativeTranslator->getBlockStateDictionary()->getStates();

		// Build set of block names in target
		$targetNames = [];
		foreach($targetRoots as $root){
			$tag = $root->mustGetCompoundTag();
			$targetNames[$tag->getString("name")] = true;
		}

		// Find custom blocks: in native but not in target
		$customEntries = [];
		$seen = [];
		foreach($nativeStates as $entry){
			$name = $entry->getStateName();
			if(!isset($targetNames[$name]) && !isset($seen[$name])){
				$seen[$name] = true;
				// This is a custom block — collect all its states from native
			}
		}

		if(count($seen) === 0){
			return $targetPaletteNbt; // No custom blocks to inject
		}

		// Collect custom block NBT tags from native palette
		// We need to re-serialize from BlockStateDictionaryEntry to NBT
		$customTags = [];
		foreach($nativeStates as $entry){
			if(isset($seen[$entry->getStateName()])){
				$tag = \pocketmine\nbt\tag\CompoundTag::create()
					->setString(\pocketmine\data\bedrock\block\BlockStateData::TAG_NAME, $entry->getStateName())
					->setTag(\pocketmine\data\bedrock\block\BlockStateData::TAG_STATES,
						\pocketmine\nbt\tag\CompoundTag::create());

				// Copy state properties
				foreach($entry->getRawStateProperties() as $propName => $propTag){
					$tag->getCompoundTag(\pocketmine\data\bedrock\block\BlockStateData::TAG_STATES)
						->setTag($propName, clone $propTag);
				}

				$customTags[] = $tag;
			}
		}

		// Build combined palette: target blocks + custom blocks, sorted by fnv164
		$allTags = [];
		foreach($targetRoots as $root){
			$allTags[] = $root->mustGetCompoundTag();
		}
		foreach($customTags as $tag){
			$allTags[] = $tag;
		}

		// Sort by fnv164 hash of block name (same as Customies)
		// Group by name first, then sort names by fnv164
		$groups = [];
		foreach($allTags as $tag){
			$name = $tag->getString("name");
			$groups[$name][] = $tag;
		}

		$names = array_keys($groups);
		usort($names, static fn(string $a, string $b) => strcmp(hash("fnv164", $a), hash("fnv164", $b)));

		// Rebuild palette NBT
		$output = "";
		foreach($names as $name){
			foreach($groups[$name] as $tag){
				$output .= $netNbt->write(new \pocketmine\nbt\TreeRoot($tag));
			}
		}

		return $output;
	}

	private function buildCache() : void{
		$nativeDictionary = $this->nativeTranslator->getBlockStateDictionary();
		$serializer = GlobalBlockStateHandlers::getSerializer();

		// Iterate all PMMP internal state IDs
		// The range of internal state IDs is not directly enumerable,
		// so we build the cache lazily + pre-populate from the native palette
		$nativeNetNbt = new NetworkNbtSerializer();

		// For each entry in the native palette, build the mapping
		// native palette index = native network runtime ID
		// We can get the internal state ID from nativeTranslator
		$nativePaletteSize = $this->nativeTranslator->getBlockStateDictionary()->getStates();
		foreach($nativePaletteSize as $nativeRid => $entry){
			// native runtime ID → block state data
			$stateData = $nativeDictionary->generateDataFromStateId($nativeRid);
			if($stateData === null) continue;

			// block state data → target runtime ID
			$targetRid = $this->targetDictionary->lookupStateIdFromData($stateData);
			if($targetRid !== null){
				$this->nativeToTargetCache[$nativeRid] = $targetRid;
				$this->targetToNativeCache[$targetRid] = $nativeRid;
			}
		}
	}

	/**
	 * Translate server's native network runtime ID → target protocol runtime ID.
	 * Used for outbound packets (server → client).
	 *
	 * For unmapped blocks (e.g., custom blocks from plugins), returns the
	 * native runtime ID unchanged. The client uses the server's block palette
	 * for custom blocks, so native IDs are valid.
	 */
	public function nativeToTarget(int $nativeRuntimeId) : int{
		return $this->nativeToTargetCache[$nativeRuntimeId] ?? $nativeRuntimeId;
	}

	/**
	 * Translate target protocol runtime ID → server's native network runtime ID.
	 * Used for inbound packets (client → server).
	 *
	 * For unmapped blocks, returns the target ID unchanged.
	 */
	public function targetToNative(int $targetRuntimeId) : int{
		return $this->targetToNativeCache[$targetRuntimeId] ?? $targetRuntimeId;
	}

	/**
	 * Translate PMMP internal state ID → target network runtime ID.
	 * Used for chunk serialization.
	 */
	public function internalToTarget(int $internalStateId) : int{
		if(isset($this->internalToTargetCache[$internalStateId])){
			return $this->internalToTargetCache[$internalStateId];
		}

		// Use PMMP's serializer to get BlockStateData from internal ID
		$serializer = GlobalBlockStateHandlers::getSerializer();
		try{
			$stateData = $serializer->serialize($internalStateId);
		}catch(\Throwable){
			$this->internalToTargetCache[$internalStateId] = $this->targetFallbackId;
			return $this->targetFallbackId;
		}

		$targetRid = $this->targetDictionary->lookupStateIdFromData($stateData);
		// For custom blocks not in target palette, use native ID (pass-through)
		$nativeRid = $this->nativeTranslator->getBlockStateDictionary()->lookupStateIdFromData($stateData);
		$result = $targetRid ?? $nativeRid ?? $internalStateId;
		$this->internalToTargetCache[$internalStateId] = $result;
		return $result;
	}

	public function getTargetDictionary() : BlockStateDictionary{
		return $this->targetDictionary;
	}

	public function getMappedCount() : int{
		return count($this->nativeToTargetCache);
	}

	public function getChangedCount() : int{
		$changed = 0;
		foreach($this->nativeToTargetCache as $native => $target){
			if($native !== $target) $changed++;
		}
		return $changed;
	}
}
