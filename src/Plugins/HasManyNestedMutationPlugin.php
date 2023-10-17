<?php

namespace Internetrix\GraphQLNestedMutations\Plugins;

use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Schema\DataObject\FieldAccessor;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\ModelMutation;
use SilverStripe\GraphQL\Schema\Interfaces\ModelMutationPlugin;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\GraphQL\Schema\Type\InputType;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

class HasManyNestedMutationPlugin implements ModelMutationPlugin
{
    const IDENTIFIER = 'mutateHasManyRelations';

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
        return [static::class, 'mutateHasManyRelations'];
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
        $hasMany = $instance->hasMany();
        foreach($hasMany as $relationship => $class){
            $descendants = array_values(ClassInfo::subclassesFor($class));
            foreach ($descendants as $descendant){
                $classTypeName = $schema->getModelByClassName($descendant)->getModel()->getTypeName();
                if($classTypeName){
                    $typeNameMap[$descendant] = $classTypeName;
                }
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
    protected function addInputTypesToSchema(DataObject $instance, Schema $schema, Type $parentInputType, $mutationType){
        $hasMany = $instance->hasMany();
        foreach($hasMany as $relationship => $class){

            $descendants = array_values(ClassInfo::subclassesFor($class));

            if(count($descendants) > 1){
                $hasManyDescendantsModelType = InputType::create($mutationType . $schema->getConfig()->getTypeNameForClass($class) . 'DescendantsInput');
                $schema->addType($hasManyDescendantsModelType);

                foreach($descendants as $descendant){
                    $modeTypeName = $schema->getModelByClassName($descendant)->getModel()->getTypeName();
                    $hasManyModelType = $schema->getType($mutationType . $modeTypeName . 'Input');

                    if($hasManyModelType){
                        $hasManyDescendantsModelType->addField($modeTypeName, '[' . $hasManyModelType->getName() . ']');
                    }
                }

                $parentInputType->addField(FieldAccessor::formatField($relationship), $hasManyDescendantsModelType->getName());
            }else{
                $typeName = $mutationType . $schema->getConfig()->getTypeNameForClass($class) . 'Input';

                $hasManyModelType = $schema->getType($typeName);

                if($hasManyModelType){
                    $parentInputType->addField(FieldAccessor::formatField($relationship), '[' . $hasManyModelType->getName() . ']');
                }
            }
        }
    }

    /**
     * @param array $context
     * @return Closure
     */
    public static function mutateHasManyRelations(array $context)
    {
        $typeNameMap = $context['typeNameMap'] ?? null;

        return function ($obj, array $args, array $context, ResolveInfo $info) use ($typeNameMap) {

            $input = $args['input'];
            $hasMany = $obj->hasMany();

            if($hasMany) {
                foreach ($hasMany as $relationship => $relationshipClass) {

                    if (isset($input[FieldAccessor::formatField($relationship)])) {
                        $descendants = array_values(ClassInfo::subclassesFor($relationshipClass));
                        foreach($descendants as $class){
                            $data = $input[FieldAccessor::formatField($relationship)];

                            //this means the input will be split between the descendant types
                            if(count($descendants) > 1){

                                if(!isset($typeNameMap[$class])) {
                                    continue;
                                }

                                if(!isset($data[$typeNameMap[$class]])){
                                    continue;
                                }
                                $data = $data[$typeNameMap[$class]];
                            }

                            /* @var $hasManyList DataList */
                            $hasManyList = $obj->$relationship();

                            $toCreate = [];
                            $update = [];
                            $updateIDs = [];
                            foreach($data as $d){
                                if(isset($d['id']) && $d['id']){
                                    $update[$d['id']] = $d;
                                    $updateIDs[] = $d['id'];
                                }else{
                                    $toCreate[] = $d;
                                }
                            }

                            if($hasManyList && $hasManyList->count()){
                                if(!empty($updateIDs)){
                                    /* @var $toUpdate DataList */
                                    $toUpdate = clone $hasManyList;
                                    $toUpdate = $toUpdate->filter('ID', $updateIDs);
                                    $nestedOperation = 'update' . $typeNameMap[$class];
                                    foreach($toUpdate as $tu){
                                        call_user_func([HasManyNestedMutationPlugin::class, 'mutateRelatedObject'], $nestedOperation, $update[$tu->ID], $context, $info);
                                    }
                                }

                                $toDelete = clone $hasManyList;

                                if(count($descendants) > 1){
                                    $toDelete = $toDelete->filter('ClassName', $class);
                                }

                                if(!empty($updateIDs)){
                                    $toDelete = $toDelete->exclude('ID', $updateIDs);
                                }

                                if($toDelete->count()){
                                    $nestedOperation = 'delete' . $typeNameMap[$class] . 's';
                                    call_user_func([HasManyNestedMutationPlugin::class, 'deleteRelatedObject'], $nestedOperation, $toDelete->column("ID"), $context, $info);
                                }

                            }

                            if(!empty($toCreate)){
                                foreach($toCreate as $tc){
                                    $nestedOperation = 'create' . $typeNameMap[$class];
                                    $item = call_user_func([HasManyNestedMutationPlugin::class, 'mutateRelatedObject'], $nestedOperation, $tc, $context, $info);

                                    $hasManyList->add($item);
                                }
                            }
                        }
                    }
                }
            }

            return $obj;
        };
    }

    public static function mutateRelatedObject($nestedOperation, $input, $context, $info){

        try{
            $fieldDefintion = $info->schema->getMutationType()->getField($nestedOperation);
        }catch(InvariantViolation $exception){
            throw $exception;
        }

       return call_user_func($fieldDefintion->resolveFn, null, ['input' => $input], $context, $info);

    }

    public static function deleteRelatedObject($nestedOperation, $ids, $context, $info){

        //delete is a little special in the this has...if the mutation doesn't exist..don't throw an error
        $mutation = $info->schema->getMutationType();
        if($mutation->hasField($nestedOperation)){
            try{
                $fieldDefintion = $mutation->getField($nestedOperation);
            }catch(InvariantViolation $exception){
                throw $exception;
            }

            return call_user_func($fieldDefintion->resolveFn, null, ['ids' => $ids], $context, $info);
        }
    }
}
