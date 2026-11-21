<?php
namespace chillerlan\QRCode\Decoder;
use chillerlan\QRCode\Common\{BitBuffer, EccLevel, MaskPattern, Version};
use chillerlan\QRCode\Data\QRMatrix;
use function property_exists;
final class DecoderResult{
	private BitBuffer   $rawBytes;
	private Version     $version;
	private EccLevel    $eccLevel;
	private MaskPattern $maskPattern;
	private string      $data = '';
	private int         $structuredAppendParity = -1;
	private int         $structuredAppendSequence = -1;
	private array       $finderPatterns = [];
	public function __construct(?iterable $properties = null){
		if($properties !== null){
			foreach($properties as $property => $value){
				if(!property_exists($this, $property)){
					continue;
				}
				$this->{$property} = $value;
			}
		}
	}
	public function __get(string $property){
		if(property_exists($this, $property)){
			return $this->{$property};
		}
		return null;
	}
	public function __toString():string{
		return $this->data;
	}
	public function hasStructuredAppend():bool{
		return $this->structuredAppendParity >= 0 && $this->structuredAppendSequence >= 0;
	}
	public function getQRMatrix():QRMatrix{
		return (new QRMatrix($this->version, $this->eccLevel))
			->initFunctionalPatterns()
			->writeCodewords($this->rawBytes)
			->setFormatInfo($this->maskPattern)
			->mask($this->maskPattern)
		;
	}
}