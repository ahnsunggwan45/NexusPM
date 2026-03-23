<?php

declare(strict_types=1);

namespace NexusPM\codec;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

/**
 * A VersionCodec translates packets between the base protocol and a specific higher version.
 *
 * Design for extensibility:
 *   - Each new protocol version gets its own VersionCodec implementation
 *   - The codec registers PacketRewriters for each changed packet
 *   - Unchanged packets pass through without any overhead
 *   - New serverbound-only packets are dropped (PMMP won't understand them)
 *   - New clientbound-only packets are never sent (PMMP doesn't know about them)
 */
interface VersionCodec{

	public function getProtocolVersion() : int;

	public function getGameVersion() : string;

	/**
	 * Inbound: translate a packet from this higher version to base version.
	 * Returns null to drop the packet.
	 */
	public function handleInbound(ServerboundPacket $packet) : ?ServerboundPacket;

	/**
	 * Outbound: translate a packet from base version to this higher version.
	 * Returns null to drop the packet.
	 */
	public function handleOutbound(ClientboundPacket $packet) : ?ClientboundPacket;

	/**
	 * Returns the list of new serverbound packet IDs introduced in this version
	 * that should be silently dropped on inbound (PMMP doesn't know them).
	 *
	 * @return int[]
	 */
	public function getDroppedInboundPacketIds() : array;
}
