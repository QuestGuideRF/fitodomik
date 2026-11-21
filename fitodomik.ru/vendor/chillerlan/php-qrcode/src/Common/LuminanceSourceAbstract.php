<?php
namespace chillerlan\QRCode\Common;
use chillerlan\QRCode\Decoder\QRCodeDecoderException;
use chillerlan\QRCode\QROptions;
use chillerlan\Settings\SettingsContainerInterface;
use function array_slice, array_splice, file_exists, is_file, is_readable, realpath;
abstract class LuminanceSourceAbstract implements LuminanceSourceInterface{
	protected SettingsContainerInterface $options;
	protected array $luminances;
	protected int   $width;
	protected int   $height;
	public function __construct(int $width, int $height, ?SettingsContainerInterface $options = null){
		$this->width   = $width;
		$this->height  = $height;
		$this->options = ($options ?? new QROptions);
		$this->luminances = [];
	}
	public function getLuminances():array{
		return $this->luminances;
	}
	public function getWidth():int{
		return $this->width;
	}
	public function getHeight():int{
		return $this->height;
	}
	public function getRow(int $y):array{
		if($y < 0 || $y >= $this->getHeight()){
			throw new QRCodeDecoderException('Requested row is outside the image: '.$y);
		}
		$arr = [];
		array_splice($arr, 0, $this->width, array_slice($this->luminances, ($y * $this->width), $this->width));
		return $arr;
	}
	protected function setLuminancePixel(int $r, int $g, int $b):void{
		$this->luminances[] = ($r === $g && $g === $b)
			? $r
			: (($r + 2 * $g + $b) / 4);
	}
	protected static function checkFile(string $path):string{
		$path = trim($path);
		if(!file_exists($path) || !is_file($path) || !is_readable($path)){
			throw new QRCodeDecoderException('invalid file: '.$path);
		}
		$realpath = realpath($path);
		if($realpath === false){
			throw new QRCodeDecoderException('unable to resolve path: '.$path);
		}
		return $realpath;
	}
}