<?php

declare(strict_types=1);

namespace NexusPM\utils;

/**
 * Cached Reflection property accessor.
 * Eliminates repeated ReflectionProperty construction overhead.
 */
final class ReflectionCache{

	/** @var array<string, \ReflectionProperty> */
	private static array $properties = [];

	private function __construct(){}

	public static function getProperty(string $class, string $property) : \ReflectionProperty{
		$key = "$class::$property";
		return self::$properties[$key] ??= new \ReflectionProperty($class, $property);
	}

	public static function getValue(object $obj, string $property) : mixed{
		return self::getProperty(get_class($obj), $property)->getValue($obj);
	}

	public static function setValue(object $obj, string $property, mixed $value) : void{
		self::getProperty(get_class($obj), $property)->setValue($obj, $value);
	}

	/**
	 * Get a property from a specific parent class (when property is defined in parent).
	 */
	public static function getParentValue(object $obj, string $parentClass, string $property) : mixed{
		return self::getProperty($parentClass, $property)->getValue($obj);
	}

	public static function setParentValue(object $obj, string $parentClass, string $property, mixed $value) : void{
		self::getProperty($parentClass, $property)->setValue($obj, $value);
	}
}
