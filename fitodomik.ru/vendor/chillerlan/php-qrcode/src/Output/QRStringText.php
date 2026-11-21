<?php
namespace chillerlan\QRCode\Output;
use function array_map, implode, is_string, max, min, sprintf;
class QRStringText extends QROutputAbstract{
	public const MIME_TYPE = 'text/plain';
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
		$lines     = [];
		$linestart = $this->options->textLineStart;
		foreach($this->matrix->getMatrix() as $row){
			$lines[] = $linestart.implode('', array_map([$this, 'getModuleValue'], $row));
		}
		$data = implode($this->eol, $lines);
		$this->saveToFile($data, $file);
		return $data;
	}
	public static function ansi8(string $str, int $color, ?bool $background = null):string{
		$color      = max(0, min($color, 255));
		$background = ($background === true) ? 48 : 38;
		return sprintf("\x1b[%s;5;%sm%s\x1b[0m", $background, $color, $str);
	}
}