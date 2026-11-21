<?php
namespace chillerlan\QRCode\Detector;
use chillerlan\QRCode\Decoder\BitMatrix;
use function abs, count;
final class AlignmentPatternFinder{
	private BitMatrix $matrix;
	private float     $moduleSize;
	private array $possibleCenters;
	public function __construct(BitMatrix $matrix, float $moduleSize){
		$this->matrix          = $matrix;
		$this->moduleSize      = $moduleSize;
		$this->possibleCenters = [];
	}
	public function find(int $startX, int $startY, int $width, int $height):?AlignmentPattern{
		$maxJ       = ($startX + $width);
		$middleI    = ($startY + ($height / 2));
		$stateCount = [];
		for($iGen = 0; $iGen < $height; $iGen++){
			$i             = (int)($middleI + ((($iGen & 0x01) === 0) ? ($iGen + 1) / 2 : -(($iGen + 1) / 2)));
			$stateCount[0] = 0;
			$stateCount[1] = 0;
			$stateCount[2] = 0;
			$j             = $startX;
			while($j < $maxJ && !$this->matrix->check($j, $i)){
				$j++;
			}
			$currentState = 0;
			while($j < $maxJ){
				if($this->matrix->check($j, $i)){
					if($currentState === 1){
						$stateCount[$currentState]++;
					}
					else{
						if($currentState === 2){
							if($this->foundPatternCross($stateCount)){
								$confirmed = $this->handlePossibleCenter($stateCount, $i, $j);
								if($confirmed !== null){
									return $confirmed;
								}
							}
							$stateCount[0] = $stateCount[2];
							$stateCount[1] = 1;
							$stateCount[2] = 0;
							$currentState  = 1;
						}
						else{
							$stateCount[++$currentState]++;
						}
					}
				}
				else{
					if($currentState === 1){
						$currentState++;
					}
					$stateCount[$currentState]++;
				}
				$j++;
			}
			if($this->foundPatternCross($stateCount)){
				$confirmed = $this->handlePossibleCenter($stateCount, $i, $maxJ);
				if($confirmed !== null){
					return $confirmed;
				}
			}
		}
		if(count($this->possibleCenters)){
			return $this->possibleCenters[0];
		}
		return null;
	}
	private function foundPatternCross(array $stateCount):bool{
		$maxVariance = ($this->moduleSize / 2.0);
		for($i = 0; $i < 3; $i++){
			if(abs($this->moduleSize - $stateCount[$i]) >= $maxVariance){
				return false;
			}
		}
		return true;
	}
	private function handlePossibleCenter(array $stateCount, int $i, int $j):?AlignmentPattern{
		$stateCountTotal = ($stateCount[0] + $stateCount[1] + $stateCount[2]);
		$centerJ         = $this->centerFromEnd($stateCount, $j);
		$centerI         = $this->crossCheckVertical($i, (int)$centerJ, (2 * $stateCount[1]), $stateCountTotal);
		if($centerI !== null){
			$estimatedModuleSize = (($stateCount[0] + $stateCount[1] + $stateCount[2]) / 3.0);
			foreach($this->possibleCenters as $center){
				if($center->aboutEquals($estimatedModuleSize, $centerI, $centerJ)){
					return $center->combineEstimate($centerI, $centerJ, $estimatedModuleSize);
				}
			}
			$point                   = new AlignmentPattern($centerJ, $centerI, $estimatedModuleSize);
			$this->possibleCenters[] = $point;
		}
		return null;
	}
	private function centerFromEnd(array $stateCount, int $end):float{
		return (float)(($end - $stateCount[2]) - $stateCount[1] / 2);
	}
	private function crossCheckVertical(int $startI, int $centerJ, int $maxCount, int $originalStateCountTotal):?float{
		$maxI          = $this->matrix->getSize();
		$stateCount    = [];
		$stateCount[0] = 0;
		$stateCount[1] = 0;
		$stateCount[2] = 0;
		$i = $startI;
		while($i >= 0 && $this->matrix->check($centerJ, $i) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$i--;
		}
		if($i < 0 || $stateCount[1] > $maxCount){
			return null;
		}
		while($i >= 0 && !$this->matrix->check($centerJ, $i) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$i--;
		}
		if($stateCount[0] > $maxCount){
			return null;
		}
		$i = ($startI + 1);
		while($i < $maxI && $this->matrix->check($centerJ, $i) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$i++;
		}
		if($i === $maxI || $stateCount[1] > $maxCount){
			return null;
		}
		while($i < $maxI && !$this->matrix->check($centerJ, $i) && $stateCount[2] <= $maxCount){
			$stateCount[2]++;
			$i++;
		}
		if($stateCount[2] > $maxCount){
			return null;
		}
		if((5 * abs(($stateCount[0] + $stateCount[1] + $stateCount[2]) - $originalStateCountTotal)) >= (2 * $originalStateCountTotal)){
			return null;
		}
		if(!$this->foundPatternCross($stateCount)){
			return null;
		}
		return $this->centerFromEnd($stateCount, $i);
	}
}