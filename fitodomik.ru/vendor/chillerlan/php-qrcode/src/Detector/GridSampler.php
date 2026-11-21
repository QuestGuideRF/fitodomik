<?php
namespace chillerlan\QRCode\Detector;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Decoder\BitMatrix;
use function array_fill, count, intdiv, sprintf;
final class GridSampler{
	private array $points;
	private function checkAndNudgePoints(int $dimension):void{
		$nudged = true;
		$max    = count($this->points);
		for($offset = 0; $offset < $max && $nudged; $offset += 2){
			$x = (int)$this->points[$offset];
			$y = (int)$this->points[($offset + 1)];
			if($x < -1 || $x > $dimension || $y < -1 || $y > $dimension){
				throw new QRCodeDetectorException(sprintf('checkAndNudgePoints 1, x: %s, y: %s, d: %s', $x, $y, $dimension));
			}
			$nudged = false;
			if($x === -1){
				$this->points[$offset] = 0.0;
				$nudged                = true;
			}
			elseif($x === $dimension){
				$this->points[$offset] = ($dimension - 1);
				$nudged                = true;
			}
			if($y === -1){
				$this->points[($offset + 1)] = 0.0;
				$nudged                      = true;
			}
			elseif($y === $dimension){
				$this->points[($offset + 1)] = ($dimension - 1);
				$nudged                      = true;
			}
		}
		$nudged = true;
		for($offset = ($max - 2); $offset >= 0 && $nudged; $offset -= 2){
			$x = (int)$this->points[$offset];
			$y = (int)$this->points[($offset + 1)];
			if($x < -1 || $x > $dimension || $y < -1 || $y > $dimension){
				throw new QRCodeDetectorException(sprintf('checkAndNudgePoints 2, x: %s, y: %s, d: %s', $x, $y, $dimension));
			}
			$nudged = false;
			if($x === -1){
				$this->points[$offset] = 0.0;
				$nudged                = true;
			}
			elseif($x === $dimension){
				$this->points[$offset] = ($dimension - 1);
				$nudged                = true;
			}
			if($y === -1){
				$this->points[($offset + 1)] = 0.0;
				$nudged                      = true;
			}
			elseif($y === $dimension){
				$this->points[($offset + 1)] = ($dimension - 1);
				$nudged                      = true;
			}
		}
	}
	public function sampleGrid(BitMatrix $matrix, int $dimension, PerspectiveTransform $transform):BitMatrix{
		if($dimension <= 0){
			throw new QRCodeDetectorException('invalid matrix size');
		}
		$bits         = new BitMatrix($dimension);
		$this->points = array_fill(0, (2 * $dimension), 0.0);
		for($y = 0; $y < $dimension; $y++){
			$max    = count($this->points);
			$iValue = ($y + 0.5);
			for($x = 0; $x < $max; $x += 2){
				$this->points[$x]       = (($x / 2) + 0.5);
				$this->points[($x + 1)] = $iValue;
			}
			[$this->points, ] = $transform->transformPoints($this->points);
			$this->checkAndNudgePoints($matrix->getSize());
			for($x = 0; $x < $max; $x += 2){
				$bits->set(
					intdiv($x, 2),
					$y,
					$matrix->check((int)$this->points[$x], (int)$this->points[($x + 1)]),
					QRMatrix::M_DATA
				);
			}
		}
		return $bits;
	}
}