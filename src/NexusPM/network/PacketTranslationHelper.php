<?php

declare(strict_types=1);

namespace NexusPM\network;

use NexusPM\mapping\NexusBlockTranslator;
use NexusPM\mapping\RuntimeIdMapper;
use NexusPM\utils\ReflectionCache;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\UpdateSubChunkBlocksPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\UpdateSubChunkBlocksPacketEntry;

/**
 * Centralized packet translation for a specific target protocol version.
 *
 * Handles:
 *   - Outbound block runtime ID translation (native → target)
 *   - Inbound block runtime ID reverse translation (target → native)
 *   - Inbound Y-coordinate encoding fix (version-dependent)
 *   - All block-containing packet types in one place
 */
class PacketTranslationHelper{

	public function __construct(
		private RuntimeIdMapper $mapper,
		private ?NexusBlockTranslator $blockTranslator = null,
		private bool $needsYFix = false
	){}

	// ─── Block ID Mapping ──────────────────────────────────────

	public function toTarget(int $nativeRid) : int{
		return $this->blockTranslator?->nativeToTarget($nativeRid) ?? $this->mapper->toTarget($nativeRid);
	}

	public function toBase(int $targetRid) : int{
		return $this->blockTranslator?->targetToNative($targetRid) ?? $this->mapper->toBase($targetRid);
	}

	// ─── Y Coordinate Fix ──────────────────────────────────────

	/**
	 * v944+ sends BlockPosition Y as SignedVarInt (ZigZag),
	 * but PMMP reads it as UnsignedVarInt.
	 * ZigZag decode: (raw >> 1) ^ -(raw & 1)
	 */
	private function fixY(int $rawY) : int{
		if(!$this->needsYFix) return $rawY;
		return ($rawY >> 1) ^ -($rawY & 1);
	}

	private function fixBlockPosition(BlockPosition $pos) : BlockPosition{
		if(!$this->needsYFix) return $pos;
		$fixedY = $this->fixY($pos->getY());
		return ($fixedY !== $pos->getY()) ? new BlockPosition($pos->getX(), $fixedY, $pos->getZ()) : $pos;
	}

	// ─── Outbound Translation ──────────────────────────────────

	/**
	 * Translate block runtime IDs in an outbound packet.
	 * Always clones before modifying to protect shared packet objects.
	 */
	public function translateOutbound(ClientboundPacket $packet) : ClientboundPacket{
		if($packet instanceof UpdateBlockPacket){
			$packet = clone $packet;
			$packet->blockRuntimeId = $this->toTarget($packet->blockRuntimeId);
			return $packet;
		}

		if($packet instanceof UpdateSubChunkBlocksPacket){
			return $this->translateSubChunkBlocks($packet);
		}

		if($packet instanceof LevelSoundEventPacket){
			if($packet->extraData > 0){
				$packet = clone $packet;
				$packet->extraData = $this->toTarget($packet->extraData);
			}
			return $packet;
		}

		if($packet instanceof LevelEventPacket){
			return $this->translateLevelEvent($packet);
		}

		return $packet;
	}

	private function translateSubChunkBlocks(UpdateSubChunkBlocksPacket $packet) : UpdateSubChunkBlocksPacket{
		$packet = clone $packet;

		$mapEntries = function(array $entries) : array{
			$result = [];
			foreach($entries as $entry){
				$result[] = new UpdateSubChunkBlocksPacketEntry(
					$entry->getBlockPosition(),
					$this->toTarget($entry->getBlockRuntimeId()),
					$entry->getFlags(),
					$entry->getSyncedUpdateActorUniqueId(),
					$entry->getSyncedUpdateType()
				);
			}
			return $result;
		};

		ReflectionCache::setValue($packet, "layer0Updates", $mapEntries($packet->getLayer0Updates()));
		ReflectionCache::setValue($packet, "layer1Updates", $mapEntries($packet->getLayer1Updates()));

		return $packet;
	}

