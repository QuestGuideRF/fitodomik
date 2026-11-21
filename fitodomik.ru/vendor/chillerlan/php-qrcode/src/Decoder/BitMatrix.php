<?php
namespace chillerlan\QRCode\Decoder;
use chillerlan\QRCode\Common\{EccLevel, MaskPattern, Version};
use chillerlan\QRCode\Data\{QRCodeDataException, QRMatrix};
use function array_fill, array_reverse, count;
use const PHP_INT_MAX, PHP_INT_SIZE;
final class BitMatrix extends QRMatrix{
	private const DECODE_LOOKUP = [
		0x5412,
		0x5125,
		0x5E7C,
		0x5B4B,
		0x45F9,
		0x40CE,
		0x4F97,
		0x4AA0,
		0x77C4,
		0x72F3,
		0x7DAA,
		0x789D,
		0x662F,
		0x6318,
		0x6C41,
		0x6976,
		0x1689,
		0x13BE,
		0x1CE7,
		0x19D0,
		0x0762,
		0x0255,
		0x0D0C,
		0x083B,
		0x355F,
		0x3068,
		0x3F31,
		0x3A06,
		0x24B4,
		0x2183,
		0x2EDA,
		0x2BED,
	];
	private const FORMAT_INFO_MASK_QR = 0x5412;
	private bool $mirror = false;
	public function __construct(int $dimension){
		$this->moduleCount = $dimension;
		$this->matrix      = array_fill(0, $this->moduleCount, array_fill(0, $this->moduleCount, $this::M_NULL));
	}
	public function resetVersionInfo():self{
		$this->version     = null;
		$this->eccLevel    = null;
		$this->maskPattern = null;
		return $this;
	}
	public function mirrorDiagonal():self{
		$this->mirror = !$this->mirror;
		$this->matrix = array_reverse($this->matrix);
		return $this->rotate90();
	}
	public function readCodewords():array{
		$this
			->readFormatInformation()
			->readVersion()
			->mask($this->maskPattern)
		;
		$matrix = (new QRMatrix($this->version, $this->eccLevel))
			->initFunctionalPatterns()
			->setFormatInfo($this->maskPattern)
		;
		$result    = [];
		$byte      = 0;
		$bitsRead  = 0;
		$direction = true;
		for($i = ($this->moduleCount - 1); $i > 0; $i -= 2){
			if($i === 6){
				$i--;
			}
			for($count = 0; $count < $this->moduleCount; $count++){
				$y = ($direction) ? ($this->moduleCount - 1 - $count) : $count;
				for($col = 0; $col < 2; $col++){
					$x = ($i - $col);
					if($matrix->get($x, $y) !== $this::M_NULL){
						continue;
					}
					$bitsRead++;
					$byte <<= 1;
					if($this->check($x, $y)){
						$byte |= 1;
					}
					if($bitsRead === 8){
						$result[] = $byte;
						$bitsRead = 0;
						$byte     = 0;
					}
				}
			}
			$direction = !$direction;
		}
		if(count($result) !== $this->version->getTotalCodewords()){
			throw new QRCodeDecoderException('result count differs from total codewords for version');
		}
		return $result;
	}
	private function readFormatInformation():self{
		if($this->eccLevel !== null && $this->maskPattern !== null){
			return $this;
		}
		$formatInfoBits1 = 0;
		for($i = 0; $i < 6; $i++){
			$formatInfoBits1 = $this->copyVersionBit($i, 8, $formatInfoBits1);
		}
		$formatInfoBits1 = $this->copyVersionBit(7, 8, $formatInfoBits1);
		$formatInfoBits1 = $this->copyVersionBit(8, 8, $formatInfoBits1);
		$formatInfoBits1 = $this->copyVersionBit(8, 7, $formatInfoBits1);
		for($j = 5; $j >= 0; $j--){
			$formatInfoBits1 = $this->copyVersionBit(8, $j, $formatInfoBits1);
		}
		$formatInfoBits2 = 0;
		$jMin            = ($this->moduleCount - 7);
		for($j = ($this->moduleCount - 1); $j >= $jMin; $j--){
			$formatInfoBits2 = $this->copyVersionBit(8, $j, $formatInfoBits2);
		}
		for($i = ($this->moduleCount - 8); $i < $this->moduleCount; $i++){
			$formatInfoBits2 = $this->copyVersionBit($i, 8, $formatInfoBits2);
		}
		$formatInfo = $this->doDecodeFormatInformation($formatInfoBits1, $formatInfoBits2);
		if($formatInfo === null){
			$formatInfo = $this->doDecodeFormatInformation(
				($formatInfoBits1 ^ $this::FORMAT_INFO_MASK_QR),
				($formatInfoBits2 ^ $this::FORMAT_INFO_MASK_QR)
			);
			if($formatInfo === null){
				throw new QRCodeDecoderException('failed to read format info');
			}
		}
		$this->eccLevel    = new EccLevel(($formatInfo >> 3) & 0x03);
		$this->maskPattern = new MaskPattern($formatInfo & 0x07);
		return $this;
	}
	private function copyVersionBit(int $i, int $j, int $versionBits):int{
		$bit = $this->mirror
			? $this->check($j, $i)
			: $this->check($i, $j);
		return ($bit) ? (($versionBits << 1) | 0x1) : ($versionBits << 1);
	}
	private function doDecodeFormatInformation(int $maskedFormatInfo1, int $maskedFormatInfo2):?int{
		$bestDifference = PHP_INT_MAX;
		$bestFormatInfo = 0;
		foreach($this::DECODE_LOOKUP as $maskedBits => $dataBits){
			if($maskedFormatInfo1 === $dataBits || $maskedFormatInfo2 === $dataBits){
				return $maskedBits;
			}
			$bitsDifference = $this->numBitsDiffering($maskedFormatInfo1, $dataBits);
			if($bitsDifference < $bestDifference){
				$bestFormatInfo = $maskedBits;
				$bestDifference = $bitsDifference;
			}
			if($maskedFormatInfo1 !== $maskedFormatInfo2){
				$bitsDifference = $this->numBitsDiffering($maskedFormatInfo2, $dataBits);
				if($bitsDifference < $bestDifference){
					$bestFormatInfo = $maskedBits;
					$bestDifference = $bitsDifference;
				}
			}
		}
		if($bestDifference <= 3){
			return $bestFormatInfo;
		}
		return null;
	}
	private function readVersion():self{
		if($this->version !== null){
			return $this;
		}
		$provisionalVersion = (($this->moduleCount - 17) / 4);
		if($provisionalVersion < 7){
			$this->version = new Version($provisionalVersion);
			return $this;
		}
		$versionBits = 0;
		$ijMin       = ($this->moduleCount - 11);
		for($y = 5; $y >= 0; $y--){
			for($x = ($this->moduleCount - 9); $x >= $ijMin; $x--){
				$versionBits = $this->copyVersionBit($x, $y, $versionBits);
			}
		}
		$this->version = $this->decodeVersionInformation($versionBits);
		if($this->version !== null && $this->version->getDimension() === $this->moduleCount){
			return $this;
		}
		$versionBits = 0;
		for($x = 5; $x >= 0; $x--){
			for($y = ($this->moduleCount - 9); $y >= $ijMin; $y--){
				$versionBits = $this->copyVersionBit($x, $y, $versionBits);
			}
		}
		$this->version = $this->decodeVersionInformation($versionBits);
		if($this->version !== null && $this->version->getDimension() === $this->moduleCount){
			return $this;
		}
		throw new QRCodeDecoderException('failed to read version');
	}
	private function decodeVersionInformation(int $versionBits):?Version{
		$bestDifference = PHP_INT_MAX;
		$bestVersion    = 0;
		for($i = 7; $i <= 40; $i++){
			$targetVersion        = new Version($i);
			$targetVersionPattern = $targetVersion->getVersionPattern();
			if($targetVersionPattern === $versionBits){
				return $targetVersion;
			}
			$bitsDifference = $this->numBitsDiffering($versionBits, $targetVersionPattern);
			if($bitsDifference < $bestDifference){
				$bestVersion    = $i;
				$bestDifference = $bitsDifference;
			}
		}
		if($bestDifference <= 3){
			return new Version($bestVersion);
		}
		return null;
	}
	private function uRShift(int $a, int $b):int{
		if($b === 0){
			return $a;
		}
		return (($a >> $b) & ~((1 << (8 * PHP_INT_SIZE - 1)) >> ($b - 1)));
	}
	private function numBitsDiffering(int $a, int $b):int{
		$a ^= $b;
		$BITS_SET_IN_HALF_BYTE = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
		$count = 0;
		for($i = 0; $i < 32; $i += 4){
			$count += $BITS_SET_IN_HALF_BYTE[($this->uRShift($a, $i) & 0x0F)];
		}
		return $count;
	}
	public function setQuietZone(?int $quietZoneSize = null):self{
		throw new QRCodeDataException('not supported');
	}
	public function setLogoSpace(int $width, ?int $height = null, ?int $startX = null, ?int $startY = null):self{
		throw new QRCodeDataException('not supported');
	}
}