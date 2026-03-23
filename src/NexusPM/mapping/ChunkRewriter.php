<?php

declare(strict_types=1);

namespace NexusPM\mapping;

/**
 * Rewrites LevelChunkPacket's extraPayload to translate block runtime IDs.
 *
 * SubChunk format (network, non-persistent):
 *   [version: u8] [layerCount: u8]
 *   per layer:
 *     [header: u8] = (bitsPerBlock << 1) | 1
 *     if bitsPerBlock > 0:
 *       [wordArray: getWordCount(bitsPerBlock) * 4 bytes]
 *       [paletteSize: zigzag varint]
 *       per palette entry: [networkRuntimeId: zigzag varint]  ← translated
 *     else (bitsPerBlock == 0):
 *       [singleRuntimeId: zigzag varint]  ← translated
 *
 * Word count calculation (Bedrock format):
 *   blocksPerWord = floor(32 / bitsPerBlock)
 *   wordCount = ceil(4096 / blocksPerWord)
 */
class ChunkRewriter{

	/**
	 * Pre-computed word counts for each bitsPerBlock value.
	 * Bedrock uses: wordCount = ceil(4096 / floor(32 / bitsPerBlock))
	 */
	private const WORD_COUNTS = [
		// bitsPerBlock => wordCount
		1 => 128,    // floor(32/1)=32, ceil(4096/32)=128
		2 => 256,    // floor(32/2)=16, ceil(4096/16)=256
		3 => 410,    // floor(32/3)=10, ceil(4096/10)=410
		4 => 512,    // floor(32/4)=8,  ceil(4096/8)=512
		5 => 683,    // floor(32/5)=6,  ceil(4096/6)=683
		6 => 820,    // floor(32/6)=5,  ceil(4096/5)=820
		8 => 1024,   // floor(32/8)=4,  ceil(4096/4)=1024
		16 => 2048,  // floor(32/16)=2, ceil(4096/2)=2048
	];

	private ?NexusBlockTranslator $blockTranslator = null;

	public function __construct(
		private RuntimeIdMapper $mapper
	){}

	/**
	 * Create from NexusBlockTranslator (preferred — proper 2-step translation).
	 */
	public static function fromBlockTranslator(NexusBlockTranslator $translator) : self{
		// Create a dummy RuntimeIdMapper that delegates to NexusBlockTranslator
		$instance = new self(new RuntimeIdMapperDelegate($translator));
		$instance->blockTranslator = $translator;
		return $instance;
	}

	public function getMapper() : RuntimeIdMapper{
		return $this->mapper;
	}

	public function getBlockTranslator() : ?NexusBlockTranslator{
		return $this->blockTranslator;
	}

	public function rewriteChunkPayload(string $payload, int $subChunkCount) : string{
		$offset = 0;
		$len = strlen($payload);
		$output = "";

		for($s = 0; $s < $subChunkCount && $offset < $len; $s++){
			$output .= $this->rewriteSubChunk($payload, $offset, $len);
		}

		// Append remaining data (biomes, border blocks, tile entities)
		if($offset < $len){
			$output .= substr($payload, $offset);
		}

		return $output;
	}

	private function rewriteSubChunk(string $payload, int &$offset, int $len) : string{
		if($offset >= $len) return "";

		$version = ord($payload[$offset++]);
		$layerCount = ord($payload[$offset++]);
		$result = chr($version) . chr($layerCount);

		for($layer = 0; $layer < $layerCount && $offset < $len; $layer++){
			$result .= $this->rewriteBlockLayer($payload, $offset, $len);
		}

		return $result;
	}

	private function rewriteBlockLayer(string $payload, int &$offset, int $len) : string{
		if($offset >= $len) return "";

		$header = ord($payload[$offset++]);
		$bitsPerBlock = $header >> 1;
		$result = chr($header);

		if($bitsPerBlock === 0){
			// Single block: 1 palette entry, no word array
			$runtimeId = self::readZigZagVarInt($payload, $offset);
			$result .= self::writeZigZagVarInt($this->mapper->toTarget($runtimeId));
			return $result;
		}

		// Word array size: Bedrock format
		$wordCount = self::WORD_COUNTS[$bitsPerBlock] ?? (int) ceil(4096 / (int) floor(32 / $bitsPerBlock));
		$wordBytes = $wordCount * 4;

		if($offset + $wordBytes > $len){
			// Not enough data — copy remainder and bail
			$result .= substr($payload, $offset);
			$offset = $len;
			return $result;
		}

		// Copy word array unchanged
		$result .= substr($payload, $offset, $wordBytes);
		$offset += $wordBytes;

		// Palette size (zigzag varint)
		$paletteSize = self::readZigZagVarInt($payload, $offset);
		$result .= self::writeZigZagVarInt($paletteSize);

		// Translate palette entries
		for($i = 0; $i < $paletteSize && $offset < $len; $i++){
			$runtimeId = self::readZigZagVarInt($payload, $offset);
			$result .= self::writeZigZagVarInt($this->mapper->toTarget($runtimeId));
		}

		return $result;
	}

	private static function readZigZagVarInt(string $buffer, int &$offset) : int{
		$raw = 0;
		$shift = 0;
		$len = strlen($buffer);
		do{
			if($offset >= $len){
				throw new \RuntimeException("VarInt read overflow at offset $offset (buffer len=$len)");
			}
			$byte = ord($buffer[$offset++]);
			$raw |= ($byte & 0x7F) << $shift;
			$shift += 7;
		}while(($byte & 0x80) !== 0 && $shift < 35);

		return ($raw >> 1) ^ -($raw & 1);
	}

	private static function writeZigZagVarInt(int $value) : string{
		$raw = ($value << 1) ^ ($value >> 31);
		$buf = "";
		$raw &= 0xFFFFFFFF;
		for($i = 0; $i < 5; $i++){
			$byte = $raw & 0x7F;
			$raw >>= 7;
			if($raw !== 0) $byte |= 0x80;
			$buf .= chr($byte);
			if($raw === 0) break;
		}
		return $buf;
	}
}
