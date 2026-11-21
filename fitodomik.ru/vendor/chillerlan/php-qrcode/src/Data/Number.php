<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, Mode};
use function ceil, intdiv, substr, unpack;
final class Number extends QRDataModeAbstract{
	public const DATAMODE = Mode::NUMBER;
	public function getLengthInBits():int{
		return (int)ceil($this->getCharCount() * (10 / 3));
	}
	public static function validateString(string $string):bool{
		return (bool)preg_match('/^\d+$/', $string);
	}
	public function write(BitBuffer $bitBuffer, int $versionNumber):QRDataModeInterface{
		$len = $this->getCharCount();
		$bitBuffer
			->put(self::DATAMODE, 4)
			->put($len, $this::getLengthBits($versionNumber))
		;
		$i = 0;
		while(($i + 2) < $len){
			$bitBuffer->put($this->parseInt(substr($this->data, $i, 3)), 10);
			$i += 3;
		}
		if($i < $len){
			if(($len - $i) === 2){
				$bitBuffer->put($this->parseInt(substr($this->data, $i, 2)), 7);
			}
			elseif(($len - $i) === 1){
				$bitBuffer->put($this->parseInt(substr($this->data, $i, 1)), 4);
			}
		}
		return $this;
	}
	private function parseInt(string $string):int{
		$num = 0;
		$ords = unpack('C*', $string);
		if($ords === false){
			throw new QRCodeDataException('unpack() error');
		}
		foreach($ords as $ord){
			$num = ($num * 10 + $ord - 48);
		}
		return $num;
	}
	public static function decodeSegment(BitBuffer $bitBuffer, int $versionNumber):string{
		$length = $bitBuffer->read(self::getLengthBits($versionNumber));
		$result = '';
		while($length >= 3){
			if($bitBuffer->available() < 10){
				throw new QRCodeDataException('not enough bits available');
			}
			$threeDigitsBits = $bitBuffer->read(10);
			if($threeDigitsBits >= 1000){
				throw new QRCodeDataException('error decoding numeric value');
			}
			$result .= intdiv($threeDigitsBits, 100);
			$result .= (intdiv($threeDigitsBits, 10) % 10);
			$result .= ($threeDigitsBits % 10);
			$length -= 3;
		}
		if($length === 2){
			if($bitBuffer->available() < 7){
				throw new QRCodeDataException('not enough bits available');
			}
			$twoDigitsBits = $bitBuffer->read(7);
			if($twoDigitsBits >= 100){
				throw new QRCodeDataException('error decoding numeric value');
			}
			$result .= intdiv($twoDigitsBits, 10);
			$result .= ($twoDigitsBits % 10);
		}
		elseif($length === 1){
			if($bitBuffer->available() < 4){
				throw new QRCodeDataException('not enough bits available');
			}
			$digitBits = $bitBuffer->read(4);
			if($digitBits >= 10){
				throw new QRCodeDataException('error decoding numeric value');
			}
			$result .= $digitBits;
		}
		return $result;
	}
}