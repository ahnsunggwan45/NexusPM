<?php

declare(strict_types=1);

namespace NexusPM\network;

use NexusPM\mapping\NexusBlockTranslator;
use NexusPM\mapping\RuntimeIdMapper;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\UpdateSubChunkBlocksPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;

/**
 * Centralized packet translation helper.
 * Handles block runtime ID translation and Y-coordinate encoding fixes
 * for ALL relevant packets in one place.
 *
 * Outbound (server→client): translate native block runtime IDs → target version IDs
 * Inbound (client→server): reverse translate + fix Y coordinate encoding
 */
class PacketTranslationHelper{

	private RuntimeIdMapper $mapper;
	private ?NexusBlockTranslator $blockTranslator;

	public function __construct(RuntimeIdMapper $mapper, ?NexusBlockTranslator $blockTranslator = null){
		$this->mapper = $mapper;
		$this->blockTranslator = $blockTranslator;
	}

	// ─── Block ID translation ──────────────────────────────────

	public function mapBlockIdToTarget(int $nativeRid) : int{
		if($this->blockTranslator !== null){
			return $this->blockTranslator->nativeToTarget($nativeRid);
		}
		return $this->mapper->toTarget($nativeRid);
	}

	public function mapBlockIdToBase(int $targetRid) : int{
		if($this->blockTranslator !== null){
			return $this->blockTranslator->targetToNative($targetRid);
		}
		return $this->mapper->toBase($targetRid);
	}

	// ─── Y coordinate fix ──────────────────────────────────────
	// v944 sends Y as SignedVarInt (ZigZag), PMMP reads as UnsignedVarInt
	// Fix: ZigZag-decode the Y value

	public static function fixInboundY(int $rawY) : int{
		return ($rawY >> 1) ^ -($rawY & 1);
	}

	public static function fixBlockPosition(BlockPosition $pos) : BlockPosition{
		$fixedY = self::fixInboundY($pos->getY());
		if($fixedY !== $pos->getY()){
			return new BlockPosition($pos->getX(), $fixedY, $pos->getZ());
		}
		return $pos;
	}

	// ─── Outbound packet translation ───────────────────────────

	/**
	 * Translate block runtime IDs in an outbound packet.
	 * Returns cloned packet if modified, original if unchanged.
	 */
	public function translateOutbound(ClientboundPacket $packet) : ClientboundPacket{
		// UpdateBlockPacket + UpdateBlockSyncedPacket
		if($packet instanceof UpdateBlockPacket){
			$packet = clone $packet;
			$packet->blockRuntimeId = $this->mapBlockIdToTarget($packet->blockRuntimeId);
			return $packet;
		}

		// UpdateSubChunkBlocksPacket
		if($packet instanceof UpdateSubChunkBlocksPacket){
			return $this->translateUpdateSubChunkBlocks($packet);
		}

		// LevelSoundEventPacket
		if($packet instanceof LevelSoundEventPacket){
			if($packet->extraData > 0){
				$packet = clone $packet;
				$packet->extraData = $this->mapBlockIdToTarget($packet->extraData);
			}
			return $packet;
		}

		// LevelEventPacket
		if($packet instanceof LevelEventPacket){
			return $this->translateLevelEvent($packet);
		}

		// UpdateClientInputLocksPacket: v944 removes serverPosition (Vector3f = 12 bytes)
		// PMMP sends: lockComponentData(varint) + serverPosition(3x floatLE)
		// v944 expects: lockComponentData(varint) only
		// We handle this by intercepting the encoded packet — but since we can't
		// modify encoding easily, and the v944 client tolerates extra trailing data,
		// this is safe to pass through. If issues arise, implement raw buffer stripping.

		return $packet;
	}

	private function translateUpdateSubChunkBlocks(UpdateSubChunkBlocksPacket $packet) : UpdateSubChunkBlocksPacket{
		$packet = clone $packet;

		// Layer 0 entries
		$newLayer0 = [];
		foreach($packet->getBlockChanges() as $entry){
			$newLayer0[] = new \pocketmine\network\mcpe\protocol\types\UpdateSubChunkBlocksPacketEntry(
				$entry->getBlockPosition(),
				$this->mapBlockIdToTarget($entry->getBlockRuntimeId()),
				$entry->getFlags(),
				$entry->getSyncedUpdateActorUniqueId(),
				$entry->getSyncedUpdateType()
			);
		}

		// Layer 1 entries (waterlogging etc.)
		$newLayer1 = [];
		foreach($packet->getExtraBlockChanges() as $entry){
			$newLayer1[] = new \pocketmine\network\mcpe\protocol\types\UpdateSubChunkBlocksPacketEntry(
				$entry->getBlockPosition(),
				$this->mapBlockIdToTarget($entry->getBlockRuntimeId()),
				$entry->getFlags(),
				$entry->getSyncedUpdateActorUniqueId(),
				$entry->getSyncedUpdateType()
			);
		}

		// Reconstruct packet via reflection (fields are private)
		$ref = new \ReflectionClass($packet);
		$ref->getProperty("blockChanges")->setValue($packet, $newLayer0);
		$ref->getProperty("extraBlockChanges")->setValue($packet, $newLayer1);

		return $packet;
	}

