<?php
namespace chillerlan\QRCode\Decoder;
use chillerlan\QRCode\Common\{BitBuffer, EccLevel, GenericGFPoly, GF256, Version};
use function array_fill, array_reverse, count;
final class ReedSolomonDecoder{
	private Version  $version;
	private EccLevel $eccLevel;
	public function __construct(Version $version, EccLevel $eccLevel){
		$this->version  = $version;
		$this->eccLevel = $eccLevel;
	}
	public function decode(array $rawCodewords):BitBuffer{
		$dataBlocks  = $this->deinterleaveRawBytes($rawCodewords);
		$dataBytes   = [];
		foreach($dataBlocks as [$numDataCodewords, $codewordBytes]){
			$corrected = $this->correctErrors($codewordBytes, $numDataCodewords);
			for($i = 0; $i < $numDataCodewords; $i++){
				$dataBytes[] = $corrected[$i];
			}
		}
		return new BitBuffer($dataBytes);
	}
	private function deinterleaveRawBytes(array $rawCodewords):array{
		[$numEccCodewords, $eccBlocks] = $this->version->getRSBlocks($this->eccLevel);
		$result          = [];
		$numResultBlocks = 0;
		foreach($eccBlocks as [$numEccBlocks, $eccPerBlock]){
			for($i = 0; $i < $numEccBlocks; $i++, $numResultBlocks++){
				$result[$numResultBlocks] = [$eccPerBlock, array_fill(0, ($numEccCodewords + $eccPerBlock), 0)];
			}
		}
		$shorterBlocksTotalCodewords = count($result[0][1]);
		$longerBlocksStartAt         = (count($result) - 1);
		while($longerBlocksStartAt >= 0){
			$numCodewords = count($result[$longerBlocksStartAt][1]);
			if($numCodewords === $shorterBlocksTotalCodewords){
				break;
			}
			$longerBlocksStartAt--;
		}
		$longerBlocksStartAt++;
		$shorterBlocksNumDataCodewords = ($shorterBlocksTotalCodewords - $numEccCodewords);
		$rawCodewordsOffset = 0;
		for($i = 0; $i < $shorterBlocksNumDataCodewords; $i++){
			for($j = 0; $j < $numResultBlocks; $j++){
				$result[$j][1][$i] = $rawCodewords[$rawCodewordsOffset++];
			}
		}
		for($j = $longerBlocksStartAt; $j < $numResultBlocks; $j++){
			$result[$j][1][$shorterBlocksNumDataCodewords] = $rawCodewords[$rawCodewordsOffset++];
		}
		$max = count($result[0][1]);
		for($i = $shorterBlocksNumDataCodewords; $i < $max; $i++){
			for($j = 0; $j < $numResultBlocks; $j++){
				$iOffset                 = ($j < $longerBlocksStartAt) ? $i : ($i + 1);
				$result[$j][1][$iOffset] = $rawCodewords[$rawCodewordsOffset++];
			}
		}
		return $result;
	}
	private function correctErrors(array $codewordBytes, int $numDataCodewords):array{
		$codewordsInts = [];
		foreach($codewordBytes as $codewordByte){
			$codewordsInts[] = ($codewordByte & 0xFF);
		}
		$decoded = $this->decodeWords($codewordsInts, (count($codewordBytes) - $numDataCodewords));
		for($i = 0; $i < $numDataCodewords; $i++){
			$codewordBytes[$i] = $decoded[$i];
		}
		return $codewordBytes;
	}
	private function decodeWords(array $received, int $numEccCodewords):array{
		$poly                 = new GenericGFPoly($received);
		$syndromeCoefficients = [];
		$error                = false;
		for($i = 0; $i < $numEccCodewords; $i++){
			$syndromeCoefficients[$i] = $poly->evaluateAt(GF256::exp($i));
			if($syndromeCoefficients[$i] !== 0){
				$error = true;
			}
		}
		if(!$error){
			return $received;
		}
		[$sigma, $omega] = $this->runEuclideanAlgorithm(
			GF256::buildMonomial($numEccCodewords, 1),
			new GenericGFPoly(array_reverse($syndromeCoefficients)),
			$numEccCodewords
		);
		$errorLocations      = $this->findErrorLocations($sigma);
		$errorMagnitudes     = $this->findErrorMagnitudes($omega, $errorLocations);
		$errorLocationsCount = count($errorLocations);
		$receivedCount       = count($received);
		for($i = 0; $i < $errorLocationsCount; $i++){
			$position = ($receivedCount - 1 - GF256::log($errorLocations[$i]));
			if($position < 0){
				throw new QRCodeDecoderException('Bad error location');
			}
			$received[$position] ^= $errorMagnitudes[$i];
		}
		return $received;
	}
	private function runEuclideanAlgorithm(GenericGFPoly $a, GenericGFPoly $b, int $z):array{
		if($a->getDegree() < $b->getDegree()){
			$temp = $a;
			$a    = $b;
			$b    = $temp;
		}
		$rLast = $a;
		$r     = $b;
		$tLast = new GenericGFPoly([0]);
		$t     = new GenericGFPoly([1]);
		while((2 * $r->getDegree()) >= $z){
			$rLastLast = $rLast;
			$tLastLast = $tLast;
			$rLast     = $r;
			$tLast     = $t;
			[$q, $r] = $rLastLast->divide($rLast);
			$t = $q->multiply($tLast)->addOrSubtract($tLastLast);
			if($r->getDegree() >= $rLast->getDegree()){
				throw new QRCodeDecoderException('Division algorithm failed to reduce polynomial?');
			}
		}
		$sigmaTildeAtZero = $t->getCoefficient(0);
		if($sigmaTildeAtZero === 0){
			throw new QRCodeDecoderException('sigmaTilde(0) was zero');
		}
		$inverse = GF256::inverse($sigmaTildeAtZero);
		return [$t->multiplyInt($inverse), $r->multiplyInt($inverse)];
	}
	private function findErrorLocations(GenericGFPoly $errorLocator):array{
		$numErrors = $errorLocator->getDegree();
		if($numErrors === 1){
			return [$errorLocator->getCoefficient(1)];
		}
		$result = array_fill(0, $numErrors, 0);
		$e      = 0;
		for($i = 1; $i < 256 && $e < $numErrors; $i++){
			if($errorLocator->evaluateAt($i) === 0){
				$result[$e] = GF256::inverse($i);
				$e++;
			}
		}
		if($e !== $numErrors){
			throw new QRCodeDecoderException('Error locator degree does not match number of roots');
		}
		return $result;
	}
	private function findErrorMagnitudes(GenericGFPoly $errorEvaluator, array $errorLocations):array{
		$s      = count($errorLocations);
		$result = [];
		for($i = 0; $i < $s; $i++){
			$xiInverse   = GF256::inverse($errorLocations[$i]);
			$denominator = 1;
			for($j = 0; $j < $s; $j++){
				if($i !== $j){
					$term        = GF256::multiply($errorLocations[$j], $xiInverse);
					$denominator = GF256::multiply($denominator, ((($term & 0x1) === 0) ? ($term | 1) : ($term & ~1)));
				}
			}
			$result[$i] = GF256::multiply($errorEvaluator->evaluateAt($xiInverse), GF256::inverse($denominator));
		}
		return $result;
	}
}