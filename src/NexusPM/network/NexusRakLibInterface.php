<?php

declare(strict_types=1);

namespace NexusPM\network;

use NexusPM\codec\CodecRegistry;
use NexusPM\mapping\ChunkRewriter;
use NexusPM\mapping\NexusBlockTranslator;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;

/**
 * Drop-in replacement for RakLibInterface.
 *
 * The only behavioral difference: onClientConnect() creates NexusNetworkSession
 * instead of the default NetworkSession. This enables:
 *   - Compressed chunk interception via queueCompressed() override
 *   - Per-session item registry remapping via sendDataPacket() override
 *
 * All other RakLib functionality (thread management, packet routing, etc.)
 * is inherited unchanged from the parent class.
 */
class NexusRakLibInterface extends RakLibInterface{

	private CodecRegistry $codecRegistry;
	/** @var array<int, ChunkRewriter> */
	private array $chunkRewriters;
	/** @var array<int, array> */
	private array $itemTables;
	/** @var array<int, NexusBlockTranslator> */
	private array $blockTranslators;

	public function setNexusContext(
		CodecRegistry $codecRegistry,
		array $chunkRewriters,
		array $itemTables,
		array $blockTranslators = []
	) : void{
		$this->codecRegistry = $codecRegistry;
		$this->chunkRewriters = $chunkRewriters;
		$this->itemTables = $itemTables;
		$this->blockTranslators = $blockTranslators;
	}

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		$parent = new \ReflectionClass(RakLibInterface::class);

		$session = new NexusNetworkSession(
			$parent->getProperty("server")->getValue($this),
			$parent->getProperty("network")->getValue($this)->getSessionManager(),
			PacketPool::getInstance(),
			new RakLibPacketSender($sessionId, $this),
			$parent->getProperty("packetBroadcaster")->getValue($this),
			$parent->getProperty("entityEventBroadcaster")->getValue($this),
			ZlibCompressor::getInstance(),
			$parent->getProperty("typeConverter")->getValue($this),
			$address,
			$port,
			$this->codecRegistry,
			$this->chunkRewriters,
			$this->itemTables,
			$this->blockTranslators
		);

		$sessionsProp = $parent->getProperty("sessions");
		$sessions = $sessionsProp->getValue($this);
		$sessions[$sessionId] = $session;
		$sessionsProp->setValue($this, $sessions);
	}
}
