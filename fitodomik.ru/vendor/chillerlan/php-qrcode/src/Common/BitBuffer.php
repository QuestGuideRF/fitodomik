<?php
namespace chillerlan\QRCode\Common;
use chillerlan\QRCode\QRCodeException;
use function count, floor, min;
final class BitBuffer{
	private array $buffer;
	private int $length;
	private int $bytesRead = 0;
	private int $bitsRead  = 0;
	public function __construct(array $bytes = []){
		$this->buffer = $bytes;
		$this->length = count($this->buffer);
	}
	public function put(int $bits, int $length):self{
		for($i = 0; $i < $length; $i++){
			$this->putBit((($bits >> ($length - $i - 1)) & 1) === 1);
		}
		return $this;
	}
	public function putBit(bool $bit):self{
		$bufIndex = (int)floor($this->length / 8);
		if(count($this->buffer) <= $bufIndex){
			$this->buffer[] = 0;
		}
		if($bit === true){
			$this->buffer[$bufIndex] |= (0x80 >> ($this->length % 8));
		}
		$this->length++;
		return $this;
	}
	public function getLength():int{
		return $this->length;
	}
	public function getBuffer():array{
		return $this->buffer;
	}
	public function available():int{
		return ((8 * ($this->length - $this->bytesRead)) - $this->bitsRead);
	}
	public function read(int $numBits):int{
		if($numBits < 1 || $numBits > $this->available()){
			throw new QRCodeException('invalid $numBits: '.$numBits);
		}
		$result = 0;
		if($this->bitsRead > 0){
			$bitsLeft       = (8 - $this->bitsRead);
			$toRead         = min($numBits, $bitsLeft);
			$bitsToNotRead  = ($bitsLeft - $toRead);
			$mask           = ((0xff >> (8 - $toRead)) << $bitsToNotRead);
			$result         = (($this->buffer[$this->bytesRead] & $mask) >> $bitsToNotRead);
			$numBits        -= $toRead;
			$this->bitsRead += $toRead;
			if($this->bitsRead === 8){
				$this->bitsRead = 0;
				$this->bytesRead++;
			}
		}
		if($numBits > 0){
			while($numBits >= 8){
				$result = (($result << 8) | ($this->buffer[$this->bytesRead] & 0xff));
				$this->bytesRead++;
				$numBits -= 8;
			}
			if($numBits > 0){
				$bitsToNotRead  = (8 - $numBits);
				$mask           = ((0xff >> $bitsToNotRead) << $bitsToNotRead);
				$result         = (($result << $numBits) | (($this->buffer[$this->bytesRead] & $mask) >> $bitsToNotRead));
				$this->bitsRead += $numBits;
			}
		}
		return $result;
	}
	public function clear():self{
		$this->buffer = [];
		$this->length = 0;
		return $this->rewind();
	}
	public function rewind():self{
		$this->bytesRead = 0;
		$this->bitsRead  = 0;
		return $this;
	}
}