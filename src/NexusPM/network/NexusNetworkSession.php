<?php

declare(strict_types=1);

namespace NexusPM\network;

use NexusPM\codec\CodecRegistry;
use NexusPM\codec\VersionCodec;
use NexusPM\mapping\ChunkRewriter;
use NexusPM\mapping\NexusBlockTranslator;
use NexusPM\mapping\RuntimeIdMapper;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\network\NetworkSessionManager;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Server;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

/**
 * Extended NetworkSession with multi-version translation capabilities.
 *
 * Overrides two methods:
 *
 * 1. queueCompressed():
 *    Intercepts compressed chunk batches that bypass DataPacketSendEvent.
 *    Decompresses → rewrites block runtime IDs in LevelChunkPacket → recompresses.
 *
 * 2. sendDataPacket():
 *    Intercepts ItemRegistryPacket to remap item runtime IDs per-session,
 *    preserving componentNbt from plugins (e.g., CustomItemLoader).
 *
 * Block ID translation for non-chunk packets (UpdateBlockPacket, LevelSoundEventPacket,
 * LevelEventPacket, etc.) is handled via DataPacketSendEvent in Main.php
 * to avoid double-translation.
 */
class NexusNetworkSession extends NetworkSession{

	private ?VersionCodec $codec = null;
	private ?ChunkRewriter $chunkRewriter = null;
	private ?PacketTranslationHelper $translationHelper = null;
	private int $clientProtocol = 0;
	private bool $translationActive = false;

	/** @var array<string, string> md5(compressed) => rewritten compressed — chunk rewrite cache */
	private array $chunkCache = [];
	private const CHUNK_CACHE_MAX = 256;

	private CodecRegistry $codecRegistry;
	/** @var array<int, ChunkRewriter> */
	private array $chunkRewriters;
	/** @var array<int, array> */
	private array $itemTables;
	/** @var array<int, NexusBlockTranslator> */
	private array $blockTranslators;

	public function __construct(
		Server $server,
		NetworkSessionManager $manager,
		PacketPool $packetPool,
		PacketSender $sender,
		PacketBroadcaster $broadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		Compressor $compressor,
		TypeConverter $typeConverter,
		string $ip,
		int $port,
		CodecRegistry $codecRegistry,
		array $chunkRewriters,
		array $itemTables,
		array $blockTranslators = []
	){
		parent::__construct($server, $manager, $packetPool, $sender, $broadcaster, $entityEventBroadcaster, $compressor, $typeConverter, $ip, $port);
		$this->codecRegistry = $codecRegistry;
		$this->chunkRewriters = $chunkRewriters;
		$this->itemTables = $itemTables;
		$this->blockTranslators = $blockTranslators;
	}

	public function getClientProtocol() : int{ return $this->clientProtocol; }
	public function getCodec() : ?VersionCodec{ return $this->codec; }
	public function isTranslationActive() : bool{ return $this->translationActive; }
	public function getTranslationHelper() : ?PacketTranslationHelper{ return $this->translationHelper; }

	/**
	 * Activate multi-version translation for this session.
	 * Called from Main when the client's protocol version is detected.
	 */
	public function activateTranslation(int $protocol) : void{
		$this->clientProtocol = $protocol;
		$this->codec = $this->codecRegistry->getCodec($protocol);
		$this->chunkRewriter = $this->chunkRewriters[$protocol] ?? null;

		$mapper = $this->chunkRewriter?->getMapper();
		$blockTranslator = $this->blockTranslators[$protocol] ?? null;
		if($mapper !== null){
			$needsYFix = $this->codec?->needsInboundYFix() ?? false;
			$this->translationHelper = new PacketTranslationHelper($mapper, $blockTranslator, $needsYFix);
		}

		$this->translationActive = ($this->codec !== null);
	}

	// ─── Chunk Interception ────────────────────────────────────

	public function queueCompressed(CompressBatchPromise|string $payload, bool $immediate = false) : void{
		if($this->translationActive && $this->chunkRewriter !== null && is_string($payload)){
			// Check cache first (same chunk data sent to multiple sessions)
			$hash = md5($payload);
			if(isset($this->chunkCache[$hash])){
				parent::queueCompressed($this->chunkCache[$hash], $immediate);
				return;
			}

			$rewritten = $this->rewriteCompressedBatch($payload);
			if($rewritten !== null){
				// Cache the result
				if(count($this->chunkCache) >= self::CHUNK_CACHE_MAX){
					array_shift($this->chunkCache); // evict oldest
				}
				$this->chunkCache[$hash] = $rewritten;
				parent::queueCompressed($rewritten, $immediate);
				return;
			}
		}
		parent::queueCompressed($payload, $immediate);
	}

	// ─── Item Registry Interception ────────────────────────────

