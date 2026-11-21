<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, Mode};
use function chr, ord;
final class Byte extends QRDataModeAbstract{
	public const DATAMODE = Mode::BYTE;
	public function getLengthInBits():int{
		return ($this->getCharCount() * 8);
	}
	public static function validateString(string $string):bool{
		return $string !== '';
	}
	public function write(BitBuffer $bitBuffer, int $versionNumber):QRDataModeInterface{
		$len = $this->getCharCount();
		$bitBuffer
			->put(self::DATAMODE, 4)
			->put($len, $this::getLengthBits($versionNumber))
		;
		$i = 0;
		while($i < $len){
			$bitBuffer->put(ord($this->data[$i]), 8);
			$i++;
		}
		return $this;
	}
	public static function decodeSegment(BitBuffer $bitBuffer, int $versionNumber):string{
		$length = $bitBuffer->read(self::getLengthBits($versionNumber));
		if($bitBuffer->available() < (8 * $length)){
			throw new QRCodeDataException('not enough bits available');
		}
		$readBytes = '';
		for($i = 0; $i < $length; $i++){
			$readBytes .= chr($bitBuffer->read(8));
		}
		return $readBytes;
	}
}