<?php

namespace Internetrix\GraphQLNestedMutations\Extensions;

use Exception;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\GraphQL\Controller;
use SilverStripe\GraphQL\Manager;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Create;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Delete;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\MutationScaffolder;
use SilverStripe\GraphQL\Scaffolding\StaticSchema;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\GraphQL\Scaffolding\Interfaces\ResolverInterface;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\Core\ClassInfo;

class NestedCreateOrUpdateScaffolderExtension extends Extension
{

    public function onBeforeAddToManager(MutationScaffolder $scaffolder, Manager $manager){
        //only allow for Create & Update scaffolders
        $scaffolderClass = get_class($scaffolder) == Update::class ? 'Update' : 'Create';
        $allowedTypes = [];
        $schemas = $manager->config()->get('schemas');
        $schema = isset($schemas[$manager->getSchemaKey()]) ? $schemas[$manager->getSchemaKey()] : [];
        if(isset($schema['scaffolding']['allowNestedMutations'])){
            $allowedTypes = $schema['scaffolding']['allowNestedMutations'];
        }


        $baseClass = $scaffolder->getDataObjectClass();
        if(in_array($baseClass, $allowedTypes)){
            $inputTypeName = $scaffolder->getTypeName().$scaffolderClass.'InputType';     //$scaffolder->inputTypeName() is protected

            //SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update.php adds this type before calling
            //this extension hook
            if($manager->hasType($inputTypeName)){
                $inputType = $manager->getType($inputTypeName);

                //the following closure is mostly copied from SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update->generateInputType()
                $inputType->config['fields'] = function () use ($manager, $scaffolder, $baseClass, $scaffolderClass) {
                    if($scaffolderClass == 'Update'){
                        $fields = [
                            'ID' => [
                                'type' => Type::nonNull(Type::id()),
                            ]
                        ];
                    }else{
                        $fields = [];
                    }

                    /** @var DataObject $instance */
                    $instance = $scaffolder->getDataObjectInstance();
                    $baseTypeName = StaticSchema::inst()->typeNameForDataObject($baseClass);

                    // Setup default input args.. Placeholder!
                    $schema = Injector::inst()->get(DataObjectSchema::class);
                    $db = $schema->fieldSpecs($baseClass);

                    unset($db['ID']);

                    foreach ($db as $dbFieldName => $dbFieldType) {
                        /** @var DBField $result */
                        $result = $instance->obj($dbFieldName);
                        // Skip complex fields, e.g. composite, as that would require scaffolding a new input type.
                        if (!$result->isInternalGraphQLType()) {
                            continue;
                        }
                        $arr = [
                            'type' => $result->getGraphQLType($manager),
                        ];
                        $fields[$dbFieldName] = $arr;
                    }

                    $this->addRelationshipFields($fields, $instance, $manager, $scaffolderClass);

                    return $fields;
                };
            }
        }
    }

