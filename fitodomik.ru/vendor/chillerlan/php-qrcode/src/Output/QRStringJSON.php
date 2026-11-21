<?php
namespace chillerlan\QRCode\Output;
use function json_encode;
class QRStringJSON extends QROutputAbstract{
	public const MIME_TYPE = 'application/json';
	public function dump(?string $file = null):string{
		$matrix = $this->matrix->getMatrix($this->options->jsonAsBooleans);
		$data   = json_encode($matrix, $this->options->jsonFlags);
		$this->saveToFile($data, $file);
		return $data;
	}
	protected function prepareModuleValue($value):string{
		return '';
	}
	protected function getDefaultModuleValue(bool $isDark):string{
		return '';
	}
	public static function moduleValueIsValid($value):bool{
		return true;
	}
}