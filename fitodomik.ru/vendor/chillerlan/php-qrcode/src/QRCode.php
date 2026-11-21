<?php
namespace chillerlan\QRCode;
use chillerlan\QRCode\Common\{
	EccLevel, ECICharset, GDLuminanceSource, IMagickLuminanceSource, LuminanceSourceInterface, MaskPattern, Mode, Version
};
use chillerlan\QRCode\Data\{AlphaNum, Byte, ECI, Hanzi, Kanji, Number, QRData, QRDataModeInterface, QRMatrix};
use chillerlan\QRCode\Decoder\{Decoder, DecoderResult};
use chillerlan\QRCode\Output\{QRCodeOutputException, QROutputInterface};
use chillerlan\Settings\SettingsContainerInterface;
use function class_exists, class_implements, in_array, mb_convert_encoding, mb_internal_encoding;
class QRCode{
	public const VERSION_AUTO      = Version::AUTO;
	public const MASK_PATTERN_AUTO = MaskPattern::AUTO;
	public const ECC_L = EccLevel::L;
	public const ECC_M = EccLevel::M;
	public const ECC_Q = EccLevel::Q;
	public const ECC_H = EccLevel::H;
	public const OUTPUT_MARKUP_HTML = QROutputInterface::MARKUP_HTML;
	public const OUTPUT_MARKUP_SVG  = QROutputInterface::MARKUP_SVG;
	public const OUTPUT_IMAGE_PNG   = QROutputInterface::GDIMAGE_PNG;
	public const OUTPUT_IMAGE_JPG   = QROutputInterface::GDIMAGE_JPG;
	public const OUTPUT_IMAGE_GIF   = QROutputInterface::GDIMAGE_GIF;
	public const OUTPUT_STRING_JSON = QROutputInterface::STRING_JSON;
	public const OUTPUT_STRING_TEXT = QROutputInterface::STRING_TEXT;
	public const OUTPUT_IMAGICK     = QROutputInterface::IMAGICK;
	public const OUTPUT_FPDF        = QROutputInterface::FPDF;
	public const OUTPUT_EPS         = QROutputInterface::EPS;
	public const OUTPUT_CUSTOM      = QROutputInterface::CUSTOM;
	public const OUTPUT_MODES       = QROutputInterface::MODES;
	protected SettingsContainerInterface $options;
	protected array $dataSegments = [];
	protected string $luminanceSourceFQN = GDLuminanceSource::class;
	public function __construct(?SettingsContainerInterface $options = null){
		$this->setOptions(($options ?? new QROptions));
	}
	public function setOptions(SettingsContainerInterface $options):self{
		$this->options = $options;
		if($this->options->readerUseImagickIfAvailable){
			$this->luminanceSourceFQN = IMagickLuminanceSource::class;
		}
		return $this;
	}
	public function render(?string $data = null, ?string $file = null){
		if($data !== null){
			foreach(Mode::INTERFACES as $dataInterface){
				if($dataInterface::validateString($data)){
					$this->addSegment(new $dataInterface($data));
					break;
				}
			}
		}
		return $this->renderMatrix($this->getQRMatrix(), $file);
	}
	public function renderMatrix(QRMatrix $matrix, ?string $file = null){
		return $this->initOutputInterface($matrix)->dump($file ?? $this->options->cachefile);
	}
	public function getQRMatrix():QRMatrix{
		$matrix = (new QRData($this->options, $this->dataSegments))->writeMatrix();
		$maskPattern = $this->options->maskPattern === MaskPattern::AUTO
			? MaskPattern::getBestPattern($matrix)
			: new MaskPattern($this->options->maskPattern);
		$matrix->setFormatInfo($maskPattern)->mask($maskPattern);
		return $this->addMatrixModifications($matrix);
	}
	protected function addMatrixModifications(QRMatrix $matrix):QRMatrix{
		if($this->options->addLogoSpace){
			$logoSpaceWidth  = ($this->options->logoSpaceWidth ?? $this->options->logoSpaceHeight ?? 0);
			$logoSpaceHeight = ($this->options->logoSpaceHeight ?? $logoSpaceWidth);
			$matrix->setLogoSpace(
				$logoSpaceWidth,
				$logoSpaceHeight,
				$this->options->logoSpaceStartX,
				$this->options->logoSpaceStartY
			);
		}
		if($this->options->addQuietzone){
			$matrix->setQuietZone($this->options->quietzoneSize);
		}
		return $matrix;
	}
	public function getMatrix():QRMatrix{
		return $this->getQRMatrix();
	}
	protected function initOutputInterface(QRMatrix $matrix):QROutputInterface{
		$outputInterface = (QROutputInterface::MODES[$this->options->outputType] ?? null);
		if($this->options->outputType === QROutputInterface::CUSTOM){
			$outputInterface = $this->options->outputInterface;
		}
		if(!$outputInterface || !class_exists($outputInterface)){
			throw new QRCodeOutputException('invalid output module');
		}
		if(!in_array(QROutputInterface::class, class_implements($outputInterface), true)){
			throw new QRCodeOutputException('output module does not implement QROutputInterface');
		}
		return new $outputInterface($this->options, $matrix);
	}
	public function isNumber(string $string):bool{
		return Number::validateString($string);
	}
	public function isAlphaNum(string $string):bool{
		return AlphaNum::validateString($string);
	}
	public function isKanji(string $string):bool{
		return Kanji::validateString($string);
	}
	public function isByte(string $string):bool{
		return Byte::validateString($string);
	}
	public function addSegment(QRDataModeInterface $segment):self{
		$this->dataSegments[] = $segment;
		return $this;
	}
	public function clearSegments():self{
		$this->dataSegments = [];
		return $this;
	}
	public function addNumericSegment(string $data):self{
		return $this->addSegment(new Number($data));
	}
	public function addAlphaNumSegment(string $data):self{
		return $this->addSegment(new AlphaNum($data));
	}
	public function addKanjiSegment(string $data):self{
		return $this->addSegment(new Kanji($data));
	}
	public function addHanziSegment(string $data):self{
		return $this->addSegment(new Hanzi($data));
	}
	public function addByteSegment(string $data):self{
		return $this->addSegment(new Byte($data));
	}
	public function addEciDesignator(int $encoding):self{
		return $this->addSegment(new ECI($encoding));
	}
	public function addEciSegment(int $encoding, string $data):self{
		$eciCharset = new ECICharset($encoding);
		$eciCharsetName = $eciCharset->getName();
		if($eciCharsetName !== null){
			$data = mb_convert_encoding($data, $eciCharsetName, mb_internal_encoding());
			return $this
				->addEciDesignator($eciCharset->getID())
				->addByteSegment($data)
			;
		}
		throw new QRCodeException('unable to add ECI segment');
	}
	public function readFromFile(string $path):DecoderResult{
		return $this->readFromSource($this->luminanceSourceFQN::fromFile($path, $this->options));
	}
	public function readFromBlob(string $blob):DecoderResult{
		return $this->readFromSource($this->luminanceSourceFQN::fromBlob($blob, $this->options));
	}
	public function readFromSource(LuminanceSourceInterface $source):DecoderResult{
		return (new Decoder)->decode($source);
	}
}