	private function translateLevelEvent(LevelEventPacket $packet) : LevelEventPacket{
		switch($packet->eventId){
			case 2001: // PARTICLE_DESTROY
			case 2021: // PARTICLE_DESTROY_NO_SOUND
			case 0x4000 | 15: // ADD_PARTICLE_MASK | TERRAIN
				$packet = clone $packet;
				$packet->eventData = $this->toTarget($packet->eventData);
				return $packet;

			case 2014: // PARTICLE_PUNCH_BLOCK
			case 3603: case 3604: case 3605: case 3606: case 3607: case 3608:
				$packet = clone $packet;
				$rid = $packet->eventData & 0xFFFFFF;
				$upper = $packet->eventData & ~0xFFFFFF;
				$packet->eventData = $this->toTarget($rid) | $upper;
				return $packet;
		}
		return $packet;
	}

	// ─── Inbound Translation ───────────────────────────────────

	/**
	 * Reverse-translate block runtime IDs and fix Y coordinates
	 * in all relevant inbound packets.
	 */
	public function translateInbound(ServerboundPacket $packet) : void{
		if($packet instanceof \pocketmine\network\mcpe\protocol\InventoryTransactionPacket){
			if($packet->trData instanceof UseItemTransactionData){
				$this->fixUseItemData($packet->trData);
			}
			return;
		}

		if($packet instanceof \pocketmine\network\mcpe\protocol\PlayerAuthInputPacket){
			$interaction = $packet->getItemInteractionData();
			if($interaction !== null){
				$this->fixUseItemData($interaction->getTransactionData());
			}
			$this->fixBlockActionsY($packet);
			return;
		}

		if($packet instanceof LevelSoundEventPacket){
			if($packet->extraData > 0){
				$packet->extraData = $this->toBase($packet->extraData);
			}
			return;
		}

		// Fix Y in other serverbound packets with BlockPosition
		$this->fixGenericBlockPosition($packet);
	}

	private function fixUseItemData(UseItemTransactionData $data) : void{
		// Fix BlockPosition Y
		$bp = $data->getBlockPosition();
		$fixed = $this->fixBlockPosition($bp);
		if($fixed !== $bp){
			ReflectionCache::setValue($data, "blockPosition", $fixed);
		}

		// Reverse-translate block runtime ID (clicked block)
		ReflectionCache::setValue($data, "blockRuntimeId", $this->toBase($data->getBlockRuntimeId()));

		// Reverse-translate ItemStack::blockRuntimeId (held item)
		$stack = $data->getItemInHand()->getItemStack();
		if(!$stack->isNull() && $stack->getBlockRuntimeId() !== 0){
			ReflectionCache::setValue($stack, "blockRuntimeId", $this->toBase($stack->getBlockRuntimeId()));
		}
	}

	private function fixBlockActionsY(\pocketmine\network\mcpe\protocol\PlayerAuthInputPacket $packet) : void{
		// PlayerBlockActionWithBlockInfo uses getSignedBlockPosition() which already
		// reads Y as SignedVarInt (ZigZag). No Y fix needed here — applying it would
		// double-decode and corrupt the Y coordinate.
	}

	/**
	 * Fix BlockPosition Y in generic serverbound packets.
	 */
	private function fixGenericBlockPosition(ServerboundPacket $packet) : void{
		if(!$this->needsYFix) return;

		$classes = [
			\pocketmine\network\mcpe\protocol\BlockActorDataPacket::class,
			\pocketmine\network\mcpe\protocol\PlayerActionPacket::class,
			\pocketmine\network\mcpe\protocol\BlockPickRequestPacket::class,
			\pocketmine\network\mcpe\protocol\AnvilDamagePacket::class,
			\pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket::class,
			\pocketmine\network\mcpe\protocol\LecternUpdatePacket::class,
			\pocketmine\network\mcpe\protocol\StructureBlockUpdatePacket::class,
		];

		foreach($classes as $class){
			if($packet instanceof $class){
				try{
					$bp = ReflectionCache::getValue($packet, "blockPosition");
					if($bp instanceof BlockPosition){
						$fixed = $this->fixBlockPosition($bp);
						if($fixed !== $bp){
							ReflectionCache::setValue($packet, "blockPosition", $fixed);
						}
					}
				}catch(\ReflectionException){}
				return;
			}
		}
	}
}
