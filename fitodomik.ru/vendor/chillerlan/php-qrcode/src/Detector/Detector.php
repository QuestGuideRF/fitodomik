<?php
namespace chillerlan\QRCode\Detector;
use chillerlan\QRCode\Common\{LuminanceSourceInterface, Version};
use chillerlan\QRCode\Decoder\{Binarizer, BitMatrix};
use function abs, intdiv, is_nan, max, min, round;
use const NAN;
final class Detector{
	private BitMatrix $matrix;
	private array     $finderPatterns = [];
	public function __construct(LuminanceSourceInterface $source){
		$this->matrix = (new Binarizer($source))->getBlackMatrix();
	}
	public function getFinderPatterns():array{
		return $this->finderPatterns;
	}
	public function detect():BitMatrix{
		$this->finderPatterns = (new FinderPatternFinder($this->matrix))->find();
		[$bottomLeft, $topLeft, $topRight] = $this->finderPatterns;
		$moduleSize         = $this->calculateModuleSize($topLeft, $topRight, $bottomLeft);
		$dimension          = $this->computeDimension($topLeft, $topRight, $bottomLeft, $moduleSize);
		$provisionalVersion = new Version(intdiv(($dimension - 17), 4));
		$alignmentPattern   = null;
		if($provisionalVersion->getAlignmentPattern() !== []){
			$bottomRightX = ($topRight->getX() - $topLeft->getX() + $bottomLeft->getX());
			$bottomRightY = ($topRight->getY() - $topLeft->getY() + $bottomLeft->getY());
			$correctionToTopLeft = (1.0 - 3.0 / (float)($provisionalVersion->getDimension() - 7));
			$estAlignmentX       = (int)($topLeft->getX() + $correctionToTopLeft * ($bottomRightX - $topLeft->getX()));
			$estAlignmentY       = (int)($topLeft->getY() + $correctionToTopLeft * ($bottomRightY - $topLeft->getY()));
			for($i = 4; $i <= 16; $i <<= 1){
				$alignmentPattern = $this->findAlignmentInRegion($moduleSize, $estAlignmentX, $estAlignmentY, (float)$i);
				if($alignmentPattern !== null){
					break;
				}
			}
		}
		$transform = $this->createTransform($topLeft, $topRight, $bottomLeft, $dimension, $alignmentPattern);
		return (new GridSampler)->sampleGrid($this->matrix, $dimension, $transform);
	}
	private function calculateModuleSize(FinderPattern $topLeft, FinderPattern $topRight, FinderPattern $bottomLeft):float{
		$moduleSize = ((
			$this->calculateModuleSizeOneWay($topLeft, $topRight) +
			$this->calculateModuleSizeOneWay($topLeft, $bottomLeft)
		) / 2.0);
		if($moduleSize < 1.0){
			throw new QRCodeDetectorException('module size < 1.0');
		}
		return $moduleSize;
	}
	private function calculateModuleSizeOneWay(FinderPattern $a, FinderPattern $b):float{
		$moduleSizeEst1 = $this->sizeOfBlackWhiteBlackRunBothWays($a->getX(), $a->getY(), $b->getX(), $b->getY());
		$moduleSizeEst2 = $this->sizeOfBlackWhiteBlackRunBothWays($b->getX(), $b->getY(), $a->getX(), $a->getY());
		if(is_nan($moduleSizeEst1)){
			return ($moduleSizeEst2 / 7.0);
		}
		if(is_nan($moduleSizeEst2)){
			return ($moduleSizeEst1 / 7.0);
		}
		return (($moduleSizeEst1 + $moduleSizeEst2) / 14.0);
	}
	private function sizeOfBlackWhiteBlackRunBothWays(float $fromX, float $fromY, float $toX, float $toY):float{
		$result    = $this->sizeOfBlackWhiteBlackRun((int)$fromX, (int)$fromY, (int)$toX, (int)$toY);
		$dimension = $this->matrix->getSize();
		$scale     = 1.0;
		$otherToX  = ($fromX - ($toX - $fromX));
		if($otherToX < 0){
			$scale    = ($fromX / ($fromX - $otherToX));
			$otherToX = 0;
		}
		elseif($otherToX >= $dimension){
			$scale    = (($dimension - 1 - $fromX) / ($otherToX - $fromX));
			$otherToX = ($dimension - 1);
		}
		$otherToY = (int)($fromY - ($toY - $fromY) * $scale);
		$scale    = 1.0;
		if($otherToY < 0){
			$scale    = ($fromY / ($fromY - $otherToY));
			$otherToY = 0;
		}
		elseif($otherToY >= $dimension){
			$scale    = (($dimension - 1 - $fromY) / ($otherToY - $fromY));
			$otherToY = ($dimension - 1);
		}
		$otherToX = (int)($fromX + ($otherToX - $fromX) * $scale);
		$result   += $this->sizeOfBlackWhiteBlackRun((int)$fromX, (int)$fromY, $otherToX, $otherToY);
		return ($result - 1.0);
	}
	private function sizeOfBlackWhiteBlackRun(int $fromX, int $fromY, int $toX, int $toY):float{
		$steep = abs($toY - $fromY) > abs($toX - $fromX);
		if($steep){
			$temp  = $fromX;
			$fromX = $fromY;
			$fromY = $temp;
			$temp  = $toX;
			$toX   = $toY;
			$toY   = $temp;
		}
		$dx    = abs($toX - $fromX);
		$dy    = abs($toY - $fromY);
		$error = (-$dx / 2);
		$xstep = (($fromX < $toX) ? 1 : -1);
		$ystep = (($fromY < $toY) ? 1 : -1);
		$state  = 0;
		$xLimit = ($toX + $xstep);
		for($x = $fromX, $y = $fromY; $x !== $xLimit; $x += $xstep){
			$realX = ($steep) ? $y : $x;
			$realY = ($steep) ? $x : $y;
			if(($state === 1) === $this->matrix->check($realX, $realY)){
				if($state === 2){
					return FinderPattern::distance($x, $y, $fromX, $fromY);
				}
				$state++;
			}
			$error += $dy;
			if($error > 0){
				if($y === $toY){
					break;
				}
				$y     += $ystep;
				$error -= $dx;
			}
		}
		if($state === 2){
			return FinderPattern::distance(($toX + $xstep), $toY, $fromX, $fromY);
		}
		return NAN;
	}
	private function computeDimension(FinderPattern $nw, FinderPattern $ne, FinderPattern $sw, float $size):int{
		$tltrCentersDimension = (int)round($nw->getDistance($ne) / $size);
		$tlblCentersDimension = (int)round($nw->getDistance($sw) / $size);
		$dimension            = (int)((($tltrCentersDimension + $tlblCentersDimension) / 2) + 7);
		switch($dimension % 4){
			case 0:
				$dimension++;
				break;
			case 2:
				$dimension--;
				break;
			case 3:
				throw new QRCodeDetectorException('estimated dimension: '.$dimension);
		}
		if(($dimension % 4) !== 1){
			throw new QRCodeDetectorException('dimension mod 4 is not 1');
		}
		return $dimension;
	}
	private function findAlignmentInRegion(
		float $overallEstModuleSize,
		int $estAlignmentX,
		int $estAlignmentY,
		float $allowanceFactor
	):?AlignmentPattern{
		$dimension           = $this->matrix->getSize();
		$allowance           = (int)($allowanceFactor * $overallEstModuleSize);
		$alignmentAreaLeftX  = max(0, ($estAlignmentX - $allowance));
		$alignmentAreaRightX = min(($dimension - 1), ($estAlignmentX + $allowance));
		if(($alignmentAreaRightX - $alignmentAreaLeftX) < ($overallEstModuleSize * 3)){
			return null;
		}
		$alignmentAreaTopY    = max(0, ($estAlignmentY - $allowance));
		$alignmentAreaBottomY = min(($dimension - 1), ($estAlignmentY + $allowance));
		if(($alignmentAreaBottomY - $alignmentAreaTopY) < ($overallEstModuleSize * 3)){
			return null;
		}
		return (new AlignmentPatternFinder($this->matrix, $overallEstModuleSize))->find(
			$alignmentAreaLeftX,
			$alignmentAreaTopY,
			($alignmentAreaRightX - $alignmentAreaLeftX),
			($alignmentAreaBottomY - $alignmentAreaTopY),
		);
	}
	private function createTransform(
		FinderPattern     $nw,
		FinderPattern     $ne,
		FinderPattern     $sw,
		int               $size,
		?AlignmentPattern $ap = null
	):PerspectiveTransform{
		$dimMinusThree = ($size - 3.5);
		if($ap instanceof AlignmentPattern){
			$bottomRightX       = $ap->getX();
			$bottomRightY       = $ap->getY();
			$sourceBottomRightX = ($dimMinusThree - 3.0);
			$sourceBottomRightY = $sourceBottomRightX;
		}
		else{
			$bottomRightX       = ($ne->getX() - $nw->getX() + $sw->getX());
			$bottomRightY       = ($ne->getY() - $nw->getY() + $sw->getY());
			$sourceBottomRightX = $dimMinusThree;
			$sourceBottomRightY = $dimMinusThree;
		}
		return (new PerspectiveTransform)->quadrilateralToQuadrilateral(
			3.5,
			3.5,
			$dimMinusThree,
			3.5,
			$sourceBottomRightX,
			$sourceBottomRightY,
			3.5,
			$dimMinusThree,
			$nw->getX(),
			$nw->getY(),
			$ne->getX(),
			$ne->getY(),
			$bottomRightX,
			$bottomRightY,
			$sw->getX(),
			$sw->getY()
		);
	}
}