    private function addRelationshipFields(&$fields, DataObject $instance, Manager $manager, $scaffolderClass){
        // TODO: maybe make it possible to set a whitelist or a blacklist of relations per object
        $hasOnes = $instance->hasOne();
        foreach($hasOnes as $relationship => $class){
            $classTypeName = StaticSchema::inst()->typeNameForDataObject($class);
            $typeName = $classTypeName. $scaffolderClass . 'InputType';

            if($manager->hasType($typeName)){
                $typeName = $classTypeName . 'CreateOrUpdateInputType';
                if($manager->hasType($typeName)){
                    $type = $manager->getType($typeName);
                }else {
                    $type = $this->buildNewCreateOrUpdateType($typeName, $class, $manager, $scaffolderClass);
                    $manager->addType($type, $typeName);
                }

                $fields[$relationship] = [
                    'type' => $type
                ];
            }
        }

        // TODO: maybe make it possible to set a whitelist or a blacklist of relations per object
        $hasManys = $instance->hasMany();
        foreach($hasManys as $relationship => $class){
            if($instance->getClassName() == $class){
                continue; //we cannot nest has_many relations of the same class as this will create an infinite loop
            }
            $classTypeName = StaticSchema::inst()->typeNameForDataObject($class);
            $typeName = $classTypeName. $scaffolderClass . 'InputType';
            if($manager->hasType($typeName)) {
                $typeName = $classTypeName . 'Nested' . $scaffolderClass . 'InputType';
                if($manager->hasType($typeName)){
                    $type = $manager->getType($typeName);
                }else {
                    $type = $this->buildNewManyType($typeName, $class, $manager, $scaffolderClass);
                    $manager->addType($type, $typeName);
                }

                $fields[$relationship] = [
                    'type' => $type
                ];
            }
        }

        // TODO: maybe make it possible to set a whitelist or a blacklist of relations per object
        $manyManys = $instance->ManyMany();
        foreach($manyManys as $relationship => $class){
            if (is_array($class) && isset($class['through'])){
                $classTypeName = StaticSchema::inst()->typeNameForDataObject($class['through']);
                $class = $class['through'];
            }elseif(!is_array($class)){
                $classTypeName = StaticSchema::inst()->typeNameForDataObject($class);
            }else{
                throw new Exception('Class is an array but the "through" class is not defined');
            }
            $typeName = $classTypeName. $scaffolderClass . 'InputType';

            if($manager->hasType($typeName)) {
                $typeName = $classTypeName . 'ManyNested' . $scaffolderClass . 'InputType';

                if($manager->hasType($typeName)){
                    $type = $manager->getType($typeName);
                }else {
                    $manyManyList = $instance->getManyManyComponents($relationship, -1);
                    $type = $this->buildNewManyManyType($typeName, $class, $manager, $scaffolderClass, $manyManyList);
                    $manager->addType($type, $typeName);
                }

                $fields[$relationship] = [
                    'type' => $type
                ];
            }
        }
    }

    public function generateFields(Manager $manager, $class, $scaffolderClass){
        $fields = [];
        $instance =  Injector::inst()->get($class);
        $schema = Injector::inst()->get(DataObjectSchema::class);
        $db = $schema->fieldSpecs($class);

        foreach ($db as $dbFieldName => $dbFieldType) {
            /** @var DBField $result */
            $result = $instance->obj($dbFieldName);
            // Skip complex fields, e.g. composite, as that would require scaffolding a new input type.
            if (!$result->isInternalGraphQLType()) {
                continue;
            }
            $arr = [
                'type' => $result->getGraphQLType($manager),
            ];
            $fields[$dbFieldName] = $arr;
        }

        $this->addRelationshipFields($fields, $instance, $manager, $scaffolderClass);

        return $fields;
    }

    private function buildNewCreateOrUpdateType($newTypeName, $class, $manager, $scaffolderClass){
        return new InputObjectType([
            'name' => $newTypeName,
            'fields' => function () use ($manager, $class, $scaffolderClass) {
                $fields = [
                    'ID' => [
                        'type' => Type::id(),
                    ],
                ];
                $instance =  Injector::inst()->get($class);

                // Setup default input args.. Placeholder!
                $schema = Injector::inst()->get(DataObjectSchema::class);
                $db = $schema->fieldSpecs($class);

                foreach ($db as $dbFieldName => $dbFieldType) {
                    /** @var DBField $result */
                    $result = $instance->obj($dbFieldName);
                    // Skip complex fields, e.g. composite, as that would require scaffolding a new input type.
                    if (!$result->isInternalGraphQLType()) {
                        continue;
                    }
                    $arr = [
                        'type' => $result->getGraphQLType($manager),
                    ];
                    $fields[$dbFieldName] = $arr;
                }

                $this->addRelationshipFields($fields, $instance, $manager, $scaffolderClass);

                return $fields;
            }
        ]);
    }

