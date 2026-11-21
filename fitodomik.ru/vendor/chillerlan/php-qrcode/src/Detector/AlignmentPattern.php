<?php
namespace chillerlan\QRCode\Detector;
final class AlignmentPattern extends ResultPoint{
	public function combineEstimate(float $i, float $j, float $newModuleSize):self{
		return new self(
			(($this->x + $j) / 2.0),
			(($this->y + $i) / 2.0),
			(($this->estimatedModuleSize + $newModuleSize) / 2.0)
		);
	}
}