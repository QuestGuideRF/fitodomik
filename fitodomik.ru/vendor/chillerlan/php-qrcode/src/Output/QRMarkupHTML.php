<?php
namespace chillerlan\QRCode\Output;
use function implode, sprintf;
class QRMarkupHTML extends QRMarkup{
	public const MIME_TYPE = 'text/html';
	protected function createMarkup(bool $saveToFile):string{
		$rows     = [];
		$cssClass = $this->getCssClass();
		foreach($this->matrix->getMatrix() as $row){
			$element = '<span style="background: %s;"></span>';
			$modules = array_map(fn(int $M_TYPE):string => sprintf($element, $this->getModuleValue($M_TYPE)), $row);
			$rows[]  = sprintf('<div>%s</div>%s', implode('', $modules), $this->eol);
		}
		$html = sprintf('<div class="%1$s">%3$s%2$s</div>%3$s', $cssClass, implode('', $rows), $this->eol);
		if($saveToFile){
			$html = sprintf(
				'<!DOCTYPE html><html lang="none">%2$s<head>%2$s<meta charset="UTF-8">%2$s'.
					'<title>QR Code</title></head>%2$s<body>%1$s</body>%2$s</html>',
				$html,
				$this->eol
			);
		}
		return $html;
	}
}