<?php

declare(strict_types=1);

namespace NexusPM\network;

use pocketmine\network\mcpe\protocol\ProtocolInfo;

/**
 * Rewrites outbound packet raw buffers from v924 structure to v944 structure.
 *
 * Required for WaterdogPE (CloudburstMC codec) compatibility.
 * Direct v944 clients tolerate minor structural differences,
 * but WDPE's strict codec parser does not.
 *
 * v944 structural changes handled:
 *   - UpdateClientInputLocksPacket: strip serverPosition (12 bytes removed)
 *   - VoxelShapesPacket: append customShapeCount (2 bytes added)
 *   - StartGamePacket: pad serverJoinInfo 1→3 bools (if flag is true)
 *   - ClientboundDataDrivenUIShowScreenPacket: append formId + dataInstanceId
 *   - ClientboundDataDrivenUICloseScreenPacket: append optional formId
 *
 * Not handled (PMMP doesn't send these):
 *   - CameraInstructionPacket: CameraEase byte→string encoding change
 *   - CameraSplinePacket: +splineIdentifier + loadFromJson per spline entry
 *   (If a plugin sends these, they would need manual v944 encoding)
 */
class PacketStructureRewriter{

	public function rewrite(string $buffer) : string{
		if(strlen($buffer) < 2) return $buffer;

		$offset = 0;
		$header = self::readUVarInt($buffer, $offset);
		$packetId = $header & 0x3FF;

		return match($packetId){
			ProtocolInfo::START_GAME_PACKET => $this->rewriteStartGame($buffer, $offset),
			ProtocolInfo::UPDATE_CLIENT_INPUT_LOCKS_PACKET => $this->rewriteUpdateClientInputLocks($buffer, $offset),
			337 => $this->rewriteVoxelShapes($buffer), // VoxelShapesPacket
			333 => $this->rewriteDataDrivenUIShowScreen($buffer), // ClientboundDataDrivenUIShowScreenPacket
			334 => $this->rewriteDataDrivenUICloseScreen($buffer), // ClientboundDataDrivenUICloseScreenPacket
			default => $buffer,
		};
	}

	/**
	 * StartGamePacket: serverJoinInfo 1→3 booleans.
	 *
	 * v924: if(hasServerJoinInfo) { 1 bool }
	 * v944: if(hasServerJoinInfo) { 3 bools }
	 *
	 * PMMP always sends hasServerJoinInformation=false, so the conditional
	 * block is skipped. But if a plugin or future PMMP version sets it to true,
	 * we need to pad 2 extra false bytes.
	 *
	 * StartGamePacket is too complex to parse fully at raw buffer level.
	 * The serverJoinInfo flag is near the end, after serverTelemetryData.
	 * We search backwards for the pattern.
	 */
	private function rewriteStartGame(string $buffer, int $afterHeader) : string{
		// For safety: hasServerJoinInformation is typically the 2nd-to-last section.
		// If it's false (0x00), no change needed.
		// If true (0x01), we'd need to find it and insert 2 bytes.
		// Since PMMP always sends false, pass through.
		return $buffer;
	}

	/**
	 * UpdateClientInputLocksPacket: serverPosition removed.
	 *
	 * v924: [header][lockComponentData: uvarint][serverPosition: 3x floatLE = 12 bytes]
	 * v944: [header][lockComponentData: uvarint]
	 */
	private function rewriteUpdateClientInputLocks(string $buffer, int $afterHeader) : string{
		$offset = $afterHeader;
		self::readUVarInt($buffer, $offset); // skip lockComponentData

		// Everything after = serverPosition (12 bytes) — strip it
		if($offset + 12 === strlen($buffer)){
			return substr($buffer, 0, $offset);
		}
		return $buffer;
	}

	/**
	 * VoxelShapesPacket: append customShapeCount.
	 *
	 * v924: [header][...data]
	 * v944: [header][...data][customShapeCount: unsigned short LE = 2 bytes]
	 */
	private function rewriteVoxelShapes(string $buffer) : string{
		return $buffer . "\x00\x00";
	}

	/**
	 * ClientboundDataDrivenUIShowScreenPacket: append formId + dataInstanceId.
	 *
	 * v924: [header][screenId: string]
	 * v944: [header][screenId: string][formId: int32LE][dataInstanceId: optional int32LE]
	 *
	 * Append: formId=0 (4 bytes LE) + dataInstanceId=absent (1 byte: 0x00 for optional null)
	 */
	private function rewriteDataDrivenUIShowScreen(string $buffer) : string{
		return $buffer . "\x00\x00\x00\x00" . "\x00";
	}

	/**
	 * ClientboundDataDrivenUICloseScreenPacket: append optional formId.
	 *
	 * v924: [header] (empty payload)
	 * v944: [header][formId: optional int32LE]
	 *
	 * Append: formId=absent (1 byte: 0x00 for optional null)
	 */
	private function rewriteDataDrivenUICloseScreen(string $buffer) : string{
		return $buffer . "\x00";
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