    /*
     * Mostly complied from SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update->generateInputType()
     * do not unset the ID
     */
    private function buildNewManyType($newTypeName, $class, $manager, $scaffolderClass){
        $schema = StaticSchema::inst();
        $tree = array_merge(
            [$class],
            $schema->getDescendants($class)
        );
        if(count($tree) > 1){
            $types = array_map(function ($class) use ($tree, $schema) {
                return $schema->typeNameForDataObject($class);
            }, $tree);

            return (new InputObjectType([
                'name' => $newTypeName,
                'fields' => function () use ($manager, $types, $scaffolderClass) {
                    $fields = [];
                    foreach($types as $type){
                        $fields[$type] = Type::listOf( (new InputObjectType([
                            'name' => $type . 'ManyNested' . $scaffolderClass . 'InputType',
                            'fields' => call_user_func(array($this, 'generateFields'), $manager, $type, $scaffolderClass)
                        ])));
                    }
                    return $fields;
                }
            ]));
        }else{
            return Type::listOf( (new InputObjectType([
                'name' => $newTypeName,
                'fields' => call_user_func(array($this, 'generateFields'), $manager, $class, $scaffolderClass)
            ])));
        }
    }

    /*
     * This input type is used to
     * do not unset the ID
     */
    private function buildNewManyManyType($newTypeName, $class, $manager, $scaffolderClass, $manyManyList)
    {
        $extraFields = $manyManyList->getExtraFields();

        return Type::listOf((new InputObjectType([
            'name' => $newTypeName,
            'fields' => function () use ($manager, $class, $extraFields, $scaffolderClass) {
                $fields = [];
                $instance = Injector::inst()->get($class);

                $extraFields = ['ID' => 'Int'] + $extraFields;

                foreach ($extraFields as $dbFieldName => $dbFieldType) {
                    /** @var DBField $result */
                    $result = $instance->obj($dbFieldName);
                    // Skip complex fields, e.g. composite, as that would require scaffolding a new input type.
                    if (!$result->isInternalGraphQLType()) {
                        continue;
                    }
                    $arr = [
                        'type' => $result->getGraphQLType($manager),
                    ];
                    $fields[$dbFieldName] = $arr;
                }

//                $this->addRelationshipFields($fields, $instance, $manager, $scaffolderClass);

                return $fields;
            }
        ])));
    }

    public function augmentMutation($obj, $args, $context, $info){
        $list = ArrayList::create([]);
        if( is_subclass_of($obj, DataObject::class)){
            $list->push($obj);
        } else {
            $list = $obj;
        }
        foreach($list as $item) {
            $this->mutateHasOneRelations($item, $args, $context, $info);
            $this->mutateHasManyRelations($item, $args, $context, $info);
            $this->mutateManyManyRelations($item, $args, $context);
        }

        // TODO: need to have logic here
        return true;
    }

    private function mutateHasOneRelations(DataObject $obj, $args, $context, $info){

        $hasOnes = $obj->hasOne();
        if($hasOnes){

            foreach($hasOnes as $relationship => $class){
                if(isset($args['Input'][$relationship])){

                    /* @var $relObj DataObject */
                    $relObj = $obj->$relationship();
                    if(isset($args['Input'][$relationship]['ID'])){
                        $relObj = $this->updateRelatedObject($relObj->ClassName, $args['Input'][$relationship], $context, $info);
                    }else{
                        $relObj = $this->createRelatedObject($relObj->ClassName, $args['Input'][$relationship], $context, $info);
                    }

                    if($relObj && $relObj->exists()){
                        $obj->{$relationship . 'ID'} = $relObj->ID;
                    }
                }
            }
        }
    }

