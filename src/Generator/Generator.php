<?php

namespace Helmich\JsonStructBuilder\Generator;

use Helmich\JsonStructBuilder\Generator\Property\CodeFormatting;
use Helmich\JsonStructBuilder\Generator\Property\OptionalPropertyDecorator;
use Helmich\JsonStructBuilder\Generator\Property\PropertyCollection;
use Helmich\JsonStructBuilder\Generator\Property\PropertyInterface;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlock\Tag\ThrowsTag;
use Zend\Code\Generator\DocBlock\Tag\VarTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Code\Generator\ValueGenerator;

class Generator
{
    use CodeFormatting;

    /** @var GeneratorContext */
    private $ctx;

    public function __construct(GeneratorContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * @param PropertyCollection $properties
     * @return PropertyGenerator[]
     */
    public function generateProperties(PropertyCollection $properties)
    {
        $propertyGenerators = [];

        foreach ($properties as $generator) {
            $schema = $generator->schema();
            $prop = new PropertyGenerator(
                $generator->key(),
                isset($schema["default"]) ? new ValueGenerator($schema["default"]) : null,
                PropertyGenerator::FLAG_PRIVATE
            );

            $prop->setDocBlock(new DocBlockGenerator(
                isset($schema["description"]) ? $schema["description"] : null,
                null,
                [new VarTag(null, $generator->typeAnnotation())]
            ));

            $propertyGenerators[] = $prop;
        }

        return $propertyGenerators;
    }

    /**
     * @param PropertyCollection $properties
     * @return MethodGenerator
     */
    public function generateBuildMethod(PropertyCollection $properties)
    {
        $in = $this->ctx->request;
        $method = new MethodGenerator(
            'buildFromInput',
            [new ParameterGenerator("input", "array")],
            MethodGenerator::FLAG_PUBLIC | MethodGenerator::FLAG_STATIC,
            'static::validateInput($input);' . "\n\n" .
            '$obj = new static;' . "\n" .
            $properties->generateJSONToTypeConversionCode('input') . "\n\n" .
            'return $obj;',
            new DocBlockGenerator(
                "Builds a new instance from an input array", null, [
                    new ParamTag("input", ["array"], "Input data"),
                    new ReturnTag([$this->ctx->request->targetClass], "Created instance"),
                    new ThrowsTag("\\InvalidArgumentException"),
                ]
            )
        );

        if (!$in->php5) {
            $method->setReturnType($in->targetNamespace . "\\" . $in->targetClass);
        }

        return $method;
    }

    /**
     * @param PropertyCollection $properties
     * @return MethodGenerator
     */
    public function generateToJSONMethod(PropertyCollection $properties)
    {
        $in = $this->ctx->request;
        $method = new MethodGenerator(
            'toJson',
            [],
            MethodGenerator::FLAG_PUBLIC,
            '$output = [];' . "\n" .
            $properties->generateTypeToJSONConversionCode('output') . "\n\n" .
            'return $output;',
            new DocBlockGenerator(
                "Converts this object back to a simple array that can be JSON-serialized", null, [
                    new ReturnTag(["array"], "Converted array"),
                ]
            )
        );

        if (!$in->php5) {
            $method->setReturnType("array");
        }

        return $method;
    }

    /**
     * @param PropertyCollection $properties
     * @return MethodGenerator
     */
    public function generateValidateMethod(PropertyCollection $properties)
    {
        $in = $this->ctx->request;
        $method = new MethodGenerator(
            'validateInput',
            [
                new ParameterGenerator("input", $in->php5 ? null : "array"),
                new ParameterGenerator("return", $in->php5 ? null : "bool", false),
            ],
            MethodGenerator::FLAG_PUBLIC | MethodGenerator::FLAG_STATIC,
            '$validator = new \\JsonSchema\\Validator();' . "\n" .
            '$validator->validate($input, static::$schema);' . "\n\n" .
            'if (!$validator->isValid() && !$return) {' . "\n" .
            '    $errors = array_map(function($e) {' . "\n" .
            '        return $e["property"] . ": " . $e["message"];' . "\n" .
            '    }, $validator->getErrors());' . "\n" .
            '    throw new \\InvalidArgumentException(join(", ", $errors));' . "\n" .
            '}' . "\n\n" .
            'return $validator->isValid();',
            new DocBlockGenerator(
                "Validates an input array", null, [
                    new ParamTag("input", ["array"], "Input data"),
                    new ParamTag("return", ["bool"], "Return instead of throwing errors"),
                    new ReturnTag(["bool"], "Validation result"),
                    new ThrowsTag("\\InvalidArgumentException"),
                ]
            )
        );

        if (!$in->php5) {
            $method->setReturnType("bool");
        }

        return $method;
    }

    /**
     * @param PropertyCollection $properties
     * @return MethodGenerator
     */
    public function generateCloneMethod(PropertyCollection $properties)
    {
        $clones = [];

        foreach ($properties as $prop) {
            $c = $prop->cloneProperty();
            if ($c !== null) {
                $clones[] = $c;
            }
        }

        return new MethodGenerator(
            '__clone',
            [],
            MethodGenerator::FLAG_PUBLIC,
            join("\n", $clones)
        );
    }

    /**
     * @param PropertyCollection $properties
     * @return MethodGenerator[]
     */
    public function generateGetterMethods(PropertyCollection $properties)
    {
        $methods = [];

        foreach ($properties as $p) {
            $methods[] = $this->generateGetterMethod($p);
        }

        return $methods;
    }

    /**
     * @param PropertyInterface $property
     * @return MethodGenerator
     */
    public function generateGetterMethod(PropertyInterface $property)
    {
        $key = $property->key();
        $capitalizedName = $this->capitalize($key);
        $annotatedType = $property->typeAnnotation();

        $getMethod = new MethodGenerator(
            'get' . $capitalizedName,
            [],
            MethodGenerator::FLAG_PUBLIC,
            "return \$this->$key;",
            new DocBlockGenerator(null, null, [new ReturnTag($annotatedType)])
        );

        if (!$this->ctx->request->php5) {
            $typeHint = $property->typeHint(7);
            if ($typeHint) {
                $getMethod->setReturnType($typeHint);
            }
        }

        return $getMethod;
    }

    /**
     * @param PropertyCollection $properties
     * @return MethodGenerator[]
     */
    public function generateSetterMethods(PropertyCollection $properties)
    {
        $methods = [];

        foreach ($properties as $p) {
            $methods[] = $this->generateSetterMethod($p);

            if ($p instanceof OptionalPropertyDecorator) {
                $methods[] = $this->generateUnsetterMethod($p);
            }
        }

        return $methods;
    }

    public function generateSetterMethod(PropertyInterface $property)
    {
        $key = $property->key();
        $capitalizedName = $this->capitalize($key);

        $requiredProperty = ($property instanceof OptionalPropertyDecorator) ? $property->unwrap() : $property;

        $annotatedType = $requiredProperty->typeAnnotation();
        $typeHint = $requiredProperty->typeHint($this->ctx->request->php5 ? 5 : 7);

        if ($property->isComplex()) {
            $setterValidation = "";
        } else {
            $setterValidation = "\$validator = new \JsonSchema\Validator();
\$validator->validate(\$$key, static::\$schema['properties']['$key']);
if (!\$validator->isValid()) {
    throw new \InvalidArgumentException(\$validator->getErrors()[0]['message']);
}

";
        }

        $setMethod = new MethodGenerator(
            'with' . $capitalizedName,
            [new ParameterGenerator($key, $typeHint)],
            MethodGenerator::FLAG_PUBLIC,
            $setterValidation . "\$clone = clone \$this;
\$clone->$key = \$$key;

return \$clone;",
            new DocBlockGenerator(null, null, [
                new ParamTag($key, str_replace("|null", "", $annotatedType)),
                new ReturnTag("self"),
            ])
        );

        if (!$this->ctx->request->php5) {
            $setMethod->setReturnType("self");
        }

        return $setMethod;
    }

    /**
     * @param PropertyInterface $property
     * @return MethodGenerator
     */
    public function generateUnsetterMethod(PropertyInterface $property)
    {
        $key = $property->key();
        $capitalizedName = $this->capitalize($key);

        $unsetMethod = new MethodGenerator(
            'without' . $capitalizedName,
            [],
            MethodGenerator::FLAG_PUBLIC,
            "\$clone = clone \$this;
unset(\$clone->$key);

return \$clone;",
            new DocBlockGenerator(null, null, [
                new ReturnTag("self"),
            ])
        );

        if (!$this->ctx->request->php5) {
            $unsetMethod->setReturnType("self");
        }

        return $unsetMethod;
    }
}