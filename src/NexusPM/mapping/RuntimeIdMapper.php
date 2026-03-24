<?php

declare(strict_types=1);

namespace NexusPM\mapping;

use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;

/**
 * Builds and caches block runtime ID mapping tables between two protocol versions.
 *
 * The mapping is built by comparing both palettes' block state entries
 * (name + state properties) and matching them by identity.
 *
 * v924 runtime ID → v944 runtime ID (for outbound: server→client)
 * v944 runtime ID → v924 runtime ID (for inbound: client→server)
 */
class RuntimeIdMapper{

	/** @var int[] v924 runtimeId => v944 runtimeId */
	private array $baseToTarget = [];

	/** @var int[] v944 runtimeId => v924 runtimeId */
	private array $targetToBase = [];

	private int $fallbackBaseId = 0;
	private int $fallbackTargetId = 0;

	/**
	 * @param string $basePaletteNbt     Raw NetworkNbt data for base version palette
	 * @param string $targetPaletteNbt   Raw NetworkNbt data for target version palette
	 */
	public function __construct(string $basePaletteNbt, string $targetPaletteNbt){
		$this->buildMapping($basePaletteNbt, $targetPaletteNbt);
	}

	private function buildMapping(string $baseNbt, string $targetNbt) : void{
		$netNbt = new NetworkNbtSerializer();

		$baseRoots = $netNbt->readMultiple($baseNbt);
		$targetRoots = $netNbt->readMultiple($targetNbt);

		// Build target palette: stateKey => runtimeId
		$targetMap = [];
		foreach($targetRoots as $rid => $root){
			$key = self::stateKey($root->mustGetCompoundTag());
			$targetMap[$key] = $rid;
		}

		// Map each base state to its target runtime ID
		foreach($baseRoots as $baseRid => $root){
			$key = self::stateKey($root->mustGetCompoundTag());
			if(isset($targetMap[$key])){
				$targetRid = $targetMap[$key];
				$this->baseToTarget[$baseRid] = $targetRid;
				$this->targetToBase[$targetRid] = $baseRid;
			}else{
				// Block doesn't exist in target — map to air or stone as fallback
				$this->baseToTarget[$baseRid] = $this->fallbackTargetId;
			}
		}

		// Find air runtime IDs for fallback
		foreach($baseRoots as $rid => $root){
			if($root->mustGetCompoundTag()->getString("name") === "minecraft:air"){
				$this->fallbackBaseId = $rid;
				break;
			}
		}
		foreach($targetRoots as $rid => $root){
			if($root->mustGetCompoundTag()->getString("name") === "minecraft:air"){
				$this->fallbackTargetId = $rid;
				break;
			}
		}
	}

	/**
	 * Create a unique key from a block state CompoundTag.
	 * Format: "minecraft:stone[key1=val1,key2=val2]"
	 */
	private static function stateKey(CompoundTag $tag) : string{
		$name = $tag->getString("name");
		$states = $tag->getCompoundTag("states");
		if($states === null || count($states->getValue()) === 0){
			return $name . "[]";
		}

		$entries = [];
		foreach($states->getValue() as $k => $v){
			$entries[] = "$k=$v";
		}
		sort($entries);
		return $name . "[" . implode(",", $entries) . "]";
	}

	/**
	 * Translate a block runtime ID from base version to target version.
	 * Used for outbound packets (server→client).
	 */
	public function toTarget(int $baseRuntimeId) : int{
		return $this->baseToTarget[$baseRuntimeId] ?? $baseRuntimeId;
	}

	/**
	 * Translate a block runtime ID from target version to base version.
	 * Used for inbound packets (client→server).
	 */
	public function toBase(int $targetRuntimeId) : int{
		return $this->targetToBase[$targetRuntimeId] ?? $targetRuntimeId;
	}

	public function getMappedCount() : int{
		return count($this->baseToTarget);
	}

	public function getChangedCount() : int{
		$changed = 0;
		foreach($this->baseToTarget as $base => $target){
			if($base !== $target) $changed++;
		}
		return $changed;
	}
}
