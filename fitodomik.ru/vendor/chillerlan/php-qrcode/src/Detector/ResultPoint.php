<?php
namespace chillerlan\QRCode\Detector;
use function abs;
abstract class ResultPoint{
	protected float $x;
	protected float $y;
	protected float $estimatedModuleSize;
	public function __construct(float $x, float $y, float $estimatedModuleSize){
		$this->x                   = $x;
		$this->y                   = $y;
		$this->estimatedModuleSize = $estimatedModuleSize;
	}
	public function getX():float{
		return $this->x;
	}
	public function getY():float{
		return $this->y;
	}
	public function getEstimatedModuleSize():float{
		return $this->estimatedModuleSize;
	}
	public function aboutEquals(float $moduleSize, float $i, float $j):bool{
		if(abs($i - $this->y) <= $moduleSize && abs($j - $this->x) <= $moduleSize){
			$moduleSizeDiff = abs($moduleSize - $this->estimatedModuleSize);
			return $moduleSizeDiff <= 1.0 || $moduleSizeDiff <= $this->estimatedModuleSize;
		}
		return false;
	}
}