<?php

declare(strict_types=1);

namespace NexusPM\codec;

/**
 * Registry of all available version codecs.
 * Codecs are registered at plugin startup and looked up per-session.
 *
 * To add a new version:
 *   1. Create a new Codec_vXXX class extending AbstractCodec
 *   2. Register it in Main::registerCodecs()
 *   3. Update ProtocolVersions::SUPPORTED
 *
 * That's it. The rest of the pipeline is automatic.
 */
class CodecRegistry{

	/** @var array<int, VersionCodec> protocolVersion => codec */
	private array $codecs = [];

	public function register(VersionCodec $codec) : void{
		$this->codecs[$codec->getProtocolVersion()] = $codec;
	}

	public function getCodec(int $protocolVersion) : ?VersionCodec{
		return $this->codecs[$protocolVersion] ?? null;
	}

	public function hasCodec(int $protocolVersion) : bool{
		return isset($this->codecs[$protocolVersion]);
	}

	/** @return int[] */
	public function getRegisteredVersions() : array{
		return array_keys($this->codecs);
	}
}
