<?php
namespace chillerlan\QRCode\Common;
use chillerlan\QRCode\QRCodeException;
use chillerlan\QRCode\Data\QRMatrix;
use Closure;
use function abs, array_column, array_search, intdiv, min;
final class MaskPattern{
	public const AUTO = -1;
	public const PATTERN_000 = 0b000;
	public const PATTERN_001 = 0b001;
	public const PATTERN_010 = 0b010;
	public const PATTERN_011 = 0b011;
	public const PATTERN_100 = 0b100;
	public const PATTERN_101 = 0b101;
	public const PATTERN_110 = 0b110;
	public const PATTERN_111 = 0b111;
	public const PATTERNS = [
		self::PATTERN_000,
		self::PATTERN_001,
		self::PATTERN_010,
		self::PATTERN_011,
		self::PATTERN_100,
		self::PATTERN_101,
		self::PATTERN_110,
		self::PATTERN_111,
	];
	private const PENALTY_N1 = 3;
	private const PENALTY_N2 = 3;
	private const PENALTY_N3 = 40;
	private const PENALTY_N4 = 10;
	private int $maskPattern;
	public function __construct(int $maskPattern){
		if(($maskPattern & 0b111) !== $maskPattern){
			throw new QRCodeException('invalid mask pattern');
		}
		$this->maskPattern = $maskPattern;
	}
	public function getPattern():int{
		return $this->maskPattern;
	}
	public function getMask():Closure{
		return [
			self::PATTERN_000 => fn(int $x, int $y):bool => (($x + $y) % 2) === 0,
			self::PATTERN_001 => fn(int $x, int $y):bool => ($y % 2) === 0,
			self::PATTERN_010 => fn(int $x, int $y):bool => ($x % 3) === 0,
			self::PATTERN_011 => fn(int $x, int $y):bool => (($x + $y) % 3) === 0,
			self::PATTERN_100 => fn(int $x, int $y):bool => ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0,
			self::PATTERN_101 => fn(int $x, int $y):bool => (($x * $y) % 6) === 0,
			self::PATTERN_110 => fn(int $x, int $y):bool => (($x * $y) % 6) < 3,
			self::PATTERN_111 => fn(int $x, int $y):bool => (($x + $y + (($x * $y) % 3)) % 2) === 0,
		][$this->maskPattern];
	}
	public static function getBestPattern(QRMatrix $QRMatrix):self{
		$penalties = [];
		$size      = $QRMatrix->getSize();
		foreach(self::PATTERNS as $pattern){
			$mp      = new self($pattern);
			$matrix  = (clone $QRMatrix)->setFormatInfo($mp)->mask($mp)->getMatrix(true);
			$penalty = 0;
			for($level = 1; $level <= 4; $level++){
				$penalty += self::{'testRule'.$level}($matrix, $size, $size);
			}
			$penalties[$pattern] = (int)$penalty;
		}
		return new self(array_search(min($penalties), $penalties, true));
	}
	public static function testRule1(array $matrix, int $height, int $width):int{
		$penalty = 0;
		foreach($matrix as $row){
			$penalty += self::applyRule1($row);
		}
		for($x = 0; $x < $width; $x++){
			$penalty += self::applyRule1(array_column($matrix, $x));
		}
		return $penalty;
	}
	private static function applyRule1(array $rc):int{
		$penalty         = 0;
		$numSameBitCells = 0;
		$prevBit         = null;
		foreach($rc as $val){
			if($val === $prevBit){
				$numSameBitCells++;
			}
			else{
				if($numSameBitCells >= 5){
					$penalty += (self::PENALTY_N1 + $numSameBitCells - 5);
				}
				$numSameBitCells = 1;
				$prevBit         = $val;
			}
		}
		if($numSameBitCells >= 5){
			$penalty += (self::PENALTY_N1 + $numSameBitCells - 5);
		}
		return $penalty;
	}
	public static function testRule2(array $matrix, int $height, int $width):int{
		$penalty = 0;
		foreach($matrix as $y => $row){
			if($y > ($height - 2)){
				break;
			}
			foreach($row as $x => $val){
				if($x > ($width - 2)){
					break;
				}
				if(
					   $val === $row[($x + 1)]
					&& $val === $matrix[($y + 1)][$x]
					&& $val === $matrix[($y + 1)][($x + 1)]
				){
					$penalty++;
				}
			}
		}
		return (self::PENALTY_N2 * $penalty);
	}
	public static function testRule3(array $matrix, int $height, int $width):int{
		$penalties = 0;
		foreach($matrix as $y => $row){
			foreach($row as $x => $val){
				if(
					($x + 6) < $width
					&&  $val
					&& !$row[($x + 1)]
					&&  $row[($x + 2)]
					&&  $row[($x + 3)]
					&&  $row[($x + 4)]
					&& !$row[($x + 5)]
					&&  $row[($x + 6)]
					&& (
						   self::isWhiteHorizontal($row, $width, ($x - 4), $x)
						|| self::isWhiteHorizontal($row, $width, ($x + 7), ($x + 11))
					)
				){
					$penalties++;
				}
				if(
					($y + 6) < $height
					&&  $val
					&& !$matrix[($y + 1)][$x]
					&&  $matrix[($y + 2)][$x]
					&&  $matrix[($y + 3)][$x]
					&&  $matrix[($y + 4)][$x]
					&& !$matrix[($y + 5)][$x]
					&&  $matrix[($y + 6)][$x]
					&& (
						   self::isWhiteVertical($matrix, $height, $x, ($y - 4), $y)
						|| self::isWhiteVertical($matrix, $height, $x, ($y + 7), ($y + 11))
					)
				){
					$penalties++;
				}
			}
		}
		return ($penalties * self::PENALTY_N3);
	}
	private static function isWhiteHorizontal(array $row, int $width, int $from, int $to):bool{
		if($from < 0 || $width < $to){
			return false;
		}
		for($x = $from; $x < $to; $x++){
			if($row[$x]){
				return false;
			}
		}
		return true;
	}
	private static function isWhiteVertical(array $matrix, int $height, int $x, int $from, int $to):bool{
		if($from < 0 || $height < $to){
			return false;
		}
		for($y = $from; $y < $to; $y++){
			if($matrix[$y][$x] === true){
				return false;
			}
		}
		return true;
	}
	public static function testRule4(array $matrix, int $height, int $width):int{
		$darkCells  = 0;
		$totalCells = ($height * $width);
		foreach($matrix as $row){
			foreach($row as $val){
				if($val === true){
					$darkCells++;
				}
			}
		}
		return (intdiv((abs($darkCells * 2 - $totalCells) * 10), $totalCells) * self::PENALTY_N4);
	}
}