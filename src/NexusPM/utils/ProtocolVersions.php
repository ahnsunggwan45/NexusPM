<?php

declare(strict_types=1);

namespace NexusPM\utils;

final class ProtocolVersions{

	public const BASE_PROTOCOL = 924;

	public const SUPPORTED = [
		924,
		944,
	];

	public const VERSION_NAMES = [
		924 => "1.26.0",
		944 => "1.26.10",
	];

	private function __construct(){}

	public static function isSupported(int $protocol) : bool{
		return in_array($protocol, self::SUPPORTED, true);
	}

	public static function isBaseVersion(int $protocol) : bool{
		return $protocol === self::BASE_PROTOCOL;
	}

	public static function getVersionName(int $protocol) : ?string{
		return self::VERSION_NAMES[$protocol] ?? null;
	}

	/**
	 * Returns the next supported protocol version above the given one, or null.
	 * Useful for checking version ordering.
	 */
	public static function getNextVersion(int $protocol) : ?int{
		$found = false;
		foreach(self::SUPPORTED as $v){
			if($found){
				return $v;
			}
			if($v === $protocol){
				$found = true;
			}
		}
		return null;
	}
}
