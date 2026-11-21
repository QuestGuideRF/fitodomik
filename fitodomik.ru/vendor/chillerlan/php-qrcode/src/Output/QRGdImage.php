<?php
namespace chillerlan\QRCode\Output;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\Settings\SettingsContainerInterface;
use ErrorException;
use Throwable;
use function array_values, count, extension_loaded, imagebmp, imagecolorallocate, imagecolortransparent,
	imagecreatetruecolor, imagefilledellipse, imagefilledrectangle, imagegif, imagejpeg, imagepng,
	imagescale, imagetypes, imagewebp, intdiv, intval, is_array, is_numeric, max, min, ob_end_clean, ob_get_contents, ob_start,
	restore_error_handler, set_error_handler, sprintf;
use const IMG_BMP, IMG_GIF, IMG_JPG, IMG_PNG, IMG_WEBP;
class QRGdImage extends QROutputAbstract{
	protected $image;
	protected int $background;
	protected bool $upscaled = false;
	public function __construct(SettingsContainerInterface $options, QRMatrix $matrix){
		$this->options = $options;
		$this->matrix  = $matrix;
		$this->checkGD();
		if($this->options->invertMatrix){
			$this->matrix->invert();
		}
		$this->copyVars();
		$this->setMatrixDimensions();
	}
	protected function checkGD():void{
		if(!extension_loaded('gd')){
			throw new QRCodeOutputException('ext-gd not loaded');
		}
		$modes = [
			self::GDIMAGE_BMP  => IMG_BMP,
			self::GDIMAGE_GIF  => IMG_GIF,
			self::GDIMAGE_JPG  => IMG_JPG,
			self::GDIMAGE_PNG  => IMG_PNG,
			self::GDIMAGE_WEBP => IMG_WEBP,
		];
		if(!isset($modes[$this->options->outputType])){
			return;
		}
		$mode = $modes[$this->options->outputType];
		if((imagetypes() & $mode) !== $mode){
			throw new QRCodeOutputException(sprintf('output mode "%s" not supported', $this->options->outputType));
		}
	}
	public static function moduleValueIsValid($value):bool{
		if(!is_array($value) || count($value) < 3){
			return false;
		}
		foreach(array_values($value) as $i => $val){
			if($i > 2){
				break;
			}
			if(!is_numeric($val)){
				return false;
			}
		}
		return true;
	}
	protected function prepareModuleValue($value):int{
		$values = [];
		foreach(array_values($value) as $i => $val){
			if($i > 2){
				break;
			}
			$values[] = max(0, min(255, intval($val)));
		}
		$color = imagecolorallocate($this->image, ...$values);
		if($color === false){
			throw new QRCodeOutputException('could not set color: imagecolorallocate() error');
		}
		return $color;
	}
	protected function getDefaultModuleValue(bool $isDark):int{
		return $this->prepareModuleValue(($isDark) ? [0, 0, 0] : [255, 255, 255]);
	}
	public function dump(?string $file = null){
		set_error_handler(function(int $errno, string $errstr):bool{
			throw new ErrorException($errstr, $errno);
		});
		$this->image = $this->createImage();
		$this->setModuleValues();
		$this->setBgColor();
		imagefilledrectangle($this->image, 0, 0, $this->length, $this->length, $this->background);
		$this->drawImage();
		if($this->upscaled){
			$this->image    = imagescale($this->image, ($this->length / 10), ($this->length / 10));
			$this->upscaled = false;
		}
		$this->setTransparencyColor();
		if($this->options->returnResource){
			restore_error_handler();
			return $this->image;
		}
		$imageData = $this->dumpImage();
		$this->saveToFile($imageData, $file);
		if($this->options->outputBase64){
			$imageData = $this->toBase64DataURI($imageData, 'image/'.$this->options->outputType);
		}
		restore_error_handler();
		return $imageData;
	}
	protected function createImage(){
		if($this->drawCircularModules && $this->options->gdImageUseUpscale && $this->options->scale < 20){
			$this->length   *= 10;
			$this->scale    *= 10;
			$this->upscaled  = true;
		}
		return imagecreatetruecolor($this->length, $this->length);
	}
	protected function setBgColor():void{
		if(isset($this->background)){
			return;
		}
		if($this::moduleValueIsValid($this->options->bgColor)){
			$this->background = $this->prepareModuleValue($this->options->bgColor);
			return;
		}
		$this->background = $this->prepareModuleValue([255, 255, 255]);
	}
	protected function setTransparencyColor():void{
		if($this->options->outputType === QROutputInterface::GDIMAGE_JPG || !$this->options->imageTransparent){
			return;
		}
		$transparencyColor = $this->background;
		if($this::moduleValueIsValid($this->options->transparencyColor)){
			$transparencyColor = $this->prepareModuleValue($this->options->transparencyColor);
		}
		imagecolortransparent($this->image, $transparencyColor);
	}
	protected function drawImage():void{
		foreach($this->matrix->getMatrix() as $y => $row){
			foreach($row as $x => $M_TYPE){
				$this->module($x, $y, $M_TYPE);
			}
		}
	}
	protected function module(int $x, int $y, int $M_TYPE):void{
		if(!$this->drawLightModules && !$this->matrix->isDark($M_TYPE)){
			return;
		}
		$color = $this->getModuleValue($M_TYPE);
		if($this->drawCircularModules && !$this->matrix->checkTypeIn($x, $y, $this->keepAsSquare)){
			imagefilledellipse(
				$this->image,
				(($x * $this->scale) + intdiv($this->scale, 2)),
				(($y * $this->scale) + intdiv($this->scale, 2)),
				(int)($this->circleDiameter * $this->scale),
				(int)($this->circleDiameter * $this->scale),
				$color
			);
			return;
		}
		imagefilledrectangle(
			$this->image,
			($x * $this->scale),
			($y * $this->scale),
			(($x + 1) * $this->scale),
			(($y + 1) * $this->scale),
			$color
		);
	}
	protected function renderImage():void{
		switch($this->options->outputType){
			case QROutputInterface::GDIMAGE_BMP:
				imagebmp($this->image, null, ($this->options->quality > 0));
				break;
			case QROutputInterface::GDIMAGE_GIF:
				imagegif($this->image);
				break;
			case QROutputInterface::GDIMAGE_JPG:
				imagejpeg($this->image, null, max(-1, min(100, $this->options->quality)));
				break;
			case QROutputInterface::GDIMAGE_WEBP:
				imagewebp($this->image, null, max(-1, min(100, $this->options->quality)));
				break;
			case QROutputInterface::GDIMAGE_PNG:
			default:
				imagepng($this->image, null, max(-1, min(9, $this->options->quality)));
		}
	}
	protected function dumpImage():string{
		$exception = null;
		$imageData = null;
		ob_start();
		try{
			$this->renderImage();
			$imageData = ob_get_contents();
		}
		catch(Throwable $e){
			$exception = $e;
		}
		ob_end_clean();
		if($exception instanceof Throwable){
			throw new QRCodeOutputException($exception->getMessage());
		}
		return $imageData;
	}
}