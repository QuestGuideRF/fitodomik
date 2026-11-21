<?php
namespace chillerlan\QRCode\Output;
use chillerlan\QRCode\Data\QRMatrix;
interface QROutputInterface{
	public const MARKUP_HTML  = 'html';
	public const MARKUP_SVG   = 'svg';
	public const GDIMAGE_BMP  = 'bmp';
	public const GDIMAGE_GIF  = 'gif';
	public const GDIMAGE_JPG  = 'jpg';
	public const GDIMAGE_PNG  = 'png';
	public const GDIMAGE_WEBP = 'webp';
	public const STRING_JSON  = 'json';
	public const STRING_TEXT  = 'text';
	public const IMAGICK      = 'imagick';
	public const FPDF         = 'fpdf';
	public const EPS          = 'eps';
	public const CUSTOM       = 'custom';
	public const MODES = [
		self::MARKUP_SVG   => QRMarkupSVG::class,
		self::MARKUP_HTML  => QRMarkupHTML::class,
		self::GDIMAGE_BMP  => QRGdImageBMP::class,
		self::GDIMAGE_GIF  => QRGdImageGIF::class,
		self::GDIMAGE_JPG  => QRGdImageJPEG::class,
		self::GDIMAGE_PNG  => QRGdImagePNG::class,
		self::GDIMAGE_WEBP => QRGdImageWEBP::class,
		self::STRING_JSON  => QRStringJSON::class,
		self::STRING_TEXT  => QRStringText::class,
		self::IMAGICK      => QRImagick::class,
		self::FPDF         => QRFpdf::class,
		self::EPS          => QREps::class,
	];
	public const DEFAULT_MODULE_VALUES = [
		QRMatrix::M_NULL             => false,
		QRMatrix::M_DARKMODULE_LIGHT => false,
		QRMatrix::M_DATA             => false,
		QRMatrix::M_FINDER           => false,
		QRMatrix::M_SEPARATOR        => false,
		QRMatrix::M_ALIGNMENT        => false,
		QRMatrix::M_TIMING           => false,
		QRMatrix::M_FORMAT           => false,
		QRMatrix::M_VERSION          => false,
		QRMatrix::M_QUIETZONE        => false,
		QRMatrix::M_LOGO             => false,
		QRMatrix::M_FINDER_DOT_LIGHT => false,
		QRMatrix::M_DARKMODULE       => true,
		QRMatrix::M_DATA_DARK        => true,
		QRMatrix::M_FINDER_DARK      => true,
		QRMatrix::M_SEPARATOR_DARK   => true,
		QRMatrix::M_ALIGNMENT_DARK   => true,
		QRMatrix::M_TIMING_DARK      => true,
		QRMatrix::M_FORMAT_DARK      => true,
		QRMatrix::M_VERSION_DARK     => true,
		QRMatrix::M_QUIETZONE_DARK   => true,
		QRMatrix::M_LOGO_DARK        => true,
		QRMatrix::M_FINDER_DOT       => true,
	];
	public const LAYERNAMES = [
		QRMatrix::M_NULL             => 'null',
		QRMatrix::M_DARKMODULE_LIGHT => 'darkmodule-light',
		QRMatrix::M_DATA             => 'data',
		QRMatrix::M_FINDER           => 'finder',
		QRMatrix::M_SEPARATOR        => 'separator',
		QRMatrix::M_ALIGNMENT        => 'alignment',
		QRMatrix::M_TIMING           => 'timing',
		QRMatrix::M_FORMAT           => 'format',
		QRMatrix::M_VERSION          => 'version',
		QRMatrix::M_QUIETZONE        => 'quietzone',
		QRMatrix::M_LOGO             => 'logo',
		QRMatrix::M_FINDER_DOT_LIGHT => 'finder-dot-light',
		QRMatrix::M_DARKMODULE       => 'darkmodule',
		QRMatrix::M_DATA_DARK        => 'data-dark',
		QRMatrix::M_FINDER_DARK      => 'finder-dark',
		QRMatrix::M_SEPARATOR_DARK   => 'separator-dark',
		QRMatrix::M_ALIGNMENT_DARK   => 'alignment-dark',
		QRMatrix::M_TIMING_DARK      => 'timing-dark',
		QRMatrix::M_FORMAT_DARK      => 'format-dark',
		QRMatrix::M_VERSION_DARK     => 'version-dark',
		QRMatrix::M_QUIETZONE_DARK   => 'quietzone-dark',
		QRMatrix::M_LOGO_DARK        => 'logo-dark',
		QRMatrix::M_FINDER_DOT       => 'finder-dot',
	];
	public const MIME_TYPE = '';
	public static function moduleValueIsValid($value):bool;
	public function dump(?string $file = null);
}