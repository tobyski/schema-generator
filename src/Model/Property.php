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

use EasyRdf\Resource as RdfResource;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Property as NetteProperty;

final class Property
{
    use ResolveNameTrait;

    private string $name;
    public ?RdfResource $resource = null;
    public string $cardinality;
    public ?RdfResource $range = null;
    public ?string $rangeName = null;
    /** @var array<string, string|string[]> */
    public ?array $ormColumn = null;
    public bool $isArray = false;
    public bool $isReadable = true;
    public bool $isReadableLink = true;
    public bool $isWritable = true;
    public bool $isWritableLink = true;
    public bool $isNullable = true;
    public bool $isUnique = false;
    public bool $isCustom = false;
    public bool $isEmbedded = false;
    public ?string $mappedBy = null;
    public ?string $inversedBy = null;
    /** @var string|bool */
    public $columnPrefix = false;
    public bool $isId = false;
    public ?string $typeHint = null;
    public ?string $relationTableName = null;
    public bool $isEnum = false;
    public ?string $adderRemoverTypeHint = null;
    /** @var string[] */
    public array $groups = [];
    public ?string $security = null;
    /** @var Attribute[] */
    private array $attributes = [];
    /** @var string[] */
    private array $annotations = [];
    /** @var string[] */
    private array $getterAnnotations = [];
    /** @var string[] */
    private array $setterAnnotations = [];
    /** @var string[] */
    private array $adderAnnotations = [];
    /** @var string[] */
    private array $removerAnnotations = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
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

    public function addGetterAnnotation(string $annotation): self
    {
        if ('' === $annotation || !\in_array($annotation, $this->getterAnnotations, true)) {
            $this->getterAnnotations[] = $annotation;
        }

        return $this;
    }

    public function addSetterAnnotation(string $annotation): self
    {
        if ('' === $annotation || !\in_array($annotation, $this->setterAnnotations, true)) {
            $this->setterAnnotations[] = $annotation;
        }

        return $this;
    }

    public function addAdderAnnotation(string $annotation): self
    {
        if ('' === $annotation || !\in_array($annotation, $this->adderAnnotations, true)) {
            $this->adderAnnotations[] = $annotation;
        }

        return $this;
    }

    /**
     * @return string[]
     */
    public function adderAnnotations(): array
    {
        return $this->adderAnnotations;
    }

    public function addRemoverAnnotation(string $annotation): self
    {
        if ('' === $annotation || !\in_array($annotation, $this->removerAnnotations, true)) {
            $this->removerAnnotations[] = $annotation;
        }

        return $this;
    }

    public function resourceUri(): ?string
    {
        return $this->resource ? $this->resource->getUri() : null;
    }

    public function markAsCustom(): self
    {
        $this->isCustom = true;

        return $this;
    }

    public function toNetteProperty(PhpNamespace $namespace, string $visibility = null, bool $useDoctrineCollections = true, ?NetteProperty $property = null): NetteProperty
    {
        $property ??= new NetteProperty($this->name);

        $property->setVisibility($visibility ?? ClassType::VISIBILITY_PRIVATE);

        if ($this->typeHint) {
            $property->setType($this->resolveName($namespace, $this->typeHint));
        }

        if (!$this->isArray || $this->isTypeHintedAsCollection()) {
            $property->setNullable($this->isNullable);
        }

        if (($default = $this->guessDefaultGeneratedValue($useDoctrineCollections)) !== -1) {
            $property->setValue($default);
        }

        $netteAttributes = $property->getAttributes();
        foreach ($this->attributes as $attribute) {
            $hasAttribute = false;
            foreach ($property->getAttributes() as $netteAttribute) {
                if ($netteAttribute->getName() === $this->resolveName($namespace, $attribute->name())) {
                    $hasAttribute = true;
                }
            }
            if (!$hasAttribute) {
                $netteAttributes[] = $attribute->toNetteAttribute($namespace);
            }
        }
        $property->setAttributes($netteAttributes);

        if (!$property->getComment()) {
            foreach ($this->annotations as $annotation) {
                $property->addComment($annotation);
            }
        }

        return $property;
    }

    /**
     * @return Method[]
     */
    public function generateNetteMethods(
        \Closure $singularize,
        PhpNamespace $namespace,
        bool $useDoctrineCollections = true,
        bool $useFluentMutators = false
    ): array {
        return array_merge(
            $this->generateMutators($singularize, $namespace, $useDoctrineCollections, $useFluentMutators),
            $this->isReadable ? [$this->generateGetter($namespace)] : []
        );
    }

