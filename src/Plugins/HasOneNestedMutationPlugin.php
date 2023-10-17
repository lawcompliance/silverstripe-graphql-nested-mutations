<?php

namespace Internetrix\GraphQLNestedMutations\Plugins;

use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Schema\DataObject\FieldAccessor;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\ModelMutation;
use SilverStripe\GraphQL\Schema\Interfaces\ModelMutationPlugin;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\ORM\DataObject;

class HasOneNestedMutationPlugin implements ModelMutationPlugin
{
    const IDENTIFIER = 'mutateHasOneRelations';

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * @return array
     */
    protected function getResolver(array $config): array
    {
        return [static::class, 'mutateHasOneRelations'];
    }

    /**
     * @param ModelMutation $mutation
     * @param Schema $schema
     * @param array $config
     * @throws SchemaBuilderException
     */
    public function apply(ModelMutation $mutation, Schema $schema, array $config = []): void
    {
        $dataClass = $mutation->getModel()->getSourceClass();
        $instance =  Injector::inst()->get($dataClass);

        $prefix = ucfirst($mutation->getName());
        $mutationType = (stripos($prefix, 'Update') === 0) ? 'Update' : 'Create';

        $parentInputTypeName = $prefix . 'Input';
        $parentInputType = $schema->getType($parentInputTypeName);

        Schema::invariant(
            $parentInputType,
            'Could not find the input type %s.',
            $parentInputTypeName
        );

        $this->addInputTypesToSchema($instance, $schema, $parentInputType, $mutationType);

        $context = [
            'typeNameMap' => $this->buildTypeNameMap($instance, $schema)
        ];

        $mutation->addResolverAfterware(
            $this->getResolver($config),
            $context
        );
    }

    /**
     * @param DataObject $instance
     * @param Schema $schema
     * @return array
     */
    private function buildTypeNameMap(DataObject $instance, Schema $schema){
        $typeNameMap = [];
        $hasOnes = $instance->hasOne();
        foreach($hasOnes as $relationship => $class){
            $classTypeName = $schema->getConfig()->getTypeNameForClass($class);
            if($classTypeName){
                $typeNameMap[$class] = $classTypeName;
            }
        }
        return $typeNameMap;
    }

    /**
     * @param DataObject $instance
     * @param Schema $schema
     * @param Type $parentInputType
     * @param string $mutationType
     */
    private function addInputTypesToSchema(DataObject $instance, Schema $schema, Type $parentInputType, $mutationType){
        $hasOnes = $instance->hasOne();
        foreach($hasOnes as $relationship => $class){
            $typeName = $mutationType . $schema->getConfig()->getTypeNameForClass($class) . 'Input';
            $hasOneModelType = $schema->getType($typeName);

            if($hasOneModelType){
                $parentInputType->addField(FieldAccessor::formatField($relationship), ['type' => $hasOneModelType->getName()]);
            }
        }
    }

    /**
     * @param array $context
     * @return Closure
     */
    public static function mutateHasOneRelations(array $context)
    {
        $typeNameMap = $context['typeNameMap'] ?? null;

        return function (DataObject $obj, array $args, array $context, ResolveInfo $info) use ($typeNameMap) {
            $input = $args['input'];
            $hasOnes = $obj->hasOne();

            if($hasOnes){
                $shouldWrite = false;
                foreach($hasOnes as $relationship => $class){
                    $relationshipInput = $input[FieldAccessor::formatField($relationship)] ?? null;
                    if($relationshipInput && isset($typeNameMap[$class])){
                        $nestedOperation = (isset($relationshipInput['id']) ? 'update' : 'create') . $typeNameMap[$class];

                        try{
                            $fieldDefintion = $info->schema->getMutationType()->getField($nestedOperation);
                        }catch(InvariantViolation $exception){
                            throw $exception;
                        }

                        $relObj = call_user_func($fieldDefintion->resolveFn, null, ['input' => $relationshipInput], $context, $info);

                        if($relObj && $relObj->exists()){
                            $obj->{$relationship . 'ID'} = $relObj->ID;
                            $shouldWrite = true;
                        }
                    }
                }

                if($shouldWrite){
                    $obj->write();
                }
            }

            return $obj;
        };
    }
}
