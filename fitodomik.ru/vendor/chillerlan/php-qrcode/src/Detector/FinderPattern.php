<?php
namespace chillerlan\QRCode\Detector;
use function sqrt;
final class FinderPattern extends ResultPoint{
	private int $count;
	public function __construct(float $posX, float $posY, float $estimatedModuleSize, ?int $count = null){
		parent::__construct($posX, $posY, $estimatedModuleSize);
		$this->count = ($count ?? 1);
	}
	public function getCount():int{
		return $this->count;
	}
	public function getDistance(FinderPattern $b):float{
		return self::distance($this->x, $this->y, $b->x, $b->y);
	}
	public function getSquaredDistance(FinderPattern $b):float{
		return self::squaredDistance($this->x, $this->y, $b->x, $b->y);
	}
	public function combineEstimate(float $i, float $j, float $newModuleSize):self{
		$combinedCount = ($this->count + 1);
		return new self(
			($this->count * $this->x + $j) / $combinedCount,
			($this->count * $this->y + $i) / $combinedCount,
			($this->count * $this->estimatedModuleSize + $newModuleSize) / $combinedCount,
			$combinedCount
		);
	}
	private static function squaredDistance(float $aX, float $aY, float $bX, float $bY):float{
		$xDiff = ($aX - $bX);
		$yDiff = ($aY - $bY);
		return ($xDiff * $xDiff + $yDiff * $yDiff);
	}
	public static function distance(float $aX, float $aY, float $bX, float $bY):float{
		return sqrt(self::squaredDistance($aX, $aY, $bX, $bY));
	}
}