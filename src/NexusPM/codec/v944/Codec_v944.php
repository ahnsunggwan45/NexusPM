<?php

declare(strict_types=1);

namespace NexusPM\codec\v944;

use NexusPM\codec\AbstractCodec;

/**
 * Protocol 944 (Minecraft Bedrock 1.26.10)
 * Base protocol: 924 (1.26.0)
 *
 * Changes from v924 (CloudburstMC/Protocol):
 *
 * Global:
 *   - BedrockCodecHelper_v944: Y coordinate in BlockPosition changed to SignedVarInt
 *     (was UnsignedVarInt) — handled by PacketTranslationHelper::fixInboundY()
 *
 * Updated serializers:
 *   - CameraInstructionPacket: CameraEase byte→string (PMMP doesn't send)
 *   - CameraSplinePacket: +2 fields per spline (PMMP doesn't send)
 *   - DataDrivenUICloseScreenPacket: +optional formId (PMMP doesn't send)
 *   - DataDrivenUIShowScreenPacket: +formId, +dataInstanceId (PMMP doesn't send)
 *   - LevelEventPacket: TypeMap only (handled by PacketTranslationHelper)
 *   - LevelEventGenericPacket: TypeMap only
 *   - LevelSoundEventPacket: TypeMap only (handled by PacketTranslationHelper)
 *   - PlayerAuthInputPacket: +clientCooldownState byte (tolerated by PMMP)
 *   - StartGamePacket: serverJoinInfo 1→3 bools (flag always false in PMMP)
 *   - UpdateClientInputLocksPacket: serverPosition removed (extra bytes tolerated)
 *   - VoxelShapesPacket: +customShapeCount (PMMP doesn't send)
 *
 * New packets (dropped on inbound):
 *   - ResourcePacksReadyForValidationPacket (ID 340, SERVER)
 *   - LocatorBarPacket (ID 341, CLIENT)
 *   - PartyChangedPacket (ID 342, SERVER)
 *   - ServerboundDataDrivenScreenClosedPacket (ID 343, SERVER)
 *   - SyncWorldClocksPacket (ID 344, CLIENT)
 *   - ClientboundAttributeLayerSyncPacket (ID 345, CLIENT)
 *
 * All block runtime ID and Y-coordinate translation is handled centrally
 * by PacketTranslationHelper, not by individual rewriters.
 *
 * @see https://github.com/CloudburstMC/Protocol/tree/3.0/bedrock-codec/src/main/java/org/cloudburstmc/protocol/bedrock/codec/v944
 */
class Codec_v944 extends AbstractCodec{

	public function __construct(){
		$this->registerDroppedInbound([
			340, // ResourcePacksReadyForValidationPacket
			342, // PartyChangedPacket
			343, // ServerboundDataDrivenScreenClosedPacket
		]);
	}

	public function getProtocolVersion() : int{
		return 944;
	}

	/**
	 * v944 changed BlockPosition Y from UnsignedVarInt to SignedVarInt (ZigZag).
	 * PMMP reads Y as unsigned, so we need to ZigZag-decode it.
	 */
	public function needsInboundYFix() : bool{
		return true;
	}
}
