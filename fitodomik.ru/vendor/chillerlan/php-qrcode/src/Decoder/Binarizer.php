<?php
namespace chillerlan\QRCode\Decoder;
use chillerlan\QRCode\Common\LuminanceSourceInterface;
use chillerlan\QRCode\Data\QRMatrix;
use function array_fill, count, intdiv, max;
final class Binarizer{
	private const BLOCK_SIZE_POWER  = 3;
	private const BLOCK_SIZE        = 8;
	private const BLOCK_SIZE_MASK   = 7;
	private const MINIMUM_DIMENSION = 40;
	private const MIN_DYNAMIC_RANGE = 24;
	private const LUMINANCE_SHIFT   = 3;
	private const LUMINANCE_BUCKETS = 32;
	private LuminanceSourceInterface $source;
	private array                    $luminances;
	public function __construct(LuminanceSourceInterface $source){
		$this->source     = $source;
		$this->luminances = $this->source->getLuminances();
	}
	private function estimateBlackPoint(array $buckets):int{
		$numBuckets     = count($buckets);
		$maxBucketCount = 0;
		$firstPeak      = 0;
		$firstPeakSize  = 0;
		for($x = 0; $x < $numBuckets; $x++){
			if($buckets[$x] > $firstPeakSize){
				$firstPeak     = $x;
				$firstPeakSize = $buckets[$x];
			}
			if($buckets[$x] > $maxBucketCount){
				$maxBucketCount = $buckets[$x];
			}
		}
		$secondPeak      = 0;
		$secondPeakScore = 0;
		for($x = 0; $x < $numBuckets; $x++){
			$distanceToBiggest = ($x - $firstPeak);
			$score = ($buckets[$x] * $distanceToBiggest * $distanceToBiggest);
			if($score > $secondPeakScore){
				$secondPeak      = $x;
				$secondPeakScore = $score;
			}
		}
		if($firstPeak > $secondPeak){
			$temp       = $firstPeak;
			$firstPeak  = $secondPeak;
			$secondPeak = $temp;
		}
		if(($secondPeak - $firstPeak) <= ($numBuckets / 16)){
			throw new QRCodeDecoderException('no meaningful dark point found');
		}
		$bestValley      = ($secondPeak - 1);
		$bestValleyScore = -1;
		for($x = ($secondPeak - 1); $x > $firstPeak; $x--){
			$fromFirst = ($x - $firstPeak);
			$score     = ($fromFirst * $fromFirst * ($secondPeak - $x) * ($maxBucketCount - $buckets[$x]));
			if($score > $bestValleyScore){
				$bestValley      = $x;
				$bestValleyScore = $score;
			}
		}
		return ($bestValley << self::LUMINANCE_SHIFT);
	}
	public function getBlackMatrix():BitMatrix{
		$width  = $this->source->getWidth();
		$height = $this->source->getHeight();
		if($width >= self::MINIMUM_DIMENSION && $height >= self::MINIMUM_DIMENSION){
			$subWidth = ($width >> self::BLOCK_SIZE_POWER);
			if(($width & self::BLOCK_SIZE_MASK) !== 0){
				$subWidth++;
			}
			$subHeight = ($height >> self::BLOCK_SIZE_POWER);
			if(($height & self::BLOCK_SIZE_MASK) !== 0){
				$subHeight++;
			}
			return $this->calculateThresholdForBlock($subWidth, $subHeight, $width, $height);
		}
		return $this->getHistogramBlackMatrix($width, $height);
	}
	private function getHistogramBlackMatrix(int $width, int $height):BitMatrix{
		$buckets = array_fill(0, self::LUMINANCE_BUCKETS, 0);
		$right   = intdiv(($width * 4), 5);
		$x       = intdiv($width, 5);
		for($y = 1; $y < 5; $y++){
			$row             = intdiv(($height * $y), 5);
			$localLuminances = $this->source->getRow($row);
			for(; $x < $right; $x++){
				$pixel = ($localLuminances[$x] & 0xff);
				$buckets[($pixel >> self::LUMINANCE_SHIFT)]++;
			}
		}
		$blackPoint = $this->estimateBlackPoint($buckets);
		$matrix = new BitMatrix(max($width, $height));
		for($y = 0; $y < $height; $y++){
			$offset = ($y * $width);
			for($x = 0; $x < $width; $x++){
				$matrix->set($x, $y, (($this->luminances[($offset + $x)] & 0xff) < $blackPoint), QRMatrix::M_DATA);
			}
		}
		return $matrix;
	}
	private function calculateBlackPoints(int $subWidth, int $subHeight, int $width, int $height):array{
		$blackPoints = array_fill(0, $subHeight, array_fill(0, $subWidth, 0));
		for($y = 0; $y < $subHeight; $y++){
			$yoffset    = ($y << self::BLOCK_SIZE_POWER);
			$maxYOffset = ($height - self::BLOCK_SIZE);
			if($yoffset > $maxYOffset){
				$yoffset = $maxYOffset;
			}
			for($x = 0; $x < $subWidth; $x++){
				$xoffset    = ($x << self::BLOCK_SIZE_POWER);
				$maxXOffset = ($width - self::BLOCK_SIZE);
				if($xoffset > $maxXOffset){
					$xoffset = $maxXOffset;
				}
				$sum = 0;
				$min = 255;
				$max = 0;
				for($yy = 0, $offset = ($yoffset * $width + $xoffset); $yy < self::BLOCK_SIZE; $yy++, $offset += $width){
					for($xx = 0; $xx < self::BLOCK_SIZE; $xx++){
						$pixel = ((int)($this->luminances[(int)($offset + $xx)]) & 0xff);
						$sum   += $pixel;
						if($pixel < $min){
							$min = $pixel;
						}
						if($pixel > $max){
							$max = $pixel;
						}
					}
					if(($max - $min) > self::MIN_DYNAMIC_RANGE){
						for($yy++, $offset += $width; $yy < self::BLOCK_SIZE; $yy++, $offset += $width){
							for($xx = 0; $xx < self::BLOCK_SIZE; $xx++){
								$sum += ((int)($this->luminances[(int)($offset + $xx)]) & 0xff);
							}
						}
					}
				}
				$average = ($sum >> (self::BLOCK_SIZE_POWER * 2));
				if(($max - $min) <= self::MIN_DYNAMIC_RANGE){
					$average = ($min / 2);
					if($y > 0 && $x > 0){
						$averageNeighborBlackPoint = (
							($blackPoints[($y - 1)][$x] + (2 * $blackPoints[$y][($x - 1)]) + $blackPoints[($y - 1)][($x - 1)]) / 4
						);
						if($min < $averageNeighborBlackPoint){
							$average = $averageNeighborBlackPoint;
						}
					}
				}
				$blackPoints[$y][$x] = $average;
			}
		}
		return $blackPoints;
	}
	private function calculateThresholdForBlock(int $subWidth, int $subHeight, int $width, int $height):BitMatrix{
		$matrix      = new BitMatrix(max($width, $height));
		$blackPoints = $this->calculateBlackPoints($subWidth, $subHeight, $width, $height);
		for($y = 0; $y < $subHeight; $y++){
			$yoffset    = ($y << self::BLOCK_SIZE_POWER);
			$maxYOffset = ($height - self::BLOCK_SIZE);
			if($yoffset > $maxYOffset){
				$yoffset = $maxYOffset;
			}
			for($x = 0; $x < $subWidth; $x++){
				$xoffset    = ($x << self::BLOCK_SIZE_POWER);
				$maxXOffset = ($width - self::BLOCK_SIZE);
				if($xoffset > $maxXOffset){
					$xoffset = $maxXOffset;
				}
				$left = $this->cap($x, 2, ($subWidth - 3));
				$top  = $this->cap($y, 2, ($subHeight - 3));
				$sum  = 0;
				for($z = -2; $z <= 2; $z++){
					$br   = $blackPoints[($top + $z)];
					$sum += ($br[($left - 2)] + $br[($left - 1)] + $br[$left] + $br[($left + 1)] + $br[($left + 2)]);
				}
				$average = (int)($sum / 25);
				for($j = 0, $o = ($yoffset * $width + $xoffset); $j < self::BLOCK_SIZE; $j++, $o += $width){
					for($i = 0; $i < self::BLOCK_SIZE; $i++){
						$v = (((int)($this->luminances[($o + $i)]) & 0xff) <= $average);
						$matrix->set(($xoffset + $i), ($yoffset + $j), $v, QRMatrix::M_DATA);
					}
				}
			}
		}
		return $matrix;
	}
	private function cap(int $value, int $min, int $max):int{
		if($value < $min){
			return $min;
		}
		if($value > $max){
			return $max;
		}
		return $value;
	}
}