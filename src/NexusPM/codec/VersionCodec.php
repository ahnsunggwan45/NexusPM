<?php

declare(strict_types=1);

namespace NexusPM\codec;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

/**
 * A VersionCodec defines how to translate packets between the base protocol
 * and a specific higher version.
 *
 * Each version codec specifies:
 *   - Which serverbound packet IDs to drop (PMMP doesn't know them)
 *   - Packet-level rewriters for structural changes (optional)
 *   - Version-specific quirks (Y-coordinate encoding, etc.)
 */
interface VersionCodec{

	public function getProtocolVersion() : int;

	public function getGameVersion() : string;

	public function handleInbound(ServerboundPacket $packet) : ?ServerboundPacket;

	public function handleOutbound(ClientboundPacket $packet) : ?ClientboundPacket;

	/** @return int[] New serverbound packet IDs to silently drop */
	public function getDroppedInboundPacketIds() : array;

	/**
	 * Whether inbound BlockPosition Y-coordinate needs ZigZag decoding.
	 * v944+ changed Y from UnsignedVarInt to SignedVarInt (ZigZag).
	 */
	public function needsInboundYFix() : bool;
}
