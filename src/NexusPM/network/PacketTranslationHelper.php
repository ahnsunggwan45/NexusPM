<?php

declare(strict_types=1);

namespace NexusPM\network;

use NexusPM\mapping\NexusBlockTranslator;
use NexusPM\mapping\RuntimeIdMapper;
use NexusPM\utils\ReflectionCache;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\BlockEventPacket;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\UpdateSubChunkBlocksPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
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

	// ─── Item ID Mapping ──────────────────────────────────────

	/** @var array<int, int> native item rid → target item rid */
	private array $itemIdNativeToTarget = [];
	/** @var array<int, int> target item rid → native item rid */
	private array $itemIdTargetToNative = [];

	public function setItemIdMapping(array $nativeToTarget, array $targetToNative) : void{
		$this->itemIdNativeToTarget = $nativeToTarget;
		$this->itemIdTargetToNative = $targetToNative;
	}

	private function translateItemStackItemId(\pocketmine\network\mcpe\protocol\types\inventory\ItemStack $stack) : void{
		$id = $stack->getId();
		if($id !== 0 && isset($this->itemIdNativeToTarget[$id])){
			ReflectionCache::setValue($stack, "id", $this->itemIdNativeToTarget[$id]);
		}
	}

	private function reverseItemStackItemId(\pocketmine\network\mcpe\protocol\types\inventory\ItemStack $stack) : void{
		$id = $stack->getId();
		if($id !== 0 && isset($this->itemIdTargetToNative[$id])){
			ReflectionCache::setValue($stack, "id", $this->itemIdTargetToNative[$id]);
		}
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

	/**
	 * Encode outbound BlockPosition Y as ZigZag for v944+.
	 * PMMP writes Y as UnsignedVarInt, but v944 client reads Y as SignedVarInt.
	 * Pre-encode Y as ZigZag so PMMP's UnsignedVarInt write produces correct SignedVarInt bytes.
	 */
	private function encodeOutboundBlockPosition(BlockPosition $pos) : BlockPosition{
		$y = $pos->getY();
		$zigzagY = ($y << 1) ^ ($y >> 31);
		return new BlockPosition($pos->getX(), $zigzagY, $pos->getZ());
	}

	/**
	 * Fix BlockPosition Y in outbound packets that use CommonTypes::putBlockPosition().
	 * All packets using getBlockPosition() for DECODE also use putBlockPosition() for ENCODE.
	 */
	private function fixOutboundBlockPositions(ClientboundPacket $packet) : ClientboundPacket{
		// Packets with blockPosition field
		$blockPosPackets = [
			ContainerOpenPacket::class,
			BlockEventPacket::class,
			BlockActorDataPacket::class,
			\pocketmine\network\mcpe\protocol\OpenSignPacket::class,
		];
		foreach($blockPosPackets as $class){
			if($packet instanceof $class){
				$packet = clone $packet;
				try{
					$bp = ReflectionCache::getValue($packet, "blockPosition");
					if($bp instanceof BlockPosition){
						ReflectionCache::setValue($packet, "blockPosition", $this->encodeOutboundBlockPosition($bp));
					}
				}catch(\ReflectionException){}
				return $packet;
			}
		}

		// SetSpawnPositionPacket — has spawnPosition AND causingBlockPosition
		if($packet instanceof \pocketmine\network\mcpe\protocol\SetSpawnPositionPacket){
			$packet = clone $packet;
			$packet->spawnPosition = $this->encodeOutboundBlockPosition($packet->spawnPosition);
			$packet->causingBlockPosition = $this->encodeOutboundBlockPosition($packet->causingBlockPosition);
			return $packet;
		}

		// AddVolumeEntityPacket — has minBound AND maxBound (private)
		if($packet instanceof \pocketmine\network\mcpe\protocol\AddVolumeEntityPacket){
			$packet = clone $packet;
			try{
				$min = ReflectionCache::getValue($packet, "minBound");
				$max = ReflectionCache::getValue($packet, "maxBound");
				if($min instanceof BlockPosition) ReflectionCache::setValue($packet, "minBound", $this->encodeOutboundBlockPosition($min));
				if($max instanceof BlockPosition) ReflectionCache::setValue($packet, "maxBound", $this->encodeOutboundBlockPosition($max));
			}catch(\ReflectionException){}
			return $packet;
		}

		// PlaySoundPacket — encodes float coords as BlockPosition internally
		if($packet instanceof \pocketmine\network\mcpe\protocol\PlaySoundPacket){
			$packet = clone $packet;
			// PlaySoundPacket encodes Y as putBlockPosition(int(y*8))
			// We need to pre-encode so the client reads correctly
			$rawY = (int) ($packet->y * 8);
			$zigzagY = ($rawY << 1) ^ ($rawY >> 31);
			$packet->y = $zigzagY / 8;
			return $packet;
		}

		return $packet;
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
			if($this->needsYFix){
				$packet->blockPosition = $this->encodeOutboundBlockPosition($packet->blockPosition);
			}
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

		if($this->needsYFix){
			$packet = $this->fixOutboundBlockPositions($packet);
		}

		if($packet instanceof InventorySlotPacket){
			$stack = $packet->item->getItemStack();
			if(!$stack->isNull()){
				$packet = clone $packet;
				$this->translateOutboundItemStack($stack);
			}
			return $packet;
		}

		if($packet instanceof InventoryContentPacket){
			$changed = false;
			foreach($packet->items as $wrapper){
				$stack = $wrapper->getItemStack();
				if(!$stack->isNull()){
					$this->translateOutboundItemStack($stack);
					$changed = true;
				}
			}
			if($changed){
				$packet = clone $packet;
			}
			return $packet;
		}

		if($packet instanceof MobEquipmentPacket){
			$stack = $packet->item->getItemStack();
			if(!$stack->isNull()){
				$packet = clone $packet;
				$this->translateOutboundItemStack($stack);
			}
			return $packet;
		}

		if($packet instanceof CreativeContentPacket){
			$packet = clone $packet;
			foreach($packet->getItems() as $entry){
				$stack = $entry->getItem();
				if(!$stack->isNull()){
					$this->translateOutboundItemStack($stack);
				}
			}
			return $packet;
		}

		if($packet instanceof AddActorPacket){
			return $this->translateActorMetadataBlockRids($packet);
		}

		if($packet instanceof SetActorDataPacket){
			return $this->translateSetActorData($packet);
		}

		return $packet;
	}

	private function translateActorMetadataBlockRids(AddActorPacket $packet) : AddActorPacket{
		// FALLING_BLOCK 등 엔티티의 VARIANT 메타데이터에 블록 런타임 ID가 들어감
		$variantKey = \pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::VARIANT;
		if(isset($packet->metadata[$variantKey])){
			$prop = $packet->metadata[$variantKey];
			if($prop instanceof \pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty){
				$native = $prop->getValue();
				$target = $this->toTarget($native);
				if($target !== $native){
					$packet = clone $packet;
					$packet->metadata[$variantKey] = new \pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty($target);
				}
			}
		}
		return $packet;
	}

	private function translateSetActorData(SetActorDataPacket $packet) : SetActorDataPacket{
		$variantKey = \pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::VARIANT;
		if(isset($packet->metadata[$variantKey])){
			$prop = $packet->metadata[$variantKey];
			if($prop instanceof \pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty){
				$native = $prop->getValue();
				$target = $this->toTarget($native);
				if($target !== $native){
					$packet = clone $packet;
					$packet->metadata[$variantKey] = new \pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty($target);
				}
			}
		}
		return $packet;
	}

	private function translateOutboundItemStack(\pocketmine\network\mcpe\protocol\types\inventory\ItemStack $stack) : void{
		if($stack->getBlockRuntimeId() !== 0){
			ReflectionCache::setValue($stack, "blockRuntimeId", $this->toTarget($stack->getBlockRuntimeId()));
		}
		$this->translateItemStackItemId($stack);
	}

	private function reverseInboundItemStack(\pocketmine\network\mcpe\protocol\types\inventory\ItemStack $stack) : void{
		if($stack->getBlockRuntimeId() !== 0){
			ReflectionCache::setValue($stack, "blockRuntimeId", $this->toBase($stack->getBlockRuntimeId()));
		}
		$this->reverseItemStackItemId($stack);
	}

	private function translateSubChunkBlocks(UpdateSubChunkBlocksPacket $packet) : UpdateSubChunkBlocksPacket{
		$packet = clone $packet;

		$mapEntries = function(array $entries) : array{
			$result = [];
			foreach($entries as $entry){
				$pos = $entry->getBlockPosition();
				if($this->needsYFix){
					$pos = $this->encodeOutboundBlockPosition($pos);
				}
				$result[] = new UpdateSubChunkBlocksPacketEntry(
					$pos,
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
			// Reverse-translate blockRuntimeId in all transaction actions (Q drop, etc.)
			$this->fixTransactionActions($packet->trData->getActions());
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

		// Reverse-translate ItemStack (held item)
		$stack = $data->getItemInHand()->getItemStack();
		if(!$stack->isNull()){
			$this->reverseInboundItemStack($stack);
		}
	}

	/**
	 * Reverse-translate item IDs and blockRuntimeId in transaction actions (Q drop, inventory moves, etc.)
	 * @param \pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction[] $actions
	 */
	private function fixTransactionActions(array $actions) : void{
		foreach($actions as $action){
			$old = $action->oldItem->getItemStack();
			if(!$old->isNull()){
				$this->reverseInboundItemStack($old);
			}
			$new = $action->newItem->getItemStack();
			if(!$new->isNull()){
				$this->reverseInboundItemStack($new);
			}
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

		// Only packets using CommonTypes::getBlockPosition() (UnsignedVarInt Y).
		// BlockPickRequestPacket uses getSignedBlockPosition() — already ZigZag decoded, NO fix needed.
		$classes = [
			\pocketmine\network\mcpe\protocol\BlockActorDataPacket::class,
			\pocketmine\network\mcpe\protocol\PlayerActionPacket::class,
			\pocketmine\network\mcpe\protocol\AnvilDamagePacket::class,
			\pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket::class,
			\pocketmine\network\mcpe\protocol\LecternUpdatePacket::class,
			\pocketmine\network\mcpe\protocol\StructureBlockUpdatePacket::class,
			\pocketmine\network\mcpe\protocol\StructureTemplateDataRequestPacket::class,
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
