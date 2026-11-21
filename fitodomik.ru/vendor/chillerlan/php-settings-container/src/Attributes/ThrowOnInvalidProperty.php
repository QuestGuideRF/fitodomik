<?php
declare(strict_types=1);
namespace chillerlan\Settings\Attributes;
use Attribute;
final class ThrowOnInvalidProperty{
	public function __construct(
		public readonly bool $throwOnInvalid,
	){}
}