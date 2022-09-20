<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\SchemaGenerator\Model;

use Doctrine\Inflector\Inflector;
use EasyRdf\Resource as RdfResource;
use MyCLabs\Enum\Enum as MyCLabsEnum;
use Nette\PhpGenerator\Helpers;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;

final class Class_
{
    use ResolveNameTrait;

    private string $name;
    private RdfResource $resource;
    public string $namespace = '';
    /** @var false|string|null */
    private $parent;
    public ?Interface_ $interface = null;
    /** @var Property[] */
    private array $properties = [];
    /** @var Use_[] */
    private array $uses = [];
    /** @var Attribute[] */
    private array $attributes = [];
    /** @var string[] */
    private array $annotations = [];
    /** @var array|Constant[] */
    private array $constants = [];
    public bool $hasConstructor = false;
    public bool $parentHasConstructor = false;
    public bool $isAbstract = false;
    public bool $hasChild = false;
    public bool $isEmbeddable = false;
    public ?string $security = null;
    /** @var array<string, array<string, string[]>> */
    public array $operations = [];

    private const SCHEMA_ORG_ENUMERATION = 'https://schema.org/Enumeration';

    /**
     * @param false|string|null $parent
     */
    public function __construct(string $name, RdfResource $resource, $parent = null)
    {
        $this->name = $name;
        $this->resource = $resource;
        $this->parent = $parent;
    }

    public function name(): string
    {
        return $this->name;
    }

    // @COREMOD
    public function setName($name): void {
        $this->name = $name;
    }

    public function resource(): RdfResource
    {
        return $this->resource;
    }

    public function resourceUri(): string
    {
        return $this->resource->getUri();
    }

    public function resourceComment(): ?string
    {
        return (string) $this->resource->get('rdfs:comment');
    }

    public function resourceLocalName(): string
    {
        return $this->resource->localName();
    }

    public function isInNamespace(string $namespace): bool
    {
        return $this->namespace === $namespace;
    }

    public function parent(): ?string
    {
        if (false === $this->parent) {
            return '';
        }

        return $this->parent;
    }

    public function hasParent(): bool
    {
        return '' !== $this->parent && null !== $this->parent && false !== $this->parent;
    }

