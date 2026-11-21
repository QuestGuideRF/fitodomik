<?php
namespace chillerlan\QRCode\Detector;
use chillerlan\QRCode\Decoder\BitMatrix;
use function abs, count, intdiv, usort;
use const PHP_FLOAT_MAX;
final class FinderPatternFinder{
	private const MIN_SKIP      = 2;
	private const MAX_MODULES   = 177;
	private const CENTER_QUORUM = 2;
	private BitMatrix $matrix;
	private array $possibleCenters;
	private bool  $hasSkipped = false;
	public function __construct(BitMatrix $matrix){
		$this->matrix          = $matrix;
		$this->possibleCenters = [];
	}
	public function find():array{
		$dimension = $this->matrix->getSize();
		$iSkip = intdiv((3 * $dimension), (4 * self::MAX_MODULES));
		if($iSkip < self::MIN_SKIP){
			$iSkip = self::MIN_SKIP;
		}
		$done = false;
		for($i = ($iSkip - 1); ($i < $dimension) && !$done; $i += $iSkip){
			$stateCount   = $this->getCrossCheckStateCount();
			$currentState = 0;
			for($j = 0; $j < $dimension; $j++){
				if($this->matrix->check($j, $i)){
					if(($currentState & 1) === 1){
						$currentState++;
					}
					$stateCount[$currentState]++;
				}
				else{
					if(($currentState & 1) === 0){
						if($currentState === 4){
							if($this->foundPatternCross($stateCount)){
								$confirmed = $this->handlePossibleCenter($stateCount, $i, $j);
								if($confirmed){
									$iSkip = 3;
									if($this->hasSkipped){
										$done = $this->haveMultiplyConfirmedCenters();
									}
									else{
										$rowSkip = $this->findRowSkip();
										if($rowSkip > $stateCount[2]){
											$i += ($rowSkip - $stateCount[2] - $iSkip);
											$j = ($dimension - 1);
										}
									}
								}
								else{
									$stateCount   = $this->doShiftCounts2($stateCount);
									$currentState = 3;
									continue;
								}
								$currentState = 0;
								$stateCount   = $this->getCrossCheckStateCount();
							}
							else{
								$stateCount   = $this->doShiftCounts2($stateCount);
								$currentState = 3;
							}
						}
						else{
							$stateCount[++$currentState]++;
						}
					}
					else{
						$stateCount[$currentState]++;
					}
				}
			}
			if($this->foundPatternCross($stateCount)){
				$confirmed = $this->handlePossibleCenter($stateCount, $i, $dimension);
				if($confirmed){
					$iSkip = $stateCount[0];
					if($this->hasSkipped){
						$done = $this->haveMultiplyConfirmedCenters();
					}
				}
			}
		}
		return $this->orderBestPatterns($this->selectBestPatterns());
	}
	private function getCrossCheckStateCount():array{
		return [0, 0, 0, 0, 0];
	}
	private function doShiftCounts2(array $stateCount):array{
		$stateCount[0] = $stateCount[2];
		$stateCount[1] = $stateCount[3];
		$stateCount[2] = $stateCount[4];
		$stateCount[3] = 1;
		$stateCount[4] = 0;
		return $stateCount;
	}
	private function centerFromEnd(array $stateCount, int $end):float{
		return (float)(($end - $stateCount[4] - $stateCount[3]) - $stateCount[2] / 2);
	}
	private function foundPatternCross(array $stateCount):bool{
		return $this->foundPatternVariance($stateCount, 2.0);
	}
	private function foundPatternDiagonal(array $stateCount):bool{
		return $this->foundPatternVariance($stateCount, 1.333);
	}
	private function foundPatternVariance(array $stateCount, float $variance):bool{
		$totalModuleSize = 0;
		for($i = 0; $i < 5; $i++){
			$count = $stateCount[$i];
			if($count === 0){
				return false;
			}
			$totalModuleSize += $count;
		}
		if($totalModuleSize < 7){
			return false;
		}
		$moduleSize  = ($totalModuleSize / 7.0);
		$maxVariance = ($moduleSize / $variance);
		return
			abs($moduleSize - $stateCount[0]) < $maxVariance
			&& abs($moduleSize - $stateCount[1]) < $maxVariance
			&& abs(3.0 * $moduleSize - $stateCount[2]) < (3 * $maxVariance)
			&& abs($moduleSize - $stateCount[3]) < $maxVariance
			&& abs($moduleSize - $stateCount[4]) < $maxVariance;
	}
	private function crossCheckDiagonal(int $centerI, int $centerJ):bool{
		$stateCount = $this->getCrossCheckStateCount();
		$i = 0;
		while($centerI >= $i && $centerJ >= $i && $this->matrix->check(($centerJ - $i), ($centerI - $i))){
			$stateCount[2]++;
			$i++;
		}
		if($stateCount[2] === 0){
			return false;
		}
		while($centerI >= $i && $centerJ >= $i && !$this->matrix->check(($centerJ - $i), ($centerI - $i))){
			$stateCount[1]++;
			$i++;
		}
		if($stateCount[1] === 0){
			return false;
		}
		while($centerI >= $i && $centerJ >= $i && $this->matrix->check(($centerJ - $i), ($centerI - $i))){
			$stateCount[0]++;
			$i++;
		}
		if($stateCount[0] === 0){
			return false;
		}
		$dimension = $this->matrix->getSize();
		$i = 1;
		while(($centerI + $i) < $dimension && ($centerJ + $i) < $dimension && $this->matrix->check(($centerJ + $i), ($centerI + $i))){
			$stateCount[2]++;
			$i++;
		}
		while(($centerI + $i) < $dimension && ($centerJ + $i) < $dimension && !$this->matrix->check(($centerJ + $i), ($centerI + $i))){
			$stateCount[3]++;
			$i++;
		}
		if($stateCount[3] === 0){
			return false;
		}
		while(($centerI + $i) < $dimension && ($centerJ + $i) < $dimension && $this->matrix->check(($centerJ + $i), ($centerI + $i))){
			$stateCount[4]++;
			$i++;
		}
		if($stateCount[4] === 0){
			return false;
		}
		return $this->foundPatternDiagonal($stateCount);
	}
	private function crossCheckVertical(int $startI, int $centerJ, int $maxCount, int $originalStateCountTotal):?float{
		$maxI       = $this->matrix->getSize();
		$stateCount = $this->getCrossCheckStateCount();
		$i = $startI;
		while($i >= 0 && $this->matrix->check($centerJ, $i)){
			$stateCount[2]++;
			$i--;
		}
		if($i < 0){
			return null;
		}
		while($i >= 0 && !$this->matrix->check($centerJ, $i) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$i--;
		}
		if($i < 0 || $stateCount[1] > $maxCount){
			return null;
		}
		while($i >= 0 && $this->matrix->check($centerJ, $i) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$i--;
		}
		if($stateCount[0] > $maxCount){
			return null;
		}
		$i = ($startI + 1);
		while($i < $maxI && $this->matrix->check($centerJ, $i)){
			$stateCount[2]++;
			$i++;
		}
		if($i === $maxI){
			return null;
		}
		while($i < $maxI && !$this->matrix->check($centerJ, $i) && $stateCount[3] < $maxCount){
			$stateCount[3]++;
			$i++;
		}
		if($i === $maxI || $stateCount[3] >= $maxCount){
			return null;
		}
		while($i < $maxI && $this->matrix->check($centerJ, $i) && $stateCount[4] < $maxCount){
			$stateCount[4]++;
			$i++;
		}
		if($stateCount[4] >= $maxCount){
			return null;
		}
		$stateCountTotal = ($stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4]);
		if((5 * abs($stateCountTotal - $originalStateCountTotal)) >= (2 * $originalStateCountTotal)){
			return null;
		}
		if(!$this->foundPatternCross($stateCount)){
			return null;
		}
		return $this->centerFromEnd($stateCount, $i);
	}
	private function crossCheckHorizontal(int $startJ, int $centerI, int $maxCount, int $originalStateCountTotal):?float{
		$maxJ       = $this->matrix->getSize();
		$stateCount = $this->getCrossCheckStateCount();
		$j = $startJ;
		while($j >= 0 && $this->matrix->check($j, $centerI)){
			$stateCount[2]++;
			$j--;
		}
		if($j < 0){
			return null;
		}
		while($j >= 0 && !$this->matrix->check($j, $centerI) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$j--;
		}
		if($j < 0 || $stateCount[1] > $maxCount){
			return null;
		}
		while($j >= 0 && $this->matrix->check($j, $centerI) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$j--;
		}
		if($stateCount[0] > $maxCount){
			return null;
		}
		$j = ($startJ + 1);
		while($j < $maxJ && $this->matrix->check($j, $centerI)){
			$stateCount[2]++;
			$j++;
		}
		if($j === $maxJ){
			return null;
		}
		while($j < $maxJ && !$this->matrix->check($j, $centerI) && $stateCount[3] < $maxCount){
			$stateCount[3]++;
			$j++;
		}
		if($j === $maxJ || $stateCount[3] >= $maxCount){
			return null;
		}
		while($j < $maxJ && $this->matrix->check($j, $centerI) && $stateCount[4] < $maxCount){
			$stateCount[4]++;
			$j++;
		}
		if($stateCount[4] >= $maxCount){
			return null;
		}
		$stateCountTotal = ($stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4]);
		if((5 * abs($stateCountTotal - $originalStateCountTotal)) >= $originalStateCountTotal){
			return null;
		}
		if(!$this->foundPatternCross($stateCount)){
			return null;
		}
		return $this->centerFromEnd($stateCount, $j);
	}
	private function handlePossibleCenter(array $stateCount, int $i, int $j):bool{
		$stateCountTotal = ($stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4]);
		$centerJ         = $this->centerFromEnd($stateCount, $j);
		$centerI         = $this->crossCheckVertical($i, (int)$centerJ, $stateCount[2], $stateCountTotal);
		if($centerI !== null){
			$centerJ = $this->crossCheckHorizontal((int)$centerJ, (int)$centerI, $stateCount[2], $stateCountTotal);
			if($centerJ !== null && ($this->crossCheckDiagonal((int)$centerI, (int)$centerJ))){
				$estimatedModuleSize = ($stateCountTotal / 7.0);
				$found               = false;
				$count = count($this->possibleCenters);
				for($index = 0; $index < $count; $index++){
					$center = $this->possibleCenters[$index];
					if($center->aboutEquals($estimatedModuleSize, $centerI, $centerJ)){
						$this->possibleCenters[$index] = $center->combineEstimate($centerI, $centerJ, $estimatedModuleSize);
						$found                         = true;
						break;
					}
				}
				if(!$found){
					$point                   = new FinderPattern($centerJ, $centerI, $estimatedModuleSize);
					$this->possibleCenters[] = $point;
				}
				return true;
			}
		}
		return false;
	}
	private function findRowSkip():int{
		$max = count($this->possibleCenters);
		if($max <= 1){
			return 0;
		}
		$firstConfirmedCenter = null;
		foreach($this->possibleCenters as $center){
			if($center->getCount() >= self::CENTER_QUORUM){
				if($firstConfirmedCenter === null){
					$firstConfirmedCenter = $center;
				}
				else{
					$this->hasSkipped = true;
					return (int)((abs($firstConfirmedCenter->getX() - $center->getX()) -
					              abs($firstConfirmedCenter->getY() - $center->getY())) / 2);
				}
			}
		}
		return 0;
	}
	private function haveMultiplyConfirmedCenters():bool{
		$confirmedCount  = 0;
		$totalModuleSize = 0.0;
		$max             = count($this->possibleCenters);
		foreach($this->possibleCenters as $pattern){
			if($pattern->getCount() >= self::CENTER_QUORUM){
				$confirmedCount++;
				$totalModuleSize += $pattern->getEstimatedModuleSize();
			}
		}
		if($confirmedCount < 3){
			return false;
		}
		$average        = ($totalModuleSize / (float)$max);
		$totalDeviation = 0.0;
		foreach($this->possibleCenters as $pattern){
			$totalDeviation += abs($pattern->getEstimatedModuleSize() - $average);
		}
		return $totalDeviation <= (0.05 * $totalModuleSize);
	}
	private function selectBestPatterns():array{
		$startSize = count($this->possibleCenters);
		if($startSize < 3){
			throw new QRCodeDetectorException('could not find enough finder patterns');
		}
		usort(
			$this->possibleCenters,
			fn(FinderPattern $a, FinderPattern $b) => ($a->getEstimatedModuleSize() <=> $b->getEstimatedModuleSize())
		);
		$distortion   = PHP_FLOAT_MAX;
		$bestPatterns = [];
		for($i = 0; $i < ($startSize - 2); $i++){
			$fpi           = $this->possibleCenters[$i];
			$minModuleSize = $fpi->getEstimatedModuleSize();
			for($j = ($i + 1); $j < ($startSize - 1); $j++){
				$fpj      = $this->possibleCenters[$j];
				$squares0 = $fpi->getSquaredDistance($fpj);
				for($k = ($j + 1); $k < $startSize; $k++){
					$fpk           = $this->possibleCenters[$k];
					$maxModuleSize = $fpk->getEstimatedModuleSize();
					if($maxModuleSize > ($minModuleSize * 1.4)){
						continue;
					}
					$a = $squares0;
					$b = $fpj->getSquaredDistance($fpk);
					$c = $fpi->getSquaredDistance($fpk);
					if($a < $b){
						if($b > $c){
							if($a < $c){
								$temp = $b;
								$b    = $c;
								$c    = $temp;
							}
							else{
								$temp = $a;
								$a    = $c;
								$c    = $b;
								$b    = $temp;
							}
						}
					}
					else{
						if($b < $c){
							if($a < $c){
								$temp = $a;
								$a    = $b;
								$b    = $temp;
							}
							else{
								$temp = $a;
								$a    = $b;
								$b    = $c;
								$c    = $temp;
							}
						}
						else{
							$temp = $a;
							$a    = $c;
							$c    = $temp;
						}
					}
					$d = (abs($c - 2 * $b) + abs($c - 2 * $a));
					if($d < $distortion){
						$distortion   = $d;
						$bestPatterns = [$fpi, $fpj, $fpk];
					}
				}
			}
		}
		if($distortion === PHP_FLOAT_MAX){
			throw new QRCodeDetectorException('finder patterns may be too distorted');
		}
		return $bestPatterns;
	}
	private function orderBestPatterns(array $patterns):array{
		$zeroOneDistance = $patterns[0]->getDistance($patterns[1]);
		$oneTwoDistance  = $patterns[1]->getDistance($patterns[2]);
		$zeroTwoDistance = $patterns[0]->getDistance($patterns[2]);
		if($oneTwoDistance >= $zeroOneDistance && $oneTwoDistance >= $zeroTwoDistance){
			[$pointB, $pointA, $pointC] = $patterns;
		}
		elseif($zeroTwoDistance >= $oneTwoDistance && $zeroTwoDistance >= $zeroOneDistance){
			[$pointA, $pointB, $pointC] = $patterns;
		}
		else{
			[$pointA, $pointC, $pointB] = $patterns;
		}
		if($this->crossProductZ($pointA, $pointB, $pointC) < 0.0){
			$temp   = $pointA;
			$pointA = $pointC;
			$pointC = $temp;
		}
		return [$pointA, $pointB, $pointC];
	}
	private function crossProductZ(FinderPattern $pointA, FinderPattern $pointB, FinderPattern $pointC):float{
		$bX = $pointB->getX();
		$bY = $pointB->getY();
		return ((($pointC->getX() - $bX) * ($pointA->getY() - $bY)) - (($pointC->getY() - $bY) * ($pointA->getX() - $bX)));
	}
}