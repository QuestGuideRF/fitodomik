<?php
namespace chillerlan\QRCode\Output;
use function imagebmp;
class QRGdImageBMP extends QRGdImage{
	public const MIME_TYPE = 'image/bmp';
	protected function renderImage():void{
		imagebmp($this->image, null, ($this->options->quality > 0));
	}
}