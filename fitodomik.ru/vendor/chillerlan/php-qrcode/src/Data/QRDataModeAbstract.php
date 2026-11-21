<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\Mode;
abstract class QRDataModeAbstract implements QRDataModeInterface{
	protected string $data;
	public function __construct(string $data){
		$data = $this::convertEncoding($data);
		if(!$this::validateString($data)){
			throw new QRCodeDataException('invalid data');
		}
		$this->data = $data;
	}
	protected function getCharCount():int{
		return strlen($this->data);
	}
	public static function convertEncoding(string $string):string{
		return $string;
	}
	protected static function getLengthBits(int $versionNumber):int{
		return Mode::getLengthBitsForVersion(static::DATAMODE, $versionNumber);
	}
}