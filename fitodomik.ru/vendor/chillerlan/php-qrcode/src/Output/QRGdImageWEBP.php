<?php
namespace chillerlan\QRCode\Output;
use function imagewebp, max, min;
class QRGdImageWEBP extends QRGdImage{
	public const MIME_TYPE = 'image/webp';
	protected function renderImage():void{
		imagewebp($this->image, null, max(-1, min(100, $this->options->quality)));
	}
}