	private function translateLevelEvent(LevelEventPacket $packet) : LevelEventPacket{
		switch($packet->eventId){
			case 2001: // PARTICLE_DESTROY
			case 2021: // PARTICLE_DESTROY_NO_SOUND
			case 0x4000 | 15: // ADD_PARTICLE_MASK | TERRAIN
				$packet = clone $packet;
				$packet->eventData = $this->mapBlockIdToTarget($packet->eventData);
				return $packet;

			case 2014: // PARTICLE_PUNCH_BLOCK
			case 3603: case 3604: case 3605: // PUNCH_BLOCK direction variants
			case 3606: case 3607: case 3608:
				$packet = clone $packet;
				$rid = $packet->eventData & 0xFFFFFF;
				$upper = $packet->eventData & ~0xFFFFFF;
				$packet->eventData = $this->mapBlockIdToTarget($rid) | $upper;
				return $packet;
		}
		return $packet;
	}

	// ─── Inbound packet translation ────────────────────────────

	/**
	 * Translate block runtime IDs and fix Y coordinates in inbound packets.
	 */
	public function translateInbound(ServerboundPacket $packet) : void{
		// InventoryTransactionPacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\InventoryTransactionPacket){
			if($packet->trData instanceof UseItemTransactionData){
				$this->fixUseItemTransactionData($packet->trData);
			}
			return;
		}

		// PlayerAuthInputPacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\PlayerAuthInputPacket){
			// itemInteractionData
			$interaction = $packet->getItemInteractionData();
			if($interaction !== null){
				$this->fixUseItemTransactionData($interaction->getTransactionData());
			}

			// blockActions (PlayerBlockActionWithBlockInfo contains BlockPosition)
			$blockActions = $packet->getBlockActions();
			if($blockActions !== null){
				foreach($blockActions as $action){
					if($action instanceof \pocketmine\network\mcpe\protocol\types\PlayerBlockActionWithBlockInfo){
						$this->fixBlockPositionInObject($action, "blockPosition");
					}
				}
			}
			return;
		}

		// LevelSoundEventPacket inbound
		if($packet instanceof LevelSoundEventPacket){
			if($packet->extraData > 0){
				$packet->extraData = $this->mapBlockIdToBase($packet->extraData);
			}
			return;
		}

		// All other serverbound packets with BlockPosition — fix Y coordinate
		$this->fixBlockPositionPackets($packet);
	}

	/**
	 * Fix UseItemTransactionData: blockPosition Y + blockRuntimeId + itemStack blockRuntimeId
	 */
	private function fixUseItemTransactionData(UseItemTransactionData $data) : void{
		// Fix blockPosition Y
		$this->fixBlockPositionInObject($data, "blockPosition");

		// Fix blockRuntimeId (clicked block)
		$ref = new \ReflectionProperty($data, "blockRuntimeId");
		$ref->setValue($data, $this->mapBlockIdToBase($data->getBlockRuntimeId()));

		// Fix itemInHand → ItemStack::blockRuntimeId
		$stack = $data->getItemInHand()->getItemStack();
		if(!$stack->isNull() && $stack->getBlockRuntimeId() !== 0){
			$ref = new \ReflectionProperty($stack, "blockRuntimeId");
			$ref->setValue($stack, $this->mapBlockIdToBase($stack->getBlockRuntimeId()));
		}
	}

	/**
	 * Fix BlockPosition Y coordinate in an object's property via Reflection.
	 */
	private function fixBlockPositionInObject(object $obj, string $propertyName) : void{
		try{
			$ref = new \ReflectionProperty($obj, $propertyName);
			$pos = $ref->getValue($obj);
			if($pos instanceof BlockPosition){
				$fixed = self::fixBlockPosition($pos);
				if($fixed !== $pos){
					$ref->setValue($obj, $fixed);
				}
			}
		}catch(\ReflectionException){
			// Property doesn't exist — skip
		}
	}

	/**
	 * Fix BlockPosition in other serverbound packets that contain block positions.
	 */
	private function fixBlockPositionPackets(ServerboundPacket $packet) : void{
		// BlockActorDataPacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\BlockActorDataPacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}

		// PlayerActionPacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\PlayerActionPacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}

		// BlockPickRequestPacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\BlockPickRequestPacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}

		// AnvilDamagePacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\AnvilDamagePacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}

		// CommandBlockUpdatePacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}

		// LecternUpdatePacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\LecternUpdatePacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}

		// StructureBlockUpdatePacket
		if($packet instanceof \pocketmine\network\mcpe\protocol\StructureBlockUpdatePacket){
			$this->fixBlockPositionInObject($packet, "blockPosition");
			return;
		}
	}
}
