<?php
namespace chillerlan\QRCode\Common;
use chillerlan\QRCode\Data\{AlphaNum, Byte, Hanzi, Kanji, Number};
use chillerlan\QRCode\QRCodeException;
final class Mode{
	public const TERMINATOR       = 0b0000;
	public const NUMBER           = 0b0001;
	public const ALPHANUM         = 0b0010;
	public const BYTE             = 0b0100;
	public const KANJI            = 0b1000;
	public const HANZI            = 0b1101;
	public const STRCTURED_APPEND = 0b0011;
	public const FNC1_FIRST       = 0b0101;
	public const FNC1_SECOND      = 0b1001;
	public const ECI              = 0b0111;
	public const LENGTH_BITS = [
		self::NUMBER   => [10, 12, 14],
		self::ALPHANUM => [ 9, 11, 13],
		self::BYTE     => [ 8, 16, 16],
		self::KANJI    => [ 8, 10, 12],
		self::HANZI    => [ 8, 10, 12],
		self::ECI      => [ 0,  0,  0],
	];
	public const INTERFACES = [
		self::NUMBER   => Number::class,
		self::ALPHANUM => AlphaNum::class,
		self::KANJI    => Kanji::class,
		self::HANZI    => Hanzi::class,
		self::BYTE     => Byte::class,
	];
	public static function getLengthBitsForVersion(int $mode, int $version):int{
		if(!isset(self::LENGTH_BITS[$mode])){
			throw new QRCodeException('invalid mode given');
		}
		$minVersion = 0;
		foreach([9, 26, 40] as $key => $breakpoint){
			if($version > $minVersion && $version <= $breakpoint){
				return self::LENGTH_BITS[$mode][$key];
			}
			$minVersion = $breakpoint;
		}
		throw new QRCodeException(sprintf('invalid version number: %d', $version));
	}
}