<?php
namespace chillerlan\QRCode\Output;
use function imagejpeg, max, min;
class QRGdImageJPEG extends QRGdImage{
	public const MIME_TYPE = 'image/jpg';
	protected function setTransparencyColor():void{
	}
	protected function renderImage():void{
		imagejpeg($this->image, null, max(-1, min(100, $this->options->quality)));
	}
}