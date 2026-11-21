<?php
declare(strict_types=1);
namespace chillerlan\Settings;
use chillerlan\Settings\Attributes\ThrowOnInvalidProperty;
use InvalidArgumentException, JsonException, ReflectionException, ReflectionObject,
	ReflectionProperty, ReflectionAttribute, RuntimeException;
use function is_object, json_decode, json_encode, json_last_error_msg,
	method_exists, property_exists, serialize, sprintf, unserialize;
use const JSON_THROW_ON_ERROR, PHP_VERSION_ID;
abstract class SettingsContainerAbstract implements SettingsContainerInterface{
	protected const SET_PREFIX = 'set_';
	protected const GET_PREFIX = 'get_';
	public function __construct(iterable|null $properties = null){
		if(!empty($properties)){
			$this->fromIterable($properties);
		}
		$this->construct();
	}
	protected function construct():void{
		$traits = (new ReflectionObject($this))->getTraits();
		foreach($traits as $trait){
			$method = $trait->getShortName();
			if(method_exists($this, $method)){
				$this->{$method}();
			}
		}
	}
	public function __get(string $property):mixed{
		if(!property_exists($this, $property) || $this->isPrivate($property)){
			if($this->throwOnInvalidProperty()){
				throw new RuntimeException(sprintf('attempt to read invalid property: "$%s"', $property));
			}
			return null;
		}
		if(method_exists($this, static::GET_PREFIX.$property) && !$this->hasGetHook($property)){
			return $this->{static::GET_PREFIX.$property}();
		}
		return $this->{$property};
	}
	public function __set(string $property, mixed $value):void{
		if(!property_exists($this, $property) || $this->isPrivate($property)){
			if($this->throwOnInvalidProperty()){
				throw new RuntimeException(sprintf('attempt to write invalid property: "$%s"', $property));
			}
			return;
		}
		if(method_exists($this, static::SET_PREFIX.$property) && !$this->hasSetHook($property)){
			$this->{static::SET_PREFIX.$property}($value);
			return;
		}
		$this->{$property} = $value;
	}
	public function __isset(string $property):bool{
		return isset($this->{$property}) && !$this->isPrivate($property);
	}
	public function __unset(string $property):void{
		if($this->__isset($property)){
			unset($this->{$property});
		}
	}
	public function __toString():string{
		return $this->toJSON();
	}
	final protected function isPrivate(string $property):bool{
		return (new ReflectionProperty($this, $property))->isPrivate();
	}
	final protected function hasSetHook(string $property):bool{
		if(PHP_VERSION_ID < 80400){
			return false;
		}
		return (new ReflectionProperty($this, $property))->hasHook(\PropertyHookType::Set);
	}
	final protected function hasGetHook(string $property):bool{
		if(PHP_VERSION_ID < 80400){
			return false;
		}
		return (new ReflectionProperty($this, $property))->hasHook(\PropertyHookType::Get);
	}
	final protected function throwOnInvalidProperty():bool{
		$attributes = (new ReflectionObject($this))
			->getAttributes(ThrowOnInvalidProperty::class, ReflectionAttribute::IS_INSTANCEOF)
		;
		if($attributes === []){
			return false;
		}
		$attr = $attributes[0]->newInstance();
		return $attr->throwOnInvalid;
	}
	public function toArray():array{
		$properties = (new ReflectionObject($this))
			->getProperties(~(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_READONLY | ReflectionProperty::IS_PRIVATE))
		;
		$data = [];
		foreach($properties as $reflectionProperty){
			$data[$reflectionProperty->name] = $this->__get($reflectionProperty->name);
		}
		return $data;
	}
	public function fromIterable(iterable $properties):static{
		foreach($properties as $key => $value){
			$this->__set($key, $value);
		}
		return $this;
	}
	public function toJSON(int|null $jsonOptions = null):string{
		$json = json_encode($this, ($jsonOptions ?? 0));
		if($json === false){
			throw new JsonException(json_last_error_msg());
		}
		return $json;
	}
	public function fromJSON(string $json):static{
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		return $this->fromIterable($data);
	}
	public function jsonSerialize():array{
		return $this->toArray();
	}
	public function serialize():string{
		return serialize($this);
	}
	public function unserialize(string $data):void{
		$obj = unserialize($data);
		if(!is_object($obj)){
			throw new InvalidArgumentException('The given serialized string is invalid');
		}
		$reflection = new ReflectionObject($obj);
		if(!$reflection->isInstance($this)){
			throw new InvalidArgumentException('The unserialized object does not match the class of this container');
		}
		$properties = $reflection->getProperties(~(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_READONLY));
		$data       = [];
		foreach($properties as $reflectionProperty){
			$data[$reflectionProperty->name] = (PHP_VERSION_ID < 80400)
				? $reflectionProperty->getValue($obj)
				: $reflectionProperty->getRawValue($obj);
		}
		$this->__unserialize($data);
	}
	public function __serialize():array{
		$properties = (new ReflectionObject($this))
			->getProperties(~(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_READONLY))
		;
		$data = [];
		foreach($properties as $reflectionProperty){
			$data[$reflectionProperty->name] = (PHP_VERSION_ID < 80400)
				? $reflectionProperty->getValue($this)
				: $reflectionProperty->getRawValue($this);
		}
		return $data;
	}
	public function __unserialize(array $data):void{
		$reflection = new ReflectionObject($this);
		foreach($data as $key => $value){
			try{
				$reflectionProperty = $reflection->getProperty($key);
				if($reflectionProperty->isStatic() || $reflectionProperty->isReadOnly()){
					continue;
				}
				(PHP_VERSION_ID < 80400)
					? $reflectionProperty->setValue($this, $value)
					: $reflectionProperty->setRawValue($this, $value);
			}
			catch(ReflectionException){
				continue;
			}
		}
	}
}