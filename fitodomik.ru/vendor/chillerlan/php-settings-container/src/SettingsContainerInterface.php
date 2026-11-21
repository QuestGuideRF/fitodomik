<?php
declare(strict_types=1);
namespace chillerlan\Settings;
use JsonSerializable, Serializable;
interface SettingsContainerInterface extends JsonSerializable, Serializable{
	public function __get(string $property):mixed;
	public function __set(string $property, mixed $value):void;
	public function __isset(string $property):bool;
	public function __unset(string $property):void;
	public function __toString():string;
	public function toArray():array;
	public function fromIterable(iterable $properties):static;
	public function toJSON(int|null $jsonOptions = null):string;
	public function fromJSON(string $json):static;
}