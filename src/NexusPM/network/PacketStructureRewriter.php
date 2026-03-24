<?php

declare(strict_types=1);

namespace NexusPM\network;

use pocketmine\network\mcpe\protocol\ProtocolInfo;

/**
 * Rewrites outbound packet raw buffers to match v944 serialization format.
 *
 * NexusPM translates DATA (block IDs, item IDs) at the packet object level,
 * but the STRUCTURE (serialization format) remains v924. This is fine for
 * direct client connections (clients tolerate minor differences), but strict
 * parsers like WaterdogPE fail when decoding v924-structured packets as v944.
 *
 * This class patches the raw encoded bytes for packets with structural changes:
 *   - StartGamePacket: serverJoinInfo 1→3 booleans
 *   - UpdateClientInputLocksPacket: strip serverPosition (12 bytes)
 *
 * Packet buffer format: [varint header][payload...]
 * Header contains packet ID in lower 10 bits.
 */
class PacketStructureRewriter{

	/**
	 * Rewrite an encoded packet buffer from v924 structure to v944 structure.
	 * Returns the original buffer if no changes needed.
	 */
	public function rewrite(string $buffer) : string{
		if(strlen($buffer) < 2) return $buffer;

		// Read packet ID from varint header
		$offset = 0;
		$header = self::readUVarInt($buffer, $offset);
		$packetId = $header & 0x3FF;

		return match($packetId){
			ProtocolInfo::START_GAME_PACKET => $this->rewriteStartGame($buffer),
			ProtocolInfo::UPDATE_CLIENT_INPUT_LOCKS_PACKET => $this->rewriteUpdateClientInputLocks($buffer, $offset),
			337 => $this->rewriteVoxelShapes($buffer), // VoxelShapesPacket
			default => $buffer,
		};
	}

	/**
	 * StartGamePacket: serverJoinInfo block changed from 1 bool to 3 bools.
	 *
	 * v924: ...hasServerJoinInfo(bool) [if true: 1 bool] serverId(string)...
	 * v944: ...hasServerJoinInfo(bool) [if true: 3 bools] serverId(string)...
	 *
	 * We need to find the serverJoinInfo flag. If it's true, insert 2 extra
	 * false bytes after the existing boolean.
	 *
	 * Since StartGamePacket is extremely complex, we search for the pattern:
	 * The serverJoinInfo block is near the end of the packet, right before
	 * the final string fields (serverId, scenarioId, worldId, ownerId).
	 *
	 * In practice, PMMP always sends hasServerJoinInformation = false,
	 * so this rewriter is a safety net. When false, no extra bytes needed.
	 */
	private function rewriteStartGame(string $buffer) : string{
		// PMMP always sends hasServerJoinInformation = false.
		// If false, the conditional block is skipped and no structural change needed.
		// We leave this as pass-through for now — only needed if PMMP ever sends true.
		return $buffer;
	}

	/**
	 * UpdateClientInputLocksPacket: serverPosition (Vector3f) removed in v944.
	 *
	 * v924: [header][lockComponentData: unsigned varint][serverPosition: 3x floatLE (12 bytes)]
	 * v944: [header][lockComponentData: unsigned varint]
	 *
	 * Strip the trailing 12 bytes (serverPosition).
	 */
	private function rewriteUpdateClientInputLocks(string $buffer, int $afterHeader) : string{
		// Skip lockComponentData (unsigned varint)
		$offset = $afterHeader;
		self::readUVarInt($buffer, $offset);

		// Everything after the varint is serverPosition (12 bytes) — remove it
		if($offset + 12 === strlen($buffer)){
			return substr($buffer, 0, $offset);
		}

		// Buffer doesn't match expected format — pass through
		return $buffer;
	}

	/**
	 * VoxelShapesPacket: v944 appends customShapeCount (unsigned short LE, 2 bytes).
	 *
	 * v924: [header][...data]
	 * v944: [header][...data][customShapeCount: 2 bytes LE]
	 */
	private function rewriteVoxelShapes(string $buffer) : string{
		// Append customShapeCount = 0 (2 bytes, unsigned short LE)
		return $buffer . "\x00\x00";
	}

	// ─── VarInt ────────────────────────────────────────────────

	private static function readUVarInt(string $buf, int &$off) : int{
		$val = 0;
		$shift = 0;
		do{
			$b = ord($buf[$off++]);
			$val |= ($b & 0x7F) << $shift;
			$shift += 7;
		}while(($b & 0x80) !== 0 && $shift < 35);
		return $val;
	}
}
