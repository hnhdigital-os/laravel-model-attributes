<?php

namespace HnhDigital\ModelAttributes\Concerns;

use Illuminate\Support\Str;
use Validator;

trait HasAttributes
{
    /**
     * Default cast as definitions.
     *
     * @var array
     */
    protected $default_cast_as_definitions = [
        'uuid'       => 'asString',
        'int'        => 'asInt',
        'integer'    => 'asInt',
        'real'       => 'asFloat',
        'float'      => 'asFloat',
        'double'     => 'asFloat',
        'string'     => 'asString',
        'bool'       => 'asBool',
        'boolean'    => 'asBool',
        'object'     => 'asObject',
        'array'      => 'fromJson',
        'json'       => 'fromJson',
        'collection' => 'asCollection',
        'date'       => 'asDate',
        'datetime'   => 'asDateTime',
        'timestamp'  => 'asTimestamp',
    ];

    /**
     * Default cast to definitions.
     *
     * @var array
     */
    protected $default_cast_to_definitions = [
        'date'       => 'castAsDateTime',
        'object'     => 'castAttributeAsJson',
        'array'      => 'castAttributeAsJson',
        'json'       => 'castAttributeAsJson',
        'collection' => 'castAttributeAsJson',
    ];

    /**
     * Validator.
     *
     * @var Validator.
     */
    private $validator;

    /**
     * Return a list of the attributes on this model.
     *
     * @return array
     */
    public function getValidAttributes()
    {
        return array_keys($this->structure);
    }

    /**
     * Is key a valid attribute?
     *
     * @return boolean
     */
    public function isValidAttribute($key)
    {
        return array_has($this->structure, $key);
    }

    /**
     * Has write access to a given key on this model.
     *
     * @param string $key
     *
     * @return boolean
     */
    public function hasWriteAccess($key)
    {
        if (static::$unguarded) {
            return true;
        }

        // Attribute is guarded.
        if (in_array($key, $this->getGuarded())) {
            return false;
        }

        if (($method = $this->getAuthMethod($key)) !== false) {
            return $this->$method($key);
        }

        // Check for the presence of a mutator for the auth operation
        // which simply lets the developers check if the current user
        // has the authority to update this value.
        if ($this->hasAuthAttributeMutator($key)) {
            $method = 'auth'.Str::studly($key).'Attribute';

            return $this->{$method}($value);
        }

        return true;
    }

    /**
     * Set default values on this attribute.
     *
     * @return $this
     */
    public function setDefaultValuesForAttributes()
    {
        // Only works on new models.
        if ($this->exists) {
            return $this;
        }

        // Defaults on attributes.
        $defaults = $this->getAttributesFromStructure('default', true);

        // Remove attributes that have been given values.
        $defaults = array_except($defaults, array_keys($this->getDirty()));

        // Unguard.
        static::unguard();

        // Allocate values.
        foreach ($defaults as $key => $value) {
            $this->{$key} = $value;
        }

        // Reguard.
        static::reguard();

        return $this;
    }

    /**
     * Get the casts array.
     *
     * @return array
     */
    public function getCasts()
    {
        if ($this->getIncrementing()) {
            return array_merge([$this->getKeyName() => $this->getKeyType()], $this->getAttributesFromStructure('cast', true));
        }

        return $this->getAttributesFromStructure('cast', true);
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        if (is_null($value)) {
            return $value;
        }

        if (($method = $this->getCastAsMethod($key)) === false) {
            return $value;
        }

        // Casting method is local.
        if (is_string($method) && method_exists($this, $method)) {
            return $this->$method($value);
        }

        return $value;
    }

    /**
     * Get the method to cast the value of this given key.
     * (when using getAttribute)
     *
     * @param string $key
     *
     * @return string|array
     */
    protected function getCastAsMethod($key)
    {
        return $this->getCastAsDefinition($this->getCastType($key));
    }

    /**
     * Get the method to cast this attribte type.
     *
     * @param string $type
     *
     * @return string|array|bool
     */
    protected function getCastAsDefinition($type)
    {
        if (array_has($this->default_cast_as_definitions, $type)) {
            return array_get($this->default_cast_as_definitions, $type);
        }

        return false;
    }

    /**
     * Get the auths array.
     *
     * @return array
     */
    protected function getAuths()
    {
        return $this->getAttributesFromStructure('auth', true);
    }

    /**
     * Get auth method.
     *
     * @param string $key
     *
     * @return string|array
     */
    public function getAuthMethod($key)
    {
        if (array_has($this->getAuths(), $key)) {
            $method = array_get($this->getAuths(), $key);

            return method_exists($this, $method) ? $method : false;
        }

        return false;
    }

