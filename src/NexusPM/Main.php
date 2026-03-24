<?php

declare(strict_types=1);

namespace NexusPM;

use NexusPM\codec\CodecRegistry;
use NexusPM\codec\v944\Codec_v944;
use NexusPM\codec\VersionCodec;
use NexusPM\mapping\ChunkRewriter;
use NexusPM\mapping\NexusBlockTranslator;
use NexusPM\mapping\RuntimeIdMapper;
use NexusPM\network\NexusNetworkSession;
use NexusPM\network\NexusRakLibInterface;
use NexusPM\network\PacketTranslationHelper;
use NexusPM\utils\ProtocolVersions;
use NexusPM\utils\ReflectionCache;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\query\DedicatedQueryNetworkInterface;
use pocketmine\plugin\PluginBase;

/**
 * NexusPM — Multi-version protocol support for PocketMine-MP.
 *
 * Architecture:
 *   1. Network layer replacement (Nightfall pattern):
 *      - NexusRakLibInterface replaces RakLibInterface
 *      - NexusNetworkSession replaces NetworkSession
 *      - Enables intercepting compressed chunks (queueCompressed)
 *
 *   2. Packet translation via events:
 *      - DataPacketDecodeEvent:  drop unknown inbound packet IDs
 *      - DataPacketReceiveEvent: fix inbound Y coordinates + block runtime IDs
 *      - DataPacketSendEvent:    translate outbound block runtime IDs
 *
 *   3. Per-version data:
 *      - Block palette (canonical_block_states.nbt) per protocol version
 *      - Item table (required_item_list.json) per protocol version
 *      - Block state meta map for 2-step translation
 */
class Main extends PluginBase{

	private CodecRegistry $codecRegistry;

	/** @var array<int, ChunkRewriter> protocolVersion => rewriter */
	private array $chunkRewriters = [];

	/** @var array<int, array<string, array{runtime_id: int, component_based: bool, version: int}>> */
	private array $itemTables = [];

	/** @var array<int, NexusBlockTranslator> protocolVersion => translator */
	private array $blockTranslators = [];

	/** @var array<int, array{protocol: int, codec: VersionCodec, helper: ?PacketTranslationHelper}> */
	private array $translatedSessions = [];

