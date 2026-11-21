<?php
namespace chillerlan\QRCode\Output;
use function imagepng, max, min;
class QRGdImagePNG extends QRGdImage{
	public const MIME_TYPE = 'image/png';
	protected function renderImage():void{
		imagepng($this->image, null, max(-1, min(9, $this->options->quality)));
	}
}