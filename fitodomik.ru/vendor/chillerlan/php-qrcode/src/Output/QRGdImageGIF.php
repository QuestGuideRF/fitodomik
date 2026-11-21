<?php
namespace chillerlan\QRCode\Output;
use function imagegif;
class QRGdImageGIF extends QRGdImage{
	public const MIME_TYPE = 'image/gif';
	protected function renderImage():void{
		imagegif($this->image);
	}
}