<?php
namespace chillerlan\QRCode\Output;
use function array_chunk, implode, is_string, preg_match, sprintf, trim;
class QRMarkupSVG extends QRMarkup{
	public const MIME_TYPE = 'image/svg+xml';
	public static function moduleValueIsValid($value):bool{
		if(!is_string($value)){
			return false;
		}
		$value = trim($value);
		if(preg_match('~^url\([-/#a-z\d]+\)$~i', $value)){
			return true;
		}
		return parent::moduleValueIsValid($value);
	}
	protected function getOutputDimensions():array{
		return [$this->moduleCount, $this->moduleCount];
	}
	protected function getCssClass(int $M_TYPE = 0):string{
		return implode(' ', [
			'qr-'.($this::LAYERNAMES[$M_TYPE] ?? $M_TYPE),
			$this->matrix->isDark($M_TYPE) ? 'dark' : 'light',
			$this->options->cssClass,
		]);
	}
	protected function createMarkup(bool $saveToFile):string{
		$svg = $this->header();
		if($this->options->svgDefs !== ''){
			$svg .= sprintf('<defs>%1$s%2$s</defs>%2$s', $this->options->svgDefs, $this->eol);
		}
		$svg .= $this->paths();
		$svg .= sprintf('%1$s</svg>%1$s', $this->eol);
		if(!$saveToFile && $this->options->outputBase64){
			$svg = $this->toBase64DataURI($svg);
		}
		return $svg;
	}
	protected function getViewBox():string{
		[$width, $height] = $this->getOutputDimensions();
		return sprintf('0 0 %s %s', $width, $height);
	}
	protected function header():string{
		$header = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" class="qr-svg %1$s" viewBox="%2$s" preserveAspectRatio="%3$s">%4$s',
			$this->options->cssClass,
			$this->getViewBox(),
			$this->options->svgPreserveAspectRatio,
			$this->eol
		);
		if($this->options->svgAddXmlHeader){
			$header = sprintf('<?xml version="1.0" encoding="UTF-8"?>%s%s', $this->eol, $header);
		}
		return $header;
	}
	protected function paths():string{
		$paths = $this->collectModules(fn(int $x, int $y, int $M_TYPE):string => $this->module($x, $y, $M_TYPE));
		$svg   = [];
		foreach($paths as $M_TYPE => $modules){
			$chunks = array_chunk($modules, 100);
			$chonks = [];
			foreach($chunks as $chunk){
				$chonks[] = implode(' ', $chunk);
			}
			$path = implode($this->eol, $chonks);
			if($path === ''){
				continue;
			}
			$svg[] = $this->path($path, $M_TYPE);
		}
		return implode($this->eol, $svg);
	}
	protected function path(string $path, int $M_TYPE):string{
		if($this->options->svgUseFillAttributes){
			return sprintf(
				'<path class="%s" fill="%s" d="%s"/>',
				$this->getCssClass($M_TYPE),
				$this->getModuleValue($M_TYPE),
				$path
			);
		}
		return sprintf('<path class="%s" d="%s"/>', $this->getCssClass($M_TYPE), $path);
	}
	protected function module(int $x, int $y, int $M_TYPE):string{
		if(!$this->drawLightModules && !$this->matrix->isDark($M_TYPE)){
			return '';
		}
		if($this->drawCircularModules && !$this->matrix->checkTypeIn($x, $y, $this->keepAsSquare)){
			$ix = ($x + 0.5 - $this->circleRadius);
			$iy = ($y + 0.5);
			return "M$ix $iy a$this->circleRadius $this->circleRadius 0 1 0 $this->circleDiameter 0 a$this->circleRadius $this->circleRadius 0 1 0 -$this->circleDiameter 0Z";
		}
		return "M$x $y h1 v1 h-1Z";
	}
}