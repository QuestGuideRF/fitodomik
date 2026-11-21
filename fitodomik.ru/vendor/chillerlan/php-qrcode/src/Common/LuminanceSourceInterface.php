<?php
namespace chillerlan\QRCode\Common;
interface LuminanceSourceInterface{
	public function getLuminances():array;
	public function getWidth():int;
	public function getHeight():int;
	public function getRow(int $y):array;
	public static function fromFile(string $path):self;
	public static function fromBlob(string $blob):self;
}