	protected function onEnable() : void{
		$this->codecRegistry = new CodecRegistry();
		$this->registerCodecs();
		$this->installNetworkLayer();
		$this->installEventHandlers();

		// Defer data loading to first tick — ensures plugins like Customies
		// have finished modifying the block palette before we build our mapping cache.
		// Customies re-sorts BlockStateDictionary by fnv164 hash when registering
		// custom blocks, which changes ALL vanilla runtime IDs.
		$this->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
			function() : void{
				$this->loadVersionData();
				$this->updateSessionTranslators();

				$base = ProtocolVersions::BASE_PROTOCOL;
				$baseName = ProtocolVersions::VERSION_NAMES[$base];
				$extra = $this->codecRegistry->getRegisteredVersions();
				$this->getLogger()->info("NexusPM ready — Base: $base ($baseName), Additional: " . implode(", ", $extra));
			}
		), 1);

		$this->getLogger()->info("NexusPM enabled — waiting for other plugins to finish loading...");
	}

	/**
	 * Update NexusRakLibInterface and existing sessions with fresh mapping data.
	 * Called after deferred loadVersionData() completes.
	 */
	private function updateSessionTranslators() : void{
		// Update NexusRakLibInterface contexts
		foreach($this->getServer()->getNetwork()->getInterfaces() as $iface){
			if($iface instanceof NexusRakLibInterface){
				$iface->setNexusContext($this->codecRegistry, $this->chunkRewriters, $this->itemTables, $this->blockTranslators);
			}
		}
	}

	// ─── Codec Registration ────────────────────────────────────
	// To support a new version, add its codec here and run:
	//   php tools/nexus-update.php

	private function registerCodecs() : void{
		$this->codecRegistry->register(new Codec_v944());
	}

	// ─── Version Data Loading ──────────────────────────────────

	private function loadVersionData() : void{
		$dataDir = $this->getFile() . "src/NexusPM/mapping/data/";
		$basePaletteFile = \pocketmine\data\bedrock\BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT;
		if(!file_exists($basePaletteFile)){
			$this->getLogger()->error("Base block palette not found — block translation disabled");
			return;
		}
		$basePalette = file_get_contents($basePaletteFile);

		foreach(ProtocolVersions::SUPPORTED as $protocol){
			if(ProtocolVersions::isBaseVersion($protocol)) continue;
			$gameVersion = ProtocolVersions::getVersionName($protocol);

			$this->loadBlockTranslator($protocol, $gameVersion, $dataDir, $basePalette);
			$this->loadItemTable($protocol, $gameVersion, $dataDir);
		}
	}

	private function loadBlockTranslator(int $protocol, string $gameVersion, string $dataDir, string $basePalette) : void{
		$paletteFile = $dataDir . "canonical_block_states-{$gameVersion}.nbt";
		$metaMapFile = $dataDir . "block_state_meta_map-{$gameVersion}.json";

		if(!file_exists($paletteFile)){
			$this->getLogger()->warning("v{$protocol}: missing block palette — block translation disabled");
			return;
		}

		$palette = file_get_contents($paletteFile);

		// Prefer 2-step translation (internal state → target runtime ID) via NexusBlockTranslator
		if(file_exists($metaMapFile)){
			try{
				$translator = new NexusBlockTranslator($palette, file_get_contents($metaMapFile));
				$this->blockTranslators[$protocol] = $translator;
				$this->chunkRewriters[$protocol] = ChunkRewriter::fromBlockTranslator($translator);
				$unmapped = $translator->getUnmappedBlocks();
				$this->getLogger()->info("v{$protocol}: block translator (2-step, {$translator->getChangedCount()} remapped, " . count($unmapped) . " unmapped)");
				if(count($unmapped) > 0){
					$this->getLogger()->warning("v{$protocol}: unmapped blocks: " . implode(", ", array_slice($unmapped, 0, 20)) . (count($unmapped) > 20 ? "... (" . count($unmapped) . " total)" : ""));
				}

				return;
			}catch(\Throwable $e){
				$this->getLogger()->warning("v{$protocol}: 2-step failed ({$e->getMessage()}), falling back to palette mapping");
			}
		}

		// Fallback: direct palette index comparison
		$mapper = new RuntimeIdMapper($basePalette, $palette);
		$this->chunkRewriters[$protocol] = new ChunkRewriter($mapper);
		$this->getLogger()->info("v{$protocol}: block mapper (palette-only, {$mapper->getChangedCount()} remapped)");
	}

	private function loadItemTable(int $protocol, string $gameVersion, string $dataDir) : void{
		$itemFile = $dataDir . "required_item_list-{$gameVersion}.json";
		if(!file_exists($itemFile)) return;

		$items = json_decode(file_get_contents($itemFile), true);
		if(!is_array($items)) return;

		$this->itemTables[$protocol] = $items;
		$this->getLogger()->info("v{$protocol}: item table (" . count($items) . " entries)");
	}

	// ─── Network Layer Installation ────────────────────────────
	// Replaces default RakLibInterface with NexusRakLibInterface.
	// Every new connection creates a NexusNetworkSession that can:
	//   - Intercept compressed chunk batches (queueCompressed override)
	//   - Remap ItemRegistryPacket per-session (sendDataPacket override)

	private function installNetworkLayer() : void{
		$server = $this->getServer();
		$typeConverter = TypeConverter::getInstance();
		$broadcaster = new StandardPacketBroadcaster($server);
		$entityBroadcaster = new StandardEntityEventBroadcaster($broadcaster, $typeConverter);

		$register = function(bool $ipv6) use ($server, $broadcaster, $entityBroadcaster, $typeConverter) : void{
			$ip = $ipv6 ? $server->getIpV6() : $server->getIp();
			$port = $ipv6
				? (int) $server->getConfigGroup()->getConfigInt("server-portv6", $server->getPort() + 1)
				: $server->getPort();

			$interface = new NexusRakLibInterface($server, $ip, $port, $ipv6, $broadcaster, $entityBroadcaster, $typeConverter);
			$interface->setNexusContext($this->codecRegistry, $this->chunkRewriters, $this->itemTables, $this->blockTranslators);
			$server->getNetwork()->registerInterface($interface);
			$this->getLogger()->info("Network: " . ($ipv6 ? "IPv6" : "IPv4") . " $ip:$port (NexusRakLibInterface)");
		};

		$register(false);
		if($server->getConfigGroup()->getConfigBool("enable-ipv6", true)){
			$register(true);
		}

		// Block PMMP's default interfaces (they would conflict with ours)
		$server->getPluginManager()->registerEvent(
			NetworkInterfaceRegisterEvent::class,
			function(NetworkInterfaceRegisterEvent $event) : void{
				$iface = $event->getInterface();
				if($iface instanceof NexusRakLibInterface) return;
				if($iface instanceof RakLibInterface || $iface instanceof DedicatedQueryNetworkInterface){
					$event->cancel();
				}
			},
			EventPriority::LOWEST,
			$this
		);
	}

	// ─── Event Handlers ────────────────────────────────────────

	private function installEventHandlers() : void{
		$pm = $this->getServer()->getPluginManager();
		$pm->registerEvent(DataPacketDecodeEvent::class, $this->onDecode(...), EventPriority::LOWEST, $this);
		$pm->registerEvent(DataPacketReceiveEvent::class, $this->onReceive(...), EventPriority::LOWEST, $this);
		$pm->registerEvent(DataPacketSendEvent::class, $this->onSend(...), EventPriority::LOWEST, $this);
		$pm->registerEvent(DataPacketSendEvent::class, $this->onSendLate(...), EventPriority::HIGHEST, $this);
		$pm->registerEvent(PlayerQuitEvent::class, $this->onQuit(...), EventPriority::MONITOR, $this);
	}

	/**
	 * Pre-decode: drop packet IDs that PMMP's PacketPool doesn't know.
	 * Without this, PMMP disconnects the client on unknown packets.
	 */
	public function onDecode(DataPacketDecodeEvent $event) : void{
		$info = $this->translatedSessions[spl_object_id($event->getOrigin())] ?? null;
		if($info !== null && in_array($event->getPacketId(), $info["codec"]->getDroppedInboundPacketIds(), true)){
			$event->cancel();
		}
	}

	/**
	 * Post-decode: version detection + inbound translation.
	 *
	 * Version detection:
	 *   Spoofs RequestNetworkSettingsPacket's protocolVersion via Reflection
	 *   so PMMP accepts the higher-version client as if it's the base version.
	 *
	 * Inbound translation:
	 *   Fixes Y-coordinate encoding (v944+ uses ZigZag for Y, PMMP reads unsigned)
	 *   and reverse-translates block runtime IDs (target → base).
	 */
	public function onReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		$origin = $event->getOrigin();

		if($packet instanceof RequestNetworkSettingsPacket){
			$this->handleVersionDetection($origin, $packet, $event);
			return;
		}

		$info = $this->translatedSessions[spl_object_id($origin)] ?? null;
		if($info === null) return;

		$info["helper"]?->translateInbound($packet);

		$result = $info["codec"]->handleInbound($packet);
		if($result === null) $event->cancel();
	}

	/**
	 * Outbound: translate block runtime IDs in broadcast packets.
	 *
	 * Required because StandardPacketBroadcaster encodes packets once
	 * and sends to all sessions, bypassing sendDataPacket().
	 * UpdateBlockPacket, LevelSoundEventPacket, LevelEventPacket,
	 * and UpdateSubChunkBlocksPacket all need block ID translation.
	 */
	private bool $isResending = false;

	public function onSend(DataPacketSendEvent $event) : void{
		if($this->isResending) return;

		$targets = $event->getTargets();
		$packets = $event->getPackets();

		// Categorize targets
		$baseTargets = [];
		/** @var array<int, array{session: \pocketmine\network\mcpe\NetworkSession, helper: PacketTranslationHelper}> */
		$translatedTargets = [];

		foreach($targets as $t){
			$info = $this->translatedSessions[spl_object_id($t)] ?? null;
			if($info !== null && $info["helper"] !== null){
				$translatedTargets[] = ["session" => $t, "helper" => $info["helper"]];
			}else{
				$baseTargets[] = $t;
			}
		}

		if(count($translatedTargets) === 0) return;

		// All-translated (no base clients): use setPackets() for efficiency
		if(count($baseTargets) === 0){
			// Check all share the same protocol (common case)
			$helper = $translatedTargets[0]["helper"];
			$translated = [];
			$changed = false;
			foreach($packets as $pkt){
				$result = $helper->translateOutbound($pkt);
				if($result !== $pkt) $changed = true;
				$translated[] = $result;
			}
			if($changed) $event->setPackets($translated);
			return;
		}

		// Mixed (base + translated clients): cancel and re-send per-group
		$event->cancel();
		$this->isResending = true;
		try{
			// Send original packets to base clients
			foreach($baseTargets as $target){
				foreach($packets as $pkt){
					$target->sendDataPacket($pkt);
				}
			}
			// Send translated packets to each translated client
			foreach($translatedTargets as $entry){
				$helper = $entry["helper"];
				$session = $entry["session"];
				foreach($packets as $pkt){
					$translated = $helper->translateOutbound($pkt);
					$session->sendDataPacket($translated);
				}
			}
		}finally{
			$this->isResending = false;
		}
	}

	/**
	 * Late-stage StartGamePacket handler.
	 * Customies sets blockPalette to custom block definitions only (not vanilla blocks).
	 * The client merges these with its internal vanilla blocks and sorts by fnv164.
	 * This matches our target dictionary's fnv164 sort order — no modification needed.
	 */
	public function onSendLate(DataPacketSendEvent $event) : void{
		// Customies' custom block entries pass through unchanged.
		// Fix StartGamePacket's spawnPosition Y for v944
		foreach($event->getPackets() as $packet){
			if(!($packet instanceof StartGamePacket)) continue;
			foreach($event->getTargets() as $target){
				$info = $this->translatedSessions[spl_object_id($target)] ?? null;
				if($info === null) continue;
				$codec = $info["codec"];
				if($codec !== null && $codec->needsInboundYFix()){
					$sp = $packet->levelSettings->spawnPosition;
					$y = $sp->getY();
					$packet->levelSettings->spawnPosition = new \pocketmine\network\mcpe\protocol\types\BlockPosition(
						$sp->getX(), ($y << 1) ^ ($y >> 31), $sp->getZ()
					);
				}
			}
		}
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		unset($this->translatedSessions[spl_object_id($event->getPlayer()->getNetworkSession())]);
	}

	// ─── Internal ──────────────────────────────────────────────

	private function handleVersionDetection(
		\pocketmine\network\mcpe\NetworkSession $origin,
		RequestNetworkSettingsPacket $packet,
		DataPacketReceiveEvent $event
	) : void{
		$protocol = $packet->getProtocolVersion();

		if(ProtocolVersions::isBaseVersion($protocol) || !ProtocolVersions::isSupported($protocol)){
			return; // Let PMMP handle (accept or reject)
		}

		$codec = $this->codecRegistry->getCodec($protocol);
		if($codec === null) return;

		// Activate translation on the custom session
		$helper = null;
		if($origin instanceof NexusNetworkSession){
			$origin->activateTranslation($protocol);
			$helper = $origin->getTranslationHelper();
		}

		$this->translatedSessions[spl_object_id($origin)] = [
			"protocol" => $protocol,
			"codec" => $codec,
			"helper" => $helper,
		];

		// Spoof protocol version so PMMP accepts the connection
		ReflectionCache::setValue($packet, "protocolVersion", ProtocolInfo::CURRENT_PROTOCOL);

		$this->getLogger()->info("[NexusPM] Client v{$protocol} ({$codec->getGameVersion()}) accepted");
	}

	// ─── Public API ────────────────────────────────────────────

	public function getCodecRegistry() : CodecRegistry{
		return $this->codecRegistry;
	}
}
