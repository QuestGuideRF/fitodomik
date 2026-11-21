<?php
namespace chillerlan\QRCode\Common;
use chillerlan\QRCode\QRCodeException;
use function sprintf;
final class ECICharset{
	public const CP437                 = 0;
	public const ISO_IEC_8859_1_GLI    = 1;
	public const CP437_WO_GLI          = 2;
	public const ISO_IEC_8859_1        = 3;
	public const ISO_IEC_8859_2        = 4;
	public const ISO_IEC_8859_3        = 5;
	public const ISO_IEC_8859_4        = 6;
	public const ISO_IEC_8859_5        = 7;
	public const ISO_IEC_8859_6        = 8;
	public const ISO_IEC_8859_7        = 9;
	public const ISO_IEC_8859_8        = 10;
	public const ISO_IEC_8859_9        = 11;
	public const ISO_IEC_8859_10       = 12;
	public const ISO_IEC_8859_11       = 13;
	public const ISO_IEC_8859_13       = 15;
	public const ISO_IEC_8859_14       = 16;
	public const ISO_IEC_8859_15       = 17;
	public const ISO_IEC_8859_16       = 18;
	public const SHIFT_JIS             = 20;
	public const WINDOWS_1250_LATIN_2  = 21;
	public const WINDOWS_1251_CYRILLIC = 22;
	public const WINDOWS_1252_LATIN_1  = 23;
	public const WINDOWS_1256_ARABIC   = 24;
	public const ISO_IEC_10646_UCS_2   = 25;
	public const ISO_IEC_10646_UTF_8   = 26;
	public const ISO_IEC_646_1991      = 27;
	public const BIG5                  = 28;
	public const GB18030               = 29;
	public const EUC_KR                = 30;
	public const MB_ENCODINGS = [
		self::CP437                 => null,
		self::ISO_IEC_8859_1_GLI    => null,
		self::CP437_WO_GLI          => null,
		self::ISO_IEC_8859_1        => 'ISO-8859-1',
		self::ISO_IEC_8859_2        => 'ISO-8859-2',
		self::ISO_IEC_8859_3        => 'ISO-8859-3',
		self::ISO_IEC_8859_4        => 'ISO-8859-4',
		self::ISO_IEC_8859_5        => 'ISO-8859-5',
		self::ISO_IEC_8859_6        => 'ISO-8859-6',
		self::ISO_IEC_8859_7        => 'ISO-8859-7',
		self::ISO_IEC_8859_8        => 'ISO-8859-8',
		self::ISO_IEC_8859_9        => 'ISO-8859-9',
		self::ISO_IEC_8859_10       => 'ISO-8859-10',
		self::ISO_IEC_8859_11       => null,
		self::ISO_IEC_8859_13       => 'ISO-8859-13',
		self::ISO_IEC_8859_14       => 'ISO-8859-14',
		self::ISO_IEC_8859_15       => 'ISO-8859-15',
		self::ISO_IEC_8859_16       => 'ISO-8859-16',
		self::SHIFT_JIS             => 'SJIS',
		self::WINDOWS_1250_LATIN_2  => null,
		self::WINDOWS_1251_CYRILLIC => 'Windows-1251',
		self::WINDOWS_1252_LATIN_1  => 'Windows-1252',
		self::WINDOWS_1256_ARABIC   => null,
		self::ISO_IEC_10646_UCS_2   => 'UTF-16BE',
		self::ISO_IEC_10646_UTF_8   => 'UTF-8',
		self::ISO_IEC_646_1991      => 'ASCII',
		self::BIG5                  => 'BIG-5',
		self::GB18030               => 'GB18030',
		self::EUC_KR                => 'EUC-KR',
	];
	private int $charsetID;
	public function __construct(int $charsetID){
		if($charsetID < 0 || $charsetID > 999999){
			throw new QRCodeException(sprintf('invalid charset id: "%s"', $charsetID));
		}
		$this->charsetID = $charsetID;
	}
	public function getID():int{
		return $this->charsetID;
	}
	public function getName():?string{
		return (self::MB_ENCODINGS[$this->charsetID] ?? null);
	}
}