    /**
     * @param false|string|null $parent
     */
    public function withParent($parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function interfaceName(): ?string
    {
        return $this->interface ? $this->interface->name() : null;
    }

    public function interfaceNamespace(): ?string
    {
        return $this->interface ? $this->interface->namespace() : null;
    }

    public function interfaceToNetteFile(string $fileHeader = null): PhpFile
    {
        if (!$this->interface) {
            throw new \LogicException(sprintf("'%s' has no interface attached.", $this->name));
        }

        return $this->interface->toNetteFile($fileHeader);
    }

    /** @return array<string, Property> */
    public function properties(): array
    {
        return $this->properties;
    }

    /** @return array<string, Property> */
    public function uniqueProperties(): array
    {
        return array_filter($this->properties, static fn (Property $property) => $property->isUnique);
    }

    /** @return string[] */
    public function uniquePropertyNames(): array
    {
        return array_map(static fn (Property $property) => $property->name(), array_values($this->uniqueProperties()));
    }

    public function addProperty(Property $property): self
    {
        $this->properties[$property->name()] = $property;

        return $this;
    }

    public function removePropertyByName(string $name): self
    {
        unset($this->properties[$name]);

        return $this;
    }

    public function getPropertyByName(string $name): ?Property
    {
        return $this->properties[$name] ?? null;
    }

    public function hasProperty(string $propertyName): bool
    {
        return isset($this->properties[$propertyName]);
    }

    public function addUse(Use_ $use): self
    {
        if (!\in_array($use, $this->uses, true)) {
            $this->uses[] = $use;
        }

        return $this;
    }

    public function addAttribute(Attribute $attribute): self
    {
        if (!\in_array($attribute, $this->attributes, true)) {
            $this->attributes[] = $attribute;
        }

        return $this;
    }

    public function addAnnotation(string $annotation): self
    {
        if ('' === $annotation || !\in_array($annotation, $this->annotations, true)) {
            $this->annotations[] = $annotation;
        }

        return $this;
    }

    public function addInterfaceAnnotation(string $annotation): self
    {
        if (null !== $this->interface) {
            $this->interface->addAnnotation($annotation);
        }

        return $this;
    }

    public function addConstant(string $key, Constant $constant): self
    {
        $this->constants[$key] = $constant;

        return $this;
    }

    /** @return Constant[] */
    public function constants(): array
    {
        return $this->constants;
    }

    /**
     * @return RdfResource[]
     */
    public function getSubClassOf(): array
    {
        return array_filter($this->resource->all('rdfs:subClassOf', 'resource'), static fn (RdfResource $resource) => !$resource->isBNode());
    }

    public function isEnum(): bool
    {
        $subClassOf = $this->resource->get('rdfs:subClassOf');

        return $subClassOf && self::SCHEMA_ORG_ENUMERATION === $subClassOf->getUri();
    }

    public function isParentEnum(): bool
    {
        if (!$this->hasParent()) {
            return false;
        }

        return 'Enum' === $this->parent;
    }

    /**
     * @param Configuration $config
     */
    public function toNetteFile(array $config, Inflector $inflector, ?PhpFile $file = null): PhpFile
    {
        $useDoctrineCollections = $config['doctrine']['useCollection'];
        $useAccessors = $config['accessorMethods'];
        $useFluentMutators = $config['fluentMutatorMethods'];
        $fileHeader = $config['header'] ?? null;
        $fieldVisibility = $config['fieldVisibility'];

        $file ??= new PhpFile();
        if (null !== $fileHeader && false !== $fileHeader && !$file->getComment()) {
            // avoid nested doc-block for configurations that already have * as delimiter
            $file->setComment(Helpers::unformatDocComment($fileHeader));
        }

        $namespace = $file->getNamespaces()[$this->namespace] ?? null;
        if (!$namespace) {
            $namespace = $file->addNamespace($this->namespace);
        }

        foreach ($this->uses as $use) {
            $namespace->addUse($use->name(), $use->alias());
        }

        $class = $namespace->getClasses()[$this->name] ?? null;
        if (!$class) {
            $class = $namespace->addClass($this->name);
        }

        $netteAttributes = $class->getAttributes();
        foreach ($this->attributes as $attribute) {
            $hasAttribute = false;
            foreach ($class->getAttributes() as $netteAttribute) {
                if ($netteAttribute->getName() === $this->resolveName($namespace, $attribute->name())) {
                    $hasAttribute = true;
                }
            }
            if (!$hasAttribute) {
                $netteAttributes[] = $attribute->toNetteAttribute($namespace);
            }
        }
        $class->setAttributes($netteAttributes);

        if (!$class->getComment()) {
            foreach ($this->annotations as $annotation) {
                $class->addComment($annotation);
            }
        }

        $class->setAbstract($this->isAbstract);

        if (null !== $this->interfaceName()) {
            $interfaceImplement = $this->resolveName($namespace, $this->interfaceName());
            $implements = $class->getImplements();
            if (!\in_array($interfaceImplement, $implements, true)) {
                $implements[] = $interfaceImplement;
            }
            $class->setImplements($implements);
        }

        if ($this->parent() && !$class->getExtends()) {
            $parentExtend = $this->resolveName($namespace, $this->parent());
            if ($this->isParentEnum()) {
                $parentExtend = MyCLabsEnum::class;
            }

            $class->setExtends($parentExtend);
        }

        $netteConstants = $class->getConstants();
        foreach ($this->constants as $constant) {
            if (!isset($class->getConstants()[$constant->name()])) {
                $netteConstants[] = $constant->toNetteConstant();
            }
        }
        $class->setConstants($netteConstants);

        $sortedProperties = isset($this->properties['id']) ? ['id' => $this->properties['id']] + $this->properties : $this->properties;

        $netteProperties = [];
        foreach ($class->getProperties() as $netteProperty) {
            $hasProperty = false;
            foreach ($sortedProperties as $property) {
                if ($property->name() === $netteProperty->getName()) {
                    $hasProperty = true;
                }
            }
            if (!$hasProperty) {
                $netteProperties[] = $netteProperty;
            }
        }
        foreach ($sortedProperties as $property) {
            $netteProperty = $class->hasProperty($property->name()) ? $class->getProperty($property->name()) : null;
            $netteProperties[] = $property->toNetteProperty($namespace, $fieldVisibility, $useDoctrineCollections, $netteProperty);
        }
        $class->setProperties($netteProperties);

        if ($useDoctrineCollections && $this->hasConstructor && !$class->hasMethod('__construct')) {
            $constructor = new Method('__construct');
            if ($this->parentHasConstructor) {
                $constructor->addBody('parent::__construct();');
            }

            foreach ($sortedProperties as $property) {
                if ($property->isArray && 'array' !== $property->typeHint && !$property->isEnum) {
                    $constructor->addBody('$this->? = new ArrayCollection();', [$property->name()]);
                }
            }

            if ('' !== $constructor->getBody()) {
                $class->addMember($constructor);
            }
        }

        if ($useAccessors) {
            //@COREMOD

            // default our list of methods as to what is in the template
            $methods = $class->getMethods();

            // a full list of methods we want to add based on getters/setters for properties
            foreach ($sortedProperties as $property) {
                foreach ($property->generateNetteMethods(static function ($string) use ($inflector) {
                    return $inflector->singularize($string);
                }, $namespace, $useDoctrineCollections, $useFluentMutators) as $method) {
                    // only add the method if it hasn't already been defined by our template
                    if(!isset($methods[$method->getName()])) {
                        $methods[$method->getName()] = $method;
                    }
                }
            }

            $class->setMethods($methods);

            // this code block will prioritise the generated getters and setters over our 
            // own template functions which we do not want

            // it also does a horrible wierd argument merge which doesn't make much sense
            // and seems to duplicate it's own logic

            // $netteMethods = [];
            // foreach ($class->getMethods() as $netteMethod) {
            //     $hasMethod = false;
            //     foreach ($methods as $method) {
            //         if ($method->getName() === $netteMethod->getName()) {
            //             $hasMethod = true;
            //         }
            //     }
            //     if (!$hasMethod) {
            //         $netteMethods[] = $netteMethod;
            //     }
            // }

            // foreach ($methods as $method) {
            //     if (!$class->hasMethod($method->getName())) {
            //         $netteMethods[] = $method;
            //         continue;
            //     }
            //     $netteMethod = $class->getMethod($method->getName());
            //     $netteAttributes = $netteMethod->getAttributes();
            //     foreach ($method->getAttributes() as $attribute) {
            //         $hasAttribute = false;
            //         foreach ($netteMethod->getAttributes() as $netteAttribute) {
            //             if ($netteAttribute->getName() === $attribute->getName()) {
            //                 $hasAttribute = true;
            //             }
            //         }
            //         if (!$hasAttribute) {
            //             $netteAttributes[] = $attribute;
            //         }
            //     }
            //     $method->setAttributes($netteAttributes);
            //     $netteMethods[] = $method;
            // }

            // $class->setMethods($netteMethods);
        }

        return $file;
    }
}
