<?php

declare(strict_types=1);

namespace NexusPM\network;

use NexusPM\codec\CodecRegistry;
use NexusPM\mapping\NexusBlockTranslator;
use NexusPM\utils\ReflectionCache;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;

/**
 * Drop-in replacement for RakLibInterface.
 * Creates NexusNetworkSession instead of NetworkSession on client connect.
 */
class NexusRakLibInterface extends RakLibInterface{

	private CodecRegistry $codecRegistry;
	private array $chunkRewriters;
	private array $itemTables;
	private array $blockTranslators;

	public function setNexusContext(CodecRegistry $codecRegistry, array $chunkRewriters, array $itemTables, array $blockTranslators = []) : void{
		$this->codecRegistry = $codecRegistry;
		$this->chunkRewriters = $chunkRewriters;
		$this->itemTables = $itemTables;
		$this->blockTranslators = $blockTranslators;
	}

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		$session = new NexusNetworkSession(
			ReflectionCache::getParentValue($this, RakLibInterface::class, "server"),
			ReflectionCache::getParentValue($this, RakLibInterface::class, "network")->getSessionManager(),
			PacketPool::getInstance(),
			new RakLibPacketSender($sessionId, $this),
			ReflectionCache::getParentValue($this, RakLibInterface::class, "packetBroadcaster"),
			ReflectionCache::getParentValue($this, RakLibInterface::class, "entityEventBroadcaster"),
			ZlibCompressor::getInstance(),
			ReflectionCache::getParentValue($this, RakLibInterface::class, "typeConverter"),
			$address,
			$port,
			$this->codecRegistry,
			$this->chunkRewriters,
			$this->itemTables,
			$this->blockTranslators
		);

		$sessions = ReflectionCache::getParentValue($this, RakLibInterface::class, "sessions");
		$sessions[$sessionId] = $session;
		ReflectionCache::setParentValue($this, RakLibInterface::class, "sessions", $sessions);
	}
}
