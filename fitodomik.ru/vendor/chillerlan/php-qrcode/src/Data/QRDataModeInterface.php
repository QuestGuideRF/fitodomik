<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\BitBuffer;
interface QRDataModeInterface{
	public const DATAMODE = -1;
	public function getLengthInBits():int;
	public static function convertEncoding(string $string):string;
	public static function validateString(string $string):bool;
	public function write(BitBuffer $bitBuffer, int $versionNumber):QRDataModeInterface;
	public static function decodeSegment(BitBuffer $bitBuffer, int $versionNumber):string;
}