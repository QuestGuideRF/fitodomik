<?php
namespace chillerlan\QRCode\Output;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\Settings\SettingsContainerInterface;
use Closure;
use function base64_encode, dirname, file_put_contents, is_writable, ksort, sprintf;
abstract class QROutputAbstract implements QROutputInterface{
	protected int $moduleCount;
	protected int $length;
	protected array $moduleValues;
	protected QRMatrix $matrix;
	protected SettingsContainerInterface $options;
	protected int $scale;
	protected bool $connectPaths;
	protected array $excludeFromConnect;
	protected string $eol;
	protected bool $drawLightModules;
	protected bool $drawCircularModules;
	protected array $keepAsSquare;
	protected float $circleRadius;
	protected float $circleDiameter;
	public function __construct(SettingsContainerInterface $options, QRMatrix $matrix){
		$this->options = $options;
		$this->matrix  = $matrix;
		if($this->options->invertMatrix){
			$this->matrix->invert();
		}
		$this->copyVars();
		$this->setMatrixDimensions();
		$this->setModuleValues();
	}
	protected function copyVars():void{
		$vars = [
			'connectPaths',
			'excludeFromConnect',
			'eol',
			'drawLightModules',
			'drawCircularModules',
			'keepAsSquare',
			'circleRadius',
		];
		foreach($vars as $property){
			$this->{$property} = $this->options->{$property};
		}
		$this->circleDiameter = ($this->circleRadius * 2);
	}
	protected function setMatrixDimensions():void{
		$this->moduleCount = $this->matrix->getSize();
		$this->scale       = $this->options->scale;
		$this->length      = ($this->moduleCount * $this->scale);
	}
	protected function getOutputDimensions():array{
		return [$this->length, $this->length];
	}
	protected function setModuleValues():void{
		foreach($this::DEFAULT_MODULE_VALUES as $M_TYPE => $defaultValue){
			$this->moduleValues[$M_TYPE] = $this->getDefaultModuleValue($defaultValue);
		}
		foreach($this->options->moduleValues as $M_TYPE => $value){
			if($this::moduleValueIsValid($value)){
				$this->moduleValues[$M_TYPE] = $this->prepareModuleValue($value);
			}
		}
	}
	abstract protected function prepareModuleValue($value);
	abstract protected function getDefaultModuleValue(bool $isDark);
	protected function getModuleValue(int $M_TYPE){
		if(!isset($this->moduleValues[$M_TYPE])){
			throw new QRCodeOutputException(sprintf('$M_TYPE %012b not found in module values map', $M_TYPE));
		}
		return $this->moduleValues[$M_TYPE];
	}
	protected function getModuleValueAt(int $x, int $y){
		return $this->getModuleValue($this->matrix->get($x, $y));
	}
	protected function toBase64DataURI(string $data, ?string $mime = null):string{
		return sprintf('data:%s;base64,%s', ($mime ?? $this::MIME_TYPE), base64_encode($data));
	}
	protected function saveToFile(string $data, ?string $file = null):void{
		if($file === null){
			return;
		}
		if(!is_writable(dirname($file))){
			throw new QRCodeOutputException(sprintf('Cannot write data to cache file: %s', $file));
		}
		if(file_put_contents($file, $data) === false){
			throw new QRCodeOutputException(sprintf('Cannot write data to cache file: %s (file_put_contents error)', $file));
		}
	}
	protected function collectModules(Closure $transform):array{
		$paths = [];
		foreach($this->matrix->getMatrix() as $y => $row){
			foreach($row as $x => $M_TYPE){
				$M_TYPE_LAYER = $M_TYPE;
				if($this->connectPaths && !$this->matrix->checkTypeIn($x, $y, $this->excludeFromConnect)){
					$M_TYPE_LAYER = QRMatrix::M_DATA;
					if($this->matrix->isDark($M_TYPE)){
						$M_TYPE_LAYER = QRMatrix::M_DATA_DARK;
					}
				}
				$module = $transform($x, $y, $M_TYPE, $M_TYPE_LAYER);
				if(!empty($module)){
					$paths[$M_TYPE_LAYER][] = $module;
				}
			}
		}
		ksort($paths);
		return $paths;
	}
	protected function moduleTransform(int $x, int $y, int $M_TYPE, int $M_TYPE_LAYER){
		return null;
	}
}