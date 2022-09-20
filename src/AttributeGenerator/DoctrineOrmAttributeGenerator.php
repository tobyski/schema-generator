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

namespace ApiPlatform\SchemaGenerator\AttributeGenerator;

use ApiPlatform\SchemaGenerator\CardinalitiesExtractor;
use ApiPlatform\SchemaGenerator\Model\Attribute;
use ApiPlatform\SchemaGenerator\Model\Class_;
use ApiPlatform\SchemaGenerator\Model\Property;
use ApiPlatform\SchemaGenerator\Model\Use_;

/**
 * Doctrine attribute generator.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class DoctrineOrmAttributeGenerator extends AbstractAttributeGenerator
{
    private const RESERVED_KEYWORDS = [
        'add',
        'create',
        'delete',
        'group',
        'join',
        'like',
        'update',
        'to',
    ];

    /**
     * {@inheritdoc}
     */
    public function generateClassAttributes(Class_ $class): array
    {
        // @COREMOD
        // if(!str_starts_with($class->name(), '\App\Entity')) {
        //     return [];
        // }

        if ($doctrineAttributes = (isset($this->config['types'][$class->name()]) ? $this->config['types'][$class->name()]['doctrine']['attributes'] : false)) {
            $attributes = [];
            foreach ($doctrineAttributes as $attributeName => $attributeArgs) {
                // @COREMOD
                if($attributeName == 'Indexes') {
                    foreach($attributeArgs as $indexDefinition) {
                        foreach($indexDefinition as $_attributeName => $_attributeArgs) {
                            $attributes[] = new Attribute($_attributeName, $_attributeArgs);
                        }
                    }
                }
                else {
                    $attributes[] = new Attribute($attributeName, $attributeArgs);
                }
            }

            return $attributes;
        }

        if ($class->isEnum()) {
            return [];
        }

        if ($class->isEmbeddable) {
            return [new Attribute('ORM\Embeddable')];
        }

        $attributes = [];
        if ($class->isAbstract) {
            if ($inheritanceAttributes = $this->config['doctrine']['inheritanceAttributes']) {
                $attributes = [];
                foreach ($inheritanceAttributes as $attributeName => $attributeArgs) {
                    $attributes[] = new Attribute($attributeName, $attributeArgs);
                }

                return $attributes;
            }

            $attributes[] = new Attribute('ORM\MappedSuperclass');
        } else {
            $attributes[] = new Attribute('ORM\Entity');
        }

        foreach (self::RESERVED_KEYWORDS as $keyword) {
            if (0 !== strcasecmp($keyword, $class->name())) {
                continue;
            }

            $attributes[] = new Attribute('ORM\Table', ['name' => strtolower($class->name())]);

            return $attributes;
        }

        return $attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function generatePropertyAttributes(Property $property, string $className): array
    {
        // @COREMOD
        // if(!str_starts_with($className, '\App\Entity')) {
        //     return [];
        // }

        if (null === $property->range || null === $property->rangeName) {
            return [];
        }

        // @COREMOD
        // if ($property->ormColumn && !isset($property->cardinality)) {
        //     return [new Attribute('ORM\Column', $property->ormColumn)];
        // }

        // @TODO Need to add support for onDelete and cascade operations
        $ormProperties = isset($property->ormColumn) ? $property->ormColumn: [];

        // move this into relationship def
        $relationProperties = [];
        if(isset($ormProperties['cascade'])) {
            $relationProperties['cascade'] = $ormProperties['cascade'];
            unset($ormProperties['cascade']);
        }

        //Should we limit this by relation type?
        if(isset($ormProperties['orphanRemoval'])) {
            $relationProperties['orphanRemoval'] = $ormProperties['orphanRemoval'];
            unset($ormProperties['orphanRemoval']);
        }

        // if(isset($ormProperties['onDelete'])) {
        //     $relationProperties['onDelete'] = $ormProperties['onDelete'];
        //     unset($ormProperties['onDelete']);
        // }

        if ($property->isId) {
            return $this->generateIdAttributes($className);
        }

        if (isset($this->config['types'][$className]['properties'][$property->name()])) {
            $property->relationTableName = $this->config['types'][$className]['properties'][$property->name()]['relationTableName'];
        }

        $type = null;
        $isDataType = $this->phpTypeConverter->isDatatype($property->range);
        if ($property->isEnum) {
            $type = $property->isArray ? 'simple_array' : 'string';
        } elseif ($property->isArray && $isDataType) {
            $type = 'json';
        } elseif (!$property->isArray && $isDataType && null !== ($phpType = $this->phpTypeConverter->getPhpType($property, $this->config, []))) {
            switch ($property->range->getUri()) {
                // TODO: use more precise types for int (smallint, bigint...)
                case 'http://www.w3.org/2001/XMLSchema#time':
                case 'https://schema.org/Time':
                    $type = 'time';
                    break;
                case 'http://www.w3.org/2001/XMLSchema#dateTime':
                case 'https://schema.org/DateTime':
                    $type = 'datetime';
                    break;
                case 'http://www.w3.org/2001/XMLSchema#date':
                case 'https://schema.org/Date':
                    $type = 'date';
                    break;
                default:
                    $type = $phpType;
                    switch ($phpType) {
                        case 'bool':
                            $type = 'boolean';
                            break;
                        case 'int':
                            $type = 'integer';
                            break;
                        case 'string':
                            $type = 'text';
                            break;
                        case '\\'.\DateTimeInterface::class:
                            $type = 'date';
                            break;
                        case '\\'.\DateInterval::class:
                            $type = 'string';
                            break;
                    }
                    break;
            }
        }

        if (null !== $type) {
            $args = [];
            if ('string' !== $type) {
                $args['type'] = $type;
            }

            if ($property->isNullable) {
                $args['nullable'] = true;
            }

            if ($property->isUnique) {
                $args['unique'] = true;
            }

            foreach (self::RESERVED_KEYWORDS as $keyword) {
                if (0 === strcasecmp($keyword, $property->name())) {
                    $args['name'] = sprintf('`%s`', $property->name());
                    break;
                }
            }

            // @COREMOD
            $args = array_merge($args, $ormProperties);

            return [new Attribute('ORM\Column', $args)];
        }

        if (null === $relationName = $this->getRelationName($property->rangeName)) {
            $this->logger->error('The type "{type}" of the property "{property}" from the class "{class}" doesn\'t exist', ['type' => $property->range->getUri(), 'property' => $property->name(), 'class' => $className]);

            return [];
        }

        if ($property->isEmbedded) {
            return [new Attribute('ORM\Embedded', ['class' => $relationName, 'columnPrefix' => $property->columnPrefix])];
        }

        // remove options if it's been set as not compatible with join columns
        if(isset($ormProperties['options'])) {
            unset($ormProperties['options']);
        }

        $attributes = [];
        switch ($property->cardinality) {
            case CardinalitiesExtractor::CARDINALITY_0_1:
                $attributes[] = new Attribute('ORM\OneToOne', array_merge(['targetEntity' => $relationName], $relationProperties));
                $attributes[] = new Attribute('ORM\JoinColumn', $ormProperties);
                break;
            case CardinalitiesExtractor::CARDINALITY_1_1:
                $attributes[] = new Attribute('ORM\OneToOne', array_merge(['targetEntity' => $relationName], $relationProperties));
                $attributes[] = new Attribute('ORM\JoinColumn', array_merge(['nullable' => false], $ormProperties));
                break;
            case CardinalitiesExtractor::CARDINALITY_UNKNOWN:
            case CardinalitiesExtractor::CARDINALITY_N_0:
                if (null !== $property->inversedBy) {
                    $attributes[] = new Attribute('ORM\ManyToOne', array_merge(['targetEntity' => $relationName, 'inversedBy' => $property->inversedBy], $relationProperties));
                } else {
                    $attributes[] = new Attribute('ORM\ManyToOne', array_merge(['targetEntity' => $relationName], $relationProperties));
                }
                $attributes[] = new Attribute('ORM\JoinColumn', $ormProperties);
                break;
            case CardinalitiesExtractor::CARDINALITY_N_1:
                if (null !== $property->inversedBy) {
                    $attributes[] = new Attribute('ORM\ManyToOne', array_merge(['targetEntity' => $relationName, 'inversedBy' => $property->inversedBy], $relationProperties));
                } else {
                    $attributes[] = new Attribute('ORM\ManyToOne', array_merge(['targetEntity' => $relationName], $relationProperties));
                }
                $attributes[] = new Attribute('ORM\JoinColumn', array_merge(['nullable' => false], $ormProperties));
                break;
            case CardinalitiesExtractor::CARDINALITY_0_N:
                if (null !== $property->mappedBy) {
                    $attributes[] = new Attribute('ORM\OneToMany', array_merge(['targetEntity' => $relationName, 'mappedBy' => $property->mappedBy], $relationProperties));
                } else {
                    $attributes[] = new Attribute('ORM\ManyToMany', array_merge(['targetEntity' => $relationName], $relationProperties));
                }
                if ($property->relationTableName) {
                    $attributes[] = new Attribute('ORM\JoinTable', ['name' => $property->relationTableName]);
                }
                $attributes[] = new Attribute('ORM\InverseJoinColumn', ['unique' => true]);
                break;
            case CardinalitiesExtractor::CARDINALITY_1_N:
                if (null !== $property->mappedBy) {
                    $attributes[] = new Attribute('ORM\OneToMany', array_merge(['targetEntity' => $relationName, 'mappedBy' => $property->mappedBy], $relationProperties));
                } else {
                    $attributes[] = new Attribute('ORM\ManyToMany', array_merge(['targetEntity' => $relationName], $relationProperties));
                }
                if ($property->relationTableName) {
                    $attributes[] = new Attribute('ORM\JoinTable', ['name' => $property->relationTableName]);
                }
                $attributes[] = new Attribute('ORM\InverseJoinColumn', ['nullable' => false, 'unique' => true]);
                break;
            case CardinalitiesExtractor::CARDINALITY_N_N:
                $attributes[] = new Attribute('ORM\ManyToMany', array_merge(['targetEntity' => $relationName], $relationProperties));
                if ($property->relationTableName) {
                    $attributes[] = new Attribute('ORM\JoinTable', ['name' => $property->relationTableName]);
                }
                break;
        }

        return $attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function generateUses(Class_ $class): array
    {
        // @COREMOD
        // if(!str_starts_with($class->name(), '\App\Entity')) {
        //     return [];
        // }

        return $class->isEnum() ? [] : [new Use_('Doctrine\ORM\Mapping', 'ORM')];
    }

    /**
     * @return Attribute[]
     */
    private function generateIdAttributes(string $className): array
    {
        $attributes = [new Attribute('ORM\Id')];

        $idConfig = [];

        if($this->config['id']) {
            $idConfig = array_merge($idConfig, $this->config['id']);
        }

        if($this->config['types'][$className]['pk']) {
            $idConfig = array_merge($idConfig, $this->config['types'][$className]['pk']);
        }

        if ('none' !== $idConfig['generationStrategy'] && !$idConfig['writable']) {
            $attributes[] = new Attribute('ORM\GeneratedValue', ['strategy' => strtoupper($idConfig['generationStrategy'])]);
        }

        switch ($idConfig['generationStrategy']) {
            case 'uuid':
                $type = 'guid';
            break;
            case 'auto':
                $type = 'integer';
            break;
            default:
                $type = 'string';
            break;
        }

        $attributes[] = new Attribute('ORM\Column', ['type' => $type]);

        return $attributes;
    }

    /**
     * Gets class or interface name to use in relations.
     */
    private function getRelationName(string $rangeName): ?string
    {
        if (!isset($this->classes[$rangeName])) {
            return null;
        }

        $class = $this->classes[$rangeName];

        // @COREMOD
        //return $class->name();
        return $rangeName;

        if (null !== $class->interfaceName()) {
            if (isset($this->config['types'][$rangeName]['namespaces']['interface'])) {
                return sprintf('%s\\%s', $this->config['types'][$rangeName]['namespaces']['interface'], $class->interfaceName());
            }

            return sprintf('%s\\%s', $this->config['namespaces']['interface'], $class->interfaceName());
        }

        if (isset($this->config['types'][$rangeName]['namespaces']['class'])) {
            return sprintf('%s\\%s', $this->config['types'][$rangeName]['namespaces']['class'], $class->name());
        }

        return sprintf('%s\\%s', $this->config['namespaces']['entity'], $rangeName);
    }
}