    /**
     * Set a given attribute on the model.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // the model, such as "json_encoding" an listing of data for storage.
        if ($this->hasSetMutator($key)) {
            $method = 'set'.Str::studly($key).'Attribute';

            return $this->{$method}($value);
        }

        // Get the method that modifies this value before storing.
        $method = $this->getCastToMethod($key);

        // Casting method is local.
        if (is_string($method) && method_exists($this, $method)) {
            $value = $this->$method($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (Str::contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Determine if a auth check exists for an attribute.
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasAuthAttributeMutator($key)
    {
        return method_exists($this, 'auth'.Str::studly($key).'Attribute');
    }

    /**
     * Get the method to cast the value of this given key.
     * (when using setAttribute)
     *
     * @param string $key
     *
     * @return string|array
     */
    protected function getCastToMethod($key)
    {
        return $this->getCastToDefinition($this->getCastType($key));
    }

    /**
     * Get the method to cast this attribte back to it's original form.
     *
     * @param string $type
     *
     * @return string|array|bool
     */
    protected function getCastToDefinition($type)
    {
        if (array_has($this->default_cast_to_definitions, $type)) {
            return array_get($this->default_cast_to_definitions, $type);
        }

        return false;
    }

    /**
     * Cast value as an int.
     *
     * @param mixed $value
     *
     * @return int
     */
    protected function asInt($value)
    {
        return (int) $value;
    }

    /**
     * Cast value as an int.
     *
     * @param mixed $value
     *
     * @return float
     */
    protected function asFloat($value)
    {
        return (float) $value;
    }

    /**
     * Cast value as an int.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function asString($value)
    {
        return (string) $value;
    }

    /**
     * Cast value as an int.
     *
     * @param mixed $value
     *
     * @return array
     */
    protected function asObject($value)
    {
        return $this->fromJson($value, true);
    }

    /**
     * Cast date as a DateTime instance.
     *
     * @return DateTime
     */
    protected function castAsDateTime($value)
    {
        return $this->fromDateTime($value);
    }

    /**
     * Get the attributes that have been changed since last sync.
     *
     * @return array
     */
    public function getDirty()
    {
        return $this->eloquentGetDirty();
    }

    /**
     * Validate the model before saving.
     *
     * @return array
     */
    public function savingValidation()
    {
        $this->validator = Validator::make($this->getDirty(), $this->getAttributeRules());

        if ($this->validator->fails()) {

            return false;
        }

        return true;
    }

    /**
     * Get rules for attributes.
     *
     * @return array
     */
    public function getAttributeRules()
    {
        $result = [];
        $attributes = $this->getAttributesFromStructure();
        $casts = $this->getCasts();
        $rules = $this->getAttributesFromStructure('rules', true);

        // Build full rule for each attribute.
        foreach ($attributes as $key) {
            $result[$key] = [];

            if ($this->exists) {
                $result[$key][] = 'sometimes';
            }
        }

        // Build full rule for each attribute.
        foreach ($casts as $key => $cast_type) {
            $result[$key][] = $this->parseAttributeType($cast_type);
        }

        // Assign specified rules.
        foreach ($rules as $key => $rule) {
            $result[$key][] = $rule;
        }

        unset($result[$this->getKeyName()]);

        foreach ($result as $key => $rules) {
            $result[$key] = implode('|', $rules);
        }

        return $result;
    }

    /**
     * Convert attribute type to validation type.
     *
     * @param string $type
     *
     * @return string
     */
    private function parseAttributeType($type)
    {
        switch ($type) {
            case 'bool':
                return 'boolean';
            case 'int':
                return 'integer';
            case 'real':
            case 'float':
            case 'double':
                return 'numeric';
            case 'datetime':
                return 'date';
        }

        return $type;
    }

    /**
     * Get the validator instance.
     *
     * @return Validator
     */
    public function getValidator()
    {
        return $this->validator;
    }

    /**
     * Get invalid attributes.
     *
     * @return array
     */
    public function getInvalidAttributes()
    {
        if (is_null($this->getValidator())) {
            return [];
        }

        return $this->getValidator()->errors()->messages();
    }

    /**
     * Get invalid attributes.
     *
     * @return array
     */
    public function getInvalidMessage()
    {
        if (is_null($this->getValidator())) {
            return [];
        }

        return $this->getValidator()->errors()->all();
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        if ($this->isValidAttribute($key) && $this->hasWriteAccess($key)) {
            $this->setAttribute($key, $value);
        }
    }
}