	public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
		if($this->translationActive && $packet instanceof ItemRegistryPacket && isset($this->itemTables[$this->clientProtocol])){
			$packet = $this->remapItemRegistry($packet);
		}
		return parent::sendDataPacket($packet, $immediate);
	}

	/**
	 * Remap ItemRegistryPacket: target version's runtime IDs,
	 * preserving original componentNbt from plugins.
	 */
	private function remapItemRegistry(ItemRegistryPacket $packet) : ItemRegistryPacket{
		$targetItems = $this->itemTables[$this->clientProtocol];
		$entries = [];

		foreach($packet->getEntries() as $entry){
			$name = $entry->getStringId();
			if(isset($targetItems[$name])){
				$entries[] = new ItemTypeEntry(
					$name,
					$targetItems[$name]["runtime_id"],
					$entry->isComponentBased(),
					$entry->getVersion(),
					$entry->getComponentNbt()
				);
			}else{
				$entries[] = $entry;
			}
		}

		// Add items that exist only in the target version
		$known = [];
		foreach($entries as $e) $known[$e->getStringId()] = true;
		$emptyNbt = new CacheableNbt(new CompoundTag());
		foreach($targetItems as $name => $data){
			if(!isset($known[$name])){
				$entries[] = new ItemTypeEntry($name, $data["runtime_id"], $data["component_based"], $data["version"] ?? 0, $emptyNbt);
			}
		}

		return ItemRegistryPacket::create($entries);
	}

	// ─── Compressed Batch Rewriting ────────────────────────────

	private function rewriteCompressedBatch(string $compressed) : ?string{
		try{
			if(strlen($compressed) < 2) return null;

			$type = ord($compressed[0]);
			$data = substr($compressed, 1);

			$decompressed = match($type){
				CompressionAlgorithm::ZLIB => @zlib_decode($data),
				CompressionAlgorithm::NONE => $data,
				default => false,
			};
			if($decompressed === false) return null;

			$stream = new ByteBufferReader($decompressed);
			$buffers = [];
			$changed = false;

			foreach(PacketBatch::decodeRaw($stream) as $buf){
				$rewritten = $this->rewriteChunkPacketBuffer($buf);
				if($rewritten !== $buf) $changed = true;
				$buffers[] = $rewritten;
			}

			if(!$changed) return null;

			$out = new ByteBufferWriter();
			PacketBatch::encodeRaw($out, $buffers);

			$recompressed = match($type){
				CompressionAlgorithm::ZLIB => zlib_encode($out->getData(), ZLIB_ENCODING_RAW, 6),
				default => $out->getData(),
			};

			return chr($type) . $recompressed;
		}catch(\Throwable){
			return null;
		}
	}

	private function rewriteChunkPacketBuffer(string $buffer) : string{
		if(strlen($buffer) < 1) return $buffer;

		$off = 0;
		$header = self::readUVarInt($buffer, $off);
		if(($header & 0x3FF) !== ProtocolInfo::LEVEL_CHUNK_PACKET) return $buffer;

		try{
			self::skipZigZag($buffer, $off); // chunkX
			self::skipZigZag($buffer, $off); // chunkZ
			self::skipZigZag($buffer, $off); // dimensionId

			$subChunkCount = self::readUVarInt($buffer, $off);
			if($subChunkCount >= 0xFFFFFFFE) return $buffer; // client-request mode

			$cacheEnabled = ord($buffer[$off++]) !== 0;
			if($cacheEnabled){
				$off += self::readUVarInt($buffer, $off) * 8;
			}

			$lenStart = $off;
			$payloadLen = self::readUVarInt($buffer, $off);
			$dataStart = $off;

			$payload = substr($buffer, $dataStart, $payloadLen);
			$rewritten = $this->chunkRewriter->rewriteChunkPayload($payload, $subChunkCount);
			if($rewritten === $payload) return $buffer;

			return substr($buffer, 0, $lenStart)
				. self::writeUVarInt(strlen($rewritten))
				. $rewritten
				. substr($buffer, $dataStart + $payloadLen);
		}catch(\Throwable){
			return $buffer;
		}
	}

	// ─── VarInt Utilities ──────────────────────────────────────

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

	private static function writeUVarInt(int $v) : string{
		$buf = "";
		$v &= 0xFFFFFFFF;
		for($i = 0; $i < 5; $i++){
			$b = $v & 0x7F;
			$v >>= 7;
			if($v !== 0) $b |= 0x80;
			$buf .= chr($b);
			if($v === 0) break;
		}
		return $buf;
	}

	private static function skipZigZag(string $buf, int &$off) : void{
		do{ $b = ord($buf[$off++]); }while(($b & 0x80) !== 0);
	}
}
