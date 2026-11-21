<?php
namespace chillerlan\QRCode\Common;
use chillerlan\QRCode\QRCodeException;
use function array_column;
final class EccLevel{
	public const L = 0b01;
	public const M = 0b00;
	public const Q = 0b11;
	public const H = 0b10;
	private const MAX_BITS = [
		[    0,     0,     0,     0],
		[  152,   128,   104,    72],
		[  272,   224,   176,   128],
		[  440,   352,   272,   208],
		[  640,   512,   384,   288],
		[  864,   688,   496,   368],
		[ 1088,   864,   608,   480],
		[ 1248,   992,   704,   528],
		[ 1552,  1232,   880,   688],
		[ 1856,  1456,  1056,   800],
		[ 2192,  1728,  1232,   976],
		[ 2592,  2032,  1440,  1120],
		[ 2960,  2320,  1648,  1264],
		[ 3424,  2672,  1952,  1440],
		[ 3688,  2920,  2088,  1576],
		[ 4184,  3320,  2360,  1784],
		[ 4712,  3624,  2600,  2024],
		[ 5176,  4056,  2936,  2264],
		[ 5768,  4504,  3176,  2504],
		[ 6360,  5016,  3560,  2728],
		[ 6888,  5352,  3880,  3080],
		[ 7456,  5712,  4096,  3248],
		[ 8048,  6256,  4544,  3536],
		[ 8752,  6880,  4912,  3712],
		[ 9392,  7312,  5312,  4112],
		[10208,  8000,  5744,  4304],
		[10960,  8496,  6032,  4768],
		[11744,  9024,  6464,  5024],
		[12248,  9544,  6968,  5288],
		[13048, 10136,  7288,  5608],
		[13880, 10984,  7880,  5960],
		[14744, 11640,  8264,  6344],
		[15640, 12328,  8920,  6760],
		[16568, 13048,  9368,  7208],
		[17528, 13800,  9848,  7688],
		[18448, 14496, 10288,  7888],
		[19472, 15312, 10832,  8432],
		[20528, 15936, 11408,  8768],
		[21616, 16816, 12016,  9136],
		[22496, 17728, 12656,  9776],
		[23648, 18672, 13328, 10208],
	];
	private const FORMAT_PATTERN = [
		[
		  0b111011111000100,
		  0b111001011110011,
		  0b111110110101010,
		  0b111100010011101,
		  0b110011000101111,
		  0b110001100011000,
		  0b110110001000001,
		  0b110100101110110,
		],
		[
		  0b101010000010010,
		  0b101000100100101,
		  0b101111001111100,
		  0b101101101001011,
		  0b100010111111001,
		  0b100000011001110,
		  0b100111110010111,
		  0b100101010100000,
		],
		[
		  0b011010101011111,
		  0b011000001101000,
		  0b011111100110001,
		  0b011101000000110,
		  0b010010010110100,
		  0b010000110000011,
		  0b010111011011010,
		  0b010101111101101,
		],
		[
		  0b001011010001001,
		  0b001001110111110,
		  0b001110011100111,
		  0b001100111010000,
		  0b000011101100010,
		  0b000001001010101,
		  0b000110100001100,
		  0b000100000111011,
		],
	];
	private int $eccLevel;
	public function __construct(int $eccLevel){
		if((0b11 & $eccLevel) !== $eccLevel){
			throw new QRCodeException('invalid ECC level');
		}
		$this->eccLevel = $eccLevel;
	}
	public function __toString():string{
		return [
			self::L => 'L',
			self::M => 'M',
			self::Q => 'Q',
			self::H => 'H',
		][$this->eccLevel];
	}
	public function getLevel():int{
		return $this->eccLevel;
	}
	public function getOrdinal():int{
		return [
			self::L => 0,
			self::M => 1,
			self::Q => 2,
			self::H => 3,
		][$this->eccLevel];
	}
	public function getformatPattern(MaskPattern $maskPattern):int{
		return self::FORMAT_PATTERN[$this->getOrdinal()][$maskPattern->getPattern()];
	}
	public function getMaxBits():array{
		$col = array_column(self::MAX_BITS, $this->getOrdinal());
		unset($col[0]);
		return $col;
	}
	public function getMaxBitsForVersion(Version $version):int{
		return self::MAX_BITS[$version->getVersionNumber()][$this->getOrdinal()];
	}
}