    private function generateGetter(PhpNamespace $namespace): Method
    {
        if (!$this->isReadable) {
            throw new \LogicException(sprintf("Property '%s' is not readable.", $this->name));
        }

        $getter = (new Method('get'.ucfirst($this->name)))->setVisibility(ClassType::VISIBILITY_PUBLIC);
        foreach ($this->getterAnnotations as $annotation) {
            $getter->addComment($annotation);
        }
        if ($this->typeHint) {
            $getter->setReturnType($this->resolveName($namespace, $this->typeHint));
            if ($this->isNullable && !$this->isArray) {
                $getter->setReturnNullable();
            }
        }
        $getter->setBody('return $this->?;', [$this->name()]);

        return $getter;
    }

    /**
     * @return Method[]
     */
    private function generateMutators(
        \Closure $singularize,
        PhpNamespace $namespace,
        bool $useDoctrineCollections = true,
        bool $useFluentMutators = false
    ): array {
        if (!$this->isWritable) {
            return [];
        }

        $mutators = [];
        if ($this->isArray) {
            $singularProperty = $singularize($this->name());

            $adder = (new Method('add'.ucfirst($singularProperty)))->setVisibility(ClassType::VISIBILITY_PUBLIC);
            $adder->setReturnType($useFluentMutators ? 'self' : 'void');
            foreach ($this->adderAnnotations() as $annotation) {
                $adder->addComment($annotation);
            }
            $parameter = $adder->addParameter($singularProperty);
            if ($this->typeHint && !$this->isEnum) {
                $parameter->setType($this->adderRemoverTypeHint ? $this->resolveName($namespace, $this->adderRemoverTypeHint) : $this->adderRemoverTypeHint);
            }
            $adder->addBody(
                sprintf('$this->%s[] = %s;', $this->name(), ($this->isEnum ? '(string) ' : '')."$$singularProperty")
            );
            if ($useFluentMutators) {
                $adder->addBody('');
                $adder->addBody('return $this;');
            }
            $mutators[] = $adder;

            $remover = (new Method('remove'.ucfirst($singularProperty)))->setVisibility(ClassType::VISIBILITY_PUBLIC);
            $remover->setReturnType($useFluentMutators ? 'self' : 'void');
            foreach ($this->removerAnnotations as $annotation) {
                $adder->addComment($annotation);
            }
            $parameter = $remover->addParameter($singularProperty);
            if ($this->typeHint) {
                $parameter->setType($this->adderRemoverTypeHint ? $this->resolveName($namespace, $this->adderRemoverTypeHint) : $this->adderRemoverTypeHint);
            }

            if ($useDoctrineCollections && $this->typeHint && 'array' !== $this->typeHint && !$this->isEnum) {
                $remover->addBody(sprintf(
                    '$this->%s->removeElement(%s);',
                    $this->name(),
                    "$$singularProperty"
                ));
            } else {
                $remover->addBody(sprintf(<<<'PHP'
if (false !== $key = array_search(%s, %s, true)) {
    unset($this->%s[$key]);
}
PHP,
                    ($this->isEnum ? '(string)' : '').'$'.$singularProperty, '$this->'.$this->name().($this->isNullable ? ' ?? []' : ''), $this->name()));
            }

            if ($useFluentMutators) {
                $remover->addBody('');
                $remover->addBody('return $this;');
            }

            $mutators[] = $remover;
        } else {
            $setter = (new Method('set'.ucfirst($this->name())))->setVisibility(ClassType::VISIBILITY_PUBLIC);
            $setter->setReturnType($useFluentMutators ? 'self' : 'void');
            foreach ($this->setterAnnotations as $annotation) {
                $setter->addComment($annotation);
            }
            $setter->addParameter($this->name())
                   ->setType($this->typeHint ? $this->resolveName($namespace, $this->typeHint) : $this->typeHint)
                   ->setNullable($this->isNullable);

            $setter->addBody('$this->? = $?;', [$this->name(), $this->name()]);
            if ($useFluentMutators) {
                $setter->addBody('');
                $setter->addBody('return $this;');
            }
            $mutators[] = $setter;
        }

        return $mutators;
    }

    /**
     * @return array{}|int|null
     */
    private function guessDefaultGeneratedValue(bool $useDoctrineCollections = true)
    {
        if ($this->isArray && !$this->isTypeHintedAsCollection() && ($this->isEnum || !$this->typeHint || 'array' === $this->typeHint || !$useDoctrineCollections)) {
            return [];
        }

        if ($this->isNullable) {
            return null;
        }

        return -1;
    }

    private function isTypeHintedAsCollection(): bool
    {
        return 'Collection' === $this->typeHint;
    }
}
