<?php

declare(strict_types=1);

namespace NexusPM\codec;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

/**
 * A PacketRewriter handles field-level translation for a specific packet type.
 *
 * To add support for a new version:
 *   1. Create a class extending PacketRewriter
 *   2. Implement rewriteInbound() and/or rewriteOutbound()
 *   3. Register it in the version's Codec constructor
 *
 * The rewriter receives the decoded packet object (not raw bytes).
 * It can modify fields in-place and return the same object,
 * create a new packet object, or return null to drop the packet.
 */
abstract class PacketRewriter{

	/**
	 * The packet ID this rewriter handles.
	 */
	abstract public function getPacketId() : int;

	/**
	 * Rewrite an inbound (client→server) packet from higher version to base version.
	 * Default: pass-through.
	 */
	public function rewriteInbound(ServerboundPacket $packet) : ?ServerboundPacket{
		return $packet;
	}

	/**
	 * Rewrite an outbound (server→client) packet from base version to higher version.
	 * Default: pass-through.
	 */
	public function rewriteOutbound(ClientboundPacket $packet) : ?ClientboundPacket{
		return $packet;
	}
}
