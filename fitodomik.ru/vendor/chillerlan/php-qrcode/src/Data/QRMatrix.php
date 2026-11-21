<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, EccLevel, MaskPattern, Version};
use function array_fill, array_map, array_reverse, count, intdiv;
class QRMatrix{
	public const IS_DARK            = 0b100000000000;
	public const M_NULL             = 0b000000000000;
	public const M_LOGO             = 0b001000000000;
	public const M_LOGO_DARK        = 0b101000000000;
	public const M_DATA             = 0b000000000010;
	public const M_FINDER           = 0b000000000100;
	public const M_SEPARATOR        = 0b000000001000;
	public const M_ALIGNMENT        = 0b000000010000;
	public const M_TIMING           = 0b000000100000;
	public const M_FORMAT           = 0b000001000000;
	public const M_VERSION          = 0b000010000000;
	public const M_QUIETZONE        = 0b000100000000;
	public const M_DARKMODULE       = 0b100000000001;
	public const M_DATA_DARK        = 0b100000000010;
	public const M_FINDER_DARK      = 0b100000000100;
	public const M_ALIGNMENT_DARK   = 0b100000010000;
	public const M_TIMING_DARK      = 0b100000100000;
	public const M_FORMAT_DARK      = 0b100001000000;
	public const M_VERSION_DARK     = 0b100010000000;
	public const M_FINDER_DOT       = 0b110000000000;
	public const M_DARKMODULE_LIGHT = 0b000000000001;
	public const M_FINDER_DOT_LIGHT = 0b010000000000;
	public const M_SEPARATOR_DARK   = 0b100000001000;
	public const M_QUIETZONE_DARK   = 0b100100000000;
	protected const neighbours = [
		0b00000001 => [-1, -1],
		0b00000010 => [ 0, -1],
		0b00000100 => [ 1, -1],
		0b00001000 => [ 1,  0],
		0b00010000 => [ 1,  1],
		0b00100000 => [ 0,  1],
		0b01000000 => [-1,  1],
		0b10000000 => [-1,  0],
	];
	protected ?Version $version = null;
	protected ?EccLevel $eccLevel = null;
	protected ?MaskPattern $maskPattern = null;
	protected int $moduleCount;
	protected array $matrix;
	public function __construct(Version $version, EccLevel $eccLevel){
		$this->version     = $version;
		$this->eccLevel    = $eccLevel;
		$this->moduleCount = $this->version->getDimension();
		$this->matrix      = $this->createMatrix($this->moduleCount, $this::M_NULL);
	}
	protected function createMatrix(int $size, int $value):array{
		return array_fill(0, $size, array_fill(0, $size, $value));
	}
	public function initFunctionalPatterns():self{
		return $this
			->setFinderPattern()
			->setSeparators()
			->setAlignmentPattern()
			->setTimingPattern()
			->setDarkModule()
			->setVersionNumber()
			->setFormatInfo()
		;
	}
	public function getMatrix(?bool $boolean = null):array{
		if($boolean !== true){
			return $this->matrix;
		}
		$matrix = $this->matrix;
		foreach($matrix as &$row){
			$row = array_map([$this, 'isDark'], $row);
		}
		return $matrix;
	}
	public function matrix(?bool $boolean = null):array{
		return $this->getMatrix($boolean);
	}
	public function getVersion():?Version{
		return $this->version;
	}
	public function version():?Version{
		return $this->getVersion();
	}
	public function getEccLevel():?EccLevel{
		return $this->eccLevel;
	}
	public function eccLevel():?EccLevel{
		return $this->getEccLevel();
	}
	public function getMaskPattern():?MaskPattern{
		return $this->maskPattern;
	}
	public function maskPattern():?MaskPattern{
		return $this->getMaskPattern();
	}
	public function getSize():int{
		return $this->moduleCount;
	}
	public function size():int{
		return $this->getSize();
	}
	public function get(int $x, int $y):int{
		if(!isset($this->matrix[$y][$x])){
			return -1;
		}
		return $this->matrix[$y][$x];
	}
	public function set(int $x, int $y, bool $value, int $M_TYPE):self{
		if(isset($this->matrix[$y][$x])){
			$M_TYPE &= ~$this::IS_DARK;
			if($value === true){
				$M_TYPE |= $this::IS_DARK;
			}
			$this->matrix[$y][$x] = $M_TYPE;
		}
		return $this;
	}
	public function setArea(int $startX, int $startY, int $width, int $height, bool $value, int $M_TYPE):self{
		for($y = $startY; $y < ($startY + $height); $y++){
			for($x = $startX; $x < ($startX + $width); $x++){
				$this->set($x, $y, $value, $M_TYPE);
			}
		}
		return $this;
	}
	public function flip(int $x, int $y):self{
		if(isset($this->matrix[$y][$x])){
			$this->matrix[$y][$x] ^= $this::IS_DARK;
		}
		return $this;
	}
	public function checkType(int $x, int $y, int $M_TYPE):bool{
		if(isset($this->matrix[$y][$x])){
			return ($this->matrix[$y][$x] & $M_TYPE) === $M_TYPE;
		}
		return false;
	}
	public function checkTypeIn(int $x, int $y, array $M_TYPES):bool{
		foreach($M_TYPES as $type){
			if($this->checkType($x, $y, $type)){
				return true;
			}
		}
		return false;
	}
	public function check(int $x, int $y):bool{
		if(isset($this->matrix[$y][$x])){
			return $this->isDark($this->matrix[$y][$x]);
		}
		return false;
	}
	public function isDark(int $M_TYPE):bool{
		return ($M_TYPE & $this::IS_DARK) === $this::IS_DARK;
	}
	public function checkNeighbours(int $x, int $y, ?int $M_TYPE = null):int{
		$bits = 0;
		foreach($this::neighbours as $bit => [$ix, $iy]){
			$ix += $x;
			$iy += $y;
			if($M_TYPE !== null && !$this->checkType($ix, $iy, $M_TYPE)){
				continue;
			}
			if($this->checkType($ix, $iy, $this::IS_DARK)){
				$bits |= $bit;
			}
		}
		return $bits;
	}
	public function setDarkModule():self{
		$this->set(8, ($this->moduleCount - 8), true, $this::M_DARKMODULE);
		return $this;
	}
	public function setFinderPattern():self{
		$pos = [
			[0, 0],
			[($this->moduleCount - 7), 0],
			[0, ($this->moduleCount - 7)],
		];
		foreach($pos as $c){
			$this
				->setArea( $c[0]     ,  $c[1]     , 7, 7, true, $this::M_FINDER)
				->setArea(($c[0] + 1), ($c[1] + 1), 5, 5, false, $this::M_FINDER)
				->setArea(($c[0] + 2), ($c[1] + 2), 3, 3, true, $this::M_FINDER_DOT)
			;
		}
		return $this;
	}
	public function setSeparators():self{
		$h = [
			[7, 0],
			[($this->moduleCount - 8), 0],
			[7, ($this->moduleCount - 8)],
		];
		$v = [
			[7, 7],
			[($this->moduleCount - 1), 7],
			[7, ($this->moduleCount - 8)],
		];
		for($c = 0; $c < 3; $c++){
			for($i = 0; $i < 8; $i++){
				$this->set( $h[$c][0]      , ($h[$c][1] + $i), false, $this::M_SEPARATOR);
				$this->set(($v[$c][0] - $i),  $v[$c][1]      , false, $this::M_SEPARATOR);
			}
		}
		return $this;
	}
	public function setAlignmentPattern():self{
		$alignmentPattern = $this->version->getAlignmentPattern();
		foreach($alignmentPattern as $y){
			foreach($alignmentPattern as $x){
				if($this->matrix[$y][$x] !== $this::M_NULL){
					continue;
				}
				$this
					->setArea(($x - 2), ($y - 2), 5, 5, true, $this::M_ALIGNMENT)
					->setArea(($x - 1), ($y - 1), 3, 3, false, $this::M_ALIGNMENT)
					->set($x, $y, true, $this::M_ALIGNMENT)
				;
			}
		}
		return $this;
	}
	public function setTimingPattern():self{
		for($i = 8; $i < ($this->moduleCount - 8); $i++){
			if($this->matrix[6][$i] !== $this::M_NULL || $this->matrix[$i][6] !== $this::M_NULL){
				continue;
			}
			$v = ($i % 2) === 0;
			$this->set($i, 6, $v, $this::M_TIMING);
			$this->set(6, $i, $v, $this::M_TIMING);
		}
		return $this;
	}
	public function setVersionNumber():self{
		$bits = $this->version->getVersionPattern();
		if($bits !== null){
			for($i = 0; $i < 18; $i++){
				$a = intdiv($i, 3);
				$b = (($i % 3) + ($this->moduleCount - 8 - 3));
				$v = (($bits >> $i) & 1) === 1;
				$this->set($b, $a, $v, $this::M_VERSION);
				$this->set($a, $b, $v, $this::M_VERSION);
			}
		}
		return $this;
	}
	public function setFormatInfo(?MaskPattern $maskPattern = null):self{
		$this->maskPattern = $maskPattern;
		$bits              = 0;
		if($this->maskPattern instanceof MaskPattern){
			$bits = $this->eccLevel->getformatPattern($this->maskPattern);
		}
		for($i = 0; $i < 15; $i++){
			$v = (($bits >> $i) & 1) === 1;
			if($i < 6){
				$this->set(8, $i, $v, $this::M_FORMAT);
			}
			elseif($i < 8){
				$this->set(8, ($i + 1), $v, $this::M_FORMAT);
			}
			else{
				$this->set(8, ($this->moduleCount - 15 + $i), $v, $this::M_FORMAT);
			}
			if($i < 8){
				$this->set(($this->moduleCount - $i - 1), 8, $v, $this::M_FORMAT);
			}
			elseif($i < 9){
				$this->set(((15 - $i)), 8, $v, $this::M_FORMAT);
			}
			else{
				$this->set((15 - $i - 1), 8, $v, $this::M_FORMAT);
			}
		}
		return $this;
	}
	public function setQuietZone(int $quietZoneSize):self{
		if($quietZoneSize < 1){
			return $this;
		}
		if($this->matrix[($this->moduleCount - 1)][($this->moduleCount - 1)] === $this::M_NULL){
			throw new QRCodeDataException('use only after writing data');
		}
		$newSize   = ($this->moduleCount + ($quietZoneSize * 2));
		$newMatrix = $this->createMatrix($newSize, $this::M_QUIETZONE);
		foreach($this->matrix as $y => $row){
			foreach($row as $x => $val){
				$newMatrix[($y + $quietZoneSize)][($x + $quietZoneSize)] = $val;
			}
		}
		$this->moduleCount = $newSize;
		$this->matrix      = $newMatrix;
		return $this;
	}
	public function rotate90():self{
		$this->matrix = array_map((fn(int ...$a):array => array_reverse($a)), ...$this->matrix);
		return $this;
	}
	public function invert():self{
		foreach($this->matrix as $y => $row){
			foreach($row as $x => $val){
				if($val === $this::M_NULL){
					continue;
				}
				$this->flip($x, $y);
			}
		}
		return $this;
	}
	public function setLogoSpace(int $width, ?int $height = null, ?int $startX = null, ?int $startY = null):self{
		$height ??= $width;
		if($width <= 0 || $height <= 0){
			return $this;
		}
		if($this->eccLevel->getLevel() !== EccLevel::H){
			throw new QRCodeDataException('ECC level "H" required to add logo space');
		}
		$dimension = $this->version->getDimension();
		if($width > $dimension || $height > $dimension){
			throw new QRCodeDataException('logo dimensions exceed matrix size');
		}
		if($startX === null && ($width % 2) === 0){
			$width++;
		}
		if($startY === null && ($height % 2) === 0){
			$height++;
		}
		if(($width * $height) > (int)($dimension * $dimension * 0.25)){
			throw new QRCodeDataException('logo space exceeds the maximum error correction capacity');
		}
		$quietzone = (($this->moduleCount - $dimension) / 2);
		$end       = ($this->moduleCount - $quietzone);
		$startX ??= (($dimension - $width) / 2);
		$startY ??= (($dimension - $height) / 2);
		$endX     = ($quietzone + $startX + $width);
		$endY     = ($quietzone + $startY + $height);
		for($y = ($quietzone + $startY); $y < $endY; $y++){
			for($x = ($quietzone + $startX); $x < $endX; $x++){
				if($x < $quietzone || $y < $quietzone ||$x >= $end || $y >= $end){
					continue;
				}
				$this->set($x, $y, false, $this::M_LOGO);
			}
		}
		return $this;
	}
	public function writeCodewords(BitBuffer $bitBuffer):self{
		$data      = (new ReedSolomonEncoder($this->version, $this->eccLevel))->interleaveEcBytes($bitBuffer);
		$byteCount = count($data);
		$iByte     = 0;
		$iBit      = 7;
		$direction = true;
		for($i = ($this->moduleCount - 1); $i > 0; $i -= 2){
			if($i === 6){
				$i--;
			}
			for($count = 0; $count < $this->moduleCount; $count++){
				$y = $count;
				if($direction){
					$y = ($this->moduleCount - 1 - $count);
				}
				for($col = 0; $col < 2; $col++){
					$x = ($i - $col);
					if($this->matrix[$y][$x] !== $this::M_NULL){
						continue;
					}
					$this->matrix[$y][$x] = $this::M_DATA;
					if($iByte < $byteCount && (($data[$iByte] >> $iBit--) & 1) === 1){
						$this->matrix[$y][$x] |= $this::IS_DARK;
					}
					if($iBit === -1){
						$iByte++;
						$iBit = 7;
					}
				}
			}
			$direction = !$direction;
		}
		return $this;
	}
	public function mask(MaskPattern $maskPattern):self{
		$this->maskPattern = $maskPattern;
		$mask              = $this->maskPattern->getMask();
		foreach($this->matrix as $y => $row){
			foreach($row as $x => $val){
				if(($val & $this::M_DATA) === $this::M_DATA && $mask($x, $y)){
					$this->flip($x, $y);
				}
			}
		}
		return $this;
	}
}