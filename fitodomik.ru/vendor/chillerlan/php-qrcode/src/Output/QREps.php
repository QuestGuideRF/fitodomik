<?php
namespace chillerlan\QRCode\Output;
use function array_values, count, date, implode, is_array, is_numeric, max, min, round, sprintf;
class QREps extends QROutputAbstract{
	public const MIME_TYPE = 'application/postscript';
	public static function moduleValueIsValid($value):bool{
		if(!is_array($value) || count($value) < 3){
			return false;
		}
		foreach(array_values($value) as $i => $val){
			if($i > 3){
				break;
			}
			if(!is_numeric($val)){
				return false;
			}
		}
		return true;
	}
	protected function prepareModuleValue($value):string{
		$values = [];
		foreach(array_values($value) as $i => $val){
			if($i > 3){
				break;
			}
			$values[] = round((max(0, min(255, intval($val))) / 255), 6);
		}
		return $this->formatColor($values);
	}
	protected function getDefaultModuleValue(bool $isDark):string{
		return $this->formatColor(($isDark) ? [0.0, 0.0, 0.0] : [1.0, 1.0, 1.0]);
	}
	protected function formatColor(array $values):string{
		$count = count($values);
		if($count < 3){
			throw new QRCodeOutputException('invalid color value');
		}
		$format = ($count === 4)
			? '%f %f %f %f C'
			: '%f %f %f R';
		return sprintf($format, ...$values);
	}
	public function dump(?string $file = null):string{
		[$width, $height] = $this->getOutputDimensions();
		$eps = [
			'%!PS-Adobe-3.0 EPSF-3.0',
			'%%Creator: php-qrcode (https://github.com/chillerlan/php-qrcode)',
			'%%Title: QR Code',
			sprintf('%%%%CreationDate: %1$s', date('c')),
			'%%DocumentData: Clean7Bit',
			'%%LanguageLevel: 3',
			sprintf('%%%%BoundingBox: 0 0 %s %s', $width, $height),
			'%%EndComments',
			'%%BeginProlog',
			'/F { rectfill } def',
			'/R { setrgbcolor } def',
			'/C { setcmykcolor } def',
			'%%EndProlog',
		];
		if($this::moduleValueIsValid($this->options->bgColor)){
			$eps[] = $this->prepareModuleValue($this->options->bgColor);
			$eps[] = sprintf('0 0 %s %s F', $width, $height);
		}
		$paths = $this->collectModules(fn(int $x, int $y, int $M_TYPE):string => $this->module($x, $y, $M_TYPE));
		foreach($paths as $M_TYPE => $path){
			if($path === []){
				continue;
			}
			$eps[] = $this->getModuleValue($M_TYPE);
			$eps[] = implode("\n", $path);
		}
		$eps[] = '%%EOF';
		$data = implode("\n", $eps);
		$this->saveToFile($data, $file);
		return $data;
	}
	protected function module(int $x, int $y, int $M_TYPE):string{
		if(!$this->drawLightModules && !$this->matrix->isDark($M_TYPE)){
			return '';
		}
		$outputX = ($x * $this->scale);
		$top     = ($this->length - $this->scale);
		$outputY = ($top - ($y * $this->scale));
		return sprintf('%d %d %d %d F', $outputX, $outputY, $this->scale, $this->scale);
	}
}