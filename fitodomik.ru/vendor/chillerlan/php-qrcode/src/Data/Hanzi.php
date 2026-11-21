<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, Mode};
use Throwable;
use function chr, implode, intdiv, is_string, mb_convert_encoding, mb_detect_encoding,
	mb_internal_encoding, mb_strlen, ord, sprintf, strlen;
final class Hanzi extends QRDataModeAbstract{
	public const ENCODING = 'GB18030';
	public const GB2312_SUBSET = 0b0001;
	public const DATAMODE = Mode::HANZI;
	protected function getCharCount():int{
		return mb_strlen($this->data, self::ENCODING);
	}
	public function getLengthInBits():int{
		return ($this->getCharCount() * 13);
	}
	public static function convertEncoding(string $string):string{
		$detected = mb_detect_encoding(
			$string,
			[mb_internal_encoding(), 'UTF-8', 'GB2312', 'GB18030', 'CP936', 'EUC-CN', 'HZ'],
			true,
		);
		if($detected === false){
			throw new QRCodeDataException('mb_detect_encoding error');
		}
		if($detected === self::ENCODING){
			return $string;
		}
		$string = mb_convert_encoding($string, self::ENCODING, $detected);
		if(!is_string($string)){
			throw new QRCodeDataException('mb_convert_encoding error');
		}
		return $string;
	}
	public static function validateString(string $string):bool{
		try{
			$string = self::convertEncoding($string);
		}
		catch(Throwable $e){
			return false;
		}
		$len = strlen($string);
		if($len < 2 || ($len % 2) !== 0){
			return false;
		}
		for($i = 0; $i < $len; $i += 2){
			$byte1 = ord($string[$i]);
			$byte2 = ord($string[($i + 1)]);
			if($byte1 < 0xa1 || ($byte1 > 0xa9 && $byte1 < 0xb0) || $byte1 > 0xf7){
				return false;
			}
			if($byte2 < 0xa1 || $byte2 > 0xfe){
				return false;
			}
		}
		return true;
	}
	public function write(BitBuffer $bitBuffer, int $versionNumber):QRDataModeInterface{
		$bitBuffer
			->put(self::DATAMODE, 4)
			->put($this::GB2312_SUBSET, 4)
			->put($this->getCharCount(), $this::getLengthBits($versionNumber))
		;
		$len = strlen($this->data);
		for($i = 0; ($i + 1) < $len; $i += 2){
			$c = (((0xff & ord($this->data[$i])) << 8) | (0xff & ord($this->data[($i + 1)])));
			if($c >= 0xa1a1 && $c <= 0xaafe){
				$c -= 0x0a1a1;
			}
			elseif($c >= 0xb0a1 && $c <= 0xfafe){
				$c -= 0x0a6a1;
			}
			else{
				throw new QRCodeDataException(sprintf('illegal char at %d [%d]', ($i + 1), $c));
			}
			$bitBuffer->put((((($c >> 8) & 0xff) * 0x060) + ($c & 0xff)), 13);
		}
		if($i < $len){
			throw new QRCodeDataException(sprintf('illegal char at %d', ($i + 1)));
		}
		return $this;
	}
	public static function decodeSegment(BitBuffer $bitBuffer, int $versionNumber):string{
		if($bitBuffer->read(4) !== self::GB2312_SUBSET){
			throw new QRCodeDataException('ecpected subset indicator for Hanzi mode');
		}
		$length = $bitBuffer->read(self::getLengthBits($versionNumber));
		if($bitBuffer->available() < ($length * 13)){
			throw new QRCodeDataException('not enough bits available');
		}
		$buffer = [];
		$offset = 0;
		while($length > 0){
			$twoBytes          = $bitBuffer->read(13);
			$assembledTwoBytes = ((intdiv($twoBytes, 0x060) << 8) | ($twoBytes % 0x060));
			$assembledTwoBytes += ($assembledTwoBytes < 0x00a00)
				? 0x0a1a1
				: 0x0a6a1;
			$buffer[$offset]       = chr(0xff & ($assembledTwoBytes >> 8));
			$buffer[($offset + 1)] = chr(0xff & $assembledTwoBytes);
			$offset                += 2;
			$length--;
		}
		return mb_convert_encoding(implode('', $buffer), mb_internal_encoding(), self::ENCODING);
	}
}