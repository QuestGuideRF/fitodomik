<?php
namespace chillerlan\QRCode;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\Common\{EccLevel, MaskPattern, Version};
use function extension_loaded, in_array, max, min, strtolower;
use const JSON_THROW_ON_ERROR, PHP_EOL;
trait QROptionsTrait{
	protected int $version = Version::AUTO;
	protected int $versionMin = 1;
	protected int $versionMax = 40;
	protected int $eccLevel = EccLevel::L;
	protected int $maskPattern = MaskPattern::AUTO;
	protected bool $addQuietzone = true;
	protected int $quietzoneSize = 4;
	protected string $outputType = QROutputInterface::MARKUP_SVG;
	protected ?string $outputInterface = null;
	protected bool $returnResource = false;
	protected ?string $cachefile = null;
	protected bool $outputBase64 = true;
	protected string $eol = PHP_EOL;
	protected $bgColor = null;
	protected bool $invertMatrix = false;
	protected bool $drawLightModules = true;
	protected bool $drawCircularModules = false;
	protected float $circleRadius = 0.45;
	protected array $keepAsSquare = [];
	protected bool $connectPaths = false;
	protected array $excludeFromConnect = [];
	protected array $moduleValues = [];
	protected bool $addLogoSpace = false;
	protected ?int $logoSpaceWidth = null;
	protected ?int $logoSpaceHeight = null;
	protected ?int $logoSpaceStartX = null;
	protected ?int $logoSpaceStartY = null;
	protected int $scale = 5;
	protected bool $imageTransparent = false;
	protected $transparencyColor = null;
	protected int $quality = -1;
	protected bool $gdImageUseUpscale = true;
	protected string $imagickFormat = 'png32';
	protected string $cssClass = 'qrcode';
	protected bool $svgAddXmlHeader = true;
	protected string $svgDefs = '';
	protected string $svgPreserveAspectRatio = 'xMidYMid';
	protected bool $svgUseFillAttributes = true;
	protected string $textLineStart = '';
	protected int $jsonFlags = JSON_THROW_ON_ERROR;
	protected bool $jsonAsBooleans = false;
	protected string $fpdfMeasureUnit = 'pt';
	protected bool $readerUseImagickIfAvailable = false;
	protected bool $readerGrayscale = false;
	protected bool $readerInvertColors = false;
	protected bool $readerIncreaseContrast = false;
	protected function setMinMaxVersion(int $versionMin, int $versionMax):void{
		$min = max(1, min(40, $versionMin));
		$max = max(1, min(40, $versionMax));
		$this->versionMin = min($min, $max);
		$this->versionMax = max($min, $max);
	}
	protected function set_versionMin(int $version):void{
		$this->setMinMaxVersion($version, $this->versionMax);
	}
	protected function set_versionMax(int $version):void{
		$this->setMinMaxVersion($this->versionMin, $version);
	}
	protected function set_version(int $version):void{
		$this->version = ($version !== Version::AUTO) ? max(1, min(40, $version)) : Version::AUTO;
	}
	protected function set_quietzoneSize(int $quietzoneSize):void{
		$this->quietzoneSize = max(0, min($quietzoneSize, 75));
	}
	protected function set_fpdfMeasureUnit(string $unit):void{
		$unit = strtolower($unit);
		if(in_array($unit, ['cm', 'in', 'mm', 'pt'], true)){
			$this->fpdfMeasureUnit = $unit;
		}
	}
	protected function set_readerUseImagickIfAvailable(bool $useImagickIfAvailable):void{
		$this->readerUseImagickIfAvailable = ($useImagickIfAvailable && extension_loaded('imagick'));
	}
	protected function clampLogoSpaceValue(?int $value):?int{
		if($value === null){
			return null;
		}
		return (int)max(0, min(177, $value));
	}
	protected function set_logoSpaceWidth(?int $value):void{
		$this->logoSpaceWidth = $this->clampLogoSpaceValue($value);
	}
	protected function set_logoSpaceHeight(?int $value):void{
		$this->logoSpaceHeight = $this->clampLogoSpaceValue($value);
	}
	protected function set_logoSpaceStartX(?int $value):void{
		$this->logoSpaceStartX = $this->clampLogoSpaceValue($value);
	}
	protected function set_logoSpaceStartY(?int $value):void{
		$this->logoSpaceStartY = $this->clampLogoSpaceValue($value);
	}
	protected function set_circleRadius(float $circleRadius):void{
		$this->circleRadius = max(0.1, min(0.75, $circleRadius));
	}
	protected bool $imageBase64;
	protected function set_imageBase64(bool $imageBase64):void{
		$this->outputBase64 = $imageBase64;
	}
	protected function get_imageBase64():bool{
		return $this->outputBase64;
	}
	protected int $jpegQuality;
	protected function set_jpegQuality(int $jpegQuality):void{
		$this->quality = $jpegQuality;
	}
	protected function get_jpegQuality():int{
		return $this->quality;
	}
	protected int $pngCompression;
	protected function set_pngCompression(int $pngCompression):void{
		$this->quality = $pngCompression;
	}
	protected function get_pngCompression():int{
		return $this->quality;
	}
	protected array $imageTransparencyBG;
	protected function set_imageTransparencyBG(?array $imageTransparencyBG):void{
		$this->transparencyColor = $imageTransparencyBG;
	}
	protected function get_imageTransparencyBG():?array{
		return $this->transparencyColor;
	}
	protected string $imagickBG;
	protected function set_imagickBG(?string $imagickBG):void{
		$this->bgColor = $imagickBG;
	}
	protected function get_imagickBG():?string{
		return $this->bgColor;
	}
}