    private function mutateHasManyRelations(DataObject $obj, $args, $context, $info){
        $hasMany = $obj->hasMany();
        if($hasMany) {
            $schema = StaticSchema::inst();
            foreach ($hasMany as $relationship => $relationshipClass) {
                $descendants = array_merge([$relationshipClass], $schema->getDescendants($relationshipClass));

                if (isset($args['Input'][$relationship])) {

                    foreach($descendants as $class){
                        $data = $args['Input'][$relationship];

                        //this means the input will be split between the descendant types
                        if(count($descendants) > 1){
                            if(!isset($data[$class])){
                                continue;
                            }
                            $data = $data[$class];
                        }

                        /* @var $hasManyList DataList */
                        $hasManyList = $obj->$relationship();

                        $toCreate = [];
                        $update = [];
                        $updateIDs = [];
                        foreach($data as $d){
                            if(isset($d['ID']) && $d['ID']){
                                $update[$d['ID']] = $d;
                                $updateIDs[] = $d['ID'];
                            }else{
                                $toCreate[] = $d;
                            }
                        }

                        if($hasManyList && $hasManyList->count()){
                            if(!empty($updateIDs)){
                                /* @var $toUpdate DataList */
                                $toUpdate = clone $hasManyList;
                                $toUpdate = $toUpdate->filter('ID', $updateIDs);
                                foreach($toUpdate as $tu){
                                    $this->updateRelatedObject($tu->ClassName, $update[$tu->ID], $context, $info);
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
                                $first = $toDelete->first();
                                $this->deleteRelatedObject($first->ClassName, $toDelete->column("ID"), $context, $info);
                            }

                        }

                        if(!empty($toCreate)){
                            foreach($toCreate as $tc){
                                $item = Injector::inst()->create($class, $tc);
                                $item = $this->createRelatedObject($item->ClassName, $tc, $context, $info);
                                $hasManyList->add($item);
                            }
                        }
                    }
                }
            }
        }
    }

    private function mutateManyManyRelations(DataObject $obj, $args, $context){
        $manyManys = $obj->manyMany();
        if($manyManys){
            foreach($manyManys as $relationship => $class){
                if(isset($args['Input'][$relationship])){
                    $items = $args['Input'][$relationship];

                    /* @var $manyManyList ManyManyList */
                    $manyManyList = $obj->$relationship();
                    $existingCount = $manyManyList->count();

                    $existingIDs = [];
                    foreach($items as $extraFields){
                        $itemID = $extraFields['ID'];
                        unset($extraFields['ID']);
                        $existingIDs[] = $itemID;
                        //TODO we should validate the itemID to ensure it exists
                        $manyManyList->add($itemID, $extraFields);
                    }

                    $toDeleteList = clone $manyManyList;

                    if(!empty($existingIDs) && $existingCount){
                        $toDeleteList = $toDeleteList->exclude('ID', $existingIDs);
                    }

                    if($toDeleteList->count() && $existingCount){
                        foreach($toDeleteList as $toDeleteItem){
                            $manyManyList->removeByID($toDeleteItem->ID);
                        }
                    }
                }
            }
        }
    }

    private function createRelatedObject($class, $input, $context, $info){
        $createScaffolder = new Create($class);

        try{
            $fieldDefintion = $info->schema->getMutationType()->getField($createScaffolder->getName());
        }catch(InvariantViolation $exception){
            throw $exception;
        }

        return call_user_func($fieldDefintion->resolveFn, null, ['Input' => $input], $context, $info);
    }

    private function updateRelatedObject($class, $input, $context, $info){
        $updateScaffolder = new Update($class);

        try{
            $fieldDefintion = $info->schema->getMutationType()->getField($updateScaffolder->getName());
        }catch(InvariantViolation $exception){
            throw $exception;
        }

        return call_user_func($fieldDefintion->resolveFn, null, ['Input' => $input], $context, $info);
    }

    private function deleteRelatedObject($class, $ids, $context, $info){
        $deleteScaffolder = new Delete($class);

        try{
            $fieldDefintion = $info->schema->getMutationType()->getField($deleteScaffolder->getName());
        }catch(InvariantViolation $exception){
            throw $exception;
        }

        return call_user_func($fieldDefintion->resolveFn, null, ['IDs' => $ids], $context, $info);
    }
}