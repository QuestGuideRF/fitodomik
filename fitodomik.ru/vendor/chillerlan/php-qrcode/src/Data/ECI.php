<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, ECICharset, Mode};
use function mb_convert_encoding, mb_detect_encoding, mb_internal_encoding, sprintf;
final class ECI extends QRDataModeAbstract{
	public const DATAMODE = Mode::ECI;
	private int $encoding;
	public function __construct(int $encoding){
		if($encoding < 0 || $encoding > 999999){
			throw new QRCodeDataException(sprintf('invalid encoding id: "%s"', $encoding));
		}
		$this->encoding = $encoding;
	}
	public function getLengthInBits():int{
		if($this->encoding < 128){
			return 8;
		}
		if($this->encoding < 16384){
			return 16;
		}
		return 24;
	}
	public function write(BitBuffer $bitBuffer, int $versionNumber):QRDataModeInterface{
		$bitBuffer->put(self::DATAMODE, 4);
		if($this->encoding < 128){
			$bitBuffer->put($this->encoding, 8);
		}
		elseif($this->encoding < 16384){
			$bitBuffer->put(($this->encoding | 0x8000), 16);
		}
		elseif($this->encoding < 1000000){
			$bitBuffer->put(($this->encoding | 0xC00000), 24);
		}
		else{
			throw new QRCodeDataException('invalid ECI ID');
		}
		return $this;
	}
	public static function parseValue(BitBuffer $bitBuffer):ECICharset{
		$firstByte = $bitBuffer->read(8);
		if(($firstByte & 0b10000000) === 0){
			$id = ($firstByte & 0b01111111);
		}
		elseif(($firstByte & 0b11000000) === 0b10000000){
			$id = ((($firstByte & 0b00111111) << 8) | $bitBuffer->read(8));
		}
		elseif(($firstByte & 0b11100000) === 0b11000000){
			$id = ((($firstByte & 0b00011111) << 16) | $bitBuffer->read(16));
		}
		else{
			throw new QRCodeDataException(sprintf('error decoding ECI value first byte: %08b', $firstByte));
		}
		return new ECICharset($id);
	}
	public static function validateString(string $string):bool{
		return true;
	}
	public static function decodeSegment(BitBuffer $bitBuffer, int $versionNumber):string{
		$eciCharset = self::parseValue($bitBuffer);
		$nextMode   = $bitBuffer->read(4);
		$data       = self::decodeModeSegment($nextMode, $bitBuffer, $versionNumber);
		$encoding   = $eciCharset->getName();
		if($encoding === null){
			$encoding = mb_detect_encoding($data, ['ISO-8859-1', 'Windows-1252', 'SJIS', 'UTF-8'], true);
			if($encoding === false){
				throw new QRCodeDataException('could not determine encoding in ECI mode');
			}
		}
		return mb_convert_encoding($data, mb_internal_encoding(), $encoding);
	}
	private static function decodeModeSegment(int $mode, BitBuffer $bitBuffer, int $versionNumber):string{
		switch(true){
			case $mode === Mode::NUMBER:   return Number::decodeSegment($bitBuffer, $versionNumber);
			case $mode === Mode::ALPHANUM: return AlphaNum::decodeSegment($bitBuffer, $versionNumber);
			case $mode === Mode::BYTE:     return Byte::decodeSegment($bitBuffer, $versionNumber);
		}
		throw new QRCodeDataException(sprintf('ECI designator followed by invalid mode: "%04b"', $mode));
	}
}