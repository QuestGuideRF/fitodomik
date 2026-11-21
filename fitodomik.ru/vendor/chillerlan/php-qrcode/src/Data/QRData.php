<?php
namespace chillerlan\QRCode\Data;
use chillerlan\QRCode\Common\{BitBuffer, EccLevel, Mode, Version};
use chillerlan\Settings\SettingsContainerInterface;
use function count, sprintf;
final class QRData{
	private SettingsContainerInterface $options;
	private BitBuffer $bitBuffer;
	private EccLevel $eccLevel;
	private Version $version;
	private array $dataSegments = [];
	private array $maxBitsForEcc;
	public function __construct(SettingsContainerInterface $options, array $dataSegments = []){
		$this->options       = $options;
		$this->bitBuffer     = new BitBuffer;
		$this->eccLevel      = new EccLevel($this->options->eccLevel);
		$this->maxBitsForEcc = $this->eccLevel->getMaxBits();
		$this->setData($dataSegments);
	}
	public function setData(array $dataSegments):self{
		$this->dataSegments = $dataSegments;
		$this->version      = $this->getMinimumVersion();
		$this->bitBuffer->clear();
		$this->writeBitBuffer();
		return $this;
	}
	public function getBitBuffer():BitBuffer{
		return $this->bitBuffer;
	}
	public function setBitBuffer(BitBuffer $bitBuffer):self{
		if($this->options->version === Version::AUTO){
			throw new QRCodeDataException('version auto detection is not available');
		}
		if($bitBuffer->getLength() === 0){
			throw new QRCodeDataException('the given BitBuffer is empty');
		}
		$this->dataSegments = [];
		$this->bitBuffer    = $bitBuffer;
		$this->version      = new Version($this->options->version);
		return $this;
	}
	public function writeMatrix():QRMatrix{
		return (new QRMatrix($this->version, $this->eccLevel))
			->initFunctionalPatterns()
			->writeCodewords($this->bitBuffer)
		;
	}
	public function estimateTotalBitLength():int{
		$length = 0;
		foreach($this->dataSegments as $segment){
			$length += $segment->getLengthInBits();
			$length += 4;
			if($segment instanceof Hanzi){
				$length += 4;
			}
		}
		$provisionalVersion = null;
		foreach($this->maxBitsForEcc as $version => $maxBits){
			if($length <= $maxBits){
				$provisionalVersion = $version;
			}
		}
		if($provisionalVersion !== null){
			foreach($this->dataSegments as $segment){
				$length += Mode::getLengthBitsForVersion($segment::DATAMODE, $provisionalVersion);
			}
			if(count($this->dataSegments) <= 1){
				$length -= 4;
			}
			if($length <= $this->maxBitsForEcc[$provisionalVersion] || isset($this->maxBitsForEcc[($provisionalVersion + 1)])){
				return $length;
			}
		}
		throw new QRCodeDataException(sprintf('estimated data exceeds %d bits', $length));
	}
	public function getMinimumVersion():Version{
		if($this->options->version !== Version::AUTO){
			return new Version($this->options->version);
		}
		$total = $this->estimateTotalBitLength();
		for($version = $this->options->versionMin; $version <= $this->options->versionMax; $version++){
			if($total <= ($this->maxBitsForEcc[$version] - 4)){
				return new Version($version);
			}
		}
		throw new QRCodeDataException('failed to guess minimum version');
	}
	private function writeBitBuffer():void{
		$MAX_BITS = $this->eccLevel->getMaxBitsForVersion($this->version);
		foreach($this->dataSegments as $segment){
			$segment->write($this->bitBuffer, $this->version->getVersionNumber());
		}
		if($this->bitBuffer->getLength() > $MAX_BITS){
			throw new QRCodeDataException(
				sprintf('code length overflow. (%d > %d bit)', $this->bitBuffer->getLength(), $MAX_BITS)
			);
		}
		if(($this->bitBuffer->getLength() + 4) <= $MAX_BITS){
			$this->bitBuffer->put(Mode::TERMINATOR, 4);
		}
		while(($this->bitBuffer->getLength() % 8) !== 0){
			if($this->bitBuffer->getLength() === $MAX_BITS){
				break;
			}
			$this->bitBuffer->putBit(false);
		}
		$alternate = false;
		while(($this->bitBuffer->getLength() + 8) <= $MAX_BITS){
			$this->bitBuffer->put(($alternate) ? 0b00010001 : 0b11101100, 8);
			$alternate = !$alternate;
		}
		while($this->bitBuffer->getLength() <= $MAX_BITS){
			$this->bitBuffer->putBit(false);
		}
	}
}