<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, Mode};
use function ceil, intdiv, preg_match, strpos;
final class AlphaNum extends QRDataModeAbstract{
	private const CHAR_MAP = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
	public const DATAMODE = Mode::ALPHANUM;
	public function getLengthInBits():int{
		return (int)ceil($this->getCharCount() * (11 / 2));
	}
	public static function validateString(string $string):bool{
		return (bool)preg_match('/^[A-Z\d %$*+\-.:\/]+$/', $string);
	}
	public function write(BitBuffer $bitBuffer, int $versionNumber):QRDataModeInterface{
		$len = $this->getCharCount();
		$bitBuffer
			->put(self::DATAMODE, 4)
			->put($len, $this::getLengthBits($versionNumber))
		;
		for($i = 0; ($i + 1) < $len; $i += 2){
			$bitBuffer->put(
				($this->ord($this->data[$i]) * 45 + $this->ord($this->data[($i + 1)])),
				11,
			);
		}
		if($i < $len){
			$bitBuffer->put($this->ord($this->data[$i]), 6);
		}
		return $this;
	}
	public static function decodeSegment(BitBuffer $bitBuffer, int $versionNumber):string{
		$length = $bitBuffer->read(self::getLengthBits($versionNumber));
		$result = '';
		while($length > 1){
			if($bitBuffer->available() < 11){
				throw new QRCodeDataException('not enough bits available');
			}
			$nextTwoCharsBits  = $bitBuffer->read(11);
			$result           .= self::chr(intdiv($nextTwoCharsBits, 45));
			$result           .= self::chr($nextTwoCharsBits % 45);
			$length           -= 2;
		}
		if($length === 1){
			if($bitBuffer->available() < 6){
				throw new QRCodeDataException('not enough bits available');
			}
			$result .= self::chr($bitBuffer->read(6));
		}
		return $result;
	}
	private function ord(string $chr):int{
		$ord = strpos(self::CHAR_MAP, $chr);
		if($ord === false){
			throw new QRCodeDataException('invalid character');
		}
		return $ord;
	}
	private static function chr(int $ord):string{
		if($ord < 0 || $ord > 44){
			throw new QRCodeDataException('invalid character code');
		}
		return self::CHAR_MAP[$ord];
	}
}