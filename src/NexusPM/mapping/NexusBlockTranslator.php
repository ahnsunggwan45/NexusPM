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

		// Pad meta map if palette has more entries (new blocks in target version)
		$paletteCount = count((new NetworkNbtSerializer())->readMultiple($targetPaletteNbt));
		$metaMap = json_decode($targetMetaMapJson, true);
		if(is_array($metaMap) && count($metaMap) < $paletteCount){
			// Pad with 0 (default meta) for new entries
			while(count($metaMap) < $paletteCount){
				$metaMap[] = 0;
			}
			$targetMetaMapJson = json_encode($metaMap);
		}

		$this->targetDictionary = BlockStateDictionary::loadFromString($targetPaletteNbt, $targetMetaMapJson);

		// Find fallback: "minecraft:info_update" or first block as fallback
		$this->targetFallbackId = 0;
		$this->buildCache();
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
	 */
	public function nativeToTarget(int $nativeRuntimeId) : int{
		return $this->nativeToTargetCache[$nativeRuntimeId] ?? $this->targetFallbackId;
	}

	/**
	 * Translate target protocol runtime ID → server's native network runtime ID.
	 * Used for inbound packets (client → server).
	 */
	public function targetToNative(int $targetRuntimeId) : int{
		return $this->targetToNativeCache[$targetRuntimeId] ?? 0;
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
		$result = $targetRid ?? $this->targetFallbackId;
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
