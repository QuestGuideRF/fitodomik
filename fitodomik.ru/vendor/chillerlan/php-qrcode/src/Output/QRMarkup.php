<?php
namespace chillerlan\QRCode\Output;
use function is_string, preg_match, strip_tags, trim;
abstract class QRMarkup extends QROutputAbstract{
	public static function moduleValueIsValid($value):bool{
		if(!is_string($value)){
			return false;
		}
		$value = trim(strip_tags($value), " '\"\r\n\t");
		if(preg_match('/^#([\da-f]{3}){1,2}$|^#([\da-f]{4}){1,2}$/i', $value)){
			return true;
		}
		if(preg_match('#^(hsla?|rgba?)\([\d .,%/]+\)$#i', $value)){
			return true;
		}
		if(preg_match('/^[a-z]+$/i', $value)){
			return true;
		}
		return false;
	}
	protected function prepareModuleValue($value):string{
		return trim(strip_tags($value), " '\"\r\n\t");
	}
	protected function getDefaultModuleValue(bool $isDark):string{
		return ($isDark) ? '#000' : '#fff';
	}
	public function dump(?string $file = null):string{
		$data = $this->createMarkup($file !== null);
		$this->saveToFile($data, $file);
		return $data;
	}
	protected function getCssClass(int $M_TYPE = 0):string{
		return $this->options->cssClass;
	}
	abstract protected function createMarkup(bool $saveToFile):string;
}