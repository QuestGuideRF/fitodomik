<?php
namespace chillerlan\QRCode\Common;
use chillerlan\Settings\SettingsContainerInterface;
use Imagick;
use function count;
class IMagickLuminanceSource extends LuminanceSourceAbstract{
	protected Imagick $imagick;
	public function __construct(Imagick $imagick, ?SettingsContainerInterface $options = null){
		parent::__construct($imagick->getImageWidth(), $imagick->getImageHeight(), $options);
		$this->imagick = $imagick;
		if($this->options->readerGrayscale){
			$this->imagick->setImageColorspace(Imagick::COLORSPACE_GRAY);
		}
		if($this->options->readerInvertColors){
			$this->imagick->negateImage($this->options->readerGrayscale);
		}
		if($this->options->readerIncreaseContrast){
			for($i = 0; $i < 10; $i++){
				$this->imagick->contrastImage(false);
			}
		}
		$this->setLuminancePixels();
	}
	protected function setLuminancePixels():void{
		$pixels = $this->imagick->exportImagePixels(1, 1, $this->width, $this->height, 'RGB', Imagick::PIXEL_CHAR);
		$count  = count($pixels);
		for($i = 0; $i < $count; $i += 3){
			$this->setLuminancePixel(($pixels[$i] & 0xff), ($pixels[($i + 1)] & 0xff), ($pixels[($i + 2)] & 0xff));
		}
	}
	public static function fromFile(string $path, ?SettingsContainerInterface $options = null):self{
		return new self(new Imagick(self::checkFile($path)), $options);
	}
	public static function fromBlob(string $blob, ?SettingsContainerInterface $options = null):self{
		$im = new Imagick;
		$im->readImageBlob($blob);
		return new self($im, $options);
	}
}