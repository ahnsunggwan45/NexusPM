<?php

declare(strict_types=1);

namespace NexusPM\mapping;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
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

	/** @var BlockPaletteEntry[] Cached palette entries for StartGamePacket */
	private array $paletteEntries = [];

	/** @var array<string, true> Custom block names (in native but not in standard target) */
	private array $customBlockNames = [];

	public function __construct(string $targetPaletteNbt, string $targetMetaMapJson){
		$this->nativeTranslator = TypeConverter::getInstance()->getBlockTranslator();

		// Build target palette: standard v944 palette + custom blocks from Customies
		$targetPaletteNbt = $this->injectCustomBlocks($targetPaletteNbt);

		// Build palette entries from raw NBT (for StartGamePacket)
		$netNbt = new NetworkNbtSerializer();
		$roots = $netNbt->readMultiple($targetPaletteNbt);
		$this->paletteEntries = [];
		foreach($roots as $root){
			$tag = $root->mustGetCompoundTag();
			$name = $tag->getString(BlockStateData::TAG_NAME);
			$states = $tag->getCompoundTag(BlockStateData::TAG_STATES) ?? CompoundTag::create();
			$this->paletteEntries[] = new BlockPaletteEntry($name, new CacheableNbt(clone $states));
		}

		// Pad meta map to match palette size
		$paletteCount = count($roots);
		$metaMap = json_decode($targetMetaMapJson, true);
		if(is_array($metaMap)){
			while(count($metaMap) < $paletteCount){
				$metaMap[] = 0;
			}
			$targetMetaMapJson = json_encode($metaMap);
		}

		$this->targetDictionary = BlockStateDictionary::loadFromString($targetPaletteNbt, $targetMetaMapJson);
		// Use info_update ("?" block) as fallback so unmapped blocks are visible
		$infoUpdate = $this->targetDictionary->lookupStateIdFromData(
			BlockStateData::current(\pocketmine\data\bedrock\block\BlockTypeNames::INFO_UPDATE, [])
		);
		$this->targetFallbackId = $infoUpdate ?? 0;
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

		$this->customBlockNames = $seen;

		if(count($seen) === 0){
			return $targetPaletteNbt; // No custom blocks to inject
		}

		// Collect custom block NBT tags from native palette
		$customTags = [];
		foreach($nativeStates as $entry){
			if(isset($seen[$entry->getStateName()])){
				$stateData = $entry->generateStateData();
				$statesTag = CompoundTag::create();
				foreach($stateData->getStates() as $propName => $propTag){
					$statesTag->setTag($propName, clone $propTag);
				}

				$tag = CompoundTag::create()
					->setString(BlockStateData::TAG_NAME, $entry->getStateName())
					->setTag(BlockStateData::TAG_STATES, $statesTag);

				$customTags[] = $tag;
			}
		}

		// Build combined palette: target blocks + custom blocks, sorted by fnv164.
		// The client also merges vanilla + custom and sorts by fnv164,
		// so the target dictionary indices must match the client's sort order.
		$allTags = [];
		foreach($targetRoots as $root){
			$allTags[] = $root->mustGetCompoundTag();
		}
		foreach($customTags as $tag){
			$allTags[] = $tag;
		}

		// Group by name, sort names by fnv164 hash (same as Customies + client)
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

	/** @var string[] Block names that failed mapping (for diagnostics) */
	private array $unmappedBlocks = [];

	private function buildCache() : void{
		$nativeDictionary = $this->nativeTranslator->getBlockStateDictionary();

		$nativePaletteSize = $this->nativeTranslator->getBlockStateDictionary()->getStates();
		$unmappedNames = [];
		foreach($nativePaletteSize as $nativeRid => $entry){
			$stateData = $nativeDictionary->generateDataFromStateId($nativeRid);
			if($stateData === null) continue;

			$targetRid = $this->targetDictionary->lookupStateIdFromData($stateData);
			if($targetRid !== null){
				$this->nativeToTargetCache[$nativeRid] = $targetRid;
				$this->targetToNativeCache[$targetRid] = $nativeRid;
			}else{
				// Track unmapped blocks for diagnostics (one per block name)
				$name = $stateData->getName();
				if(!isset($unmappedNames[$name])){
					$unmappedNames[$name] = true;
					$this->unmappedBlocks[] = $name;
				}
				// Map to fallback (info_update / update block) instead of passing through
				// native ID, which would map to a completely wrong block in the target palette
				$this->nativeToTargetCache[$nativeRid] = $this->targetFallbackId;
			}
		}
	}

	/**
	 * @return string[] Block names that could not be mapped to target palette
	 */
	public function getUnmappedBlocks() : array{
		return $this->unmappedBlocks;
	}

	/**
	 * Translate server's native network runtime ID → target protocol runtime ID.
	 * Used for outbound packets (server → client).
	 *
	 * Unmapped blocks are mapped to the fallback block (info_update) by buildCache().
	 * If a runtime ID isn't in the cache at all (e.g., dynamically registered after init),
	 * returns the native ID unchanged as a last resort.
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

	/**
	 * Get BlockPaletteEntry array for StartGamePacket (vanilla v944 only).
	 * @return BlockPaletteEntry[]
	 */
	public function getBlockPaletteEntries() : array{
		return $this->paletteEntries;
	}

	/**
	 * @return array<string, true>
	 */
	public function getCustomBlockNames() : array{
		return $this->customBlockNames;
	}
}
