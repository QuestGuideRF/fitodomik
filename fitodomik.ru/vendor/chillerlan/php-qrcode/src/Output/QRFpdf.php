<?php
namespace chillerlan\QRCode\Output;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\Settings\SettingsContainerInterface;
use FPDF;
use function array_values, class_exists, count, intval, is_array, is_numeric, max, min;
class QRFpdf extends QROutputAbstract{
	public const MIME_TYPE = 'application/pdf';
	protected FPDF   $fpdf;
	protected ?array $prevColor = null;
	public function __construct(SettingsContainerInterface $options, QRMatrix $matrix){
		if(!class_exists(FPDF::class)){
			throw new QRCodeOutputException(
				'The QRFpdf output requires FPDF (https://github.com/Setasign/FPDF)'.
				' as dependency but the class "\\FPDF" couldn\'t be found.'
			);
		}
		parent::__construct($options, $matrix);
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
	protected function prepareModuleValue($value):array{
		$values = [];
		foreach(array_values($value) as $i => $val){
			if($i > 2){
				break;
			}
			$values[] = max(0, min(255, intval($val)));
		}
		if(count($values) !== 3){
			throw new QRCodeOutputException('invalid color value');
		}
		return $values;
	}
	protected function getDefaultModuleValue(bool $isDark):array{
		return ($isDark) ? [0, 0, 0] : [255, 255, 255];
	}
	protected function initFPDF():FPDF{
		$fpdf = new FPDF('P', $this->options->fpdfMeasureUnit, $this->getOutputDimensions());
		$fpdf->AddPage();
		return $fpdf;
	}
	public function dump(?string $file = null, ?FPDF $fpdf = null){
		$this->fpdf = ($fpdf ?? $this->initFPDF());
		if($this::moduleValueIsValid($this->options->bgColor)){
			$bgColor          = $this->prepareModuleValue($this->options->bgColor);
			[$width, $height] = $this->getOutputDimensions();
			$this->fpdf->SetFillColor(...$bgColor);
			$this->fpdf->Rect(0, 0, $width, $height, 'F');
		}
		$this->prevColor = null;
		foreach($this->matrix->getMatrix() as $y => $row){
			foreach($row as $x => $M_TYPE){
				$this->module($x, $y, $M_TYPE);
			}
		}
		if($this->options->returnResource){
			return $this->fpdf;
		}
		$pdfData = $this->fpdf->Output('S');
		$this->saveToFile($pdfData, $file);
		if($this->options->outputBase64){
			$pdfData = $this->toBase64DataURI($pdfData);
		}
		return $pdfData;
	}
	protected function module(int $x, int $y, int $M_TYPE):void{
		if(!$this->drawLightModules && !$this->matrix->isDark($M_TYPE)){
			return;
		}
		$color = $this->getModuleValue($M_TYPE);
		if($color !== null && $color !== $this->prevColor){
			$this->fpdf->SetFillColor(...$color);
			$this->prevColor = $color;
		}
		$this->fpdf->Rect(($x * $this->scale), ($y * $this->scale), $this->scale, $this->scale, 'F');
	}
}