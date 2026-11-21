<?php
namespace chillerlan\QRCode\Decoder;
use chillerlan\QRCode\Common\{BitBuffer, EccLevel, LuminanceSourceInterface, MaskPattern, Mode, Version};
use chillerlan\QRCode\Data\{AlphaNum, Byte, ECI, Hanzi, Kanji, Number};
use chillerlan\QRCode\Detector\Detector;
use Throwable;
use function chr, str_replace;
final class Decoder{
	private ?Version     $version = null;
	private ?EccLevel    $eccLevel = null;
	private ?MaskPattern $maskPattern = null;
	private BitBuffer    $bitBuffer;
	private Detector     $detector;
	public function decode(LuminanceSourceInterface $source):DecoderResult{
		$this->detector = new Detector($source);
		$matrix         = $this->detector->detect();
		try{
			return $this->decodeMatrix(clone $matrix);
		}
		catch(Throwable $e){
			try{
				return $this->decodeMatrix($matrix->resetVersionInfo()->mirrorDiagonal());
			}
			catch(Throwable $f){
				throw $e;
			}
		}
	}
	private function decodeMatrix(BitMatrix $matrix):DecoderResult{
		$rawCodewords      = $matrix->readCodewords();
		$this->version     = $matrix->getVersion();
		$this->eccLevel    = $matrix->getEccLevel();
		$this->maskPattern = $matrix->getMaskPattern();
		if($this->version === null || $this->eccLevel === null || $this->maskPattern === null){
			throw new QRCodeDecoderException('unable to read version or format info');
		}
		$resultBytes = (new ReedSolomonDecoder($this->version, $this->eccLevel))->decode($rawCodewords);
		return $this->decodeBitStream($resultBytes);
	}
	private function decodeBitStream(BitBuffer $bitBuffer):DecoderResult{
		$this->bitBuffer  = $bitBuffer;
		$versionNumber    = $this->version->getVersionNumber();
		$symbolSequence   = -1;
		$parityData       = -1;
		$fc1InEffect      = false;
		$result           = '';
		while($this->bitBuffer->available() >= 4){
			$datamode = $this->bitBuffer->read(4);
			if($datamode === Mode::TERMINATOR){
				break;
			}
			elseif($datamode === Mode::NUMBER){
				$result .= Number::decodeSegment($this->bitBuffer, $versionNumber);
			}
			elseif($datamode === Mode::ALPHANUM){
				$result .= $this->decodeAlphanumSegment($versionNumber, $fc1InEffect);
			}
			elseif($datamode === Mode::BYTE){
				$result .= Byte::decodeSegment($this->bitBuffer, $versionNumber);
			}
			elseif($datamode === Mode::KANJI){
				$result .= Kanji::decodeSegment($this->bitBuffer, $versionNumber);
			}
			elseif($datamode === Mode::STRCTURED_APPEND){
				if($this->bitBuffer->available() < 16){
					throw new QRCodeDecoderException('structured append: not enough bits left');
				}
				$symbolSequence = $this->bitBuffer->read(8);
				$parityData     = $this->bitBuffer->read(8);
			}
			elseif($datamode === Mode::FNC1_FIRST || $datamode === Mode::FNC1_SECOND){
				$fc1InEffect = true;
			}
			elseif($datamode === Mode::ECI){
				$result .= ECI::decodeSegment($this->bitBuffer, $versionNumber);
			}
			elseif($datamode === Mode::HANZI){
				$result .= Hanzi::decodeSegment($this->bitBuffer, $versionNumber);
			}
			else{
				throw new QRCodeDecoderException('invalid data mode');
			}
		}
		return new DecoderResult([
			'rawBytes'                 => $this->bitBuffer,
			'data'                     => $result,
			'version'                  => $this->version,
			'eccLevel'                 => $this->eccLevel,
			'finderPatterns'           => $this->detector->getFinderPatterns(),
			'maskPattern'              => $this->maskPattern,
			'structuredAppendParity'   => $parityData,
			'structuredAppendSequence' => $symbolSequence,
		]);
	}
	private function decodeAlphanumSegment(int $versionNumber, bool $fc1InEffect):string{
		$str = AlphaNum::decodeSegment($this->bitBuffer, $versionNumber);
		if($fc1InEffect){
			$str = str_replace(chr(0x1d), '%', $str);
			$str = str_replace('%%', '%', $str);
		}
		return $str;
	}
}