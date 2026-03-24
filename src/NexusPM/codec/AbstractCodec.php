<?php

declare(strict_types=1);

namespace NexusPM\codec;

use NexusPM\utils\ProtocolVersions;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

/**
 * Abstract base for version-specific codecs.
 * Manages a registry of PacketRewriters and dispatches to them.
 *
 * Subclasses register their rewriters in the constructor:
 *   $this->registerRewriter(new SomePacketRewriter());
 *
 * This is the recommended way to add new version codecs.
 */
abstract class AbstractCodec implements VersionCodec{

	/** @var array<int, PacketRewriter> packetId => rewriter */
	private array $inboundRewriters = [];

	/** @var array<int, PacketRewriter> packetId => rewriter */
	private array $outboundRewriters = [];

	/** @var int[] packet IDs to silently drop on inbound */
	private array $droppedInboundIds = [];

	public function getGameVersion() : string{
		return ProtocolVersions::getVersionName($this->getProtocolVersion()) ?? "unknown";
	}

	/**
	 * Register a rewriter for both inbound and outbound.
	 */
	protected function registerRewriter(PacketRewriter $rewriter) : void{
		$pid = $rewriter->getPacketId();
		$this->inboundRewriters[$pid] = $rewriter;
		$this->outboundRewriters[$pid] = $rewriter;
	}

	/**
	 * Register packet IDs that should be silently dropped on inbound.
	 * These are NEW serverbound packets introduced in this version that PMMP doesn't know.
	 *
	 * @param int[] $packetIds
	 */
	protected function registerDroppedInbound(array $packetIds) : void{
		$this->droppedInboundIds = array_merge($this->droppedInboundIds, $packetIds);
	}

	public function getDroppedInboundPacketIds() : array{
		return $this->droppedInboundIds;
	}

	/**
	 * Override in subclasses that need Y-coordinate ZigZag fix.
	 * Default: false (no fix needed).
	 */
	public function needsInboundYFix() : bool{
		return false;
	}

	public function handleInbound(ServerboundPacket $packet) : ?ServerboundPacket{
		$pid = $packet->pid();

		// Drop packets that don't exist in base version
		if(in_array($pid, $this->droppedInboundIds, true)){
			return null;
		}

		// Apply rewriter if one exists
		$rewriter = $this->inboundRewriters[$pid] ?? null;
		if($rewriter !== null){
			return $rewriter->rewriteInbound($packet);
		}

		// Pass-through: no translation needed
		return $packet;
	}

	public function handleOutbound(ClientboundPacket $packet) : ?ClientboundPacket{
		$pid = $packet->pid();

		// Apply rewriter if one exists
		$rewriter = $this->outboundRewriters[$pid] ?? null;
		if($rewriter !== null){
			return $rewriter->rewriteOutbound($packet);
		}

		// Pass-through: no translation needed
		return $packet;
	}
}
