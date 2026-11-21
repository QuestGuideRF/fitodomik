<?php
namespace chillerlan\QRCode\Output;
use function implode, is_string, json_encode, max, min, sprintf;
use const JSON_THROW_ON_ERROR;
class QRString extends QROutputAbstract{
	public static function moduleValueIsValid($value):bool{
		return is_string($value);
	}
	protected function prepareModuleValue($value):string{
		return $value;
	}
	protected function getDefaultModuleValue(bool $isDark):string{
		return ($isDark) ? '██' : '░░';
	}
	public function dump(?string $file = null):string{
		switch($this->options->outputType){
			case QROutputInterface::STRING_TEXT:
				$data = $this->text();
				break;
			case QROutputInterface::STRING_JSON:
			default:
				$data = $this->json();
		}
		$this->saveToFile($data, $file);
		return $data;
	}
	protected function text():string{
		$lines     = [];
		$linestart = $this->options->textLineStart;
		for($y = 0; $y < $this->moduleCount; $y++){
			$r = [];
			for($x = 0; $x < $this->moduleCount; $x++){
				$r[] = $this->getModuleValueAt($x, $y);
			}
			$lines[] = $linestart.implode('', $r);
		}
		return implode($this->eol, $lines);
	}
	protected function json():string{
		return json_encode($this->matrix->getMatrix($this->options->jsonAsBooleans), JSON_THROW_ON_ERROR);
	}
	public static function ansi8(string $str, int $color, ?bool $background = null):string{
		$color      = max(0, min($color, 255));
		$background = ($background === true) ? 48 : 38;
		return sprintf("\x1b[%s;5;%sm%s\x1b[0m", $background, $color, $str);
	}
}